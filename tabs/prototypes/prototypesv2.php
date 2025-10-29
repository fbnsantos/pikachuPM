<?php
// tabs/prototypes/prototypesv2.php - Sistema Completo de Gestão de Protótipos
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once __DIR__ . '/../../config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro de conexão à base de dados: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Verificar se tabela sprints existe
$checkSprints = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'sprints'");
    $checkSprints = $result->rowCount() > 0;
} catch (PDOException $e) {
    $checkSprints = false;
}

// Verificar se tabela todos existe
$checkTodos = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'todos'");
    $checkTodos = $result->rowCount() > 0;
} catch (PDOException $e) {
    $checkTodos = false;
}

// Criar tabelas necessárias
try {
    // Tabela prototype_members
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS prototype_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prototype_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
        UNIQUE KEY unique_prototype_user (prototype_id, user_id),
        INDEX idx_prototype (prototype_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Tabela user_story_sprints (se sprints existe)
    if ($checkSprints) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_story_sprints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            sprint_id INT NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_sprint (story_id, sprint_id),
            INDEX idx_story (story_id),
            INDEX idx_sprint (sprint_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Verificar e adicionar coluna responsavel_id à tabela prototypes se não existir
    $checkColumn = $pdo->query("SHOW COLUMNS FROM prototypes LIKE 'responsavel_id'")->fetch();
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE prototypes ADD COLUMN responsavel_id INT NULL AFTER name");
    }
    
    // Verificar e adicionar coluna completion_percentage à tabela user_stories
    $checkCompletionColumn = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'completion_percentage'")->fetch();
    if (!$checkCompletionColumn) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN completion_percentage INT DEFAULT 0 AFTER status");
    }
    
    // Criar tabela story_tasks para associar tasks a stories (se todos existe)
    if ($checkTodos) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS story_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            todo_id INT NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_task (story_id, todo_id),
            INDEX idx_story (story_id),
            INDEX idx_task (todo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (PDOException $e) {
    // Tabelas já existem
}

// Obter lista de utilizadores
$users = [];
try {
    $stmt = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignorar erro
}

// Obter lista de sprints (se existir)
$sprints = [];
if ($checkSprints) {
    try {
        $stmt = $pdo->query("SELECT id, nome, estado FROM sprints WHERE estado != 'fechada' ORDER BY nome");
        $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignorar erro
    }
}

// Obter lista de projetos (se existir)
$projects = [];
try {
    $result = $pdo->query("SHOW TABLES LIKE 'projects'");
    if ($result->rowCount() > 0) {
        $stmt = $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignorar erro
}

// Processar ações
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'create_prototype':
                $stmt = $pdo->prepare("
                    INSERT INTO prototypes (short_name, title, vision, target_group, needs, 
                                          product_description, business_goals, sentence, 
                                          repo_links, documentation_links, name, responsavel_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '',
                    $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '',
                    $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '',
                    $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['responsavel_id'] ?: null
                ]);
                $message = "Protótipo criado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_prototype':
                $stmt = $pdo->prepare("
                    UPDATE prototypes SET 
                        short_name=?, title=?, vision=?, target_group=?, needs=?,
                        product_description=?, business_goals=?, sentence=?,
                        repo_links=?, documentation_links=?, name=?, responsavel_id=?,
                        updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '',
                    $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '',
                    $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '',
                    $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['responsavel_id'] ?: null,
                    $_POST['prototype_id']
                ]);
                $message = "Protótipo atualizado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'delete_prototype':
                $stmt = $pdo->prepare("DELETE FROM prototypes WHERE id=?");
                $stmt->execute([$_POST['prototype_id']]);
                $message = "Protótipo eliminado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("
                    INSERT INTO prototype_members (prototype_id, user_id, role)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE role=VALUES(role)
                ");
                $stmt->execute([
                    $_POST['prototype_id'],
                    $_POST['user_id'],
                    $_POST['role'] ?? 'member'
                ]);
                $message = "Membro adicionado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM prototype_members WHERE id=?");
                $stmt->execute([$_POST['member_id']]);
                $message = "Membro removido com sucesso!";
                $messageType = 'success';
                break;
                
            case 'create_story':
                $stmt = $pdo->prepare("
                    INSERT INTO user_stories (prototype_id, story_text, moscow_priority, status, completion_percentage, created_at)
                    VALUES (?, ?, ?, 'open', 0, NOW())
                ");
                $stmt->execute([
                    $_POST['prototype_id'],
                    $_POST['story_text'],
                    $_POST['moscow_priority'] ?? 'Should'
                ]);
                $message = "User Story criada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar para evitar resubmissão do formulário
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $_POST['prototype_id'];
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'update_story':
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET 
                        story_text=?, moscow_priority=?, status=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['story_text'],
                    $_POST['moscow_priority'],
                    $_POST['status'],
                    $_POST['story_id']
                ]);
                $message = "User Story atualizada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar para evitar resubmissão do formulário
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'update_story_percentage':
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET 
                        completion_percentage=?, updated_at=NOW()
                    WHERE id=?
                ");
                $percentage = max(0, min(100, (int)$_POST['percentage']));
                $stmt->execute([$percentage, $_POST['story_id']]);
                $message = "Percentagem atualizada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'toggle_story_status':
                // Alternar status entre open e closed
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET 
                        status = CASE 
                            WHEN status = 'open' THEN 'closed' 
                            ELSE 'open' 
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['story_id']]);
                $message = "Status da story atualizado com sucesso!";
                $messageType = 'success';
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'delete_story':
                $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id=?");
                $stmt->execute([$_POST['story_id']]);
                
                // Obter prototype_id do POST (enviado pelo formulário)
                $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'create_task_from_story':
                if ($checkTodos) {
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    if (!$current_user_id) {
                        throw new Exception('Sessão expirada. Por favor, faça login novamente.');
                    }
                    
                    // Criar a task
                    $stmt = $pdo->prepare("
                        INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, projeto_id, estado, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'aberta', NOW())
                    ");
                    $stmt->execute([
                        $_POST['titulo'],
                        $_POST['descritivo'] ?? '',
                        $_POST['data_limite'] ?: null,
                        $current_user_id,
                        $_POST['responsavel'] ?: null,
                        $_POST['projeto_id'] ?: null
                    ]);
                    
                    $todo_id = $pdo->lastInsertId();
                    
                    // Associar automaticamente à user story
                    if (!empty($_POST['story_id'])) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO story_tasks (story_id, todo_id) VALUES (?, ?)");
                        $stmt->execute([$_POST['story_id'], $todo_id]);
                    }
                    
                    // Se foi selecionada uma sprint, associar automaticamente
                    if (!empty($_POST['sprint_id']) && $checkSprints) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                        $stmt->execute([$_POST['sprint_id'], $todo_id]);
                    }
                    
                    $message = "Task criada e associada com sucesso!";
                    $messageType = 'success';
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Tasks não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'add_story_to_sprint':
                if ($checkSprints) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO user_story_sprints (story_id, sprint_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([
                        $_POST['story_id'],
                        $_POST['sprint_id']
                    ]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Sprints não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'remove_story_from_sprint':
                if ($checkSprints) {
                    $stmt = $pdo->prepare("DELETE FROM user_story_sprints WHERE id=?");
                    $stmt->execute([$_POST['association_id']]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                }
                break;
                
            case 'add_task_to_story':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO story_tasks (story_id, todo_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([
                        $_POST['story_id'],
                        $_POST['todo_id']
                    ]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Tasks não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'remove_task_from_story':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("DELETE FROM story_tasks WHERE id=?");
                    $stmt->execute([$_POST['association_id']]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                }
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obter filtros
$filterMine = isset($_GET['filter_mine']) ? $_GET['filter_mine'] === 'true' : false;
$filterParticipate = isset($_GET['filter_participate']) ? $_GET['filter_participate'] === 'true' : false;
$showClosedStories = isset($_GET['show_closed']) ? $_GET['show_closed'] === 'true' : false;
$selectedPrototypeId = $_GET['prototype_id'] ?? null;
$currentUserId = $_SESSION['user_id'] ?? null;

// Buscar protótipos
$whereConditions = [];
$params = [];

if ($filterMine && $currentUserId) {
    $whereConditions[] = "p.responsavel_id = ?";
    $params[] = $currentUserId;
}

if ($filterParticipate && $currentUserId) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM prototype_members pm WHERE pm.prototype_id = p.id AND pm.user_id = ?)";
    $params[] = $currentUserId;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' OR ', $whereConditions) : '';

$sql = "
    SELECT p.*, u.username as responsavel_nome
    FROM prototypes p
    LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
    $whereClause
    ORDER BY p.short_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se um protótipo está selecionado, carregar seus detalhes
$selectedPrototype = null;
if ($selectedPrototypeId) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username as responsavel_nome
        FROM prototypes p
        LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$selectedPrototypeId]);
    $selectedPrototype = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPrototype) {
        // Obter membros
        $stmt = $pdo->prepare("
            SELECT pm.*, u.username
            FROM prototype_members pm
            JOIN user_tokens u ON pm.user_id = u.user_id
            WHERE pm.prototype_id = ?
            ORDER BY u.username
        ");
        $stmt->execute([$selectedPrototypeId]);
        $selectedPrototype['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obter user stories (com filtro de status)
        $statusCondition = $showClosedStories ? "" : "AND status = 'open'";
        $stmt = $pdo->prepare("
            SELECT * FROM user_stories 
            WHERE prototype_id = ? $statusCondition
            ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won''t'), id
        ");
        $stmt->execute([$selectedPrototypeId]);
        $selectedPrototype['stories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Para cada story, obter sprints associadas (se módulo sprints existe)
        if ($checkSprints) {
            foreach ($selectedPrototype['stories'] as &$story) {
                $stmt = $pdo->prepare("
                    SELECT uss.id as association_id, s.id as sprint_id, s.nome, s.estado
                    FROM user_story_sprints uss
                    JOIN sprints s ON uss.sprint_id = s.id
                    WHERE uss.story_id = ?
                    ORDER BY s.nome
                ");
                $stmt->execute([$story['id']]);
                $story['sprints'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($story); // CRÍTICO: Destruir a referência após o loop!
        }
        
        // Para cada story, obter tasks associadas (se módulo todos existe)
        if ($checkTodos) {
            foreach ($selectedPrototype['stories'] as &$story) {
                $stmt = $pdo->prepare("
                    SELECT st.id as association_id, t.*, u1.username as autor_nome, u2.username as responsavel_nome
                    FROM story_tasks st
                    JOIN todos t ON st.todo_id = t.id
                    LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
                    LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
                    WHERE st.story_id = ?
                    ORDER BY t.created_at DESC
                ");
                $stmt->execute([$story['id']]);
                $story['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($story); // CRÍTICO: Destruir a referência após o loop!
        }
    }
}

// Buscar tasks disponíveis para associar (se um protótipo está selecionado e todos existe)
$availableTasks = [];
if ($selectedPrototype && $checkTodos) {
    try {
        $stmt = $pdo->query("
            SELECT t.id, t.titulo, t.estado, t.data_limite,
                   u1.username as autor_nome, u2.username as responsavel_nome
            FROM todos t 
            LEFT JOIN user_tokens u1 ON t.autor = u1.user_id 
            LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
            WHERE t.estado != 'completada'
            ORDER BY t.created_at DESC
            LIMIT 200
        ");
        $availableTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $availableTasks = [];
    }
}
?>

<style>
.prototypes-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 150px);
}

.prototypes-sidebar {
    width: 350px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.sidebar-header {
    margin-bottom: 15px;
}

.sidebar-header h4 {
    margin: 0 0 10px 0;
    color: #1a202c;
}

.filter-container {
    padding: 12px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    margin-bottom: 15px;
}

.filter-container .form-check {
    margin-bottom: 8px;
}

.filter-container .form-check:last-child {
    margin-bottom: 0;
}

.filter-container .form-check-input {
    cursor: pointer;
}

.filter-container .form-check-label {
    cursor: pointer;
    font-weight: 500;
    color: #374151;
}

.search-box {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.search-box input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #e1e8ed;
    border-radius: 6px;
    font-size: 14px;
}

.prototype-item {
    padding: 12px;
    margin-bottom: 10px;
    background: white;
    border-radius: 6px;
    border: 2px solid #e1e8ed;
    cursor: pointer;
    transition: all 0.2s;
}

.prototype-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.1);
}

.prototype-item.active {
    border-color: #3b82f6;
    background: #eff6ff;
}

.prototype-item h5 {
    margin: 0 0 5px 0;
    font-size: 15px;
    color: #1a202c;
    font-weight: 600;
}

.prototype-item p {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
}

.prototype-item .badge {
    font-size: 10px;
    padding: 3px 6px;
    margin-top: 5px;
}

.prototypes-content {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: white;
    border-radius: 8px;
}

.detail-section {
    background: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
}

.detail-section h5 {
    margin: 0 0 15px 0;
    color: #1a202c;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-card {
    background: white;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #1a202c;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 14px;
    color: #4a5568;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e1e8ed;
    border-radius: 6px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.member-list,
.story-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.member-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.member-badge span:first-child {
    flex: 1;
    font-weight: 500;
    color: #1a202c;
}

.story-item {
    background: white;
    border-left: 4px solid #94a3b8;
    padding: 15px;
    border-radius: 6px;
    transition: all 0.2s;
}

.story-item.must {
    border-left-color: #ef4444;
}

.story-item.should {
    border-left-color: #f59e0b;
}

.story-item.could {
    border-left-color: #3b82f6;
}

.story-item.wont {
    border-left-color: #94a3b8;
}

.story-item.closed {
    opacity: 0.6;
    background: #f8f9fa;
}

.story-item.closed .story-text {
    text-decoration: line-through;
    color: #6b7280;
}

.story-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.story-priority {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.priority-must {
    background: #fee2e2;
    color: #991b1b;
}

.priority-should {
    background: #fed7aa;
    color: #92400e;
}

.priority-could {
    background: #dbeafe;
    color: #1e40af;
}

.priority-wont {
    background: #e2e8f0;
    color: #475569;
}

.story-text {
    font-size: 14px;
    line-height: 1.6;
    color: #374151;
}

.story-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.story-sprints {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
}

.sprint-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 12px;
}

.sprint-badge.aberta {
    border-color: #10b981;
    background: #d1fae5;
    color: #065f46;
}

.sprint-badge.pausa {
    border-color: #f59e0b;
    background: #fef3c7;
    color: #92400e;
}

.sprint-badge.fechada {
    border-color: #6b7280;
    background: #f3f4f6;
    color: #374151;
}

.sprint-badge a {
    color: inherit;
    text-decoration: none;
}

.sprint-badge a:hover {
    text-decoration: underline;
}

.story-progress {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
}

.progress-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar-container {
    flex: 1;
    height: 20px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    transition: width 0.3s ease;
    border-radius: 10px;
}

.progress-percentage {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    min-width: 40px;
    text-align: right;
}

.progress-edit-btn {
    padding: 2px 8px;
    font-size: 11px;
}

.story-tasks {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
}

.task-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 8px;
    transition: all 0.2s;
    cursor: pointer;
}

.task-badge:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
    background: #f9fafb;
}

.task-badge.aberta {
    border-left: 3px solid #fbbf24;
}

.task-badge.em-execucao {
    border-left: 3px solid #3b82f6;
}

.task-badge.suspensa {
    border-left: 3px solid #f59e0b;
}

.task-badge.completada {
    border-left: 3px solid #10b981;
    opacity: 0.7;
}

.task-info {
    flex: 1;
}

.task-title {
    font-weight: 500;
    color: #1a202c;
    margin-bottom: 4px;
}

.task-meta {
    font-size: 12px;
    color: #6b7280;
}

.filter-select {
    margin-bottom: 10px;
}

.filter-select input {
    width: 100%;
    padding: 8px;
    border: 1px solid #e1e8ed;
    border-radius: 4px;
    font-size: 13px;
}

.filter-select input:focus {
    outline: none;
    border-color: #3b82f6;
}


.btn-group {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

.empty-state h3 {
    font-size: 24px;
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 16px;
}

@media (max-width: 768px) {
    .prototypes-container {
        flex-direction: column;
    }
    
    .prototypes-sidebar {
        width: 100%;
        max-height: 40vh;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="prototypes-container">
    <!-- Sidebar Esquerda -->
    <div class="prototypes-sidebar">
        <div class="sidebar-header">
            <h4>Protótipos</h4>
            
            <!-- Filtros -->
            <div class="filter-container">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterMine" 
                           <?= $filterMine ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="filterMine">
                        Sou Responsável
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterParticipate" 
                           <?= $filterParticipate ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="filterParticipate">
                        Participo
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showClosedStories" 
                           <?= $showClosedStories ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="showClosedStories">
                        Mostrar Stories Fechadas
                    </label>
                </div>
            </div>
            
            <!-- Busca e Adicionar -->
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Buscar..." onkeyup="filterPrototypes()">
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newPrototypeModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </div>
        
        <!-- Lista de Protótipos -->
        <div id="prototypesList">
            <?php if (empty($prototypes)): ?>
                <p class="text-muted text-center">Nenhum protótipo encontrado</p>
            <?php else: ?>
                <?php foreach ($prototypes as $proto): ?>
                    <div class="prototype-item <?= $proto['id'] == $selectedPrototypeId ? 'active' : '' ?>"
                         onclick="window.location.href='?tab=prototypes/prototypesv2&prototype_id=<?= $proto['id'] ?><?= $filterMine ? '&filter_mine=true' : '' ?><?= $filterParticipate ? '&filter_participate=true' : '' ?>'">
                        <h5><?= htmlspecialchars($proto['short_name']) ?></h5>
                        <p><?= htmlspecialchars($proto['title']) ?></p>
                        <?php if ($proto['responsavel_nome']): ?>
                            <span class="badge bg-primary">👤 <?= htmlspecialchars($proto['responsavel_nome']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Conteúdo Principal -->
    <div class="prototypes-content">
        <?php if (!$selectedPrototype): ?>
            <div class="empty-state">
                <h3>Selecione um protótipo</h3>
                <p>Escolha um protótipo da lista para ver os detalhes</p>
            </div>
        <?php else: ?>
            <!-- Informações Básicas -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-info-circle"></i> Informações Básicas</h5>
                    <div>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editPrototypeModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="if(confirm('Tem certeza?')) { document.getElementById('deleteForm').submit(); }">
                            <i class="bi bi-trash"></i> Eliminar
                        </button>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Nome Curto</div>
                        <div class="info-value"><?= htmlspecialchars($selectedPrototype['short_name']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Título</div>
                        <div class="info-value"><?= htmlspecialchars($selectedPrototype['title']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Responsável</div>
                        <div class="info-value">
                            <?= $selectedPrototype['responsavel_nome'] ? '👤 ' . htmlspecialchars($selectedPrototype['responsavel_nome']) : 'Não atribuído' ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Criado em</div>
                        <div class="info-value">
                            <?= $selectedPrototype['created_at'] ? date('d/m/Y H:i', strtotime($selectedPrototype['created_at'])) : '-' ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($selectedPrototype['vision']): ?>
                <div class="mt-3">
                    <div class="info-label">Visão</div>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['vision'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedPrototype['sentence']): ?>
                <div class="mt-3">
                    <div class="info-label">Frase de Posicionamento</div>
                    <p class="mb-0" style="font-style: italic;"><?= nl2br(htmlspecialchars($selectedPrototype['sentence'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Membros -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-people"></i> Membros da Equipa</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="bi bi-person-plus"></i> Adicionar
                    </button>
                </div>
                
                <div class="member-list">
                    <?php if (!empty($selectedPrototype['members'])): ?>
                        <?php foreach ($selectedPrototype['members'] as $member): ?>
                            <div class="member-badge">
                                <span>👤 <?= htmlspecialchars($member['username']) ?></span>
                                <?php if ($member['role'] !== 'member'): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($member['role']) ?></span>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este membro?')">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nenhum membro adicionado</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Stories -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-book"></i> User Stories</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newStoryModal">
                        <i class="bi bi-plus-lg"></i> Nova Story
                    </button>
                </div>
                
                <div class="story-list">
                    <?php if (!empty($selectedPrototype['stories'])): ?>
                        <?php foreach ($selectedPrototype['stories'] as $story): ?>
                            <div class="story-item <?= strtolower($story['moscow_priority']) ?> <?= $story['status'] === 'closed' ? 'closed' : '' ?>">
                                <div class="story-header">
                                    <span class="story-priority priority-<?= strtolower($story['moscow_priority']) ?>">
                                        <?= htmlspecialchars($story['moscow_priority']) ?> Have
                                    </span>
                                    <span class="badge <?= $story['status'] === 'closed' ? 'bg-secondary' : 'bg-info' ?>">
                                        <?= $story['status'] === 'closed' ? 'Fechada' : 'Aberta' ?>
                                    </span>
                                </div>
                                <div class="story-text">
                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                </div>
                                
                                <!-- Barra de Progresso -->
                                <div class="story-progress">
                                    <div class="progress-container">
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill" style="width: <?= $story['completion_percentage'] ?? 0 ?>%"></div>
                                        </div>
                                        <span class="progress-percentage"><?= $story['completion_percentage'] ?? 0 ?>%</span>
                                        <button class="btn btn-sm btn-outline-secondary progress-edit-btn" 
                                                onclick="editPercentage(<?= $story['id'] ?>, <?= $story['completion_percentage'] ?? 0 ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Sprints Associadas -->
                                <?php if ($checkSprints && !empty($story['sprints'])): ?>
                                <div class="story-sprints">
                                    <small class="text-muted">Sprints:</small>
                                    <?php foreach ($story['sprints'] as $sprint): ?>
                                        <span class="sprint-badge <?= $sprint['estado'] ?>">
                                            <a href="https://criis-projects.inesctec.pt/PK/index.php?tab=sprints&sprint_id=<?= $sprint['sprint_id'] ?>" target="_blank">
                                                🏃 <?= htmlspecialchars($sprint['nome']) ?>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover desta sprint?')">
                                                <input type="hidden" name="action" value="remove_story_from_sprint">
                                                <input type="hidden" name="association_id" value="<?= $sprint['association_id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="line-height: 1;">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Tasks Associadas -->
                                <?php if ($checkTodos && !empty($story['tasks'])): ?>
                                <div class="story-tasks">
                                    <small class="text-muted mb-2 d-block">Tasks Associadas:</small>
                                    <?php foreach ($story['tasks'] as $task): ?>
                                        <div class="task-badge <?= $task['estado'] ?>">
                                            <div class="task-info" onclick="openTaskEditor(<?= $task['id'] ?>)" style="cursor: pointer; flex: 1;">
                                                <div class="task-title">
                                                    <?= htmlspecialchars($task['titulo']) ?>
                                                </div>
                                                <div class="task-meta">
                                                    <span class="badge badge-sm bg-secondary"><?= htmlspecialchars($task['estado']) ?></span>
                                                    <?php if ($task['responsavel_nome']): ?>
                                                        👤 <?= htmlspecialchars($task['responsavel_nome']) ?>
                                                    <?php endif; ?>
                                                    <?php if ($task['data_limite']): ?>
                                                        📅 <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-primary" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar Task">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover esta task?')">
                                                <input type="hidden" name="action" value="remove_task_from_story">
                                                <input type="hidden" name="association_id" value="<?= $task['association_id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Remover da Story">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Ações -->
                                <div class="story-actions">
                                    <button class="btn btn-sm btn-primary" onclick="editStory(<?= $story['id'] ?>, '<?= htmlspecialchars(addslashes($story['story_text'])) ?>', '<?= $story['moscow_priority'] ?>', '<?= $story['status'] ?>')">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <?php if ($story['status'] === 'open'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_story_status">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-circle"></i> Fechar Story
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_story_status">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reabrir Story
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($checkSprints): ?>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#addStoryToSprintModal<?= $story['id'] ?>">
                                        <i class="bi bi-link-45deg"></i> Associar Sprint
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($checkTodos): ?>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#addTaskToStoryModal<?= $story['id'] ?>">
                                        <i class="bi bi-link-45deg"></i> Associar Task
                                    </button>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createTaskModal<?= $story['id'] ?>">
                                        <i class="bi bi-plus-circle"></i> Criar Task
                                    </button>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar esta story?')">
                                        <input type="hidden" name="action" value="delete_story">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i> Eliminar
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Modal para associar à sprint (um por story) -->
                            <?php if ($checkSprints): ?>
                            <div class="modal fade" id="addStoryToSprintModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Associar Story à Sprint</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="add_story_to_sprint">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Filtrar Sprints</label>
                                                    <input type="text" class="form-control" id="filterSprintInput<?= $story['id'] ?>" 
                                                           placeholder="Digite para filtrar..." 
                                                           onkeyup="filterSprintOptions(<?= $story['id'] ?>)">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Sprint</label>
                                                    <select name="sprint_id" id="sprintSelect<?= $story['id'] ?>" class="form-select" required size="8">
                                                        <option value="">Selecione...</option>
                                                        <?php foreach ($sprints as $sprint): ?>
                                                            <option value="<?= $sprint['id'] ?>">
                                                                <?= htmlspecialchars($sprint['nome']) ?> 
                                                                (<?= htmlspecialchars($sprint['estado']) ?>)
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
                            
                            <!-- Modal para associar task existente (um por story) -->
                            <?php if ($checkTodos): ?>
                            <div class="modal fade" id="addTaskToStoryModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Associar Task à User Story</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="add_task_to_story">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Filtrar Tasks</label>
                                                    <input type="text" class="form-control" id="filterTaskInput<?= $story['id'] ?>" 
                                                           placeholder="Digite para filtrar..." 
                                                           onkeyup="filterTaskOptions(<?= $story['id'] ?>)">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Task</label>
                                                    <select name="todo_id" id="taskSelect<?= $story['id'] ?>" class="form-select" required size="12">
                                                        <option value="">Selecione uma task...</option>
                                                        <?php 
                                                        // Obter IDs de tasks já associadas a esta story
                                                        $associatedTaskIds = array_column($story['tasks'] ?? [], 'id');
                                                        
                                                        foreach ($availableTasks as $task): 
                                                            // Não mostrar tasks já associadas
                                                            if (in_array($task['id'], $associatedTaskIds)) continue;
                                                        ?>
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
                                                        <?= count($availableTasks) - count($associatedTaskIds) ?> tasks disponíveis
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
                            
                            <!-- Modal para criar task (um por story) -->
                            <?php if ($checkTodos): ?>
                            <div class="modal fade" id="createTaskModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Criar Task da User Story</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="create_task_from_story">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="alert alert-info">
                                                    <strong>User Story:</strong><br>
                                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Título da Task *</label>
                                                    <input type="text" name="titulo" class="form-control" required 
                                                           placeholder="Ex: Implementar funcionalidade X">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Descrição</label>
                                                    <textarea name="descritivo" class="form-control" rows="4" 
                                                              placeholder="Detalhes da implementação..."></textarea>
                                                    <small class="text-muted">Suporta Markdown</small>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Responsável</label>
                                                        <select name="responsavel" class="form-select">
                                                            <option value="">Não atribuído</option>
                                                            <?php foreach ($users as $user): ?>
                                                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Data Limite</label>
                                                        <input type="date" name="data_limite" class="form-control">
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($projects)): ?>
                                                <div class="mb-3">
                                                    <label class="form-label">Projeto</label>
                                                    <select name="projeto_id" class="form-select">
                                                        <option value="">Nenhum</option>
                                                        <?php foreach ($projects as $project): ?>
                                                            <option value="<?= $project['id'] ?>">
                                                                <?= htmlspecialchars($project['short_name']) ?> - <?= htmlspecialchars($project['title']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($checkSprints && !empty($story['sprints'])): ?>
                                                <div class="mb-3">
                                                    <label class="form-label">Associar à Sprint</label>
                                                    <select name="sprint_id" class="form-select">
                                                        <option value="">Não associar</option>
                                                        <?php foreach ($story['sprints'] as $sprint): ?>
                                                            <option value="<?= $sprint['sprint_id'] ?>">
                                                                <?= htmlspecialchars($sprint['nome']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="text-muted">Sprints associadas a esta user story</small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-success">Criar Task</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nenhuma user story criada</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Links e Recursos -->
            <?php if ($selectedPrototype['repo_links'] || $selectedPrototype['documentation_links']): ?>
            <div class="detail-section">
                <h5><i class="bi bi-link-45deg"></i> Links e Recursos</h5>
                
                <?php if ($selectedPrototype['repo_links']): ?>
                <div class="mb-3">
                    <div class="info-label">Repositórios</div>
                    <?php 
                    $repoLinks = json_decode($selectedPrototype['repo_links'], true);
                    if (is_array($repoLinks)):
                        foreach ($repoLinks as $link):
                    ?>
                        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="d-block mb-1">
                            🔗 <?= htmlspecialchars($link) ?>
                        </a>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedPrototype['documentation_links']): ?>
                <div>
                    <div class="info-label">Documentação</div>
                    <?php 
                    $docLinks = json_decode($selectedPrototype['documentation_links'], true);
                    if (is_array($docLinks)):
                        foreach ($docLinks as $link):
                    ?>
                        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="d-block mb-1">
                            📄 <?= htmlspecialchars($link) ?>
                        </a>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Form oculto para eliminar -->
            <form id="deleteForm" method="POST" style="display: none;">
                <input type="hidden" name="action" value="delete_prototype">
                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Novo Protótipo -->
<div class="modal fade" id="newPrototypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_prototype">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome Curto *</label>
                            <input type="text" name="short_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Não atribuído</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visão</label>
                        <textarea name="vision" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Grupo Alvo</label>
                        <textarea name="target_group" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Necessidades</label>
                        <textarea name="needs" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição do Produto</label>
                        <textarea name="product_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Objetivos de Negócio</label>
                        <textarea name="business_goals" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Frase de Posicionamento</label>
                        <textarea name="sentence" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Protótipo -->
<?php if ($selectedPrototype): ?>
<div class="modal fade" id="editPrototypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_prototype">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome Curto *</label>
                            <input type="text" name="short_name" class="form-control" 
                                   value="<?= htmlspecialchars($selectedPrototype['short_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Não atribuído</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" 
                                            <?= $user['user_id'] == $selectedPrototype['responsavel_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" 
                               value="<?= htmlspecialchars($selectedPrototype['title']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visão</label>
                        <textarea name="vision" class="form-control" rows="3"><?= htmlspecialchars($selectedPrototype['vision']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Grupo Alvo</label>
                        <textarea name="target_group" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['target_group']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Necessidades</label>
                        <textarea name="needs" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['needs']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição do Produto</label>
                        <textarea name="product_description" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['product_description']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Objetivos de Negócio</label>
                        <textarea name="business_goals" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['business_goals']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Frase de Posicionamento</label>
                        <textarea name="sentence" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['sentence']) ?></textarea>
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
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
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
                            <option value="developer">Developer</option>
                            <option value="designer">Designer</option>
                            <option value="tester">Tester</option>
                            <option value="lead">Lead</option>
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

<!-- Modal: Nova User Story -->
<div class="modal fade" id="newStoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova User Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_story">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Story Text *</label>
                        <textarea name="story_text" class="form-control" rows="4" required 
                                  placeholder="Como [tipo de usuário], eu quero [objetivo], para [benefício]"></textarea>
                        <small class="text-muted">Formato: Como [usuário], eu quero [ação], para [benefício]</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">MoSCoW Priority</label>
                        <select name="moscow_priority" class="form-select">
                            <option value="Must">Must Have</option>
                            <option value="Should" selected>Should Have</option>
                            <option value="Could">Could Have</option>
                            <option value="Won't">Won't Have</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar User Story -->
<div class="modal fade" id="editStoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar User Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_story">
                    <input type="hidden" name="story_id" id="edit_story_id">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Story Text *</label>
                        <textarea name="story_text" id="edit_story_text" class="form-control" rows="4" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">MoSCoW Priority</label>
                        <select name="moscow_priority" id="edit_story_priority" class="form-select">
                            <option value="Must">Must Have</option>
                            <option value="Should">Should Have</option>
                            <option value="Could">Could Have</option>
                            <option value="Won't">Won't Have</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_story_status" class="form-select">
                            <option value="open">Aberta</option>
                            <option value="closed">Fechada</option>
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

<!-- Modal: Editar Percentagem -->
<div class="modal fade" id="editPercentageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Percentagem</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_story_percentage">
                    <input type="hidden" name="story_id" id="edit_percentage_story_id">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Percentagem de Conclusão *</label>
                        <input type="number" name="percentage" id="edit_percentage_value" 
                               class="form-control" min="0" max="100" step="5" required>
                        <small class="text-muted">0 a 100%</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Atualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateFilters() {
    const filterMine = document.getElementById('filterMine').checked;
    const filterParticipate = document.getElementById('filterParticipate').checked;
    const showClosedStories = document.getElementById('showClosedStories').checked;
    const prototypeId = <?= $selectedPrototypeId ?? 'null' ?>;
    
    let url = '?tab=prototypes/prototypesv2';
    if (prototypeId) url += '&prototype_id=' + prototypeId;
    if (filterMine) url += '&filter_mine=true';
    if (filterParticipate) url += '&filter_participate=true';
    if (showClosedStories) url += '&show_closed=true';
    
    window.location.href = url;
}

function filterPrototypes() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const items = document.querySelectorAll('.prototype-item');
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}

function editStory(id, text, priority, status) {
    document.getElementById('edit_story_id').value = id;
    document.getElementById('edit_story_text').value = text;
    document.getElementById('edit_story_priority').value = priority;
    document.getElementById('edit_story_status').value = status;
    
    const modal = new bootstrap.Modal(document.getElementById('editStoryModal'));
    modal.show();
}

function editPercentage(storyId, currentPercentage) {
    document.getElementById('edit_percentage_story_id').value = storyId;
    document.getElementById('edit_percentage_value').value = currentPercentage;
    
    const modal = new bootstrap.Modal(document.getElementById('editPercentageModal'));
    modal.show();
}

function filterSprintOptions(storyId) {
    const input = document.getElementById('filterSprintInput' + storyId);
    const select = document.getElementById('sprintSelect' + storyId);
    const filter = input.value.toLowerCase();
    const options = select.getElementsByTagName('option');
    
    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toLowerCase().indexOf(filter) > -1 || options[i].value === '') {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}

function filterTaskOptions(storyId) {
    const input = document.getElementById('filterTaskInput' + storyId);
    const select = document.getElementById('taskSelect' + storyId);
    const filter = input.value.toLowerCase();
    const options = select.getElementsByTagName('option');
    
    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toLowerCase().indexOf(filter) > -1 || options[i].value === '') {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}
</script>

<?php
// Incluir editor universal de tasks
if ($checkTodos && file_exists(__DIR__ . '/../../edit_task.php')) {
    include __DIR__ . '/../../edit_task.php';
}
?>