<?php
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch($action) {
        // ===== PROTOTYPES =====
        case 'get_prototypes':
            $search = $_GET['search'] ?? '';
            $sql = "SELECT * FROM prototypes";
            if ($search) {
                $sql .= " WHERE short_name LIKE ? OR title LIKE ?";
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
                                       repo_links, documentation_links)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['short_name'], $data['title'], $data['vision'],
                $data['target_group'], $data['needs'], $data['product_description'],
                $data['business_goals'], $data['sentence'], $data['repo_links'],
                $data['documentation_links']
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
            
            $sql = "SELECT * FROM user_stories WHERE prototype_id = ?";
            $params = [$prototypeId];
            
            if ($priority) {
                $sql .= " AND moscow_priority = ?";
                $params[] = $priority;
            }
            
            $sql .= " ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won''t')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'create_story':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                INSERT INTO user_stories (prototype_id, story_text, moscow_priority)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $data['prototype_id'], 
                $data['story_text'], 
                $data['moscow_priority']
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'update_story':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("
                UPDATE user_stories 
                SET story_text=?, moscow_priority=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['story_text'],
                $data['moscow_priority'],
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_story':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id = ?");
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
                
                // Debug: log dos dados recebidos
                error_log("Create task from story - Data received: " . print_r($data, true));
                
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
                
                // Criar a tarefa na tabela todos
                $stmt = $pdo->prepare("
                    INSERT INTO todos (titulo, descritivo, estado, autor, created_at)
                    VALUES (?, ?, 'aberta', 1, NOW())
                ");
                
                $stmt->execute([
                    $data['title'],
                    $data['description'] ?? ''
                ]);
                
                $taskId = $pdo->lastInsertId();
                error_log("Task created with ID: $taskId");
                
                // Associar à user story
                $stmt = $pdo->prepare("
                    INSERT INTO user_story_tasks (story_id, task_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$data['story_id'], $taskId]);
                
                error_log("Task linked to story: {$data['story_id']}");
                
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
            $stmt = $pdo->prepare("SELECT * FROM user_stories WHERE prototype_id = ? ORDER BY moscow_priority");
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
            foreach ($stories as $story) {
                $md .= "### [{$story['moscow_priority']}] Story #{$story['id']}\n\n";
                $md .= "{$story['story_text']}\n\n";
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