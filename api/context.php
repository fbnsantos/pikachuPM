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


$window = $_GET['window'] ?? '24h';

require_once __DIR__ . '/_context_data.php';

echo json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
