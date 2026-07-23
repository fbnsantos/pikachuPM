<?php
// api/settings.php - Devolve configurações da aplicação (apenas leitura, autenticado)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_bearer_token() {
    $headers = getallheaders();
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) return $m[1];
    if (isset($headers['authorization']) && preg_match('/Bearer\s(\S+)/', $headers['authorization'], $m)) return $m[1];
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) return $m[1];
    return null;
}

function json_error($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

$token = get_bearer_token();
if (!$token) json_error(401, 'Unauthorized');

require_once __DIR__ . '/../config.php';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    json_error(500, 'DB error');
}

// Validar token
$stmt = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
if (!$stmt->fetch()) json_error(401, 'Invalid token');

// Criar tabela se não existir
$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ler settings de MQTT
$keys = ['mqtt_broker', 'mqtt_bar_user', 'mqtt_bar_pass', 'mqtt_bar_topic', 'mqtt_topics'];
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($placeholders)");
$stmt->execute($keys);
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'mqtt_broker'    => $rows['mqtt_broker']    ?? '',
    'mqtt_bar_user'  => $rows['mqtt_bar_user']  ?? '',
    'mqtt_bar_pass'  => $rows['mqtt_bar_pass']  ?? '',
    'mqtt_bar_topic' => $rows['mqtt_bar_topic'] ?? '/PK/alertabarulho',
    'mqtt_topics'    => $rows['mqtt_topics']    ?? '#',
]);
