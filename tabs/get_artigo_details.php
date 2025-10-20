<?php
// get_artigo_details.php - API para obter detalhes de um artigo
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
    
    // Obter ID do artigo
    $artigo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($artigo_id <= 0) {
        throw new Exception("ID de artigo inválido");
    }
    
    // Buscar artigo
    $stmt = $db->prepare('
        SELECT a.* 
        FROM phd_artigos a
        WHERE a.id = ?
    ');
    
    if (!$stmt) {
        throw new Exception("Erro ao preparar query: " . $db->error);
    }
    
    $stmt->bind_param('i', $artigo_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Erro ao executar query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Artigo não encontrado");
    }
    
    $artigo = $result->fetch_assoc();
    $stmt->close();
    $db->close();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'artigo' => $artigo
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>