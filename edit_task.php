<?php
/**
 * EDITOR DE TASKS UNIVERSAL
 * Modal overlay para edição completa de tasks
 * Pode ser incluído em qualquer página (todo.php, sprints.php, etc)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar se está logado
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Não autenticado']));
}

require_once __DIR__ . '/config.php';

// Conectar BD
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

// Migração tabelas de notas de tasks
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        note_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES todos(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
        INDEX idx_tn_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_note_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES task_notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// GET: listar notas de uma task
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_notes') {
    header('Content-Type: application/json');
    $task_id = (int)($_GET['task_id'] ?? 0);
    $notes = [];
    try {
        $nStmt = $pdo->prepare("SELECT tn.id, tn.user_id, tn.note_text, tn.created_at, u.username
            FROM task_notes tn JOIN user_tokens u ON tn.user_id = u.user_id
            WHERE tn.task_id = ? ORDER BY tn.created_at DESC");
        $nStmt->execute([$task_id]);
        foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $iStmt = $pdo->prepare("SELECT id, file_path, original_name FROM task_note_images WHERE note_id=? ORDER BY created_at ASC");
            $iStmt->execute([$n['id']]);
            $n['images'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);
            $notes[] = $n;
        }
    } catch (PDOException $e) {}
    echo json_encode(['success' => true, 'notes' => $notes, 'current_user_id' => (int)$_SESSION['user_id']]);
    exit;
}

// PROCESSAR UPLOAD DE FICHEIROS - VERSÃO CORRIGIDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
    // Aumentar limites temporariamente para suportar ficheiros até 300MB
    @ini_set('upload_max_filesize', '300M');
    @ini_set('post_max_size', '305M');
    @ini_set('max_execution_time', '600');
    @ini_set('memory_limit', '512M');
    
    header('Content-Type: application/json');
    
    $todo_id = (int)$_POST['todo_id'];
    
    // Verificar permissão
    $stmt = $pdo->prepare('SELECT autor, responsavel FROM todos WHERE id = ?');
    $stmt->execute([$todo_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($task['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($task['responsavel']) && $task['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($task['responsavel']) || is_null($task['responsavel']);
    
    if (!$task || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // Validações
        $max_file_size = 300 * 1024 * 1024; // 300MB
        
        // Extensões bloqueadas por motivos de segurança (executáveis e scripts)
        $blocked_extensions = ['php', 'exe', 'sh', 'bat', 'phtml', 'php3', 'php4', 'php5', 'phps', 'pht', 'phar', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'msi'];
        
        $file_name = basename($_FILES['file']['name']);
        $file_size = $_FILES['file']['size'];
        $file_tmp = $_FILES['file']['tmp_name'];
        
        // Obter extensão (case-insensitive)
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validar tamanho
        if ($file_size > $max_file_size) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro muito grande. Máximo 300MB']);
            exit;
        }
        
        // Bloquear extensões perigosas
        if (in_array($file_ext, $blocked_extensions)) {
            echo json_encode(['success' => false, 'error' => 'Tipo de ficheiro não permitido por motivos de segurança: .' . $file_ext]);
            exit;
        }
        
        // Validar se é realmente um ficheiro
        if (!is_uploaded_file($file_tmp)) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro inválido']);
            exit;
        }
        
        // Criar diretório se não existir
        $upload_dir = __DIR__ . '/files/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                echo json_encode(['success' => false, 'error' => 'Erro ao criar diretório de upload']);
                exit;
            }
        }
        
        // Verificar permissões do diretório
        if (!is_writable($upload_dir)) {
            echo json_encode(['success' => false, 'error' => 'Diretório não tem permissões de escrita']);
            exit;
        }
        
        // Gerar nome único para o ficheiro (sempre em minúsculas)
        $new_name = uniqid() . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $new_name;
        
        // Mover ficheiro
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Guardar na base de dados
            try {
                $stmt = $pdo->prepare('INSERT INTO task_files (todo_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $todo_id, 
                    $file_name, // Nome original do ficheiro
                    'files/' . $new_name, // Caminho com nome único
                    $file_size,
                    $_SESSION['user_id']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'file_id' => $pdo->lastInsertId(),
                    'file_name' => $file_name,
                    'file_path' => 'files/' . $new_name,
                    'file_size' => $file_size
                ]);
            } catch (PDOException $e) {
                // Se falhar a inserção na BD, apagar o ficheiro
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                echo json_encode(['success' => false, 'error' => 'Erro ao guardar na base de dados: ' . $e->getMessage()]);
            }
        } else {
            $error_info = error_get_last();
            echo json_encode(['success' => false, 'error' => 'Erro ao mover ficheiro: ' . ($error_info['message'] ?? 'Erro desconhecido')]);
        }
    } else {
        // Tratar diferentes tipos de erro de upload
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'Ficheiro excede o tamanho máximo permitido no PHP',
            UPLOAD_ERR_FORM_SIZE => 'Ficheiro excede o tamanho máximo do formulário',
            UPLOAD_ERR_PARTIAL => 'Upload parcial - tente novamente',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever ficheiro no disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
        ];
        
        $error_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error_message = $error_messages[$error_code] ?? 'Erro desconhecido no upload';
        
        echo json_encode(['success' => false, 'error' => $error_message]);
    }
    exit;
}

// PROCESSAR ELIMINAÇÃO DE FICHEIROS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_file') {
    header('Content-Type: application/json');
    
    $file_id = (int)$_POST['file_id'];
    
    $stmt = $pdo->prepare('SELECT tf.*, t.autor, t.responsavel FROM task_files tf JOIN todos t ON tf.todo_id = t.id WHERE tf.id = ?');
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($file['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($file['responsavel']) && $file['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($file['responsavel']) || is_null($file['responsavel']);
    
    if (!$file || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    // Eliminar ficheiro físico
    if (file_exists(__DIR__ . '/' . $file['file_path'])) {
        unlink(__DIR__ . '/' . $file['file_path']);
    }
    
    // Eliminar registo
    $stmt = $pdo->prepare('DELETE FROM task_files WHERE id = ?');
    $stmt->execute([$file_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// PROCESSAR GUARDAR TASK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_task') {
    header('Content-Type: application/json');
    
    $todo_id = (int)$_POST['todo_id'];
    
    // Verificar permissão
    $stmt = $pdo->prepare('SELECT autor, responsavel FROM todos WHERE id = ?');
    $stmt->execute([$todo_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($task['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($task['responsavel']) && $task['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($task['responsavel']) || is_null($task['responsavel']);
    
    if (!$task || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    // Atualizar task
    $stmt = $pdo->prepare('UPDATE todos SET titulo = ?, descritivo = ?, data_limite = ?, responsavel = ?, estado = ? WHERE id = ?');
    $stmt->execute([
        $_POST['titulo'],
        $_POST['descritivo'],
        $_POST['data_limite'] ?: null,
        $_POST['responsavel'] ?: null,
        $_POST['estado'],
        $todo_id
    ]);
    
    // Guardar checklist
    $pdo->prepare('DELETE FROM task_checklist WHERE todo_id = ?')->execute([$todo_id]);
    
    if (isset($_POST['checklist']) && is_array($_POST['checklist'])) {
        $stmt = $pdo->prepare('INSERT INTO task_checklist (todo_id, item_text, is_checked, position) VALUES (?, ?, ?, ?)');
        foreach ($_POST['checklist'] as $index => $item) {
            $stmt->execute([
                $todo_id,
                $item['text'],
                $item['checked'] ? 1 : 0,
                $index
            ]);
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// POST: adicionar nota de task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_task_note') {
    header('Content-Type: application/json');
    $task_id = (int)($_POST['task_id'] ?? 0);
    $note_text = trim($_POST['note_text'] ?? '');
    $user_id = (int)$_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("INSERT INTO task_notes (task_id, user_id, note_text) VALUES (?,?,?)");
        $stmt->execute([$task_id, $user_id, $note_text]);
        $note_id = (int)$pdo->lastInsertId();
        $images = [];
        if (!empty($_FILES['note_images']['name'][0])) {
            $upload_dir = 'files/task_notes/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $mime = mime_content_type($tmp);
                $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','text/plain','text/csv','application/zip','application/x-zip-compressed'];
                if (!in_array($mime, $allowedMimes)) continue;
                $ext = strtolower(pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION));
                $filename = "tn_{$note_id}_{$i}_" . uniqid() . ".$ext";
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($tmp, $dest)) {
                    $iStmt = $pdo->prepare("INSERT INTO task_note_images (note_id, file_path, original_name) VALUES (?,?,?)");
                    $iStmt->execute([$note_id, $dest, $_FILES['note_images']['name'][$i]]);
                    $images[] = ['id' => (int)$pdo->lastInsertId(), 'file_path' => $dest, 'original_name' => $_FILES['note_images']['name'][$i]];
                }
            }
        }
        $uStmt = $pdo->prepare("SELECT username FROM user_tokens WHERE user_id=?");
        $uStmt->execute([$user_id]);
        $username = $uStmt->fetchColumn();
        echo json_encode(['success' => true, 'note' => [
            'id' => $note_id, 'user_id' => $user_id, 'username' => $username,
            'note_text' => $note_text, 'created_at' => date('Y-m-d H:i:s'), 'images' => $images
        ]]);
    } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// POST: editar nota de task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_task_note') {
    header('Content-Type: application/json');
    $note_id = (int)($_POST['note_id'] ?? 0);
    $note_text = trim($_POST['note_text'] ?? '');
    $user_id = (int)$_SESSION['user_id'];
    try {
        $pdo->prepare("UPDATE task_notes SET note_text=? WHERE id=? AND user_id=?")->execute([$note_text, $note_id, $user_id]);
        if (!empty($_FILES['note_images']['name'][0])) {
            $upload_dir = 'files/task_notes/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $mime = mime_content_type($tmp);
                $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','text/plain','text/csv','application/zip','application/x-zip-compressed'];
                if (!in_array($mime, $allowedMimes)) continue;
                $ext = strtolower(pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION));
                $filename = "tn_{$note_id}_e{$i}_" . uniqid() . ".$ext";
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($tmp, $dest)) {
                    $iStmt = $pdo->prepare("INSERT INTO task_note_images (note_id, file_path, original_name) VALUES (?,?,?)");
                    $iStmt->execute([$note_id, $dest, $_FILES['note_images']['name'][$i]]);
                }
            }
        }
        $iStmt = $pdo->prepare("SELECT id, file_path, original_name FROM task_note_images WHERE note_id=? ORDER BY created_at ASC");
        $iStmt->execute([$note_id]);
        echo json_encode(['success' => true, 'note_text' => $note_text, 'images' => $iStmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// POST: eliminar nota de task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task_note') {
    header('Content-Type: application/json');
    $note_id = (int)($_POST['note_id'] ?? 0);
    $user_id = (int)$_SESSION['user_id'];
    try {
        $iStmt = $pdo->prepare("SELECT file_path FROM task_note_images WHERE note_id=?");
        $iStmt->execute([$note_id]);
        foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $img) { if (file_exists($img['file_path'])) unlink($img['file_path']); }
        $pdo->prepare("DELETE FROM task_notes WHERE id=? AND user_id=?")->execute([$note_id, $user_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// POST: eliminar imagem de nota de task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task_note_image') {
    header('Content-Type: application/json');
    $image_id = (int)($_POST['image_id'] ?? 0);
    $user_id = (int)$_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT tni.file_path FROM task_note_images tni JOIN task_notes tn ON tni.note_id=tn.id WHERE tni.id=? AND tn.user_id=?");
        $stmt->execute([$image_id, $user_id]);
        $img = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            if (file_exists($img['file_path'])) unlink($img['file_path']);
            $pdo->prepare("DELETE FROM task_note_images WHERE id=?")->execute([$image_id]);
            echo json_encode(['success' => true]);
        } else { echo json_encode(['success' => false, 'error' => 'Sem permissão']); }
    } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

// Obter lista de utilizadores para dropdown
$users = $pdo->query('SELECT user_id, username FROM user_tokens ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Marked.js para renderizar Markdown -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<!-- CSS do Editor de Tasks -->
<style>
#task-editor-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 9999;
    backdrop-filter: blur(5px);
}

#task-editor-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

#task-editor-unsaved {
    display: none;
    background: #fff3cd;
    border-bottom: 2px solid #ffc107;
    padding: 10px 16px;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: #856404;
}
#task-editor-unsaved.active { display: flex; }
#task-editor-unsaved span { flex: 1; }
#task-editor-unsaved button { font-size: 13px; padding: 4px 12px; border-radius: 6px; border: none; cursor: pointer; }
#task-editor-unsaved .btn-save   { background: #0d6efd; color: #fff; }
#task-editor-unsaved .btn-save:hover   { background: #0b5ed7; }
#task-editor-unsaved .btn-discard { background: #dc3545; color: #fff; }
#task-editor-unsaved .btn-discard:hover { background: #bb2d3b; }
#task-editor-unsaved .btn-cancel  { background: #6c757d; color: #fff; }
#task-editor-unsaved .btn-cancel:hover  { background: #5c636a; }

.task-editor-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-editor-header h3 {
    margin: 0;
    font-size: 1.5rem;
}

.task-editor-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 20px;
    transition: all 0.3s;
}

.task-editor-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.task-editor-body {
    padding: 30px;
    overflow-y: auto;
    flex: 1;
}

.task-editor-footer {
    padding: 20px 30px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #333;
}

.form-control {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

/* Editor Markdown */
.markdown-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.markdown-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #666;
}

.markdown-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.markdown-toolbar {
    display: flex;
    gap: 5px;
    margin-bottom: 10px;
    padding: 10px;
    background: #f5f5f5;
    border-radius: 8px;
    flex-wrap: wrap;
}

.markdown-btn {
    padding: 5px 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}

.markdown-btn:hover {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

/* Preview de Markdown */
#markdown-preview {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    min-height: 120px;
    background: #fafafa;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
}

#markdown-preview h1 { font-size: 2em; margin-top: 0; }
#markdown-preview h2 { font-size: 1.5em; margin-top: 1em; }
#markdown-preview h3 { font-size: 1.17em; margin-top: 1em; }
#markdown-preview code {
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
}
#markdown-preview pre {
    background: #f0f0f0;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}
#markdown-preview blockquote {
    border-left: 4px solid #667eea;
    padding-left: 15px;
    margin-left: 0;
    color: #666;
}
#markdown-preview ul, #markdown-preview ol {
    padding-left: 25px;
}
#markdown-preview a {
    color: #667eea;
    text-decoration: none;
}
#markdown-preview a:hover {
    text-decoration: underline;
}
#markdown-preview img {
    max-width: 100%;
    height: auto;
}
#markdown-preview table {
    border-collapse: collapse;
    width: 100%;
}
#markdown-preview table td, #markdown-preview table th {
    border: 1px solid #ddd;
    padding: 8px;
}
#markdown-preview table th {
    background-color: #f0f0f0;
    font-weight: bold;
}

/* Checklist */
.checklist-container {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    max-height: 300px;
    overflow-y: auto;
}

.checklist-item {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.checklist-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.checklist-item input[type="text"] {
    flex: 1;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.checklist-item.checked input[type="text"] {
    text-decoration: line-through;
    opacity: 0.6;
}

.checklist-item button {
    padding: 5px 10px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-add-checklist {
    margin-top: 10px;
    padding: 8px 15px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}

.btn-add-checklist:hover {
    background: #218838;
}

/* Ficheiros */
.files-container {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
}

.file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 10px;
}

.file-item span {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-item div {
    display: flex;
    gap: 5px;
}

/* Botões */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

/* Task Notes */
.tn-user-block { border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:6px; }
.tn-user-header { display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; background:#f8f9fa; user-select:none; }
.tn-user-header:hover { background:#e9ecef; }
.tn-collapsed .tn-chevron { transform:rotate(-90deg); }
.tn-chevron { transition:transform .2s; }
.tn-avatar { width:28px; height:28px; border-radius:50%; background:#6c757d; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
.tn-avatar-me { background:#0d6efd; }
.tn-user-notes { padding:8px; }
.tn-note-item { padding:8px; border-bottom:1px solid #f0f0f0; }
.tn-note-item:last-child { border-bottom:none; }
.tn-note-meta { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.tn-note-md-view { font-size:13px; color:#333; line-height:1.6; }
.tn-note-md-view p { margin:0 0 .4em; }
.tn-note-md-view ul,.tn-note-md-view ol { padding-left:1.4em; margin:.3em 0; }
.tn-note-md-view code { background:#f0f0f0; border-radius:3px; padding:1px 4px; font-size:12px; }
.tn-note-md-view h1,.tn-note-md-view h2,.tn-note-md-view h3 { font-size:14px; font-weight:700; margin:.5em 0 .2em; }
.tn-note-md-view blockquote { border-left:3px solid #ccc; margin:.3em 0; padding-left:8px; color:#666; }
.tn-note-md-view a { color:#0d6efd; }
.tn-note-md-view img { max-width:100%; max-height:280px; border-radius:4px; border:1px solid #dee2e6; cursor:zoom-in; display:block; margin:6px 0; }
.tn-note-images { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.tn-img-wrap img { width:72px; height:72px; object-fit:cover; border-radius:4px; cursor:zoom-in; border:1px solid #dee2e6; }
.tn-note-edit-area { margin-top:8px; padding:8px; background:#f8f9fa; border-radius:6px; border:1px solid #dee2e6; }
.tn-editor-tabs { display:flex; gap:4px; margin-bottom:4px; }
.tn-tab { border:1px solid #dee2e6; border-radius:4px; padding:2px 10px; font-size:12px; cursor:pointer; background:#fff; color:#6c757d; transition:all .15s; }
.tn-tab.active { border-color:#0d6efd; color:#0d6efd; font-weight:600; }
.tn-tab:hover:not(.active) { background:#e9ecef; }
.tn-md-toolbar { display:flex; align-items:center; gap:3px; flex-wrap:wrap; background:#f1f3f5; border:1px solid #dee2e6; border-bottom:none; border-radius:4px 4px 0 0; padding:4px 6px; }
.tn-md-toolbar button { border:1px solid #ced4da; background:#fff; border-radius:3px; padding:1px 6px; font-size:11px; cursor:pointer; line-height:1.6; white-space:nowrap; }
.tn-md-toolbar button:hover { background:#e9ecef; }
.tn-sep { width:1px; height:16px; background:#ced4da; margin:0 2px; flex-shrink:0; }
.tn-md-toolbar + .tn-md-textarea { border-radius:0 0 4px 4px; }
.tn-md-textarea { width:100%; font-size:13px; font-family:monospace; border:1px solid #ced4da; border-radius:0 0 4px 4px; padding:6px 8px; }
.tn-md-live-preview { border:1px solid #ced4da; border-radius:4px; min-height:80px; padding:8px 12px; font-size:13px; line-height:1.6; background:#fff; }
.tn-md-live-preview p { margin:0 0 .4em; }
.tn-md-live-preview ul,.tn-md-live-preview ol { padding-left:1.4em; margin:.3em 0; }
.tn-md-live-preview code { background:#f0f0f0; border-radius:3px; padding:1px 4px; font-size:12px; }
.tn-md-live-preview h1,.tn-md-live-preview h2,.tn-md-live-preview h3 { font-size:14px; font-weight:700; margin:.5em 0 .2em; }
.tn-md-live-preview blockquote { border-left:3px solid #ccc; margin:.3em 0; padding-left:8px; color:#666; }
.tn-md-live-preview img { max-width:100%; max-height:280px; border-radius:4px; border:1px solid #dee2e6; display:block; margin:6px 0; }
.tn-edit-img-gallery { background:#fff; border:1px solid #e9ecef; border-radius:4px; padding:8px; margin-top:6px; }
.tn-edit-img-ref { position:relative; width:64px; height:64px; cursor:pointer; border-radius:4px; overflow:hidden; border:2px solid transparent; transition:border-color .15s; flex-shrink:0; }
.tn-edit-img-ref:hover { border-color:#0d6efd; }
.tn-edit-img-ref img { width:100%; height:100%; object-fit:cover; }
.tn-edit-img-ref-overlay { position:absolute; inset:0; background:rgba(13,110,253,.4); display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px; opacity:0; transition:opacity .15s; }
.tn-edit-img-ref:hover .tn-edit-img-ref-overlay { opacity:1; }
.note-file-chip { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border:1px solid #dee2e6; border-radius:6px; background:#f8f9fa; font-size:13px; max-width:240px; }
.note-file-chip a { text-overflow:ellipsis; overflow:hidden; white-space:nowrap; color:#0d6efd; text-decoration:none; }
.note-file-chip a:hover { text-decoration:underline; }
.note-chip-del { background:none; border:none; color:#dc3545; cursor:pointer; padding:0 2px; font-size:14px; line-height:1; }
.tn-edit-file-ref { position:relative; display:inline-flex; flex-direction:column; align-items:center; justify-content:center; width:64px; height:64px; border:1px solid #dee2e6; border-radius:4px; background:#f8f9fa; cursor:pointer; overflow:hidden; gap:2px; }
.tn-edit-file-ref:hover { border-color:#0d6efd; background:#e9f0ff; }
.ln-file-ref-name { font-size:9px; color:#6c757d; text-align:center; max-width:60px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
#tn-lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:99999; align-items:center; justify-content:center; cursor:zoom-out; }
#tn-lightbox img { max-width:90vw; max-height:90vh; border-radius:6px; }
</style>

<!-- Modal HTML -->
<div id="task-editor-overlay">
    <div id="task-editor-modal">
        <div class="task-editor-header">
            <h3>✏️ Editar Task</h3>
            <div style="display:flex;align-items:center;gap:8px;margin-left:auto;">
                <div style="position:relative;display:inline-block;">
                    <button id="task-share-btn" onclick="shareTaskLink()" title="Copiar link para esta tarefa"
                        style="background:none;border:1px solid rgba(255,255,255,0.4);border-radius:6px;color:inherit;cursor:pointer;padding:4px 8px;font-size:16px;line-height:1;">
                        <i class="bi bi-link-45deg"></i>
                    </button>
                    <span id="task-share-tooltip"
                        style="display:none;position:absolute;top:calc(100% + 6px);right:0;background:#333;color:#fff;font-size:12px;padding:4px 8px;border-radius:4px;white-space:nowrap;z-index:10;">
                        Copiado!
                    </span>
                </div>
                <button class="task-editor-close" onclick="closeTaskEditor()">&times;</button>
            </div>
        </div>

        <div id="task-editor-unsaved">
            <span>⚠️ Tens alterações não guardadas.</span>
            <button class="btn-save"    onclick="saveTask()">💾 Guardar</button>
            <button class="btn-discard" onclick="closeTaskEditor(true)">🗑️ Descartar</button>
            <button class="btn-cancel"  onclick="document.getElementById('task-editor-unsaved').classList.remove('active')">Cancelar</button>
        </div>

        <div class="task-editor-body">
            <form id="task-editor-form">
                <input type="hidden" id="edit_todo_id" name="todo_id">
                
                <!-- Título -->
                <div class="form-group">
                    <label for="edit_titulo">📝 Título *</label>
                    <input type="text" id="edit_titulo" name="titulo" class="form-control" required>
                </div>
                
                <!-- Linha com Data e Responsável -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label for="edit_data_limite">📅 Data Limite</label>
                        <input type="date" id="edit_data_limite" name="data_limite" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_responsavel">👤 Responsável</label>
                        <select id="edit_responsavel" name="responsavel" class="form-control">
                            <option value="">Sem responsável</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Estado -->
                <div class="form-group">
                    <label for="edit_estado">🎯 Estado</label>
                    <select id="edit_estado" name="estado" class="form-control">
                        <option value="aberta">Aberta</option>
                        <option value="em execução">Em Execução</option>
                        <option value="suspensa">Suspensa</option>
                        <option value="concluída">Concluída</option>
                    </select>
                </div>
                
                <!-- Descrição com Markdown -->
                <div class="form-group">
                    <div class="markdown-header">
                        <label for="edit_descritivo">📄 Descrição (Markdown)</label>
                        <div class="markdown-toggle">
                            <input type="checkbox" id="edit-mode-toggle" onchange="toggleEditMode()">
                            <label for="edit-mode-toggle" style="margin: 0; font-weight: normal; cursor: pointer;">
                                ✏️ Modo Edição
                            </label>
                        </div>
                    </div>
                    
                    <div id="markdown-toolbar" class="markdown-toolbar" style="display: none;">
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('**', '**')"><b>B</b></button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('*', '*')"><i>I</i></button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('# ', '')">H1</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('## ', '')">H2</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('### ', '')">H3</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('- ', '')">• Lista</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('1. ', '')">1. Lista</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('[', '](url)')">🔗 Link</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('`', '`')">Code</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('> ', '')">💬 Quote</button>
                    </div>
                    
                    <textarea id="edit_descritivo" name="descritivo" class="form-control" style="display: none;" oninput="updatePreview()"></textarea>
                    <div id="markdown-preview"></div>
                </div>
                
                <!-- Checklist -->
                <div class="form-group">
                    <label>✅ Checklist de Itens</label>
                    <div class="checklist-container" id="checklist-container">
                        <!-- Items serão adicionados aqui -->
                    </div>
                    <button type="button" class="btn-add-checklist" onclick="addChecklistItem()">+ Adicionar Item</button>
                </div>
                
                <!-- Upload de Ficheiros -->
                <div class="form-group">
                    <label>📎 Ficheiros Anexados</label>
                    <div class="files-container">
                        <input type="file" id="file-upload" style="display:none" onchange="uploadFile()">
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('file-upload').click()">
                            📤 Escolher Ficheiro
                        </button>
                        <div id="files-list" style="margin-top: 15px;">
                            <!-- Ficheiros serão listados aqui -->
                        </div>
                    </div>
                </div>
            </form>

            <!-- Notas por utilizador -->
            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e9ecef;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <label style="margin:0;font-weight:600;font-size:14px;">💬 Notas</label>
                    <button type="button" onclick="tnToggleAdd()"
                        style="background:#28a745;color:#fff;border:none;border-radius:4px;padding:3px 10px;font-size:12px;cursor:pointer;">
                        + Adicionar nota
                    </button>
                </div>
                <div id="tn-add-form" style="display:none;margin-bottom:10px;padding:10px;background:#f8f9fa;border-radius:6px;border:1px solid #dee2e6;">
                    <div class="tn-editor-wrap">
                        <div class="tn-editor-tabs">
                            <button type="button" class="tn-tab active" onclick="tnEditorTab(this,'write')">Escrever</button>
                            <button type="button" class="tn-tab" onclick="tnEditorTab(this,'preview')">Pré-visualizar</button>
                        </div>
                        <div class="tn-md-toolbar">
                            <button type="button" onclick="tnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                            <button type="button" onclick="tnMdWrap(this,'*','*')" title="Itálico"><em>I</em></button>
                            <button type="button" onclick="tnMdWrap(this,'***','***')" title="BI"><b><em>BI</em></b></button>
                            <button type="button" onclick="tnMdWrap(this,'~~','~~')" title="Riscado"><s>S</s></button>
                            <span class="tn-sep"></span>
                            <button type="button" onclick="tnMdWrap(this,'`','`')" title="Código inline"><code>c</code></button>
                            <button type="button" onclick="tnMdBlock(this,'```\n','\n```')" title="Bloco"><code>```</code></button>
                            <span class="tn-sep"></span>
                            <button type="button" onclick="tnMdInsert(this,'# ')" style="font-weight:700;">H1</button>
                            <button type="button" onclick="tnMdInsert(this,'## ')" style="font-weight:700;">H2</button>
                            <button type="button" onclick="tnMdInsert(this,'### ')" style="font-weight:700;">H3</button>
                            <span class="tn-sep"></span>
                            <button type="button" onclick="tnMdInsert(this,'- ')">• ≡</button>
                            <button type="button" onclick="tnMdInsert(this,'1. ')">1.</button>
                            <button type="button" onclick="tnMdInsert(this,'- [ ] ')">☐</button>
                            <span class="tn-sep"></span>
                            <button type="button" onclick="tnMdInsert(this,'> ')">❝</button>
                            <button type="button" onclick="tnMdWrap(this,'[','](url)')">🔗</button>
                            <button type="button" onclick="tnMdInsertLine(this,'---')">—</button>
                            <button type="button" onclick="tnMdTable(this)">⊞</button>
                        </div>
                        <textarea id="tn-add-text" class="tn-md-textarea" rows="4"
                                  placeholder="Suporta **Markdown**…"></textarea>
                        <div class="tn-md-live-preview" style="display:none;"></div>
                    </div>
                    <div style="margin-top:8px;">
                        <label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Ficheiros (opcional)</label>
                        <input type="file" id="tn-add-images" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" multiple style="font-size:12px;"
                               onchange="tnPreviewImages(this,'tn-add-preview')">
                        <div id="tn-add-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:10px;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="tnAddNote()"
                            style="padding:4px 12px;font-size:12px;">Guardar</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="tnToggleAdd()"
                            style="padding:4px 12px;font-size:12px;">Cancelar</button>
                    </div>
                </div>
                <div id="tn-notes-list"></div>
            </div>
        </div>

        <div class="task-editor-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTaskEditor()">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="saveTask()">💾 Guardar</button>
        </div>
    </div>
</div>

<!-- Lightbox de notas -->
<div id="tn-lightbox" onclick="this.style.display='none'">
    <img src="" alt="">
</div>

<script>
// Variáveis globais
let checklistItems = [];
let taskFiles = [];
let isEditMode = false;

// Abrir editor
function openTaskEditor(taskId) {
    fetch(`api/get_task_full.php?id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Verificar se pode editar (opcional - pode mostrar apenas em modo leitura)
                const canEdit = data.can_edit !== false; // Por padrão assume que pode
                
                // Preencher formulário
                document.getElementById('edit_todo_id').value = data.task.id;
                document.getElementById('edit_titulo').value = data.task.titulo || '';
                document.getElementById('edit_descritivo').value = data.task.descritivo || '';
                document.getElementById('edit_data_limite').value = data.task.data_limite || '';
                document.getElementById('edit_responsavel').value = data.task.responsavel || '';
                document.getElementById('edit_estado').value = data.task.estado || 'aberta';
                
                // Carregar checklist
                checklistItems = data.checklist || [];
                renderChecklist();
                
                // Carregar ficheiros
                taskFiles = data.files || [];
                renderFiles();
                
                // Iniciar em modo preview
                isEditMode = false;
                document.getElementById('edit-mode-toggle').checked = false;
                updatePreview();
                toggleEditMode();
                
                // Carregar notas
                tnLoadNotes(data.task.id);

                // Mostrar modal
                window._taskEditorDirty = false;
                document.getElementById('task-editor-unsaved').classList.remove('active');
                document.getElementById('task-editor-overlay').style.display = 'block';
            } else {
                alert('Erro ao carregar task: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erro ao carregar task');
        });
}

// Fechar editor
function closeTaskEditor(force) {
    if (!force && window._taskEditorDirty) {
        document.getElementById('task-editor-unsaved').classList.add('active');
        return;
    }
    window._taskEditorDirty = false;
    document.getElementById('task-editor-unsaved').classList.remove('active');
    document.getElementById('task-editor-overlay').style.display = 'none';
}

// Alternar entre modo preview e edição
function toggleEditMode() {
    isEditMode = document.getElementById('edit-mode-toggle').checked;
    const textarea = document.getElementById('edit_descritivo');
    const preview = document.getElementById('markdown-preview');
    const toolbar = document.getElementById('markdown-toolbar');
    
    if (isEditMode) {
        // Modo Edição
        textarea.style.display = 'block';
        preview.style.display = 'none';
        toolbar.style.display = 'flex';
    } else {
        // Modo Preview
        textarea.style.display = 'none';
        preview.style.display = 'block';
        toolbar.style.display = 'none';
        updatePreview();
    }
}

// Atualizar preview do Markdown
function updatePreview() {
    const textarea = document.getElementById('edit_descritivo');
    const preview = document.getElementById('markdown-preview');
    const markdown = textarea.value;
    
    if (markdown.trim() === '') {
        preview.innerHTML = '<em style="color: #999;">Sem descrição</em>';
    } else {
        // Usar marked.js para renderizar Markdown
        preview.innerHTML = marked.parse(markdown);
    }
}

// Inserir markdown
function insertMarkdown(before, after) {
    const textarea = document.getElementById('edit_descritivo');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selectedText = text.substring(start, end);
    
    const newText = text.substring(0, start) + before + selectedText + after + text.substring(end);
    textarea.value = newText;
    textarea.focus();
    textarea.setSelectionRange(start + before.length, end + before.length);
    updatePreview();
}

// Checklist functions
function addChecklistItem() {
    checklistItems.push({ text: '', checked: false });
    renderChecklist();
}

function renderChecklist() {
    const container = document.getElementById('checklist-container');
    container.innerHTML = '';
    
    checklistItems.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = 'checklist-item' + (item.checked ? ' checked' : '');
        div.innerHTML = `
            <input type="checkbox" ${item.checked ? 'checked' : ''} onchange="toggleChecklistItem(${index})">
            <input type="text" value="${item.text}" onchange="updateChecklistItem(${index}, this.value)" placeholder="Descrição do item...">
            <button type="button" onclick="removeChecklistItem(${index})">🗑️</button>
        `;
        container.appendChild(div);
    });
}

function toggleChecklistItem(index) {
    checklistItems[index].checked = !checklistItems[index].checked;
    renderChecklist();
}

function updateChecklistItem(index, text) {
    checklistItems[index].text = text;
}

function removeChecklistItem(index) {
    checklistItems.splice(index, 1);
    renderChecklist();
}

// Upload de ficheiro
function uploadFile() {
    const fileInput = document.getElementById('file-upload');
    const file = fileInput.files[0];
    if (!file) return;
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('todo_id', document.getElementById('edit_todo_id').value);
    formData.append('action', 'upload_file');
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            taskFiles.push(data);
            renderFiles();
            fileInput.value = '';
        } else {
            alert('Erro no upload: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erro no upload');
    });
}

// Renderizar ficheiros
function renderFiles() {
    const container = document.getElementById('files-list');
    container.innerHTML = '';
    
    taskFiles.forEach(file => {
        const div = document.createElement('div');
        div.className = 'file-item';
        div.innerHTML = `
            <span>📄 ${file.file_name}</span>
            <div>
                <a href="${file.file_path}" target="_blank" class="btn btn-sm btn-primary">Ver</a>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteFile(${file.file_id})">🗑️</button>
            </div>
        `;
        container.appendChild(div);
    });
}

// Eliminar ficheiro
function deleteFile(fileId) {
    if (!confirm('Eliminar este ficheiro?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_file');
    formData.append('file_id', fileId);
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            taskFiles = taskFiles.filter(f => f.file_id != fileId);
            renderFiles();
        } else {
            alert('Erro ao eliminar: ' + data.error);
        }
    });
}

// Guardar task
function saveTask() {
    const formData = new FormData();
    formData.append('action', 'save_task');
    formData.append('todo_id', document.getElementById('edit_todo_id').value);
    formData.append('titulo', document.getElementById('edit_titulo').value);
    formData.append('descritivo', document.getElementById('edit_descritivo').value);
    formData.append('data_limite', document.getElementById('edit_data_limite').value);
    formData.append('responsavel', document.getElementById('edit_responsavel').value);
    formData.append('estado', document.getElementById('edit_estado').value);
    
    // Adicionar checklist
    checklistItems.forEach((item, index) => {
        formData.append(`checklist[${index}][text]`, item.text);
        formData.append(`checklist[${index}][checked]`, item.checked ? '1' : '0');
    });
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window._taskEditorDirty = false;
            alert('Task guardada com sucesso!');
            closeTaskEditor(true);
            location.reload();
        } else {
            alert('Erro ao guardar: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erro ao guardar task');
    });
}

// Gerar e copiar link de partilha da tarefa
function shareTaskLink() {
    var taskId = document.getElementById('edit_todo_id').value;
    if (!taskId) return;
    var base = window.location.origin + window.location.pathname;
    var link = base + '?tab=todos&open_task=' + taskId;
    navigator.clipboard.writeText(link).then(function() {
        var tooltip = document.getElementById('task-share-tooltip');
        tooltip.style.display = 'block';
        setTimeout(function() { tooltip.style.display = 'none'; }, 2000);
    }).catch(function() {
        prompt('Copia este link:', link);
    });
}

// Marcar dirty quando o utilizador edita qualquer campo do editor
document.getElementById('task-editor-form').addEventListener('input', function() {
    window._taskEditorDirty = true;
});
document.getElementById('task-editor-form').addEventListener('change', function() {
    window._taskEditorDirty = true;
});

/* ===== TASK NOTES ===== */
var _tnCurrentTaskId = null;
var _tnCurrentUserId = null;

function tnLoadNotes(taskId) {
    _tnCurrentTaskId = taskId;
    document.getElementById('tn-notes-list').innerHTML = '<p style="color:#999;font-size:12px;padding:6px;">A carregar…</p>';
    fetch('edit_task.php?action=get_task_notes&task_id=' + taskId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            _tnCurrentUserId = data.current_user_id;
            tnRenderNotes(data.notes, data.current_user_id);
        }
    });
}

function tnToggleAdd() {
    var f = document.getElementById('tn-add-form');
    var showing = f.style.display !== 'none';
    f.style.display = showing ? 'none' : 'block';
    if (!showing) {
        var wrap = f.querySelector('.tn-editor-wrap');
        if (wrap) {
            var tabs = wrap.querySelectorAll('.tn-tab');
            tabs[0].classList.add('active'); tabs[1].classList.remove('active');
            wrap.querySelector('.tn-md-toolbar').style.display = '';
            wrap.querySelector('.tn-md-textarea').style.display = '';
            wrap.querySelector('.tn-md-live-preview').style.display = 'none';
        }
        document.getElementById('tn-add-text').focus();
    }
}

function tnAddNote() {
    var taskId = _tnCurrentTaskId;
    var text = document.getElementById('tn-add-text').value.trim();
    var files = document.getElementById('tn-add-images').files;
    var fd = new FormData();
    fd.append('action', 'add_task_note');
    fd.append('task_id', taskId);
    fd.append('note_text', text);
    Array.from(files).forEach(function(f) { fd.append('note_images[]', f); });
    fetch('edit_task.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('tn-add-text').value = '';
            document.getElementById('tn-add-preview').innerHTML = '';
            document.getElementById('tn-add-images').value = '';
            document.getElementById('tn-add-form').style.display = 'none';
            tnLoadNotes(taskId);
        } else { alert('Erro: ' + (data.error || 'desconhecido')); }
    });
}

function tnRenderNotes(notes, currentUserId) {
    var container = document.getElementById('tn-notes-list');
    if (!notes.length) {
        container.innerHTML = '<p style="color:#999;font-size:13px;text-align:center;padding:8px;">Nenhuma nota ainda.</p>';
        return;
    }
    var byUser = {};
    notes.forEach(function(n) {
        if (!byUser[n.user_id]) byUser[n.user_id] = {username: n.username, notes: []};
        byUser[n.user_id].notes.push(n);
    });
    var html = '';
    Object.keys(byUser).forEach(function(uid) {
        var udata = byUser[uid];
        var isMe = (parseInt(uid) === parseInt(currentUserId));
        var initial = udata.username.charAt(0).toUpperCase();
        html += '<div class="tn-user-block">';
        html += '<div class="tn-user-header tn-collapsed" onclick="tnToggleUser(this)">';
        html += '<span class="tn-avatar' + (isMe ? ' tn-avatar-me' : '') + '">' + initial + '</span>';
        html += '<span style="font-weight:600;font-size:14px;">' + tnH(udata.username);
        if (isMe) html += '<span style="font-size:10px;font-weight:400;background:#6c757d;color:#fff;border-radius:4px;padding:1px 5px;margin-left:4px;">Eu</span>';
        html += '</span>';
        html += '<span style="font-size:12px;color:#6c757d;margin-left:4px;">(' + udata.notes.length + ')</span>';
        html += '<i class="bi bi-chevron-down tn-chevron" style="margin-left:auto;font-size:12px;"></i></div>';
        html += '<div class="tn-user-notes" style="display:none;">';
        udata.notes.forEach(function(note) { html += tnNoteHtml(note, isMe); });
        html += '</div></div>';
    });
    container.innerHTML = html;
    container.querySelectorAll('.tn-note-md-view').forEach(function(el) {
        var raw = el.dataset.raw || '';
        el.innerHTML = raw ? marked.parse(raw) : '';
    });
}

function tnNoteHtml(note, canEdit) {
    var dt = note.created_at ? note.created_at.replace(' ', 'T') : '';
    var dtObj = dt ? new Date(dt) : null;
    var dtStr = dtObj ? dtObj.toLocaleDateString('pt-PT') + ' ' + dtObj.toLocaleTimeString('pt-PT', {hour:'2-digit',minute:'2-digit'}) : '';
    var html = '<div class="tn-note-item" id="tn-note-' + note.id + '">';
    html += '<div class="tn-note-meta">';
    html += '<small style="color:#6c757d;">' + dtStr + '</small>';
    if (canEdit) {
        html += '<span style="display:flex;gap:4px;">';
        html += '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:11px;border:1px solid #6c757d;background:#fff;border-radius:3px;cursor:pointer;" onclick="tnStartEdit(' + note.id + ')"><i class="bi bi-pencil"></i></button>';
        html += '<button type="button" class="btn btn-sm" style="padding:1px 6px;font-size:11px;border:1px solid #dc3545;color:#dc3545;background:#fff;border-radius:3px;cursor:pointer;" onclick="tnDeleteNote(' + note.id + ')"><i class="bi bi-trash"></i></button>';
        html += '</span>';
    }
    html += '</div>';
    html += '<div class="tn-note-md-view" data-raw="' + tnA(note.note_text) + '"></div>';
    if (note.images && note.images.length) {
        html += '<div class="tn-note-images">';
        note.images.forEach(function(img) {
            if (/\.(jpe?g|png|gif|webp|svg)$/i.test(img.original_name || img.file_path)) {
                html += '<div class="tn-img-wrap"><img src="' + tnH(img.file_path) + '" onclick="tnLightbox(this.src)" title="' + tnA(img.original_name) + '"></div>';
            } else {
                html += '<div class="note-file-chip"><i class="bi ' + tnFileIcon(img.original_name || img.file_path) + '"></i><a href="' + tnH(img.file_path) + '" target="_blank" title="' + tnA(img.original_name) + '">' + tnH(img.original_name || img.file_path) + '</a></div>';
            }
        });
        html += '</div>';
    }
    if (canEdit) {
        html += '<div class="tn-note-edit-area" style="display:none;">';
        html += '<div class="tn-editor-wrap">';
        html += '<div class="tn-editor-tabs"><button type="button" class="tn-tab active" onclick="tnEditorTab(this,\'write\')">Escrever</button><button type="button" class="tn-tab" onclick="tnEditorTab(this,\'preview\')">Pré-visualizar</button></div>';
        html += '<div class="tn-md-toolbar"><button type="button" onclick="tnMdWrap(this,\'**\',\'**\')"><b>B</b></button><button type="button" onclick="tnMdWrap(this,\'*\',\'*\')"><em>I</em></button><button type="button" onclick="tnMdWrap(this,\'***\',\'***\')"><b><em>BI</em></b></button><button type="button" onclick="tnMdWrap(this,\'~~\',\'~~\')"><s>S</s></button><span class="tn-sep"></span><button type="button" onclick="tnMdWrap(this,\'`\',\'`\')"><code>c</code></button><button type="button" onclick="tnMdBlock(this,\'```\\n\',\'\\n```\')"><code>```</code></button><span class="tn-sep"></span><button type="button" onclick="tnMdInsert(this,\'# \')">H1</button><button type="button" onclick="tnMdInsert(this,\'## \')">H2</button><button type="button" onclick="tnMdInsert(this,\'### \')">H3</button><span class="tn-sep"></span><button type="button" onclick="tnMdInsert(this,\'- \')">• ≡</button><button type="button" onclick="tnMdInsert(this,\'1. \')">1.</button><button type="button" onclick="tnMdInsert(this,\'- [ ] \')">☐</button><span class="tn-sep"></span><button type="button" onclick="tnMdInsert(this,\'> \')">❝</button><button type="button" onclick="tnMdWrap(this,\'[\',\'](url)\')">🔗</button><button type="button" onclick="tnMdInsertLine(this,\'---\')">—</button><button type="button" onclick="tnMdTable(this)">⊞</button></div>';
        html += '<textarea class="tn-md-textarea" rows="4">' + tnT(note.note_text) + '</textarea>';
        html += '<div class="tn-md-live-preview" style="display:none;"></div>';
        html += '</div>';
        if (note.images && note.images.length) {
            html += '<div class="tn-edit-img-gallery"><div style="font-size:12px;color:#6c757d;margin-bottom:4px;">Clica num ficheiro para o inserir no texto:</div><div style="display:flex;flex-wrap:wrap;gap:6px;">';
            note.images.forEach(function(img) {
                if (/\.(jpe?g|png|gif|webp|svg)$/i.test(img.original_name || img.file_path)) {
                    html += '<div class="tn-edit-img-ref" onclick="tnInsertImgRef(' + note.id + ',\'' + tnJ(img.file_path) + '\',\'' + tnJ(img.original_name) + '\')" title="' + tnA(img.original_name) + '">';
                    html += '<img src="' + tnH(img.file_path) + '" alt=""><div class="tn-edit-img-ref-overlay"><i class="bi bi-plus-circle-fill"></i></div></div>';
                } else {
                    html += '<div class="tn-edit-file-ref" onclick="tnInsertFileRef(' + note.id + ',\'' + tnJ(img.file_path) + '\',\'' + tnJ(img.original_name) + '\')" title="' + tnA(img.original_name) + '">';
                    html += '<i class="bi ' + tnFileIcon(img.original_name || img.file_path) + ' fs-4"></i><div class="ln-file-ref-name">' + tnH(img.original_name || img.file_path) + '</div></div>';
                }
            });
            html += '</div></div>';
        }
        html += '<div style="margin-top:8px;"><label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">Adicionar ficheiros</label>';
        html += '<input type="file" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" multiple style="font-size:12px;" onchange="tnPreviewImages(this,\'tn-edit-preview-' + note.id + '\')">';
        html += '<div id="tn-edit-preview-' + note.id + '" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div></div>';
        html += '<div style="display:flex;gap:8px;margin-top:10px;">';
        html += '<button type="button" class="btn btn-sm btn-primary" onclick="tnSaveEdit(' + note.id + ')" style="padding:4px 12px;font-size:12px;">Guardar</button>';
        html += '<button type="button" class="btn btn-sm btn-secondary" onclick="tnCancelEdit(' + note.id + ')" style="padding:4px 12px;font-size:12px;">Cancelar</button>';
        html += '</div></div>';
    }
    html += '</div>';
    return html;
}

function tnToggleUser(header) {
    var notes = header.nextElementSibling;
    var collapsed = header.classList.toggle('tn-collapsed');
    notes.style.display = collapsed ? 'none' : 'block';
}

function tnStartEdit(noteId) {
    var item = document.getElementById('tn-note-' + noteId);
    item.querySelector('.tn-note-md-view').style.display = 'none';
    var ea = item.querySelector('.tn-note-edit-area');
    ea.style.display = 'block';
    var wrap = ea.querySelector('.tn-editor-wrap');
    if (wrap) {
        var tabs = wrap.querySelectorAll('.tn-tab');
        tabs[0].classList.add('active'); tabs[1].classList.remove('active');
        wrap.querySelector('.tn-md-toolbar').style.display = '';
        wrap.querySelector('.tn-md-textarea').style.display = '';
        wrap.querySelector('.tn-md-live-preview').style.display = 'none';
    }
    ea.querySelector('textarea').focus();
    var btns = item.querySelector('.tn-note-meta span');
    if (btns) btns.style.visibility = 'hidden';
}

function tnCancelEdit(noteId) {
    var item = document.getElementById('tn-note-' + noteId);
    item.querySelector('.tn-note-md-view').style.display = '';
    item.querySelector('.tn-note-edit-area').style.display = 'none';
    var btns = item.querySelector('.tn-note-meta span');
    if (btns) btns.style.visibility = '';
}

function tnSaveEdit(noteId) {
    var item = document.getElementById('tn-note-' + noteId);
    var ta = item.querySelector('.tn-note-edit-area .tn-md-textarea');
    var fileInput = item.querySelector('.tn-note-edit-area input[type=file]');
    var fd = new FormData();
    fd.append('action', 'edit_task_note');
    fd.append('note_id', noteId);
    fd.append('note_text', ta.value);
    if (fileInput && fileInput.files.length) {
        Array.from(fileInput.files).forEach(function(f) { fd.append('note_images[]', f); });
    }
    fetch('edit_task.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { tnLoadNotes(_tnCurrentTaskId); }
        else { alert('Erro: ' + (data.error || 'desconhecido')); }
    });
}

function tnDeleteNote(noteId) {
    if (!confirm('Eliminar esta nota?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_task_note');
    fd.append('note_id', noteId);
    fetch('edit_task.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { tnLoadNotes(_tnCurrentTaskId); }
        else { alert('Erro: ' + (data.error || 'desconhecido')); }
    });
}

function tnEditorTab(btn, mode) {
    var wrap = btn.closest('.tn-editor-wrap');
    var toolbar = wrap.querySelector('.tn-md-toolbar');
    var ta = wrap.querySelector('.tn-md-textarea');
    var preview = wrap.querySelector('.tn-md-live-preview');
    wrap.querySelectorAll('.tn-tab').forEach(function(t) { t.classList.toggle('active', t === btn); });
    if (mode === 'preview') {
        toolbar.style.display = 'none'; ta.style.display = 'none'; preview.style.display = '';
        preview.innerHTML = (typeof marked !== 'undefined') ? marked.parse(ta.value || '') : ta.value;
    } else {
        toolbar.style.display = ''; ta.style.display = ''; preview.style.display = 'none'; ta.focus();
    }
}

function tnFileIcon(n) { if(/\.pdf$/i.test(n)) return 'bi-file-earmark-pdf text-danger'; if(/\.docx?$/i.test(n)) return 'bi-file-earmark-word text-primary'; if(/\.xlsx?$/i.test(n)) return 'bi-file-earmark-excel text-success'; if(/\.pptx?$/i.test(n)) return 'bi-file-earmark-ppt text-warning'; if(/\.(zip|rar|7z)$/i.test(n)) return 'bi-file-earmark-zip text-secondary'; if(/\.(txt|csv)$/i.test(n)) return 'bi-file-earmark-text text-muted'; return 'bi-file-earmark text-muted'; }
function tnInsertFileRef(noteId, path, name) {
    var ta = document.querySelector('#tn-note-' + noteId + ' .tn-note-edit-area .tn-md-textarea');
    if (!ta) return;
    var text = '[' + name + '](' + path + ')';
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + text + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + text.length;
    ta.focus();
}
function tnPreviewImages(input, previewId) {
    var preview = document.getElementById(previewId);
    if (!preview) return;
    preview.innerHTML = '';
    Array.from(input.files).forEach(function(f) {
        if (/\.(jpe?g|png|gif|webp|svg)$/i.test(f.name)) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var img = document.createElement('img');
                img.src = e.target.result;
                img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;';
                preview.appendChild(img);
            };
            reader.readAsDataURL(f);
        } else {
            var chip = document.createElement('div');
            chip.className = 'note-file-chip';
            chip.innerHTML = '<i class="bi ' + tnFileIcon(f.name) + '"></i><span style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + f.name + '</span>';
            preview.appendChild(chip);
        }
    });
}

function tnInsertImgRef(noteId, path, alt) {
    var ta = document.querySelector('#tn-note-' + noteId + ' .tn-note-edit-area .tn-md-textarea');
    if (!ta) return;
    var text = '![' + alt + '](' + path + ')';
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + text + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + text.length;
    ta.focus();
}

function tnLightbox(src) {
    var lb = document.getElementById('tn-lightbox');
    lb.querySelector('img').src = src;
    lb.style.display = 'flex';
}

/* MD toolbar helpers */
function tnMdWrap(btn, before, after) {
    var ta = btn.closest('.tn-md-toolbar').nextElementSibling;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.substring(s, e) || 'texto';
    ta.value = ta.value.substring(0, s) + before + sel + after + ta.value.substring(e);
    ta.focus(); ta.selectionStart = s + before.length; ta.selectionEnd = s + before.length + sel.length;
}
function tnMdInsert(btn, prefix) {
    var ta = btn.closest('.tn-md-toolbar').nextElementSibling;
    var s = ta.selectionStart;
    var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
    ta.value = ta.value.substring(0, lineStart) + prefix + ta.value.substring(lineStart);
    ta.focus(); ta.selectionStart = ta.selectionEnd = lineStart + prefix.length;
}
function tnMdBlock(btn, before, after) {
    var ta = btn.closest('.tn-md-toolbar').nextElementSibling;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.substring(s, e) || 'código';
    var needsBefore = s > 0 && ta.value[s - 1] !== '\n' ? '\n' : '';
    var needsAfter  = e < ta.value.length && ta.value[e] !== '\n' ? '\n' : '';
    var ins = needsBefore + before + sel + after + needsAfter;
    ta.value = ta.value.substring(0, s) + ins + ta.value.substring(e);
    ta.focus();
}
function tnMdInsertLine(btn, text) {
    var ta = btn.closest('.tn-md-toolbar').nextElementSibling;
    var s = ta.selectionStart;
    var pre = ta.value.substring(0, s);
    var post = ta.value.substring(s);
    ta.value = pre + (pre.endsWith('\n') || pre === '' ? '' : '\n') + text + '\n' + post;
    ta.focus();
}
function tnMdTable(btn) {
    var tbl = '| Col 1 | Col 2 | Col 3 |\n|-------|-------|-------|\n| A     | B     | C     |\n';
    tnMdInsertLine(btn, tbl);
}

/* Escape helpers */
function tnH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function tnA(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function tnT(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function tnJ(s) { return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

// Fechar ao clicar fora (backdrop)
document.addEventListener('click', function(e) {
    if (e.target.id === 'task-editor-overlay') {
        closeTaskEditor();
    }
});
</script>