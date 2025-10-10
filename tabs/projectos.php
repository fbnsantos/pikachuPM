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
$projects = $pdo->query("SELECT p.*, u.username as owner_name FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id ORDER BY p.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Obter usuários disponíveis
$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

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
            }
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
                    <a href="?tab=projectos&project_id=<?= $proj['id'] ?>" class="text-decoration-none">
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
                                    <div class="deliverable-actions">
                                        <span class="status-badge status-<?= $deliv['status'] ?>">
                                            <?= ucfirst(str_replace('-', ' ', $deliv['status'])) ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editDeliverable(<?= htmlspecialchars(json_encode($deliv)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success" onclick="manageDeliverableTasks(<?= $deliv['id'] ?>, '<?= htmlspecialchars($deliv['title']) ?>')">
                                            <i class="bi bi-list-task"></i> Tasks
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
                                
                                <?php if ($deliv['description']): ?>
                                    <p class="mb-2 small"><?= nl2br(htmlspecialchars($deliv['description'])) ?></p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="small text-muted">
                                        <?php if ($deliv['due_date']): ?>
                                            <i class="bi bi-calendar-event"></i> <?= date('d/m/Y', strtotime($deliv['due_date'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($deliv['tasks'])): ?>
                                    <div class="task-list">
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
                                                <span class="story-priority priority-<?= strtolower($story['moscow_priority'] ?? 'should') ?>">
                                                    <?= strtoupper($story['moscow_priority'] ?? 'SHOULD') ?>
                                                </span>
                                                <div class="small mt-1">
                                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
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