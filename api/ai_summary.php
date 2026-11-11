<?php
// api/ai_summary.php — Chama LLM configurado com contexto do PikachuPM
// POST { window: '1h'|'24h'|'7d'|'all', prompt?: string }
// Auth: Bearer token

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>'DB error']); exit;
}

// Auth: Bearer token OR PHP session (same-site calls; Apache often strips Authorization header)
$userId = null;

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
    $stTok = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ?");
    $stTok->execute([trim($m[1])]);
    $row = $stTok->fetch(PDO::FETCH_ASSOC);
    if ($row) $userId = (int)$row['user_id'];
}

if (!$userId) {
    // Fallback: PHP session (browser requests from same domain)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check admin or delegate
$stAdm = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stAdm->execute([$userId]);
$isAdmin = (bool)$stAdm->fetch();
if (!$isAdmin) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_delegates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, granted_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stDel = $pdo->prepare("SELECT id FROM ai_delegates WHERE user_id = ?");
    $stDel->execute([$userId]);
    if (!$stDel->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }
}

$tokenRow = ['user_id' => $userId];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$window = $input['window'] ?? '24h';
$customPrompt = trim($input['prompt'] ?? '');

// Load AI config from app_settings
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) { return $default; }
}

$provider = getSetting($pdo, 'ai_provider', 'claude');
$apiKey   = getSetting($pdo, 'ai_api_key', '');
$model    = getSetting($pdo, 'ai_model', '');

if (!$apiKey) {
    http_response_code(422);
    echo json_encode(['error' => 'AI API key not configured. Configure in AI Management.']);
    exit;
}

// Get context from api/context.php logic (inline call)
$contextUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/context.php?window=' . urlencode($window);

$ch = curl_init($contextUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$contextJson = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr || !$contextJson) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch context: ' . $curlErr]);
    exit;
}

$context = json_decode($contextJson, true);
if (!$context || isset($context['error'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Context error: ' . ($context['error'] ?? 'unknown')]);
    exit;
}

// Build system prompt
$sysPrompt = "És um assistente de gestão de projeto para uma equipa de investigação e desenvolvimento. "
    . "Analisa os dados fornecidos e produz um resumo estruturado em português com:\n"
    . "1. **Crítico** — problemas urgentes, bloqueios, itens em risco, atrasos\n"
    . "2. **Não crítico** — progresso normal, tarefas em curso, destaques positivos\n"
    . "3. **Recomendações** — ações sugeridas para as próximas horas/dias\n\n"
    . "Sê conciso e objetivo. Usa bullet points. Destaca nomes de pessoas e tarefas.";

if ($customPrompt) {
    $sysPrompt .= "\n\nInstrução adicional do utilizador: " . $customPrompt;
}

$userMsg = "Dados do sistema PikachuPM (janela: $window):\n\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Call LLM based on provider
$result = null;
$error  = null;

if ($provider === 'claude') {
    $mdl = $model ?: 'claude-haiku-5-5';
    $payload = [
        'model'      => $mdl,
        'max_tokens' => 1500,
        'system'     => $sysPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) { $error = $curlErr; }
    else {
        $data = json_decode($resp, true);
        if (isset($data['content'][0]['text'])) $result = $data['content'][0]['text'];
        elseif (isset($data['error'])) $error = $data['error']['message'] ?? 'Claude API error';
        else $error = 'Unexpected response from Claude API';
    }

} elseif ($provider === 'openai') {
    $mdl = $model ?: 'gpt-4o-mini';
    $payload = [
        'model'    => $mdl,
        'messages' => [
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'max_tokens' => 1500,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) { $error = $curlErr; }
    else {
        $data = json_decode($resp, true);
        if (isset($data['choices'][0]['message']['content'])) $result = $data['choices'][0]['message']['content'];
        elseif (isset($data['error'])) $error = $data['error']['message'] ?? 'OpenAI API error';
        else $error = 'Unexpected response from OpenAI API';
    }

} elseif ($provider === 'ollama') {
    $ollamaUrl = getSetting($pdo, 'ai_ollama_url', 'http://localhost:11434');
    $mdl = $model ?: 'llama3';
    $payload = [
        'model'  => $mdl,
        'prompt' => $sysPrompt . "\n\n" . $userMsg,
        'stream' => false,
    ];
    $ch = curl_init(rtrim($ollamaUrl, '/') . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) { $error = $curlErr; }
    else {
        $data = json_decode($resp, true);
        if (isset($data['response'])) $result = $data['response'];
        else $error = 'Unexpected response from Ollama';
    }
} else {
    $error = 'Unknown provider: ' . $provider;
}

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => $error]);
    exit;
}

// Save to ai_summaries table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_summaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        window_type VARCHAR(10) NOT NULL,
        provider VARCHAR(30) NOT NULL,
        model VARCHAR(100) NOT NULL,
        summary TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT INTO ai_summaries (user_id, window_type, provider, model, summary) VALUES (?,?,?,?,?)");
    $st->execute([$tokenRow['user_id'], $window, $provider, $model ?: 'default', $result]);
    $summaryId = $pdo->lastInsertId();
} catch (Exception $e) {
    $summaryId = null;
}

echo json_encode([
    'summary'    => $result,
    'window'     => $window,
    'provider'   => $provider,
    'model'      => $model,
    'summary_id' => $summaryId,
    'generated_at' => date('c'),
], JSON_UNESCAPED_UNICODE);
