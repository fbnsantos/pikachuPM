<?php
// ajax_handler.php - Handler centralizado para requisições AJAX
session_start();

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sessão expirada. Por favor, faça login novamente.',
        'redirect' => 'login.php'
    ]);
    exit;
}

// Incluir configuração
include_once __DIR__ . '/config.php';

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao conectar à base de dados: ' . $e->getMessage()
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Processar ação solicitada
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        
        case 'get_task_details':
            // Buscar detalhes de uma tarefa
           // if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
           //     header('Content-Type: application/json');
           //     http_response_code(400);
           //     echo json_encode(['success' => false, 'error' => 'ID inválido']);
            //    exit;
           // }
            
            $task_id = (int)$_GET['id'];
            
            $stmt = $db->prepare('
                SELECT t.*, 
                       autor.username as autor_nome, 
                       resp.username as responsavel_nome
                FROM todos t
                LEFT JOIN user_tokens autor ON t.autor = autor.user_id
                LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
                WHERE t.id = ? AND (t.autor = ? OR t.responsavel = ?)
            ');
            $stmt->bind_param('iii', $task_id, $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $task = $result->fetch_assoc();
                
                // Garantir que campos NULL são convertidos para strings vazias
                $task['descritivo'] = $task['descritivo'] ?? '';
                $task['data_limite'] = $task['data_limite'] ?? '';
                $task['todo_issue'] = $task['todo_issue'] ?? '';
                $task['task_id'] = $task['task_id'] ?? '';
                $task['milestone_id'] = $task['milestone_id'] ?? '';
                $task['projeto_id'] = $task['projeto_id'] ?? '';
                $task['responsavel'] = $task['responsavel'] ?? '';
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'task' => $task]);
            } else {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada ou sem permissão']);
            }
            
            $stmt->close();
            break;
        
        case 'update_task_status':
            // Processar atualização de status via drag and drop
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['todo_id']) || !isset($input['new_estado'])) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
                exit;
            }
            
            $todo_id = (int)$input['todo_id'];
            $new_estado = trim($input['new_estado']);
            
            $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
            
            if (!in_array($new_estado, $valid_estados)) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Estado inválido']);
                exit;
            }
            
            $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('siii', $new_estado, $todo_id, $user_id, $user_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Estado atualizado com sucesso']);
            } else {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Erro ao atualizar ou sem permissão']);
            }
            
            $stmt->close();
            break;
        
        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    
    $db->close();
    exit;
}

// Se chegou aqui, nenhuma ação válida foi especificada
header('Content-Type: application/json');
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Nenhuma ação especificada']);
exit;