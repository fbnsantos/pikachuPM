<?php
// tabs/phd_kanban.php - Gestão de Tarefas do Doutoramento com Kanban Board

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Criar coluna 'estagio' na tabela todos se não existir
    $check_column = $db->query("SHOW COLUMNS FROM todos LIKE 'estagio'");
    if ($check_column->num_rows == 0) {
        $db->query("ALTER TABLE todos ADD COLUMN estagio VARCHAR(20) DEFAULT 'pensada' AFTER estado");
    }
    
} catch (Exception $e) {
    die("Erro ao conectar à base de dados: " . $e->getMessage());
}

// Processar ações
$success_message = '';
$error_message = '';
$user_id = $_SESSION['user_id'];

// Adicionar nova tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_task') {
    $titulo = trim($_POST['titulo']);
    $descritivo = trim($_POST['descritivo']);
    $data_limite = !empty($_POST['data_limite']) ? $_POST['data_limite'] : null;
    $responsavel = !empty($_POST['responsavel']) ? intval($_POST['responsavel']) : null;
    $estagio = isset($_POST['estagio']) ? $_POST['estagio'] : 'pensada';
    
    if (!empty($titulo)) {
        $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estagio, estado) VALUES (?, ?, ?, ?, ?, ?, "aberta")');
        $stmt->bind_param('sssiss', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estagio);
        
        if ($stmt->execute()) {
            $success_message = "Tarefa adicionada com sucesso!";
        } else {
            $error_message = "Erro ao adicionar tarefa: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "O título da tarefa é obrigatório.";
    }
}

// Atualizar estágio da tarefa (via AJAX ou POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage') {
    $task_id = intval($_POST['task_id']);
    $new_stage = $_POST['new_stage'];
    
    $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
    if (in_array($new_stage, $valid_stages)) {
        $stmt = $db->prepare('UPDATE todos SET estagio = ? WHERE id = ?');
        $stmt->bind_param('si', $new_stage, $task_id);
        
        if ($stmt->execute()) {
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => true]);
                exit;
            }
            $success_message = "Estágio atualizado com sucesso!";
        } else {
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
                exit;
            }
            $error_message = "Erro ao atualizar estágio: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Eliminar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    $task_id = intval($_POST['task_id']);
    
    $stmt = $db->prepare('DELETE FROM todos WHERE id = ?');
    $stmt->bind_param('i', $task_id);
    
    if ($stmt->execute()) {
        $success_message = "Tarefa eliminada com sucesso!";
    } else {
        $error_message = "Erro ao eliminar tarefa: " . $stmt->error;
    }
    $stmt->close();
}

// Obter utilizador selecionado do dropdown (padrão: utilizador logado)
$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user_id;

// Obter todos os utilizadores para o dropdown
$users_query = "SELECT DISTINCT ut.user_id, ut.username 
                FROM user_tokens ut 
                ORDER BY ut.username";
$users_result = $db->query($users_query);
$all_users = [];
while ($row = $users_result->fetch_assoc()) {
    $all_users[] = $row;
}

// Obter tarefas do utilizador selecionado agrupadas por estágio
$stages = ['pensada', 'execucao', 'espera', 'concluida'];
$tasks_by_stage = [];

foreach ($stages as $stage) {
    $stmt = $db->prepare('
        SELECT t.*, 
               autor.username as autor_nome, 
               resp.username as responsavel_nome
        FROM todos t
        LEFT JOIN user_tokens autor ON t.autor = autor.user_id
        LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
        WHERE t.estagio = ? AND (t.autor = ? OR t.responsavel = ?)
        ORDER BY t.created_at DESC
    ');
    $stmt->bind_param('sii', $stage, $selected_user, $selected_user);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tasks_by_stage[$stage] = [];
    while ($row = $result->fetch_assoc()) {
        $tasks_by_stage[$stage][] = $row;
    }
    $stmt->close();
}

?>

<!-- Mensagens de sucesso/erro -->
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

<!-- Cabeçalho e controles -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-kanban"></i> Gestão de Tarefas - Doutoramento</h2>
    <div class="d-flex gap-2">
        <!-- Dropdown para selecionar utilizador -->
        <select class="form-select" id="userSelect" style="width: auto;">
            <?php foreach ($all_users as $u): ?>
                <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $selected_user ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['username']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <!-- Botão para adicionar tarefa -->
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle"></i> Nova Tarefa
        </button>
    </div>
</div>

<!-- Kanban Board -->
<div class="row g-3">
    <?php 
    $stage_names = [
        'pensada' => ['title' => 'Pensadas', 'icon' => 'lightbulb', 'color' => 'secondary'],
        'execucao' => ['title' => 'Em Execução', 'icon' => 'play-circle', 'color' => 'primary'],
        'espera' => ['title' => 'Em Espera', 'icon' => 'pause-circle', 'color' => 'warning'],
        'concluida' => ['title' => 'Concluídas', 'icon' => 'check-circle', 'color' => 'success']
    ];
    
    foreach ($stages as $stage): 
        $stage_info = $stage_names[$stage];
    ?>
    <div class="col-md-3">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-<?= $stage_info['color'] ?> text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $stage_info['icon'] ?>"></i> 
                    <?= $stage_info['title'] ?>
                    <span class="badge bg-light text-dark float-end">
                        <?= count($tasks_by_stage[$stage]) ?>
                    </span>
                </h5>
            </div>
            <div class="card-body kanban-column" data-stage="<?= $stage ?>" style="min-height: 400px; max-height: 70vh; overflow-y: auto;">
                <?php if (empty($tasks_by_stage[$stage])): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <p class="mt-2">Nenhuma tarefa</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks_by_stage[$stage] as $task): ?>
                        <div class="card mb-2 task-card" data-task-id="<?= $task['id'] ?>" draggable="true">
                            <div class="card-body p-2">
                                <h6 class="card-title mb-1">
                                    <?= htmlspecialchars($task['titulo']) ?>
                                </h6>
                                <?php if (!empty($task['descritivo'])): ?>
                                    <p class="card-text small text-muted mb-2">
                                        <?= htmlspecialchars(substr($task['descritivo'], 0, 100)) ?>
                                        <?= strlen($task['descritivo']) > 100 ? '...' : '' ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <?php if ($task['data_limite']): ?>
                                            <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                        <?php endif; ?>
                                    </small>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-info btn-sm view-task-btn" 
                                                data-task-id="<?= $task['id'] ?>"
                                                title="Ver detalhes">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-task-btn" 
                                                data-task-id="<?= $task['id'] ?>"
                                                title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <?php if ($task['responsavel_nome']): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-light text-dark">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($task['responsavel_nome']) ?>
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
    <?php endforeach; ?>
</div>

<!-- Modal para adicionar tarefa -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_task">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titulo" class="form-label">Título *</label>
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
                            <option value="">Nenhum</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $user_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="estagio" class="form-label">Estágio Inicial</label>
                        <select class="form-select" id="estagio" name="estagio">
                            <option value="pensada">Pensada</option>
                            <option value="execucao">Em Execução</option>
                            <option value="espera">Em Espera</option>
                            <option value="concluida">Concluída</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar Tarefa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal para ver detalhes da tarefa -->
<div class="modal fade" id="viewTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye"></i> Detalhes da Tarefa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">A carregar...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Estilos CSS -->
<style>
.task-card {
    cursor: move;
    transition: all 0.3s ease;
}

.task-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    transform: translateY(-2px);
}

.task-card.dragging {
    opacity: 0.5;
}

.kanban-column {
    background-color: #f8f9fa;
}

.kanban-column.drag-over {
    background-color: #e9ecef;
    border: 2px dashed #6c757d;
}
</style>

<!-- JavaScript para Drag & Drop e funcionalidades -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Seletor de utilizador
    const userSelect = document.getElementById('userSelect');
    if (userSelect) {
        userSelect.addEventListener('change', function() {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('user_id', this.value);
            window.location.search = urlParams.toString();
        });
    }
    
    // Drag and Drop
    const taskCards = document.querySelectorAll('.task-card');
    const columns = document.querySelectorAll('.kanban-column');
    
    taskCards.forEach(card => {
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
    });
    
    columns.forEach(column => {
        column.addEventListener('dragover', handleDragOver);
        column.addEventListener('drop', handleDrop);
        column.addEventListener('dragleave', handleDragLeave);
    });
    
    let draggedElement = null;
    
    function handleDragStart(e) {
        draggedElement = this;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.innerHTML);
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        columns.forEach(col => col.classList.remove('drag-over'));
    }
    
    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        this.classList.add('drag-over');
        e.dataTransfer.dropEffect = 'move';
        return false;
    }
    
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        if (e.stopPropagation) {
            e.stopPropagation();
        }
        
        this.classList.remove('drag-over');
        
        if (draggedElement && draggedElement !== this) {
            const taskId = draggedElement.dataset.taskId;
            const newStage = this.dataset.stage;
            
            // Atualizar via AJAX
            const formData = new FormData();
            formData.append('action', 'update_stage');
            formData.append('task_id', taskId);
            formData.append('new_stage', newStage);
            formData.append('ajax', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Recarregar página para atualizar contadores
                    location.reload();
                } else {
                    alert('Erro ao atualizar estágio: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao atualizar estágio');
            });
        }
        
        return false;
    }
    
    // Botões de eliminar tarefa
    document.querySelectorAll('.delete-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Tem a certeza que deseja eliminar esta tarefa?')) {
                const taskId = this.dataset.taskId;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_task">
                    <input type="hidden" name="task_id" value="${taskId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
    
    // Botões de ver detalhes
    document.querySelectorAll('.view-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.dataset.taskId;
            
            // Buscar detalhes da tarefa via AJAX
            fetch(`get_task_details.php?id=${taskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const task = data.task;
                        const content = `
                            <div class="task-details">
                                <h4>${task.titulo}</h4>
                                ${task.descritivo ? `<p class="text-muted">${task.descritivo}</p>` : ''}
                                <hr>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Autor:</strong> ${task.autor_nome || 'N/A'}</p>
                                        <p><strong>Responsável:</strong> ${task.responsavel_nome || 'N/A'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Data Limite:</strong> ${task.data_limite ? new Date(task.data_limite).toLocaleDateString('pt-PT') : 'N/A'}</p>
                                        <p><strong>Estágio:</strong> <span class="badge bg-info">${task.estagio}</span></p>
                                    </div>
                                </div>
                                <hr>
                                <p class="small text-muted">
                                    <strong>Criada em:</strong> ${new Date(task.created_at).toLocaleString('pt-PT')}<br>
                                    <strong>Atualizada em:</strong> ${new Date(task.updated_at).toLocaleString('pt-PT')}
                                </p>
                            </div>
                        `;
                        document.getElementById('taskDetailsContent').innerHTML = content;
                    } else {
                        document.getElementById('taskDetailsContent').innerHTML = 
                            '<div class="alert alert-danger">Erro ao carregar detalhes da tarefa.</div>';
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    document.getElementById('taskDetailsContent').innerHTML = 
                        '<div class="alert alert-danger">Erro ao carregar detalhes da tarefa.</div>';
                });
            
            const modal = new bootstrap.Modal(document.getElementById('viewTaskModal'));
            modal.show();
        });
    });
});
</script>