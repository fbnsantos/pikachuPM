<?php
// prototype_media.php — AJAX para links/ficheiros/imagens de protótipos
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Sessão inválida']);
    exit;
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

include_once __DIR__ . '/config.php';
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Garantir que a tabela existe
$pdo->exec("CREATE TABLE IF NOT EXISTS prototype_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prototype_id INT NOT NULL,
    tipo ENUM('link','ficheiro','imagem') NOT NULL DEFAULT 'link',
    url VARCHAR(500) NOT NULL,
    label VARCHAR(255) DEFAULT '',
    file_size BIGINT DEFAULT 0,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
    INDEX idx_prototype (prototype_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

// ── LIST ──────────────────────────────────────────────
if ($action === 'list') {
    $protoId = (int)($_GET['prototype_id'] ?? 0);
    if (!$protoId) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT * FROM prototype_media WHERE prototype_id=? ORDER BY tipo, created_at DESC");
    $stmt->execute([$protoId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── ADD LINK ──────────────────────────────────────────
if ($action === 'add_link') {
    $data    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $protoId = (int)($data['prototype_id'] ?? 0);
    $url     = trim($data['url'] ?? '');
    $label   = trim($data['label'] ?? '');
    if (!$protoId || !$url) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']); exit;
    }
    $stmt = $pdo->prepare("INSERT INTO prototype_media (prototype_id, tipo, url, label, uploaded_by) VALUES (?,?,?,?,?)");
    $stmt->execute([$protoId, 'link', $url, $label, $userId]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'item' => ['id'=>$id,'tipo'=>'link','url'=>$url,'label'=>$label,'file_size'=>0,'created_at'=>date('Y-m-d H:i:s')]]);
    exit;
}

// ── UPLOAD ────────────────────────────────────────────
if ($action === 'upload') {
    $protoId = (int)($_POST['prototype_id'] ?? 0);
    if (!$protoId || !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']); exit;
    }

    $blocked    = ['php','exe','sh','bat','phtml','php3','php4','php5','phps','pht','phar','cmd','com','scr','vbs','jar','msi'];
    $imageExts  = ['jpg','jpeg','png','gif','webp','svg','heic','heif','bmp','avif'];

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Erro no upload']); exit;
    }
    if ($file['size'] > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Ficheiro demasiado grande (máx. 50 MB)']); exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $blocked)) {
        echo json_encode(['success' => false, 'error' => 'Tipo de ficheiro não permitido']); exit;
    }

    $tipo    = in_array($ext, $imageExts) ? 'imagem' : 'ficheiro';
    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest    = __DIR__ . '/files/' . $newName;

    if (!is_dir(__DIR__ . '/files/')) mkdir(__DIR__ . '/files/', 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'error' => 'Falha ao guardar ficheiro']); exit;
    }

    $relPath = 'files/' . $newName;
    $stmt = $pdo->prepare("INSERT INTO prototype_media (prototype_id, tipo, url, label, file_size, uploaded_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$protoId, $tipo, $relPath, $file['name'], $file['size'], $userId]);
    $id = (int)$pdo->lastInsertId();

    echo json_encode(['success' => true, 'item' => ['id'=>$id,'tipo'=>$tipo,'url'=>$relPath,'label'=>$file['name'],'file_size'=>$file['size'],'created_at'=>date('Y-m-d H:i:s')]]);
    exit;
}

// ── DELETE ────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }
    $stmt = $pdo->prepare("SELECT tipo, url FROM prototype_media WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Não encontrado']); exit; }
    if ($row['tipo'] !== 'link') {
        $fp = __DIR__ . '/' . $row['url'];
        if (file_exists($fp)) unlink($fp);
    }
    $pdo->prepare("DELETE FROM prototype_media WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação desconhecida']);
