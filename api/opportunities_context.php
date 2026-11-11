<?php
// api/opportunities_context.php — Contexto de oportunidades de financiamento para IA Scout
// GET: devolve lista de calls + notas
// POST { action: 'add_note', call_id, note_text }: adiciona nota de assistente IA
// Auth: X-Api-Key header (funding_scout_keys) OR Bearer token (admin/delegate)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error' => 'DB error']); exit;
}

// Auth
$xApiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$auth    = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$accessGranted = false;
$scoutKeyId = null;

if ($xApiKey) {
    try {
        $st = $pdo->prepare("SELECT id FROM funding_scout_keys WHERE api_key = ?");
        $st->execute([$xApiKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $accessGranted = true;
            $scoutKeyId = $row['id'];
            $pdo->prepare("UPDATE funding_scout_keys SET last_used_at = NOW() WHERE id = ?")->execute([$scoutKeyId]);
        }
    } catch (Exception $e) {}
}

if (!$accessGranted && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
    $st = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ?");
    $st->execute([trim($m[1])]);
    $tokenRow = $st->fetch(PDO::FETCH_ASSOC);
    if ($tokenRow) {
        $stAdm = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
        $stAdm->execute([$tokenRow['user_id']]);
        if ($stAdm->fetch()) { $accessGranted = true; }
        if (!$accessGranted) {
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

// POST: adicionar nota
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';

    if ($action === 'add_note') {
        $callId = (int)($input['call_id'] ?? 0);
        $noteText = trim($input['note_text'] ?? '');
        if (!$callId || !$noteText) {
            http_response_code(400); echo json_encode(['error' => 'call_id and note_text required']); exit;
        }
        // User ID: use scout key owner or token user
        $userId = 1;
        if ($scoutKeyId) {
            $r = $pdo->prepare("SELECT created_by FROM funding_scout_keys WHERE id = ?");
            $r->execute([$scoutKeyId]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row) $userId = $row['created_by'];
        } elseif (isset($tokenRow['user_id'])) {
            $userId = $tokenRow['user_id'];
        }
        try {
            $pdo->prepare("INSERT INTO funding_call_notes (call_id, user_id, note_text) VALUES (?,?,?)")
                ->execute([$callId, $userId, '[IA] ' . $noteText]);
            echo json_encode(['ok' => true, 'message' => 'Note added']);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
}

// GET: listar oportunidades
$estado = $_GET['estado'] ?? null;
$prioridade = $_GET['prioridade'] ?? null;

$where = [];
$params = [];
if ($estado) { $where[] = 'estado = ?'; $params[] = $estado; }
if ($prioridade) { $where[] = 'prioridade = ?'; $params[] = $prioridade; }
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$calls = $pdo->prepare("SELECT * FROM funding_calls $whereStr ORDER BY FIELD(prioridade,'A','B','C'), estado, titulo");
$calls->execute($params);
$callsData = $calls->fetchAll(PDO::FETCH_ASSOC);

// Carregar notas
$notes = $pdo->query("SELECT fcn.*, u.username FROM funding_call_notes fcn LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) u ON u.user_id = fcn.user_id ORDER BY fcn.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$notesByCall = [];
foreach ($notes as $n) { $notesByCall[$n['call_id']][] = ['user' => $n['username'], 'text' => $n['note_text'], 'at' => $n['created_at']]; }

foreach ($callsData as &$c) {
    $c['notes'] = $notesByCall[$c['id']] ?? [];
}

echo json_encode([
    'generated_at' => date('c'),
    'total' => count($callsData),
    'calls' => $callsData,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
