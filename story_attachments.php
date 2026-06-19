<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

include_once __DIR__ . '/config.php';
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list') {
    $story_id = (int)($_GET['story_id'] ?? 0);
    if (!$story_id) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT id, file_name, file_path, file_size, uploaded_at FROM story_attachments WHERE story_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$story_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'upload') {
    $story_id = (int)($_GET['story_id'] ?? 0);
    if (!$story_id || !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $blocked = ['php','exe','sh','bat','phtml','php3','php4','php5','phps','pht','phar','cmd','com','scr','vbs','js','jar','msi'];
    $image_exts = ['jpg','jpeg','png','gif','webp','svg'];

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Erro no upload']);
        exit;
    }

    if ($file['size'] > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Ficheiro demasiado grande (máx. 50MB)']);
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $blocked)) {
        echo json_encode(['success' => false, 'error' => 'Tipo de ficheiro não permitido']);
        exit;
    }

    if (in_array($ext, $image_exts)) {
        $mime = mime_content_type($file['tmp_name']);
        $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        if (!in_array($mime, $allowed_mimes)) {
            echo json_encode(['success' => false, 'error' => 'Tipo MIME de imagem inválido']);
            exit;
        }
    }

    $new_name = uniqid() . '_' . time() . '.' . $ext;
    $dest = __DIR__ . '/files/' . $new_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'error' => 'Falha ao guardar ficheiro']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO story_attachments (story_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$story_id, $file['name'], 'files/' . $new_name, $file['size'], $_SESSION['user_id']]);
    $id = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'file' => ['id' => $id, 'file_name' => $file['name'], 'file_path' => 'files/' . $new_name, 'file_size' => $file['size'], 'uploaded_at' => date('Y-m-d H:i:s')]]);
    exit;
}

if ($action === 'delete') {
    $attachment_id = (int)($_POST['attachment_id'] ?? 0);
    if (!$attachment_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT file_path FROM story_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Não encontrado']);
        exit;
    }
    $full_path = __DIR__ . '/' . $row['file_path'];
    if (file_exists($full_path)) {
        unlink($full_path);
    }
    $pdo->prepare("DELETE FROM story_attachments WHERE id = ?")->execute([$attachment_id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação desconhecida']);
