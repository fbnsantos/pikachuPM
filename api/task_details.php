<?php
// api/task_details.php — Bearer token auth
// GET  ?id=X                        → task + checklist
// PUT  {checklist_id, checked}       → toggle checklist item

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function json_error($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function get_bearer_token() {
    $h = getallheaders();
    if (isset($h['Authorization']) && preg_match('/Bearer\s(\S+)/', $h['Authorization'], $m)) return $m[1];
    return null;
}

$token = get_bearer_token();
if (!$token) json_error(401, 'Token não fornecido');

require_once __DIR__ . '/../config.php';

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) throw new Exception($db->connect_error);
    $db->set_charset('utf8mb4');
} catch (Exception $e) {
    json_error(500, 'Erro de conexão');
}

$st = $db->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
$st->bind_param('s', $token);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
if (!$user) json_error(401, 'Token inválido');
$user_id = (int)$user['user_id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $task_id = (int)($_GET['id'] ?? 0);
    if ($task_id <= 0) json_error(400, 'ID inválido');

    $st = $db->prepare('SELECT * FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
    $st->bind_param('iii', $task_id, $user_id, $user_id);
    $st->execute();
    $task = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$task) json_error(404, 'Tarefa não encontrada');

    $st = $db->prepare('SELECT id, item_text, is_checked FROM task_checklist WHERE todo_id = ? ORDER BY position');
    $st->bind_param('i', $task_id);
    $st->execute();
    $res  = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();

    $checklist = array_map(function($r) {
        return [
            'id'      => (int)$r['id'],
            'text'    => $r['item_text'],
            'checked' => (bool)$r['is_checked'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'task' => $task, 'checklist' => $checklist]);

} elseif ($method === 'PUT') {
    $input        = json_decode(file_get_contents('php://input'), true) ?? [];
    $checklist_id = (int)($input['checklist_id'] ?? 0);
    $checked      = isset($input['checked']) ? (int)(bool)$input['checked'] : null;

    if ($checklist_id <= 0 || $checked === null) json_error(400, 'Dados inválidos');

    $st = $db->prepare('
        SELECT tc.id FROM task_checklist tc
        JOIN todos t ON t.id = tc.todo_id
        WHERE tc.id = ? AND (t.autor = ? OR t.responsavel = ?)
    ');
    $st->bind_param('iii', $checklist_id, $user_id, $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) json_error(403, 'Sem permissão');

    $st = $db->prepare('UPDATE task_checklist SET is_checked = ? WHERE id = ?');
    $st->bind_param('ii', $checked, $checklist_id);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true]);
} else {
    json_error(405, 'Método não permitido');
}

$db->close();
