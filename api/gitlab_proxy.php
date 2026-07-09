<?php
/**
 * gitlab_proxy.php — proxy server-side para API GitLab
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
function glGet(string $url, string $token): mixed {
    $ctx = stream_context_create(['http' => [
        'header'        => "PRIVATE-TOKEN: $token\r\nUser-Agent: PikachuPM/1.0\r\n",
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return ['error' => 'Erro de ligação ao GitLab'];
    $decoded = json_decode($res, true);
    return $decoded ?? ['error' => 'Resposta inválida do GitLab'];
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

    // Obter token
    $tStmt = $pdo->prepare("SELECT encrypted_token, gitlab_base_url FROM prototype_gitlab_tokens WHERE user_id=? AND prototype_id=?");
    $tStmt->execute([$user_id, $prototype_id]);
    $tokenRow = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tokenRow) { echo json_encode(['error' => 'Token não configurado para este protótipo']); exit; }

    $token   = glDecryptToken($tokenRow['encrypted_token'], $API_KEY);
    $baseUrl = rtrim($tokenRow['gitlab_base_url'], '/');

    // 1. Verificar override manual do caminho do projecto
    $ppStmt = $pdo->prepare("SELECT project_path FROM gitlab_project_paths WHERE user_id=? AND prototype_id=?");
    $ppStmt->execute([$user_id, $prototype_id]);
    $ppRow = $ppStmt->fetch(PDO::FETCH_ASSOC);
    $projectPath = $ppRow['project_path'] ?? null;

    // 2. Auto-detecção a partir dos repo_links se não houver override
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
                    // HTTPS URL: https://gitlab.host/group/project[.git]
                    $path = trim(str_replace($baseUrl, '', $url), '/');
                } elseif ($host && strpos($url, $sshBase) !== false) {
                    // SSH URL: git@gitlab.host:group/project[.git]
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
            'error'     => 'Nenhum repositório GitLab encontrado. Define o caminho manualmente no painel GitLab do protótipo (ex: grupo/projecto).',
            'debug_links' => $repoLinksRaw,
            'base_url'    => $baseUrl,
        ]);
        exit;
    }

    $apiBase     = $baseUrl . '/api/v4/projects/' . urlencode($projectPath);
    $data        = [];

    switch ($action) {
        case 'summary':
            $commits   = glGet("$apiBase/repository/commits?per_page=8&with_stats=false", $token);
            $pipelines = glGet("$apiBase/pipelines?per_page=1", $token);
            $mrs       = glGet("$apiBase/merge_requests?state=opened&per_page=10", $token);
            $project   = glGet($apiBase, $token);
            $data = [
                'commits'  => is_array($commits)   && !isset($commits['error'])   ? $commits   : [],
                'pipeline' => is_array($pipelines)  && !empty($pipelines)         ? $pipelines[0] : null,
                'mrs'      => is_array($mrs)        && !isset($mrs['error'])       ? $mrs       : [],
                'project'  => is_array($project)    && !isset($project['error'])  ? [
                    'name'              => $project['name'] ?? '',
                    'path_with_ns'      => $project['path_with_namespace'] ?? $projectPath,
                    'default_branch'    => $project['default_branch'] ?? 'main',
                    'last_activity_at'  => $project['last_activity_at'] ?? null,
                    'web_url'           => $project['web_url'] ?? '',
                ] : ['error' => 'Projecto não encontrado'],
            ];
            break;

        case 'contributors':
            $data = glGet("$apiBase/repository/contributors?order_by=commits&per_page=15", $token);
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
