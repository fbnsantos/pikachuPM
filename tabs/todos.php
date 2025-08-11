<?php
// tabs/todos.php - Gestão de ToDos com integração de Milestones

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// Incluir funções do milestone.php para buscar milestones
require_once __DIR__ . '/milestone.php';

// Conectar ao banco de dados MySQL
try {
    // Usar as variáveis de configuração do arquivo config.php
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Verificar conexão
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    // Definir conjunto de caracteres para UTF-8
    $db->set_charset("utf8mb4");
    
    // Criar tabela de tokens se não existir
    $db->query('CREATE TABLE IF NOT EXISTS user_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Criar tabela de tarefas se não existir (com campo para identificar milestones)
    $db->query('CREATE TABLE IF NOT EXISTS todos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(255) NOT NULL,
        descritivo TEXT,
        data_limite DATE,
        autor INT NOT NULL,
        responsavel INT,
        task_id INT,
        todo_issue TEXT,
        milestone_id INT,
        projeto_id INT,
        estado VARCHAR(20) DEFAULT "aberta",
        is_milestone TINYINT(1) DEFAULT 0,
        redmine_milestone_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (autor) REFERENCES user_tokens(user_id),
        FOREIGN KEY (responsavel) REFERENCES user_tokens(user_id)
    )');

    // Verificar se o usuário atual já tem um token
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    
    $stmt = $db->prepare('SELECT token FROM user_tokens WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_token = $result->fetch_assoc();
    $stmt->close();
    
    // Se não tiver token, gerar um novo
    if (!$user_token) {
        $token = bin2hex(random_bytes(16));
        
        $stmt = $db->prepare('INSERT INTO user_tokens (user_id, username, token) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $user_id, $username, $token);
        $stmt->execute();
        $stmt->close();
        
        $user_token = ['token' => $token];
    }

    // Função para sincronizar milestones do Redmine
    function syncMilestonesFromRedmine($db, $user_id) {
        try {
            // Buscar milestones do Redmine
            $milestones = getMilestones();
            
            if (isset($milestones['error'])) {
                error_log("Erro ao buscar milestones: " . $milestones['error']);
                return false;
            }
            
            // Mapear usuários do Redmine para usuários locais
            $redmine_users = getUsers();
            $user_mapping = [];
            
            // Criar mapeamento baseado no username (assumindo que são iguais)
            $local_users_stmt = $db->prepare('SELECT user_id, username FROM user_tokens');
            $local_users_stmt->execute();
            $local_users_result = $local_users_stmt->get_result();
            
            $local_users = [];
            while ($local_user = $local_users_result->fetch_assoc()) {
                $local_users[strtolower($local_user['username'])] = $local_user['user_id'];
            }
            $local_users_stmt->close();
            
            // Criar mapeamento de usuários do Redmine para IDs locais
            if (!isset($redmine_users['error']) && !empty($redmine_users)) {
                foreach ($redmine_users as $redmine_user) {
                    $redmine_username = '';
                    if (isset($redmine_user['login'])) {
                        $redmine_username = strtolower($redmine_user['login']);
                    } elseif (isset($redmine_user['name'])) {
                        $redmine_username = strtolower($redmine_user['name']);
                    } elseif (isset($redmine_user['firstname']) && isset($redmine_user['lastname'])) {
                        $redmine_username = strtolower($redmine_user['firstname'] . '.' . $redmine_user['lastname']);
                    }
                    
                    if (!empty($redmine_username) && isset($local_users[$redmine_username])) {
                        $user_mapping[$redmine_user['id']] = $local_users[$redmine_username];
                    }
                }
            }
            
            $synced_count = 0;
            
            foreach ($milestones as $milestone) {
                // Verificar se já existe como tarefa
                $check_stmt = $db->prepare('SELECT id FROM todos WHERE redmine_milestone_id = ? AND is_milestone = 1');
                $check_stmt->bind_param('i', $milestone['id']);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                // Determinar responsável local
                $local_responsavel = $user_id; // Default para o usuário atual
                if (isset($milestone['assigned_to']) && isset($user_mapping[$milestone['assigned_to']['id']])) {
                    $local_responsavel = $user_mapping[$milestone['assigned_to']['id']];
                }
                
                // Determinar estado baseado no status da milestone
                $estado = 'aberta';
                if (isset($milestone['status'])) {
                    switch ($milestone['status']['id']) {
                        case 5: // Fechado
                            $estado = 'completada';
                            break;
                        case 2: // Em progresso
                            $estado = 'em execução';
                            break;
                        case 3: // Resolvido/Pausa
                            $estado = 'suspensa';
                            break;
                        default:
                            $estado = 'aberta';
                    }
                }
                
                // Preparar descrição com informações da milestone
                $descricao = "Milestone do Redmine";
                if (isset($milestone['task_stats'])) {
                    $stats = $milestone['task_stats'];
                    $descricao .= "\n\nProgresso: " . $stats['completion'] . "% concluído";
                    $descricao .= "\nTarefas: " . $stats['total'] . " total";
                    $descricao .= " (" . $stats['closed']['count'] . " fechadas, ";
                    $descricao .= $stats['in_progress']['count'] . " em execução, ";
                    $descricao .= $stats['backlog']['count'] . " em backlog)";
                }
                
                if (!$existing) {
                    // Inserir nova milestone como tarefa
                    $insert_stmt = $db->prepare('
                        INSERT INTO todos (
                            titulo, descritivo, data_limite, autor, responsavel, 
                            estado, is_milestone, redmine_milestone_id
                        ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                    ');
                    
                    $insert_stmt->bind_param(
                        'sssiisi',
                        $milestone['subject'],
                        $descricao,
                        $milestone['due_date'] ?? null,
                        $user_id,
                        $local_responsavel,
                        $estado,
                        $milestone['id']
                    );
                    
                    if ($insert_stmt->execute()) {
                        $synced_count++;
                    }
                    $insert_stmt->close();
                } else {
                    // Atualizar milestone existente
                    $update_stmt = $db->prepare('
                        UPDATE todos SET 
                            titulo = ?, 
                            descritivo = ?, 
                            data_limite = ?, 
                            responsavel = ?, 
                            estado = ?
                        WHERE redmine_milestone_id = ? AND is_milestone = 1
                    ');
                    
                    $update_stmt->bind_param(
                        'sssisi',
                        $milestone['subject'],
                        $descricao,
                        $milestone['due_date'] ?? null,
                        $local_responsavel,
                        $estado,
                        $milestone['id']
                    );
                    
                    if ($update_stmt->execute()) {
                        $synced_count++;
                    }
                    $update_stmt->close();
                }
            }
            
            return $synced_count;
            
        } catch (Exception $e) {
            error_log("Erro ao sincronizar milestones: " . $e->getMessage());
            return false;
        }
    }

    // Processamento do formulário de adição/edição de tarefas
    $success_message = '';
    $error_message = '';
    
    // Obter o parâmetro "tab" para redirecionar corretamente após as ações
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            // Sincronizar milestones
            if ($_POST['action'] === 'sync_milestones') {
                $synced = syncMilestonesFromRedmine($db, $user_id);
                if ($synced !== false) {
                    $success_message = "Sincronizadas $synced milestones do Redmine!";
                } else {
                    $error_message = "Erro ao sincronizar milestones. Verifique a conexão com o Redmine.";
                }
                
                if (!empty($current_tab)) {
                    header('Location: ?tab=' . urlencode($current_tab));
                    exit;
                }
            }
            // Adicionar nova tarefa
            elseif ($_POST['action'] === 'add') {
                $titulo = trim($_POST['titulo'] ?? '');
                $descritivo = trim($_POST['descritivo'] ?? '');
                $data_limite = trim($_POST['data_limite'] ?? '');
                $responsavel = (int)($_POST['responsavel'] ?? $user_id);
                $task_id = (int)($_POST['task_id'] ?? 0);
                $todo_issue = trim($_POST['todo_issue'] ?? '');
                $milestone_id = (int)($_POST['milestone_id'] ?? 0);
                $projeto_id = (int)($_POST['projeto_id'] ?? 0);
                $estado = trim($_POST['estado'] ?? 'aberta');
                
                // Validação básica
                if (empty($titulo)) {
                    $error_message = 'O título da tarefa é obrigatório.';
                } else {
                    // Preparar a consulta SQL
                    $query = 'INSERT INTO todos (
                        titulo, descritivo, data_limite, autor, responsavel, 
                        task_id, todo_issue, milestone_id, projeto_id, estado, is_milestone
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)';
                    
                    $stmt = $db->prepare($query);
                    
                    // Tratar valores nulos adequadamente
                    $task_id_param = ($task_id > 0) ? $task_id : NULL;
                    $milestone_id_param = ($milestone_id > 0) ? $milestone_id : NULL;
                    $projeto_id_param = ($projeto_id > 0) ? $projeto_id : NULL;
                    
                    $stmt->bind_param(
                        'sssiiisiis', 
                        $titulo, 
                        $descritivo, 
                        $data_limite, 
                        $user_id, 
                        $responsavel, 
                        $task_id_param, 
                        $todo_issue, 
                        $milestone_id_param, 
                        $projeto_id_param, 
                        $estado
                    );
                    
                    if ($stmt->execute()) {
                        $success_message = 'Tarefa adicionada com sucesso!';
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao adicionar tarefa: ' . $db->error;
                    }
                    
                    $stmt->close();
                }
            }
            // Editar tarefa existente
            elseif ($_POST['action'] === 'edit_task') {
                $todo_id = (int)$_POST['todo_id'];
                $titulo = trim($_POST['titulo'] ?? '');
                $descritivo = trim($_POST['descritivo'] ?? '');
                $data_limite = trim($_POST['data_limite'] ?? '');
                $responsavel = (int)($_POST['responsavel'] ?? $user_id);
                $task_id = (int)($_POST['task_id'] ?? 0);
                $todo_issue = trim($_POST['todo_issue'] ?? '');
                $milestone_id = (int)($_POST['milestone_id'] ?? 0);
                $projeto_id = (int)($_POST['projeto_id'] ?? 0);
                $estado = trim($_POST['estado'] ?? 'aberta');
                
                // Validação básica
                if (empty($titulo)) {
                    $error_message = 'O título da tarefa é obrigatório.';
                } else {
                    // Preparar a consulta SQL para atualização (não permite editar milestones)
                    $query = 'UPDATE todos SET 
                        titulo = ?, 
                        descritivo = ?, 
                        data_limite = ?, 
                        responsavel = ?, 
                        task_id = ?, 
                        todo_issue = ?, 
                        milestone_id = ?, 
                        projeto_id = ?, 
                        estado = ?
                        WHERE id = ? AND (autor = ? OR responsavel = ?) AND is_milestone = 0';
                    
                    $stmt = $db->prepare($query);
                    
                    // Tratar valores nulos adequadamente
                    $task_id_param = ($task_id > 0) ? $task_id : NULL;
                    $milestone_id_param = ($milestone_id > 0) ? $milestone_id : NULL;
                    $projeto_id_param = ($projeto_id > 0) ? $projeto_id : NULL;
                    
                    $stmt->bind_param(
                        'sssiisiisiii', 
                        $titulo, 
                        $descritivo, 
                        $data_limite, 
                        $responsavel, 
                        $task_id_param, 
                        $todo_issue, 
                        $milestone_id_param, 
                        $projeto_id_param, 
                        $estado,
                        $todo_id,
                        $user_id,
                        $user_id
                    );
                    
                    if ($stmt->execute()) {
                        $success_message = 'Tarefa atualizada com sucesso!';
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao atualizar tarefa: ' . $db->error;
                    }
                    
                    $stmt->close();
                }
            }
            // Atualizar estado da tarefa
            elseif ($_POST['action'] === 'update_status') {
                $todo_id = (int)$_POST['todo_id'];
                $new_estado = trim($_POST['new_estado']);
                
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                
                if (in_array($new_estado, $valid_estados)) {
                    // Verificar se é milestone antes de atualizar
                    $check_stmt = $db->prepare('SELECT is_milestone, redmine_milestone_id FROM todos WHERE id = ?');
                    $check_stmt->bind_param('i', $todo_id);
                    $check_stmt->execute();
                    $task_info = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($task_info && $task_info['is_milestone'] == 1) {
                        // Para milestones, talvez queira sincronizar o estado com o Redmine
                        // Por enquanto, vamos apenas atualizar localmente
                        $error_message = 'Não é possível alterar o estado de milestones manualmente. Use a sincronização.';
                    } else {
                        $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ?');
                        $stmt->bind_param('si', $new_estado, $todo_id);
                        
                        if ($stmt->execute()) {
                            $success_message = 'Estado da tarefa atualizado com sucesso!';
                            if (!empty($current_tab)) {
                                header('Location: ?tab=' . urlencode($current_tab));
                                exit;
                            }
                        } else {
                            $error_message = 'Erro ao atualizar estado: ' . $db->error;
                        }
                        $stmt->close();
                    }
                } else {
                    $error_message = 'Estado inválido.';
                }
            }
            // Excluir tarefa
            elseif ($_POST['action'] === 'delete') {
                $todo_id = (int)$_POST['todo_id'];
                
                // Não permitir exclusão de milestones
                $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?) AND is_milestone = 0');
                $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success_message = 'Tarefa excluída com sucesso!';
                    } else {
                        $error_message = 'Não foi possível excluir a tarefa. Verifique suas permissões ou se é uma milestone.';
                    }
                    if (!empty($current_tab)) {
                        header('Location: ?tab=' . urlencode($current_tab));
                        exit;
                    }
                } else {
                    $error_message = 'Erro ao excluir tarefa: ' . $db->error;
                }
                $stmt->close();
            }
            // Atualizar estado via AJAX (para drag and drop)
            elseif ($_POST['action'] === 'drag_update_status') {
                $todo_id = (int)$_POST['todo_id'];
                $new_estado = trim($_POST['new_estado']);
                
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                
                if (in_array($new_estado, $valid_estados)) {
                    // Verificar se é milestone
                    $check_stmt = $db->prepare('SELECT is_milestone FROM todos WHERE id = ?');
                    $check_stmt->bind_param('i', $todo_id);
                    $check_stmt->execute();
                    $task_info = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($task_info && $task_info['is_milestone'] == 1) {
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => 'Não é possível mover milestones']);
                            exit;
                        }
                    } else {
                        $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ?');
                        $stmt->bind_param('si', $new_estado, $todo_id);
                        
                        if ($stmt->execute()) {
                            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                header('Content-Type: application/json');
                                echo json_encode(['success' => true, 'message' => 'Estado atualizado com sucesso']);
                                exit;
                            }
                            $success_message = 'Estado da tarefa atualizado com sucesso!';
                            if (!empty($current_tab)) {
                                header('Location: ?tab=' . urlencode($current_tab));
                                exit;
                            }
                        } else {
                            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                header('Content-Type: application/json');
                                echo json_encode(['success' => false, 'message' => 'Erro ao atualizar estado']);
                                exit;
                            }
                            $error_message = 'Erro ao atualizar estado: ' . $db->error;
                        }
                        $stmt->close();
                    }
                } else {
                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Estado inválido']);
                        exit;
                    }
                    $error_message = 'Estado inválido.';
                }
            }
        }
    }
    
    // Verificar se há parâmetro de edição
    $edit_mode = false;
    $task_to_edit = null;
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $edit_task_id = (int)$_GET['edit'];
        
        // Buscar os dados da tarefa (excluindo milestones)
        $stmt = $db->prepare('SELECT * FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?) AND is_milestone = 0');
        $stmt->bind_param('iii', $edit_task_id, $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $task_to_edit = $result->fetch_assoc();
        $stmt->close();
        
        if ($task_to_edit) {
            $edit_mode = true;
        }
    }
    
    // Verificar se estão sendo solicitados detalhes de uma tarefa via AJAX
    if (isset($_GET['get_task_details']) && is_numeric($_GET['get_task_details'])) {
        $task_id = (int)$_GET['get_task_details'];
        
        $stmt = $db->prepare('SELECT * FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $task_id, $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $task = $result->fetch_assoc();
        $stmt->close();
        
        if ($task) {
            header('Content-Type: application/json');
            echo json_encode($task);
            exit;
        } else {
            header('Content-Type: application/json');
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Tarefa não encontrada ou sem permissão de acesso']);
            exit;
        }
    }
    
    // Obter filtro de responsável (se existir)
    $filter_responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : null;
    
    // Verificar se devemos mostrar tarefas completadas
    $show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == '1';
    
    // Construir a consulta SQL com base nos filtros
    $sql = '
        SELECT t.*, 
               autor_user.username as autor_nome,
               resp_user.username as responsavel_nome
        FROM todos t
        LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
        LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
        WHERE 1=1';
    
    $params = [];
    $types = '';
    
    // Filtrar por responsável se especificado
    if ($filter_responsavel) {
        $sql .= ' AND t.responsavel = ?';
        $params[] = $filter_responsavel;
        $types .= 'i';
    } else {
        // Se não houver filtro, mostrar apenas tarefas do usuário
        $sql .= ' AND (t.autor = ? OR t.responsavel = ?)';
        $params[] = $user_id;
        $params[] = $user_id;
        $types .= 'ii';
    }
    
    // Filtrar tarefas completadas, se necessário
    if (!$show_completed) {
        $sql .= ' AND t.estado != "completada"';
    }
    
    // Ordenação (milestones primeiro, depois por estado)
    $sql .= ' ORDER BY 
            t.is_milestone DESC,
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
            t.created_at DESC';
    
    $stmt = $db->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Organizar tarefas por estado
    $tarefas_por_estado = [
        'aberta' => [],
        'em execução' => [],
        'suspensa' => [],
        'completada' => []
    ];
    
    while ($row = $result->fetch_assoc()) {
        $tarefas_por_estado[$row['estado']][] = $row;
    }
    $stmt->close();
    
    // Obter todos os usuários para o select de responsável
    $stmt = $db->prepare('SELECT user_id, username FROM user_tokens ORDER BY username');
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erro ao conectar ao banco de dados: ' . $e->getMessage() . '</div>';
    exit;
}

?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h2><i class="bi bi-check2-square"></i> Gestão de ToDos</h2>
        </div>
        <div class="col-md-6 text-end">
            <div class="d-flex justify-content-end align-items-center">
                <div class="me-3">
                    <form method="get" action="" class="d-flex align-items-center" id="filter-form">
                        <!-- Manter o parâmetro tab -->
                        <input type="hidden" name="tab" value="todos">
                        <select class="form-select form-select-sm me-2" name="responsavel" id="filter-responsavel">
                            <option value="">Minhas tarefas</option>
                            <?php foreach ($users as $u): ?>
                                <?php if ($u['user_id'] != $user_id): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $filter_responsavel == $u['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" id="show-completed" name="show_completed" value="1" <?= $show_completed ? 'checked' : '' ?>>
                            <label class="form-check-label" for="show-completed">Mostrar completadas</label>
                        </div>
                    </form>
                </div>
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-info" id="sync-milestones-btn">
                        <i class="bi bi-arrow-clockwise"></i> Sync Milestones
                    </button>
                    <button type="button" class="btn btn-primary" id="new-task-btn">
                        <i class="bi bi-plus-circle"></i> Nova Tarefa
                    </button>
                </div>
            </div>
            <p class="mb-0 mt-2 small">Seu Token API: <code><?= htmlspecialchars($user_token['token']) ?></code></p>
        </div>
    </div>
    
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <!-- Formulário de sincronização de milestones (oculto) -->
    <form method="post" action="" id="sync-milestones-form" style="display: none;">
        <input type="hidden" name="action" value="sync_milestones">
    </form>
    
    <!-- Formulário de nova tarefa (inicialmente escondido) -->
    <div class="row mb-4" id="new-task-form-container" style="display: none;">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-plus-circle"></i> Nova Tarefa</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="new-task-form">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="titulo" class="form-label">Título da Tarefa*</label>
                                    <input type="text" class="form-control" id="titulo" name="titulo" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="descritivo" class="form-label">Descrição</label>
                                    <textarea class="form-control" id="descritivo" name="descritivo" rows="3"></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_limite" class="form-label">Data Limite</label>
                                            <input type="date" class="form-control" id="data_limite" name="data_limite">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="estado" class="form-label">Estado</label>
                                            <select class="form-select" id="estado" name="estado">
                                                <option value="aberta" selected>Aberta</option>
                                                <option value="em execução">Em Execução</option>
                                                <option value="suspensa">Suspensa</option>
                                                <option value="completada">Completada</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="responsavel" class="form-label">Responsável</label>
                                    <select class="form-select" id="responsavel" name="responsavel">
                                        <?php foreach ($users as $u): ?>
                                        <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $user_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['username']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Informações do Redmine (Opcional)</label>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="task_id" name="task_id" placeholder="ID da Tarefa">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" class="form-control" id="todo_issue" name="todo_issue" placeholder="ToDo do Issue">
                                        </div>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="milestone_id" name="milestone_id" placeholder="ID do Milestone">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="projeto_id" name="projeto_id" placeholder="ID do Projeto">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12 d-flex justify-content-end">
                                <button type="button" class="btn btn-secondary me-2" id="cancel-new-task">
                                    Cancelar
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> Adicionar Tarefa
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Painéis kanban de tarefas por estado -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-kanban"></i> Quadro de Tarefas</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Coluna: Aberta -->
                        <div class="col-md-<?= $show_completed ? '3' : '4' ?>">
                            <div class="card h-100 border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-circle"></i> Abertas
                                        <span class="badge bg-light text-dark ms-1"><?= count($tarefas_por_estado['aberta']) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="todo-container" id="aberta-container" data-estado="aberta">
                                        <?php if (empty($tarefas_por_estado['aberta'])): ?>
                                            <div class="text-center p-3 text-muted">
                                                <i class="bi bi-inbox"></i> Sem tarefas abertas
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($tarefas_por_estado['aberta'] as $tarefa): ?>
                                                <div class="card mb-2 task-card <?= $tarefa['is_milestone'] ? 'milestone-card' : '' ?>" 
                                                     draggable="<?= $tarefa['is_milestone'] ? 'false' : 'true' ?>" 
                                                     data-task-id="<?= $tarefa['id'] ?>">
                                                    <div class="card-body p-2">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <?php if ($tarefa['is_milestone']): ?>
                                                                <i class="bi bi-flag-fill text-warning me-1" title="Milestone do Redmine"></i>
                                                            <?php endif; ?>
                                                            <h6 class="card-title mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                                                        </div>
                                                        <p class="card-text small mb-1">
                                                            <?php if (!empty($tarefa['descritivo'])): ?>
                                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tarefa['descritivo']) ?>">
                                                                    <?= htmlspecialchars($tarefa['descritivo']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($tarefa['responsavel_nome']) ?></span>
                                                            <?php if (!empty($tarefa['data_limite'])): ?>
                                                                <?php 
                                                                $data_limite = new DateTime($tarefa['data_limite']);
                                                                $hoje = new DateTime();
                                                                $vencida = $hoje > $data_limite;
                                                                ?>
                                                                <span class="badge <?= $vencida ? 'bg-danger' : 'bg-secondary' ?>">
                                                                    <?= $data_limite->format('d/m/Y') ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($tarefa['is_milestone']): ?>
                                                            <div class="mt-2">
                                                                <span class="badge bg-warning text-dark">
                                                                    <i class="bi bi-flag"></i> Milestone #<?= $tarefa['redmine_milestone_id'] ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Repetir para outras colunas... -->
                        <!-- (Em execução, Suspensa, Completada) -->
                        <!-- O código segue o mesmo padrão, apenas mudando o estado -->
                        
                        <!-- Coluna: Em Execução -->
                        <div class="col-md-<?= $show_completed ? '3' : '4' ?>">
                            <div class="card h-100 border-info">
                                <div class="card-header bg-info text-dark">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-play-circle"></i> Em Execução
                                        <span class="badge bg-light text-dark ms-1"><?= count($tarefas_por_estado['em execução']) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="todo-container" id="em-execucao-container" data-estado="em execução">
                                        <?php if (empty($tarefas_por_estado['em execução'])): ?>
                                            <div class="text-center p-3 text-muted">
                                                <i class="bi bi-inbox"></i> Sem tarefas em execução
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($tarefas_por_estado['em execução'] as $tarefa): ?>
                                                <div class="card mb-2 task-card <?= $tarefa['is_milestone'] ? 'milestone-card' : '' ?>" 
                                                     draggable="<?= $tarefa['is_milestone'] ? 'false' : 'true' ?>" 
                                                     data-task-id="<?= $tarefa['id'] ?>">
                                                    <div class="card-body p-2">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <?php if ($tarefa['is_milestone']): ?>
                                                                <i class="bi bi-flag-fill text-warning me-1" title="Milestone do Redmine"></i>
                                                            <?php endif; ?>
                                                            <h6 class="card-title mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                                                        </div>
                                                        <p class="card-text small mb-1">
                                                            <?php if (!empty($tarefa['descritivo'])): ?>
                                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tarefa['descritivo']) ?>">
                                                                    <?= htmlspecialchars($tarefa['descritivo']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($tarefa['responsavel_nome']) ?></span>
                                                            <?php if (!empty($tarefa['data_limite'])): ?>
                                                                <?php 
                                                                $data_limite = new DateTime($tarefa['data_limite']);
                                                                $hoje = new DateTime();
                                                                $vencida = $hoje > $data_limite;
                                                                ?>
                                                                <span class="badge <?= $vencida ? 'bg-danger' : 'bg-secondary' ?>">
                                                                    <?= $data_limite->format('d/m/Y') ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($tarefa['is_milestone']): ?>
                                                            <div class="mt-2">
                                                                <span class="badge bg-warning text-dark">
                                                                    <i class="bi bi-flag"></i> Milestone #<?= $tarefa['redmine_milestone_id'] ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Coluna: Suspensa -->
                        <div class="col-md-<?= $show_completed ? '3' : '4' ?>">
                            <div class="card h-100 border-warning">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-pause-circle"></i> Suspensas
                                        <span class="badge bg-light text-dark ms-1"><?= count($tarefas_por_estado['suspensa']) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="todo-container" id="suspensa-container" data-estado="suspensa">
                                        <?php if (empty($tarefas_por_estado['suspensa'])): ?>
                                            <div class="text-center p-3 text-muted">
                                                <i class="bi bi-inbox"></i> Sem tarefas suspensas
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($tarefas_por_estado['suspensa'] as $tarefa): ?>
                                                <div class="card mb-2 task-card <?= $tarefa['is_milestone'] ? 'milestone-card' : '' ?>" 
                                                     draggable="<?= $tarefa['is_milestone'] ? 'false' : 'true' ?>" 
                                                     data-task-id="<?= $tarefa['id'] ?>">
                                                    <div class="card-body p-2">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <?php if ($tarefa['is_milestone']): ?>
                                                                <i class="bi bi-flag-fill text-warning me-1" title="Milestone do Redmine"></i>
                                                            <?php endif; ?>
                                                            <h6 class="card-title mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                                                        </div>
                                                        <p class="card-text small mb-1">
                                                            <?php if (!empty($tarefa['descritivo'])): ?>
                                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tarefa['descritivo']) ?>">
                                                                    <?= htmlspecialchars($tarefa['descritivo']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($tarefa['responsavel_nome']) ?></span>
                                                            <?php if (!empty($tarefa['data_limite'])): ?>
                                                                <?php 
                                                                $data_limite = new DateTime($tarefa['data_limite']);
                                                                $hoje = new DateTime();
                                                                $vencida = $hoje > $data_limite;
                                                                ?>
                                                                <span class="badge <?= $vencida ? 'bg-danger' : 'bg-secondary' ?>">
                                                                    <?= $data_limite->format('d/m/Y') ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($tarefa['is_milestone']): ?>
                                                            <div class="mt-2">
                                                                <span class="badge bg-warning text-dark">
                                                                    <i class="bi bi-flag"></i> Milestone #<?= $tarefa['redmine_milestone_id'] ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Coluna: Completada (só visível se show_completed=1) -->
                        <div class="col-md-3" id="completada-column" <?= $show_completed ? '' : 'style="display: none;"' ?>>
                            <div class="card h-100 border-success">
                                <div class="card-header bg-success text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-check-circle"></i> Completadas
                                        <span class="badge bg-light text-dark ms-1"><?= count($tarefas_por_estado['completada']) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="todo-container" id="completada-container" data-estado="completada">
                                        <?php if (empty($tarefas_por_estado['completada'])): ?>
                                            <div class="text-center p-3 text-muted">
                                                <i class="bi bi-inbox"></i> Sem tarefas completadas
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($tarefas_por_estado['completada'] as $tarefa): ?>
                                                <div class="card mb-2 task-card <?= $tarefa['is_milestone'] ? 'milestone-card' : '' ?>" 
                                                     draggable="<?= $tarefa['is_milestone'] ? 'false' : 'true' ?>" 
                                                     data-task-id="<?= $tarefa['id'] ?>">
                                                    <div class="card-body p-2">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <?php if ($tarefa['is_milestone']): ?>
                                                                <i class="bi bi-flag-fill text-warning me-1" title="Milestone do Redmine"></i>
                                                            <?php endif; ?>
                                                            <h6 class="card-title mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                                                        </div>
                                                        <p class="card-text small mb-1">
                                                            <?php if (!empty($tarefa['descritivo'])): ?>
                                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tarefa['descritivo']) ?>">
                                                                    <?= htmlspecialchars($tarefa['descritivo']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($tarefa['responsavel_nome']) ?></span>
                                                            <?php if (!empty($tarefa['data_limite'])): ?>
                                                                <?php 
                                                                $data_limite = new DateTime($tarefa['data_limite']);
                                                                $hoje = new DateTime();
                                                                $vencida = $hoje > $data_limite;
                                                                ?>
                                                                <span class="badge <?= $vencida ? 'bg-danger' : 'bg-secondary' ?>">
                                                                    <?= $data_limite->format('d/m/Y') ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($tarefa['is_milestone']): ?>
                                                            <div class="mt-2">
                                                                <span class="badge bg-warning text-dark">
                                                                    <i class="bi bi-flag"></i> Milestone #<?= $tarefa['redmine_milestone_id'] ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabela de todas as tarefas -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-list-check"></i> Lista de Tarefas</h5>
                </div>
                <div class="card-body p-0">
                    <?php
                    $total_tarefas = count($tarefas_por_estado['aberta']) + 
                                    count($tarefas_por_estado['em execução']) + 
                                    count($tarefas_por_estado['suspensa']) + 
                                    count($tarefas_por_estado['completada']);
                    
                    if ($total_tarefas > 0):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>ID</th>
                                    <th width="25%">Título</th>
                                    <th>Responsável</th>
                                    <th>Data Limite</th>
                                    <th>Estado</th>
                                    <th>Task ID</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $estados_ordem = ['aberta', 'em execução', 'suspensa'];
                                if ($show_completed) {
                                    $estados_ordem[] = 'completada';
                                }
                                
                                foreach ($estados_ordem as $estado):
                                    foreach ($tarefas_por_estado[$estado] as $tarefa): 
                                ?>
                                <tr class="<?= $tarefa['estado'] === 'completada' ? 'table-success' : ($tarefa['estado'] === 'suspensa' ? 'table-warning' : '') ?> <?= $tarefa['is_milestone'] ? 'table-info' : '' ?>">
                                    <td>
                                        <?php if ($tarefa['is_milestone']): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-flag"></i> Milestone
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-check2-square"></i> Tarefa
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $tarefa['id'] ?>
                                        <?php if ($tarefa['is_milestone']): ?>
                                            <small class="text-muted d-block">Redmine: #<?= $tarefa['redmine_milestone_id'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($tarefa['titulo']) ?></strong>
                                        <?php if (!empty($tarefa['descritivo'])): ?>
                                        <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="<?= htmlspecialchars($tarefa['descritivo']) ?>">
                                            <i class="bi bi-info-circle-fill text-primary"></i>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($tarefa['responsavel_nome']) ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($tarefa['data_limite'])) {
                                            $data_limite = new DateTime($tarefa['data_limite']);
                                            $hoje = new DateTime();
                                            $diff = $hoje->diff($data_limite);
                                            $vencida = $hoje > $data_limite && $tarefa['estado'] !== 'completada';
                                            
                                            echo '<span class="' . ($vencida ? 'text-danger fw-bold' : '') . '">';
                                            echo htmlspecialchars($data_limite->format('d/m/Y'));
                                            echo '</span>';
                                            
                                            if ($vencida) {
                                                echo ' <span class="badge bg-danger">Vencida</span>';
                                            } elseif ($diff->days <= 2 && $tarefa['estado'] !== 'completada') {
                                                echo ' <span class="badge bg-warning text-dark">Em breve</span>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">Não definida</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= 
                                            $tarefa['estado'] === 'aberta' ? 'bg-primary' : 
                                            ($tarefa['estado'] === 'em execução' ? 'bg-info text-dark' : 
                                            ($tarefa['estado'] === 'suspensa' ? 'bg-warning text-dark' : 
                                            'bg-success')) ?>">
                                            <?= htmlspecialchars(ucfirst($tarefa['estado'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($tarefa['task_id'])): ?>
                                        <a href="<?= $BASE_URL ?>/redmine/issues/<?= $tarefa['task_id'] ?>" target="_blank" class="text-decoration-none">
                                            #<?= $tarefa['task_id'] ?>
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <?php elseif ($tarefa['is_milestone']): ?>
                                        <a href="<?= $BASE_URL ?>/redmine/issues/<?= $tarefa['redmine_milestone_id'] ?>" target="_blank" class="text-decoration-none">
                                            #<?= $tarefa['redmine_milestone_id'] ?>
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                Ações
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if (!$tarefa['is_milestone']): ?>
                                                    <!-- Opções de mudança de estado para tarefas normais -->
                                                    <li><h6 class="dropdown-header">Mudar Estado</h6></li>
                                                    <?php if ($tarefa['estado'] !== 'aberta'): ?>
                                                    <li>
                                                        <button class="dropdown-item change-state-btn" data-task-id="<?= $tarefa['id'] ?>" data-state="aberta">
                                                            <i class="bi bi-circle text-primary"></i> Marcar como Aberta
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($tarefa['estado'] !== 'em execução'): ?>
                                                    <li>
                                                        <button class="dropdown-item change-state-btn" data-task-id="<?= $tarefa['id'] ?>" data-state="em execução">
                                                            <i class="bi bi-play-circle text-info"></i> Marcar como Em Execução
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($tarefa['estado'] !== 'suspensa'): ?>
                                                    <li>
                                                        <button class="dropdown-item change-state-btn" data-task-id="<?= $tarefa['id'] ?>" data-state="suspensa">
                                                            <i class="bi bi-pause-circle text-warning"></i> Marcar como Suspensa
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($tarefa['estado'] !== 'completada'): ?>
                                                    <li>
                                                        <button class="dropdown-item change-state-btn" data-task-id="<?= $tarefa['id'] ?>" data-state="completada">
                                                            <i class="bi bi-check-circle text-success"></i> Marcar como Completada
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <li><hr class="dropdown-divider"></li>
                                                    
                                                    <!-- Opção de excluir (só para tarefas normais criadas pelo usuário) -->
                                                    <?php if ($tarefa['autor'] == $user_id): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger delete-todo" data-id="<?= $tarefa['id'] ?>" data-title="<?= htmlspecialchars($tarefa['titulo']) ?>">
                                                            <i class="bi bi-trash"></i> Excluir
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <!-- Opções para milestones -->
                                                    <li><h6 class="dropdown-header">Milestone do Redmine</h6></li>
                                                    <li>
                                                        <a class="dropdown-item" href="<?= $BASE_URL ?>/redmine/issues/<?= $tarefa['redmine_milestone_id'] ?>" target="_blank">
                                                            <i class="bi bi-box-arrow-up-right"></i> Abrir no Redmine
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="?tab=milestone&action=view&id=<?= $tarefa['redmine_milestone_id'] ?>">
                                                            <i class="bi bi-eye"></i> Ver Detalhes da Milestone
                                                        </a>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                    endforeach; 
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center p-4">
                        <i class="bi bi-clipboard-check" style="font-size: 3rem;"></i>
                        <p class="mt-3">Você ainda não tem tarefas. Crie uma nova tarefa ou sincronize milestones do Redmine!</p>
                        <button type="button" class="btn btn-info me-2" onclick="document.getElementById('sync-milestones-form').submit();">
                            <i class="bi bi-arrow-clockwise"></i> Sincronizar Milestones
                        </button>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('new-task-btn').click();">
                            <i class="bi bi-plus-circle"></i> Nova Tarefa
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">Confirmar Exclusão</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Tem certeza que deseja excluir a tarefa <strong id="delete-task-title"></strong>?</p>
                <p class="text-danger">Esta ação não pode ser desfeita.</p>
                <p class="text-info"><i class="bi bi-info-circle"></i> Milestones não podem ser excluídas, apenas sincronizadas.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="post" action="" id="delete-form">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="todo_id" id="delete-todo-id">
                    <button type="submit" class="btn btn-danger">Excluir Permanentemente</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Formulário oculto para atualização de estados -->
<form id="update-state-form" method="post" style="display: none;">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="todo_id" id="update-todo-id">
    <input type="hidden" name="new_estado" id="update-new-estado">
</form>

<style>
/* Estilos específicos para milestones */
.milestone-card {
    border-left: 4px solid #ffc107 !important;
    background: linear-gradient(135deg, #fff9c4 0%, #ffffff 100%);
}

.milestone-card:hover {
    box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
}

.milestone-card .card-body {
    position: relative;
}

.milestone-card::before {
    content: '';
    position: absolute;
    top: 5px;
    right: 5px;
    width: 12px;
    height: 12px;
    background: #ffc107;
    border-radius: 50%;
    opacity: 0.7;
}

.task-card { 
    cursor: move; 
    transition: all 0.2s ease;
}

.task-card:not(.milestone-card):hover {
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.milestone-card[draggable="false"] {
    cursor: default;
    opacity: 0.9;
}

.dragging { 
    opacity: 0.4; 
}

.drag-over { 
    background-color: rgba(0, 0, 0, 0.05); 
}

.todo-container { 
    min-height: 100px; 
}

/* Indicadores visuais para milestones na tabela */
.table-info {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Toggle do formulário de nova tarefa
    const newTaskBtn = document.getElementById('new-task-btn');
    const newTaskFormContainer = document.getElementById('new-task-form-container');
    const cancelNewTaskBtn = document.getElementById('cancel-new-task');
    
    newTaskBtn.addEventListener('click', function() {
        newTaskFormContainer.style.display = 'block';
        newTaskBtn.style.display = 'none';
        document.getElementById('titulo').focus();
    });
    
    cancelNewTaskBtn.addEventListener('click', function() {
        newTaskFormContainer.style.display = 'none';
        newTaskBtn.style.display = 'inline-block';
    });
    
    // Botão de sincronização de milestones
    const syncMilestonesBtn = document.getElementById('sync-milestones-btn');
    const syncMilestonesForm = document.getElementById('sync-milestones-form');
    
    syncMilestonesBtn.addEventListener('click', function() {
        // Mostrar indicador de carregamento
        const originalText = this.innerHTML;
        this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sincronizando...';
        this.disabled = true;
        
        // Enviar formulário
        syncMilestonesForm.submit();
    });
    
    // Garantir que o parâmetro tab seja mantido nos formulários
    function ensureFormHasTabParam(form) {
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        
        if (tabParam && !form.querySelector('input[name="tab"]')) {
            const tabInput = document.createElement('input');
            tabInput.type = 'hidden';
            tabInput.name = 'tab';
            tabInput.value = tabParam;
            form.appendChild(tabInput);
        }
    }
    
    // Adicionar parâmetro tab a todos os formulários na página
    document.querySelectorAll('form').forEach(form => {
        ensureFormHasTabParam(form);
    });
    
    // Filtro de responsável e mostrar completadas
    const filterResponsavel = document.getElementById('filter-responsavel');
    const showCompletedCheckbox = document.getElementById('show-completed');
    const filterForm = document.getElementById('filter-form');
    
    ensureFormHasTabParam(filterForm);
    
    filterResponsavel.addEventListener('change', function() {
        filterForm.submit();
    });
    
    showCompletedCheckbox.addEventListener('change', function() {
        filterForm.submit();
    });
    
    // Manipular cliques no botão de excluir
    document.querySelectorAll('.delete-todo').forEach(function(button) {
        button.addEventListener('click', function() {
            var todoId = this.getAttribute('data-id');
            var todoTitle = this.getAttribute('data-title');
            
            document.getElementById('delete-todo-id').value = todoId;
            document.getElementById('delete-task-title').textContent = todoTitle;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        });
    });
    
    // Manipular mudança de estado via botões
    document.querySelectorAll('.change-state-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var taskId = this.getAttribute('data-task-id');
            var newState = this.getAttribute('data-state');
            
            document.getElementById('update-todo-id').value = taskId;
            document.getElementById('update-new-estado').value = newState;
            document.getElementById('update-state-form').submit();
        });
    });
    
    // Implementação de Drag and Drop melhorada
    let dragSrcEl = null;
    let draggingTask = false;
    
    function handleDragStart(e) {
        // Verificar se é uma milestone - não permitir arrastar
        if (this.classList.contains('milestone-card')) {
            e.preventDefault();
            return false;
        }
        
        this.classList.add('dragging');
        dragSrcEl = this;
        draggingTask = true;
        
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
        
        setTimeout(() => {
            this.style.opacity = '0.4';
        }, 0);
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        draggingTask = false;
        
        document.querySelectorAll('.todo-container').forEach(container => {
            container.classList.remove('drag-over');
        });
        
        this.style.opacity = '1';
    }
    
    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        
        e.dataTransfer.dropEffect = 'move';
        this.classList.add('drag-over');
        
        return false;
    }
    
    function handleDragEnter(e) {
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        e.stopPropagation();
        e.preventDefault();
        
        this.classList.remove('drag-over');
        
        // Só prosseguir se não é uma milestone e está soltando em um container diferente
        if (dragSrcEl && this !== dragSrcEl.parentNode && !dragSrcEl.classList.contains('milestone-card')) {
            const newState = this.getAttribute('data-estado');
            const taskId = dragSrcEl.getAttribute('data-task-id');
            
            // Adiciona a tarefa ao novo container visualmente
            this.appendChild(dragSrcEl);
            
            // Atualiza o estado no servidor
            updateTaskStatus(taskId, newState);
        }
        
        return false;
    }
    
    function updateTaskStatus(taskId, newState) {
        const formData = new FormData();
        formData.append('action', 'drag_update_status');
        formData.append('todo_id', taskId);
        formData.append('new_estado', newState);
        
        let url = window.location.href;
        if (!url.includes('tab=todos')) {
            url += (url.includes('?') ? '&' : '?') + 'tab=todos';
        }
        
        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Estado atualizado com sucesso');
                // Mostrar notificação de sucesso se desejar
                showNotification('Estado atualizado com sucesso!', 'success');
            } else {
                console.error('Erro ao atualizar estado:', data.message);
                alert('Erro ao atualizar estado: ' + data.message);
                // Recarregar a página em caso de erro para manter a consistência
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Erro na requisição AJAX:', error);
            alert('Erro na requisição. Por favor, tente novamente.');
            // Recarregar a página em caso de erro de rede
            window.location.reload();
        });
    }
    
    // Função para mostrar notificações (opcional)
    function showNotification(message, type = 'info') {
        // Criar elemento de notificação
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Remover após 3 segundos
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
    
    function initDragAndDrop() {
        // Configurar cada cartão de tarefa como arrastável
        const taskCards = document.querySelectorAll('.task-card');
        taskCards.forEach(taskCard => {
            // Verificar se é milestone
            const isMilestone = taskCard.classList.contains('milestone-card');
            taskCard.setAttribute('draggable', isMilestone ? 'false' : 'true');
            
            // Remover eventos antigos
            taskCard.removeEventListener('dragstart', handleDragStart);
            taskCard.removeEventListener('dragend', handleDragEnd);
            
            // Adicionar novos event listeners apenas se não for milestone
            if (!isMilestone) {
                taskCard.addEventListener('dragstart', handleDragStart);
                taskCard.addEventListener('dragend', handleDragEnd);
            }
        });
        
        // Configurar containers como áreas de soltar
        const containers = document.querySelectorAll('.todo-container');
        containers.forEach(container => {
            container.removeEventListener('dragover', handleDragOver);
            container.removeEventListener('dragenter', handleDragEnter);
            container.removeEventListener('dragleave', handleDragLeave);
            container.removeEventListener('drop', handleDrop);
            
            container.addEventListener('dragover', handleDragOver);
            container.addEventListener('dragenter', handleDragEnter);
            container.addEventListener('dragleave', handleDragLeave);
            container.addEventListener('drop', handleDrop);
        });
    }
    
    // Inicializar drag and drop
    initDragAndDrop();
    
    // Adicionar evento para mostrar informações de milestone ao clicar
    document.querySelectorAll('.milestone-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Evitar propagação se clicar em links ou botões
            if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                return;
            }
            
            const milestoneId = this.querySelector('[data-task-id]')?.getAttribute('data-task-id');
            if (milestoneId) {
                // Adicionar efeito visual de clique
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
                
                // Mostrar informações ou redirecionar
                showNotification('Milestone clicada! Consulte a aba de Milestones para mais detalhes.', 'info');
            }
        });
        
        // Adicionar cursor pointer para indicar que é clicável
        card.style.cursor = 'pointer';
    });
});
</script>