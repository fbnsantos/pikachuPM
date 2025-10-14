<?php
/**
 * MÓDULO TODOS - Com Editor Universal Integrado
 * Gestão completa de tarefas com markdown, checklist e ficheiros
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
$current_tab = $_GET['tab'] ?? 'minhas';

// Obter todos os utilizadores para dropdowns
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
    
    // ADICIONAR NOVA TASK
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
    
    // EDITAR TASK (do modal antigo - manter para compatibilidade)
    elseif ($action === 'edit_task') {
        $todo_id = (int)$_POST['todo_id'];
        $titulo = trim($_POST['titulo']);
        $descritivo = trim($_POST['descritivo'] ?? '');
        $data_limite = $_POST['data_limite'] ?: null;
        $responsavel = !empty($_POST['responsavel']) ? (int)$_POST['responsavel'] : null;
        $estado = $_POST['estado'] ?? 'aberta';
        
        if (!empty($titulo)) {
            $stmt = $db->prepare('SELECT id FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
            $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $stmt->close();
                
                $stmt = $db->prepare('UPDATE todos SET titulo = ?, descritivo = ?, data_limite = ?, responsavel = ?, estado = ? WHERE id = ?');
                $stmt->bind_param('sssisi', $titulo, $descritivo, $data_limite, $responsavel, $estado, $todo_id);
                
                if ($stmt->execute()) {
                    $success_message = '✅ Tarefa atualizada com sucesso!';
                } else {
                    $error_message = '❌ Erro ao atualizar tarefa.';
                }
                $stmt->close();
            } else {
                $stmt->close();
                $error_message = '❌ Sem permissão para editar esta tarefa.';
            }
        }
    }
    
    // ELIMINAR TASK
    elseif ($action === 'delete') {
        $todo_id = (int)$_POST['todo_id'];
        
        $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND (autor = ? OR responsavel = ?)');
        $stmt->bind_param('iii', $todo_id, $user_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success_message = '✅ Tarefa eliminada com sucesso!';
        } else {
            $error_message = '❌ Erro ao eliminar tarefa ou sem permissão.';
        }
        $stmt->close();
    }
}

// OBTER TAREFAS CONFORME ABA SELECIONADA
$tarefas = [];
$query_base = 'SELECT t.*, 
               autor.username as autor_nome,
               resp.username as responsavel_nome
               FROM todos t
               LEFT JOIN user_tokens autor ON t.autor = autor.user_id
               LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id';

switch ($current_tab) {
    case 'minhas':
        $query = $query_base . ' WHERE (t.autor = ? OR t.responsavel = ?) ORDER BY 
                 CASE WHEN t.estado = "em execução" THEN 1 
                      WHEN t.estado = "aberta" THEN 2 
                      ELSE 3 END, 
                 t.data_limite ASC';
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $user_id, $user_id);
        break;
        
    case 'todas':
        $query = $query_base . ' ORDER BY t.created_at DESC';
        $stmt = $db->prepare($query);
        break;
        
    case 'abertas':
        $query = $query_base . ' WHERE t.estado IN ("aberta", "em execução") ORDER BY t.data_limite ASC';
        $stmt = $db->prepare($query);
        break;
        
    case 'concluidas':
        $query = $query_base . ' WHERE t.estado = "concluída" ORDER BY t.updated_at DESC LIMIT 50';
        $stmt = $db->prepare($query);
        break;
        
    default:
        $query = $query_base . ' WHERE t.autor = ? OR t.responsavel = ? ORDER BY t.created_at DESC';
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $user_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $tarefas[] = $row;
}
$stmt->close();

// Estatísticas
$stats = [
    'total' => 0,
    'abertas' => 0,
    'execucao' => 0,
    'concluidas' => 0
];

$stmt = $db->prepare('SELECT estado, COUNT(*) as total FROM todos WHERE autor = ? OR responsavel = ? GROUP BY estado');
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['total'] += $row['total'];
    if ($row['estado'] === 'aberta') $stats['abertas'] = $row['total'];
    if ($row['estado'] === 'em execução') $stats['execucao'] = $row['total'];
    if ($row['estado'] === 'concluída') $stats['concluidas'] = $row['total'];
}
$stmt->close();
?>

<style>
.task-card {
    transition: all 0.2s;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    height: 100%;
}

.task-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}

.task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 10px;
}

.badge {
    font-size: 0.75rem;
    padding: 4px 8px;
}

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

.stats-label {
    font-size: 0.9rem;
    opacity: 0.9;
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
    
    <!-- Tabs de Filtros -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'minhas' ? 'active' : '' ?>" href="?tab=todos&view=minhas">
                <i class="bi bi-person"></i> Minhas Tarefas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'todas' ? 'active' : '' ?>" href="?tab=todos&view=todas">
                <i class="bi bi-list-task"></i> Todas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'abertas' ? 'active' : '' ?>" href="?tab=todos&view=abertas">
                <i class="bi bi-hourglass-split"></i> Abertas/Em Execução
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_tab === 'concluidas' ? 'active' : '' ?>" href="?tab=todos&view=concluidas">
                <i class="bi bi-check-circle"></i> Concluídas
            </a>
        </li>
    </ul>
    
    <!-- Grid de Tarefas -->
    <div class="row g-3">
        <?php if (empty($tarefas)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle"></i> Nenhuma tarefa encontrada nesta visualização.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($tarefas as $tarefa): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card task-card">
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
                            
                            <div class="task-meta">
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
                                <!-- BOTÃO COM NOVO EDITOR UNIVERSAL -->
                                <button type="button" 
                                        class="btn btn-outline-primary edit-task-btn" 
                                        data-task-id="<?= $tarefa['id'] ?>"
                                        title="Editar com editor completo (Markdown, Checklist, Ficheiros)">
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>
                                
                                <button type="button" 
                                        class="btn btn-outline-danger" 
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
                        <textarea class="form-control" name="descritivo" rows="3" placeholder="Descrição breve da tarefa..."></textarea>
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
                        <i class="bi bi-plus-circle"></i> Adicionar Tarefa
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
// Função para confirmar exclusão
function confirmarExclusao(id, titulo) {
    if (confirm(`Tem certeza que deseja excluir: "${titulo}"?`)) {
        document.getElementById('delete-todo-id').value = id;
        document.getElementById('delete-form').submit();
    }
}

// Integração com o novo editor universal
document.addEventListener('DOMContentLoaded', function() {
    // Adicionar event listeners aos botões de editar
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const taskId = this.dataset.taskId;
            
            // Abrir o editor universal (função do edit_task.php)
            if (typeof openTaskEditor === 'function') {
                openTaskEditor(taskId);
            } else {
                console.error('Editor universal não carregado. Certifique-se que edit_task.php está incluído.');
                alert('Erro: Editor não disponível. Contacte o administrador.');
            }
        });
    });
});
</script>

<?php
// IMPORTANTE: Incluir o editor universal no final
include __DIR__ . '/../edit_task.php';
?>