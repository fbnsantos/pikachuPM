<?php
// tabs/phd_kanboard.php - Gestão de Tarefas do Doutoramento com Kanban Board

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// ID do projeto de doutoramento
define('PHD_PROJECT_ID', 9999);

// Mapeamento entre estágios Kanban e estados Todo
$stage_to_estado_map = [
    'pensada' => 'aberta',
    'execucao' => 'em execução',
    'espera' => 'suspensa',
    'concluida' => 'concluída'
];

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
    
    // Criar tabela para informações do doutoramento
    $db->query('CREATE TABLE IF NOT EXISTS phd_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        data_inicio DATE,
        titulo_doutoramento TEXT,
        orientador VARCHAR(255),
        coorientador VARCHAR(255),
        instituicao VARCHAR(255),
        departamento VARCHAR(255),
        link_tese TEXT,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id)
    )');
    
    // Criar tabela para artigos do doutoramento
    $db->query('CREATE TABLE IF NOT EXISTS phd_artigos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        titulo VARCHAR(500),
        autores TEXT,
        revista_conferencia VARCHAR(255),
        ano INT,
        link TEXT,
        status VARCHAR(50) DEFAULT "publicado",
        tipo VARCHAR(50) DEFAULT "artigo",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id)
    )');
    
} catch (Exception $e) {
    die("Erro ao conectar à base de dados: " . $e->getMessage());
}

// ============================================
// PROCESSAR PEDIDOS AJAX PRIMEIRO (ANTES DE QUALQUER OUTPUT)
// ============================================

// Atualizar estágio da tarefa (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage' && isset($_POST['ajax'])) {
    
    // Limpar qualquer output buffer que possa existir
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    $task_id = intval($_POST['task_id']);
    $new_stage = $_POST['new_stage'];
    
    $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
    
    if (!in_array($new_stage, $valid_stages)) {
        echo json_encode(['success' => false, 'error' => 'Estágio inválido']);
        $db->close();
        exit;
    }
    
    $new_estado = $stage_to_estado_map[$new_stage];
    $stmt = $db->prepare('UPDATE todos SET estagio = ?, estado = ? WHERE id = ? AND projeto_id = ?');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('ssii', $new_stage, $new_estado, $task_id, $projeto_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    
    $stmt->close();
    $db->close();
    exit; // IMPORTANTE: Parar execução aqui
}

// ============================================
// PROCESSAR AÇÕES NORMAIS (COM REDIRECT)
// ============================================

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
    $estado = $stage_to_estado_map[$estagio];
    
    if (!empty($titulo)) {
        $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estagio, estado, projeto_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('sssiissi', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estagio, $estado, $projeto_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            // Redirect para evitar resubmissão do formulário
            $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
            if (isset($_GET['user'])) {
                $redirect_url .= '&user=' . intval($_GET['user']);
            }
            $redirect_url .= '&success=task_added';
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $error_message = "Erro ao adicionar tarefa: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $error_message = "O título da tarefa é obrigatório.";
    }
}

// Verificar se há mensagem de sucesso no URL
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'task_added':
            $success_message = "Tarefa adicionada com sucesso!";
            break;
        case 'task_deleted':
            $success_message = "Tarefa eliminada com sucesso!";
            break;
        case 'phd_info_saved':
            $success_message = "Informações do doutoramento guardadas com sucesso!";
            break;
        case 'artigo_added':
            $success_message = "Artigo adicionado com sucesso!";
            break;
        case 'artigo_updated':
            $success_message = "Artigo atualizado com sucesso!";
            break;
        case 'artigo_deleted':
            $success_message = "Artigo eliminado com sucesso!";
            break;
    }
}

// Atualizar estágio da tarefa (via POST normal, não AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage' && !isset($_POST['ajax'])) {
    $task_id = intval($_POST['task_id']);
    $new_stage = $_POST['new_stage'];
    
    $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
    if (in_array($new_stage, $valid_stages)) {
        $new_estado = $stage_to_estado_map[$new_stage];
        $stmt = $db->prepare('UPDATE todos SET estagio = ?, estado = ? WHERE id = ? AND projeto_id = ?');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('ssii', $new_stage, $new_estado, $task_id, $projeto_id);
        
        if ($stmt->execute()) {
            $success_message = "Estágio atualizado com sucesso!";
        } else {
            $error_message = "Erro ao atualizar estágio: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Estágio inválido";
    }
}

// Eliminar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    $task_id = intval($_POST['task_id']);
    
    $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND projeto_id = ?');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('ii', $task_id, $projeto_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect para evitar resubmissão
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=task_deleted';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao eliminar tarefa: " . $stmt->error;
        $stmt->close();
    }
}

// Guardar/Atualizar informações do doutoramento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_phd_info') {
    $data_inicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : null;
    $titulo = trim($_POST['titulo_doutoramento']);
    $orientador = trim($_POST['orientador']);
    $coorientador = trim($_POST['coorientador']);
    $instituicao = trim($_POST['instituicao']);
    $departamento = trim($_POST['departamento']);
    $link_tese = trim($_POST['link_tese']);
    $notas = trim($_POST['notas']);
    $selected_user = intval($_POST['selected_user']);
    
    $stmt = $db->prepare('SELECT id FROM phd_info WHERE user_id = ?');
    $stmt->bind_param('i', $selected_user);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $db->prepare('UPDATE phd_info SET data_inicio = ?, titulo_doutoramento = ?, orientador = ?, coorientador = ?, instituicao = ?, departamento = ?, link_tese = ?, notas = ? WHERE user_id = ?');
        $stmt->bind_param('ssssssssi', $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas, $selected_user);
    } else {
        $stmt = $db->prepare('INSERT INTO phd_info (user_id, data_inicio, titulo_doutoramento, orientador, coorientador, instituicao, departamento, link_tese, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssssss', $selected_user, $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas);
    }
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=phd_info_saved';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao guardar informações: " . $stmt->error;
        $stmt->close();
    }
}

// Adicionar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_artigo') {
    $titulo = trim($_POST['titulo_artigo']);
    $autores = trim($_POST['autores']);
    $revista = trim($_POST['revista_conferencia']);
    $ano = !empty($_POST['ano']) ? intval($_POST['ano']) : null;
    $link = trim($_POST['link_artigo']);
    $status = $_POST['status_artigo'];
    $tipo = $_POST['tipo_artigo'];
    $selected_user = intval($_POST['selected_user']);
    
    $stmt = $db->prepare('INSERT INTO phd_artigos (user_id, titulo, autores, revista_conferencia, ano, link, status, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssisss', $selected_user, $titulo, $autores, $revista, $ano, $link, $status, $tipo);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_added';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao adicionar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// Editar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_artigo') {
    $artigo_id = intval($_POST['artigo_id']);
    $titulo = trim($_POST['titulo_artigo_edit']);
    $autores = trim($_POST['autores_edit']);
    $revista = trim($_POST['revista_conferencia_edit']);
    $ano = !empty($_POST['ano_edit']) ? intval($_POST['ano_edit']) : null;
    $link = trim($_POST['link_artigo_edit']);
    $status = $_POST['status_artigo_edit'];
    $tipo = $_POST['tipo_artigo_edit'];
    
    $stmt = $db->prepare('UPDATE phd_artigos SET titulo = ?, autores = ?, revista_conferencia = ?, ano = ?, link = ?, status = ?, tipo = ? WHERE id = ?');
    $stmt->bind_param('ssssissi', $titulo, $autores, $revista, $ano, $link, $status, $tipo, $artigo_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_updated';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao atualizar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// Eliminar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_artigo') {
    $artigo_id = intval($_POST['artigo_id']);
    
    $stmt = $db->prepare('DELETE FROM phd_artigos WHERE id = ?');
    $stmt->bind_param('i', $artigo_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_deleted';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao eliminar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// Buscar todos os utilizadores com prioridade para quem tem info de doutoramento
$all_users = [];
$stmt = $db->query('
    SELECT ut.user_id, ut.username,
           CASE WHEN pi.id IS NOT NULL THEN 1 ELSE 0 END as has_phd_info
    FROM user_tokens ut 
    LEFT JOIN phd_info pi ON ut.user_id = pi.user_id
    ORDER BY has_phd_info DESC, ut.username ASC
');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $all_users[] = $row;
    }
}

// Determinar utilizador selecionado
$selected_user = $user_id;
if (isset($_GET['user']) && !empty($_GET['user'])) {
    $selected_user = intval($_GET['user']);
}

// Buscar informações do doutoramento
$phd_info = null;
$stmt = $db->prepare('SELECT * FROM phd_info WHERE user_id = ?');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $phd_info = $result->fetch_assoc();
}
$stmt->close();

// Buscar artigos
$artigos = [];
$stmt = $db->prepare('SELECT * FROM phd_artigos WHERE user_id = ? ORDER BY ano DESC, titulo ASC');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $artigos[] = $row;
}
$stmt->close();

// Buscar tarefas
$tasks_by_stage = [
    'pensada' => [],
    'execucao' => [],
    'espera' => [],
    'concluida' => []
];

$stmt = $db->prepare('
    SELECT t.*, u.username as responsavel_nome 
    FROM todos t 
    LEFT JOIN user_tokens u ON t.responsavel = u.user_id 
    WHERE t.projeto_id = ? AND (t.autor = ? OR t.responsavel = ?)
    ORDER BY t.data_limite ASC
');
$projeto_id = PHD_PROJECT_ID;
$stmt->bind_param('iii', $projeto_id, $selected_user, $selected_user);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $estagio = $row['estagio'] ?: 'pensada';
    if (isset($tasks_by_stage[$estagio])) {
        $tasks_by_stage[$estagio][] = $row;
    }
}
$stmt->close();

// Estatísticas
$total_tasks = count($tasks_by_stage['pensada']) + count($tasks_by_stage['execucao']) + count($tasks_by_stage['espera']) + count($tasks_by_stage['concluida']);
$completed_tasks = count($tasks_by_stage['concluida']);
$progress_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
$total_artigos = count($artigos);

?>

<style>
.kanban-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.kanban-column {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    min-height: 400px;
}

.kanban-column-header {
    font-weight: bold;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.kanban-card {
    background: white;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
    cursor: move;
}

.kanban-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.stat-item {
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
}

.stat-number {
    font-size: 2em;
    font-weight: bold;
}

.artigo-item {
    border-left: 3px solid #667eea;
    padding: 15px;
    margin-bottom: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}

@media (max-width: 1200px) {
    .kanban-board {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .kanban-board {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid mt-4">
    
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="bi bi-mortarboard"></i> Gestão do Doutoramento</h2>
        <select class="form-select" id="userSelector" style="width: 250px;">
            <?php foreach ($all_users as $u): ?>
                <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $selected_user ? 'selected' : '' ?>>
                    <?= $u['has_phd_info'] ? '⭐ ' : '' ?><?= htmlspecialchars($u['username']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="stats-card">
        <h4><i class="bi bi-graph-up"></i> Estatísticas</h4>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?= $total_tasks ?></div>
                <div class="stat-label">Tarefas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $completed_tasks ?></div>
                <div class="stat-label">Concluídas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $progress_percentage ?>%</div>
                <div class="stat-label">Progresso</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $total_artigos ?></div>
                <div class="stat-label">KPIs</div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="bi bi-info-circle"></i> Informações do Doutoramento</h4>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#phdInfoModal">
                <i class="bi bi-pencil"></i> Editar
            </button>
        </div>
        <div class="card-body">
            <?php if ($phd_info): ?>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Título:</strong> <?= htmlspecialchars($phd_info['titulo_doutoramento'] ?: 'N/A') ?></p>
                        <p><strong>Data de Início:</strong> <?= $phd_info['data_inicio'] ? date('d/m/Y', strtotime($phd_info['data_inicio'])) : 'N/A' ?></p>
                        <p><strong>Orientador:</strong> <?= htmlspecialchars($phd_info['orientador'] ?: 'N/A') ?></p>
                        <p><strong>Coorientador:</strong> <?= htmlspecialchars($phd_info['coorientador'] ?: 'N/A') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Instituição:</strong> <?= htmlspecialchars($phd_info['instituicao'] ?: 'N/A') ?></p>
                        <p><strong>Departamento:</strong> <?= htmlspecialchars($phd_info['departamento'] ?: 'N/A') ?></p>
                        <?php if ($phd_info['link_tese']): ?>
                            <p><strong>Link:</strong> <a href="<?= htmlspecialchars($phd_info['link_tese']) ?>" target="_blank">Ver</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted">Nenhuma informação registada.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
        <h4><i class="bi bi-kanban"></i> Quadro Kanban</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle"></i> Nova Tarefa
        </button>
    </div>
    <div class="kanban-board">
        <?php 
        $stage_info = [
            'pensada' => ['title' => 'Pensadas', 'icon' => 'lightbulb', 'color' => 'secondary'],
            'execucao' => ['title' => 'Em Execução', 'icon' => 'play-circle', 'color' => 'primary'],
            'espera' => ['title' => 'Em Espera', 'icon' => 'pause-circle', 'color' => 'warning'],
            'concluida' => ['title' => 'Concluídas', 'icon' => 'check-circle', 'color' => 'success']
        ];
        
        foreach ($stage_info as $stage => $info): 
            $tasks = $tasks_by_stage[$stage];
        ?>
        <div class="kanban-column" data-stage="<?= $stage ?>">
            <div class="kanban-column-header">
                <span><i class="bi bi-<?= $info['icon'] ?>"></i> <?= $info['title'] ?></span>
                <span class="badge bg-<?= $info['color'] ?>"><?= count($tasks) ?></span>
            </div>
            
            <div class="kanban-cards-container">
                <?php if (empty($tasks)): ?>
                    <p class="text-muted text-center">Nenhuma tarefa</p>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <div class="kanban-card" data-task-id="<?= $task['id'] ?>" draggable="true">
                            <div style="font-weight: 600; margin-bottom: 8px;">
                                <?= htmlspecialchars($task['titulo']) ?>
                            </div>
                            
                            <?php if ($task['descritivo']): ?>
                                <div class="text-muted" style="font-size: 0.85em; margin-bottom: 8px;">
                                    <?= htmlspecialchars(mb_substr($task['descritivo'], 0, 100)) ?>
                                    <?= mb_strlen($task['descritivo']) > 100 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small>
                                    <?php if ($task['data_limite']): ?>
                                        <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                    <?php endif; ?>
                                </small>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary edit-task-btn" data-task-id="<?= $task['id'] ?>" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-task-btn" data-task-id="<?= $task['id'] ?>" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="bi bi-journal-code"></i> Produção Científica</h4>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addArtigoModal">
                <i class="bi bi-plus-circle"></i> Adicionar
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($artigos)): ?>
                <p class="text-muted">Nenhuma produção científica registada.</p>
            <?php else: ?>
                <?php 
                // Agrupar por tipo
                $artigos_por_tipo = [
                    'artigo' => [],
                    'conferencia' => [],
                    'codigo' => [],
                    'dataset' => [],
                    'patente' => [],
                    'capitulo' => [],
                    'poster' => [],
                    'outro' => []
                ];
                
                foreach ($artigos as $artigo) {
                    $tipo = $artigo['tipo'] ?: 'outro';
                    if (isset($artigos_por_tipo[$tipo])) {
                        $artigos_por_tipo[$tipo][] = $artigo;
                    } else {
                        $artigos_por_tipo['outro'][] = $artigo;
                    }
                }
                
                $tipo_icons = [
                    'artigo' => 'file-text',
                    'conferencia' => 'calendar-event',
                    'codigo' => 'code-slash',
                    'dataset' => 'database',
                    'patente' => 'award',
                    'capitulo' => 'book',
                    'poster' => 'image',
                    'outro' => 'file-earmark'
                ];
                
                $tipo_labels = [
                    'artigo' => 'Artigos',
                    'conferencia' => 'Conferências',
                    'codigo' => 'Código',
                    'dataset' => 'Datasets',
                    'patente' => 'Patentes',
                    'capitulo' => 'Capítulos',
                    'poster' => 'Posters',
                    'outro' => 'Outros'
                ];
                
                foreach ($artigos_por_tipo as $tipo => $items):
                    if (empty($items)) continue;
                ?>
                    <h5 class="mt-3 mb-3">
                        <i class="bi bi-<?= $tipo_icons[$tipo] ?>"></i> 
                        <?= $tipo_labels[$tipo] ?> 
                        <span class="badge bg-secondary"><?= count($items) ?></span>
                    </h5>
                    
                    <?php foreach ($items as $artigo): ?>
                        <div class="artigo-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div style="font-weight: bold; margin-bottom: 8px;"><?= htmlspecialchars($artigo['titulo']) ?></div>
                                    <div style="font-size: 0.9em; color: #666;">
                                        <?php if ($artigo['autores']): ?>
                                            <strong>Autores:</strong> <?= htmlspecialchars($artigo['autores']) ?><br>
                                        <?php endif; ?>
                                        <?php if ($artigo['revista_conferencia']): ?>
                                            <strong><?= in_array($tipo, ['codigo', 'dataset']) ? 'Repositório:' : 'Publicado em:' ?></strong> 
                                            <?= htmlspecialchars($artigo['revista_conferencia']) ?><br>
                                        <?php endif; ?>
                                        <?php if ($artigo['ano']): ?>
                                            <strong>Ano:</strong> <?= $artigo['ano'] ?> | 
                                        <?php endif; ?>
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?= $artigo['status'] == 'publicado' ? 'success' : ($artigo['status'] == 'submetido' ? 'warning' : 'secondary') ?>">
                                            <?= htmlspecialchars($artigo['status']) ?>
                                        </span>
                                        <?php if ($artigo['link']): ?>
                                            <br><a href="<?= htmlspecialchars($artigo['link']) ?>" target="_blank">
                                                <i class="bi bi-link"></i> <?= in_array($tipo, ['codigo', 'dataset']) ? 'Aceder ao repositório' : 'Ver publicação' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary edit-artigo-btn" data-artigo-id="<?= $artigo['id'] ?>" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-artigo-btn" data-artigo-id="<?= $artigo['id'] ?>" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Adicionar Tarefa -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_task">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                    <div class="mb-3">
                        <label class="form-label">Data Limite</label>
                        <input type="date" class="form-control" name="data_limite">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select class="form-select" name="responsavel">
                            <option value="">Nenhum</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $selected_user ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Estágio</label>
                        <select class="form-select" name="estagio">
                            <option value="pensada">Pensada</option>
                            <option value="execucao">Em Execução</option>
                            <option value="espera">Em Espera</option>
                            <option value="concluida">Concluída</option>
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

<!-- Modal Editar Informações PhD -->
<div class="modal fade" id="phdInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="save_phd_info">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Informações do Doutoramento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Título</label>
                            <input type="text" class="form-control" name="titulo_doutoramento" 
                                   value="<?= htmlspecialchars($phd_info['titulo_doutoramento'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data de Início</label>
                            <input type="date" class="form-control" name="data_inicio" 
                                   value="<?= $phd_info['data_inicio'] ?? '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Orientador</label>
                            <input type="text" class="form-control" name="orientador" 
                                   value="<?= htmlspecialchars($phd_info['orientador'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Coorientador</label>
                            <input type="text" class="form-control" name="coorientador" 
                                   value="<?= htmlspecialchars($phd_info['coorientador'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instituição</label>
                            <input type="text" class="form-control" name="instituicao" 
                                   value="<?= htmlspecialchars($phd_info['instituicao'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Departamento</label>
                            <input type="text" class="form-control" name="departamento" 
                                   value="<?= htmlspecialchars($phd_info['departamento'] ?? '') ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Link da Tese</label>
                            <input type="url" class="form-control" name="link_tese" 
                                   value="<?= htmlspecialchars($phd_info['link_tese'] ?? '') ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Notas</label>
                            <textarea class="form-control" name="notas" rows="4"><?= htmlspecialchars($phd_info['notas'] ?? '') ?></textarea>
                        </div>
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

<!-- Modal Adicionar Artigo -->
<div class="modal fade" id="addArtigoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_artigo">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Produção Científica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select class="form-select" name="tipo_artigo" id="tipo_artigo_add" onchange="updateFieldLabels('add')">
                            <option value="artigo">Artigo Científico</option>
                            <option value="conferencia">Conferência</option>
                            <option value="codigo">Código/Software</option>
                            <option value="dataset">Dataset</option>
                            <option value="patente">Patente</option>
                            <option value="capitulo">Capítulo de Livro</option>
                            <option value="poster">Poster</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo_artigo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="autores_label_add">Autores *</label>
                        <input type="text" class="form-control" name="autores" required 
                               placeholder="Nome1, Nome2, Nome3">
                        <small class="text-muted" id="autores_help_add">Separe os nomes por vírgulas</small>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label" id="revista_label_add">Revista/Conferência</label>
                            <input type="text" class="form-control" name="revista_conferencia" id="revista_add">
                            <small class="text-muted" id="revista_help_add">Ex: Nature, IEEE, arXiv</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ano</label>
                            <input type="number" class="form-control" name="ano" value="<?= date('Y') ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status_artigo">
                                <option value="publicado">Publicado</option>
                                <option value="submetido">Submetido</option>
                                <option value="em_preparacao">Em Preparação</option>
                                <option value="aceite">Aceite</option>
                                <option value="em_desenvolvimento">Em Desenvolvimento</option>
                                <option value="disponivel">Disponível</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="link_label_add">Link/URL</label>
                        <input type="url" class="form-control" name="link_artigo" id="link_add"
                               placeholder="https://">
                        <small class="text-muted" id="link_help_add">DOI, GitHub, Zenodo, etc.</small>
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

<!-- Modal Editar Artigo -->
<div class="modal fade" id="editArtigoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_artigo">
                <input type="hidden" name="artigo_id" id="edit_artigo_id">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Produção Científica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select class="form-select" name="tipo_artigo_edit" id="tipo_artigo_edit" onchange="updateFieldLabels('edit')">
                            <option value="artigo">Artigo Científico</option>
                            <option value="conferencia">Conferência</option>
                            <option value="codigo">Código/Software</option>
                            <option value="dataset">Dataset</option>
                            <option value="patente">Patente</option>
                            <option value="capitulo">Capítulo de Livro</option>
                            <option value="poster">Poster</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo_artigo_edit" id="titulo_artigo_edit" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="autores_label_edit">Autores *</label>
                        <input type="text" class="form-control" name="autores_edit" id="autores_edit" required>
                        <small class="text-muted" id="autores_help_edit">Separe os nomes por vírgulas</small>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label" id="revista_label_edit">Revista/Conferência</label>
                            <input type="text" class="form-control" name="revista_conferencia_edit" id="revista_conferencia_edit">
                            <small class="text-muted" id="revista_help_edit">Ex: Nature, IEEE, arXiv</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ano</label>
                            <input type="number" class="form-control" name="ano_edit" id="ano_edit">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status_artigo_edit" id="status_artigo_edit">
                                <option value="publicado">Publicado</option>
                                <option value="submetido">Submetido</option>
                                <option value="em_preparacao">Em Preparação</option>
                                <option value="aceite">Aceite</option>
                                <option value="em_desenvolvimento">Em Desenvolvimento</option>
                                <option value="disponivel">Disponível</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="link_label_edit">Link/URL</label>
                        <input type="url" class="form-control" name="link_artigo_edit" id="link_artigo_edit">
                        <small class="text-muted" id="link_help_edit">DOI, GitHub, Zenodo, etc.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Função para atualizar labels baseado no tipo selecionado
function updateFieldLabels(mode) {
    const tipo = document.getElementById('tipo_artigo_' + mode).value;
    
    const labels = {
        autores: {
            codigo: 'Autores/Desenvolvedores *',
            dataset: 'Autores/Criadores *',
            patente: 'Inventores *',
            default: 'Autores *'
        },
        revista: {
            codigo: 'Repositório',
            dataset: 'Repositório',
            patente: 'Escritório de Patentes',
            conferencia: 'Conferência',
            default: 'Revista/Publicação'
        },
        link: {
            codigo: 'Link (GitHub, GitLab, etc.)',
            dataset: 'Link (Zenodo, Figshare, etc.)',
            patente: 'Link/Número da Patente',
            default: 'Link/DOI'
        },
        revista_help: {
            codigo: 'Ex: GitHub, GitLab, Bitbucket',
            dataset: 'Ex: Zenodo, Figshare, OSF',
            patente: 'Ex: INPI, EPO, USPTO',
            default: 'Ex: Nature, IEEE, arXiv'
        },
        link_help: {
            codigo: 'URL do repositório',
            dataset: 'URL do repositório de dados',
            patente: 'URL ou número oficial',
            default: 'DOI, arXiv, ou URL'
        }
    };
    
    // Atualizar label de autores
    document.getElementById('autores_label_' + mode).textContent = 
        labels.autores[tipo] || labels.autores.default;
    
    // Atualizar label de revista
    document.getElementById('revista_label_' + mode).textContent = 
        labels.revista[tipo] || labels.revista.default;
    
    // Atualizar placeholder de revista
    const revistaField = document.getElementById(mode === 'add' ? 'revista_add' : 'revista_conferencia_edit');
    if (revistaField) {
        revistaField.placeholder = labels.revista[tipo] || labels.revista.default;
    }
    
    // Atualizar label de link
    document.getElementById('link_label_' + mode).textContent = 
        labels.link[tipo] || labels.link.default;
    
    // Atualizar help text
    document.getElementById('revista_help_' + mode).textContent = 
        labels.revista_help[tipo] || labels.revista_help.default;
    
    document.getElementById('link_help_' + mode).textContent = 
        labels.link_help[tipo] || labels.link_help.default;
}

document.addEventListener('DOMContentLoaded', function() {
    // Seletor de utilizador
    document.getElementById('userSelector').addEventListener('change', function() {
        const userId = this.value;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('tab', 'phd_kanban');
        currentUrl.searchParams.set('user', userId);
        window.location.href = currentUrl.toString();
    });
    
    // Drag and Drop
    let draggedElement = null;
    
    document.querySelectorAll('.kanban-card').forEach(card => {
        card.addEventListener('dragstart', function(e) {
            draggedElement = this;
            this.style.opacity = '0.5';
        });
        
        card.addEventListener('dragend', function(e) {
            this.style.opacity = '';
        });
    });
    
    document.querySelectorAll('.kanban-column').forEach(column => {
        column.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.background = '#e9ecef';
        });
        
        column.addEventListener('dragleave', function(e) {
            this.style.background = '';
        });
        
        column.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.background = '';
            
            if (draggedElement) {
                const taskId = draggedElement.dataset.taskId;
                const newStage = this.dataset.stage;
                
                const formData = new FormData();
                formData.append('action', 'update_stage');
                formData.append('task_id', taskId);
                formData.append('new_stage', newStage);
                
                fetch('phd_kanban_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Sucesso - recarregar página
                        location.reload();
                    } else {
                        console.error('Erro:', data.error);
                        alert('Erro ao mover tarefa: ' + data.error);
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Erro de rede:', error);
                    alert('Erro de conexão. A tarefa pode ter sido movida. Recarregando...');
                    location.reload();
                });
            }
        });
    });
    
    // Botões de editar tarefa - usar função openTaskEditor
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.dataset.taskId;
            openTaskEditor(taskId);
        });
    });
    
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
    
    // Botões de editar artigo
    document.querySelectorAll('.edit-artigo-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const artigoId = this.dataset.artigoId;
            
            fetch(`get_artigo_details.php?id=${artigoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const artigo = data.artigo;
                        
                        document.getElementById('edit_artigo_id').value = artigo.id;
                        document.getElementById('titulo_artigo_edit').value = artigo.titulo;
                        document.getElementById('autores_edit').value = artigo.autores;
                        document.getElementById('revista_conferencia_edit').value = artigo.revista_conferencia || '';
                        document.getElementById('ano_edit').value = artigo.ano || '';
                        document.getElementById('link_artigo_edit').value = artigo.link || '';
                        document.getElementById('tipo_artigo_edit').value = artigo.tipo || 'artigo';
                        document.getElementById('status_artigo_edit').value = artigo.status || 'publicado';
                        
                        const modal = new bootstrap.Modal(document.getElementById('editArtigoModal'));
                        modal.show();
                    } else {
                        alert('Erro ao carregar dados: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do artigo');
                });
        });
    });
    
    // Botões de eliminar artigo
    document.querySelectorAll('.delete-artigo-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Tem a certeza que deseja eliminar este artigo?')) {
                const artigoId = this.dataset.artigoId;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_artigo">
                    <input type="hidden" name="artigo_id" value="${artigoId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
});
</script>

<?php
$db->close();

// Incluir editor universal de tasks
include __DIR__ . '/../edit_task.php';
?>