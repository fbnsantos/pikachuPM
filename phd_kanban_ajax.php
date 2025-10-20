<?php
// phd_kanban_ajax.php - Processar apenas pedidos AJAX do Kanban

session_start();

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Incluir configuração
include_once __DIR__ . '/config.php';

// ID do projeto de doutoramento
define('PHD_PROJECT_ID', 9999);

// Mapeamento entre estágios Kanban e estados Todo
$stage_to_estado_map = [
    'pensada' => 'aberta',
    'execucao' => 'em execução',
    'espera' => 'suspensa',
    'concluida' => 'concluída'
];

// Limpar qualquer output buffer
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

try {
    // Conectar à base de dados
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Verificar se é pedido para atualizar estágio
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage') {
        
        $task_id = intval($_POST['task_id']);
        $new_stage = $_POST['new_stage'];
        
        $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
        
        if (!in_array($new_stage, $valid_stages)) {
            echo json_encode(['success' => false, 'error' => 'Estágio inválido']);
            $db->close();
            exit;
        }
        
        $new_estado = $stage_to_estado_map[$new_stage];
        $stmt = $db->prepare('UPDATE todos SET estagio = ?, estado = ? WHERE id = ? AND projeto_id = ?');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('ssii', $new_stage, $new_estado, $task_id, $projeto_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tarefa atualizada com sucesso']);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        
        $stmt->close();
        $db->close();
        exit;
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

exit;
?>