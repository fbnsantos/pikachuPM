<?php
// get_task_details.php - API para obter detalhes de uma tarefa
session_start();

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

include_once __DIR__ . '/config.php';

// Conectar à base de dados
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Obter ID da tarefa
    $task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($task_id <= 0) {
        throw new Exception("ID de tarefa inválido");
    }
    
    // Buscar tarefa
    $stmt = $db->prepare('
        SELECT t.*, 
               autor.username as autor_nome, 
               resp.username as responsavel_nome
        FROM todos t
        LEFT JOIN user_tokens autor ON t.autor = autor.user_id
        LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
        WHERE t.id = ?
    ');
    $stmt->bind_param('i', $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Tarefa não encontrada");
    }
    
    $task = $result->fetch_assoc();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'task' => $task
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$db->close();
?>