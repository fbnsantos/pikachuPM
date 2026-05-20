<?php
// api/leads.php — leads do utilizador autenticado (responsável ou membro)

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
    $hasLeads   = $db->query("SHOW TABLES LIKE 'leads'")->num_rows > 0;
    $hasMembers = $db->query("SHOW TABLES LIKE 'lead_members'")->num_rows > 0;
    if (!$hasLeads) { echo json_encode([]); exit; }

    $join   = $hasMembers ? 'LEFT JOIN lead_members lm ON l.id = lm.lead_id AND lm.user_id = ?' : '';
    $where  = $hasMembers ? '(l.responsavel_id = ? OR lm.user_id = ?)' : 'l.responsavel_id = ?';
    $types  = $hasMembers ? 'iii' : 'i';
    $params = $hasMembers ? [$uid, $uid, $uid] : [$uid];

    $sql = "
        SELECT DISTINCT l.id, l.titulo, l.descricao, l.relevancia,
               l.data_inicio, l.data_fim, l.estado,
               u.username AS responsavel_nome,
               CASE WHEN l.responsavel_id = ? THEN 1 ELSE 0 END AS is_responsible
        FROM leads l
        LEFT JOIN user_tokens u ON l.responsavel_id = u.user_id
        $join
        WHERE l.estado = 'aberta' AND $where
        ORDER BY l.relevancia DESC,
                 CASE WHEN l.data_fim IS NULL THEN 1 ELSE 0 END,
                 l.data_fim ASC,
                 l.criado_em DESC
    ";

    // uid extra para is_responsible CASE
    array_unshift($params, $uid);
    $types = 'i' . $types;

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
