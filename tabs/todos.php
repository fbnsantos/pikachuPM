<?php
// tabs/todos.php - Gestão de ToDos

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Configuração do banco de dados SQLite
$db_file = 'db/todos.db';
$db_dir = dirname($db_file);

// Criar diretório se não existir
if (!file_exists($db_dir)) {
    mkdir($db_dir, 0755, true);
}

// Conectar ao banco de dados
try {
    $db = new SQLite3($db_file);
    
    // Ativar chaves estrangeiras
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Criar tabela de tokens se não existir
    $db->exec('CREATE TABLE IF NOT EXISTS user_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        username TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Criar tabela de tarefas se não existir
    $db->exec('CREATE TABLE IF NOT EXISTS todos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo TEXT NOT NULL,
        descritivo TEXT,
        data_limite DATE,
        autor INTEGER NOT NULL,
        responsavel INTEGER,
        task_id INTEGER,
        todo_issue TEXT,
        milestone_id INTEGER,
        projeto_id INTEGER,
        estado TEXT DEFAULT "aberta",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (autor) REFERENCES user_tokens(user_id),
        FOREIGN KEY (responsavel) REFERENCES user_tokens(user_id)
    )');
    
    // Verificar se o usuário atual já tem um token
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    
    $stmt = $db->prepare('SELECT token FROM user_tokens WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user_token = $result->fetchArray(SQLITE3_ASSOC);
    
    // Se não tiver token, gerar um novo
    if (!$user_token) {
        $token = bin2hex(random_bytes(16)); // Gera um token hexadecimal de 32 caracteres
        
        $insert = $db->prepare('INSERT INTO user_tokens (user_id, username, token) VALUES (:user_id, :username, :token)');
        $insert->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        $insert->bindValue(':username', $username, SQLITE3_TEXT);
        $insert->bindValue(':token', $token, SQLITE3_TEXT);
        $insert->execute();
        
        $user_token = ['token' => $token];
    }

    // Verificar se foi requisitado obter os detalhes de uma tarefa via AJAX
    if (isset($_GET['get_task_details']) && is_numeric($_GET['get_task_details'])) {
        $task_id = (int)$_GET['get_task_details'];
        
        $stmt = $db->prepare('SELECT * FROM todos WHERE id = :id AND (autor = :user_id OR responsavel = :user_id)');
        $stmt->bindValue(':id', $task_id, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $task = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($task) {
            header('Content-Type: application/json');
            echo json_encode($task);
            exit;
        } else {
            header('Content-Type: application/json');
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Tarefa não encontrada ou acesso negado']);
            exit;
        }
    }

    // Obter o tab atual para redirecionamentos
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : '';
    
    // Processamento do formulário
    $success_message = '';
    $error_message = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            // Adicionar nova tarefa
            if ($_POST['action'] === 'add') {
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
                    $stmt->bindValue(':task_id', $task_id > 0 ? $task_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':todo_issue', $todo_issue, SQLITE3_TEXT);
                    $stmt->bindValue(':milestone_id', $milestone_id > 0 ? $milestone_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':projeto_id', $projeto_id > 0 ? $projeto_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':estado', $estado, SQLITE3_TEXT);
                    
                    if ($stmt->execute()) {
                        $success_message = 'Tarefa adicionada com sucesso!';
                        // Redirecionar para manter o parâmetro tab
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao adicionar tarefa: ' . $db->lastErrorMsg();
                    }
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
                        WHERE id = :id AND (autor = :user_id OR responsavel = :user_id)');
                    
                    $stmt->bindValue(':titulo', $titulo, SQLITE3_TEXT);
                    $stmt->bindValue(':descritivo', $descritivo, SQLITE3_TEXT);
                    $stmt->bindValue(':data_limite', $data_limite, SQLITE3_TEXT);
                    $stmt->bindValue(':responsavel', $responsavel, SQLITE3_INTEGER);
                    $stmt->bindValue(':task_id', $task_id > 0 ? $task_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':todo_issue', $todo_issue, SQLITE3_TEXT);
                    $stmt->bindValue(':milestone_id', $milestone_id > 0 ? $milestone_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':projeto_id', $projeto_id > 0 ? $projeto_id : null, SQLITE3_INTEGER);
                    $stmt->bindValue(':estado', $estado, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $todo_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        $success_message = 'Tarefa atualizada com sucesso!';
                        // Redirecionar para manter o parâmetro tab
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao atualizar tarefa: ' . $db->lastErrorMsg();
                    }
                }
            }
            // Atualizar estado da tarefa
            elseif ($_POST['action'] === 'update_status') {
                $todo_id = (int)$_POST['todo_id'];
                $new_estado = trim($_POST['new_estado']);
                
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                
                if (in_array($new_estado, $valid_estados)) {
                    $stmt = $db->prepare('UPDATE todos SET estado = :estado, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->bindValue(':estado', $new_estado, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $todo_id, SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        // Para requisições AJAX (drag and drop)
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => 'Estado atualizado com sucesso']);
                            exit;
                        }
                        
                        $success_message = 'Estado da tarefa atualizado com sucesso!';
                        // Redirecionar para manter o parâmetro tab
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
                        $error_message = 'Erro ao atualizar estado: ' . $db->lastErrorMsg();
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
            // Excluir tarefa
            elseif ($_POST['action'] === 'delete') {
                $todo_id = (int)$_POST['todo_id'];
                
                $stmt = $db->prepare('DELETE FROM todos WHERE id = :id AND (autor = :user_id OR responsavel = :user_id)');
                $stmt->bindValue(':id', $todo_id, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                
                if ($stmt->execute()) {
                    $success_message = 'Tarefa excluída com sucesso!';
                    // Redirecionar para manter o parâmetro tab
                    if (!empty($current_tab)) {
                        header('Location: ?tab=' . urlencode($current_tab));
                        exit;
                    }
                } else {
                    $error_message = 'Erro ao excluir tarefa: ' . $db->lastErrorMsg();
                }
            }
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
    
    // Filtrar por responsável se especificado
    if ($filter_responsavel) {
        $sql .= ' AND t.responsavel = :responsavel_id';
    } else {
        // Se não houver filtro, mostrar apenas tarefas do usuário
        $sql .= ' AND (t.autor = :user_id OR t.responsavel = :user_id)';
    }
    
    // Filtrar tarefas completadas, se necessário
    if (!$show_completed) {
        $sql .= ' AND t.estado != "completada"';
    }
    
    // Ordenação
    $sql .= ' ORDER BY 
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
    
    if ($filter_responsavel) {
        $stmt->bindValue(':responsavel_id', $filter_responsavel, SQLITE3_INTEGER);
    } else {
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    }
    
    $result = $stmt->execute();
    
    // Organizar tarefas por estado
    $tarefas_por_estado = [
        'aberta' => [],
        'em execução' => [],
        'suspensa' => [],
        'completada' => []
    ];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tarefas_por_estado[$row['estado']][] = $row;
    }
    
    // Obter todos os usuários para o select de responsável
    $users_stmt = $db->prepare('SELECT user_id, username FROM user_tokens ORDER BY username');
    $users_result = $users_stmt->execute();
    $users = [];
    while ($row = $users_result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    
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
                <button type="button" class="btn btn-primary" id="new-task-btn">
                    <i class="bi bi-plus-circle"></i> Nova Tarefa
                </button>
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
    
    <!-- Formulário de nova tarefa (inicialmente escondido a menos que esteja em modo de edição) -->
    <div class="row mb-4" id="new-task-form-container" style="display: <?= $edit_mode ? 'block' : 'none' ?>;">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-<?= $edit_mode ? 'pencil' : 'plus-circle' ?>"></i> <?= $edit_mode ? 'Editar' : 'Nova' ?> Tarefa</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="new-task-form">
                        <input type="hidden" name="action" value="<?= $edit_mode ? 'edit_task' : 'add' ?>">
                        <input type="hidden" name="tab" value="todos">
                        <?php if ($edit_mode): ?>
                        <input type="hidden" name="todo_id" value="<?= $task_to_edit['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="titulo" class="form-label">Título da Tarefa*</label>
                                    <input type="text" class="form-control" id="titulo" name="titulo" required value="<?= $edit_mode ? htmlspecialchars($task_to_edit['titulo']) : '' ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="descritivo" class="form-label">Descrição</label>
                                    <textarea class="form-control" id="descritivo" name="descritivo" rows="3"><?= $edit_mode ? htmlspecialchars($task_to_edit['descritivo']) : '' ?></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_limite" class="form-label">Data Limite</label>
                                            <input type="date" class="form-control" id="data_limite" name="data_limite" value="<?= $edit_mode ? htmlspecialchars($task_to_edit['data_limite']) : '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="estado" class="form-label">Estado</label>
                                            <select class="form-select" id="estado" name="estado">
                                                <option value="aberta" <?= ($edit_mode && $task_to_edit['estado'] == 'aberta') ? 'selected' : '' ?>>Aberta</option>
                                                <option value="em execução" <?= ($edit_mode && $task_to_edit['estado'] == 'em execução') ? 'selected' : '' ?>>Em Execução</option>
                                                <option value="suspensa" <?= ($edit_mode && $task_to_edit['estado'] == 'suspensa') ? 'selected' : '' ?>>Suspensa</option>
                                                <option value="completada" <?= ($edit_mode && $task_to_edit['estado'] == 'completada') ? 'selected' : '' ?>>Completada</option>
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
                                        <option value="<?= $u['user_id'] ?>" <?= ($edit_mode && $task_to_edit['responsavel'] == $u['user_id']) || (!$edit_mode && $u['user_id'] == $user_id) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['username']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Informações do Redmine (Opcional)</label>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="task_id" name="task_id" placeholder="ID da Tarefa" value="<?= $edit_mode && $task_to_edit['task_id'] ? htmlspecialchars($task_to_edit['task_id']) : '' ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" class="form-control" id="todo_issue" name="todo_issue" placeholder="ToDo do Issue" value="<?= $edit_mode && $task_to_edit['todo_issue'] ? htmlspecialchars($task_to_edit['todo_issue']) : '' ?>">
                                        </div>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="milestone_id" name="milestone_id" placeholder="ID do Milestone" value="<?= $edit_mode && $task_to_edit['milestone_id'] ? htmlspecialchars($task_to_edit['milestone_id']) : '' ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="number" class="form-control" id="projeto_id" name="projeto_id" placeholder="ID do Projeto" value="<?= $edit_mode && $task_to_edit['projeto_id'] ? htmlspecialchars($task_to_edit['projeto_id']) : '' ?>">
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
                                    <i class="bi bi-<?= $edit_mode ? 'save' : 'plus-circle' ?>"></i> <?= $edit_mode ? 'Salvar Alterações' : 'Adicionar Tarefa' ?>
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
                                                <div class="card mb-2 task-card" draggable="true" data-task-id="<?= $tarefa['id'] ?>">
                                                    <button type="button" class="btn btn-sm edit-task-btn" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-task-id="<?= $tarefa['id'] ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <div class="card-body p-2">
                                                        <h6 class="card-title mb-1"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
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
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
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
                                                <div class="card mb-2 task-card" draggable="true" data-task-id="<?= $tarefa['id'] ?>">
                                                    <button type="button" class="btn btn-sm edit-task-btn" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-task-id="<?= $tarefa['id'] ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <div class="card-body p-2">
                                                        <h6 class="card-title mb-1"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
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
                                                <div class="card mb-2 task-card" draggable="true" data-task-id="<?= $tarefa['id'] ?>">
                                                    <button type="button" class="btn btn-sm edit-task-btn" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-task-id="<?= $tarefa['id'] ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <div class="card-body p-2">
                                                        <h6 class="card-title mb-1"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
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
                                                <div class="card mb-2 task-card" draggable="true" data-task-id="<?= $tarefa['id'] ?>">
                                                    <button type="button" class="btn btn-sm edit-task-btn" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-task-id="<?= $tarefa['id'] ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <div class="card-body p-2">
                                                        <h6 class="card-title mb-1"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
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
                                // Adicionar 'completada' apenas se show_completed estiver ativado
                                if ($show_completed) {
                                    $estados_ordem[] = 'completada';
                                }
                                
                                foreach ($estados_ordem as $estado):
                                    foreach ($tarefas_por_estado[$estado] as $tarefa): 
                                ?>
                                <tr class="<?= $tarefa['estado'] === 'completada' ? 'table-success' : ($tarefa['estado'] === 'suspensa' ? 'table-warning' : '') ?>">
                                    <td><?= $tarefa['id'] ?></td>
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
                                        <a href="https://redmine.example.com/issues/<?= $tarefa['task_id'] ?>" target="_blank" class="text-decoration-none">
                                            #<?= $tarefa['task_id'] ?>
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
                                                <!-- Opção de editar -->
                                                <li>
                                                    <button type="button" class="dropdown-item edit-task-btn" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-task-id="<?= $tarefa['id'] ?>">
                                                        <i class="bi bi-pencil"></i> Editar
                                                    </button>
                                                </li>
                                                
                                                <li><hr class="dropdown-divider"></li>
                                                
                                                <!-- Opções de mudança de estado -->
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
                                                
                                                <!-- Opção de excluir -->
                                                <?php if ($tarefa['autor'] == $user_id): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger delete-todo" data-id="<?= $tarefa['id'] ?>" data-title="<?= htmlspecialchars($tarefa['titulo']) ?>">
                                                        <i class="bi bi-trash"></i> Excluir
                                                    </button>
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
                        <p class="mt-3">Você ainda não tem tarefas. Crie uma nova tarefa para começar!</p>
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="post" action="" id="delete-form">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="todo_id" id="delete-todo-id">
                    <input type="hidden" name="tab" value="todos">
                    <button type="submit" class="btn btn-danger">Excluir Permanentemente</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Edição de Tarefa -->
<div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editTaskModalLabel">Editar Tarefa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="" id="edit-task-form">
                    <input type="hidden" name="action" value="edit_task">
                    <input type="hidden" name="todo_id" id="edit-task-id">
                    <input type="hidden" name="tab" value="todos">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit-titulo" class="form-label">Título da Tarefa*</label>
                                <input type="text" class="form-control" id="edit-titulo" name="titulo" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit-descritivo" class="form-label">Descrição</label>
                                <textarea class="form-control" id="edit-descritivo" name="descritivo" rows="3"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit-data_limite" class="form-label">Data Limite</label>
                                        <input type="date" class="form-control" id="edit-data_limite" name="data_limite">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit-estado" class="form-label">Estado</label>
                                        <select class="form-select" id="edit-estado" name="estado">
                                            <option value="aberta">Aberta</option>
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
                                <label for="edit-responsavel" class="form-label">Responsável</label>
                                <select class="form-select" id="edit-responsavel" name="responsavel">
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['user_id'] ?>">
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Informações do Redmine (Opcional)</label>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <input type="number" class="form-control" id="edit-task_id" name="task_id" placeholder="ID da Tarefa">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" id="edit-todo_issue" name="todo_issue" placeholder="ToDo do Issue">
                                    </div>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-md-6">
                                        <input type="number" class="form-control" id="edit-milestone_id" name="milestone_id" placeholder="ID do Milestone">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="number" class="form-control" id="edit-projeto_id" name="projeto_id" placeholder="ID do Projeto">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12 d-flex justify-content-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                                Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salvar Alterações
                            </button>
                        </div>
                    </div>
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
    <input type="hidden" name="tab" value="todos">
</form>

<style>
    .task-card {
        position: relative;
        cursor: move;
        transition: all 0.2s;
    }
    .task-card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .dragging {
        opacity: 0.5;
    }
    .todo-container {
        min-height: 80px;
        padding: 8px;
        border-radius: 4px;
        transition: background-color 0.3s;
    }
    .todo-container.drag-over {
        background-color: rgba(0,0,0,0.05);
        border: 2px dashed #ccc;
    }
    .edit-task-btn {
        position: absolute;
        top: 5px;
        right: 5px;
        opacity: 0;
        transition: opacity 0.2s;
        font-size: 0.8rem;
        padding: 2px 5px;
        background-color: rgba(255,255,255,0.8);
        border: 1px solid #dee2e6;
        z-index: 5;
    }
    .task-card:hover .edit-task-btn {
        opacity: 1;
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
    
    // Filtro de responsável e mostrar completadas
    const filterResponsavel = document.getElementById('filter-responsavel');
    const showCompletedCheckbox = document.getElementById('show-completed');
    
    filterResponsavel.addEventListener('change', function() {
        document.getElementById('filter-form').submit();
    });
    
    showCompletedCheckbox.addEventListener('change', function() {
        document.getElementById('filter-form').submit();
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
    
    // ============== Drag and Drop Simplificado ==============
    // Implementação simplificada de drag-and-drop para todos os cartões de tarefa
    const draggables = document.querySelectorAll('.task-card');
    const containers = document.querySelectorAll('.todo-container');
    
    // Configurar cada cartão como arrastável
    draggables.forEach(draggable => {
        // Evitar problemas com o botão de edição
        const editBtn = draggable.querySelector('.edit-task-btn');
        if (editBtn) {
            editBtn.addEventListener('mousedown', e => {
                e.stopPropagation(); // Impedir que o evento chegue ao card
            });
        }
        
        draggable.addEventListener('dragstart', () => {
            draggable.classList.add('dragging');
        });
        
        draggable.addEventListener('dragend', () => {
            draggable.classList.remove('dragging');
            
            // Verificar se o estado da tarefa foi alterado
            const container = draggable.parentNode;
            if (container && container.classList.contains('todo-container')) {
                const newState = container.getAttribute('data-estado');
                const taskId = draggable.getAttribute('data-task-id');
                
                // Submeter formulário para atualizar o estado
                const form = document.getElementById('update-state-form');
                document.getElementById('update-todo-id').value = taskId;
                document.getElementById('update-new-estado').value = newState;
                form.submit();
            }
        });
    });
    
    // Configurar os containers para receber os cartões
    containers.forEach(container => {
        container.addEventListener('dragover', e => {
            e.preventDefault();
            container.classList.add('drag-over');
            
            const draggable = document.querySelector('.dragging');
            if (draggable) {
                container.appendChild(draggable);
            }
        });
        
        container.addEventListener('dragleave', () => {
            container.classList.remove('drag-over');
        });
        
        container.addEventListener('drop', () => {
            container.classList.remove('drag-over');
        });
    });
    
    // ============== Edição de Tarefas ==============
    // Manipular cliques no botão de editar
    document.querySelectorAll('.edit-task-btn').forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.stopPropagation(); // Impedir propagação para evitar conflitos com drag
            const taskId = this.getAttribute('data-task-id');
            
            // Indicar que está carregando
            document.getElementById('editTaskModalLabel').innerHTML = 'Carregando tarefa... <span class="spinner-border spinner-border-sm"></span>';
            
            // Buscar dados da tarefa
            fetch('?tab=todos&get_task_details=' + taskId)
                .then(response => response.json())
                .then(task => {
                    // Preencher o formulário com os dados da tarefa
                    document.getElementById('edit-task-id').value = task.id;
                    document.getElementById('edit-titulo').value = task.titulo;
                    document.getElementById('edit-descritivo').value = task.descritivo || '';
                    document.getElementById('edit-data_limite').value = task.data_limite || '';
                    document.getElementById('edit-responsavel').value = task.responsavel;
                    document.getElementById('edit-estado').value = task.estado;
                    document.getElementById('edit-task_id').value = task.task_id || '';
                    document.getElementById('edit-todo_issue').value = task.todo_issue || '';
                    document.getElementById('edit-milestone_id').value = task.milestone_id || '';
                    document.getElementById('edit-projeto_id').value = task.projeto_id || '';
                    
                    // Restaurar o título
                    document.getElementById('editTaskModalLabel').textContent = 'Editar Tarefa';
                })
                .catch(error => {
                    console.error('Erro ao carregar dados da tarefa:', error);
                    document.getElementById('editTaskModalLabel').textContent = 'Erro ao carregar tarefa';
                    
                    // Fechar o modal após alguns segundos
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('editTaskModal'));
                        if (modal) modal.hide();
                    }, 2000);
                });
        });
    });
});