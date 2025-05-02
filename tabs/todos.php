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

    // Processamento do formulário de adição/edição de tarefas
    $success_message = '';
    $error_message = '';
    
    // Obter o parâmetro "tab" para redirecionar corretamente após as ações
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : '';
    
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
                        // Redirecionar para manter o parâmetro tab=todos
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao adicionar tarefa: ' . $db->lastErrorMsg();
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
                        $success_message = 'Estado da tarefa atualizado com sucesso!';
                        // Redirecionar para manter o parâmetro tab=todos
                        if (!empty($current_tab)) {
                            header('Location: ?tab=' . urlencode($current_tab));
                            exit;
                        }
                    } else {
                        $error_message = 'Erro ao atualizar estado: ' . $db->lastErrorMsg();
                    }
                } else {
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
                    // Redirecionar para manter o parâmetro tab=todos
                    if (!empty($current_tab)) {
                        header('Location: ?tab=' . urlencode($current_tab));
                        exit;
                    }
                } else {
                    $error_message = 'Erro ao excluir tarefa: ' . $db->lastErrorMsg();
                }
            }
            // Atualizar estado via AJAX (para drag and drop)
            elseif ($_POST['action'] === 'drag_update_status') {
                $todo_id = (int)$_POST['todo_id'];
                $new_estado = trim($_POST['new_estado']);
                
                $valid_estados = ['aberta', 'em execução', 'suspensa', 'completada'];
                
                if (in_array($new_estado, $valid_estados)) {
                    $stmt = $db->prepare('UPDATE todos SET estado = :estado, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->bindValue(':estado', $new_estado, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $todo_id, SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        // Responder com JSON para requisições AJAX
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => 'Estado atualizado com sucesso']);
                            exit;
                        }
                        $success_message = 'Estado da tarefa atualizado com sucesso!';
                        // Redirecionar para manter o parâmetro tab=todos
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
                        <div class="col-md-3">
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
                        <div class="col-md-3">
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
                        <div class="col-md-3">
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
                        
                        <!-- Coluna: Completada -->
                        <div class="col-md-3">
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
                                $estados_ordem = ['aberta', 'em execução', 'suspensa', 'completada'];
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
    
    // Garantir que o parâmetro tab seja mantido nos formulários
    function ensureFormHasTabParam(form) {
        // Verificar se a URL atual tem o parâmetro tab
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
    
    // Garantir que o formulário de filtro tenha o parâmetro tab
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
        this.classList.add('dragging');
        dragSrcEl = this;
        draggingTask = true;
        
        // Para compatibilidade com Firefox
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
        
        // Adicionar um efeito visual
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
            e.preventDefault(); // Necessário para permitir o drop
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
        e.stopPropagation(); // Evita redirecionamento no Firefox
        e.preventDefault();
        
        this.classList.remove('drag-over');
        
        // Só prosseguir se estamos soltando em um container diferente
        if (dragSrcEl && this !== dragSrcEl.parentNode) {
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
        
        // Manter o parâmetro tab=todos na URL
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
            } else {
                console.error('Erro ao atualizar estado:', data.message);
                alert('Erro ao atualizar estado: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro na requisição AJAX:', error);
            alert('Erro na requisição. Por favor, tente novamente.');
        });
    }
    
    function initDragAndDrop() {
        // Adicionar estilos CSS necessários para o drag and drop
        const style = document.createElement('style');
        style.textContent = `
            .task-card { cursor: move; }
            .dragging { opacity: 0.4; }
            .drag-over { background-color: rgba(0, 0, 0, 0.05); }
            .todo-container { min-height: 100px; }
        `;
        document.head.appendChild(style);
        
        // Configurar cada cartão de tarefa como arrastável
        const taskCards = document.querySelectorAll('.task-card');
        taskCards.forEach(taskCard => {
            taskCard.setAttribute('draggable', 'true');
            
            // Remover eventos antigos para evitar duplicação
            taskCard.removeEventListener('dragstart', handleDragStart);
            taskCard.removeEventListener('dragend', handleDragEnd);
            
            // Adicionar novos event listeners
            taskCard.addEventListener('dragstart', handleDragStart);
            taskCard.addEventListener('dragend', handleDragEnd);
        });
        
        // Configurar containers como áreas de soltar
        const containers = document.querySelectorAll('.todo-container');
        containers.forEach(container => {
            // Remover eventos antigos para evitar duplicação
            container.removeEventListener('dragover', handleDragOver);
            container.removeEventListener('dragenter', handleDragEnter);
            container.removeEventListener('dragleave', handleDragLeave);
            container.removeEventListener('drop', handleDrop);
            
            // Adicionar novos event listeners
            container.addEventListener('dragover', handleDragOver);
            container.addEventListener('dragenter', handleDragEnter);
            container.addEventListener('dragleave', handleDragLeave);
            container.addEventListener('drop', handleDrop);
        });
    }
    
    // Inicializar drag and drop
    initDragAndDrop();
});
</script>