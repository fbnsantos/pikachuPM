<?php if ($checkPrototypes): ?>
<!-- Modal: Associar Protótipo -->
<div class="modal fade" id="addPrototypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Associar Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_prototype">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Protótipo *</label>
                        <select name="prototype_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($prototypes as $proto): ?>
                                <option value="<?= $proto['id'] ?>">
                                    <?= htmlspecialchars($proto['short_name']) ?> - <?= htmlspecialchars($proto['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        As user stories do protótipo aparecerão automaticamente após associar.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Gerir Tasks do Entregável -->
<div class="modal fade" id="manageTasksModal" tabindex="-1" aria-labelledby="manageTasksModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manageTasksModalLabel">
                    Gerir Tasks: <span id="taskModalDeliverableTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="taskModalDeliverableId">
                
                <!-- Tabs para Criar Nova ou Associar Existente -->
                <ul class="nav nav-tabs mb-3" id="taskTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="create-task-tab" data-bs-toggle="tab" data-bs-target="#create-task-panel" type="button" role="tab">
                            <i class="bi bi-plus-circle"></i> Criar Nova Task
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="link-task-tab" data-bs-toggle="tab" data-bs-target="#link-task-panel" type="button" role="tab">
                            <i class="bi bi-link-45deg"></i> Associar Task Existente
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Criar Nova Task -->
                    <div class="tab-pane fade show active" id="create-task-panel">
                        <form method="post">
                            <input type="hidden" name="action" value="create_new_task_for_deliverable">
                            <input type="hidden" name="deliverable_id" id="create_task_deliverable_id">
                            
                            <div class="mb-3">
                                <label class="form-label">Título da Task *</label>
                                <input type="text" name="task_title" class="form-control" required placeholder="Nome da task">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Descrição</label>
                                <textarea name="task_description" class="form-control" rows="3" placeholder="Detalhes da task"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Data Limite</label>
                                <input type="date" name="task_due_date" class="form-control">
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                A task será criada e automaticamente associada a este entregável. O estado do entregável será recalculado.
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-circle"></i> Criar e Associar Task
                            </button>
                        </form>
                    </div>
                    
                    <!-- Associar Task Existente -->
                    <div class="tab-pane fade" id="link-task-panel">
                        <?php if ($todosExist && !empty($availableTodos)): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="add_task_to_deliverable">
                                <input type="hidden" name="deliverable_id" id="link_task_deliverable_id">
                                
                                <div class="mb-3">
                                    <label class="form-label">Selecionar Task da tabela <code>todos</code> *</label>
                                    <select name="todo_id" class="form-select" required size="12" style="min-height: 400px;">
                                        <?php foreach ($availableTodos as $todo): ?>
                                            <option value="<?= $todo['id'] ?>" style="padding: 8px;">
                                                <?php 
                                                $estadoBadge = '';
                                                switch($todo['estado']) {
                                                    case 'aberta': $estadoBadge = '🟡'; break;
                                                    case 'em_progresso': $estadoBadge = '🔵'; break;
                                                    case 'fechada': $estadoBadge = '🟢'; break;
                                                    default: $estadoBadge = '⚪';
                                                }
                                                ?>
                                                <?= $estadoBadge ?> [<?= strtoupper($todo['estado']) ?>] #<?= $todo['id'] ?> - <?= htmlspecialchars($todo['titulo']) ?>
                                                <?php if ($todo['projeto_nome']): ?>
                                                    | 📁 <?= htmlspecialchars($todo['projeto_nome']) ?>
                                                <?php endif; ?>
                                                <?php if ($todo['autor_name']): ?>
                                                    | 👤 <?= htmlspecialchars($todo['autor_name']) ?>
                                                <?php endif; ?>
                                                <?php if ($todo['data_limite']): ?>
                                                    | 📅 <?= date('d/m/Y', strtotime($todo['data_limite'])) ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">
                                        📊 Mostrando as últimas 200 tasks da tabela <code>todos</code><br>
                                        🟡 Aberta | 🔵 Em Progresso | 🟢 Fechada
                                    </small>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    Estas tasks são da <strong>tabela todos</strong> (mesma do módulo todos.php). 
                                    Ao associar, a task mantém seu <code>projeto_id</code> original.
                                </div>
                                
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-link-45deg"></i> Associar Task Selecionada ao Entregável
                                </button>
                            </form>
                        <?php elseif (!$todosExist): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                A tabela <code>todos</code> não existe. Instale o módulo <code>todos.php</code> primeiro para poder associar tasks.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                Não há tasks disponíveis na tabela <code>todos</code>. Crie uma nova task usando a aba "Criar Nova Task" ou no módulo todos.php.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div><?php
// tabs/projectos.php - Sistema Completo de Gestão de Projetos
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

// Criar tabelas se não existirem
$pdo->exec("
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_name VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    owner_id INT,
    data_inicio DATE,
    data_fim DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// migração silenciosa para projectos existentes
foreach (['data_inicio DATE', 'data_fim DATE', "estado ENUM('aberto','fechado') NOT NULL DEFAULT 'aberto'"] as $col) {
    [$colName] = explode(' ', $col);
    if (!$pdo->query("SHOW COLUMNS FROM projects LIKE '$colName'")->fetch())
        $pdo->exec("ALTER TABLE projects ADD COLUMN $col");
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS project_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS project_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (project_id, user_id),
    INDEX idx_project (project_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS project_deliverables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    due_date DATE,
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Tabela para associar múltiplas tasks aos entregáveis
$pdo->exec("
CREATE TABLE IF NOT EXISTS deliverable_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deliverable_id INT NOT NULL,
    todo_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deliverable_id) REFERENCES project_deliverables(id) ON DELETE CASCADE,
    UNIQUE KEY unique_deliverable_task (deliverable_id, todo_id),
    INDEX idx_deliverable (deliverable_id),
    INDEX idx_todo (todo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS project_prototypes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    prototype_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_prototype (project_id, prototype_id),
    INDEX idx_project (project_id),
    INDEX idx_prototype (prototype_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Tabela para associar sprints aos entregáveis
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS deliverable_sprints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        deliverable_id INT NOT NULL,
        sprint_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (deliverable_id) REFERENCES project_deliverables(id) ON DELETE CASCADE,
        UNIQUE KEY unique_deliverable_sprint (deliverable_id, sprint_id),
        INDEX idx_deliverable (deliverable_id),
        INDEX idx_sprint (sprint_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Tabela já existe ou erro não crítico
}

// Tabela para ficheiros de projetos
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS project_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL,
        uploaded_by INT,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        INDEX idx_project (project_id),
        INDEX idx_uploaded_by (uploaded_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Tabela já existe
}

// Tabelas para notas de projetos
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        user_id INT NOT NULL,
        note_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        INDEX idx_project (project_id), INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_note_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES project_notes(id) ON DELETE CASCADE,
        INDEX idx_note (note_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) { /* tabelas já existem */ }

// PROCESSAR UPLOAD DE FICHEIROS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_project_file') {
    @ini_set('upload_max_filesize', '300M');
    @ini_set('post_max_size', '305M');
    @ini_set('max_execution_time', '600');
    @ini_set('memory_limit', '512M');

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $project_id = (int)$_POST['project_id'];
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $max_file_size = 300 * 1024 * 1024;
        $blocked_extensions = ['php', 'exe', 'sh', 'bat', 'phtml', 'php3', 'php4', 'php5', 'phps', 'pht', 'phar', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'msi'];
        
        $file_name = basename($_FILES['file']['name']);
        $file_size = $_FILES['file']['size'];
        $file_tmp = $_FILES['file']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if ($file_size > $max_file_size) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro muito grande. Máximo 300MB']);
            exit;
        }
        
        if (in_array($file_ext, $blocked_extensions)) {
            echo json_encode(['success' => false, 'error' => 'Tipo de ficheiro não permitido: .' . $file_ext]);
            exit;
        }
        
        if (!is_uploaded_file($file_tmp)) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro inválido']);
            exit;
        }
        
        $upload_dir = __DIR__ . '/../files/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $new_name = uniqid() . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $new_name;
        
        if (move_uploaded_file($file_tmp, $file_path)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO project_files (project_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $project_id,
                    $file_name,
                    'files/' . $new_name,
                    $file_size,
                    $_SESSION['user_id'] ?? null
                ]);
                
                echo json_encode([
                    'success' => true,
                    'file_id' => $pdo->lastInsertId(),
                    'file_name' => $file_name,
                    'file_path' => 'files/' . $new_name,
                    'file_size' => $file_size
                ]);
            } catch (PDOException $e) {
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                echo json_encode(['success' => false, 'error' => 'Erro ao guardar: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao mover ficheiro']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Nenhum ficheiro enviado']);
    }
    exit;
}

// PROCESSAR ELIMINAÇÃO DE FICHEIROS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project_file') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $file_id = (int)$_POST['file_id'];
    
    $stmt = $pdo->prepare('SELECT * FROM project_files WHERE id = ?');
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        if (file_exists(__DIR__ . '/../' . $file['file_path'])) {
            unlink(__DIR__ . '/../' . $file['file_path']);
        }
        
        $stmt = $pdo->prepare('DELETE FROM project_files WHERE id = ?');
        $stmt->execute([$file_id]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ficheiro não encontrado']);
    }
    exit;
}

// ===== AJAX HANDLERS: NOTAS DE PROJETO =====
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_project_notes') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $pid = (int)($_GET['project_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false]); exit; }
    $stmt = $pdo->prepare("SELECT pn.*, ut.username FROM project_notes pn LEFT JOIN user_tokens ut ON pn.user_id=ut.user_id WHERE pn.project_id=? ORDER BY pn.created_at DESC");
    $stmt->execute([$pid]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notes as &$n) {
        $si = $pdo->prepare("SELECT id, file_path, original_name FROM project_note_images WHERE note_id=?");
        $si->execute([$n['id']]);
        $n['images'] = $si->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['success'=>true,'notes'=>$notes,'current_user_id'=>$_SESSION['user_id']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_project_note') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $pid  = (int)($_POST['project_id'] ?? 0);
    $uid  = (int)$_SESSION['user_id'];
    $text = trim($_POST['note_text'] ?? '');
    if (!$pid) { echo json_encode(['success'=>false,'error'=>'Projeto inválido']); exit; }
    $pdo->prepare("INSERT INTO project_notes (project_id,user_id,note_text) VALUES (?,?,?)")->execute([$pid,$uid,$text]);
    $nid = (int)$pdo->lastInsertId();
    $imgDir = __DIR__.'/../files/project_notes/';
    if (!is_dir($imgDir)) mkdir($imgDir,0755,true);
    $allowedImg = ['jpg','jpeg','png','gif','webp'];
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $i=>$tmp) {
            if ($_FILES['images']['error'][$i]!==UPLOAD_ERR_OK) continue;
            $ext=strtolower(pathinfo($_FILES['images']['name'][$i],PATHINFO_EXTENSION));
            if (!in_array($ext,$allowedImg)) continue;
            if ($_FILES['images']['size'][$i]>10*1024*1024) continue;
            $fname="pjn_{$nid}_{$i}_".uniqid().".$ext";
            if (move_uploaded_file($tmp,$imgDir.$fname)) {
                $pdo->prepare("INSERT INTO project_note_images (note_id,file_path,original_name) VALUES (?,?,?)")->execute([$nid,'files/project_notes/'.$fname,$_FILES['images']['name'][$i]]);
            }
        }
    }
    echo json_encode(['success'=>true,'note_id'=>$nid]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_project_note') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $nid  = (int)($_POST['note_id'] ?? 0);
    $uid  = (int)$_SESSION['user_id'];
    $text = trim($_POST['note_text'] ?? '');
    $pdo->prepare("UPDATE project_notes SET note_text=?,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$text,$nid,$uid]);
    $imgDir = __DIR__.'/../files/project_notes/';
    if (!is_dir($imgDir)) mkdir($imgDir,0755,true);
    $allowedImg=['jpg','jpeg','png','gif','webp'];
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $i=>$tmp) {
            if ($_FILES['images']['error'][$i]!==UPLOAD_ERR_OK) continue;
            $ext=strtolower(pathinfo($_FILES['images']['name'][$i],PATHINFO_EXTENSION));
            if (!in_array($ext,$allowedImg)) continue;
            if ($_FILES['images']['size'][$i]>10*1024*1024) continue;
            $fname="pjn_{$nid}_e{$i}_".uniqid().".$ext";
            if (move_uploaded_file($tmp,$imgDir.$fname)) {
                $pdo->prepare("INSERT INTO project_note_images (note_id,file_path,original_name) VALUES (?,?,?)")->execute([$nid,'files/project_notes/'.$fname,$_FILES['images']['name'][$i]]);
            }
        }
    }
    echo json_encode(['success'=>true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project_note') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $nid = (int)($_POST['note_id'] ?? 0);
    $uid = (int)$_SESSION['user_id'];
    $row = $pdo->prepare("SELECT id FROM project_notes WHERE id=? AND user_id=?");
    $row->execute([$nid,$uid]);
    if (!$row->fetch()) { echo json_encode(['success'=>false,'error'=>'Não encontrado']); exit; }
    $imgs = $pdo->prepare("SELECT file_path FROM project_note_images WHERE note_id=?");
    $imgs->execute([$nid]);
    foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $full = __DIR__.'/../'.$fp;
        if (file_exists($full)) unlink($full);
    }
    $pdo->prepare("DELETE FROM project_notes WHERE id=? AND user_id=?")->execute([$nid,$uid]);
    echo json_encode(['success'=>true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project_note_image') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $iid = (int)($_POST['image_id'] ?? 0);
    $uid = (int)$_SESSION['user_id'];
    $r = $pdo->prepare("SELECT pni.file_path FROM project_note_images pni JOIN project_notes pn ON pni.note_id=pn.id WHERE pni.id=? AND pn.user_id=?");
    $r->execute([$iid,$uid]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success'=>false]); exit; }
    $full = __DIR__.'/../'.$row['file_path'];
    if (file_exists($full)) unlink($full);
    $pdo->prepare("DELETE FROM project_note_images WHERE id=?")->execute([$iid]);
    echo json_encode(['success'=>true]);
    exit;
}

// Processar ações
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_project':
                $stmt = $pdo->prepare("INSERT INTO projects (short_name, title, description, owner_id, data_inicio, data_fim, estado) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $_POST['owner_id'] ?: null,
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    'aberto',
                ]);
                $message = "Projeto criado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_project':
                $stmt = $pdo->prepare("UPDATE projects SET short_name=?, title=?, description=?, owner_id=?, data_inicio=?, data_fim=?, estado=? WHERE id=?");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $_POST['owner_id'] ?: null,
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    in_array($_POST['estado'] ?? '', ['aberto','fechado']) ? $_POST['estado'] : 'aberto',
                    $_POST['project_id']
                ]);
                $message = "Projeto atualizado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'delete_project':
                $stmt = $pdo->prepare("DELETE FROM projects WHERE id=?");
                $stmt->execute([$_POST['project_id']]);
                $message = "Projeto eliminado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'add_link':
                $stmt = $pdo->prepare("INSERT INTO project_links (project_id, title, url) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['project_id'], $_POST['link_title'], $_POST['link_url']]);
                $message = "Link adicionado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'delete_link':
                $stmt = $pdo->prepare("DELETE FROM project_links WHERE id=?");
                $stmt->execute([$_POST['link_id']]);
                $message = "Link removido!";
                $messageType = 'success';
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['project_id'], $_POST['user_id'], $_POST['role'] ?? 'member']);
                $message = "Membro adicionado ao projeto!";
                $messageType = 'success';
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM project_members WHERE id=?");
                $stmt->execute([$_POST['member_id']]);
                $message = "Membro removido do projeto!";
                $messageType = 'success';
                break;
                
            case 'add_deliverable':
                $stmt = $pdo->prepare("INSERT INTO project_deliverables (project_id, title, description, due_date, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['project_id'],
                    $_POST['deliverable_title'],
                    $_POST['deliverable_description'] ?? '',
                    $_POST['due_date'] ?: null,
                    $_POST['status'] ?? 'pending'
                ]);
                $message = "Entregável adicionado com sucesso!";
                $messageType = 'success';
                // Sincronizar com calendário
                entregaSyncCalendar($pdo, (int)$pdo->lastInsertId(), $_POST['deliverable_title'], $_POST['due_date'] ?: null, $_SESSION['username'] ?? 'sistema');
                break;

            case 'update_deliverable':
                $stmt = $pdo->prepare("UPDATE project_deliverables SET title=?, description=?, due_date=? WHERE id=?");
                $stmt->execute([
                    $_POST['deliverable_title'],
                    $_POST['deliverable_description'] ?? '',
                    $_POST['due_date'] ?: null,
                    $_POST['deliverable_id']
                ]);
                // Recalcular estado automaticamente
                updateDeliverableStatus($pdo, $_POST['deliverable_id']);
                $message = "Entregável atualizado!";
                $messageType = 'success';
                // Re-sincronizar calendário
                entregaSyncCalendar($pdo, (int)$_POST['deliverable_id'], $_POST['deliverable_title'], $_POST['due_date'] ?: null, $_SESSION['username'] ?? 'sistema');
                break;
                
            case 'add_task_to_deliverable':
                // Verificar se tabela todos existe
                $checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
                if ($checkTodos) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO deliverable_tasks (deliverable_id, todo_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['deliverable_id'], $_POST['todo_id']]);
                    // Recalcular estado do entregável
                    updateDeliverableStatus($pdo, $_POST['deliverable_id']);
                    $message = "Task associada ao entregável!";
                    $messageType = 'success';
                } else {
                    $message = "Tabela 'todos' não existe!";
                    $messageType = 'danger';
                }
                break;
                
            case 'remove_task_from_deliverable':
                $stmt = $pdo->prepare("DELETE FROM deliverable_tasks WHERE id=?");
                $stmt->execute([$_POST['task_link_id']]);
                // Recalcular estado do entregável
                updateDeliverableStatus($pdo, $_POST['deliverable_id']);
                $message = "Task desassociada!";
                $messageType = 'success';
                break;
                
            case 'create_new_task_for_deliverable':
                // Verificar se tabela todos existe
                $checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
                if ($checkTodos) {
                    // Obter o projeto_id do deliverable
                    $deliverableStmt = $pdo->prepare("SELECT project_id FROM project_deliverables WHERE id=?");
                    $deliverableStmt->execute([$_POST['deliverable_id']]);
                    $deliverable = $deliverableStmt->fetch(PDO::FETCH_ASSOC);
                    $projectId = $deliverable['project_id'];
                    
                    // Criar nova task na tabela todos com projeto_id preenchido
                    $stmt = $pdo->prepare("INSERT INTO todos (titulo, descritivo, data_limite, autor, projeto_id, estado) VALUES (?, ?, ?, ?, ?, 'aberta')");
                    $stmt->execute([
                        $_POST['task_title'],
                        $_POST['task_description'] ?? '',
                        $_POST['task_due_date'] ?: null,
                        $_SESSION['user_id'],
                        $projectId
                    ]);
                    $todoId = $pdo->lastInsertId();
                    
                    // Associar ao entregável
                    $stmt = $pdo->prepare("INSERT INTO deliverable_tasks (deliverable_id, todo_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['deliverable_id'], $todoId]);
                    
                    // Recalcular estado
                    updateDeliverableStatus($pdo, $_POST['deliverable_id']);
                    
                    $message = "Nova task criada e associada ao entregável! (projeto_id = $projectId)";
                    $messageType = 'success';
                } else {
                    $message = "Tabela 'todos' não existe!";
                    $messageType = 'danger';
                }
                break;
                
            case 'convert_to_todo':
                // Converter entregável em task da tabela todos
                $checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
                if ($checkTodos) {
                    $deliverable = $pdo->prepare("SELECT pd.*, pd.project_id FROM project_deliverables pd WHERE pd.id=?");
                    $deliverable->execute([$_POST['deliverable_id']]);
                    $deliv = $deliverable->fetch(PDO::FETCH_ASSOC);
                    
                    // Criar task na tabela todos com projeto_id preenchido
                    $stmt = $pdo->prepare("INSERT INTO todos (titulo, descritivo, data_limite, autor, projeto_id, estado) VALUES (?, ?, ?, ?, ?, 'aberta')");
                    $stmt->execute([
                        $deliv['title'],
                        $deliv['description'],
                        $deliv['due_date'],
                        $_SESSION['user_id'],
                        $deliv['project_id']
                    ]);
                    $todoId = $pdo->lastInsertId();
                    
                    // Associar à tabela deliverable_tasks
                    $stmt = $pdo->prepare("INSERT INTO deliverable_tasks (deliverable_id, todo_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['deliverable_id'], $todoId]);
                    
                    // Recalcular estado
                    updateDeliverableStatus($pdo, $_POST['deliverable_id']);
                    
                    $message = "Entregável convertido em Task (tabela todos) com sucesso! (projeto_id = {$deliv['project_id']})";
                    $messageType = 'success';
                } else {
                    $message = "Tabela 'todos' não existe! Instale o módulo de ToDos primeiro.";
                    $messageType = 'danger';
                }
                break;
                
            case 'delete_deliverable':
                $stmt = $pdo->prepare("DELETE FROM project_deliverables WHERE id=?");
                $stmt->execute([$_POST['deliverable_id']]);
                $message = "Entregável removido!";
                $messageType = 'success';
                break;

            case 'change_deliverable_status':
                $allowedStatuses = ['pending', 'in-progress', 'completed'];
                $newStatus = $_POST['new_status'] ?? '';
                if (in_array($newStatus, $allowedStatuses)) {
                    $stmt = $pdo->prepare("UPDATE project_deliverables SET status=? WHERE id=?");
                    $stmt->execute([$newStatus, $_POST['deliverable_id']]);
                    $message = "Estado do entregável atualizado!";
                    $messageType = 'success';
                } else {
                    $message = "Estado inválido!";
                    $messageType = 'danger';
                }
                break;
                
            case 'add_prototype':
                $stmt = $pdo->prepare("INSERT IGNORE INTO project_prototypes (project_id, prototype_id) VALUES (?, ?)");
                $stmt->execute([$_POST['project_id'], $_POST['prototype_id']]);
                $message = "Protótipo associado ao projeto!";
                $messageType = 'success';
                break;
                
            case 'remove_prototype':
                $stmt = $pdo->prepare("DELETE FROM project_prototypes WHERE id=?");
                $stmt->execute([$_POST['prototype_id']]);
                $message = "Protótipo desassociado!";
                $messageType = 'success';
                break;

            case 'add_milestone':
                $stmt = $pdo->prepare("INSERT INTO prototype_milestones (prototype_id, title, description, target_date, color) VALUES (?,?,?,?,?)");
                $stmt->execute([(int)$_POST['prototype_id'], $_POST['milestone_title'], $_POST['milestone_desc'] ?? '', $_POST['milestone_date'], $_POST['milestone_color'] ?? '#0d6efd']);
                $message = "Milestone criado!"; $messageType = 'success';
                break;

            case 'edit_milestone':
                $stmt = $pdo->prepare("UPDATE prototype_milestones SET title=?, description=?, target_date=?, color=? WHERE id=?");
                $stmt->execute([$_POST['milestone_title'], $_POST['milestone_desc'] ?? '', $_POST['milestone_date'], $_POST['milestone_color'] ?? '#0d6efd', (int)$_POST['milestone_id']]);
                $message = "Milestone atualizado!"; $messageType = 'success';
                break;

            case 'delete_milestone':
                $stmt = $pdo->prepare("DELETE FROM prototype_milestones WHERE id=?");
                $stmt->execute([(int)$_POST['milestone_id']]);
                $message = "Milestone eliminado!"; $messageType = 'success';
                break;

            case 'toggle_milestone_story':
                $mid = (int)$_POST['milestone_id']; $sid = (int)$_POST['story_id'];
                $chk = $pdo->prepare("SELECT id FROM milestone_stories WHERE milestone_id=? AND story_id=?");
                $chk->execute([$mid, $sid]);
                if ($chk->fetch()) { $pdo->prepare("DELETE FROM milestone_stories WHERE milestone_id=? AND story_id=?")->execute([$mid, $sid]); }
                else { $pdo->prepare("INSERT IGNORE INTO milestone_stories (milestone_id, story_id) VALUES (?,?)")->execute([$mid, $sid]); }
                $message = "Story atualizada!"; $messageType = 'success';
                break;

            case 'toggle_milestone_project':
                $mid = (int)$_POST['milestone_id']; $pid2 = (int)$_POST['project_id'];
                $chk = $pdo->prepare("SELECT id FROM milestone_projects WHERE milestone_id=? AND project_id=?");
                $chk->execute([$mid, $pid2]);
                if ($chk->fetch()) { $pdo->prepare("DELETE FROM milestone_projects WHERE milestone_id=? AND project_id=?")->execute([$mid, $pid2]); }
                else { $pdo->prepare("INSERT IGNORE INTO milestone_projects (milestone_id, project_id) VALUES (?,?)")->execute([$mid, $pid2]); }
                $message = "Projecto atualizado!"; $messageType = 'success';
                break;
                
            case 'create_sprint_for_deliverable':
                // Criar sprint e associar ao entregável
                $stmt = $pdo->prepare("INSERT INTO sprints (nome, descricao, data_inicio, data_fim, estado, responsavel_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['nome'],
                    $_POST['descricao'] ?? '',
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    $_POST['estado'] ?? 'aberta',
                    $_POST['responsavel_id'] ?: null
                ]);
                $sprint_id = $pdo->lastInsertId();
                
                // Associar sprint ao entregável
                $stmt = $pdo->prepare("INSERT IGNORE INTO deliverable_sprints (deliverable_id, sprint_id) VALUES (?, ?)");
                $stmt->execute([$_POST['deliverable_id'], $sprint_id]);
                
                $message = "Sprint criada e associada ao entregável com sucesso!";
                $messageType = 'success';
                break;
                
            case 'associate_sprint_to_deliverable':
                // Associar sprint existente ao entregável
                $stmt = $pdo->prepare("INSERT IGNORE INTO deliverable_sprints (deliverable_id, sprint_id) VALUES (?, ?)");
                $stmt->execute([$_POST['deliverable_id'], $_POST['sprint_id']]);
                $message = "Sprint associada ao entregável!";
                $messageType = 'success';
                break;
                
            case 'remove_sprint_from_deliverable':
                // Desassociar sprint do entregável
                $stmt = $pdo->prepare("DELETE FROM deliverable_sprints WHERE id=?");
                $stmt->execute([$_POST['link_id']]);
                $message = "Sprint desassociada do entregável!";
                $messageType = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Sincronização com calendário: apaga evento anterior deste entregável e insere novo
function entregaSyncCalendar(PDO $pdo, int $id, string $titulo, ?string $dueDate, string $criador): void {
    try {
        $pdo->prepare("DELETE FROM calendar_eventos WHERE tipo='entrega' AND descricao LIKE ?")->execute(['%[entrega:' . $id . ']%']);
        if ($dueDate) {
            $pdo->prepare("INSERT INTO calendar_eventos (data, tipo, descricao, hora, criador, cor) VALUES (?,?,?,NULL,?,?)")
                ->execute([$dueDate, 'entrega', 'Entregável: ' . $titulo . ' [entrega:' . $id . ']', $criador, 'indigo']);
        }
    } catch (PDOException $e) { /* calendário não crítico */ }
}

// Função para calcular e atualizar o estado do entregável baseado nas tasks
function updateDeliverableStatus($pdo, $deliverableId) {
    // Obter todas as tasks associadas ao entregável
    $stmt = $pdo->prepare("
        SELECT t.estado 
        FROM deliverable_tasks dt 
        JOIN todos t ON dt.todo_id = t.id 
        WHERE dt.deliverable_id = ?
    ");
    $stmt->execute([$deliverableId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tasks)) {
        // Sem tasks, manter como pending
        $status = 'pending';
    } else {
        // Contar estados
        $total = count($tasks);
        $fechadas = count(array_filter($tasks, fn($estado) => $estado === 'fechada'));
        $emProgresso = count(array_filter($tasks, fn($estado) => $estado !== 'fechada' && $estado !== 'aberta'));
        
        if ($fechadas === $total) {
            // Todas fechadas
            $status = 'completed';
        } elseif ($fechadas > 0 || $emProgresso > 0) {
            // Pelo menos uma em progresso ou fechada
            $status = 'in-progress';
        } else {
            // Todas abertas
            $status = 'pending';
        }
    }
    
    // Atualizar estado do entregável
    $stmt = $pdo->prepare("UPDATE project_deliverables SET status = ? WHERE id = ?");
    $stmt->execute([$status, $deliverableId]);
}

// Obter dados para exibição
$mostrarFechados = isset($_GET['fechados']);
$estadoFiltro = $mostrarFechados ? 'fechado' : 'aberto';
$stmtProj = $pdo->prepare("SELECT p.*, u.username as owner_name FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id WHERE p.estado = ? ORDER BY p.created_at DESC");
$stmtProj->execute([$estadoFiltro]);
$projects = $stmtProj->fetchAll(PDO::FETCH_ASSOC);
$totalFechados = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE estado='fechado'")->fetchColumn();
$totalAbertos  = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE estado='aberto'")->fetchColumn();

// Obter usuários disponíveis
$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Obter sprints disponíveis
$checkSprints = $pdo->query("SHOW TABLES LIKE 'sprints'")->fetch();
$allSprints = [];
if ($checkSprints) {
    $allSprints = $pdo->query("SELECT id, nome, descricao, estado, data_inicio, data_fim FROM sprints ORDER BY data_inicio DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// Obter protótipos disponíveis
$checkPrototypes = $pdo->query("SHOW TABLES LIKE 'prototypes'")->fetch();
$prototypes = [];
if ($checkPrototypes) {
    $prototypes = $pdo->query("SELECT id, short_name, title FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Obter projeto selecionado
$selectedProject = null;
if (isset($_GET['project_id'])) {
    $stmt = $pdo->prepare("SELECT p.*, u.username as owner_name FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id WHERE p.id=?");
    $stmt->execute([$_GET['project_id']]);
    $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedProject) {
        // MOVER VERIFICAÇÃO DA TABELA TODOS PARA AQUI (dentro do if selectedProject)
        // Verificar se a tabela todos existe
        $checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
        $todosExist = (bool)$checkTodos;
        
        // Obter todas as tasks disponíveis para associar (da tabela todos)
        $availableTodos = [];
        if ($todosExist) {
            // Buscar todas as tasks da tabela todos, ordenadas por data de criação
            $availableTodos = $pdo->query("
                SELECT t.id, t.titulo, t.estado, t.data_limite, t.projeto_id, u.username as autor_name, p.short_name as projeto_nome
                FROM todos t 
                LEFT JOIN user_tokens u ON t.autor = u.user_id 
                LEFT JOIN projects p ON t.projeto_id = p.id
                ORDER BY t.created_at DESC 
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo "<!-- DEBUG: todosExist = " . ($todosExist ? 'true' : 'false') . ", availableTodos count = " . count($availableTodos) . " -->";
        
        // Obter links
        $stmt = $pdo->prepare("SELECT * FROM project_links WHERE project_id=? ORDER BY title");
        $stmt->execute([$selectedProject['id']]);
        $selectedProject['links'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obter membros
        $stmt = $pdo->prepare("SELECT pm.*, u.username FROM project_members pm JOIN user_tokens u ON pm.user_id = u.user_id WHERE pm.project_id=? ORDER BY u.username");
        $stmt->execute([$selectedProject['id']]);
        $selectedProject['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obter entregáveis
        $stmt = $pdo->prepare("SELECT * FROM project_deliverables WHERE project_id=? ORDER BY due_date, created_at");
        $stmt->execute([$selectedProject['id']]);
        $selectedProject['deliverables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Para cada entregável, obter as tasks associadas
        if ($checkTodos) {
            foreach ($selectedProject['deliverables'] as &$deliv) {
                $stmt = $pdo->prepare("
                    SELECT dt.id as link_id, t.id, t.titulo, t.descritivo, t.estado, t.data_limite, u.username as autor_name
                    FROM deliverable_tasks dt
                    JOIN todos t ON dt.todo_id = t.id
                    LEFT JOIN user_tokens u ON t.autor = u.user_id
                    WHERE dt.deliverable_id = ?
                    ORDER BY t.created_at
                ");
                $stmt->execute([$deliv['id']]);
                $deliv['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Obter sprints associadas ao entregável
                try {
                    $stmt = $pdo->prepare("
                        SELECT ds.id as link_id, s.id, s.nome, s.descricao, s.estado, s.data_inicio, s.data_fim
                        FROM deliverable_sprints ds
                        JOIN sprints s ON ds.sprint_id = s.id
                        WHERE ds.deliverable_id = ?
                        ORDER BY s.data_inicio DESC, s.created_at DESC
                    ");
                    $stmt->execute([$deliv['id']]);
                    $deliv['sprints'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $deliv['sprints'] = [];
                }
            }
            // CRÍTICO: quebrar a referência ao último elemento para não corromper
            // os foreach seguintes que usam a mesma variável $deliv sem &
            unset($deliv);
        }
        
        // Obter protótipos associados
        if ($checkPrototypes) {
            $stmt = $pdo->prepare("
                SELECT pp.id as association_id, p.id, p.short_name, p.title 
                FROM project_prototypes pp 
                JOIN prototypes p ON pp.prototype_id = p.id 
                WHERE pp.project_id=?
                ORDER BY p.short_name
            ");
            $stmt->execute([$selectedProject['id']]);
            $selectedProject['prototypes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Para cada protótipo, obter suas stories
            foreach ($selectedProject['prototypes'] as &$proto) {
                $stmt = $pdo->prepare("SELECT id, story_text, moscow_priority FROM user_stories WHERE prototype_id=? ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won''t'), id");
                $stmt->execute([$proto['id']]);
                $proto['stories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($proto); // quebrar a referência do foreach para evitar corrupção do array

            // Carregar milestones para cada protótipo
            $checkMilestones = $pdo->query("SHOW TABLES LIKE 'prototype_milestones'")->fetch();
            foreach ($selectedProject['prototypes'] as &$proto) {
                $proto['milestones'] = [];
                if ($checkMilestones) {
                    try {
                        $mStmt = $pdo->prepare("SELECT * FROM prototype_milestones WHERE prototype_id=? ORDER BY target_date ASC");
                        $mStmt->execute([$proto['id']]);
                        $mRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($mRows as &$rm) {
                            $sStmt = $pdo->prepare("SELECT us.id, us.story_text, us.moscow_priority FROM milestone_stories ms JOIN user_stories us ON ms.story_id=us.id WHERE ms.milestone_id=?");
                            $sStmt->execute([$rm['id']]);
                            $rm['stories'] = $sStmt->fetchAll(PDO::FETCH_ASSOC);
                            $pStmt2 = $pdo->prepare("SELECT p2.id, p2.title, COALESCE(p2.short_name,'') as short_name FROM milestone_projects mp JOIN projects p2 ON mp.project_id=p2.id WHERE mp.milestone_id=?");
                            $pStmt2->execute([$rm['id']]);
                            $rm['projects'] = $pStmt2->fetchAll(PDO::FETCH_ASSOC);
                        }
                        unset($rm);
                        $proto['milestones'] = $mRows;
                    } catch (PDOException $e) {}
                }
            }
            unset($proto);
        } else {
            $selectedProject['prototypes'] = [];
        }
    }
}
?>

<style>
.projects-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 180px);
    overflow: hidden;
}

.projects-sidebar {
    width: 300px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow-y: auto;
    padding: 15px;
}

.project-list-item {
    padding: 12px;
    margin-bottom: 8px;
    background: #f8f9fa;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.project-list-item:hover {
    background: #e9ecef;
    border-left-color: #0d6efd;
}

.project-list-item.active {
    background: #e7f1ff;
    border-left-color: #0d6efd;
}

.project-short-name {
    font-weight: 600;
    color: #0d6efd;
    font-size: 14px;
}

.project-title {
    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
}

.project-detail {
    flex: 1;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow-y: auto;
    padding: 25px;
}

.detail-section {
    margin-bottom: 30px;
}

/* Notas de projeto */
.pjn-user-block { margin-bottom:14px; }
.pjn-user-header { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.pjn-avatar { width:30px; height:30px; border-radius:50%; background:#6c757d; color:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:600; flex-shrink:0; }
.pjn-avatar-me { background:#0d6efd; }
.pjn-note-item { background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:10px 12px; font-size:.9rem; }
.pjn-note-md-view { font-size:.9rem; line-height:1.6; }
.pjn-note-md-view img { max-width:100%; border-radius:4px; cursor:pointer; }
.pjn-note-md-view p:last-child { margin-bottom:0; }
.pjn-md-toolbar { display:flex; flex-wrap:wrap; gap:3px; margin-bottom:4px; }
.pjn-md-toolbar button { padding:2px 7px; font-size:.78rem; }
.pjn-editor-tabs { display:flex; gap:4px; margin-bottom:6px; }
.pjn-tab { padding:3px 12px; border-radius:4px; border:1px solid #dee2e6; background:#fff; font-size:.82rem; cursor:pointer; color:#495057; }
.pjn-tab.active { background:#0d6efd; color:#fff; border-color:#0d6efd; }
.pjn-md-live-preview { min-height:60px; border:1px solid #dee2e6; border-radius:6px; padding:8px 10px; background:#fff; font-size:.9rem; }
.pjn-edit-img-gallery { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.pjn-edit-img-ref { position:relative; cursor:pointer; }
.pjn-edit-img-ref img { width:60px; height:60px; object-fit:cover; border-radius:4px; border:2px solid #dee2e6; }
.pjn-edit-img-ref-overlay { position:absolute; inset:0; background:rgba(0,0,0,.35); border-radius:4px; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .15s; }
.pjn-edit-img-ref:hover .pjn-edit-img-ref-overlay { opacity:1; }
#pjn-lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:9999; align-items:center; justify-content:center; cursor:zoom-out; }
#pjn-lightbox img { max-width:92vw; max-height:92vh; border-radius:6px; }

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.info-item {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 6px;
    border-left: 3px solid #0d6efd;
}

.info-label {
    font-size: 11px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
}

.link-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 8px;
}

.file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 8px;
    border-left: 3px solid #17a2b8;
    transition: all 0.2s;
}

.file-item:hover {
    background: #e9ecef;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.file-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}

.file-info i {
    font-size: 20px;
    color: #17a2b8;
}

.file-info a {
    font-weight: 500;
    color: #212529;
    text-decoration: none;
}

.file-info a:hover {
    color: #0d6efd;
    text-decoration: underline;
}

.member-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 8px;
}

.member-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.member-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #0d6efd;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
}

.deliverable-item {
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 10px;
    border-left: 3px solid #dee2e6;
}

.deliverable-item.pending { border-left-color: #ffc107; }
.deliverable-item.in-progress { border-left-color: #0d6efd; }
.deliverable-item.completed { border-left-color: #198754; }

.deliverable-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 8px;
}

.deliverable-title {
    font-weight: 600;
    color: #212529;
    flex: 1;
}

.deliverable-actions {
    display: flex;
    gap: 5px;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-in-progress { background: #cfe2ff; color: #084298; }
.status-completed { background: #d1e7dd; color: #0f5132; }

.task-list {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dee2e6;
}

.task-item {
    background: white;
    padding: 8px 10px;
    border-radius: 4px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-left: 3px solid #dee2e6;
    font-size: 13px;
}

.task-item.aberta { border-left-color: #ffc107; }
.task-item.em_progresso { border-left-color: #0d6efd; }
.task-item.fechada { border-left-color: #198754; }

.task-info {
    flex: 1;
}

.task-title {
    font-weight: 500;
    color: #212529;
}

.task-meta {
    font-size: 11px;
    color: #6c757d;
    margin-top: 2px;
}

.task-badge {
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 600;
}

.task-badge.aberta { background: #fff3cd; color: #856404; }
.task-badge.em_progresso { background: #cfe2ff; color: #084298; }
.task-badge.fechada { background: #d1e7dd; color: #0f5132; }

.prototype-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border: 1px solid #dee2e6;
}

.prototype-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

.story-item {
    background: white;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 8px;
    border-left: 3px solid #0d6efd;
    font-size: 13px;
}

.story-priority {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    margin-right: 8px;
}

.priority-must { background: #f8d7da; color: #842029; }
.priority-should { background: #fff3cd; color: #856404; }
.priority-could { background: #d1e7dd; color: #0f5132; }
.priority-won't { background: #e2e3e5; color: #41464b; }

.empty-state {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}
</style>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="projects-container">
        <!-- Sidebar com lista de projetos -->
        <div class="projects-sidebar">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Projetos</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newProjectModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>

            <!-- toggle abertos / fechados -->
            <div class="d-flex gap-1 mb-3">
                <a href="?tab=projectos<?= isset($_GET['project_id']) ? '&project_id='.$_GET['project_id'] : '' ?>"
                   class="btn btn-xs <?= !$mostrarFechados ? 'btn-success' : 'btn-outline-secondary' ?>" style="font-size:11px;padding:2px 8px;">
                   ✅ Abertos <span class="badge bg-light text-dark ms-1"><?= $totalAbertos ?></span>
                </a>
                <a href="?tab=projectos&fechados=1<?= isset($_GET['project_id']) ? '&project_id='.$_GET['project_id'] : '' ?>"
                   class="btn btn-xs <?= $mostrarFechados ? 'btn-secondary' : 'btn-outline-secondary' ?>" style="font-size:11px;padding:2px 8px;">
                   🔒 Fechados <span class="badge bg-light text-dark ms-1"><?= $totalFechados ?></span>
                </a>
            </div>

            <?php if (empty($projects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-folder-x" style="font-size: 32px;"></i>
                    <p class="mt-2 mb-0"><?= $mostrarFechados ? 'Nenhum projeto fechado' : 'Nenhum projeto aberto' ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $proj): ?>
                    <a href="?tab=projectos&project_id=<?= $proj['id'] ?><?= $mostrarFechados ? '&fechados=1' : '' ?>" class="text-decoration-none">
                        <div class="project-list-item <?= isset($_GET['project_id']) && $_GET['project_id'] == $proj['id'] ? 'active' : '' ?>">
                            <div class="project-short-name"><?= htmlspecialchars($proj['short_name']) ?></div>
                            <div class="project-title"><?= htmlspecialchars($proj['title']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Detalhes do projeto -->
        <div class="project-detail">
            <?php if (!$selectedProject): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <h4>Selecione um projeto</h4>
                    <p>Escolha um projeto da lista ou crie um novo</p>
                </div>
            <?php else: ?>
                <!-- Informações Básicas -->
                <div class="detail-section">
                    <div class="section-title">
                        <span>
                            <i class="bi bi-info-circle"></i> Informações Básicas
                            <?php if (($selectedProject['estado'] ?? 'aberto') === 'fechado'): ?>
                                <span class="badge bg-secondary ms-2" style="font-size:11px;">🔒 Fechado</span>
                            <?php endif; ?>
                        </span>
                        <div class="d-flex gap-1">
                        <?php if (($selectedProject['estado'] ?? 'aberto') === 'aberto'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Fechar este projeto?');">
                            <input type="hidden" name="action" value="update_project">
                            <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                            <input type="hidden" name="short_name" value="<?= htmlspecialchars($selectedProject['short_name']) ?>">
                            <input type="hidden" name="title" value="<?= htmlspecialchars($selectedProject['title']) ?>">
                            <input type="hidden" name="description" value="<?= htmlspecialchars($selectedProject['description'] ?? '') ?>">
                            <input type="hidden" name="owner_id" value="<?= $selectedProject['owner_id'] ?? '' ?>">
                            <input type="hidden" name="data_inicio" value="<?= $selectedProject['data_inicio'] ?? '' ?>">
                            <input type="hidden" name="data_fim" value="<?= $selectedProject['data_fim'] ?? '' ?>">
                            <input type="hidden" name="estado" value="fechado">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">🔒 Fechar</button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="update_project">
                            <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                            <input type="hidden" name="short_name" value="<?= htmlspecialchars($selectedProject['short_name']) ?>">
                            <input type="hidden" name="title" value="<?= htmlspecialchars($selectedProject['title']) ?>">
                            <input type="hidden" name="description" value="<?= htmlspecialchars($selectedProject['description'] ?? '') ?>">
                            <input type="hidden" name="owner_id" value="<?= $selectedProject['owner_id'] ?? '' ?>">
                            <input type="hidden" name="data_inicio" value="<?= $selectedProject['data_inicio'] ?? '' ?>">
                            <input type="hidden" name="data_fim" value="<?= $selectedProject['data_fim'] ?? '' ?>">
                            <input type="hidden" name="estado" value="aberto">
                            <button type="submit" class="btn btn-sm btn-outline-success">✅ Reabrir</button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProjectModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        </div><!-- /d-flex gap-1 -->
                    </div><!-- /section-title -->
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Nome Curto</div>
                            <div class="info-value"><?= htmlspecialchars($selectedProject['short_name']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Título</div>
                            <div class="info-value"><?= htmlspecialchars($selectedProject['title']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Responsável</div>
                            <div class="info-value"><?= $selectedProject['owner_name'] ? htmlspecialchars($selectedProject['owner_name']) : '<em>Não definido</em>' ?></div>
                        </div>
                        <?php if (!empty($selectedProject['data_inicio']) || !empty($selectedProject['data_fim'])): ?>
                        <div class="info-item">
                            <div class="info-label">Início</div>
                            <div class="info-value"><?= $selectedProject['data_inicio'] ? date('d/m/Y', strtotime($selectedProject['data_inicio'])) : '<em>—</em>' ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Fim</div>
                            <div class="info-value"><?= $selectedProject['data_fim'] ? date('d/m/Y', strtotime($selectedProject['data_fim'])) : '<em>—</em>' ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($selectedProject['description']): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Descrição</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selectedProject['description'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notas do Projeto -->
                <div class="detail-section" id="pjn-section">
                    <div class="section-title">
                        <span><i class="bi bi-journal-text"></i> Notas</span>
                        <button class="btn btn-sm btn-outline-primary" onclick="pjnToggleAdd()">
                            <i class="bi bi-plus-lg"></i> Nova Nota
                        </button>
                    </div>

                    <!-- Formulário de adição -->
                    <div id="pjn-add-form" style="display:none;" class="mb-3">
                        <div class="pjn-editor-tabs">
                            <button class="pjn-tab active" onclick="pjnEditorTab(this,'write','add')">Escrever</button>
                            <button class="pjn-tab" onclick="pjnEditorTab(this,'preview','add')">Pré-visualizar</button>
                        </div>
                        <div class="pjn-md-toolbar" id="pjn-add-toolbar">
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdWrap(this,'*','*')" title="Itálico"><i>I</i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsert(this,'`')" title="Código"><i class="bi bi-code"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdBlock(this,'```\n','\n```')" title="Bloco de código"><i class="bi bi-code-square"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsertLine(this,'- ')" title="Lista"><i class="bi bi-list-ul"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsertLine(this,'> ')" title="Citação"><i class="bi bi-blockquote-left"></i></button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdTable(this)" title="Tabela"><i class="bi bi-table"></i></button>
                        </div>
                        <textarea id="pjn-add-text" class="form-control mb-2" rows="4" placeholder="Escrever nota em Markdown..."></textarea>
                        <div id="pjn-add-preview" class="pjn-md-live-preview mb-2" style="display:none;"></div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Imagens (opcional)</label>
                            <input type="file" id="pjn-add-images" multiple accept="image/*" class="form-control form-control-sm" onchange="pjnPreviewImages(this,'pjn-add-img-preview')">
                            <div id="pjn-add-img-preview" class="pjn-edit-img-gallery mt-1"></div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm" onclick="pjnAddNote()"><i class="bi bi-save"></i> Guardar</button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="pjnToggleAdd()">Cancelar</button>
                        </div>
                    </div>

                    <!-- Lista de notas -->
                    <div id="pjn-notes-list"></div>
                </div>

                <!-- Links/Recursos -->
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-link-45deg"></i> Links e Recursos</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                            <i class="bi bi-plus-lg"></i> Adicionar
                        </button>
                    </div>
                    
                    <?php if (empty($selectedProject['links'])): ?>
                        <p class="text-muted">Nenhum link adicionado</p>
                    <?php else: ?>
                        <?php foreach ($selectedProject['links'] as $link): ?>
                            <div class="link-item">
                                <div>
                                    <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" class="text-decoration-none">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        <?= htmlspecialchars($link['title']) ?>
                                    </a>
                                </div>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_link">
                                    <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover este link?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Ficheiros Anexados -->
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-paperclip"></i> Ficheiros Anexados</span>
                    </div>
                    
                    <!-- Upload de Ficheiro -->
                    <div class="mb-3">
                        <input type="file" id="project-file-upload" style="display:none" onchange="uploadProjectFile(<?= $selectedProject['id'] ?>)">
                        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('project-file-upload').click()">
                            <i class="bi bi-upload"></i> Adicionar Ficheiro
                        </button>
                        <span id="upload-status" class="ms-2 text-muted"></span>
                    </div>
                    
                    <!-- Lista de Ficheiros -->
                    <div id="project-files-list">
                        <?php
                        // Buscar ficheiros do projeto
                        try {
                            $stmt = $pdo->prepare('SELECT pf.*, ut.username FROM project_files pf LEFT JOIN user_tokens ut ON pf.uploaded_by = ut.user_id WHERE pf.project_id = ? ORDER BY pf.uploaded_at DESC');
                            $stmt->execute([$selectedProject['id']]);
                            $project_files = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($project_files) > 0):
                        ?>
                            <?php foreach ($project_files as $file): ?>
                                <div class="file-item" id="project-file-<?= $file['id'] ?>">
                                    <div class="file-info">
                                        <i class="bi bi-file-earmark"></i>
                                        <a href="<?= htmlspecialchars($file['file_path']) ?>" target="_blank">
                                            <?= htmlspecialchars($file['file_name']) ?>
                                        </a>
                                        <small class="text-muted ms-2">
                                            (<?= number_format($file['file_size'] / 1024, 0) ?> KB)
                                            <?php if ($file['username']): ?>
                                                - por <?= htmlspecialchars($file['username']) ?>
                                            <?php endif; ?>
                                            - <?= date('d/m/Y H:i', strtotime($file['uploaded_at'])) ?>
                                        </small>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProjectFile(<?= $file['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php 
                            else:
                        ?>
                            <p class="text-muted" id="no-files-message">
                                <i class="bi bi-info-circle"></i> Nenhum ficheiro adicionado ainda
                            </p>
                        <?php 
                            endif;
                        } catch (PDOException $e) {
                            echo '<p class="text-danger">Erro ao carregar ficheiros</p>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Equipa -->
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-people"></i> Equipa do Projeto</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                            <i class="bi bi-person-plus"></i> Adicionar
                        </button>
                    </div>
                    
                    <?php if (empty($selectedProject['members'])): ?>
                        <p class="text-muted">Nenhum membro associado</p>
                    <?php else: ?>
                        <?php foreach ($selectedProject['members'] as $member): ?>
                            <div class="member-item">
                                <div class="member-info">
                                    <div class="member-avatar"><?= strtoupper(substr($member['username'], 0, 1)) ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($member['username']) ?></strong>
                                        <small class="text-muted d-block"><?= htmlspecialchars($member['role']) ?></small>
                                    </div>
                                </div>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover este membro?')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Entregáveis -->
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-check2-square"></i> Entregáveis/PPS/KPIs</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addDeliverableModal">
                            <i class="bi bi-plus-lg"></i> Adicionar
                        </button>
                    </div>
                    
                    <?php if (empty($selectedProject['deliverables'])): ?>
                        <p class="text-muted">Nenhum entregável/PPS/KPIs definido</p>
                    <?php else: ?>
                        <?php foreach ($selectedProject['deliverables'] as $deliv): ?>
                            <div class="deliverable-item <?= $deliv['status'] ?>">
                                <!-- Linha sempre visível: título + data + status + expandir -->
                                <div class="deliverable-header" style="cursor:pointer;" onclick="toggleDeliverable(this)">
                                    <div class="deliverable-title"><?= htmlspecialchars($deliv['title']) ?></div>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($deliv['due_date']): ?>
                                            <small class="text-muted"><i class="bi bi-calendar-event"></i> <?= date('d/m/Y', strtotime($deliv['due_date'])) ?></small>
                                        <?php endif; ?>
                                        <span class="status-badge status-<?= $deliv['status'] ?>">
                                            <?= ucfirst(str_replace('-', ' ', $deliv['status'])) ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="event.stopPropagation();toggleDeliverable(this.closest('.deliverable-item').querySelector('.deliverable-header'))" title="Expandir">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Detalhes colapsados -->
                                <div class="deliverable-details" style="display:none;">
                                    <div class="deliverable-actions mt-2">
                                        <?php if ($deliv['status'] === 'pending'): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-warning dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Alterar estado">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><h6 class="dropdown-header">Alterar estado para:</h6></li>
                                                <li>
                                                    <form method="post" class="px-2">
                                                        <input type="hidden" name="action" value="change_deliverable_status">
                                                        <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                                        <input type="hidden" name="new_status" value="in-progress">
                                                        <button type="submit" class="dropdown-item text-primary">
                                                            <span class="status-badge status-in-progress">Em progresso</span>
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="post" class="px-2">
                                                        <input type="hidden" name="action" value="change_deliverable_status">
                                                        <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                                        <input type="hidden" name="new_status" value="completed">
                                                        <button type="submit" class="dropdown-item text-success">
                                                            <span class="status-badge status-completed">Concluído</span>
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editDeliverable(<?= htmlspecialchars(json_encode($deliv)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($checkSprints): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="createSprintForDeliverable(<?= $deliv['id'] ?>, '<?= htmlspecialchars($deliv['title']) ?>')">
                                            <i class="bi bi-plus-circle"></i> Nova Sprint
                                        </button>
                                        <button class="btn btn-sm btn-outline-info" onclick="associateSprintToDeliverable(<?= $deliv['id'] ?>, '<?= htmlspecialchars($deliv['title']) ?>')">
                                            <i class="bi bi-link-45deg"></i> Associar Sprint
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" onclick="listDeliverablelSprints(<?= $deliv['id'] ?>, '<?= htmlspecialchars($deliv['title']) ?>')">
                                            <i class="bi bi-list-ul"></i> Listar Sprints
                                        </button>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_deliverable">
                                            <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover este entregável?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <?php if ($deliv['description']): ?>
                                        <p class="mb-2 small mt-2"><?= nl2br(htmlspecialchars($deliv['description'])) ?></p>
                                    <?php endif; ?>

                                    <?php if (!empty($deliv['tasks'])): ?>
                                        <div class="task-list mt-2">
                                            <small class="text-muted fw-bold">Tasks Associadas (<?= count($deliv['tasks']) ?>):</small>
                                            <?php foreach ($deliv['tasks'] as $task): ?>
                                                <div class="task-item <?= $task['estado'] ?>">
                                                    <div class="task-info">
                                                        <div class="task-title"><?= htmlspecialchars($task['titulo']) ?></div>
                                                        <div class="task-meta">
                                                            <span class="task-badge <?= $task['estado'] ?>"><?= ucfirst(str_replace('_', ' ', $task['estado'])) ?></span>
                                                            <?php if ($task['autor_name']): ?>
                                                                | <?= htmlspecialchars($task['autor_name']) ?>
                                                            <?php endif; ?>
                                                            <?php if ($task['data_limite']): ?>
                                                                | <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="remove_task_from_deliverable">
                                                        <input type="hidden" name="task_link_id" value="<?= $task['link_id'] ?>">
                                                        <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Desassociar esta task?')" title="Desassociar">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Protótipos Associados -->
                <?php if ($checkPrototypes): ?>
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-box"></i> Protótipos e User Stories</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPrototypeModal">
                            <i class="bi bi-plus-lg"></i> Associar Protótipo
                        </button>
                    </div>
                    
                    <?php if (empty($selectedProject['prototypes'])): ?>
                        <p class="text-muted">Nenhum protótipo associado</p>
                    <?php else: ?>
                        <?php foreach ($selectedProject['prototypes'] as $proto): ?>
                            <div class="prototype-card">
                                <div class="prototype-header">
                                    <div>
                                        <strong><?= htmlspecialchars($proto['short_name']) ?></strong> -
                                        <?= htmlspecialchars($proto['title']) ?>
                                    </div>
                                    <div class="d-flex gap-1 align-items-center flex-wrap">
                                        <a href="index.php?tab=prototypes%2Fprototypesv2&prototype_id=<?= $proto['id'] ?>"
                                           class="btn btn-sm btn-outline-info" title="Ver protótipo">
                                            <i class="bi bi-box-arrow-up-right"></i> Ver
                                        </a>
                                        <?php if (!empty($proto['stories'])): ?>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                onclick="toggleProtoStories(this)" title="Ver User Stories">
                                            <i class="bi bi-list-ul"></i> User Stories (<?= count($proto['stories']) ?>)
                                        </button>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_prototype">
                                            <input type="hidden" name="prototype_id" value="<?= $proto['association_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Desassociar este protótipo?')">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php if (!empty($proto['stories'])): ?>
                                <div class="proto-stories mt-2" style="display:none;">
                                    <small class="text-muted fw-bold">User Stories:</small>
                                    <?php foreach ($proto['stories'] as $story): ?>
                                        <div class="story-item">
                                            <span class="story-priority priority-<?= strtolower($story['moscow_priority'] ?? 'should') ?>">
                                                <?= strtoupper($story['moscow_priority'] ?? 'SHOULD') ?>
                                            </span>
                                            <div class="small mt-1">
                                                <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Roadmap do protótipo -->
                                <?php $rmPid = (int)$proto['id']; $rmMs = $proto['milestones'] ?? []; ?>
                                <div class="proto-roadmap mt-3" id="proto-roadmap-<?= $rmPid ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="fw-bold text-secondary"><i class="bi bi-map"></i> Roadmap <span style="font-weight:400;">(24 meses)</span></small>
                                        <button class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:11px;"
                                                onclick="rmqOpenAdd(<?= $rmPid ?>)">
                                            <i class="bi bi-plus-lg"></i> Milestone
                                        </button>
                                    </div>
                                    <div class="rmq-wrap">
                                        <div class="rmq-timeline" data-proto="<?= $rmPid ?>"
                                             data-milestones="<?= htmlspecialchars(json_encode($rmMs), ENT_QUOTES) ?>">
                                            <div class="rmq-bar">
                                                <div class="rmq-today" title="Hoje"></div>
                                                <?php foreach ($rmMs as $rm): ?>
                                                <div class="rmq-dot"
                                                     data-id="<?= $rm['id'] ?>"
                                                     data-date="<?= htmlspecialchars($rm['target_date']) ?>"
                                                     data-title="<?= htmlspecialchars($rm['title']) ?>"
                                                     style="background:<?= htmlspecialchars($rm['color']) ?>;border-color:<?= htmlspecialchars($rm['color']) ?>;"
                                                     onclick="rmqSelect(this)"
                                                     title="<?= htmlspecialchars($rm['title']) ?> — <?= date('d/m/Y', strtotime($rm['target_date'])) ?>">
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="rmq-months"></div>
                                        </div>
                                    </div>
                                    <?php if (empty($rmMs)): ?>
                                    <p class="text-muted" style="font-size:11px;margin-top:4px;">Sem milestones. Clica em <strong>+ Milestone</strong> para começar.</p>
                                    <?php endif; ?>
                                    <!-- Detalhe inline do milestone -->
                                    <div class="rmq-detail" id="rmq-detail-<?= $rmPid ?>" style="display:none;">
                                        <div class="rmq-detail-header">
                                            <div>
                                                <span class="rmq-d-dot"></span>
                                                <strong class="rmq-d-title"></strong>
                                                <small class="text-muted ms-1 rmq-d-date"></small>
                                            </div>
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-xs btn-outline-primary" onclick="rmqOpenEdit(<?= $rmPid ?>)"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-xs btn-outline-danger" onclick="rmqDelete(<?= $rmPid ?>)"><i class="bi bi-trash"></i></button>
                                                <button class="btn btn-xs btn-outline-secondary" onclick="document.getElementById('rmq-detail-<?= $rmPid ?>').style.display='none'"><i class="bi bi-x"></i></button>
                                            </div>
                                        </div>
                                        <p class="rmq-d-desc small text-muted mb-2" style="white-space:pre-line;"></p>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <div class="rmq-links-block">
                                                    <div class="rmq-links-title"><i class="bi bi-book"></i> User Stories</div>
                                                    <div class="rmq-d-stories"></div>
                                                    <?php if (!empty($proto['stories'])): ?>
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer;font-size:11px;">+ Associar</summary>
                                                        <div class="rmq-picker">
                                                            <?php foreach ($proto['stories'] as $rs): ?>
                                                            <form method="POST" class="rmq-pick-form">
                                                                <input type="hidden" name="action" value="toggle_milestone_story">
                                                                <input type="hidden" name="prototype_id" value="<?= $rmPid ?>">
                                                                <input type="hidden" name="milestone_id" class="rmq-mid-f-<?= $rmPid ?>" value="">
                                                                <input type="hidden" name="story_id" value="<?= $rs['id'] ?>">
                                                                <button type="submit" class="btn rmq-pick-btn" data-sid="<?= $rs['id'] ?>">
                                                                    <span class="rmq-mos rmq-mos-<?= strtolower($rs['moscow_priority'] ?? 'should') ?>"><?= strtoupper($rs['moscow_priority'] ?? '?') ?></span>
                                                                    <?= htmlspecialchars(mb_strimwidth($rs['story_text'], 0, 55, '…')) ?>
                                                                </button>
                                                            </form>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </details>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="rmq-links-block">
                                                    <div class="rmq-links-title"><i class="bi bi-kanban"></i> Projectos</div>
                                                    <div class="rmq-d-projects"></div>
                                                    <?php if (!empty($projects)): ?>
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer;font-size:11px;">+ Associar</summary>
                                                        <div class="rmq-picker">
                                                            <?php foreach ($projects as $rp2): ?>
                                                            <form method="POST" class="rmq-pick-form">
                                                                <input type="hidden" name="action" value="toggle_milestone_project">
                                                                <input type="hidden" name="prototype_id" value="<?= $rmPid ?>">
                                                                <input type="hidden" name="milestone_id" class="rmq-mid-f-<?= $rmPid ?>" value="">
                                                                <input type="hidden" name="project_id" value="<?= $rp2['id'] ?>">
                                                                <button type="submit" class="btn rmq-pick-btn" data-pid="<?= $rp2['id'] ?>">
                                                                    <?php if (!empty($rp2['short_name'])): ?><strong><?= htmlspecialchars($rp2['short_name']) ?></strong> — <?php endif; ?>
                                                                    <?= htmlspecialchars(mb_strimwidth($rp2['title'], 0, 50, '…')) ?>
                                                                </button>
                                                            </form>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </details>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- form oculto delete -->
                                    <form method="POST" id="rmq-del-<?= $rmPid ?>" style="display:none;">
                                        <input type="hidden" name="action" value="delete_milestone">
                                        <input type="hidden" name="prototype_id" value="<?= $rmPid ?>">
                                        <input type="hidden" name="milestone_id" class="rmq-del-mid-<?= $rmPid ?>" value="">
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Ações do Projeto -->
                <div class="detail-section">
                    <div class="section-title">
                        <span><i class="bi bi-gear"></i> Ações</span>
                    </div>
                    <form method="post" onsubmit="return confirm('Tem certeza que deseja eliminar este projeto? Esta ação não pode ser desfeita!')">
                        <input type="hidden" name="action" value="delete_project">
                        <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Eliminar Projeto
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Novo Projeto -->
<div class="modal fade" id="newProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Projeto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_project">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome Curto *</label>
                        <input type="text" name="short_name" class="form-control" required maxlength="50" 
                               placeholder="Ex: PROJ-001">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" required maxlength="255"
                               placeholder="Nome completo do projeto">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="4" 
                                  placeholder="Descrição detalhada do projeto"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="owner_id" class="form-select">
                            <option value="">Selecione...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Data de Início</label>
                            <input type="date" name="data_inicio" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Data de Fim</label>
                            <input type="date" name="data_fim" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Projeto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Projeto -->
<?php if ($selectedProject): ?>
<div class="modal fade" id="editProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Projeto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_project">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome Curto *</label>
                        <input type="text" name="short_name" class="form-control" required 
                               value="<?= htmlspecialchars($selectedProject['short_name']) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" required 
                               value="<?= htmlspecialchars($selectedProject['title']) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($selectedProject['description']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="owner_id" class="form-select">
                            <option value="">Selecione...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>" <?= $selectedProject['owner_id'] == $user['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Data de Início</label>
                            <input type="date" name="data_inicio" class="form-control"
                                   value="<?= htmlspecialchars($selectedProject['data_inicio'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Data de Fim</label>
                            <input type="date" name="data_fim" class="form-control"
                                   value="<?= htmlspecialchars($selectedProject['data_fim'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="aberto"  <?= ($selectedProject['estado'] ?? 'aberto') === 'aberto'  ? 'selected' : '' ?>>✅ Aberto</option>
                            <option value="fechado" <?= ($selectedProject['estado'] ?? 'aberto') === 'fechado' ? 'selected' : '' ?>>🔒 Fechado</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Adicionar Link -->
<div class="modal fade" id="addLinkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_link">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="link_title" class="form-control" required
                               placeholder="Ex: Documentação, Repositório, etc.">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">URL *</label>
                        <input type="url" name="link_url" class="form-control" required
                               placeholder="https://...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Adicionar Membro -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Membro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Utilizador *</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Papel</label>
                        <select name="role" class="form-select">
                            <option value="member">Membro</option>
                            <option value="developer">Desenvolvedor</option>
                            <option value="designer">Designer</option>
                            <option value="manager">Gestor</option>
                            <option value="consultant">Consultor</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Adicionar Entregável -->
<div class="modal fade" id="addDeliverableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Entregável</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_deliverable">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="deliverable_title" class="form-control" required
                               placeholder="Nome do entregável">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="deliverable_description" class="form-control" rows="3"
                                  placeholder="Detalhes sobre o entregável"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data Limite</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Estado</label>
                        <select name="status" class="form-select">
                            <option value="pending">Pendente</option>
                            <option value="in-progress">Em Progresso</option>
                            <option value="completed">Concluído</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Entregável -->
<div class="modal fade" id="editDeliverableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Entregável</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_deliverable">
                    <input type="hidden" name="deliverable_id" id="edit_deliverable_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="deliverable_title" id="edit_deliverable_title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="deliverable_description" id="edit_deliverable_description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data Limite</label>
                        <input type="date" name="due_date" id="edit_due_date" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Estado</label>
                        <select name="status" id="edit_status" class="form-select" disabled>
                            <option value="pending">Pendente</option>
                            <option value="in-progress">Em Progresso</option>
                            <option value="completed">Concluído</option>
                        </select>
                        <small class="text-muted">O estado é calculado automaticamente baseado nas tasks associadas</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Criar Nova Sprint para Entregável -->
<?php if ($checkSprints): ?>
<div class="modal fade" id="createSprintModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i> Criar Nova Sprint para: 
                    <span id="create_sprint_modal_title"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_sprint_for_deliverable">
                    <input type="hidden" name="deliverable_id" id="create_sprint_deliverable_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome da Sprint *</label>
                        <input type="text" name="nome" class="form-control" required placeholder="Ex: Sprint 1 - MVP">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3" placeholder="Objetivo e escopo da sprint..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data Início</label>
                                <input type="date" name="data_inicio" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data Fim</label>
                                <input type="date" name="data_fim" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Estado</label>
                                <select name="estado" class="form-select">
                                    <option value="aberta">Aberta</option>
                                    <option value="pausa">Em Pausa</option>
                                    <option value="fechada">Fechada</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Responsável</label>
                                <select name="responsavel_id" class="form-select">
                                    <option value="">Nenhum</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Criar e Associar Sprint
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Associar Sprint Existente -->
<div class="modal fade" id="associateSprintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-link-45deg"></i> Associar Sprint a: 
                    <span id="associate_sprint_modal_title"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="associate_sprint_to_deliverable">
                    <input type="hidden" name="deliverable_id" id="associate_sprint_deliverable_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Sprint *</label>
                        <select name="sprint_id" class="form-select" required size="8">
                            <option value="">Selecione uma sprint...</option>
                            <?php foreach ($allSprints as $sprint): ?>
                                <option value="<?= $sprint['id'] ?>">
                                    <?= htmlspecialchars($sprint['nome']) ?> 
                                    [<?= ucfirst($sprint['estado']) ?>]
                                    <?php if ($sprint['data_inicio']): ?>
                                        - <?= date('d/m/Y', strtotime($sprint['data_inicio'])) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            <?= count($allSprints) ?> sprints disponíveis
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-link-45deg"></i> Associar Sprint
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Listar Sprints do Entregável -->
<div class="modal fade" id="listSprintsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-list-ul"></i> Sprints de: 
                    <span id="list_sprint_modal_title"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($selectedProject): ?>
                    <?php foreach ($selectedProject['deliverables'] as $deliv): ?>
                        <?php if (!empty($deliv['sprints'])): ?>
                            <div class="deliverable-sprints-section" data-deliverable-id="<?= $deliv['id'] ?>">
                                <h6 class="text-muted mb-3"><?= htmlspecialchars($deliv['title']) ?></h6>
                                <div class="list-group mb-3">
                                    <?php foreach ($deliv['sprints'] as $sprint): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">
                                                        <a href="?tab=sprints&sprint_id=<?= $sprint['id'] ?>" class="text-decoration-none" target="_blank">
                                                            <?= htmlspecialchars($sprint['nome']) ?>
                                                            <i class="bi bi-box-arrow-up-right small"></i>
                                                        </a>
                                                    </h6>
                                                    <?php if ($sprint['descricao']): ?>
                                                        <p class="mb-1 small text-muted"><?= htmlspecialchars($sprint['descricao']) ?></p>
                                                    <?php endif; ?>
                                                    <div class="small text-muted">
                                                        <span class="badge bg-<?= $sprint['estado'] == 'aberta' ? 'success' : ($sprint['estado'] == 'pausa' ? 'warning' : 'secondary') ?>">
                                                            <?= ucfirst($sprint['estado']) ?>
                                                        </span>
                                                        <?php if ($sprint['data_inicio'] && $sprint['data_fim']): ?>
                                                            | <i class="bi bi-calendar-range"></i> 
                                                            <?= date('d/m/Y', strtotime($sprint['data_inicio'])) ?> - 
                                                            <?= date('d/m/Y', strtotime($sprint['data_fim'])) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <a href="?tab=sprints&sprint_id=<?= $sprint['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="bi bi-arrow-right-circle"></i> Abrir
                                                    </a>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="remove_sprint_from_deliverable">
                                                        <input type="hidden" name="link_id" value="<?= $sprint['link_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Desassociar esta sprint?')">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php
                    $hasSprints = false;
                    foreach ($selectedProject['deliverables'] as $deliv) {
                        if (!empty($deliv['sprints'])) {
                            $hasSprints = true;
                            break;
                        }
                    }
                    if (!$hasSprints):
                    ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            Nenhuma sprint associada a este entregável ainda.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal partilhado: Add/Edit Milestone (roadmap inline) -->
<div class="modal fade" id="rmqMilestoneModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="rmq-form-action" value="add_milestone">
                <input type="hidden" name="prototype_id" id="rmq-form-proto" value="">
                <input type="hidden" name="milestone_id" id="rmq-form-mid" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="rmq-modal-title">Novo Milestone</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Título *</label>
                        <input type="text" name="milestone_title" id="rmq-f-title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Data alvo *</label>
                        <input type="date" name="milestone_date" id="rmq-f-date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Descrição</label>
                        <textarea name="milestone_desc" id="rmq-f-desc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cor</label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="color" name="milestone_color" id="rmq-f-color" value="#0d6efd" class="form-control form-control-color" style="width:46px;">
                            <div class="d-flex gap-1 flex-wrap">
                                <?php foreach (['#0d6efd','#198754','#dc3545','#fd7e14','#6f42c1','#0dcaf0','#ffc107','#6c757d'] as $c): ?>
                                <div onclick="document.getElementById('rmq-f-color').value='<?= $c ?>'"
                                     style="width:20px;height:20px;border-radius:50%;background:<?= $c ?>;cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1px #ccc;"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.proto-roadmap { border-top:1px solid #e9ecef; padding-top:10px; }
.rmq-wrap { overflow-x:auto; padding-bottom:4px; }
.rmq-timeline { min-width:500px; padding:18px 8px 0; position:relative; }
.rmq-bar { position:relative; height:5px; background:#e9ecef; border-radius:3px; margin:14px 0 0; }
.rmq-today { position:absolute; top:-8px; width:2px; background:#dc3545; height:22px; z-index:3; }
.rmq-today::before { content:'Hoje'; position:absolute; top:-14px; left:50%; transform:translateX(-50%); font-size:9px; color:#dc3545; white-space:nowrap; font-weight:600; }
.rmq-dot { position:absolute; top:50%; transform:translate(-50%,-50%); width:14px; height:14px; border-radius:50%; border:2px solid; cursor:pointer; z-index:4; transition:transform .15s,box-shadow .15s; }
.rmq-dot:hover,.rmq-dot.active { transform:translate(-50%,-50%) scale(1.45); box-shadow:0 0 0 3px rgba(0,0,0,.12); }
.rmq-months { display:flex; margin-top:8px; font-size:10px; color:#6c757d; }
.rmq-month-cell { flex:1; text-align:center; padding-top:4px; border-left:1px solid #dee2e6; cursor:pointer; user-select:none; }
.rmq-month-cell:hover { color:#0d6efd; }
.rmq-cur-month { color:#dc3545; font-weight:600; }
.rmq-detail { border:1px solid #dee2e6; border-radius:6px; padding:10px 12px; margin-top:10px; background:#fafafa; font-size:13px; }
.rmq-detail-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }
.rmq-d-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:5px; }
.rmq-links-block { border:1px solid #e9ecef; border-radius:5px; padding:8px; background:#fff; height:100%; }
.rmq-links-title { font-size:11px; font-weight:600; color:#6c757d; margin-bottom:4px; }
.rmq-linked-item { display:flex; align-items:center; gap:5px; font-size:11px; background:#f0f4ff; border-radius:3px; padding:2px 6px; margin-bottom:3px; }
.rmq-linked-item form { margin:0; }
.rmq-remove { border:none; background:none; color:#aaa; cursor:pointer; font-size:13px; line-height:1; padding:0 2px; }
.rmq-remove:hover { color:#dc3545; }
.rmq-picker { max-height:140px; overflow-y:auto; display:flex; flex-direction:column; gap:2px; margin-top:4px; }
.rmq-pick-btn { width:100%; text-align:left; font-size:11px; padding:3px 7px; border:1px solid #dee2e6; border-radius:3px; background:#fff; color:#333; white-space:normal; line-height:1.3; }
.rmq-pick-btn:hover { background:#e8f0ff; border-color:#0d6efd; }
.rmq-mos { display:inline-block; font-size:9px; font-weight:700; padding:1px 3px; border-radius:2px; margin-right:3px; }
.rmq-mos-must   { background:#dc3545; color:#fff; }
.rmq-mos-should { background:#0d6efd; color:#fff; }
.rmq-mos-could  { background:#198754; color:#fff; }
</style>

<?php if ($checkPrototypes): ?>
<!-- Modal: Associar Protótipo -->
<div class="modal fade" id="addPrototypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Associar Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_prototype">
                    <input type="hidden" name="project_id" value="<?= $selectedProject['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Protótipo *</label>
                        <select name="prototype_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($prototypes as $proto): ?>
                                <option value="<?= $proto['id'] ?>">
                                    <?= htmlspecialchars($proto['short_name']) ?> - <?= htmlspecialchars($proto['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        As user stories do protótipo aparecerão automaticamente após associar.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function toggleProtoStories(btn) {
    var stories = btn.closest('.prototype-card').querySelector('.proto-stories');
    var open = stories.style.display !== 'none';
    stories.style.display = open ? 'none' : 'block';
    btn.classList.toggle('active', !open);
}

/* ── Roadmap inline nos prototype-cards ──────────────────────────── */
(function(){
const RMQ_MONTHS = 24;
// keyed by protoId
const _rmqData = {};
const _rmqSel  = {};

function _now()  { return new Date(); }
function _start(){ var d = _now(); return new Date(d.getFullYear(), d.getMonth(), 1); }
function _end()  { var s = _start(), e = new Date(s); e.setMonth(e.getMonth() + RMQ_MONTHS); return e; }

function _pct(dateStr) {
    var s = _start(), e = _end(), d = new Date(dateStr + 'T12:00:00');
    return Math.min(1, Math.max(0, (d - s) / (e - s)));
}

function _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function rmqLayout(protoId) {
    var tl = document.querySelector('.rmq-timeline[data-proto="' + protoId + '"]');
    if (!tl) return;

    // Parse data once
    if (!_rmqData[protoId]) {
        try { _rmqData[protoId] = JSON.parse(tl.dataset.milestones || '[]'); } catch(e) { _rmqData[protoId] = []; }
    }

    // Today marker
    var todayEl = tl.querySelector('.rmq-today');
    if (todayEl) todayEl.style.left = (_pct(_now().toISOString().slice(0,10)) * 100) + '%';

    // Milestone dots
    tl.querySelectorAll('.rmq-dot').forEach(function(el) {
        el.style.left = (_pct(el.dataset.date) * 100) + '%';
    });

    // Month grid
    var grid = tl.querySelector('.rmq-months');
    if (!grid || grid.children.length) return;
    var s = _start();
    for (var i = 0; i < RMQ_MONTHS; i++) {
        var m = new Date(s); m.setMonth(m.getMonth() + i);
        var cell = document.createElement('div');
        cell.className = 'rmq-month-cell' + (i===0 ? ' rmq-cur-month' : '');
        cell.innerHTML = '<div>' + m.toLocaleString('pt-PT',{month:'short'}) + '</div><div style="font-size:9px;opacity:.7;">' + m.getFullYear() + '</div>';
        (function(mi, yr){ cell.addEventListener('click', function() { rmqOpenAdd(protoId, yr+'-'+String(mi+1).padStart(2,'0')+'-01'); }); })(m.getMonth(), m.getFullYear());
        grid.appendChild(cell);
    }
}

window.rmqOpenAdd = function(protoId, preDate) {
    document.getElementById('rmq-modal-title').textContent = 'Novo Milestone';
    document.getElementById('rmq-form-action').value = 'add_milestone';
    document.getElementById('rmq-form-proto').value = protoId;
    document.getElementById('rmq-form-mid').value = '';
    document.getElementById('rmq-f-title').value = '';
    document.getElementById('rmq-f-date').value = preDate || '';
    document.getElementById('rmq-f-desc').value = '';
    document.getElementById('rmq-f-color').value = '#0d6efd';
    window._rmqCurrentProto = protoId;
    new bootstrap.Modal(document.getElementById('rmqMilestoneModal')).show();
};

window.rmqOpenEdit = function(protoId) {
    var mid = _rmqSel[protoId];
    if (!mid) return;
    var ms = (_rmqData[protoId] || []).find(function(x){ return x.id == mid; });
    if (!ms) return;
    document.getElementById('rmq-modal-title').textContent = 'Editar Milestone';
    document.getElementById('rmq-form-action').value = 'edit_milestone';
    document.getElementById('rmq-form-proto').value = protoId;
    document.getElementById('rmq-form-mid').value = mid;
    document.getElementById('rmq-f-title').value = ms.title;
    document.getElementById('rmq-f-date').value = ms.target_date;
    document.getElementById('rmq-f-desc').value = ms.description || '';
    document.getElementById('rmq-f-color').value = ms.color || '#0d6efd';
    window._rmqCurrentProto = protoId;
    new bootstrap.Modal(document.getElementById('rmqMilestoneModal')).show();
};

window.rmqDelete = function(protoId) {
    var mid = _rmqSel[protoId];
    if (!mid || !confirm('Eliminar este milestone?')) return;
    var form = document.getElementById('rmq-del-' + protoId);
    form.querySelector('.rmq-del-mid-' + protoId).value = mid;
    form.submit();
};

window.rmqSelect = function(el) {
    var tl = el.closest('.rmq-timeline');
    var protoId = tl.dataset.proto;
    tl.querySelectorAll('.rmq-dot').forEach(function(d){ d.classList.remove('active'); });
    el.classList.add('active');
    var mid = el.dataset.id;
    _rmqSel[protoId] = mid;

    var ms = (_rmqData[protoId] || []).find(function(x){ return x.id == mid; });
    if (!ms) return;

    var panel = document.getElementById('rmq-detail-' + protoId);
    panel.querySelector('.rmq-d-dot').style.background = ms.color;
    panel.querySelector('.rmq-d-title').textContent = ms.title;
    var d = new Date(ms.target_date + 'T12:00:00');
    panel.querySelector('.rmq-d-date').textContent = d.toLocaleDateString('pt-PT',{day:'2-digit',month:'long',year:'numeric'});
    panel.querySelector('.rmq-d-desc').textContent = ms.description || '';

    // Stories
    var sl = panel.querySelector('.rmq-d-stories'); sl.innerHTML = '';
    (ms.stories || []).forEach(function(s) {
        var div = document.createElement('div'); div.className = 'rmq-linked-item';
        div.innerHTML = '<span class="rmq-mos rmq-mos-' + (s.moscow_priority||'should').toLowerCase() + '">' + (s.moscow_priority||'?').toUpperCase() + '</span>' +
            '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;">' + _esc(s.story_text.substring(0,55)) + '</span>' +
            '<form method="POST" style="margin:0"><input type="hidden" name="action" value="toggle_milestone_story"><input type="hidden" name="prototype_id" value="' + protoId + '"><input type="hidden" name="milestone_id" value="' + mid + '"><input type="hidden" name="story_id" value="' + s.id + '"><button type="submit" class="rmq-remove" title="Remover">×</button></form>';
        sl.appendChild(div);
    });
    if (!ms.stories || !ms.stories.length) sl.innerHTML = '<span class="text-muted" style="font-size:11px;">Sem stories associadas</span>';

    // Projects
    var pl = panel.querySelector('.rmq-d-projects'); pl.innerHTML = '';
    (ms.projects || []).forEach(function(p) {
        var div = document.createElement('div'); div.className = 'rmq-linked-item';
        div.innerHTML = (p.short_name ? '<strong>' + _esc(p.short_name) + '</strong> — ' : '') + _esc(p.title.substring(0,45)) +
            '<form method="POST" style="margin:0"><input type="hidden" name="action" value="toggle_milestone_project"><input type="hidden" name="prototype_id" value="' + protoId + '"><input type="hidden" name="milestone_id" value="' + mid + '"><input type="hidden" name="project_id" value="' + p.id + '"><button type="submit" class="rmq-remove" title="Remover">×</button></form>';
        pl.appendChild(div);
    });
    if (!ms.projects || !ms.projects.length) pl.innerHTML = '<span class="text-muted" style="font-size:11px;">Sem projectos associados</span>';

    // Fill hidden milestone_id fields in pick forms
    document.querySelectorAll('.rmq-mid-f-' + protoId).forEach(function(f){ f.value = mid; });

    panel.style.display = 'block';
};

// Init all timelines on load
function rmqInitAll() {
    document.querySelectorAll('.rmq-timeline[data-proto]').forEach(function(tl) {
        rmqLayout(tl.dataset.proto);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rmqInitAll);
} else {
    rmqInitAll();
}
window.addEventListener('resize', rmqInitAll);
})();

function toggleDeliverable(header) {
    var item = header.closest('.deliverable-item');
    var details = item.querySelector('.deliverable-details');
    var icon = item.querySelector('.deliverable-header .bi-chevron-down, .deliverable-header .bi-chevron-up');
    var open = details.style.display !== 'none';
    details.style.display = open ? 'none' : 'block';
    if (icon) icon.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
}

function editDeliverable(deliverable) {
    document.getElementById('edit_deliverable_id').value = deliverable.id;
    document.getElementById('edit_deliverable_title').value = deliverable.title;
    document.getElementById('edit_deliverable_description').value = deliverable.description || '';
    document.getElementById('edit_due_date').value = deliverable.due_date || '';
    document.getElementById('edit_status').value = deliverable.status;
    
    var modal = new bootstrap.Modal(document.getElementById('editDeliverableModal'));
    modal.show();
}

function createSprintForDeliverable(deliverableId, deliverableTitle) {
    document.getElementById('create_sprint_deliverable_id').value = deliverableId;
    document.getElementById('create_sprint_modal_title').textContent = deliverableTitle;
    var modal = new bootstrap.Modal(document.getElementById('createSprintModal'));
    modal.show();
}

function associateSprintToDeliverable(deliverableId, deliverableTitle) {
    document.getElementById('associate_sprint_deliverable_id').value = deliverableId;
    document.getElementById('associate_sprint_modal_title').textContent = deliverableTitle;
    var modal = new bootstrap.Modal(document.getElementById('associateSprintModal'));
    modal.show();
}

function listDeliverablelSprints(deliverableId, deliverableTitle) {
    document.getElementById('list_sprint_modal_title').textContent = deliverableTitle;
    // Carregar sprints via AJAX ou usar dados PHP
    var modal = new bootstrap.Modal(document.getElementById('listSprintsModal'));
    modal.show();
    
    // Atualizar conteúdo das sprints
    loadSprintsForDeliverable(deliverableId);
}

function loadSprintsForDeliverable(deliverableId) {
    // Aqui carregaríamos via AJAX, mas por agora vamos usar reload
    // A lista já está carregada no PHP
}

function manageDeliverableTasks(deliverableId, deliverableTitle) {
    console.log('Opening tasks modal for deliverable:', deliverableId, deliverableTitle);
    
    // Verificar se todos os elementos existem
    const elements = {
        modal: document.getElementById('manageTasksModal'),
        taskModalDeliverableId: document.getElementById('taskModalDeliverableId'),
        taskModalDeliverableTitle: document.getElementById('taskModalDeliverableTitle'),
        create_task_deliverable_id: document.getElementById('create_task_deliverable_id'),
        link_task_deliverable_id: document.getElementById('link_task_deliverable_id')
    };
    
    // Debug: mostrar quais elementos não foram encontrados
    Object.keys(elements).forEach(key => {
        if (!elements[key]) {
            console.error(`Elemento não encontrado: ${key}`);
        }
    });
    
    if (!elements.modal) {
        alert('Erro: Modal não encontrado! Certifique-se que está num projeto selecionado.');
        return;
    }
    
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap not loaded!');
        alert('Erro: Bootstrap não está carregado.');
        return;
    }
    
    // Definir valores apenas se os elementos existirem
    if (elements.taskModalDeliverableId) {
        elements.taskModalDeliverableId.value = deliverableId;
    }
    if (elements.taskModalDeliverableTitle) {
        elements.taskModalDeliverableTitle.textContent = deliverableTitle;
    }
    if (elements.create_task_deliverable_id) {
        elements.create_task_deliverable_id.value = deliverableId;
    }
    if (elements.link_task_deliverable_id) {
        elements.link_task_deliverable_id.value = deliverableId;
    }
    
    // Abrir modal
    try {
        var modal = new bootstrap.Modal(elements.modal);
        modal.show();
        console.log('Modal aberto com sucesso!');
    } catch (error) {
        console.error('Erro ao abrir modal:', error);
        alert('Erro ao abrir modal: ' + error.message);
    }
}

// Debug: verificar se Bootstrap está carregado ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== DEBUG INFO ===');
    console.log('Bootstrap disponível:', typeof bootstrap !== 'undefined');
    console.log('jQuery disponível:', typeof $ !== 'undefined');
    console.log('Modal tasks existe:', document.getElementById('manageTasksModal') !== null);
    console.log('Projeto selecionado:', <?= $selectedProject ? 'true' : 'false' ?>);
    
    // Testar se conseguimos abrir o modal manualmente
    window.testModal = function() {
        var modalEl = document.getElementById('manageTasksModal');
        if (modalEl) {
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
            console.log('Modal aberto com sucesso!');
        } else {
            console.error('Modal element não encontrado!');
        }
    };
    
    console.log('Para testar manualmente, execute: testModal()');
    console.log('==================');
});
</script>

<?php if (!$selectedProject): ?>
<script>
// Se não há projeto selecionado, avisar ao tentar abrir modal
function manageDeliverableTasks(deliverableId, deliverableTitle) {
    alert('Erro: Esta funcionalidade só está disponível quando um projeto está selecionado.');
    console.error('Tentou abrir modal sem projeto selecionado');
}
</script>
<?php endif; ?>

<script>
// ============ FUNÇÕES DE UPLOAD/DELETE DE FICHEIROS DO PROJETO ============

function uploadProjectFile(projectId) {
    const fileInput = document.getElementById('project-file-upload');
    const file = fileInput.files[0];
    const statusSpan = document.getElementById('upload-status');
    
    if (!file) {
        return;
    }
    
    // Validar tamanho (300MB)
    if (file.size > 300 * 1024 * 1024) {
        statusSpan.innerHTML = '<i class="bi bi-x-circle"></i> Ficheiro muito grande (máx 300MB)';
        statusSpan.className = 'ms-2 text-danger';
        fileInput.value = '';
        return;
    }
    
    // Mostrar progresso
    statusSpan.innerHTML = '<span class="spinner-border spinner-border-sm"></span> A enviar...';
    statusSpan.className = 'ms-2 text-primary';
    
    const formData = new FormData();
    formData.append('action', 'upload_project_file');
    formData.append('project_id', projectId);
    formData.append('file', file);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusSpan.innerHTML = '<i class="bi bi-check-circle"></i> Ficheiro adicionado!';
            statusSpan.className = 'ms-2 text-success';
            
            // Limpar input
            fileInput.value = '';
            
            // Recarregar a página após 1 segundo
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            statusSpan.innerHTML = '<i class="bi bi-x-circle"></i> Erro: ' + (data.error || 'Erro desconhecido');
            statusSpan.className = 'ms-2 text-danger';
            fileInput.value = '';
        }
    })
    .catch(err => {
        statusSpan.innerHTML = '<i class="bi bi-x-circle"></i> Erro de rede';
        statusSpan.className = 'ms-2 text-danger';
        fileInput.value = '';
        console.error('Erro:', err);
    });
}

function deleteProjectFile(fileId) {
    if (!confirm('Tem certeza que deseja eliminar este ficheiro?')) {
        return;
    }
    
    const fileElement = document.getElementById(`project-file-${fileId}`);
    if (fileElement) {
        fileElement.style.opacity = '0.5';
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_project_file');
    formData.append('file_id', fileId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (fileElement) {
                fileElement.remove();
            }
            
            // Verificar se não há mais ficheiros e mostrar mensagem
            const filesList = document.getElementById('project-files-list');
            const remainingFiles = filesList.querySelectorAll('.file-item');
            if (remainingFiles.length === 0) {
                filesList.innerHTML = '<p class="text-muted" id="no-files-message"><i class="bi bi-info-circle"></i> Nenhum ficheiro adicionado ainda</p>';
            }
        } else {
            alert('Erro ao eliminar ficheiro: ' + (data.error || 'Erro desconhecido'));
            if (fileElement) {
                fileElement.style.opacity = '1';
            }
        }
    })
    .catch(err => {
        alert('Erro de rede ao eliminar ficheiro');
        if (fileElement) {
            fileElement.style.opacity = '1';
        }
        console.error('Erro:', err);
    });
}
</script>

<!-- Lightbox notas -->
<div id="pjn-lightbox" onclick="this.style.display='none'"><img src="" alt=""></div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
(function(){
// ===== NOTAS DE PROJETO =====
const _pjnBase = '<?= rtrim(dirname($_SERVER['PHP_SELF']),'/') ?>/';
let _pjnProjectId = <?= $selectedProject ? (int)$selectedProject['id'] : 0 ?>;
let _pjnCurrentUserId = <?= (int)$_SESSION['user_id'] ?>;

function pjnEscH(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function pjnEscA(s){ return String(s).replace(/"/g,'&quot;'); }

function pjnLightbox(src){
    const lb=document.getElementById('pjn-lightbox');
    lb.querySelector('img').src=src;
    lb.style.display='flex';
}
window.pjnLightbox=pjnLightbox;

function pjnLoadNotes(pid){
    _pjnProjectId=pid;
    if(!pid) return;
    fetch('?tab=projectos&action=get_project_notes&project_id='+pid)
        .then(r=>r.json()).then(data=>{
            if(data.success) pjnRenderNotes(data.notes, data.current_user_id);
        }).catch(()=>{});
}
window.pjnLoadNotes=pjnLoadNotes;

function pjnRenderNotes(notes, uid){
    const el=document.getElementById('pjn-notes-list');
    if(!el) return;
    if(!notes||!notes.length){ el.innerHTML='<p class="text-muted small">Sem notas ainda.</p>'; return; }
    el.innerHTML=notes.map(n=>pjnNoteHtml(n, n.user_id==uid)).join('');
    if(typeof marked!=='undefined') el.querySelectorAll('.pjn-note-md-view').forEach(d=>{
        d.innerHTML=marked.parse(d.dataset.raw||'');
        d.querySelectorAll('img').forEach(i=>i.onclick=()=>pjnLightbox(i.src));
    });
}

function pjnNoteHtml(n, canEdit){
    const me=canEdit;
    const initials=(n.username||'?').substring(0,2).toUpperCase();
    const date=n.created_at?n.created_at.substring(0,16).replace('T',' '):'';
    const imgs=(n.images||[]).map(i=>`
        <div class="pjn-edit-img-ref" onclick="pjnLightbox('${pjnEscA(_pjnBase+i.file_path)}')">
            <img src="${pjnEscA(_pjnBase+i.file_path)}" alt="${pjnEscA(i.original_name||'')}">
            <div class="pjn-edit-img-ref-overlay"><i class="bi bi-zoom-in text-white"></i></div>
        </div>`).join('');
    return `<div class="pjn-user-block" id="pjn-note-${n.id}">
        <div class="pjn-user-header">
            <div class="pjn-avatar ${me?'pjn-avatar-me':''}">${pjnEscH(initials)}</div>
            <span class="small fw-semibold">${pjnEscH(n.username||'Desconhecido')}</span>
            <span class="small text-muted">${pjnEscH(date)}</span>
            ${me?`<div class="ms-auto d-flex gap-1">
                <button class="btn btn-xs btn-outline-secondary" style="padding:1px 7px;font-size:.75rem" onclick="pjnStartEdit(${n.id})"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-xs btn-outline-danger" style="padding:1px 7px;font-size:.75rem" onclick="pjnDeleteNote(${n.id})"><i class="bi bi-trash"></i></button>
            </div>`:''}
        </div>
        <div class="pjn-note-item">
            <div class="pjn-note-md-view" data-raw="${pjnEscA(n.note_text||'')}">${pjnEscH(n.note_text||'')}</div>
            ${imgs?`<div class="pjn-edit-img-gallery mt-2">${imgs}</div>`:''}
            ${me?`<div class="pjn-note-edit-area mt-2" style="display:none;">
                <div class="pjn-editor-tabs">
                    <button class="pjn-tab active" onclick="pjnEditorTab(this,'write','edit-${n.id}')">Escrever</button>
                    <button class="pjn-tab" onclick="pjnEditorTab(this,'preview','edit-${n.id}')">Pré-visualizar</button>
                </div>
                <div class="pjn-md-toolbar">
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdWrap(this,'**','**')"><b>B</b></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdWrap(this,'*','*')"><i>I</i></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsert(this,'`')"><i class="bi bi-code"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdBlock(this,'```\n','\n```')"><i class="bi bi-code-square"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsertLine(this,'- ')"><i class="bi bi-list-ul"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdInsertLine(this,'> ')"><i class="bi bi-blockquote-left"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnMdTable(this)"><i class="bi bi-table"></i></button>
                </div>
                <textarea class="form-control mb-1" rows="3">${pjnEscH(n.note_text||'')}</textarea>
                <div class="pjn-md-live-preview mb-1" style="display:none;"></div>
                <div class="mb-1"><input type="file" multiple accept="image/*" class="form-control form-control-sm" onchange="pjnPreviewImages(this,'pjn-edit-img-new-${n.id}')"></div>
                <div id="pjn-edit-img-new-${n.id}" class="pjn-edit-img-gallery mb-1"></div>
                <div class="pjn-edit-img-gallery mb-1">${(n.images||[]).map(i=>`
                    <div class="pjn-edit-img-ref" title="${pjnEscA(i.original_name||'')}">
                        <img src="${pjnEscA(_pjnBase+i.file_path)}" alt="">
                        <div class="pjn-edit-img-ref-overlay" onclick="pjnDelImg(${i.id},${n.id})"><i class="bi bi-trash text-white"></i></div>
                    </div>`).join('')}</div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" onclick="pjnSaveEdit(${n.id})"><i class="bi bi-save"></i> Guardar</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="pjnCancelEdit(${n.id})">Cancelar</button>
                </div>
            </div>`:''}
        </div>
    </div>`;
}

function pjnToggleAdd(){
    const f=document.getElementById('pjn-add-form');
    const visible=f.style.display!=='none';
    f.style.display=visible?'none':'block';
    if(!visible){
        document.getElementById('pjn-add-text').value='';
        document.getElementById('pjn-add-img-preview').innerHTML='';
        const tabs=f.querySelectorAll('.pjn-tab');
        tabs[0].classList.add('active'); tabs[1].classList.remove('active');
        f.querySelector('textarea').style.display='';
        f.querySelector('.pjn-md-live-preview').style.display='none';
        f.querySelector('.pjn-md-toolbar').style.display='';
    }
}
window.pjnToggleAdd=pjnToggleAdd;

function pjnStartEdit(nid){
    const block=document.getElementById('pjn-note-'+nid);
    block.querySelector('.pjn-note-md-view').parentElement.querySelector('.pjn-note-edit-area').style.display='block';
    block.querySelector('.pjn-note-md-view').style.display='none';
    const editArea=block.querySelector('.pjn-note-edit-area');
    const tabs=editArea.querySelectorAll('.pjn-tab');
    tabs[0].classList.add('active'); tabs[1].classList.remove('active');
    editArea.querySelector('textarea').style.display='';
    editArea.querySelector('.pjn-md-live-preview').style.display='none';
    editArea.querySelector('.pjn-md-toolbar').style.display='';
}
window.pjnStartEdit=pjnStartEdit;

function pjnCancelEdit(nid){
    const block=document.getElementById('pjn-note-'+nid);
    block.querySelector('.pjn-note-edit-area').style.display='none';
    block.querySelector('.pjn-note-md-view').style.display='';
}
window.pjnCancelEdit=pjnCancelEdit;

function pjnAddNote(){
    const text=document.getElementById('pjn-add-text').value.trim();
    const fd=new FormData();
    fd.append('action','add_project_note');
    fd.append('project_id',_pjnProjectId);
    fd.append('note_text',text);
    const imgs=document.getElementById('pjn-add-images').files;
    for(let i=0;i<imgs.length;i++) fd.append('images[]',imgs[i]);
    fetch(location.pathname+'?tab=projectos',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            if(d.success){ pjnToggleAdd(); pjnLoadNotes(_pjnProjectId); }
        });
}
window.pjnAddNote=pjnAddNote;

function pjnSaveEdit(nid){
    const block=document.getElementById('pjn-note-'+nid);
    const text=block.querySelector('.pjn-note-edit-area textarea').value;
    const fd=new FormData();
    fd.append('action','edit_project_note');
    fd.append('note_id',nid);
    fd.append('note_text',text);
    const imgs=block.querySelector('input[type=file]').files;
    for(let i=0;i<imgs.length;i++) fd.append('images[]',imgs[i]);
    fetch(location.pathname+'?tab=projectos',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{ if(d.success) pjnLoadNotes(_pjnProjectId); });
}
window.pjnSaveEdit=pjnSaveEdit;

function pjnDeleteNote(nid){
    if(!confirm('Eliminar esta nota?')) return;
    const fd=new FormData();
    fd.append('action','delete_project_note');
    fd.append('note_id',nid);
    fetch(location.pathname+'?tab=projectos',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{ if(d.success) pjnLoadNotes(_pjnProjectId); });
}
window.pjnDeleteNote=pjnDeleteNote;

function pjnDelImg(iid,nid){
    if(!confirm('Remover esta imagem?')) return;
    const fd=new FormData();
    fd.append('action','delete_project_note_image');
    fd.append('image_id',iid);
    fetch(location.pathname+'?tab=projectos',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{ if(d.success) pjnLoadNotes(_pjnProjectId); });
}
window.pjnDelImg=pjnDelImg;

function pjnEditorTab(btn,mode,ctx){
    const wrap=btn.closest('.pjn-editor-tabs').parentElement;
    wrap.querySelectorAll('.pjn-tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    const ta=wrap.querySelector('textarea');
    const prev=wrap.querySelector('.pjn-md-live-preview');
    const tb=wrap.querySelector('.pjn-md-toolbar');
    if(mode==='preview'){
        ta.style.display='none'; if(tb) tb.style.display='none';
        prev.style.display=''; prev.innerHTML=typeof marked!=='undefined'?marked.parse(ta.value):'(sem preview)';
    } else {
        ta.style.display=''; if(tb) tb.style.display='';
        prev.style.display='none';
    }
}
window.pjnEditorTab=pjnEditorTab;

function pjnPreviewImages(input,containerId){
    const c=document.getElementById(containerId); if(!c) return;
    c.innerHTML='';
    Array.from(input.files).forEach(f=>{
        const r=new FileReader();
        r.onload=e=>{
            c.innerHTML+=`<div class="pjn-edit-img-ref"><img src="${e.target.result}" style="width:60px;height:60px;object-fit:cover;border-radius:4px;"></div>`;
        };
        r.readAsDataURL(f);
    });
}
window.pjnPreviewImages=pjnPreviewImages;

// Toolbar helpers
function _pjnTa(btn){ return btn.closest('.pjn-md-toolbar').nextElementSibling; }
function pjnMdWrap(btn,o,c){ const ta=_pjnTa(btn);const s=ta.selectionStart,e=ta.selectionEnd,v=ta.value;ta.value=v.slice(0,s)+o+v.slice(s,e)+c+v.slice(e);ta.focus();ta.selectionStart=s+o.length;ta.selectionEnd=e+o.length; }
function pjnMdInsert(btn,t){ pjnMdWrap(btn,t,t); }
function pjnMdBlock(btn,o,c){ pjnMdWrap(btn,o,c); }
function pjnMdInsertLine(btn,p){ const ta=_pjnTa(btn);const s=ta.selectionStart,v=ta.value;const nl=v.lastIndexOf('\n',s-1)+1;ta.value=v.slice(0,nl)+p+v.slice(nl);ta.focus();ta.selectionStart=ta.selectionEnd=s+p.length; }
function pjnMdTable(btn){ pjnMdWrap(btn,'| Col1 | Col2 |\n|------|------|\n| val1 | val2 |',''); }
window.pjnMdWrap=pjnMdWrap; window.pjnMdInsert=pjnMdInsert; window.pjnMdBlock=pjnMdBlock;
window.pjnMdInsertLine=pjnMdInsertLine; window.pjnMdTable=pjnMdTable;

// Carregar notas ao abrir projeto
if(_pjnProjectId) pjnLoadNotes(_pjnProjectId);
})();
</script>