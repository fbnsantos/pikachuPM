<?php
// tabs/sprints.php - Sistema Completo de Gestão de Sprints
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Habilitar exibição de erros para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once __DIR__ . '/../config.php';

// Conectar à base de dados com tratamento de erro
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro de conexão à base de dados: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Função para verificar se tabela existe
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '$tableName'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Verificar tabelas necessárias
$checkTodos = tableExists($pdo, 'todos');
$checkProjects = tableExists($pdo, 'projects');
$checkPrototypes = tableExists($pdo, 'prototypes');
$checkUserTokens = tableExists($pdo, 'user_tokens');

if (!$checkUserTokens) {
    die("<div class='alert alert-danger'>Erro: Tabela 'user_tokens' não existe. Este módulo requer utilizadores cadastrados.</div>");
}

// Criar tabelas necessárias
try {
    // Criar tabela sprints
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
        INDEX idx_estado (estado),
        INDEX idx_responsavel (responsavel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Criar tabela sprint_members
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS sprint_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sprint_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
        UNIQUE KEY unique_sprint_user (sprint_id, user_id),
        INDEX idx_sprint (sprint_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Criar tabela sprint_tasks se tabela todos existir
    if ($checkTodos) {
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
    }
    
    // Criar tabela sprint_projects se tabela projects existir
    if ($checkProjects) {
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
    }
    
    // Criar tabela sprint_prototypes se tabela prototypes existir
    if ($checkPrototypes) {
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
    }
} catch (PDOException $e) {
    // Tabelas já existem ou erro não crítico
}

// Get current user ID from session
$current_user_id = $_SESSION['user_id'] ?? null;

// Verificar se o usuário está logado
if (!$current_user_id) {
    die("<div class='alert alert-danger'>Erro: Sessão inválida. Por favor, faça login novamente.</div>");
}

// Processar ações
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
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
                header("Location: ?tab=sprints&message=" . urlencode($message) . "&type=success");
                exit;
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
                
            case 'add_task':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['sprint_id'], $_POST['todo_id']]);
                    $message = "Task associada à sprint!";
                }
                break;
                
            case 'remove_task':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("DELETE FROM sprint_tasks WHERE sprint_id=? AND todo_id=?");
                    $stmt->execute([$_POST['sprint_id'], $_POST['task_id']]);
                    $message = "Task removida da sprint!";
                }
                break;
                
            case 'change_task_status':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("UPDATE todos SET estado=? WHERE id=?");
                    $stmt->execute([$_POST['estado'], $_POST['todo_id']]);
                    $message = "Estado da task atualizado!";
                }
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
    
    if ($message && !headers_sent()) {
        $redirect_params = [
            'tab' => 'sprints',
            'message' => urlencode($message),
            'type' => $messageType
        ];
        
        if (isset($_POST['sprint_id']) || isset($_GET['sprint_id'])) {
            $redirect_params['sprint_id'] = $_POST['sprint_id'] ?? $_GET['sprint_id'];
        }
        
        header("Location: ?" . http_build_query($redirect_params));
        exit;
    }
}

// Get filter preferences
$filter_my_sprints = isset($_GET['filter_my_sprints']) && $_GET['filter_my_sprints'] === '1';
$filter_responsible_only = isset($_GET['filter_responsible_only']) && $_GET['filter_responsible_only'] === '1';
$showClosed = isset($_GET['show_closed']) && $_GET['show_closed'] === '1';

// Obter sprints com filtros
try {
    $query = "
        SELECT s.*, 
               u.username as responsavel_nome,
               (SELECT COUNT(*) FROM sprint_members WHERE sprint_id = s.id) as total_membros,
               CASE 
                   WHEN s.responsavel_id = ? THEN 1
                   ELSE 0
               END as is_responsible,
               CASE 
                   WHEN sm.user_id IS NOT NULL THEN 1
                   ELSE 0
               END as is_member
        FROM sprints s
        LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
        LEFT JOIN sprint_members sm ON s.id = sm.sprint_id AND sm.user_id = ?
    ";
    
    if (!$showClosed) {
        $query .= " WHERE s.estado != 'fechada'";
    }
    
    $query .= " GROUP BY s.id
                ORDER BY 
                CASE s.estado 
                    WHEN 'aberta' THEN 1 
                    WHEN 'pausa' THEN 2 
                    WHEN 'fechada' THEN 3 
                END,
                s.data_fim ASC,
                s.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$current_user_id, $current_user_id]);
    $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas para cada sprint
    foreach ($sprints as &$sprint) {
        if ($checkTodos && tableExists($pdo, 'sprint_tasks')) {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN t.estado = 'concluída' OR t.estado = 'completada' THEN 1 ELSE 0 END) as completadas
                FROM sprint_tasks st
                JOIN todos t ON st.todo_id = t.id
                WHERE st.sprint_id = ?
            ");
            $stmt->execute([$sprint['id']]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $sprint['total_tasks'] = $stats['total'] ?? 0;
            $sprint['tasks_completadas'] = $stats['completadas'] ?? 0;
            $sprint['percentagem'] = $sprint['total_tasks'] > 0 
                ? round(($sprint['tasks_completadas'] / $sprint['total_tasks']) * 100) 
                : 0;
        } else {
            $sprint['total_tasks'] = 0;
            $sprint['tasks_completadas'] = 0;
            $sprint['percentagem'] = 0;
        }
    }
    unset($sprint);
    
} catch (PDOException $e) {
    $sprints = [];
    $message = "Erro ao carregar sprints: " . $e->getMessage();
    $messageType = 'danger';
}

// Obter usuários
$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Obter sprint selecionada
$selectedSprint = null;
if (isset($_GET['sprint_id']) && !empty($_GET['sprint_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, u.username as responsavel_nome 
            FROM sprints s 
            LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id 
            WHERE s.id=?
        ");
        $stmt->execute([$_GET['sprint_id']]);
        $selectedSprint = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selectedSprint && $checkTodos) {
            // Inicializar kanban
            $selectedSprint['kanban'] = [
                'aberta' => [],
                'em execução' => [],
                'suspensa' => [],
                'completada' => [],
                'concluída' => [] // Alias
            ];
            
            // Obter tasks da sprint
            $stmt = $pdo->prepare("
                SELECT t.*, 
                       u1.username as autor_nome, 
                       u2.username as responsavel_nome
                FROM sprint_tasks st
                JOIN todos t ON st.todo_id = t.id
                LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
                LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
                WHERE st.sprint_id = ?
                ORDER BY st.position, t.created_at DESC
            ");
            $stmt->execute([$selectedSprint['id']]);
            $selectedSprint['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Agrupar tasks por estado
            foreach ($selectedSprint['tasks'] as $task) {
                $estado = $task['estado'] ?? 'aberta';
                if (isset($selectedSprint['kanban'][$estado])) {
                    $selectedSprint['kanban'][$estado][] = $task;
                }
                // Tratar também "concluída" como "completada"
                if ($estado === 'concluída' && !isset($selectedSprint['kanban']['concluída'])) {
                    $selectedSprint['kanban']['completada'][] = $task;
                }
            }
        }
    } catch (PDOException $e) {
        $message = "Erro ao carregar sprint: " . $e->getMessage();
        $messageType = 'danger';
    }
}
?>
<!-- Continuação do sprints.php - Parte 2: HTML/CSS/JavaScript -->

<style>
.sprints-container {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    min-height: 80vh;
}

.sprints-sidebar {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    max-height: 85vh;
    overflow-y: auto;
}

.sprint-item {
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.sprint-item:hover {
    background: #f8f9fa;
    border-color: #667eea;
}

.sprint-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
}

.sprint-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.sprint-badge.aberta { background: #dcfce7; color: #166534; }
.sprint-badge.pausa { background: #fef3c7; color: #92400e; }
.sprint-badge.fechada { background: #f3f4f6; color: #6b7280; }

.sprint-details {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.info-card {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 16px;
    font-weight: 600;
    color: #1a202c;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 30px 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e5e7eb;
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
    margin-bottom: 8px;
}

/* Botões de ação nas tasks */
.task-actions {
    display: flex;
    gap: 5px;
    margin-top: 8px;
}

.btn-edit-task {
    padding: 4px 8px;
    font-size: 12px;
}

.btn-edit-task:hover {
    transform: scale(1.05);
}

.kanban-task .btn {
    padding: 4px 8px;
    font-size: 12px;
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

.progress-bar-container {
    margin-top: 10px;
}

.progress {
    height: 8px;
    border-radius: 4px;
    background: #e5e7eb;
}

.progress-bar {
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 4px;
    transition: width 0.3s;
}

.progress-bar.complete {
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}

.progress-text {
    font-size: 11px;
    color: #6b7280;
    margin-top: 5px;
}

.filter-container {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-container label {
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

<?php if (isset($_GET['message'])): ?>
<div class="alert alert-<?= htmlspecialchars($_GET['type'] ?? 'success') ?> alert-dismissible fade show">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="sprints-container">
    <!-- Sidebar: Lista de Sprints -->
    <div class="sprints-sidebar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i class="bi bi-flag"></i> Sprints</h5>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createSprintModal">
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>
        
        <!-- Filtros -->
        <div class="filter-container">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="filterMySprints" 
                       <?= $filter_my_sprints ? 'checked' : '' ?>
                       onchange="toggleFilter('filter_my_sprints', this.checked)">
                <label class="form-check-label" for="filterMySprints">
                    <i class="bi bi-person"></i> Minhas Sprints
                </label>
            </div>
            
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="showClosed" 
                       <?= $showClosed ? 'checked' : '' ?>
                       onchange="toggleFilter('show_closed', this.checked)">
                <label class="form-check-label" for="showClosed">
                    <i class="bi bi-archive"></i> Mostrar Fechadas
                </label>
            </div>
        </div>
        
        <!-- Lista de Sprints -->
        <?php if (empty($sprints)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                <p class="mt-2">Nenhuma sprint encontrada</p>
            </div>
        <?php else: ?>
            <?php foreach ($sprints as $sprint): 
                $is_active = isset($_GET['sprint_id']) && $_GET['sprint_id'] == $sprint['id'];
            ?>
                <div class="sprint-item <?= $is_active ? 'active' : '' ?>" 
                     onclick="window.location.href='?tab=sprints&sprint_id=<?= $sprint['id'] ?>'">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><?= htmlspecialchars($sprint['nome']) ?></strong>
                        <span class="sprint-badge <?= $sprint['estado'] ?>">
                            <?= $sprint['estado'] ?>
                        </span>
                    </div>
                    
                    <?php if ($sprint['data_fim']): ?>
                        <div class="small text-muted">
                            <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($sprint['data_fim'])) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($checkTodos): ?>
                        <div class="progress-bar-container">
                            <div class="progress">
                                <div class="progress-bar <?= $sprint['percentagem'] >= 100 ? 'complete' : '' ?>" 
                                     style="width: <?= $sprint['percentagem'] ?>%"></div>
                            </div>
                            <div class="progress-text">
                                <?= $sprint['tasks_completadas'] ?>/<?= $sprint['total_tasks'] ?> tasks 
                                (<?= $sprint['percentagem'] ?>%)
                            </div>
                        </div>
                    <?php endif; ?>
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
                        <?= $selectedSprint['responsavel_nome'] ? '👤 ' . htmlspecialchars($selectedSprint['responsavel_nome']) : 'Sem responsável' ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Data Início</div>
                    <div class="info-value">
                        <?= $selectedSprint['data_inicio'] ? date('d/m/Y', strtotime($selectedSprint['data_inicio'])) : '-' ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Deadline</div>
                    <div class="info-value">
                        <?= $selectedSprint['data_fim'] ? date('d/m/Y', strtotime($selectedSprint['data_fim'])) : '-' ?>
                    </div>
                </div>
            </div>
            
            <!-- Kanban Board -->
            <?php if ($checkTodos): ?>
            <div class="section-header">
                <h5><i class="bi bi-kanban"></i> Kanban de Tasks</h5>
                <div>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                        <i class="bi bi-link-45deg"></i> Associar Task
                    </button>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                        <i class="bi bi-plus-lg"></i> Nova Task
                    </button>
                </div>
            </div>
            
            <div class="kanban-board">
                <!-- Coluna: Aberta -->
                <div class="kanban-column">
                    <div class="kanban-header aberta">
                        🟡 Aberta (<?= count($selectedSprint['kanban']['aberta']) ?>)
                    </div>
                    <?php foreach ($selectedSprint['kanban']['aberta'] as $task): ?>
                        <div class="kanban-task" data-task-id="<?= $task['id'] ?>">
                            <div class="task-title"><?= htmlspecialchars($task['titulo']) ?></div>
                            <div class="task-meta">
                                <?php if ($task['responsavel_nome']): ?>
                                    <span>👤 <?= htmlspecialchars($task['responsavel_nome']) ?></span>
                                <?php endif; ?>
                                <?php if ($task['data_limite']): ?>
                                    <span>📅 <?= date('d/m', strtotime($task['data_limite'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="task-actions">
                                <button class="btn btn-sm btn-primary btn-edit-task" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="removeTask(<?= $task['id'] ?>)" title="Remover">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Coluna: Em Execução -->
                <div class="kanban-column">
                    <div class="kanban-header execucao">
                        🔵 Em Execução (<?= count($selectedSprint['kanban']['em execução']) ?>)
                    </div>
                    <?php foreach ($selectedSprint['kanban']['em execução'] as $task): ?>
                        <div class="kanban-task" data-task-id="<?= $task['id'] ?>">
                            <div class="task-title"><?= htmlspecialchars($task['titulo']) ?></div>
                            <div class="task-meta">
                                <?php if ($task['responsavel_nome']): ?>
                                    <span>👤 <?= htmlspecialchars($task['responsavel_nome']) ?></span>
                                <?php endif; ?>
                                <?php if ($task['data_limite']): ?>
                                    <span>📅 <?= date('d/m', strtotime($task['data_limite'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="task-actions">
                                <button class="btn btn-sm btn-primary btn-edit-task" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="removeTask(<?= $task['id'] ?>)" title="Remover">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Coluna: Suspensa -->
                <div class="kanban-column">
                    <div class="kanban-header suspensa">
                        🟠 Suspensa (<?= count($selectedSprint['kanban']['suspensa']) ?>)
                    </div>
                    <?php foreach ($selectedSprint['kanban']['suspensa'] as $task): ?>
                        <div class="kanban-task" data-task-id="<?= $task['id'] ?>">
                            <div class="task-title"><?= htmlspecialchars($task['titulo']) ?></div>
                            <div class="task-meta">
                                <?php if ($task['responsavel_nome']): ?>
                                    <span>👤 <?= htmlspecialchars($task['responsavel_nome']) ?></span>
                                <?php endif; ?>
                                <?php if ($task['data_limite']): ?>
                                    <span>📅 <?= date('d/m', strtotime($task['data_limite'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="task-actions">
                                <button class="btn btn-sm btn-primary btn-edit-task" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="removeTask(<?= $task['id'] ?>)" title="Remover">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Coluna: Completada -->
                <div class="kanban-column">
                    <div class="kanban-header completada">
                        ✅ Completada (<?= count($selectedSprint['kanban']['completada']) + count($selectedSprint['kanban']['concluída']) ?>)
                    </div>
                    <?php 
                    $completedTasks = array_merge(
                        $selectedSprint['kanban']['completada'],
                        $selectedSprint['kanban']['concluída']
                    );
                    foreach ($completedTasks as $task): 
                    ?>
                        <div class="kanban-task" data-task-id="<?= $task['id'] ?>">
                            <div class="task-title"><?= htmlspecialchars($task['titulo']) ?></div>
                            <div class="task-meta">
                                <?php if ($task['responsavel_nome']): ?>
                                    <span>👤 <?= htmlspecialchars($task['responsavel_nome']) ?></span>
                                <?php endif; ?>
                                <?php if ($task['data_limite']): ?>
                                    <span>📅 <?= date('d/m', strtotime($task['data_limite'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="task-actions">
                                <button class="btn btn-sm btn-primary btn-edit-task" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="removeTask(<?= $task['id'] ?>)" title="Remover">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info mt-4">
                <i class="bi bi-info-circle"></i> Instale o módulo de ToDos para gerenciar tasks nas sprints.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modais (criar/editar sprint, etc) -->
<div class="modal fade" id="createSprintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova Sprint</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_sprint">
                    <div class="mb-3">
                        <label class="form-label">Nome da Sprint *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Deadline</label>
                            <input type="date" name="data_fim" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="responsavel_id" class="form-select">
                            <option value="">Sem responsável</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Sprint</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleFilter(filterName, checked) {
    const url = new URL(window.location.href);
    if (checked) {
        url.searchParams.set(filterName, '1');
    } else {
        url.searchParams.delete(filterName);
    }
    window.location.href = url.toString();
}

function removeTask(taskId) {
    if (!confirm('Remover esta task da sprint?')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="remove_task">
        <input type="hidden" name="task_id" value="${taskId}">
        <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?? '' ?>">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>

<?php
// Incluir editor universal de tasks
include __DIR__ . '/../edit_task.php';
?>
