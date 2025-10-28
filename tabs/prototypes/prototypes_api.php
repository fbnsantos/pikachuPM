<?php
session_start();
// prototypes_api.php
header('Content-Type: application/json');

// Incluir configuração do projeto
include_once __DIR__ . '/../../config.php';

// Criar conexão PDO usando as variáveis do config.php
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Verificar e criar campos adicionais nas tabelas se necessário
try {
    // Adicionar campo 'status' à tabela user_stories
    $pdo->exec("ALTER TABLE user_stories ADD COLUMN IF NOT EXISTS status ENUM('open', 'closed') DEFAULT 'open'");
    
    // Adicionar campo 'completion_percentage' à tabela user_stories
    $pdo->exec("ALTER TABLE user_stories ADD COLUMN IF NOT EXISTS completion_percentage INT DEFAULT 0");
    
    // Criar tabela user_story_sprints se não existir
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
} catch (PDOException $e) {
    // Campos/tabelas já existem ou erro não crítico
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
// Verificar e gerar token do usuário
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;

try {
    switch($action) {
        // ===== PROTOTYPES =====
        case 'get_prototypes':
            $search = $_GET['search'] ?? '';
            $sql = "SELECT * FROM prototypes ORDER BY short_name ASC";
            if ($search) {
                $sql = "SELECT * FROM prototypes WHERE short_name LIKE ? OR title LIKE ? ORDER BY short_name ASC";
                $stmt = $pdo->prepare($sql);
                $searchParam = "%$search%";
                $stmt->execute([$searchParam, $searchParam]);
            } else {
                $stmt = $pdo->query($sql);
            }
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_prototype':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM prototypes WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
            break;
            
        case 'create_prototype':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                INSERT INTO prototypes (short_name, title, vision, target_group, needs, 
                                       product_description, business_goals, sentence, 
                                       repo_links, documentation_links, name, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['short_name'], $data['title'], $data['vision'],
                $data['target_group'], $data['needs'], $data['product_description'],
                $data['business_goals'], $data['sentence'], $data['repo_links'],
                $data['documentation_links'], $username ?? 'user'
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'update_prototype':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                UPDATE prototypes 
                SET short_name=?, title=?, vision=?, target_group=?, needs=?,
                    product_description=?, business_goals=?, sentence=?,
                    repo_links=?, documentation_links=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['short_name'], $data['title'], $data['vision'],
                $data['target_group'], $data['needs'], $data['product_description'],
                $data['business_goals'], $data['sentence'], $data['repo_links'],
                $data['documentation_links'], $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_prototype':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM prototypes WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        // ===== USER STORIES =====
        case 'get_stories':
            $prototypeId = $_GET['prototype_id'] ?? 0;
            $priority = $_GET['priority'] ?? '';
            $status = $_GET['status'] ?? ''; // Novo filtro por status
            
            $sql = "SELECT us.*, 
                    (SELECT COUNT(*) FROM user_story_tasks ust 
                     JOIN todos t ON ust.task_id = t.id 
                     WHERE ust.story_id = us.id) as total_tasks,
                    (SELECT COUNT(*) FROM user_story_tasks ust 
                     JOIN todos t ON ust.task_id = t.id 
                     WHERE ust.story_id = us.id AND t.estado = 'concluida') as completed_tasks
                    FROM user_stories us 
                    WHERE prototype_id = ?";
            $params = [$prototypeId];
            
            if ($priority) {
                $sql .= " AND moscow_priority = ?";
                $params[] = $priority;
            }
            
            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won''t'), 
                     FIELD(status, 'open', 'closed')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calcular percentagem automaticamente baseada nas tarefas
            foreach ($stories as &$story) {
                if ($story['total_tasks'] > 0) {
                    $story['completion_percentage'] = round(($story['completed_tasks'] / $story['total_tasks']) * 100);
                } else {
                    $story['completion_percentage'] = 0;
                }
                
                // Atualizar percentagem na base de dados
                $updateStmt = $pdo->prepare("UPDATE user_stories SET completion_percentage = ? WHERE id = ?");
                $updateStmt->execute([$story['completion_percentage'], $story['id']]);
            }
            
            echo json_encode($stories);
            break;
            
        case 'create_story':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                INSERT INTO user_stories (prototype_id, story_text, moscow_priority, status, completion_percentage)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['prototype_id'], 
                $data['story_text'], 
                $data['moscow_priority'],
                $data['status'] ?? 'open',
                0 // Iniciar com 0%
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'update_story':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                UPDATE user_stories 
                SET story_text=?, moscow_priority=?, status=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['story_text'],
                $data['moscow_priority'],
                $data['status'] ?? 'open',
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;
            
        case 'toggle_story_status':
            $id = $_POST['id'] ?? 0;
            
            // Obter status atual
            $stmt = $pdo->prepare("SELECT status FROM user_stories WHERE id = ?");
            $stmt->execute([$id]);
            $story = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($story) {
                $newStatus = $story['status'] === 'open' ? 'closed' : 'open';
                $stmt = $pdo->prepare("UPDATE user_stories SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $id]);
                echo json_encode(['success' => true, 'new_status' => $newStatus]);
            } else {
                echo json_encode(['error' => 'Story not found']);
            }
            break;
            
        case 'delete_story':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        // ===== SPRINT ASSOCIATION =====
        case 'get_story_sprints':
            $storyId = $_GET['story_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT s.*, uss.id as link_id 
                FROM sprints s
                JOIN user_story_sprints uss ON s.id = uss.sprint_id
                WHERE uss.story_id = ?
                ORDER BY s.data_inicio DESC
            ");
            $stmt->execute([$storyId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_available_sprints':
            $storyId = $_GET['story_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT * FROM sprints 
                WHERE id NOT IN (
                    SELECT sprint_id FROM user_story_sprints WHERE story_id = ?
                )
                AND estado != 'fechada'
                ORDER BY data_inicio DESC
            ");
            $stmt->execute([$storyId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'link_sprint':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                INSERT INTO user_story_sprints (story_id, sprint_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$data['story_id'], $data['sprint_id']]);
            echo json_encode(['success' => true]);
            break;
            
        case 'unlink_sprint':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM user_story_sprints WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        // ===== TASKS ASSOCIATION =====
        case 'get_story_tasks':
            $storyId = $_GET['story_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT t.*, ust.id as link_id 
                FROM todos t
                JOIN user_story_tasks ust ON t.id = ust.task_id
                WHERE ust.story_id = ?
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([$storyId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_available_tasks':
            $storyId = $_GET['story_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT * FROM todos 
                WHERE id NOT IN (
                    SELECT task_id FROM user_story_tasks WHERE story_id = ?
                )
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$storyId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'link_task':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                INSERT INTO user_story_tasks (story_id, task_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$data['story_id'], $data['task_id']]);
            echo json_encode(['success' => true]);
            break;
            
        case 'unlink_task':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM user_story_tasks WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        case 'create_task_from_story':
            try {
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!isset($data['story_id']) || !isset($data['title'])) {
                    echo json_encode([
                        'error' => 'Missing required fields',
                        'received' => $data
                    ]);
                    break;
                }
                
                // Verificar se a tabela todos existe
                $tables = $pdo->query("SHOW TABLES LIKE 'todos'")->fetchAll();
                if (empty($tables)) {
                    echo json_encode([
                        'error' => 'Table "todos" does not exist',
                        'help' => 'Please create the todos table first'
                    ]);
                    break;
                }
                
                // Verificar/criar entrada na tabela user_tokens se não existir
                if ($user_id) {
                    $checkUser = $pdo->prepare("SELECT user_id FROM user_tokens WHERE user_id = ?");
                    $checkUser->execute([$user_id]);
                    
                    if (!$checkUser->fetch()) {
                        $token = bin2hex(random_bytes(16));
                        $insertUser = $pdo->prepare("
                            INSERT INTO user_tokens (user_id, username, token)
                            VALUES (?, ?, ?)
                        ");
                        $insertUser->execute([$user_id, $username, $token]);
                    }
                }
                
                // Criar a tarefa na tabela todos
                $insertTodo = $pdo->prepare("
                    INSERT INTO todos (titulo, descritivo, estado, autor, created_at)
                    VALUES (?, ?, 'aberta', ?, NOW())
                ");
                
                $insertTodo->execute([
                    $data['title'],
                    $data['description'] ?? '',
                    $user_id
                ]);
                
                $taskId = $pdo->lastInsertId();
                
                // Associar à user story
                $linkStory = $pdo->prepare("
                    INSERT INTO user_story_tasks (story_id, task_id)
                    VALUES (?, ?)
                ");
                $linkStory->execute([$data['story_id'], $taskId]);
                
                echo json_encode(['success' => true, 'task_id' => $taskId]);
                
            } catch (PDOException $e) {
                error_log("Database error in create_task_from_story: " . $e->getMessage());
                echo json_encode([
                    'error' => 'Database error',
                    'message' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);
            } catch (Exception $e) {
                error_log("Error in create_task_from_story: " . $e->getMessage());
                echo json_encode([
                    'error' => 'Server error',
                    'message' => $e->getMessage()
                ]);
            }
            break;
            
        // ===== EXPORT =====
        case 'export_markdown':
            $id = $_GET['id'] ?? 0;
            
            // Buscar protótipo
            $stmt = $pdo->prepare("SELECT * FROM prototypes WHERE id = ?");
            $stmt->execute([$id]);
            $prototype = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar user stories
            $stmt = $pdo->prepare("
                SELECT us.*, 
                (SELECT COUNT(*) FROM user_story_tasks WHERE story_id = us.id) as task_count
                FROM user_stories us 
                WHERE prototype_id = ? 
                ORDER BY FIELD(status, 'open', 'closed'), moscow_priority
            ");
            $stmt->execute([$id]);
            $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Gerar Markdown
            $md = "# {$prototype['title']}\n\n";
            $md .= "**Short Name:** {$prototype['short_name']}\n\n";
            $md .= "## Vision\n\n{$prototype['vision']}\n\n";
            $md .= "## Product Statement\n\n{$prototype['sentence']}\n\n";
            $md .= "## Target Group\n\n{$prototype['target_group']}\n\n";
            $md .= "## Needs\n\n{$prototype['needs']}\n\n";
            $md .= "## Product Description\n\n{$prototype['product_description']}\n\n";
            $md .= "## Business Goals\n\n{$prototype['business_goals']}\n\n";
            
            if ($prototype['repo_links']) {
                $md .= "## Repository Links\n\n{$prototype['repo_links']}\n\n";
            }
            
            if ($prototype['documentation_links']) {
                $md .= "## Documentation Links\n\n{$prototype['documentation_links']}\n\n";
            }
            
            $md .= "## User Stories\n\n";
            
            // Agrupar por status
            $openStories = array_filter($stories, fn($s) => $s['status'] === 'open');
            $closedStories = array_filter($stories, fn($s) => $s['status'] === 'closed');
            
            if (!empty($openStories)) {
                $md .= "### 📖 Open Stories\n\n";
                foreach ($openStories as $story) {
                    $md .= "#### [{$story['moscow_priority']}] Story #{$story['id']} - {$story['completion_percentage']}%\n\n";
                    $md .= "{$story['story_text']}\n\n";
                    $md .= "*Tasks: {$story['task_count']}*\n\n";
                }
            }
            
            if (!empty($closedStories)) {
                $md .= "### ✅ Closed Stories\n\n";
                foreach ($closedStories as $story) {
                    $md .= "#### [{$story['moscow_priority']}] Story #{$story['id']} - {$story['completion_percentage']}%\n\n";
                    $md .= "{$story['story_text']}\n\n";
                    $md .= "*Tasks: {$story['task_count']}*\n\n";
                }
            }
            
            header('Content-Type: text/markdown');
            header('Content-Disposition: attachment; filename="' . $prototype['short_name'] . '.md"');
            echo $md;
            exit;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>