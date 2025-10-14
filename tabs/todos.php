<?php
/**
 * MÓDULO TODOS - VERSÃO FINAL COMPLETA
 * - Kanban Board com Drag & Drop
 * - Modal avançado para criar tasks
 * - Editor universal para editar
 * - Suporte completo à API
 * - Checklist e ficheiros
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

// Verificar se há mensagem de sucesso via GET
if (isset($_GET['success'])) {
    $success_message = '✅ Tarefa criada com sucesso!';
}

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
    
    // MUDAR ESTADO (Drag & Drop)
    if ($action === 'change_estado') {
        $todo_id = (int)$_POST['todo_id'];
        $new_estado = $_POST['new_estado'];
        
        $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
        if (in_array($new_estado, $valid_estados)) {
            $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('siii', $new_estado, $todo_id, $user_id, $user_id);
            
            if ($stmt->execute()) {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }
                $success_message = '✅ Estado atualizado!';
            } else {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Erro']);
                    exit;
                }
            }
            $stmt->close();
        }
    }
    
    // ADICIONAR TASK COMPLETA
    elseif ($action === 'add_complete') {
        $titulo = trim($_POST['titulo']);
        $descritivo = trim($_POST['descritivo'] ?? '');
        $data_limite = $_POST['data_limite'] ?: null;
        $responsavel = !empty($_POST['responsavel']) ? (int)$_POST['responsavel'] : null;
        $estado = $_POST['estado'] ?? 'aberta';
        $projeto_id = !empty($_POST['projeto_id']) ? (int)$_POST['projeto_id'] : null;
        $milestone_id = !empty($_POST['milestone_id']) ? (int)$_POST['milestone_id'] : null;
        $task_id = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        $todo_issue = trim($_POST['todo_issue'] ?? '');
        
        if (!empty($titulo)) {
            $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estado, projeto_id, milestone_id, task_id, todo_issue) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssiissiis', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estado, $projeto_id, $milestone_id, $task_id, $todo_issue);
            
            if ($stmt->execute()) {
                $new_todo_id = $stmt->insert_id;
                $stmt->close();
                
                // Inserir checklist
                if (isset($_POST['checklist'])) {
                    $checklist = json_decode($_POST['checklist'], true);
                    if (is_array($checklist) && !empty($checklist)) {
                        $stmt_check = $db->prepare('INSERT INTO task_checklist (todo_id, item_text, is_checked, position) VALUES (?, ?, ?, ?)');
                        foreach ($checklist as $index => $item) {
                            if (!empty($item['text'])) {
                                $is_checked = $item['checked'] ? 1 : 0;
                                $stmt_check->bind_param('isii', $new_todo_id, $item['text'], $is_checked, $index);
                                $stmt_check->execute();
                            }
                        }
                        $stmt_check->close();
                    }
                }
                
                header('Location: ?tab=todos&success=1');
                exit;
            } else {
                $error_message = '❌ Erro: ' . $db->error;
                $stmt->close();
            }
        }
    }
    
    // ELIMINAR
    elseif ($action === 'delete') {
        $todo_id = (int)$_POST['todo_id'];
        $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success_message = '✅ Eliminada!';
        }
        $stmt->close();
    }
}

// OBTER TAREFAS
$view_mode = $_GET['view'] ?? 'kanban';
$filter_responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : null;
$show_completed = isset($_GET['show_completed']);

$query = 'SELECT t.*, autor.username as autor_nome, resp.username as responsavel_nome
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

$query .= ' ORDER BY CASE WHEN t.estado = "em execução" THEN 1 WHEN t.estado = "aberta" THEN 2 ELSE 3 END, t.data_limite ASC';

$stmt = $db->prepare($query);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$tarefas = [];
while ($row = $result->fetch_assoc()) {
    $tarefas[] = $row;
}
$stmt->close();

// Agrupar por estado
$tarefas_por_estado = ['aberta' => [], 'em execução' => [], 'suspensa' => [], 'concluída' => []];
foreach ($tarefas as $t) {
    if (isset($tarefas_por_estado[$t['estado']])) {
        $tarefas_por_estado[$t['estado']][] = $t;
    }
}

// Stats
$stats = ['total' => count($tarefas), 'abertas' => count($tarefas_por_estado['aberta']), 
          'execucao' => count($tarefas_por_estado['em execução']), 
          'concluidas' => count($tarefas_por_estado['concluída'])];
?>

<style>
.stats-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
.stats-item { text-align: center; }
.stats-number { font-size: 2rem; font-weight: bold; }
.kanban-card { cursor: move; transition: all 0.2s; border-left: 4px solid transparent; margin-bottom: 10px; }
.kanban-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.kanban-card.dragging { opacity: 0.5; }
.kanban-column { min-height: 400px; max-height: 70vh; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
.kanban-column.drag-over { background: #e3f2fd; border: 2px dashed #2196f3; }
.kanban-card[data-estado="aberta"] { border-left-color: #6c757d; }
.kanban-card[data-estado="em execução"] { border-left-color: #0d6efd; }
.kanban-card[data-estado="suspensa"] { border-left-color: #ffc107; }
.kanban-card[data-estado="concluída"] { border-left-color: #198754; }
.task-card { transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.task-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.15); }
.markdown-toolbar-simple { display: flex; gap: 5px; padding: 8px; background: #f5f5f5; border-radius: 8px; margin-bottom: 10px; }
.add-checklist-item { display: flex; align-items: center; gap: 10px; padding: 10px; background: white; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 8px; }
.add-checklist-item input[type="text"] { flex: 1; border: 1px solid #ddd; padding: 8px; border-radius: 4px; }
</style>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-check2-square"></i> Tarefas (ToDos)</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModalComplete">
            <i class="bi bi-plus-circle"></i> Nova Tarefa
        </button>
    </div>
    
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
                <div>Total</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['abertas'] ?></div>
                <div>Abertas</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['execucao'] ?></div>
                <div>Em Execução</div>
            </div>
            <div class="col-md-3 stats-item">
                <div class="stats-number"><?= $stats['concluidas'] ?></div>
                <div>Concluídas</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
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
                    <form method="GET">
                        <input type="hidden" name="tab" value="todos">
                        <input type="hidden" name="view" value="<?= $view_mode ?>">
                        <?php if ($show_completed): ?><input type="hidden" name="show_completed" value="1"><?php endif; ?>
                        <label class="form-label">Responsável</label>
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
                        <?php if ($filter_responsavel): ?><input type="hidden" name="responsavel" value="<?= $filter_responsavel ?>"><?php endif; ?>
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
                    <div class="card shadow-sm">
                        <div class="card-header bg-<?= $info['color'] ?> text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-<?= $info['icon'] ?>"></i>
                                <?= $info['titulo'] ?>
                                <span class="badge bg-light text-dark float-end"><?= count($tarefas_coluna) ?></span>
                            </h6>
                        </div>
                        <div class="kanban-column" data-estado="<?= $estado ?>">
                            <?php if (empty($tarefas_coluna)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                    <p class="mt-2 small">Nenhuma tarefa</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tarefas_coluna as $t): ?>
                                    <div class="card kanban-card" draggable="true" 
                                         data-task-id="<?= $t['id'] ?>" data-estado="<?= $t['estado'] ?>">
                                        <div class="card-body p-3">
                                            <h6><?= htmlspecialchars($t['titulo']) ?></h6>
                                            <?php if ($t['descritivo']): ?>
                                                <p class="small text-muted mb-2">
                                                    <?= nl2br(htmlspecialchars(substr($t['descritivo'], 0, 60))) ?>...
                                                </p>
                                            <?php endif; ?>
                                            <div class="d-flex gap-1 flex-wrap mb-2">
                                                <?php if ($t['data_limite']): ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-calendar"></i> <?= date('d/m', strtotime($t['data_limite'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($t['responsavel_nome']): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($t['responsavel_nome']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="btn-group btn-group-sm w-100">
                                                <button class="btn btn-sm btn-outline-primary edit-task-btn" data-task-id="<?= $t['id'] ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        onclick="confirmarExclusao(<?= $t['id'] ?>, '<?= htmlspecialchars($t['titulo'], ENT_QUOTES) ?>')">
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
            <?php foreach ($tarefas as $t): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card task-card">
                        <div class="card-header bg-<?= $t['estado'] === 'concluída' ? 'success' : ($t['estado'] === 'em execução' ? 'primary' : 'warning') ?> text-white">
                            <h6 class="mb-0"><?= htmlspecialchars($t['titulo']) ?></h6>
                        </div>
                        <div class="card-body">
                            <?php if ($t['descritivo']): ?>
                                <p class="small text-muted"><?= nl2br(htmlspecialchars(substr($t['descritivo'], 0, 100))) ?>...</p>
                            <?php endif; ?>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($t['data_limite']): ?>
                                    <span class="badge bg-info"><i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($t['data_limite'])) ?></span>
                                <?php endif; ?>
                                <?php if ($t['responsavel_nome']): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($t['responsavel_nome']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group btn-group-sm w-100">
                                <button class="btn btn-outline-primary edit-task-btn" data-task-id="<?= $t['id'] ?>">
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>
                                <button class="btn btn-outline-danger" onclick="confirmarExclusao(<?= $t['id'] ?>, '<?= htmlspecialchars($t['titulo'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL NOVA TAREFA COMPLETO -->
<div class="modal fade" id="addTaskModalComplete" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form id="formNovaTask" method="POST">
                <input type="hidden" name="action" value="add_complete">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title"><i class="bi bi-plus-circle-fill"></i> Nova Tarefa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item">
                            <button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#tab-basico">
                                <i class="bi bi-pencil"></i> Básico
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab-detalhes">
                                <i class="bi bi-info-circle"></i> Detalhes
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab-checklist">
                                <i class="bi bi-check2-square"></i> Checklist
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <!-- TAB BÁSICO -->
                        <div class="tab-pane fade show active" id="tab-basico">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Título *</label>
                                <input type="text" class="form-control form-control-lg" name="titulo" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Descrição</label>
                                <div class="markdown-toolbar-simple">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertMd('**','**','add_desc')"><b>B</b></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertMd('*','*','add_desc')"><i>I</i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertMd('- ','','add_desc')">• Lista</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertMd('`','`','add_desc')">{'<>'}</button>
                                </div>
                                <textarea class="form-control" name="descritivo" id="add_desc" rows="5"></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Data Limite</label>
                                    <input type="date" class="form-control" name="data_limite">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Estado</label>
                                    <select class="form-select" name="estado">
                                        <option value="aberta">🟡 Aberta</option>
                                        <option value="em execução">🔵 Em Execução</option>
                                        <option value="suspensa">🟠 Suspensa</option>
                                        <option value="concluída">🟢 Concluída</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- TAB DETALHES -->
                        <div class="tab-pane fade" id="tab-detalhes">
                            <div class="row">
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
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Task ID (Externo)</label>
                                    <input type="number" class="form-control" name="task_id" placeholder="Ex: 1234">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Issue URL</label>
                                    <input type="text" class="form-control" name="todo_issue" placeholder="https://github.com/user/repo/issues/123">
                                </div>
                            </div>
                        </div>
                        
                        <!-- TAB CHECKLIST -->
                        <div class="tab-pane fade" id="tab-checklist">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Adicione items à checklist
                            </div>
                            <div id="add-checklist-container"></div>
                            <button type="button" class="btn btn-success" onclick="addCheckItem()">
                                <i class="bi bi-plus-circle"></i> Adicionar Item
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-plus-circle-fill"></i> Criar Tarefa
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="todo_id" id="delete-todo-id">
</form>

<script>
let checklistItems = [];

function insertMd(b, a, id) {
    const t = document.getElementById(id);
    const start = t.selectionStart, end = t.selectionEnd;
    const text = t.value, sel = text.substring(start, end);
    t.value = text.substring(0, start) + b + sel + a + text.substring(end);
    const pos = start + b.length + sel.length + a.length;
    t.setSelectionRange(pos, pos);
    t.focus();
}

function addCheckItem() {
    const idx = checklistItems.length;
    checklistItems.push({text: '', checked: false});
    const div = document.createElement('div');
    div.className = 'add-checklist-item';
    div.innerHTML = `
        <input type="checkbox" disabled>
        <input type="text" placeholder="Descrição..." onchange="checklistItems[${idx}].text=this.value">
        <button type="button" class="btn btn-sm btn-danger" onclick="removeCheckItem(${idx})">
            <i class="bi bi-trash"></i>
        </button>
    `;
    document.getElementById('add-checklist-container').appendChild(div);
}

function removeCheckItem(idx) {
    checklistItems.splice(idx, 1);
    renderChecklist();
}

function renderChecklist() {
    const c = document.getElementById('add-checklist-container');
    c.innerHTML = '';
    checklistItems.forEach((item, i) => {
        const div = document.createElement('div');
        div.className = 'add-checklist-item';
        div.innerHTML = `
            <input type="checkbox" disabled>
            <input type="text" value="${item.text}" onchange="checklistItems[${i}].text=this.value">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeCheckItem(${i})">
                <i class="bi bi-trash"></i>
            </button>
        `;
        c.appendChild(div);
    });
}

document.getElementById('formNovaTask')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('checklist', JSON.stringify(checklistItems));
    fetch(window.location.href, {method: 'POST', body: fd})
        .then(() => location.reload());
});

function confirmarExclusao(id, titulo) {
    if (confirm(`Eliminar "${titulo}"?`)) {
        document.getElementById('delete-todo-id').value = id;
        document.getElementById('delete-form').submit();
    }
}

// Editor Universal - Aguardar carregamento
document.addEventListener('DOMContentLoaded', function() {
    // Verificar se editor foi carregado
    console.log('openTaskEditor disponível?', typeof openTaskEditor);
    
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const id = this.dataset.taskId;
            console.log('Tentando abrir editor para task:', id);
            
            if (typeof openTaskEditor === 'function') {
                openTaskEditor(id);
            } else {
                console.error('openTaskEditor não está definido!');
                alert('❌ Editor não disponível. Verifique:\n1. Se install_task_editor.php foi executado\n2. Se edit_task.php existe na raiz\n3. Console do navegador (F12) para erros');
            }
        });
    });
    
    // DRAG & DROP KANBAN
    const cards = document.querySelectorAll('.kanban-card');
    const cols = document.querySelectorAll('.kanban-column');
    let dragged = null;
    
    console.log('Cards encontrados:', cards.length);
    console.log('Colunas encontradas:', cols.length);
    
    cards.forEach(c => {
        c.addEventListener('dragstart', function(e) {
            console.log('Drag start:', this.dataset.taskId);
            dragged = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        });
        
        c.addEventListener('dragend', function(e) {
            console.log('Drag end');
            this.classList.remove('dragging');
            cols.forEach(col => col.classList.remove('drag-over'));
        });
    });
    
    cols.forEach(col => {
        col.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('drag-over');
            e.dataTransfer.dropEffect = 'move';
            return false;
        });
        
        col.addEventListener('dragleave', function(e) {
            this.classList.remove('drag-over');
        });
        
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('drag-over');
            
            if (dragged) {
                const id = dragged.dataset.taskId;
                const newEstado = this.dataset.estado;
                const oldEstado = dragged.dataset.estado;
                
                console.log('Drop - Task:', id, 'de', oldEstado, 'para', newEstado);
                
                if (newEstado === oldEstado) {
                    console.log('Mesmo estado, não fazer nada');
                    return false;
                }
                
                // Mostrar indicador visual
                dragged.style.opacity = '0.5';
                
                const fd = new FormData();
                fd.append('action', 'change_estado');
                fd.append('todo_id', id);
                fd.append('new_estado', newEstado);
                fd.append('ajax', '1');
                
                console.log('Enviando request...');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers);
                    
                    // Verificar se é JSON
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        // Não é JSON, ler como texto para debug
                        return response.text().then(text => {
                            console.error('Resposta não é JSON:', text.substring(0, 500));
                            throw new Error('Resposta não é JSON');
                        });
                    }
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        console.log('Sucesso! Recarregando...');
                        location.reload();
                    } else {
                        console.error('Erro na resposta:', data);
                        alert('Erro: ' + (data.error || 'Erro desconhecido'));
                        dragged.style.opacity = '1';
                    }
                })
                .catch(error => {
                    console.error('Erro no fetch:', error);
                    alert('Erro ao mover tarefa. Veja o console (F12) para detalhes.');
                    dragged.style.opacity = '1';
                });
            }
            
            return false;
        });
    });
});
</script>

<?php 
// IMPORTANTE: Incluir o editor universal
// Verificar se o ficheiro existe antes de incluir
$editor_path = __DIR__ . '/../edit_task.php';
if (file_exists($editor_path)) {
    include $editor_path;
    echo "<!-- Editor universal incluído -->\n";
} else {
    echo "<!-- ERRO: edit_task.php não encontrado em: $editor_path -->\n";
    echo "<script>console.error('ERRO: edit_task.php não encontrado! Execute install_task_editor.php');</script>\n";
}
?>