<?php
// api/stories.php — GET prototypes list / POST create user story

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function json_error($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

function get_bearer_token() {
    $h = getallheaders();
    if (isset($h['Authorization']) && preg_match('/Bearer\s(\S+)/', $h['Authorization'], $m)) return $m[1];
    return null;
}

$token = get_bearer_token();
if (!$token) json_error(401, 'Token não fornecido');

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $uStmt = $pdo->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
    $uStmt->execute([$token]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) json_error(401, 'Token inválido');
    $user_id = (int)$user['user_id'];
} catch (Exception $e) {
    json_error(500, 'Erro BD: ' . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Retorna lista de protótipos (id, short_name, title)
    try {
        $rows = $pdo->query("SELECT id, short_name, title FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['prototypes' => $rows]);
    } catch (Exception $e) {
        json_error(500, $e->getMessage());
    }
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $protoId  = (int)($input['prototype_id'] ?? 0);
    $text     = trim($input['story_text'] ?? '');
    $type     = in_array($input['story_type'] ?? '', ['Bug','Feature','Story']) ? $input['story_type'] : 'Bug';
    $priority = in_array($input['moscow_priority'] ?? '', ['Must','Should','Could',"Won't"]) ? $input['moscow_priority'] : 'Should';

    if (!$protoId || !$text) json_error(400, 'prototype_id e story_text são obrigatórios');

    try {
        $pdo->prepare("INSERT INTO user_stories (prototype_id, story_text, moscow_priority, story_type, status, completion_percentage, created_at, created_by)
                       VALUES (?,?,?,?,'open',0,NOW(),?)")
            ->execute([$protoId, $text, $priority, $type, $user_id]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        json_error(500, $e->getMessage());
    }
    exit;
}

json_error(405, 'Método não permitido');
