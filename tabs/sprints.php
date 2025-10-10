<?php
// tabs/sprints.php - Sistema Completo de Gestão de Sprints
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
CREATE TABLE IF NOT EXISTS sprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_inicio DATE,
    data_fim DATE,
    estado ENUM('aberta', 'pausa', 'fechada') DEFAULT 'aberta',
    responsavel_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (responsavel_id) REFERENCES user_tokens(user_id),
    INDEX idx_estado (estado),
    INDEX idx_responsavel (responsavel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sprint_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_sprint_user (sprint_id, user_id),
    INDEX idx_sprint (sprint_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sprint_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT NOT NULL,
    project_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sprint_project (sprint_id, project_id),
    INDEX idx_sprint (sprint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sprint_prototypes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT NOT NULL,
    prototype_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sprint_prototype (sprint_id, prototype_id),
    INDEX idx_sprint (sprint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sprint_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT NOT NULL,
    todo_id INT NOT NULL,
    position INT DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sprint_task (sprint_id, todo_id),
    INDEX idx_sprint (sprint_id),
    INDEX idx_todo (todo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Processar ações
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'create_sprint':
                $stmt = $pdo->prepare("INSERT INTO sprints (nome, descricao, data_inicio, data_fim, estado, responsavel_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['nome'],
                    $_POST['descricao'] ?? '',
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    $_POST['estado'] ?? 'aberta',
                    $_POST['responsavel_id'] ?: null
                ]);
                $message = "Sprint criada com sucesso!";
                break;
                
            case 'update_sprint':
                $stmt = $pdo->prepare("UPDATE sprints SET nome=?, descricao=?, data_inicio=?, data_fim=?, estado=?, responsavel_id=? WHERE id=?");
                $stmt->execute([
                    $_POST['nome'],
                    $_POST['descricao'] ?? '',
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    $_POST['estado'] ?? 'aberta',
                    $_POST['responsavel_id'] ?: null,
                    $_POST['sprint_id']
                ]);
                $message = "Sprint atualizada com sucesso!";
                break;
                
            case 'delete_sprint':
                $stmt = $pdo->prepare("DELETE FROM sprints WHERE id=?");
                $stmt->execute([$_POST['sprint_id']]);
                $message = "Sprint removida com sucesso!";
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_members (sprint_id, user_id, role) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['sprint_id'], $_POST['user_id'], $_POST['role'] ?? 'member']);
                $message = "Membro adicionado à sprint!";
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM sprint_members WHERE id=?");
                $stmt->execute([$_POST['member_id']]);
                $message = "Membro removido da sprint!";
                break;
                
            case 'add_project':
                $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_projects (sprint_id, project_id) VALUES (?, ?)");
                $stmt->execute([$_POST['sprint_id'], $_POST['project_id']]);
                $message = "Projeto associado à sprint!";
                break;
                
            case 'remove_project':
                $stmt = $pdo->prepare("DELETE FROM sprint_projects WHERE id=?");
                $stmt->execute([$_POST['project_id']]);
                $message = "Projeto removido da sprint!";
                break;
                
            case 'add_prototype':
                $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_prototypes (sprint_id, prototype_id) VALUES (?, ?)");
                $stmt->execute([$_POST['sprint_id'], $_POST['prototype_id']]);
                $message = "Protótipo associado à sprint!";
                break;
                
            case 'remove_prototype':
                $stmt = $pdo->prepare("DELETE FROM sprint_prototypes WHERE id=?");
                $stmt->execute([$_POST['prototype_id']]);
                $message = "Protótipo removido da sprint!";
                break;
                
            case 'add_task':
                $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                $stmt->execute([$_POST['sprint_id'], $_POST['todo_id']]);
                $message = "Task adicionada à sprint!";
                break;
                
            case 'create_task':
                // Criar nova task na tabela todos
                $stmt = $pdo->prepare("INSERT INTO todos (titulo, descritivo, data_limite, estado, autor, responsavel, projeto_id) VALUES (?, ?, ?, 'aberta', ?, ?, ?)");
                $stmt->execute([
                    $_POST['titulo'],
                    $_POST['descritivo'] ?? '',
                    $_POST['data_limite'] ?: null,
                    $_SESSION['user_id'],
                    $_POST['responsavel_id'] ?: null,
                    $_POST['projeto_id'] ?: null
                ]);
                $todoId = $pdo->lastInsertId();
                
                // Associar à sprint
                $stmt = $pdo->prepare("INSERT INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                $stmt->execute([$_POST['sprint_id'], $todoId]);
                $message = "Task criada e adicionada à sprint!";
                break;
                
            case 'remove_task':
                $stmt = $pdo->prepare("DELETE FROM sprint_tasks WHERE id=?");
                $stmt->execute([$_POST['task_id']]);
                $message = "Task removida da sprint!";
                break;
                
            case 'update_task_status':
                $stmt = $pdo->prepare("UPDATE todos SET estado=? WHERE id=?");
                $stmt->execute([$_POST['estado'], $_POST['todo_id']]);
                $message = "Estado da task atualizado!";
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
    
    if ($message) {
        header("Location: ?tab=sprints&sprint_id=" . ($_POST['sprint_id'] ?? $_GET['sprint_id'] ?? '') . "&message=" . urlencode($message) . "&type=$messageType");
        exit;
    }
}

// Obter dados
$sprints = $pdo->query("
    SELECT s.*, u.username as responsavel_nome 
    FROM sprints s 
    LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id 
    WHERE s.estado != 'fechada'
    ORDER BY 
        CASE s.estado 
            WHEN 'aberta' THEN 1 
            WHEN 'pausa' THEN 2 
            ELSE 3 
        END,
        s.data_fim ASC
")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Verificar se existem tabelas de projetos e protótipos
$checkProjects = $pdo->query("SHOW TABLES LIKE 'projects'")->fetch();
$checkPrototypes = $pdo->query("SHOW TABLES LIKE 'prototypes'")->fetch();
$checkTodos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();

$projects = $checkProjects ? $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC) : [];
$prototypes = $checkPrototypes ? $pdo->query("SELECT id, short_name, title FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC) : [];

// Obter sprint selecionada
$selectedSprint = null;
if (isset($_GET['sprint_id'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.username as responsavel_nome 
        FROM sprints s 
        LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id 
        WHERE s.id=?
    ");
    $stmt->execute([$_GET['sprint_id']]);
    $selectedSprint = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedSprint) {
        // Obter membros
        $stmt = $pdo->prepare("
            SELECT sm.*, u.username 
            FROM sprint_members sm 
            JOIN user_tokens u ON sm.user_id = u.user_id 
            WHERE sm.sprint_id=? 
            ORDER BY u.username
        ");
        $stmt->execute([$selectedSprint['id']]);
        $selectedSprint['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obter projetos
        if ($checkProjects) {
            $stmt = $pdo->prepare("
                SELECT sp.*, p.short_name, p.title 
                FROM sprint_projects sp 
                JOIN projects p ON sp.project_id = p.id 
                WHERE sp.sprint_id=?
            ");
            $stmt->execute([$selectedSprint['id']]);
            $selectedSprint['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Obter protótipos
        if ($checkPrototypes) {
            $stmt = $pdo->prepare("
                SELECT sp.*, p.short_name, p.title 
                FROM sprint_prototypes sp 
                JOIN prototypes p ON sp.prototype_id = p.id 
                WHERE sp.sprint_id=?
            ");
            $stmt->execute([$selectedSprint['id']]);
            $selectedSprint['prototypes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Obter tasks
        if ($checkTodos) {
            $stmt = $pdo->prepare("
                SELECT st.*, t.titulo, t.descritivo, t.estado, t.data_limite, t.projeto_id,
                       u1.username as autor_nome, u2.username as responsavel_nome,
                       p.short_name as projeto_nome
                FROM sprint_tasks st 
                JOIN todos t ON st.todo_id = t.id 
                LEFT JOIN user_tokens u1 ON t.autor = u1.user_id 
                LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
                LEFT JOIN projects p ON t.projeto_id = p.id
                WHERE st.sprint_id=?
                ORDER BY st.position, t.created_at DESC
            ");
            $stmt->execute([$selectedSprint['id']]);
            $selectedSprint['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Agrupar tasks por estado para o kanban
            $selectedSprint['kanban'] = [
                'aberta' => [],
                'em execução' => [],
                'suspensa' => [],
                'completada' => []
            ];
            
            foreach ($selectedSprint['tasks'] as $task) {
                $estado = $task['estado'] ?? 'aberta';
                if (isset($selectedSprint['kanban'][$estado])) {
                    $selectedSprint['kanban'][$estado][] = $task;
                }
            }
            
            // Obter tasks disponíveis para adicionar
            $stmt = $pdo->query("
                SELECT t.id, t.titulo, t.estado, t.data_limite, t.projeto_id,
                       u1.username as autor_nome, u2.username as responsavel_nome,
                       p.short_name as projeto_nome
                FROM todos t 
                LEFT JOIN user_tokens u1 ON t.autor = u1.user_id 
                LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
                LEFT JOIN projects p ON t.projeto_id = p.id
                WHERE t.estado != 'completada'
                AND t.id NOT IN (SELECT todo_id FROM sprint_tasks WHERE sprint_id = {$selectedSprint['id']})
                ORDER BY t.created_at DESC
                LIMIT 100
            ");
            $availableTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
?>

<style>
.sprints-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 150px);
}

.sprints-sidebar {
    width: 300px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    overflow-y: auto;
}

.sprint-item {
    padding: 12px;
    margin-bottom: 10px;
    background: white;
    border-radius: 6px;
    border: 2px solid #e1e8ed;
    cursor: pointer;
    transition: all 0.2s;
}

.sprint-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.1);
}

.sprint-item.active {
    border-color: #3b82f6;
    background: #eff6ff;
}

.sprint-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-top: 5px;
}

.sprint-badge.aberta { background: #dcfce7; color: #166534; }
.sprint-badge.pausa { background: #fef3c7; color: #92400e; }
.sprint-badge.fechada { background: #f3f4f6; color: #6b7280; }

.sprint-details {
    flex: 1;
    background: white;
    border-radius: 8px;
    padding: 20px;
    overflow-y: auto;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.info-card {
    padding: 15px;
    background: #f9fafb;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.info-label {
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 15px;
    color: #1a202c;
    font-weight: 500;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 30px 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e5e7eb;
}

.member-list, .project-list, .prototype-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.member-badge, .project-badge, .prototype-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.kanban-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-top: 20px;
}

.kanban-column {
    background: #f9fafb;
    border-radius: 8px;
    padding: 15px;
    min-height: 400px;
}

.kanban-header {
    font-weight: 600;
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 6px;
    text-align: center;
}

.kanban-header.aberta { background: #dcfce7; color: #166534; }
.kanban-header.execucao { background: #dbeafe; color: #1e40af; }
.kanban-header.suspensa { background: #fef3c7; color: #92400e; }
.kanban-header.completada { background: #f3f4f6; color: #6b7280; }

.kanban-task {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    cursor: move;
    transition: all 0.2s;
}

.kanban-task:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.task-title {
    font-weight: 600;
    margin-bottom: 5px;
    color: #1a202c;
}

.task-meta {
    font-size: 12px;
    color: #6b7280;
    display: flex;
    gap: 10px;
    align-items: center;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 20px;
    opacity: 0.3;
}
</style>

<?php if (isset($_GET['message'])): ?>
<div class="alert alert-<?= $_GET['type'] ?? 'success' ?> alert-dismissible fade show">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="sprints-container">
    <!-- Sidebar: Lista de Sprints -->
    <div class="sprints-sidebar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i class="bi bi-flag"></i> Sprints Abertas</h5>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createSprintModal">
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>
        
        <?php if (empty($sprints)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size: 32px;"></i>
                <p class="mt-2">Nenhuma sprint aberta</p>
            </div>
        <?php else: ?>
            <?php foreach ($sprints as $sprint): ?>
                <div class="sprint-item <?= $selectedSprint && $selectedSprint['id'] == $sprint['id'] ? 'active' : '' ?>" 
                     onclick="window.location='?tab=sprints&sprint_id=<?= $sprint['id'] ?>'">
                    <div style="font-weight: 600; margin-bottom: 5px;">
                        <?= htmlspecialchars($sprint['nome']) ?>
                    </div>
                    <div style="font-size: 12px; color: #6b7280;">
                        <?php if ($sprint['data_fim']): ?>
                            📅 <?= date('d/m/Y', strtotime($sprint['data_fim'])) ?>
                        <?php endif; ?>
                        <?php if ($sprint['responsavel_nome']): ?>
                            <br>👤 <?= htmlspecialchars($sprint['responsavel_nome']) ?>
                        <?php endif; ?>
                    </div>
                    <span class="sprint-badge <?= $sprint['estado'] ?>">
                        <?= $sprint['estado'] ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Main Content: Detalhes da Sprint -->
    <div class="sprint-details">
        <?php if (!$selectedSprint): ?>
            <div class="empty-state">
                <i class="bi bi-flag"></i>
                <h4>Selecione uma sprint</h4>
                <p>Escolha uma sprint da lista ou crie uma nova para começar</p>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3><?= htmlspecialchars($selectedSprint['nome']) ?></h3>
                    <?php if ($selectedSprint['descricao']): ?>
                        <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($selectedSprint['descricao'])) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSprintModal">
                        <i class="bi bi-pencil"></i> Editar
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('Remover esta sprint?')) { document.getElementById('deleteSprintForm').submit(); }">
                        <i class="bi bi-trash"></i>
                    </button>
                    <form id="deleteSprintForm" method="POST" style="display:none;">
                        <input type="hidden" name="action" value="delete_sprint">
                        <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    </form>
                </div>
            </div>
            
            <!-- Informações Básicas -->
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Estado</div>
                    <div class="info-value">
                        <span class="sprint-badge <?= $selectedSprint['estado'] ?>">
                            <?= $selectedSprint['estado'] ?>
                        </span>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Responsável</div>
                    <div class="info-value">
                        <?= $selectedSprint['responsavel_nome'] ? '👤 ' . htmlspecialchars($selectedSprint['responsavel_nome']) : '—' ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Data Início</div>
                    <div class="info-value">
                        <?= $selectedSprint['data_inicio'] ? date('d/m/Y', strtotime($selectedSprint['data_inicio'])) : '—' ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Deadline</div>
                    <div class="info-value">
                        <?= $selectedSprint['data_fim'] ? date('d/m/Y', strtotime($selectedSprint['data_fim'])) : '—' ?>
                    </div>
                </div>
            </div>
            
            <!-- Membros -->
            <div class="section-header">
                <h5><i class="bi bi-people"></i> Equipa</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="bi bi-person-plus"></i> Adicionar
                </button>
            </div>
            <div class="member-list">
                <?php if (empty($selectedSprint['members'])): ?>
                    <span class="text-muted">Nenhum membro associado</span>
                <?php else: ?>
                    <?php foreach ($selectedSprint['members'] as $member): ?>
                        <div class="member-badge">
                            <span>👤 <?= htmlspecialchars($member['username']) ?></span>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este membro?')">
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="text-decoration:none;">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Projetos -->
            <?php if ($checkProjects): ?>
            <div class="section-header">
                <h5><i class="bi bi-folder"></i> Projetos</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                    <i class="bi bi-plus-lg"></i> Adicionar
                </button>
            </div>
            <div class="project-list">
                <?php if (empty($selectedSprint['projects'])): ?>
                    <span class="text-muted">Nenhum projeto associado</span>
                <?php else: ?>
                    <?php foreach ($selectedSprint['projects'] as $project): ?>
                        <div class="project-badge">
                            <span>📁 <?= htmlspecialchars($project['short_name']) ?></span>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este projeto?')">
                                <input type="hidden" name="action" value="remove_project">
                                <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="text-decoration:none;">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div