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

// Criar tabela sprints
try {
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
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro ao criar tabela sprints: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Criar tabela sprint_members
try {
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
} catch (PDOException $e) {
    // Tabela já existe ou erro não crítico
}

// Criar tabela sprint_projects (apenas se tabela projects existir)
if ($checkProjects) {
    try {
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
    } catch (PDOException $e) {
        // Tabela já existe
    }
}

// Criar tabela sprint_prototypes (apenas se tabela prototypes existir)
if ($checkPrototypes) {
    try {
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
    } catch (PDOException $e) {
        // Tabela já existe
    }
}

// Criar tabela sprint_tasks (apenas se tabela todos existir)
if ($checkTodos) {
    try {
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
    } catch (PDOException $e) {
        // Tabela já existe
    }
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
                // Redirecionar para lista após deletar
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
                
            case 'add_project':
                if ($checkProjects) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_projects (sprint_id, project_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['sprint_id'], $_POST['project_id']]);
                    $message = "Projeto associado à sprint!";
                } else {
                    $message = "Módulo de Projects não está instalado!";
                    $messageType = 'warning';
                }
                break;
                
            case 'remove_project':
                if ($checkProjects) {
                    $stmt = $pdo->prepare("DELETE FROM sprint_projects WHERE id=?");
                    $stmt->execute([$_POST['project_id']]);
                    $message = "Projeto removido da sprint!";
                }
                break;
                
            case 'add_prototype':
                if ($checkPrototypes) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_prototypes (sprint_id, prototype_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['sprint_id'], $_POST['prototype_id']]);
                    $message = "Protótipo associado à sprint!";
                } else {
                    $message = "Módulo de Prototypes não está instalado!";
                    $messageType = 'warning';
                }
                break;
                
            case 'remove_prototype':
                if ($checkPrototypes) {
                    $stmt = $pdo->prepare("DELETE FROM sprint_prototypes WHERE id=?");
                    $stmt->execute([$_POST['prototype_id']]);
                    $message = "Protótipo removido da sprint!";
                }
                break;
                
            case 'add_task':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                    $stmt->execute([$_POST['sprint_id'], $_POST['todo_id']]);
                    $message = "Task associada à sprint!";
                } else {
                    $message = "Módulo de ToDos não está instalado!";
                    $messageType = 'warning';
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
                } else {
                    $message = "Módulo de ToDos não está instalado!";
                    $messageType = 'warning';
                }
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
    
    if ($message && !headers_sent()) {
        header("Location: ?tab=sprints&sprint_id=" . ($_POST['sprint_id'] ?? $_GET['sprint_id'] ?? '') . "&message=" . urlencode($message) . "&type=$messageType" . (isset($_GET['filter_my_sprints']) ? '&filter_my_sprints=' . $_GET['filter_my_sprints'] : '') . (isset($_GET['show_closed']) ? '&show_closed=' . $_GET['show_closed'] : ''));
        exit;
    }
}

// Get current user ID from session
$current_user_id = $_SESSION['user_id'] ?? null;

// Get filter preference from URL or cookie
$filter_my_sprints = isset($_GET['filter_my_sprints']) ? $_GET['filter_my_sprints'] === '1' : ($_COOKIE['filter_my_sprints'] ?? '0') === '1';

// Save filter preference in cookie
if (isset($_GET['filter_my_sprints'])) {
    setcookie('filter_my_sprints', $_GET['filter_my_sprints'], time() + (86400 * 30), "/");
}

// Obter dados
$showClosed = isset($_GET['show_closed']) && $_GET['show_closed'] == '1';

try {
    // Build the query based on filter
    if ($filter_my_sprints && $current_user_id) {
        // Show only sprints where user is responsible or member
        $query = "
            SELECT DISTINCT s.*, u.username as responsavel_nome,
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
            WHERE (s.responsavel_id = ? OR sm.user_id IS NOT NULL)
        ";
        
        if (!$showClosed) {
            $query .= " AND s.estado != 'fechada'";
        }
        
        $query .= " ORDER BY 
                    CASE s.estado 
                        WHEN 'aberta' THEN 1 
                        WHEN 'pausa' THEN 2 
                        WHEN 'fechada' THEN 3 
                    END,
                    s.data_fim ASC,
                    s.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    } else {
        // Show all sprints
        $query = "
            SELECT s.*, u.username as responsavel_nome,
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
    }
    
    $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas para cada sprint
    foreach ($sprints as &$sprint) {
        // Calcular dias para deadline
        if ($sprint['data_fim']) {
            $hoje = new DateTime();
            $deadline = new DateTime($sprint['data_fim']);
            $diff = $hoje->diff($deadline);
            
            if ($hoje > $deadline) {
                $sprint['dias_restantes'] = -$diff->days;
                $sprint['status_deadline'] = 'atrasado';
            } else {
                $sprint['dias_restantes'] = $diff->days;
                if ($diff->days <= 3) {
                    $sprint['status_deadline'] = 'urgente';
                } elseif ($diff->days <= 7) {
                    $sprint['status_deadline'] = 'proximo';
                } else {
                    $sprint['status_deadline'] = 'normal';
                }
            }
        } else {
            $sprint['dias_restantes'] = null;
            $sprint['status_deadline'] = 'sem_deadline';
        }
        
        // Calcular progresso (tasks completadas / total tasks)
        if ($checkTodos && tableExists($pdo, 'sprint_tasks')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN t.estado = 'completada' THEN 1 ELSE 0 END) as completadas
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
            } catch (PDOException $e) {
                $sprint['total_tasks'] = 0;
                $sprint['tasks_completadas'] = 0;
                $sprint['percentagem'] = 0;
            }
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
try {
    $users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Obter projetos e protótipos se existirem
$projects = [];
$prototypes = [];
$availableTasks = [];

if ($checkProjects) {
    try {
        $projects = $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignorar erro
    }
}

if ($checkPrototypes) {
    try {
        $prototypes = $pdo->query("SELECT id, short_name, title FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignorar erro
    }
}

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
        
        if ($selectedSprint) {
            // Obter membros
            try {
                $stmt = $pdo->prepare("
                    SELECT sm.*, u.username 
                    FROM sprint_members sm 
                    JOIN user_tokens u ON sm.user_id = u.user_id 
                    WHERE sm.sprint_id=? 
                    ORDER BY u.username
                ");
                $stmt->execute([$selectedSprint['id']]);
                $selectedSprint['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $selectedSprint['members'] = [];
            }
            
            // Obter projetos
            $selectedSprint['projects'] = [];
            if ($checkProjects && tableExists($pdo, 'sprint_projects')) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT sp.*, p.short_name, p.title 
                        FROM sprint_projects sp 
                        JOIN projects p ON sp.project_id = p.id 
                        WHERE sp.sprint_id=?
                    ");
                    $stmt->execute([$selectedSprint['id']]);
                    $selectedSprint['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Ignorar erro
                }
            }
            
            // Obter protótipos
            $selectedSprint['prototypes'] = [];
            if ($checkPrototypes && tableExists($pdo, 'sprint_prototypes')) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT sp.*, p.short_name, p.title 
                        FROM sprint_prototypes sp 
                        JOIN prototypes p ON sp.prototype_id = p.id 
                        WHERE sp.sprint_id=?
                    ");
                    $stmt->execute([$selectedSprint['id']]);
                    $selectedSprint['prototypes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Ignorar erro
                }
            }
            
            // Obter tasks da sprint organizadas em kanban
            $selectedSprint['kanban'] = [
                'aberta' => [],
                'em execução' => [],
                'suspensa' => [],
                'completada' => []
            ];
            
            if ($checkTodos && tableExists($pdo, 'sprint_tasks')) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT st.id as st_id, t.*, u1.username as autor_nome, u2.username as responsavel_nome,
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
                    foreach ($selectedSprint['tasks'] as $task) {
                        $estado = $task['estado'] ?? 'aberta';
                        if (isset($selectedSprint['kanban'][$estado])) {
                            $selectedSprint['kanban'][$estado][] = $task;
                        }
                    }
                } catch (PDOException $e) {
                    // Ignorar erro
                }
                
                // Obter tasks disponíveis para adicionar
                try {
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
                } catch (PDOException $e) {
                    $availableTasks = [];
                }
            }
        }
    } catch (PDOException $e) {
        $message = "Erro ao carregar detalhes da sprint: " . $e->getMessage();
        $messageType = 'danger';
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

.filter-container {
    padding: 12px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    margin-bottom: 15px;
}

.filter-container .form-check-input {
    cursor: pointer;
}

.filter-container .form-check-label {
    cursor: pointer;
    font-weight: 500;
    color: #374151;
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

.sprint-item .badge {
    font-size: 10px;
    padding: 3px 6px;
}

.sprint-item .badge.bg-primary {
    background-color: #3b82f6 !important;
}

.sprint-item .badge.bg-info {
    background-color: #06b6d4 !important;
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

.sprint-deadline {
    font-size: 11px;
    padding: 3px 6px;
    border-radius: 3px;
    display: inline-block;
    margin-top: 5px;
    font-weight: 600;
}

.sprint-deadline.normal { background: #dbeafe; color: #1e40af; }
.sprint-deadline.proximo { background: #fef3c7; color: #92400e; }
.sprint-deadline.urgente { background: #fee2e2; color: #991b1b; }
.sprint-deadline.atrasado { background: #fecaca; color: #7f1d1d; }

.sprint-progress {
    margin-top: 8px;
}

.progress-bar-sprint {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    transition: width 0.3s ease;
}

.progress-bar-fill.complete {
    background: linear-gradient(90deg, #10b981, #059669);
}

.progress-text {
    font-size: 10px;
    color: #6b7280;
    margin-top: 2px;
}

.show-closed-container {
    margin-bottom: 15px;
    padding: 10px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.show-closed-container label {
    margin: 0;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

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
<div class="alert alert-<?= htmlspecialchars($_GET['type'] ?? 'success') ?> alert-dismissible fade show">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
    <?= htmlspecialchars($message) ?>
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
        
        <!-- Filter Toggle -->
        <div class="filter-container">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="filterMySprints" 
                       <?= $filter_my_sprints ? 'checked' : '' ?>
                       onchange="window.location.href='?tab=sprints&filter_my_sprints=' + (this.checked ? '1' : '0') + '<?= $showClosed ? '&show_closed=1' : '' ?><?= isset($_GET['sprint_id']) ? '&sprint_id=' . $_GET['sprint_id'] : '' ?>'">
                <label class="form-check-label" for="filterMySprints">
                    <i class="bi bi-person-check"></i> Only My Sprints
                </label>
            </div>
            <?php if ($filter_my_sprints): ?>
                <small class="text-muted d-block mt-1">
                    Showing sprints where you are responsible or member
                </small>
            <?php endif; ?>
        </div>
        
        <!-- Show Closed Toggle -->
        <div class="show-closed-container">
            <label>
                <input type="checkbox" id="showClosedCheckbox" <?= $showClosed ? 'checked' : '' ?> 
                       onchange="window.location.href='?tab=sprints<?= $filter_my_sprints ? '&filter_my_sprints=1' : '' ?>&show_closed=' + (this.checked ? '1' : '0')<?= isset($_GET['sprint_id']) ? '&sprint_id=' . $_GET['sprint_id'] : '' ?>'">
                <i class="bi bi-archive"></i> Show Closed Sprints
            </label>
        </div>
        
        <!-- Sprint List -->
        <?php if (empty($sprints)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                <p class="mt-2">
                    <?php if ($filter_my_sprints): ?>
                        No sprints found where you are involved
                    <?php else: ?>
                        No sprints created yet
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($sprints as $sprint): 
                $deadline_class = 'normal';
                if ($sprint['data_fim']) {
                    $today = new DateTime();
                    $deadline = new DateTime($sprint['data_fim']);
                    $diff = $today->diff($deadline);
                    
                    if ($deadline < $today) {
                        $deadline_class = 'atrasado';
                    } elseif ($diff->days <= 3) {
                        $deadline_class = 'urgente';
                    } elseif ($diff->days <= 7) {
                        $deadline_class = 'proximo';
                    }
                }
                
                $is_active = isset($_GET['sprint_id']) && $_GET['sprint_id'] == $sprint['id'];
            ?>
                <div class="sprint-item <?= $is_active ? 'active' : '' ?>" 
                     onclick="window.location.href='?tab=sprints<?= $filter_my_sprints ? '&filter_my_sprints=1' : '' ?><?= $showClosed ? '&show_closed=1' : '' ?>&sprint_id=<?= $sprint['id'] ?>'">
                    
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <strong style="font-size: 14px;"><?= htmlspecialchars($sprint['nome']) ?></strong>
                        <div>
                            <?php if ($sprint['is_responsible']): ?>
                                <span class="badge bg-primary" title="You are responsible">
                                    <i class="bi bi-star-fill"></i>
                                </span>
                            <?php endif; ?>
                            <?php if ($sprint['is_member']): ?>
                                <span class="badge bg-info" title="You are a member">
                                    <i class="bi bi-person-check"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div>
                        <span class="sprint-badge <?= $sprint['estado'] ?>">
                            <?= $sprint['estado'] ?>
                        </span>
                        
                        <?php if ($sprint['data_fim']): ?>
                            <span class="sprint-deadline <?= $deadline_class ?>">
                                <i class="bi bi-calendar-event"></i>
                                <?= date('d/m/Y', strtotime($sprint['data_fim'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($checkTodos && isset($sprint['total_tasks'])): ?>
                        <div class="sprint-progress">
                            <div class="progress-bar-sprint">
                                <div class="progress-bar-fill <?= $sprint['percentagem'] == 100 ? 'complete' : '' ?>" 
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
                        <?= $selectedSprint['responsavel_nome'] ? '👤 ' . htmlspecialchars($selectedSprint['responsavel_nome']) : 'Não definido' ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Período</div>
                    <div class="info-value">
                        <?php if ($selectedSprint['data_inicio'] && $selectedSprint['data_fim']): ?>
                            <?= date('d/m/Y', strtotime($selectedSprint['data_inicio'])) ?> - 
                            <?= date('d/m/Y', strtotime($selectedSprint['data_fim'])) ?>
                        <?php else: ?>
                            Não definido
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($checkTodos): ?>
                <div class="info-card">
                    <div class="info-label">Progresso</div>
                    <div class="info-value">
                        <?= $selectedSprint['tasks_completadas'] ?? 0 ?>/<?= $selectedSprint['total_tasks'] ?? 0 ?> tasks 
                        (<?= $selectedSprint['percentagem'] ?? 0 ?>%)
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Membros da Sprint -->
            <div class="section-header">
                <h5><i class="bi bi-people"></i> Membros</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="bi bi-person-plus"></i> Adicionar
                </button>
            </div>
            <div class="member-list">
                <?php if (!empty($selectedSprint['members'])): ?>
                    <?php foreach ($selectedSprint['members'] as $member): ?>
                        <div class="member-badge">
                            <span>👤 <?= htmlspecialchars($member['username']) ?></span>
                            <?php if ($member['role'] !== 'member'): ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($member['role']) ?></span>
                            <?php endif; ?>
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
                <?php else: ?>
                    <p class="text-muted">Nenhum membro adicionado</p>
                <?php endif; ?>
            </div>
            
            <!-- Projetos Associados -->
            <?php if ($checkProjects): ?>
            <div class="section-header">
                <h5><i class="bi bi-folder"></i> Projetos</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addProjectModal">
                    <i class="bi bi-link-45deg"></i> Associar
                </button>
            </div>
            <div class="project-list">
                <?php if (!empty($selectedSprint['projects'])): ?>
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
                <?php else: ?>
                    <p class="text-muted">Nenhum projeto associado</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Protótipos Associados -->
            <?php if ($checkPrototypes): ?>
            <div class="section-header">
                <h5><i class="bi bi-cpu"></i> Protótipos</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addPrototypeModal">
                    <i class="bi bi-link-45deg"></i> Associar
                </button>
            </div>
            <div class="prototype-list">
                <?php if (!empty($selectedSprint['prototypes'])): ?>
                    <?php foreach ($selectedSprint['prototypes'] as $prototype): ?>
                        <div class="prototype-badge">
                            <span>🔧 <?= htmlspecialchars($prototype['short_name']) ?></span>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este protótipo?')">
                                <input type="hidden" name="action" value="remove_prototype">
                                <input type="hidden" name="prototype_id" value="<?= $prototype['id'] ?>">
                                <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="text-decoration:none;">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">Nenhum protótipo associado</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Kanban Board -->
            <?php if ($checkTodos): ?>
            <div class="section-header">
                <h5><i class="bi bi-kanban"></i> Kanban de Tasks</h5>
                <div>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                        <i class="bi bi-link-45deg"></i> Associar Task
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
                            <div class="mt-2">
                                <button class="btn btn-sm btn-link text-danger p-0" onclick="removeTask(<?= $task['id'] ?>)">
                                    <i class="bi bi-x"></i> Remover
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
                            <div class="mt-2">
                                <button class="btn btn-sm btn-link text-danger p-0" onclick="removeTask(<?= $task['id'] ?>)">
                                    <i class="bi bi-x"></i> Remover
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
                            <div class="mt-2">
                                <button class="btn btn-sm btn-link text-danger p-0" onclick="removeTask(<?= $task['id'] ?>)">
                                    <i class="bi bi-x"></i> Remover
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Coluna: Completada -->
                <div class="kanban-column">
                    <div class="kanban-header completada">
                        ✅ Completada (<?= count($selectedSprint['kanban']['completada']) ?>)
                    </div>
                    <?php foreach ($selectedSprint['kanban']['completada'] as $task): ?>
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
                            <div class="mt-2">
                                <button class="btn btn-sm btn-link text-danger p-0" onclick="removeTask(<?= $task['id'] ?>)">
                                    <i class="bi bi-x"></i> Remover
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

<!-- Modais -->

<!-- Modal: Criar Sprint -->
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
                        <input type="text" name="nome" class="form-control" required placeholder="Ex: Sprint 1 - MVP">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3" placeholder="Objetivos e metas desta sprint"></textarea>
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
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="aberta">Aberta</option>
                            <option value="pausa">Pausa</option>
                            <option value="fechada">Fechada</option>
                        </select>
                    </div>
                    
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Sprint</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Sprint -->
<?php if ($selectedSprint): ?>
<div class="modal fade" id="editSprintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Sprint</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_sprint">
                    <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome da Sprint *</label>
                        <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($selectedSprint['nome']) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3"><?= htmlspecialchars($selectedSprint['descricao']) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?= $selectedSprint['data_inicio'] ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Deadline</label>
                            <input type="date" name="data_fim" class="form-control" value="<?= $selectedSprint['data_fim'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="aberta" <?= $selectedSprint['estado'] == 'aberta' ? 'selected' : '' ?>>Aberta</option>
                            <option value="pausa" <?= $selectedSprint['estado'] == 'pausa' ? 'selected' : '' ?>>Pausa</option>
                            <option value="fechada" <?= $selectedSprint['estado'] == 'fechada' ? 'selected' : '' ?>>Fechada</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="responsavel_id" class="form-select">
                            <option value="">Nenhum</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>" <?= $selectedSprint['responsavel_id'] == $user['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
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
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Utilizador</label>
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
                            <option value="member">Member</option>
                            <option value="lead">Lead</option>
                            <option value="developer">Developer</option>
                            <option value="tester">Tester</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Associar Projeto -->
<?php if ($checkProjects): ?>
<div class="modal fade" id="addProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Associar Projeto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_project">
                    <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Projeto</label>
                        <select name="project_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= $project['id'] ?>">
                                    <?= htmlspecialchars($project['short_name']) ?> - <?= htmlspecialchars($project['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Associar Protótipo -->
<?php if ($checkPrototypes): ?>
<div class="modal fade" id="addPrototypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Associar Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_prototype">
                    <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Protótipo</label>
                        <select name="prototype_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($prototypes as $prototype): ?>
                                <option value="<?= $prototype['id'] ?>">
                                    <?= htmlspecialchars($prototype['short_name']) ?> - <?= htmlspecialchars($prototype['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Associar Task -->
<?php if ($checkTodos): ?>
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Associar Task à Sprint</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_task">
                    <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Task</label>
                        <select name="todo_id" class="form-select" required size="10">
                            <option value="">Selecione uma task...</option>
                            <?php foreach ($availableTasks as $task): ?>
                                <option value="<?= $task['id'] ?>">
                                    [<?= htmlspecialchars($task['estado']) ?>] <?= htmlspecialchars($task['titulo']) ?>
                                    <?php if ($task['responsavel_nome']): ?>
                                        - 👤 <?= htmlspecialchars($task['responsavel_nome']) ?>
                                    <?php endif; ?>
                                    <?php if ($task['data_limite']): ?>
                                        - 📅 <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            <?= count($availableTasks) ?> tasks disponíveis (excluindo completadas e já associadas)
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function changeTaskStatus(taskId, newStatus) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="change_task_status">
        <input type="hidden" name="todo_id" value="${taskId}">
        <input type="hidden" name="estado" value="${newStatus}">
        <input type="hidden" name="sprint_id" value="<?= $selectedSprint['id'] ?? '' ?>">
    `;
    document.body.appendChild(form);
    form.submit();
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

// Auto-dismiss alerts após 5 segundos
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Drag and drop para o Kanban
document.addEventListener('DOMContentLoaded', function() {
    const kanbanTasks = document.querySelectorAll('.kanban-task');
    const kanbanColumns = document.querySelectorAll('.kanban-column');
    
    kanbanTasks.forEach(task => {
        task.setAttribute('draggable', true);
        
        task.addEventListener('dragstart', function(e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
            e.dataTransfer.setData('taskId', this.dataset.taskId);
            this.style.opacity = '0.4';
        });
        
        task.addEventListener('dragend', function(e) {
            this.style.opacity = '1';
        });
    });
    
    kanbanColumns.forEach(column => {
        column.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            return false;
        });
        
        column.addEventListener('dragenter', function(e) {
            this.style.background = '#e0e7ff';
        });
        
        column.addEventListener('dragleave', function(e) {
            this.style.background = '';
        });
        
        column.addEventListener('drop', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            this.style.background = '';
            
            const taskId = e.dataTransfer.getData('taskId');
            const columnHeader = this.querySelector('.kanban-header');
            
            // Determinar novo estado baseado na coluna
            let newStatus = 'aberta';
            if (columnHeader.classList.contains('execucao')) {
                newStatus = 'em execução';
            } else if (columnHeader.classList.contains('suspensa')) {
                newStatus = 'suspensa';
            } else if (columnHeader.classList.contains('completada')) {
                newStatus = 'completada';
            }
            
            if (confirm(`Mover task para "${newStatus}"?`)) {
                changeTaskStatus(taskId, newStatus);
            }
            
            return false;
        });
    });
});
</script>