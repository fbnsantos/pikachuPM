<?php
// api/context.php — Agrega dados do PikachuPM para contexto LLM
// GET ?window=1h|24h|all
// Auth: Bearer token (mesmo sistema que api/todos.php)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// --- Auth: X-Api-Key header (AI assistants) OR Bearer token (user) ---
$xApiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$auth    = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

$accessGranted = false;

if ($xApiKey) {
    // Dedicated AI API key
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        api_key VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_key (api_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stKey = $pdo->prepare("SELECT id FROM ai_api_keys WHERE api_key = ?");
    $stKey->execute([$xApiKey]);
    if ($stKey->fetch()) {
        $accessGranted = true;
        // Update last_used_at
        $pdo->prepare("UPDATE ai_api_keys SET last_used_at = NOW() WHERE api_key = ?")->execute([$xApiKey]);
    }
} elseif (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
    $token = trim($m[1]);
    $stTok = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ?");
    $stTok->execute([$token]);
    $tokenRow = $stTok->fetch(PDO::FETCH_ASSOC);
    if ($tokenRow) {
        $stAdm = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
        $stAdm->execute([$tokenRow['user_id']]);
        $isAdmin = (bool)$stAdm->fetch();
        if ($isAdmin) {
            $accessGranted = true;
        } else {
            $stDel = $pdo->prepare("SELECT id FROM ai_delegates WHERE user_id = ?");
            $stDel->execute([$tokenRow['user_id']]);
            if ($stDel->fetch()) $accessGranted = true;
        }
    }
}

if (!$accessGranted) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Use X-Api-Key header or Bearer token.']);
    exit;
}


$window = $_GET['window'] ?? '1h';
$since = match($window) {
    '1h'  => date('Y-m-d H:i:s', strtotime('-1 hour')),
    '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    '7d'  => date('Y-m-d H:i:s', strtotime('-7 days')),
    default => null,
};

$ctx = [
    'generated_at' => date('c'),
    'window'       => $window,
    'since'        => $since,
];

// Helper: query with optional time filter
function qRows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function tableExists(PDO $pdo, string $t): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch();
}

// --- Daily reports ---
if (tableExists($pdo, 'daily_reports')) {
    $sql = $since
        ? "SELECT u.username, dr.report_date, dr.tarefas_alteradas, dr.tarefas_em_execucao, dr.correu_bem, dr.correu_mal, dr.plano_proximas_horas
           FROM daily_reports dr LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = dr.user_id
           WHERE dr.created_at >= ? ORDER BY dr.report_date DESC, u.username"
        : "SELECT u.username, dr.report_date, dr.tarefas_alteradas, dr.tarefas_em_execucao, dr.correu_bem, dr.correu_mal, dr.plano_proximas_horas
           FROM daily_reports dr LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = dr.user_id
           ORDER BY dr.report_date DESC, u.username LIMIT 200";
    $ctx['daily_reports'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Todos (tasks) ---
if (tableExists($pdo, 'todos')) {
    $sql = $since
        ? "SELECT t.id, t.titulo, t.estado, t.prioridade, u.username as responsavel, t.updated_at
           FROM todos t LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = t.responsavel_id
           WHERE t.updated_at >= ? ORDER BY t.updated_at DESC"
        : "SELECT t.id, t.titulo, t.estado, t.prioridade, u.username as responsavel, t.updated_at
           FROM todos t LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = t.responsavel_id
           ORDER BY t.updated_at DESC LIMIT 300";
    $ctx['todos'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Sprints ---
if (tableExists($pdo, 'sprints')) {
    $sql = $since
        ? "SELECT s.id, s.nome, s.estado, s.descricao, u.username as responsavel, s.updated_at
           FROM sprints s LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = s.responsavel_id
           WHERE s.updated_at >= ? ORDER BY s.updated_at DESC"
        : "SELECT s.id, s.nome, s.estado, s.descricao, u.username as responsavel, s.updated_at
           FROM sprints s LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = s.responsavel_id
           WHERE s.estado NOT IN ('arquivado','concluido') ORDER BY s.updated_at DESC LIMIT 50";
    $ctx['sprints'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Lab issues ---
if (tableExists($pdo, 'lab_issues')) {
    $sql = $since
        ? "SELECT li.id, li.titulo, li.descricao, li.status, li.prioridade, u.username as responsavel, li.updated_at
           FROM lab_issues li LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = li.responsavel_id
           WHERE li.updated_at >= ? ORDER BY li.updated_at DESC"
        : "SELECT li.id, li.titulo, li.descricao, li.status, li.prioridade, u.username as responsavel, li.updated_at
           FROM lab_issues li LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = li.responsavel_id
           WHERE li.status NOT IN ('resolvido','fechado') ORDER BY FIELD(li.prioridade,'urgente','alta','media','baixa'), li.id DESC LIMIT 100";
    $ctx['lab_issues'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Leads ---
if (tableExists($pdo, 'leads')) {
    $sql = $since
        ? "SELECT l.id, l.titulo, l.estado, l.valor_estimado, u.username as responsavel, l.updated_at
           FROM leads l LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = l.responsavel_id
           WHERE l.updated_at >= ? ORDER BY l.updated_at DESC"
        : "SELECT l.id, l.titulo, l.estado, l.valor_estimado, u.username as responsavel, l.updated_at
           FROM leads l LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = l.responsavel_id
           WHERE l.estado NOT IN ('perdido','arquivado') ORDER BY l.updated_at DESC LIMIT 100";
    $ctx['leads'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Chat messages ---
if (tableExists($pdo, 'chat_messages')) {
    $sql = $since
        ? "SELECT cm.channel_type, cm.channel_id, u.username as autor, cm.body, cm.created_at
           FROM chat_messages cm LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = cm.user_id
           WHERE cm.created_at >= ? AND cm.deleted_at IS NULL ORDER BY cm.created_at DESC LIMIT 500"
        : "SELECT cm.channel_type, cm.channel_id, u.username as autor, cm.body, cm.created_at
           FROM chat_messages cm LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = cm.user_id
           WHERE cm.deleted_at IS NULL ORDER BY cm.created_at DESC LIMIT 500";
    $ctx['chat_messages'] = qRows($pdo, $sql, $since ? [$since] : []);
}

// --- Projects ---
if (tableExists($pdo, 'projects')) {
    $sql = $since
        ? "SELECT p.id, p.nome, p.estado, u.username as responsavel, p.updated_at
           FROM projects p LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = p.responsavel_id
           WHERE p.updated_at >= ? ORDER BY p.updated_at DESC"
        : "SELECT p.id, p.nome, p.estado, u.username as responsavel, p.updated_at
           FROM projects p LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = p.responsavel_id
           WHERE p.estado NOT IN ('arquivado','concluido') ORDER BY p.updated_at DESC LIMIT 50";
    $ctx['projects'] = qRows($pdo, $sql, $since ? [$since] : []);
}

echo json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
