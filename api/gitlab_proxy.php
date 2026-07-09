<?php
/**
 * gitlab_proxy.php — proxy server-side para APIs GitLab e GitHub
 * O token nunca sai do servidor; desencriptado em memória por request.
 */
session_start();
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

include_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

function glEncryptToken(string $token, string $key): string {
    $iv = random_bytes(16);
    $enc = openssl_encrypt($token, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}
function glDecryptToken(string $data, string $key): string {
    $raw    = base64_decode($data);
    $iv     = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    return (string)openssl_decrypt($cipher, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
}

function detectProvider(string $baseUrl): string {
    $host = parse_url($baseUrl, PHP_URL_HOST) ?? '';
    return (strpos($host, 'github.com') !== false) ? 'github' : 'gitlab';
}

function apiGet(string $url, string $token, string $provider): mixed {
    $authHeader = $provider === 'github'
        ? "Authorization: Bearer $token"
        : "PRIVATE-TOKEN: $token";
    $ctx = stream_context_create(['http' => [
        'header'        => "$authHeader\r\nUser-Agent: PikachuPM/1.0\r\nAccept: application/vnd.github+json\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return ['error' => 'Erro de ligação'];
    $decoded = json_decode($res, true);
    return $decoded ?? ['error' => 'Resposta inválida'];
}

$prototype_id = (int)($_GET['prototype_id'] ?? 0);
$action       = preg_replace('/[^a-z_]/', '', $_GET['action'] ?? 'summary');
$user_id      = $_SESSION['user_id'] ?? null;

if (!$prototype_id || !$user_id) {
    echo json_encode(['error' => 'Parâmetros inválidos']); exit;
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Criar tabelas se não existirem
    $pdo->exec("CREATE TABLE IF NOT EXISTS prototype_gitlab_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prototype_id INT NOT NULL,
        encrypted_token TEXT NOT NULL,
        gitlab_base_url VARCHAR(255) NOT NULL DEFAULT 'https://gitlab.com',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_proto (user_id, prototype_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gitlab_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prototype_id INT NOT NULL,
        cache_key VARCHAR(50) NOT NULL,
        data LONGTEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uk_cache (user_id, prototype_id, cache_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gitlab_project_paths (
        user_id INT NOT NULL,
        prototype_id INT NOT NULL,
        project_path VARCHAR(500) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, prototype_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Verificar cache (ignora cache se ?nocache=1)
    if (empty($_GET['nocache'])) {
        $cStmt = $pdo->prepare("SELECT data FROM gitlab_cache WHERE user_id=? AND prototype_id=? AND cache_key=? AND expires_at > NOW()");
        $cStmt->execute([$user_id, $prototype_id, $action]);
        if ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            echo $row['data']; exit;
        }
    }

    // Obter token e URL base
    $tStmt = $pdo->prepare("SELECT encrypted_token, gitlab_base_url FROM prototype_gitlab_tokens WHERE user_id=? AND prototype_id=?");
    $tStmt->execute([$user_id, $prototype_id]);
    $tokenRow = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tokenRow) { echo json_encode(['error' => 'Token não configurado para este protótipo']); exit; }

    $token    = glDecryptToken($tokenRow['encrypted_token'], $API_KEY);
    $baseUrl  = rtrim($tokenRow['gitlab_base_url'], '/');
    $provider = detectProvider($baseUrl);

    // 1. Override manual do caminho do projecto
    $ppStmt = $pdo->prepare("SELECT project_path FROM gitlab_project_paths WHERE user_id=? AND prototype_id=?");
    $ppStmt->execute([$user_id, $prototype_id]);
    $ppRow = $ppStmt->fetch(PDO::FETCH_ASSOC);
    $projectPath = $ppRow['project_path'] ?? null;

    // 2. Auto-detecção a partir dos repo_links
    if (!$projectPath) {
        $pStmt = $pdo->prepare("SELECT repo_links FROM prototypes WHERE id=?");
        $pStmt->execute([$prototype_id]);
        $protoRow = $pStmt->fetch(PDO::FETCH_ASSOC);

        $host    = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $sshBase = 'git@' . $host . ':';

        if ($protoRow && !empty($protoRow['repo_links'])) {
            $links = json_decode($protoRow['repo_links'], true) ?? [];
            foreach ($links as $link) {
                $url = is_array($link) ? ($link['url'] ?? '') : (string)$link;
                if (strpos($url, $baseUrl) !== false) {
                    $path = trim(str_replace($baseUrl, '', $url), '/');
                } elseif ($host && strpos($url, $sshBase) !== false) {
                    $path = trim(str_replace($sshBase, '', $url), '/');
                } else {
                    continue;
                }
                $path = preg_replace('/\.git$/', '', $path);
                if ($path) { $projectPath = $path; break; }
            }
        }
    }

    if (!$projectPath) {
        $repoLinksRaw = isset($protoRow['repo_links']) ? $protoRow['repo_links'] : '(sem repo_links)';
        echo json_encode([
            'error'       => 'Repositório não encontrado. Define o caminho manualmente no painel GitLab/GitHub do protótipo (ex: grupo/projecto ou owner/repo).',
            'debug_links' => $repoLinksRaw,
            'base_url'    => $baseUrl,
            'provider'    => $provider,
        ]);
        exit;
    }

    // Construir URL base da API conforme o fornecedor
    if ($provider === 'github') {
        $apiBase = 'https://api.github.com/repos/' . $projectPath;
    } else {
        $apiBase = $baseUrl . '/api/v4/projects/' . urlencode($projectPath);
    }

    $data = [];

    switch ($action) {
        case 'summary':
            if ($provider === 'github') {
                $rawCommits = apiGet("$apiBase/commits?per_page=8", $token, 'github');
                $rawRuns    = apiGet("$apiBase/actions/runs?per_page=1", $token, 'github');
                $rawPRs     = apiGet("$apiBase/pulls?state=open&per_page=10", $token, 'github');
                $rawProject = apiGet($apiBase, $token, 'github');

                // Normalizar commits
                $commits = [];
                if (is_array($rawCommits) && !isset($rawCommits['error'])) {
                    foreach ($rawCommits as $c) {
                        $commits[] = [
                            'short_id'       => substr($c['sha'] ?? '', 0, 8),
                            'message'        => $c['commit']['message'] ?? '',
                            'author_name'    => $c['commit']['author']['name'] ?? ($c['author']['login'] ?? ''),
                            'committed_date' => $c['commit']['author']['date'] ?? null,
                        ];
                    }
                }

                // Normalizar pipeline (último workflow run)
                $pipeline = null;
                $runs = $rawRuns['workflow_runs'] ?? [];
                if (!empty($runs)) {
                    $r = $runs[0];
                    $status = $r['status'] ?? 'unknown';
                    if ($status === 'completed') {
                        $status = match($r['conclusion'] ?? '') {
                            'success'          => 'success',
                            'failure'          => 'failed',
                            'cancelled'        => 'canceled',
                            'skipped'          => 'skipped',
                            default            => 'failed',
                        };
                    } elseif ($status === 'in_progress') {
                        $status = 'running';
                    } elseif ($status === 'queued') {
                        $status = 'pending';
                    }
                    $pipeline = [
                        'status' => $status,
                        'id'     => $r['id'] ?? null,
                        'ref'    => $r['head_branch'] ?? null,
                    ];
                }

                // Normalizar PRs
                $mrs = [];
                if (is_array($rawPRs) && !isset($rawPRs['error'])) {
                    foreach ($rawPRs as $pr) {
                        $mrs[] = [
                            'iid'           => $pr['number'] ?? null,
                            'title'         => $pr['title'] ?? '',
                            'web_url'       => $pr['html_url'] ?? '',
                            'source_branch' => $pr['head']['ref'] ?? '',
                        ];
                    }
                }

                // Normalizar projecto
                $project = isset($rawProject['full_name']) ? [
                    'name'             => $rawProject['name'] ?? '',
                    'path_with_ns'     => $rawProject['full_name'] ?? $projectPath,
                    'default_branch'   => $rawProject['default_branch'] ?? 'main',
                    'last_activity_at' => $rawProject['pushed_at'] ?? null,
                    'web_url'          => $rawProject['html_url'] ?? '',
                ] : ['error' => 'Repositório não encontrado'];

                $data = [
                    'provider' => 'github',
                    'commits'  => $commits,
                    'pipeline' => $pipeline,
                    'mrs'      => $mrs,
                    'project'  => $project,
                ];

            } else {
                // GitLab
                $rawCommits   = apiGet("$apiBase/repository/commits?per_page=8&with_stats=false", $token, 'gitlab');
                $rawPipelines = apiGet("$apiBase/pipelines?per_page=1", $token, 'gitlab');
                $rawMRs       = apiGet("$apiBase/merge_requests?state=opened&per_page=10", $token, 'gitlab');
                $rawProject   = apiGet($apiBase, $token, 'gitlab');

                $data = [
                    'provider' => 'gitlab',
                    'commits'  => is_array($rawCommits)   && !isset($rawCommits['error'])   ? $rawCommits   : [],
                    'pipeline' => is_array($rawPipelines)  && !empty($rawPipelines)          ? $rawPipelines[0] : null,
                    'mrs'      => is_array($rawMRs)        && !isset($rawMRs['error'])        ? $rawMRs       : [],
                    'project'  => is_array($rawProject)    && isset($rawProject['id'])        ? [
                        'name'             => $rawProject['name'] ?? '',
                        'path_with_ns'     => $rawProject['path_with_namespace'] ?? $projectPath,
                        'default_branch'   => $rawProject['default_branch'] ?? 'main',
                        'last_activity_at' => $rawProject['last_activity_at'] ?? null,
                        'web_url'          => $rawProject['web_url'] ?? '',
                    ] : ['error' => 'Projecto não encontrado'],
                ];
            }
            break;

        case 'contributors':
            if ($provider === 'github') {
                $raw  = apiGet("$apiBase/contributors?per_page=15", $token, 'github');
                $data = [];
                if (is_array($raw) && !isset($raw['error'])) {
                    foreach ($raw as $c) {
                        $data[] = [
                            'name'    => $c['login'] ?? '',
                            'commits' => $c['contributions'] ?? 0,
                        ];
                    }
                }
            } else {
                $raw  = apiGet("$apiBase/repository/contributors?order_by=commits&per_page=15", $token, 'gitlab');
                $data = is_array($raw) && !isset($raw['error']) ? $raw : [];
            }
            break;

        default:
            echo json_encode(['error' => 'Acção desconhecida']); exit;
    }

    $result = json_encode($data, JSON_UNESCAPED_UNICODE);

    // Guardar em cache (5 minutos)
    $pdo->prepare("INSERT INTO gitlab_cache (user_id, prototype_id, cache_key, data, expires_at)
                   VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                   ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)")
        ->execute([$user_id, $prototype_id, $action, $result]);

    echo $result;

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
