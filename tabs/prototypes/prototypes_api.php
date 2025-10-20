<?php
// prototypes_api.php - VERSÃO SIMPLES
session_start();
header('Content-Type: application/json');

include_once __DIR__ . '/../../config.php';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
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
            
        case 'delete_prototype':
            $id = $_POST['id'] ?? 0;
            
            // Deletar user stories
            $stmt1 = $pdo->prepare("DELETE FROM user_stories WHERE prototype_id = ?");
            $stmt1->execute([$id]);
            
            // Deletar prototype
            $stmt2 = $pdo->prepare("DELETE FROM prototypes WHERE id = ?");
            $stmt2->execute([$id]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'get_stories':
            $prototypeId = $_GET['prototype_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM user_stories WHERE prototype_id = ? ORDER BY created_at DESC");
            $stmt->execute([$prototypeId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'create_story':
            $prototype_id = $_POST['prototype_id'] ?? 0;
            $story_text = $_POST['story_text'] ?? '';
            $priority = $_POST['priority'] ?? 'Should';
            
            $stmt = $pdo->prepare("
                INSERT INTO user_stories (prototype_id, story_text, moscow_priority, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$prototype_id, $story_text, $priority]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'delete_story':
            $id = $_POST['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>