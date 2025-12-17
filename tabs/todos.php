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

// PROCESSAR AÇÕES - IMPORTANTE: Deve estar ANTES de qualquer output HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // MUDAR ESTADO (Drag & Drop do Kanban) - AJAX
    if ($action === 'change_estado' && isset($_POST['ajax'])) {
        // Limpar qualquer output buffer
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $todo_id = (int)$_POST['todo_id'];
        $new_estado = $_POST['new_estado'];
        
        $valid_estados = ['aberta', 'em execução', 'suspensa', 'concluída'];
        
        if (!in_array($new_estado, $valid_estados)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Estado inválido']);
            $db->close();
            exit;
        }
        
        $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('siii', $new_estado, $todo_id, $user_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            $db->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Estado atualizado!']);
            exit;
        } else {
            $stmt->close();
            $db->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Erro ao atualizar ou sem permissão']);
            exit;
        }
    }
    
    // MARCAR COMO CONCLUÍDA (Botão de Concluir) - POST Normal
    if ($action === 'mark_completed') {
        $todo_id = (int)$_POST['todo_id'];
        
        $stmt = $db->prepare('UPDATE todos SET estado = "concluída" WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success_message = '✅ Tarefa marcada como concluída!';
        } else {
            $error_message = '❌ Erro ao atualizar ou sem permissão.';
        }
        $stmt->close();
    }
    
    // ADICIONAR NOVA TASK (Via Modal Simples)
    if ($action === 'add') {
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
    if ($action === 'delete') {
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
$include_autor = isset($_GET['include_autor']); // Novo filtro para incluir tarefas onde é autor

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
    // Se filtrou por um responsável específico
    $query .= ' AND t.responsavel = ?';
    $types .= 'i';
    $params[] = $filter_responsavel;
} else {
    // Por padrão, mostrar apenas tarefas onde é responsável
    // Se checkbox "include_autor" estiver marcado, incluir também onde é autor
    if ($include_autor) {
        $query .= ' AND (t.autor = ? OR t.responsavel = ?)';
        $types .= 'ii';
        $params[] = $user_id;
        $params[] = $user_id;
    } else {
        $query .= ' AND t.responsavel = ?';
        $types .= 'i';
        $params[] = $user_id;
    }
}

if (!$show_completed) {
    $query .= ' AND t.estado != "concluída"';
}

$query .= ' ORDER BY 
    CASE t.estado 
        WHEN "em execução" THEN 1
        WHEN "aberta" THEN 2
        WHEN "suspensa" THEN 3
        WHEN "concluída" THEN 4
    END,
    t.data_limite ASC, t.id DESC';

$stmt = $db->prepare($query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$todos = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Agrupar por estado
$todos_by_estado = [
    'aberta' => [],
    'em execução' => [],
    'suspensa' => [],
    'concluída' => []
];

foreach ($todos as $todo) {
    $estado = $todo['estado'];
    if (isset($todos_by_estado[$estado])) {
        $todos_by_estado[$estado][] = $todo;
    }
}


// PROCESSAR DAILY REPORT
if ($action === 'save_daily_report') {
    $report_date = date('Y-m-d');
    $tarefas_alteradas = $_POST['tarefas_alteradas'] ?? '';
    $tarefas_em_execucao = $_POST['tarefas_em_execucao'] ?? '';
    $correu_bem = trim($_POST['correu_bem'] ?? '');
    $correu_mal = trim($_POST['correu_mal'] ?? '');
    $plano_proximas_horas = trim($_POST['plano_proximas_horas'] ?? '');
    
    // Verificar se já existe um report para hoje
    $stmt = $db->prepare('SELECT id FROM daily_reports WHERE user_id = ? AND report_date = ?');
    $stmt->bind_param('is', $user_id, $report_date);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        // Atualizar report existente
        $stmt = $db->prepare('UPDATE daily_reports SET tarefas_alteradas = ?, tarefas_em_execucao = ?, correu_bem = ?, correu_mal = ?, plano_proximas_horas = ? WHERE id = ?');
        $stmt->bind_param('sssssi', $tarefas_alteradas, $tarefas_em_execucao, $correu_bem, $correu_mal, $plano_proximas_horas, $existing['id']);
    } else {
        // Criar novo report
        $stmt = $db->prepare('INSERT INTO daily_reports (user_id, report_date, tarefas_alteradas, tarefas_em_execucao, correu_bem, correu_mal, plano_proximas_horas) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssss', $user_id, $report_date, $tarefas_alteradas, $tarefas_em_execucao, $correu_bem, $correu_mal, $plano_proximas_horas);
    }
    
    if ($stmt->execute()) {
        $success_message = '✅ Daily Report guardado com sucesso!';
    } else {
        $error_message = '❌ Erro ao guardar Daily Report.';
    }
    $stmt->close();
    
    header("Location: ?tab=todos#daily-report");
    exit;
}

// Obter Daily Report de hoje (se existir)
$today_report = null;
$stmt = $db->prepare('SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?');
$today_date = date('Y-m-d');
$stmt->bind_param('is', $user_id, $today_date);
$stmt->execute();
$today_report = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Obter tarefas alteradas nas últimas 24 horas
$stmt = $db->prepare('
    SELECT t.id, t.titulo, t.estado, t.projeto_id, t.updated_at,
           u1.username as autor_nome, u2.username as responsavel_nome
    FROM todos t
    LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
    LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
    WHERE (t.autor = ? OR t.responsavel = ?)
    AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND t.updated_at != t.created_at
    ORDER BY t.updated_at DESC
');
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$tasks_changed_24h = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Obter tarefas em execução
$stmt = $db->prepare('
    SELECT t.id, t.titulo, t.estado, t.projeto_id,
           u1.username as autor_nome, u2.username as responsavel_nome
    FROM todos t
    LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
    LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
    WHERE (t.autor = ? OR t.responsavel = ?)
    AND t.estado = "em execução"
    ORDER BY t.updated_at DESC
');
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$tasks_in_progress = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Estatísticas
$stats = [
    'total' => count($todos),
    'aberta' => count($todos_by_estado['aberta']),
    'em_execucao' => count($todos_by_estado['em execução']),
    'suspensa' => count($todos_by_estado['suspensa']),
    'concluida' => count($todos_by_estado['concluída'])
];

$db->close();
?>

<style>
.stats-card {
    border-radius: 10px;
    padding: 15px;
    text-align: center;
    transition: all 0.3s;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stats-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}

.kanban-board {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.kanban-card {
    background: white;
    padding: 15px;
    border-radius: 8px;
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

.task-actions {
    display: flex;
    gap: 5px;
    margin-top: 10px;
}

.btn-task-action {
    padding: 2px 8px;
    font-size: 12px;
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
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="stats-card bg-light">
                <h3 class="mb-0"><?= $stats['total'] ?></h3>
                <small class="text-muted">Total de Tarefas</small>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h3 class="mb-0"><?= $stats['aberta'] ?></h3>
                <small>Abertas</small>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                <h3 class="mb-0"><?= $stats['em_execucao'] ?></h3>
                <small>Em Execução</small>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                <h3 class="mb-0"><?= $stats['suspensa'] ?></h3>
                <small>Suspensas</small>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="todos">
                
                <div class="col-md-3">
                    <label class="form-label">Visualização</label>
                    <select name="view" class="form-select" onchange="this.form.submit()">
                        <option value="kanban" <?= $view_mode === 'kanban' ? 'selected' : '' ?>>Kanban</option>
                        <option value="lista" <?= $view_mode === 'lista' ? 'selected' : '' ?>>Lista</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Responsável</label>
                    <select name="responsavel" class="form-select" onchange="this.form.submit()">
                        <option value="">Tarefas onde sou responsável</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $filter_responsavel === $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label d-block">&nbsp;</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_completed" 
                               id="showCompleted" <?= $show_completed ? 'checked' : '' ?> 
                               onchange="this.form.submit()">
                        <label class="form-check-label" for="showCompleted">
                            Mostrar concluídas
                        </label>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label d-block">&nbsp;</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="include_autor" 
                               id="includeAutor" <?= $include_autor ? 'checked' : '' ?> 
                               onchange="this.form.submit()">
                        <label class="form-check-label" for="includeAutor">
                            Incluir onde sou autor
                        </label>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- KANBAN VIEW -->
    <?php if ($view_mode === 'kanban'): ?>
        <div class="kanban-board">
            <?php
            $estados_config = [
                'aberta' => ['titulo' => 'Abertas', 'icon' => 'bi-circle', 'color' => '#6c757d'],
                'em execução' => ['titulo' => 'Em Execução', 'icon' => 'bi-arrow-repeat', 'color' => '#0d6efd'],
                'suspensa' => ['titulo' => 'Suspensas', 'icon' => 'bi-pause-circle', 'color' => '#ffc107'],
                'concluída' => ['titulo' => 'Concluídas', 'icon' => 'bi-check-circle', 'color' => '#198754']
            ];
            
            foreach ($estados_config as $estado => $config):
                // Pular coluna de concluídas se checkbox não estiver marcado
                if ($estado === 'concluída' && !$show_completed) {
                    continue;
                }
                
                $tasks = $todos_by_estado[$estado];
            ?>
                <div>
                    <div class="card mb-0">
                        <div class="card-header" style="background: <?= $config['color'] ?>; color: white;">
                            <i class="bi <?= $config['icon'] ?>"></i> <?= $config['titulo'] ?>
                            <span class="badge bg-white text-dark float-end"><?= count($tasks) ?></span>
                        </div>
                        <div class="kanban-column" data-estado="<?= $estado ?>">
                            <?php foreach ($tasks as $todo): ?>
                                <div class="kanban-card" draggable="true" 
                                     data-task-id="<?= $todo['id'] ?>" 
                                     data-estado="<?= $estado ?>">
                                    <h6 class="mb-2">
                                        <?= htmlspecialchars($todo['titulo']) ?>
                                    </h6>
                                    
                                    <?php if ($todo['descritivo']): ?>
                                        <p class="small text-muted mb-2">
                                            <?= htmlspecialchars(substr($todo['descritivo'], 0, 80)) ?>
                                            <?= strlen($todo['descritivo']) > 80 ? '...' : '' ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($todo['responsavel_nome'] ?? 'N/A') ?>
                                        </small>
                                        <?php if ($todo['data_limite']): ?>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($todo['data_limite'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Botões de ação -->
                                    <div class="task-actions">
                                        <button class="btn btn-sm btn-primary btn-task-action edit-task-btn" 
                                                data-task-id="<?= $todo['id'] ?>" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        
                                        <?php if ($estado !== 'concluída'): ?>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Marcar esta tarefa como concluída?');">
                                            <input type="hidden" name="action" value="mark_completed">
                                            <input type="hidden" name="todo_id" value="<?= $todo['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success btn-task-action" title="Marcar como concluída">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Tem certeza que deseja eliminar esta tarefa?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="todo_id" value="<?= $todo['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger btn-task-action" title="Eliminar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($tasks)): ?>
                                <p class="text-muted text-center p-3">Nenhuma tarefa</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    
    <!-- LISTA VIEW -->
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Responsável</th>
                        <th>Estado</th>
                        <th>Data Limite</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todos as $todo): ?>
                        <tr>
                            <td><?= $todo['id'] ?></td>
                            <td><?= htmlspecialchars($todo['titulo']) ?></td>
                            <td><?= htmlspecialchars($todo['responsavel_nome'] ?? 'N/A') ?></td>
                            <td>
                                <?php
                                $badge_class = [
                                    'aberta' => 'secondary',
                                    'em execução' => 'primary',
                                    'suspensa' => 'warning',
                                    'concluída' => 'success'
                                ];
                                ?>
                                <span class="badge bg-<?= $badge_class[$todo['estado']] ?? 'secondary' ?>">
                                    <?= ucfirst($todo['estado']) ?>
                                </span>
                            </td>
                            <td><?= $todo['data_limite'] ? date('d/m/Y', strtotime($todo['data_limite'])) : '-' ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-task-btn" 
                                        data-task-id="<?= $todo['id'] ?>" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                
                                <?php if ($todo['estado'] !== 'concluída'): ?>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Marcar esta tarefa como concluída?');">
                                    <input type="hidden" name="action" value="mark_completed">
                                    <input type="hidden" name="todo_id" value="<?= $todo['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Marcar como concluída">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Tem certeza que deseja eliminar esta tarefa?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="todo_id" value="<?= $todo['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================= -->
<!-- DAILY REPORT SECTION -->
<!-- ============================================= -->
<div id="daily-report" class="container-fluid mt-5 mb-5">
    <div class="card shadow">
        <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-0">
                        <i class="bi bi-journal-text"></i> Daily Report
                    </h3>
                    <small>Reflexão diária sobre o trabalho realizado</small>
                </div>
                <div class="col-md-4 text-end">
                    <?php if ($today_report): ?>
                        <span class="badge bg-success fs-6">
                            <i class="bi bi-check-circle"></i> Report de Hoje Guardado
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6">
                            <i class="bi bi-exclamation-circle"></i> Report Pendente
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_daily_report">
                
                <!-- Botão para Auto-Preencher Tarefas -->
                <div class="mb-4 text-center">
                    <button type="button" class="btn btn-primary btn-lg" id="btnAutoFillTasks">
                        <i class="bi bi-magic"></i> Auto-Preencher Tarefas das Últimas 24h
                    </button>
                    <p class="text-muted small mt-2">
                        Clique para preencher automaticamente as tarefas que alteraram de estado e as que estão em execução
                    </p>
                </div>
                
                <div class="row">
                    <!-- Tarefas Alteradas nas Últimas 24h -->
                    <div class="col-md-6 mb-4">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-arrow-repeat"></i> Tarefas Alteradas (Últimas 24h)
                                </h5>
                            </div>
                            <div class="card-body">
                                <textarea name="tarefas_alteradas" id="tarefas_alteradas" class="form-control" rows="8" 
                                          placeholder="Lista de tarefas que mudaram de estado nas últimas 24 horas..."><?= htmlspecialchars($today_report['tarefas_alteradas'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    <?= count($tasks_changed_24h) ?> tarefa(s) alterada(s) nas últimas 24h
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tarefas em Execução -->
                    <div class="col-md-6 mb-4">
                        <div class="card border-info">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-play-circle"></i> Tarefas em Execução
                                </h5>
                            </div>
                            <div class="card-body">
                                <textarea name="tarefas_em_execucao" id="tarefas_em_execucao" class="form-control" rows="8" 
                                          placeholder="Lista de tarefas que estão atualmente em execução..."><?= htmlspecialchars($today_report['tarefas_em_execucao'] ?? '') ?></textarea>
                                <small class="text-muted">
                                    <?= count($tasks_in_progress) ?> tarefa(s) em execução
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Reflexão Diária -->
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card border-success h-100">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-emoji-smile"></i> O que correu bem?
                                </h5>
                            </div>
                            <div class="card-body">
                                <textarea name="correu_bem" class="form-control" rows="6" 
                                          placeholder="Descreva o que correu bem hoje..." required><?= htmlspecialchars($today_report['correu_bem'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card border-warning h-100">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="bi bi-emoji-frown"></i> O que correu menos bem?
                                </h5>
                            </div>
                            <div class="card-body">
                                <textarea name="correu_mal" class="form-control" rows="6" 
                                          placeholder="Descreva os desafios ou problemas encontrados..." required><?= htmlspecialchars($today_report['correu_mal'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card border-info h-100">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-calendar-check"></i> Plano para as próximas horas
                                </h5>
                            </div>
                            <div class="card-body">
                                <textarea name="plano_proximas_horas" class="form-control" rows="6" 
                                          placeholder="Descreva o que planeia fazer nas próximas horas..." required><?= htmlspecialchars($today_report['plano_proximas_horas'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg px-5">
                        <i class="bi bi-save"></i> Guardar Daily Report
                    </button>
                </div>
            </form>
            
            <?php if ($today_report): ?>
                <div class="alert alert-info mt-4">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Último report guardado:</strong> 
                    <?= date('d/m/Y H:i', strtotime($today_report['atualizado_em'])) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Dados das tarefas para JavaScript -->
<script>
const tasksChanged24h = <?= json_encode($tasks_changed_24h) ?>;
const tasksInProgress = <?= json_encode($tasks_in_progress) ?>;

// Função para obter nome do projeto/sprint
function getProjectInfo(task) {
    if (task.projeto_id == 9999) {
        return '[PhD]';
    } else if (task.projeto_id == 0) {
        return '[Geral]';
    } else {
        return '[Projeto #' + task.projeto_id + ']';
    }
}

// Função para auto-preencher tarefas
document.getElementById('btnAutoFillTasks').addEventListener('click', function() {
    let textChanged = '';
    let textInProgress = '';
    
    // Preencher tarefas alteradas
    if (tasksChanged24h.length > 0) {
        tasksChanged24h.forEach(function(task) {
            const projectInfo = getProjectInfo(task);
            textChanged += `${projectInfo} #${task.id} - ${task.titulo} [${task.estado}]\n`;
        });
    } else {
        textChanged = 'Nenhuma tarefa alterada nas últimas 24 horas.\n';
    }
    
    // Preencher tarefas em execução
    if (tasksInProgress.length > 0) {
        tasksInProgress.forEach(function(task) {
            const projectInfo = getProjectInfo(task);
            textInProgress += `${projectInfo} #${task.id} - ${task.titulo}\n`;
        });
    } else {
        textInProgress = 'Nenhuma tarefa em execução atualmente.\n';
    }
    
    document.getElementById('tarefas_alteradas').value = textChanged;
    document.getElementById('tarefas_em_execucao').value = textInProgress;
    
    // Feedback visual
    this.innerHTML = '<i class="bi bi-check-circle"></i> Tarefas Preenchidas!';
    this.classList.remove('btn-primary');
    this.classList.add('btn-success');
    
    setTimeout(() => {
        this.innerHTML = '<i class="bi bi-magic"></i> Auto-Preencher Tarefas das Últimas 24h';
        this.classList.remove('btn-success');
        this.classList.add('btn-primary');
    }, 2000);
});
</script>

<!-- Modal: Adicionar Tarefa -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="titulo" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descritivo" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Data Limite</label>
                        <input type="date" name="data_limite" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="responsavel" class="form-select">
                            <option value="">Eu</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Abrir editor ao clicar em tarefa
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const taskId = this.dataset.taskId;
            
            if (typeof openTaskEditor === 'function') {
                openTaskEditor(taskId);
            } else {
                alert('Editor não disponível. Certifique-se que edit_task.php está incluído.');
            }
        });
    });
    
    // DRAG AND DROP KANBAN - VERSÃO CORRIGIDA
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
                    // Feedback visual
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
                    .then(response => {
                        // Log da resposta para debug
                        console.log('Status:', response.status);
                        console.log('Content-Type:', response.headers.get('content-type'));
                        
                        // Verificar se a resposta é JSON válido
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            // Tentar ler como texto para ver o que foi retornado
                            return response.text().then(text => {
                                console.error('Resposta não é JSON:', text.substring(0, 200));
                                throw new Error('Servidor retornou HTML em vez de JSON');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Sucesso - recarregar página
                            location.reload();
                        } else {
                            // Erro retornado pela API
                            alert('Erro ao mover tarefa: ' + (data.error || 'Erro desconhecido'));
                            draggedCard.style.opacity = '1';
                        }
                    })
                    .catch(err => {
                        // Erro de rede ou parsing
                        console.error('Erro completo:', err);
                        alert('Erro ao mover tarefa: ' + err.message);
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