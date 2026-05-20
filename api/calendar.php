<?php
// api/calendar.php - API REST para eventos do calendário

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function json_error($code, $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function get_bearer_token() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Método não permitido');
}

$token = get_bearer_token();
if (!$token) {
    json_error(401, 'Token de autenticação não fornecido');
}

include_once __DIR__ . '/../config.php';

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    $db->set_charset("utf8mb4");

    // Verificar token
    $stmt = $db->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result->fetch_assoc()) {
        json_error(401, 'Token inválido');
    }
    $stmt->close();

} catch (Exception $e) {
    json_error(500, 'Erro de base de dados: ' . $e->getMessage());
}

// Parâmetros: days (quantos dias para a frente, default 30), from (data início, default hoje)
$days = isset($_GET['days']) ? max(1, min(365, (int)$_GET['days'])) : 30;
$from = date('Y-m-d');
$to   = date('Y-m-d', strtotime("+{$days} days"));

try {
    $stmt = $db->prepare(
        'SELECT id, data, tipo, descricao, hora, criador, cor
         FROM calendar_eventos
         WHERE data BETWEEN ? AND ?
         ORDER BY data ASC, hora ASC'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();

    $eventos = [];
    while ($row = $result->fetch_assoc()) {
        $eventos[] = [
            'id'        => (int)$row['id'],
            'data'      => $row['data'],
            'tipo'      => $row['tipo'],
            'descricao' => $row['descricao'],
            'hora'      => $row['hora'],
            'criador'   => $row['criador'],
            'cor'       => $row['cor'],
        ];
    }
    $stmt->close();

    echo json_encode($eventos);

} catch (Exception $e) {
    json_error(500, 'Erro ao buscar eventos: ' . $e->getMessage());
}
