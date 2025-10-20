<?php
// prototypes_api.php - Versão corrigida com nova estrutura
session_start();
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

// Verificar usuário na sessão
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'guest';

try {
    switch($action) {
        // ===== PROTOTYPES =====
        case 'get_prototypes':
            $search = $_GET['search'] ?? '';
            
            // Verificar quais colunas existem na tabela
            $columns = $pdo->query("SHOW COLUMNS FROM prototypes")->fetchAll(PDO::FETCH_COLUMN);
            
            $sql = "SELECT * FROM prototypes";
            
            if ($search) {
                // Buscar em todas as colunas possíveis
                $searchConditions = [];
                if (in_array('name', $columns)) $searchConditions[] = "name LIKE :search";
                if (in_array('identifier', $columns)) $searchConditions[] = "identifier LIKE :search";
                if (in_array('description', $columns)) $searchConditions[] = "description LIKE :search";
                if (in_array('short_name', $columns)) $searchConditions[] = "short_name LIKE :search";
                if (in_array('title', $columns)) $searchConditions[] = "title LIKE :search";
                
                if (!empty($searchConditions)) {
                    $sql .= " WHERE " . implode(" OR ", $searchConditions);
                }
                
                $stmt = $pdo->prepare($sql);
                $searchParam = "%$search%";
                $stmt->execute(['search' => $searchParam]);
            } else {
                $stmt = $pdo->query($sql);
            }
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Processar participantes JSON
            foreach ($results as &$row) {
                if (isset($row['participants']) && $row['participants']) {
                    $row['participants'] = json_decode($row['participants'], true) ?? [];
                } else {
                    $row['participants'] = [];
                }
            }
            
            echo json_encode($results);
            break;
            
        case 'get_prototype':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM prototypes WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Decodificar participantes JSON
                if (isset($result['participants']) && $result['participants']) {
                    $result['participants'] = json_decode($result['participants'], true) ?? [];
                } else {
                    $result['participants'] = [];
                }
            }
            
            echo json_encode($result);
            break;
            
        case 'create_prototype':
            // Verificar quais colunas existem
            $columns = $pdo->query("SHOW COLUMNS FROM prototypes")->fetchAll(PDO::FETCH_COLUMN);
            
            // Campos da nova estrutura
            $name = $_POST['name'] ?? '';
            $identifier = $_POST['identifier'] ?? '';
            $description = $_POST['description'] ?? '';
            $responsible = $_POST['responsible'] ?? '';
            $participants = $_POST['participants'] ?? '[]';
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name is required']);
                break;
            }
            
            // Montar SQL dinamicamente baseado nas colunas existentes
            $fields = [];
            $values = [];
            $params = [];
            
            if (in_array('name', $columns)) {
                $fields[] = 'name';
                $values[] = '?';
                $params[] = $name;
            }
            
            if (in_array('identifier', $columns)) {
                $fields[] = 'identifier';
                $values[] = '?';
                $params[] = $identifier;
            }
            
            if (in_array('description', $columns)) {
                $fields[] = 'description';
                $values[] = '?';
                $params[] = $description;
            }
            
            if (in_array('responsible', $columns)) {
                $fields[] = 'responsible';
                $values[] = '?';
                $params[] = $responsible;
            }
            
            if (in_array('participants', $columns)) {
                $fields[] = 'participants';
                $values[] = '?';
                $params[] = $participants;
            }
            
            $sql = "INSERT INTO prototypes (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'update_prototype':
            $id = $_POST['id'] ?? 0;
            
            if (empty($id)) {
                echo json_encode(['success' => false, 'error' => 'ID is required']);
                break;
            }
            
            // Verificar quais colunas existem
            $columns = $pdo->query("SHOW COLUMNS FROM prototypes")->fetchAll(PDO::FETCH_COLUMN);
            
            $name = $_POST['name'] ?? '';
            $identifier = $_POST['identifier'] ?? '';
            $description = $_POST['description'] ?? '';
            $responsible = $_POST['responsible'] ?? '';
            $participants = $_POST['participants'] ?? '[]';
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Name is required']);
                break;
            }
            
            // Montar SQL dinamicamente
            $updates = [];
            $params = [];
            
            if (in_array('name', $columns)) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            
            if (in_array('identifier', $columns)) {
                $updates[] = 'identifier = ?';
                $params[] = $identifier;
            }
            
            if (in_array('description', $columns)) {
                $updates[] = 'description = ?';
                $params[] = $description;
            }
            
            if (in_array('responsible', $columns)) {
                $updates[] = 'responsible = ?';
                $params[] = $responsible;
            }
            
            if (in_array('participants', $columns)) {
                $updates[] = 'participants = ?';
                $params[] = $participants;
            }
            
            if (in_array('updated_at', $columns)) {
                $updates[] = 'updated_at = NOW()';
            }
            
            $params[] = $id; // ID no final para o WHERE
            
            $sql = "UPDATE prototypes SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_prototype':
            $id = $_POST['id'] ?? 0;
            
            // Começar transação
            $pdo->beginTransaction();
            
            try {
                // Deletar user stories associadas
                $stmt1 = $pdo->prepare("DELETE FROM user_stories WHERE prototype_id = ?");
                $stmt1->execute([$id]);
                
                // Deletar prototype
                $stmt2 = $pdo->prepare("DELETE FROM prototypes WHERE id = ?");
                $stmt2->execute([$id]);
                
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollback();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        // ===== USER STORIES =====
        case 'get_stories':
            $prototypeId = $_GET['prototype_id'] ?? 0;
            
            // Verificar quais colunas existem
            $columns = $pdo->query("SHOW COLUMNS FROM user_stories")->fetchAll(PDO::FETCH_COLUMN);
            
            $sql = "SELECT * FROM user_stories WHERE prototype_id = ? ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$prototypeId]);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Normalizar campo de prioridade
            foreach ($results as &$row) {
                if (isset($row['moscow_priority']) && !isset($row['priority'])) {
                    $row['priority'] = $row['moscow_priority'];
                }
            }
            
            echo json_encode($results);
            break;
            
        case 'create_story':
            $prototype_id = $_POST['prototype_id'] ?? 0;
            $story_text = $_POST['story_text'] ?? '';
            $priority = $_POST['priority'] ?? 'Should';
            
            if (empty($story_text)) {
                echo json_encode(['success' => false, 'error' => 'Story text is required']);
                break;
            }
            
            // Verificar quais colunas existem
            $columns = $pdo->query("SHOW COLUMNS FROM user_stories")->fetchAll(PDO::FETCH_COLUMN);
            
            $fields = ['prototype_id', 'story_text'];
            $values = ['?', '?'];
            $params = [$prototype_id, $story_text];
            
            if (in_array('priority', $columns)) {
                $fields[] = 'priority';
                $values[] = '?';
                $params[] = $priority;
            }
            
            if (in_array('moscow_priority', $columns)) {
                $fields[] = 'moscow_priority';
                $values[] = '?';
                $params[] = $priority;
            }
            
            $sql = "INSERT INTO user_stories (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'update_story':
            $id = $_POST['id'] ?? 0;
            $story_text = $_POST['story_text'] ?? '';
            $priority = $_POST['priority'] ?? 'Should';
            
            if (empty($story_text)) {
                echo json_encode(['success' => false, 'error' => 'Story text is required']);
                break;
            }
            
            // Verificar quais colunas existem
            $columns = $pdo->query("SHOW COLUMNS FROM user_stories")->fetchAll(PDO::FETCH_COLUMN);
            
            $updates = ['story_text = ?'];
            $params = [$story_text];
            
            if (in_array('priority', $columns)) {
                $updates[] = 'priority = ?';
                $params[] = $priority;
            }
            
            if (in_array('moscow_priority', $columns)) {
                $updates[] = 'moscow_priority = ?';
                $params[] = $priority;
            }
            
            $params[] = $id;
            
            $sql = "UPDATE user_stories SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_story':
            $id = $_POST['id'] ?? 0;
            
            $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action: ' . $action]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'action' => $action
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'action' => $action
    ]);
}
?>