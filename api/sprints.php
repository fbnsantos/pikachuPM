<?php
// api/sprints.php — sprints do utilizador autenticado (responsável ou membro)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function json_error($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function get_bearer_token() {
    $h = getallheaders();
    if (isset($h['Authorization']) && preg_match('/Bearer\s(\S+)/', $h['Authorization'], $m))
        return $m[1];
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error(405, 'Método não permitido');

$token = get_bearer_token();
if (!$token) json_error(401, 'Token não fornecido');

include_once __DIR__ . '/../config.php';

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) throw new Exception($db->connect_error);
    $db->set_charset('utf8mb4');

    // Validar token
    $st = $db->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
    $st->bind_param('s', $token);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user) json_error(401, 'Token inválido');
    $uid = $user['user_id'];

    // Verificar se tabelas existem
    $hasSprints  = $db->query("SHOW TABLES LIKE 'sprints'")->num_rows > 0;
    $hasMembers  = $db->query("SHOW TABLES LIKE 'sprint_members'")->num_rows > 0;
    if (!$hasSprints) { echo json_encode([]); exit; }

    $join   = $hasMembers ? 'LEFT JOIN sprint_members sm ON s.id = sm.sprint_id' : '';
    $where  = $hasMembers ? '(s.responsavel_id = ? OR sm.user_id = ?)' : 's.responsavel_id = ?';
    $types  = $hasMembers ? 'ii' : 'i';
    $params = $hasMembers ? [$uid, $uid] : [$uid];

    $sql = "
        SELECT s.id, s.nome, s.descricao, s.data_inicio, s.data_fim, s.estado,
               u.username AS responsavel_nome
        FROM sprints s
        LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
        $join
        WHERE $where
        GROUP BY s.id
        ORDER BY
          CASE s.estado
            WHEN 'em execução' THEN 1
            WHEN 'aberta'      THEN 2
            WHEN 'suspensa'    THEN 3
            ELSE 4
          END,
          CASE WHEN s.data_fim IS NULL THEN 1 ELSE 0 END,
          s.data_fim ASC,
          s.created_at DESC
    ";

    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    echo json_encode($rows);
} catch (Exception $e) {
    json_error(500, $e->getMessage());
}
$db->close();
