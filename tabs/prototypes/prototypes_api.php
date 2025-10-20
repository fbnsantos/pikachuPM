<?php
// prototypes_api.php - API atualizada com responsável e participantes
header('Content-Type: application/json');

// Configuração de banco de dados
require_once '../../config/database.php';

// Obter ação
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_prototypes':
            getPrototypes();
            break;
        case 'get_prototype':
            getPrototype();
            break;
        case 'create_prototype':
            createPrototype();
            break;
        case 'update_prototype':
            updatePrototype();
            break;
        case 'delete_prototype':
            deletePrototype();
            break;
        case 'get_stories':
            getUserStories();
            break;
        case 'create_story':
            createUserStory();
            break;
        case 'update_story':
            updateUserStory();
            break;
        case 'delete_story':
            deleteUserStory();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

// ===== PROTOTYPES =====
function getPrototypes() {
    global $conn;
    
    $search = $_GET['search'] ?? '';
    
    $sql = "SELECT id, name, identifier, description, responsible, participants, created_at, updated_at 
            FROM prototypes";
    
    if ($search) {
        $sql .= " WHERE name LIKE ? OR identifier LIKE ? OR description LIKE ?";
        $stmt = $conn->prepare($sql);
        $searchParam = "%$search%";
        $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
    } else {
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $prototypes = [];
    while ($row = $result->fetch_assoc()) {
        // Decodificar participantes JSON
        $row['participants'] = $row['participants'] ? json_decode($row['participants'], true) : [];
        $prototypes[] = $row;
    }
    
    echo json_encode($prototypes);
}

function getPrototype() {
    global $conn;
    
    $id = $_GET['id'] ?? 0;
    
    $stmt = $conn->prepare("SELECT * FROM prototypes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Decodificar participantes JSON
        $row['participants'] = $row['participants'] ? json_decode($row['participants'], true) : [];
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Prototype not found']);
    }
}

function createPrototype() {
    global $conn;
    
    $name = $_POST['name'] ?? '';
    $identifier = $_POST['identifier'] ?? '';
    $description = $_POST['description'] ?? '';
    $responsible = $_POST['responsible'] ?? '';
    $participants = $_POST['participants'] ?? '[]';
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        return;
    }
    
    $stmt = $conn->prepare(
        "INSERT INTO prototypes (name, identifier, description, responsible, participants, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
    );
    
    $stmt->bind_param("sssss", $name, $identifier, $description, $responsible, $participants);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $conn->insert_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $stmt->error
        ]);
    }
}

function updatePrototype() {
    global $conn;
    
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $identifier = $_POST['identifier'] ?? '';
    $description = $_POST['description'] ?? '';
    $responsible = $_POST['responsible'] ?? '';
    $participants = $_POST['participants'] ?? '[]';
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        return;
    }
    
    $stmt = $conn->prepare(
        "UPDATE prototypes 
         SET name = ?, identifier = ?, description = ?, responsible = ?, participants = ?, updated_at = NOW() 
         WHERE id = ?"
    );
    
    $stmt->bind_param("sssssi", $name, $identifier, $description, $responsible, $participants, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $stmt->error
        ]);
    }
}

function deletePrototype() {
    global $conn;
    
    $id = $_POST['id'] ?? 0;
    
    // Começar transação para deletar prototype e suas stories
    $conn->begin_transaction();
    
    try {
        // Deletar user stories primeiro
        $stmt1 = $conn->prepare("DELETE FROM prototype_user_stories WHERE prototype_id = ?");
        $stmt1->bind_param("i", $id);
        $stmt1->execute();
        
        // Deletar prototype
        $stmt2 = $conn->prepare("DELETE FROM prototypes WHERE id = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// ===== USER STORIES =====
function getUserStories() {
    global $conn;
    
    $prototype_id = $_GET['prototype_id'] ?? 0;
    
    $stmt = $conn->prepare(
        "SELECT * FROM prototype_user_stories 
         WHERE prototype_id = ? 
         ORDER BY created_at DESC"
    );
    
    $stmt->bind_param("i", $prototype_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stories = [];
    while ($row = $result->fetch_assoc()) {
        $stories[] = $row;
    }
    
    echo json_encode($stories);
}

function createUserStory() {
    global $conn;
    
    $prototype_id = $_POST['prototype_id'] ?? 0;
    $story_text = $_POST['story_text'] ?? '';
    $priority = $_POST['priority'] ?? 'Should';
    
    if (empty($story_text)) {
        echo json_encode(['success' => false, 'error' => 'Story text is required']);
        return;
    }
    
    $stmt = $conn->prepare(
        "INSERT INTO prototype_user_stories (prototype_id, story_text, priority, created_at) 
         VALUES (?, ?, ?, NOW())"
    );
    
    $stmt->bind_param("iss", $prototype_id, $story_text, $priority);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $conn->insert_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $stmt->error
        ]);
    }
}

function updateUserStory() {
    global $conn;
    
    $id = $_POST['id'] ?? 0;
    $story_text = $_POST['story_text'] ?? '';
    $priority = $_POST['priority'] ?? 'Should';
    
    if (empty($story_text)) {
        echo json_encode(['success' => false, 'error' => 'Story text is required']);
        return;
    }
    
    $stmt = $conn->prepare(
        "UPDATE prototype_user_stories 
         SET story_text = ?, priority = ? 
         WHERE id = ?"
    );
    
    $stmt->bind_param("ssi", $story_text, $priority, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $stmt->error
        ]);
    }
}

function deleteUserStory() {
    global $conn;
    
    $id = $_POST['id'] ?? 0;
    
    $stmt = $conn->prepare("DELETE FROM prototype_user_stories WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $stmt->error
        ]);
    }
}
?>