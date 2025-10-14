<?php
/**
 * MÓDULO TODOS - Com Kanban Board e Editor Universal
 * Gestão completa de tarefas com drag & drop, markdown, checklist e ficheiros
 */

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar BD
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $db->set_charset('utf8mb4');
} catch (Exception $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Obter todos os utilizadores
$all_users = [];
$stmt = $db->prepare('SELECT user_id, username FROM user_tokens ORDER BY username');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_users[] = $row;
}
$stmt->close();

// PROCESSAR AÇÕES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // MUDAR ESTADO (Drag & Drop do Kanban)
    if ($action === 'change_estado' && isset($_POST['todo_id']) && isset($_POST['new_estado'])) {
        $todo_id = (int)$_POST['todo_id'];
        $new_estado = $_POST['new_estado'];
        
        $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
        if (in_array($new_estado, $valid_estados)) {
            $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('siii', $new_estado, $todo_id, $user_id, $user_id);
            
            if ($stmt->execute()) {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Estado atualizado!']);
                    exit;
                }
                $success_message = '✅ Estado atualizado!';
            } else {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Erro ao atualizar']);
                    exit;
                }
                $error_message = '❌ Erro ao atualizar estado.';
            }
            $stmt->close();
        }
    }
    
    // ADICIONAR NOVA TASK (Via Modal Simples)
    elseif ($action === 'add') {
        $titulo = trim($_POST['titulo']);
        $descritivo = trim($_POST['descritivo'] ?? '');
        $data_limite = $_POST['data_limite'] ?: null;
        $responsavel = !empty($_POST['responsavel']) ? (int)$_POST['responsavel'] : $user_id;
        $estado = 'aberta';
        
        if (!empty($titulo)) {
            $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estado) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssiss', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estado);
            
            if ($stmt->execute()) {
                $success_message = '✅ Tarefa adicionada com sucesso!';
            } else {
                $error_message = '❌ Erro ao adicionar tarefa.';
            }
            $stmt->close();
        }
    }
    
    // ELIMINAR TASK
    elseif ($action === 'delete') {
        $todo_id = (int)$_POST['todo_id'];
        
        $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success_message = '✅ Tarefa eliminada!';
        } else {
            $error_message = '❌ Erro ao eliminar ou sem permissão.';
        }
        $stmt->close();
    }
}

// OBTER TAREFAS
$view_mode = $_GET['view'] ?? 'kanban';
$filter_responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : null;
$show_completed = isset($_GET['show_completed']);

$query = 'SELECT t.*, 
          autor.username as autor_nome,
          resp.username as responsavel_nome
          FROM todos t
          LEFT JOIN user_tokens autor ON t.autor = autor.user_id
          LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
          WHERE 1=1';

$types = '';
$params = [];

if ($filter_responsavel) {
    $query .= ' AND t.responsavel = ?';
    $types .= 'i';
    $params[] = $filter_responsavel;
} else {
    $query .= ' AND (t.autor = ? OR t.responsavel = ?)';
    $types .= 'ii';
    $params[] = $user_id;
    $params[] = $user_id;
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
    t.created_at DESC';

$stmt = $db->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$tarefas = [];
while ($row = $result->fetch_assoc()) {
    $tarefas[] = $row;
}
$stmt->close();

// Agrupar por estado para Kanban
$tarefas_por_estado = [
    'aberta' => [],
    'em execução' => [],
    'suspensa' => [],
    'concluída' => []
];

foreach ($tarefas as $tarefa) {
    if (isset($tarefas_por_estado[$tarefa['estado']])) {
        $tarefas_por_estado[$tarefa['estado']][] = $tarefa;
    }
}

// Estatísticas
$stats = ['total' => count($tarefas), 'abertas' => 0, 'execucao' => 0, 'concluidas' => 0];
foreach ($tarefas as $t) {
    if ($t['estado'] === 'aberta') $stats['abertas']++;
    if ($t['estado'] === 'em execução') $stats['execucao']++;
    if ($t['estado'] === 'concluída') $stats['concluidas']++;
}
?>

<style>
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.stats-item {
    text-align: center;
}

.stats-number {
    font-size: 2rem;
    font-weight: bold;
}

.kanban-card {
    cursor: move;
    transition: all 0.2s;
    border-left: 4px solid transparent;
    margin-bottom: 10px;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.kanban-card.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
}

.kanban-column {
    min-height: 400px;
    max-height: 70vh;
    overflow-y: auto;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 0 0 8px 8px;
}

.kanban-column.drag-over {
    background: #e3f2fd;
    border: 2px dashed #2196f3;
}

.kanban-card[data-estado="aberta"] {
    border-left-color: #6c757d;
}

.kanban-card[data-estado="em execução"] {
    border-left-color: #0d6efd;
}

.kanban-card[data-estado="suspensa"] {
    border-left-color: #ffc107;
}

.kanban-card[data-estado="concluída"] {
    border-left-color: #198754;
}

.task-card {
    transition: all 0.2s;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.task-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}
</style>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-check2-square"></i> Tarefas (ToDos)</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle"></i> Nova Tarefa
        </button>
    </div>
    
    <!-- Mensagens -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-card">
        <div class="row">
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['total'] ?></div>
                <div class="stats-label">Total</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['abertas'] ?></div>
                <div class="stats-label">Abertas</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['execucao'] ?></div>
                <div class="stats-label">Em Execução</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['concluidas'] ?></div>
                <div class="stats-label">Concluídas</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros e Visualização -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Visualização</label>
                    <div class="btn-group w-100">
                        <a href="?tab=todos&view=kanban<?= $filter_responsavel ? '&responsavel='.$filter_responsavel : '' ?><?= $show_completed ? '&show_completed=1' : '' ?>" 
                           class="btn btn-<?= $view_mode === 'kanban' ? 'primary' : 'outline-primary' ?>">
                            <i class="bi bi-kanban"></i> Kanban
                        </a>
                        <a href="?tab=todos&view=lista<?= $filter_responsavel ? '&responsavel='.$filter_responsavel : '' ?><?= $show_completed ? '&show_completed=1' : '' ?>" 
                           class="btn btn-<?= $view_mode === 'lista' ? 'primary' : 'outline-primary' ?>">
                            <i class="bi bi-list-ul"></i> Lista
                        </a>
                    </div>
                </div>
                <div class="col-md-4">
                    <form method="GET" class="d-inline">
                        <input type="hidden" name="tab" value="todos">
                        <input type="hidden" name="view" value="<?= $view_mode ?>">
                        <?php if ($show_completed): ?>
                            <input type="hidden" name="show_completed" value="1">
                        <?php endif; ?>
                        <label class="form-label">Filtrar por Responsável</label>
                        <select name="responsavel" class="form-select" onchange="this.form.submit()">
                            <option value="">Minhas Tarefas</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $filter_responsavel == $u['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="GET">
                        <input type="hidden" name="tab" value="todos">
                        <input type="hidden" name="view" value="<?= $view_mode ?>">
                        <?php if ($filter_responsavel): ?>
                            <input type="hidden" name="responsavel" value="<?= $filter_responsavel ?>">
                        <?php endif; ?>
                        <label class="form-label">&nbsp;</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="show_completed" value="1" 
                                   <?= $show_completed ? 'checked' : '' ?> onchange="this.form.submit()">
                            <label class="form-check-label">Mostrar concluídas</label>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($view_mode === 'kanban'): ?>
        <!-- KANBAN BOARD -->
        <div class="row g-3">
            <?php
            $colunas = [
                'aberta' => ['titulo' => 'Abertas', 'icon' => 'circle', 'color' => 'secondary'],
                'em execução' => ['titulo' => 'Em Execução', 'icon' => 'play-circle', 'color' => 'primary'],
                'suspensa' => ['titulo' => 'Suspensas', 'icon' => 'pause-circle', 'color' => 'warning'],
                'concluída' => ['titulo' => 'Concluídas', 'icon' => 'check-circle', 'color' => 'success']
            ];
            
            foreach ($colunas as $estado => $info):
                $tarefas_coluna = $tarefas_por_estado[$estado];
            ?>
                <div class="col-md-3">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header bg-<?= $info['color'] ?> text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-<?= $info['icon'] ?>"></i>
                                <?= $info['titulo'] ?>
                                <span class="badge bg-light text-dark float-end">
                                    <?= count($tarefas_coluna) ?>
                                </span>
                            </h5>
                        </div>
                        <div class="kanban-column" data-estado="<?= $estado ?>">
                            <?php if (empty($tarefas_coluna)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                    <p class="mt-2 small">Nenhuma tarefa</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tarefas_coluna as $tarefa): ?>
                                    <div class="card kanban-card" 
                                         draggable="true" 
                                         data-task-id="<?= $tarefa['id'] ?>"
                                         data-estado="<?= $tarefa['estado'] ?>">
                                        <div class="card-body p-3">
                                            <h6 class="card-title"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                                            <?php if ($tarefa['descritivo']): ?>
                                                <p class="card-text small text-muted">
                                                    <?= nl2br(htmlspecialchars(substr($tarefa['descritivo'], 0, 80))) ?>
                                                    <?= strlen($tarefa['descritivo']) > 80 ? '...' : '' ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex gap-1 flex-wrap mt-2">
                                                <?php if ($tarefa['data_limite']): ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-calendar"></i> 
                                                        <?= date('d/m', strtotime($tarefa['data_limite'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($tarefa['responsavel_nome']): ?>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($tarefa['responsavel_nome']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="btn-group btn-group-sm w-100 mt-2">
                                                <button class="btn btn-outline-primary edit-task-btn" 
                                                        data-task-id="<?= $tarefa['id'] ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" 
                                                        onclick="confirmarExclusao(<?= $tarefa['id'] ?>, '<?= htmlspecialchars($tarefa['titulo'], ENT_QUOTES) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <!-- VISTA LISTA -->
        <div class="row g-3">
            <?php if (empty($tarefas)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle"></i> Nenhuma tarefa encontrada.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($tarefas as $tarefa): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card task-card h-100">
                            <div class="card-header bg-<?= $tarefa['estado'] === 'concluída' ? 'success' : ($tarefa['estado'] === 'em execução' ? 'primary' : 'warning') ?> text-white">
                                <h6 class="mb-0"><?= htmlspecialchars($tarefa['titulo']) ?></h6>
                            </div>
                            <div class="card-body">
                                <?php if ($tarefa['descritivo']): ?>
                                    <p class="card-text small text-muted">
                                        <?= nl2br(htmlspecialchars(substr($tarefa['descritivo'], 0, 100))) ?>
                                        <?= strlen($tarefa['descritivo']) > 100 ? '...' : '' ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="d-flex gap-1 flex-wrap mt-2">
                                    <?php if ($tarefa['data_limite']): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-calendar"></i> 
                                            <?= date('d/m/Y', strtotime($tarefa['data_limite'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($tarefa['responsavel_nome']): ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-person"></i> 
                                            <?= htmlspecialchars($tarefa['responsavel_nome']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge bg-<?= $tarefa['estado'] === 'concluída' ? 'success' : ($tarefa['estado'] === 'em execução' ? 'primary' : 'warning') ?>">
                                        <?= ucfirst($tarefa['estado']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent">
                                <div class="btn-group btn-group-sm w-100">
                                    <button class="btn btn-outline-primary edit-task-btn" 
                                            data-task-id="<?= $tarefa['id'] ?>">
                                        <i class="bi bi-pencil-square"></i> Editar
                                    </button>
                                    <button class="btn btn-outline-danger" 
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
    <?php endif; ?>
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
                                <option value="">Sem responsável</option>
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
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Adicionar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form oculto para eliminar -->
<form id="delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="todo_id" id="delete-todo-id">
</form>

<script>
// Confirmar exclusão
function confirmarExclusao(id, titulo) {
    if (confirm(`Eliminar "${titulo}"?`)) {
        document.getElementById('delete-todo-id').value = id;
        document.getElementById('delete-form').submit();
    }
}

// Integração com editor universal
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const taskId = this.dataset.taskId;
            if (typeof openTaskEditor === 'function') {
                openTaskEditor(taskId);
            } else {
                alert('Editor não disponível');
            }
        });
    });
    
    // DRAG AND DROP KANBAN
    const kanbanCards = document.querySelectorAll('.kanban-card');
    const kanbanColumns = document.querySelectorAll('.kanban-column');
    
    let draggedCard = null;
    
    kanbanCards.forEach(card => {
        card.addEventListener('dragstart', function(e) {
            draggedCard = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        
        card.addEventListener('dragend', function(e) {
            this.classList.remove('dragging');
            kanbanColumns.forEach(col => col.classList.remove('drag-over'));
        });
    });
    
    kanbanColumns.forEach(column => {
        column.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
            e.dataTransfer.dropEffect = 'move';
            return false;
        });
        
        column.addEventListener('dragleave', function(e) {
            this.classList.remove('drag-over');
        });
        
        column.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('drag-over');
            
            if (draggedCard) {
                const taskId = draggedCard.dataset.taskId;
                const newEstado = this.dataset.estado;
                const oldEstado = draggedCard.dataset.estado;
                
                if (newEstado !== oldEstado) {
                    draggedCard.style.opacity = '0.5';
                    
                    const formData = new FormData();
                    formData.append('action', 'change_estado');
                    formData.append('todo_id', taskId);
                    formData.append('new_estado', newEstado);
                    formData.append('ajax', '1');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Erro: ' + data.error);
                            draggedCard.style.opacity = '1';
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Erro ao mover tarefa');
                        draggedCard.style.opacity = '1';
                    });
                }
            }
        });
    });
});
</script>

<?php
// Incluir editor universal
include __DIR__ . '/../edit_task.php';
?>