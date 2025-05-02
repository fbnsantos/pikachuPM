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
                } else {
                    $error_message = 'Erro ao excluir tarefa: ' . $db->lastErrorMsg();
                }
            }
        }
    }
    
    // Buscar tarefas do usuário (como autor ou responsável)
    $stmt = $db->prepare('
        SELECT t.*, 
               autor_user.username as autor_nome,
               resp_user.username as responsavel_nome
        FROM todos t
        LEFT JOIN user_tokens autor_user ON t.autor = autor_user.user_id
        LEFT JOIN user_tokens resp_user ON t.responsavel = resp_user.user_id
        WHERE t.autor = :user_id OR t.responsavel = :user_id
        ORDER BY 
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
    ');
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
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
            <p class="mb-0">Seu Token API: <code><?= htmlspecialchars($user_token['token']) ?></code></p>
            <p class="text-muted small">Use este token para acessar a API de ToDos</p>
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
    
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-plus-circle"></i> Nova Tarefa</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label for="titulo" class="form-label">Título da Tarefa*</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descritivo" class="form-label">Descrição</label>
                            <textarea class="form-control" id="descritivo" name="descritivo" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="data_limite" class="form-label">Data Limite</label>
                            <input type="date" class="form-control" id="data_limite" name="data_limite">
                        </div>
                        
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
                            <label for="estado" class="form-label">Estado</label>
                            <select class="form-select" id="estado" name="estado">
                                <option value="aberta" selected>Aberta</option>
                                <option value="em execução">Em Execução</option>
                                <option value="suspensa">Suspensa</option>
                                <option value="completada">Completada</option>
                            </select>
                        </div>
                        
                        <div class="accordion mb-3" id="accordionRedmine">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRedmine" aria-expanded="false" aria-controls="collapseRedmine">
                                        Informações do Redmine (Opcional)
                                    </button>
                                </h2>
                                <div id="collapseRedmine" class="accordion-collapse collapse" data-bs-parent="#accordionRedmine">
                                    <div class="accordion-body">
                                        <div class="mb-3">
                                            <label for="task_id" class="form-label">ID da Tarefa no Redmine</label>
                                            <input type="number" class="form-control" id="task_id" name="task_id">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="todo_issue" class="form-label">ToDo do Issue</label>
                                            <input type="text" class="form-control" id="todo_issue" name="todo_issue">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="milestone_id" class="form-label">ID do Milestone</label>
                                            <input type="number" class="form-control" id="milestone_id" name="milestone_id">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="projeto_id" class="form-label">ID do Projeto</label>
                                            <input type="number" class="form-control" id="projeto_id" name="projeto_id">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle"></i> Adicionar Tarefa
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-info-circle"></i> Informações da API</h5>
                </div>
                <div class="card-body">
                    <p>Use seu token para interagir com a API de ToDos:</p>
                    
                    <div class="mb-3">
                        <h6>Endpoints disponíveis:</h6>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>GET /api/todos.php</strong>
                                    <div class="text-muted small">Listar todas as tarefas</div>
                                </div>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>POST /api/todos.php</strong>
                                    <div class="text-muted small">Adicionar uma nova tarefa</div>
                                </div>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>PUT /api/todos.php</strong>
                                    <div class="text-muted small">Atualizar uma tarefa existente</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    
                    <p class="small text-muted">Inclua seu token no cabeçalho de autorização:<br>
                    <code>Authorization: Bearer <?= htmlspecialchars($user_token['token']) ?></code></p>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-list-check"></i> Suas Tarefas</h5>
                </div>
                <div class="card-body p-0">
                    <?php
                    $tarefas = [];
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $tarefas[] = $row;
                    }
                    
                    if (count($tarefas) > 0):
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
                                <?php foreach ($tarefas as $tarefa): ?>
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
                                                    <form method="post" action="" class="d-inline update-status-form">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="todo_id" value="<?= $tarefa['id'] ?>">
                                                        <input type="hidden" name="new_estado" value="aberta">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-circle text-primary"></i> Marcar como Aberta
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <?php if ($tarefa['estado'] !== 'em execução'): ?>
                                                <li>
                                                    <form method="post" action="" class="d-inline update-status-form">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="todo_id" value="<?= $tarefa['id'] ?>">
                                                        <input type="hidden" name="new_estado" value="em execução">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-play-circle text-info"></i> Marcar como Em Execução
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <?php if ($tarefa['estado'] !== 'suspensa'): ?>
                                                <li>
                                                    <form method="post" action="" class="d-inline update-status-form">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="todo_id" value="<?= $tarefa['id'] ?>">
                                                        <input type="hidden" name="new_estado" value="suspensa">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-pause-circle text-warning"></i> Marcar como Suspensa
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <?php if ($tarefa['estado'] !== 'completada'): ?>
                                                <li>
                                                    <form method="post" action="" class="d-inline update-status-form">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="todo_id" value="<?= $tarefa['id'] ?>">
                                                        <input type="hidden" name="new_estado" value="completada">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-check-circle text-success"></i> Marcar como Completada
                                                        </button>
                                                    </form>
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
                                <?php endforeach; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
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
    
    // Confirmar alteração de status
    document.querySelectorAll('.update-status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm('Tem certeza que deseja alterar o estado desta tarefa?')) {
                e.preventDefault();
            }
        });
    });
});
</script>