<?php
// tabs/todos.php - Gestão de ToDos (VERSÃO COMPLETA E CORRIGIDA)

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';
include __DIR__ . '/todos_milestones.php';

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Criar tabela de tokens se não existir
    $db->query('CREATE TABLE IF NOT EXISTS user_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Criar tabela de tarefas se não existir
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (autor) REFERENCES user_tokens(user_id),
        FOREIGN KEY (responsavel) REFERENCES user_tokens(user_id)
    )');
    
} catch (Exception $e) {
    die("Erro ao conectar à base de dados: " . $e->getMessage());
}

// Verificar e gerar token do usuário
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$stmt = $db->prepare('SELECT token FROM user_tokens WHERE user_id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_token = $result->fetch_assoc();
$stmt->close();

if (!$user_token) {
    $token = bin2hex(random_bytes(16));
    $stmt = $db->prepare('INSERT INTO user_tokens (user_id, username, token) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $user_id, $username, $token);
    $stmt->execute();
    $stmt->close();
    $user_token = ['token' => $token];
}

// ===========================================================================
// ENDPOINT AJAX: Buscar detalhes de tarefa (DEVE VIR ANTES DE QUALQUER HTML)
// ===========================================================================
if (isset($_GET['get_task_details']) && is_numeric($_GET['get_task_details'])) {
    $task_id = (int)$_GET['get_task_details'];
    
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
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'task' => $task]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada']);
    }
    
    $stmt->close();
    $db->close();
    exit;
}

// ===========================================================================
// PROCESSAMENTO DE FORMULÁRIOS
// ===========================================================================
$success_message = '';
$error_message = '';
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ADICIONAR NOVA TAREFA
    if ($_POST['action'] === 'add') {
        $titulo = trim($_POST['titulo'] ?? '');
        $descritivo = trim($_POST['descritivo'] ?? '');
        $data_limite = !empty($_POST['data_limite']) ? trim($_POST['data_limite']) : null;
        $responsavel = !empty($_POST['responsavel']) ? (int)$_POST['responsavel'] : $user_id;
        $task_id = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        $todo_issue = trim($_POST['todo_issue'] ?? '');
        $milestone_id = !empty($_POST['milestone_id']) ? (int)$_POST['milestone_id'] : null;
        $projeto_id = !empty($_POST['projeto_id']) ? (int)$_POST['projeto_id'] : null;
        $estado = trim($_POST['estado'] ?? 'aberta');
        
        if (empty($titulo)) {
            $error_message = 'O título da tarefa é obrigatório.';
        } else {
            $query = 'INSERT INTO todos (
                titulo, descritivo, data_limite, autor, responsavel, 
                task_id, todo_issue, milestone_id, projeto_id, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            
            $stmt = $db->prepare($query);
            $stmt->bind_param(
                'sssiiisiis', 
                $titulo, $descritivo, $data_limite, $user_id, $responsavel, 
                $task_id, $todo_issue, $milestone_id, $projeto_id, $estado
            );
            
            if ($stmt->execute()) {
                $success_message = 'Tarefa adicionada com sucesso!';
                $stmt->close();
                if (!empty($current_tab)) {
                    header('Location: ?tab=' . urlencode($current_tab));
                    exit;
                }
            } else {
                $error_message = 'Erro ao adicionar tarefa: ' . $db->error;
                $stmt->close();
            }
        }
    }
    
    // EDITAR TAREFA EXISTENTE
    elseif ($_POST['action'] === 'edit_task') {
        $todo_id = (int)$_POST['todo_id'];
        $titulo = trim($_POST['titulo'] ?? '');
        $descritivo = trim($_POST['descritivo'] ?? '');
        $data_limite = !empty($_POST['data_limite']) ? trim($_POST['data_limite']) : null;
        $responsavel = !empty($_POST['responsavel']) ? (int)$_POST['responsavel'] : null;
        $task_id = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        $todo_issue = trim($_POST['todo_issue'] ?? '');
        $milestone_id = !empty($_POST['milestone_id']) ? (int)$_POST['milestone_id'] : null;
        $projeto_id = !empty($_POST['projeto_id']) ? (int)$_POST['projeto_id'] : null;
        $estado = trim($_POST['estado'] ?? 'aberta');
        
        if (empty($titulo)) {
            $error_message = 'O título da tarefa é obrigatório.';
        } else {
            // Verificar permissão
            $stmt = $db->prepare('SELECT id FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $stmt->close();
                
                $query = 'UPDATE todos SET 
                    titulo = ?, descritivo = ?, data_limite = ?, responsavel = ?, 
                    task_id = ?, todo_issue = ?, milestone_id = ?, projeto_id = ?, estado = ?
                    WHERE id = ?';
                
                $stmt = $db->prepare($query);
                $stmt->bind_param(
                    'sssiiisisi', 
                    $titulo, $descritivo, $data_limite, $responsavel, 
                    $task_id, $todo_issue, $milestone_id, $projeto_id, $estado, $todo_id
                );
                
                if ($stmt->execute()) {
                    $success_message = 'Tarefa atualizada com sucesso!';
                    $stmt->close();
                    if (!empty($current_tab)) {
                        header('Location: ?tab=' . urlencode($current_tab));
                        exit;
                    }
                } else {
                    $error_message = 'Erro ao atualizar tarefa: ' . $db->error;
                    $stmt->close();
                }
            } else {
                $stmt->close();
                $error_message = 'Não tem permissão para editar esta tarefa.';
            }
        }
    }
    
    // ATUALIZAR ESTADO (DRAG AND DROP) - COM CORREÇÃO DE AJAX
    elseif ($_POST['action'] === 'drag_update_status') {
        $todo_id = (int)$_POST['todo_id'];
        $new_estado = trim($_POST['new_estado']);
        
        $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
        
        if (in_array($new_estado, $valid_estados)) {
            $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('siii', $new_estado, $todo_id, $user_id, $user_id);
            
            if ($stmt->execute()) {
                // CORREÇÃO: Sempre retornar JSON para AJAX
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Estado atualizado com sucesso']);
                $stmt->close();
                $db->close();
                exit; // CRÍTICO
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $db->error]);
                $stmt->close();
                $db->close();
                exit;
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Estado inválido']);
            $db->close();
            exit;
        }
    }
    
    // ATUALIZAR ESTADO (FORM TRADICIONAL)
    elseif ($_POST['action'] === 'update_status') {
        $todo_id = (int)$_POST['todo_id'];
        $new_estado = trim($_POST['new_estado']);
        
        $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
        
        if (in_array($new_estado, $valid_estados)) {
            $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ?');
            $stmt->bind_param('si', $new_estado, $todo_id);
            
            if ($stmt->execute()) {
                $success_message = 'Estado atualizado com sucesso!';
                $stmt->close();
                if (!empty($current_tab)) {
                    header('Location: ?tab=' . urlencode($current_tab));
                    exit;
                }
            } else {
                $error_message = 'Erro ao atualizar estado: ' . $db->error;
                $stmt->close();
            }
        } else {
            $error_message = 'Estado inválido.';
        }
    }
    
    // EXCLUIR TAREFA
    elseif ($_POST['action'] === 'delete') {
        $todo_id = (int)$_POST['todo_id'];
        
        $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
        
        if ($stmt->execute()) {
            $success_message = 'Tarefa excluída com sucesso!';
            $stmt->close();
            if (!empty($current_tab)) {
                header('Location: ?tab=' . urlencode($current_tab));
                exit;
            }
        } else {
            $error_message = 'Erro ao excluir tarefa: ' . $db->error;
            $stmt->close();
        }
    }
}

// ===========================================================================
// BUSCAR TAREFAS
// ===========================================================================
$filter_responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : null;
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';

$query = '
    SELECT t.*, 
           autor.username as autor_nome, 
           resp.username as responsavel_nome
    FROM todos t
    LEFT JOIN user_tokens autor ON t.autor = autor.user_id
    LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
    WHERE (t.autor = ? OR t.responsavel = ?)
';

$types = 'ii';
$params = [$user_id, $user_id];

if ($filter_responsavel) {
    $query .= ' AND t.responsavel = ?';
    $types .= 'i';
    $params[] = $filter_responsavel;
}

if (!$show_completed) {
    $query .= ' AND t.estado != "concluída"';
}

$query .= ' ORDER BY 
    CASE 
        WHEN t.estado = "em execução" THEN 1
        WHEN t.estado = "aberta" THEN 2
        WHEN t.estado = "suspensa" THEN 3
        WHEN t.estado = "concluída" THEN 4
        ELSE 5
    END,
    t.data_limite ASC,
    t.created_at DESC
';

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$tarefas = [];
while ($row = $result->fetch_assoc()) {
    $tarefas[] = $row;
}
$stmt->close();

// Buscar lista de utilizadores para dropdown
$users_query = "SELECT user_id, username FROM user_tokens ORDER BY username";
$users_result = $db->query($users_query);
$all_users = [];
while ($user_row = $users_result->fetch_assoc()) {
    $all_users[] = $user_row;
}

?>

<!-- HTML PRINCIPAL -->
<div class="container-fluid">
    
    <!-- Mensagens -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-check2-square"></i> Minhas Tarefas</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle"></i> Nova Tarefa
        </button>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="todos">
                <div class="col-md-4">
                    <label class="form-label">Responsável</label>
                    <select name="responsavel" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $filter_responsavel == $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_completed" value="1" 
                               <?= $show_completed ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label">Mostrar concluídas</label>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Tarefas -->
    <div class="row">
        <?php if (empty($tarefas)): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Nenhuma tarefa encontrada.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($tarefas as $tarefa): ?>
                <div class="col-md-4 mb-3">
                    <div class="card h-100">
                        <div class="card-header bg-<?= $tarefa['estado'] === 'concluída' ? 'success' : ($tarefa['estado'] === 'em execução' ? 'primary' : 'secondary') ?> text-white">
                            <h6 class="mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                        </div>
                        <div class="card-body">
                            <?php if ($tarefa['descritivo']): ?>
                                <p class="card-text"><?= nl2br(htmlspecialchars(substr($tarefa['descritivo'], 0, 150))) ?></p>
                            <?php endif; ?>
                            
                            <div class="mb-2">
                                <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($tarefa['estado'])) ?></span>
                                <?php if ($tarefa['data_limite']): ?>
                                    <span class="badge bg-info"><?= date('d/m/Y', strtotime($tarefa['data_limite'])) ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($tarefa['responsavel_nome']): ?>
                                <small class="text-muted">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($tarefa['responsavel_nome']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group btn-group-sm w-100">
                                <button type="button" class="btn btn-outline-primary edit-task-btn" 
                                        data-task-id="<?= $tarefa['id'] ?>">
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                                <button type="button" class="btn btn-outline-danger" 
                                        onclick="confirmarExclusao(<?= $tarefa['id'] ?>, '<?= htmlspecialchars($tarefa['titulo'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Nova Tarefa -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nova Tarefa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" name="descritivo" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Limite</label>
                            <input type="date" class="form-control" name="data_limite">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select class="form-select" name="responsavel">
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $user_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Tarefa -->
<div class="modal fade" id="editTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_task">
                <input type="hidden" name="todo_id" id="edit_todo_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Tarefa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" id="edit_titulo" name="titulo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" id="edit_descritivo" name="descritivo" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Limite</label>
                            <input type="date" class="form-control" id="edit_data_limite" name="data_limite">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estado</label>
                            <select class="form-select" id="edit_estado" name="estado">
                                <option value="aberta">Aberta</option>
                                <option value="em execução">Em Execução</option>
                                <option value="suspensa">Suspensa</option>
                                <option value="concluída">Concluída</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select class="form-select" id="edit_responsavel" name="responsavel">
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>">
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form oculto para excluir -->
<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="todo_id" id="delete-todo-id">
</form>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Função para mostrar notificações
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} position-fixed top-0 start-50 translate-middle-x mt-3`;
        toast.style.zIndex = '9999';
        toast.style.minWidth = '300px';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
    
    // Editar tarefa
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.dataset.taskId;
            
            fetch(`?tab=todos&get_task_details=${taskId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.task) {
                    const task = data.task;
                    document.getElementById('edit_todo_id').value = task.id;
                    document.getElementById('edit_titulo').value = task.titulo || '';
                    document.getElementById('edit_descritivo').value = task.descritivo || '';
                    document.getElementById('edit_data_limite').value = task.data_limite || '';
                    document.getElementById('edit_estado').value = task.estado || 'aberta';
                    document.getElementById('edit_responsavel').value = task.responsavel || '';
                    
                    const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
                    modal.show();
                } else {
                    showToast('Erro ao carregar tarefa', 'danger');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro ao carregar tarefa', 'danger');
            });
        });
    });
});

// Confirmar exclusão
function confirmarExclusao(id, titulo) {
    if (confirm(`Tem certeza que deseja excluir: "${titulo}"?`)) {
        document.getElementById('delete-todo-id').value = id;
        document.getElementById('delete-form').submit();
    }
}
</script>