<?php
// api/todos.php - API REST para gerenciar ToDos

// Definições de cabeçalhos para API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Se for uma requisição OPTIONS (preflight), responder imediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função para retornar erros em formato JSON
function json_error($code, $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Função para obter token de autenticação
function get_bearer_token() {
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

// Obter o método da requisição
$method = $_SERVER['REQUEST_METHOD'];

// Obter o token do cabeçalho
$token = get_bearer_token();

// Verificar se o token foi fornecido
if (!$token) {
    json_error(401, 'Token de autenticação não fornecido');
}

// Configuração do banco de dados SQLite
$db_file = '../db/todos.db';

// Verificar se o banco de dados existe
if (!file_exists($db_file)) {
    json_error(500, 'Banco de dados não encontrado');
}

// Conectar ao banco de dados
try {
    $db = new SQLite3($db_file);
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Verificar se o token é válido
    $stmt = $db->prepare('SELECT user_id, username FROM user_tokens WHERE token = :token');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        json_error(401, 'Token inválido');
    }
    
    $user_id = $user['user_id'];
    $username = $user['username'];
    
} catch (Exception $e) {
    json_error(500, 'Erro ao conectar ao banco de dados: ' . $e->getMessage());
}

// Obter o corpo da requisição para POST e PUT
$input = json_decode(file_get_contents('php://input'), true);

// Processar a requisição de acordo com o método
switch ($method) {
    case 'GET':
        // Listar todos os todos do usuário
        try {
            // Ver se há parâmetro todo_id para retornar um todo específico
            if (isset($_GET['id'])) {
                $todo_id = (int)$_GET['id'];
                
                $stmt = $db->prepare('
                    SELECT t.*, 
                           autor_user.username as autor_nome,
                           resp_user.username as responsavel_nome
                    FROM todos t
                    LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
                    LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
                    WHERE t.id = :todo_id AND (t.autor = :user_id OR t.responsavel = :user_id)
                ');
                $stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                $result = $stmt->execute();
                
                $todo = $result->fetchArray(SQLITE3_ASSOC);
                
                if (!$todo) {
                    json_error(404, 'Tarefa não encontrada ou sem permissão para acessá-la');
                }
                
                echo json_encode($todo);
                
            } else {
                // Parâmetros de filtro opcionais
                $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
                $responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : null;
                
                // Construir a consulta básica
                $query = '
                    SELECT t.*, 
                           autor_user.username as autor_nome,
                           resp_user.username as responsavel_nome
                    FROM todos t
                    LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
                    LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
                    WHERE (t.autor = :user_id OR t.responsavel = :user_id)
                ';
                
                // Adicionar filtros se fornecidos
                $params = [':user_id' => $user_id];
                
                if ($estado) {
                    $query .= ' AND t.estado = :estado';
                    $params[':estado'] = $estado;
                }
                
                if ($responsavel) {
                    $query .= ' AND t.responsavel = :responsavel';
                    $params[':responsavel'] = $responsavel;
                }
                
                // Adicionar ordenação
                $query .= ' ORDER BY 
                    CASE 
                        WHEN t.estado = "em execução" THEN 1
                        WHEN t.estado = "aberta" THEN 2
                        WHEN t.estado = "suspensa" THEN 3
                        WHEN t.estado = "completada" THEN 4
                        ELSE 5
                    END,
                    CASE 
                        WHEN t.data_limite IS NULL THEN 1
                        ELSE 0
                    END,
                    t.data_limite ASC,
                    t.created_at DESC
                ';
                
                $stmt = $db->prepare($query);
                
                // Vincular parâmetros
                foreach ($params as $param => $value) {
                    $stmt->bindValue($param, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
                }
                
                $result = $stmt->execute();
                
                $todos = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $todos[] = $row;
                }
                
                echo json_encode(['todos' => $todos]);
            }
        } catch (Exception $e) {
            json_error(500, 'Erro ao buscar tarefas: ' . $e->getMessage());
        }
        break;
        
    case 'POST':
        // Adicionar um novo todo
        try {
            // Verificar se os dados necessários foram fornecidos
            if (!isset($input['titulo']) || trim($input['titulo']) === '') {
                json_error(400, 'O título da tarefa é obrigatório');
            }
            
            // Preparar os dados
            $titulo = trim($input['titulo']);
            $descritivo = trim($input['descritivo'] ?? '');
            $data_limite = trim($input['data_limite'] ?? '');
            $responsavel = isset($input['responsavel']) ? (int)$input['responsavel'] : $user_id;
            $task_id = isset($input['task_id']) ? (int)$input['task_id'] : null;
            $todo_issue = trim($input['todo_issue'] ?? '');
            $milestone_id = isset($input['milestone_id']) ? (int)$input['milestone_id'] : null;
            $projeto_id = isset($input['projeto_id']) ? (int)$input['projeto_id'] : null;
            $estado = trim($input['estado'] ?? 'aberta');
            
            // Validar o estado
            $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
            if (!in_array($estado, $valid_estados)) {
                json_error(400, 'Estado inválido. Use: ' . implode(', ', $valid_estados));
            }
            
            // Verificar se o responsável existe
            if ($responsavel !== $user_id) {
                $check_stmt = $db->prepare('SELECT user_id FROM user_tokens WHERE user_id = :user_id');
                $check_stmt->bindValue(':user_id', $responsavel, SQLITE3_INTEGER);
                $check_result = $check_stmt->execute();
                
                if (!$check_result->fetchArray()) {
                    json_error(400, 'Responsável inválido');
                }
            }
            
            // Inserir o todo
            $stmt = $db->prepare('INSERT INTO todos (
                titulo, descritivo, data_limite, autor, responsavel, 
                task_id, todo_issue, milestone_id, projeto_id, estado
            ) VALUES (
                :titulo, :descritivo, :data_limite, :autor, :responsavel,
                :task_id, :todo_issue, :milestone_id, :projeto_id, :estado
            )');
            
            $stmt->bindValue(':titulo', $titulo, SQLITE3_TEXT);
            $stmt->bindValue(':descritivo', $descritivo, SQLITE3_TEXT);
            $stmt->bindValue(':data_limite', $data_limite, SQLITE3_TEXT);
            $stmt->bindValue(':autor', $user_id, SQLITE3_INTEGER);
            $stmt->bindValue(':responsavel', $responsavel, SQLITE3_INTEGER);
            $stmt->bindValue(':task_id', $task_id, $task_id ? SQLITE3_INTEGER : SQLITE3_NULL);
            $stmt->bindValue(':todo_issue', $todo_issue, SQLITE3_TEXT);
            $stmt->bindValue(':milestone_id', $milestone_id, $milestone_id ? SQLITE3_INTEGER : SQLITE3_NULL);
            $stmt->bindValue(':projeto_id', $projeto_id, $projeto_id ? SQLITE3_INTEGER : SQLITE3_NULL);
            $stmt->bindValue(':estado', $estado, SQLITE3_TEXT);
            
            $stmt->execute();
            $todo_id = $db->lastInsertRowID();
            
            // Buscar o todo recém-criado para retornar
            $get_stmt = $db->prepare('
                SELECT t.*, 
                       autor_user.username as autor_nome,
                       resp_user.username as responsavel_nome
                FROM todos t
                LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
                LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
                WHERE t.id = :todo_id
            ');
            $get_stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
            $result = $get_stmt->execute();
            
            $todo = $result->fetchArray(SQLITE3_ASSOC);
            
            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'Tarefa criada com sucesso', 'todo' => $todo]);
            
        } catch (Exception $e) {
            json_error(500, 'Erro ao criar tarefa: ' . $e->getMessage());
        }
        break;
        
    case 'PUT':
        // Atualizar um todo existente
        try {
            // Verificar se o ID foi fornecido
            if (!isset($input['id'])) {
                json_error(400, 'O ID da tarefa é obrigatório');
            }
            
            $todo_id = (int)$input['id'];
            
            // Verificar se a tarefa existe e se o usuário tem permissão para atualizá-la
            $check_stmt = $db->prepare('SELECT * FROM todos WHERE id = :todo_id AND (autor = :user_id OR responsavel = :user_id)');
            $check_stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
            $check_stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            $check_result = $check_stmt->execute();
            
            $todo = $check_result->fetchArray(SQLITE3_ASSOC);
            
            if (!$todo) {
                json_error(404, 'Tarefa não encontrada ou sem permissão para atualizá-la');
            }
            
            // Se apenas o estado estiver sendo atualizado
            if (isset($input['estado']) && count($input) === 2) { // id e estado
                $estado = trim($input['estado']);
                
                // Validar o estado
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                if (!in_array($estado, $valid_estados)) {
                    json_error(400, 'Estado inválido. Use: ' . implode(', ', $valid_estados));
                }
                
                // Atualizar apenas o estado
                $stmt = $db->prepare('UPDATE todos SET estado = :estado, updated_at = CURRENT_TIMESTAMP WHERE id = :todo_id');
                $stmt->bindValue(':estado', $estado, SQLITE3_TEXT);
                $stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
                $stmt->execute();
                
                // Buscar a tarefa atualizada
                $get_stmt = $db->prepare('
                    SELECT t.*, 
                           autor_user.username as autor_nome,
                           resp_user.username as responsavel_nome
                    FROM todos t
                    LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
                    LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
                    WHERE t.id = :todo_id
                ');
                $get_stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
                $result = $get_stmt->execute();
                
                $updated_todo = $result->fetchArray(SQLITE3_ASSOC);
                
                echo json_encode(['success' => true, 'message' => 'Estado da tarefa atualizado com sucesso', 'todo' => $updated_todo]);
                
            } else {
                // Atualização completa
                // Permitir apenas que o autor altere certos campos
                $is_author = ($todo['autor'] == $user_id);
                
                // Campos que podem ser atualizados
                $titulo = isset($input['titulo']) ? trim($input['titulo']) : $todo['titulo'];
                $descritivo = isset($input['descritivo']) ? trim($input['descritivo']) : $todo['descritivo'];
                $data_limite = isset($input['data_limite']) ? trim($input['data_limite']) : $todo['data_limite'];
                $estado = isset($input['estado']) ? trim($input['estado']) : $todo['estado'];
                
                // Campos que só o autor pode atualizar
                $responsavel = $is_author && isset($input['responsavel']) ? (int)$input['responsavel'] : $todo['responsavel'];
                $task_id = $is_author && isset($input['task_id']) ? (int)$input['task_id'] : $todo['task_id'];
                $todo_issue = $is_author && isset($input['todo_issue']) ? trim($input['todo_issue']) : $todo['todo_issue'];
                $milestone_id = $is_author && isset($input['milestone_id']) ? (int)$input['milestone_id'] : $todo['milestone_id'];
                $projeto_id = $is_author && isset($input['projeto_id']) ? (int)$input['projeto_id'] : $todo['projeto_id'];
                
                // Validações
                if (empty($titulo)) {
                    json_error(400, 'O título da tarefa não pode ser vazio');
                }
                
                // Validar o estado
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                if (!in_array($estado, $valid_estados)) {
                    json_error(400, 'Estado inválido. Use: ' . implode(', ', $valid_estados));
                }
                
                // Atualizar a tarefa
                $stmt = $db->prepare('UPDATE todos SET 
                    titulo = :titulo,
                    descritivo = :descritivo,
                    data_limite = :data_limite,
                    responsavel = :responsavel,
                    task_id = :task_id,
                    todo_issue = :todo_issue,
                    milestone_id = :milestone_id,
                    projeto_id = :projeto_id,
                    estado = :estado,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :todo_id
                ');
                
                $stmt->bindValue(':titulo', $titulo, SQLITE3_TEXT);
                $stmt->bindValue(':descritivo', $descritivo, SQLITE3_TEXT);
                $stmt->bindValue(':data_limite', $data_limite, SQLITE3_TEXT);
                $stmt->bindValue(':responsavel', $responsavel, SQLITE3_INTEGER);
                $stmt->bindValue(':task_id', $task_id, $task_id ? SQLITE3_INTEGER : SQLITE3_NULL);
                $stmt->bindValue(':todo_issue', $todo_issue, SQLITE3_TEXT);
                $stmt->bindValue(':milestone_id', $milestone_id, $milestone_id ? SQLITE3_INTEGER : SQLITE3_NULL);
                $stmt->bindValue(':projeto_id', $projeto_id, $projeto_id ? SQLITE3_INTEGER : SQLITE3_NULL);
                $stmt->bindValue(':estado', $estado, SQLITE3_TEXT);
                $stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
                
                $stmt->execute();
                
                // Buscar a tarefa atualizada
                $get_stmt = $db->prepare('
                    SELECT t.*, 
                           autor_user.username as autor_nome,
                           resp_user.username as responsavel_nome
                    FROM todos t
                    LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
                    LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
                    WHERE t.id = :todo_id
                ');
                $get_stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
                $result = $get_stmt->execute();
                
                $updated_todo = $result->fetchArray(SQLITE3_ASSOC);
                
                echo json_encode(['success' => true, 'message' => 'Tarefa atualizada com sucesso', 'todo' => $updated_todo]);
            }
            
        } catch (Exception $e) {
            json_error(500, 'Erro ao atualizar tarefa: ' . $e->getMessage());
        }
        break;
        
    case 'DELETE':
        // Excluir um todo
        try {
            // Verificar se o ID foi fornecido
            if (!isset($_GET['id'])) {
                json_error(400, 'O ID da tarefa é obrigatório');
            }
            
            $todo_id = (int)$_GET['id'];
            
            // Verificar se a tarefa existe e se o usuário é o autor (apenas autores podem excluir)
            $check_stmt = $db->prepare('SELECT * FROM todos WHERE id = :todo_id AND autor = :user_id');
            $check_stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
            $check_stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            $check_result = $check_stmt->execute();
            
            if (!$check_result->fetchArray()) {
                json_error(404, 'Tarefa não encontrada ou sem permissão para excluí-la');
            }
            
            // Excluir a tarefa
            $stmt = $db->prepare('DELETE FROM todos WHERE id = :todo_id AND autor = :user_id');
            $stmt->bindValue(':todo_id', $todo_id, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Tarefa excluída com sucesso']);
            
        } catch (Exception $e) {
            json_error(500, 'Erro ao excluir tarefa: ' . $e->getMessage());
        }
        break;
        
    default:
        // Método não suportado
        json_error(405, 'Método não permitido');
}