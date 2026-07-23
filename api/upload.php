<?php
// api/upload.php — upload de imagens a partir da PWA para task ou story
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

function jerr($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }

// Auth — mesma lógica do todos.php (getallheaders é mais fiável em Apache/FPM)
function get_bearer_token() {
    $headers = getallheaders();
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) return $m[1];
    if (isset($headers['authorization']) && preg_match('/Bearer\s(\S+)/', $headers['authorization'], $m)) return $m[1];
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) return $m[1];
    return null;
}
$token = get_bearer_token();
if (!$token) jerr(401, 'Token em falta');

require_once __DIR__ . '/../config.php';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { jerr(500, 'DB error'); }

$row = $pdo->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
$row->execute([$token]);
$user = $row->fetch(PDO::FETCH_ASSOC);
if (!$user) jerr(401, 'Token inválido');
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr(405, 'Método não suportado');

$type  = $_POST['type']   ?? '';   // 'todo' ou 'story'
$refId = (int)($_POST['ref_id'] ?? 0);
if (!in_array($type, ['todo','story']) || !$refId) jerr(400, 'Parâmetros inválidos');
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr(400, 'Ficheiro inválido');

$file    = $_FILES['file'];
$maxSize = 20 * 1024 * 1024; // 20 MB
if ($file['size'] > $maxSize) jerr(400, 'Ficheiro demasiado grande (máx 20 MB)');

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed  = ['jpg','jpeg','png','gif','webp','heic','heif','bmp'];
if (!in_array($ext, $allowed)) jerr(400, 'Tipo não permitido: ' . $ext);

$uploadDir = __DIR__ . '/../files/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest    = $uploadDir . $newName;
if (!move_uploaded_file($file['tmp_name'], $dest)) jerr(500, 'Erro ao mover ficheiro');

$relPath  = 'files/' . $newName;
$origName = basename($file['name']);

try {
    if ($type === 'todo') {
        if (!$pdo->query("SHOW TABLES LIKE 'task_files'")->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS task_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                todo_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL,
                uploaded_by INT,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $s = $pdo->prepare('INSERT INTO task_files (todo_id, file_name, file_path, file_size, uploaded_by) VALUES (?,?,?,?,?)');
        $s->execute([$refId, $origName, $relPath, $file['size'], $userId]);
    } else {
        // story_attachments
        if (!$pdo->query("SHOW TABLES LIKE 'story_attachments'")->fetch()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS story_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                story_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL,
                uploaded_by INT,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $s = $pdo->prepare('INSERT INTO story_attachments (story_id, file_name, file_path, file_size, uploaded_by) VALUES (?,?,?,?,?)');
        $s->execute([$refId, $origName, $relPath, $file['size'], $userId]);
    }
    echo json_encode(['ok' => true, 'file_id' => (int)$pdo->lastInsertId(), 'file_name' => $origName, 'file_path' => $relPath]);
} catch (PDOException $e) {
    @unlink($dest);
    jerr(500, 'Erro BD: ' . $e->getMessage());
}
