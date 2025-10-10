<?php
// tabs/projecto.php - Sistema Completo de Gestão de Projetos
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

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
    todo_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id),
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

// Processar ações
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_project':
                $stmt = $pdo->prepare("INSERT INTO projects (short_name, title, description, owner_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $_POST['owner_id'] ?: null
                ]);
                $message = "Projeto criado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_project':
                $stmt = $pdo->prepare("UPDATE projects SET short_name=?, title=?, description=?, owner_id=? WHERE id=?");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $_POST['owner_id'] ?: null,
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
                break;
                
            case 'update_deliverable':
                $stmt = $pdo->prepare("UPDATE project_deliverables SET title=?, description=?, due_date=?, status=? WHERE id=?");
                $stmt->execute([
                    $_POST['deliverable_title'],
                    $_POST['deliverable_description'] ?? '',
                    $_POST['due_date'] ?: null,
                    $_POST['status'],
                    $_POST['deliverable_id']
                ]);
                $message = "Entregável atualizado!";
                $messageType = 'success';
                break;
                
            case 'convert_to_todo':
                // Verificar se tabela todos existe
                $checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
                if ($checkTodos) {
                    $deliverable = $pdo->prepare("SELECT * FROM project_deliverables WHERE id=?");
                    $deliverable->execute([$_POST['deliverable_id']]);
                    $deliv = $deliverable->fetch(PDO::FETCH_ASSOC);
                    
                    // Criar todo
                    $stmt = $pdo->prepare("INSERT INTO todos (titulo, descritivo, data_limite, autor, estado) VALUES (?, ?, ?, ?, 'aberta')");
                    $stmt->execute([
                        $deliv['title'],
                        $deliv['description'],
                        $deliv['due_date'],
                        $_SESSION['user_id']
                    ]);
                    $todoId = $pdo->lastInsertId();
                    
                    // Associar todo ao deliverable
                    $stmt = $pdo->prepare("UPDATE project_deliverables SET todo_id=? WHERE id=?");
                    $stmt->execute([$todoId, $_POST['deliverable_id']]);
                    
                    $message = "Entregável convertido em ToDo com sucesso!";
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
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obter dados para exibição
$projects = $pdo->query("SELECT p.*, u.username as owner_name FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id ORDER BY p.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Obter usuários disponíveis
$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Obter protótipos disponíveis
$checkPrototypes = $pdo->query("SHOW TABLES LIKE 'prototypes'")->fetch();
$prototypes = [];
if ($checkPrototypes) {
    $prototypes = $pdo->query("SELECT id, short_name, title FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Verificar se a tabela todos existe
$checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
$todosExist = (bool)$checkTodos;

// Obter projeto selecionado
$selectedProject = null;
if (isset($_GET['project_id'])) {
    $stmt = $pdo->prepare("SELECT p.*, u.username as owner_name FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id WHERE p.id=?");
    $stmt->execute([$_GET['project_id']]);
    $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedProject) {
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
                $stmt = $pdo->prepare("SELECT id, title, as_a, i_want, so_that, priority, status FROM user_stories WHERE prototype_id=? ORDER BY priority DESC, id");
                $stmt->execute([$proto['id']]);
                $proto['stories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
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
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.deliverable-title {
    font-weight: 600;
    color: #212529;
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

.priority-high { background: #f8d7da; color: #842029; }
.priority-medium { background: #fff3cd; color: #856404; }
.priority-low { background: #d1e7dd; color: #0f5132; }

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
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Projetos</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newProjectModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            
            <?php if (empty($projects)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-folder-x" style="font-size: 32px;"></i>
                    <p class="mt-2 mb-0">Nenhum projeto</p>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $proj): ?>
                    <a href="?tab=projecto&project_id=<?= $proj['id'] ?>" class="text-decoration-none">
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
                        <span><i class="bi bi-info-circle"></i> Informações Básicas</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProjectModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                    </div>
                    
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
                        <?php if ($selectedProject['description']): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Descrição</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selectedProject['description'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
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
                        <span><i class="bi bi-check2-square"></i> Entregáveis</span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addDeliverableModal">
                            <i class="bi bi-plus-lg"></i> Adicionar
                        </button>
                    </div>
                    
                    <?php if (empty($selectedProject['deliverables'])): ?>
                        <p class="text-muted">Nenhum entregável definido</p>
                    <?php else: ?>
                        <?php foreach ($selectedProject['deliverables'] as $deliv): ?>
                            <div class="deliverable-item <?= $deliv['status'] ?>">
                                <div class="deliverable-header">
                                    <div class="deliverable-title"><?= htmlspecialchars($deliv['title']) ?></div>
                                    <div>
                                        <span class="status-badge status-<?= $deliv['status'] ?>">
                                            <?= ucfirst($deliv['status']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($deliv['description']): ?>
                                    <p class="mb-2 small"><?= nl2br(htmlspecialchars($deliv['description'])) ?></p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="small text-muted">
                                        <?php if ($deliv['due_date']): ?>
                                            <i class="bi bi-calendar-event"></i> <?= date('d/m/Y', strtotime($deliv['due_date'])) ?>
                                        <?php endif; ?>
                                        <?php if ($deliv['todo_id']): ?>
                                            <span class="badge bg-info ms-2">
                                                <i class="bi bi-check-circle"></i> Convertido em ToDo
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if ($todosExist && !$deliv['todo_id']): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="convert_to_todo">
                                                <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Converter em ToDo">
                                                    <i class="bi bi-arrow-right-circle"></i> ToDo
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editDeliverable(<?= htmlspecialchars(json_encode($deliv)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_deliverable">
                                            <input type="hidden" name="deliverable_id" value="<?= $deliv['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover este entregável?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
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
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="remove_prototype">
                                        <input type="hidden" name="prototype_id" value="<?= $proto['association_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Desassociar este protótipo?')">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </div>
                                
                                <?php if (!empty($proto['stories'])): ?>
                                    <div class="mt-2">
                                        <small class="text-muted fw-bold">User Stories:</small>
                                        <?php foreach ($proto['stories'] as $story): ?>
                                            <div class="story-item">
                                                <span class="story-priority priority-<?= strtolower($story['priority'] ?? 'medium') ?>">
                                                    <?= strtoupper($story['priority'] ?? 'medium') ?>
                                                </span>
                                                <strong><?= htmlspecialchars($story['title']) ?></strong>
                                                <div class="small text-muted mt-1">
                                                    Como <?= htmlspecialchars($story['as_a']) ?>, 
                                                    quero <?= htmlspecialchars($story['i_want']) ?>, 
                                                    para <?= htmlspecialchars($story['so_that']) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="small text-muted mb-0 mt-2">Nenhuma user story definida neste protótipo</p>
                                <?php endif; ?>
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
                        <select name="status" id="edit_status" class="form-select">
                            <option value="pending">Pendente</option>
                            <option value="in-progress">Em Progresso</option>
                            <option value="completed">Concluído</option>
                        </select>
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
function editDeliverable(deliverable) {
    document.getElementById('edit_deliverable_id').value = deliverable.id;
    document.getElementById('edit_deliverable_title').value = deliverable.title;
    document.getElementById('edit_deliverable_description').value = deliverable.description || '';
    document.getElementById('edit_due_date').value = deliverable.due_date || '';
    document.getElementById('edit_status').value = deliverable.status;
    
    var modal = new bootstrap.Modal(document.getElementById('editDeliverableModal'));
    modal.show();
}
</script>