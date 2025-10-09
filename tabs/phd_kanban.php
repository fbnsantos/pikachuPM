<?php
// tabs/phd_kanban.php - Gestão de Tarefas do Doutoramento com Kanban Board

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// ID do projeto de doutoramento
define('PHD_PROJECT_ID', 9999);

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
        $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estagio, estado, projeto_id) VALUES (?, ?, ?, ?, ?, ?, "aberta", ?)');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('sssissi', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estagio, $projeto_id);
        
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
        $stmt = $db->prepare('UPDATE todos SET estagio = ? WHERE id = ? AND projeto_id = ?');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('sii', $new_stage, $task_id, $projeto_id);
        
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
    
    $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND projeto_id = ?');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('ii', $task_id, $projeto_id);
    
    if ($stmt->execute()) {
        $success_message = "Tarefa eliminada com sucesso!";
    } else {
        $error_message = "Erro ao eliminar tarefa: " . $stmt->error;
    }
    $stmt->close();
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
    
    // Verificar se já existe registro
    $stmt = $db->prepare('SELECT id FROM phd_info WHERE user_id = ?');
    $stmt->bind_param('i', $selected_user);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Atualizar
        $stmt = $db->prepare('UPDATE phd_info SET data_inicio = ?, titulo_doutoramento = ?, orientador = ?, coorientador = ?, instituicao = ?, departamento = ?, link_tese = ?, notas = ? WHERE user_id = ?');
        $stmt->bind_param('ssssssssi', $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas, $selected_user);
    } else {
        // Inserir
        $stmt = $db->prepare('INSERT INTO phd_info (user_id, data_inicio, titulo_doutoramento, orientador, coorientador, instituicao, departamento, link_tese, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssssss', $selected_user, $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas);
    }
    
    if ($stmt->execute()) {
        $success_message = "Informações do doutoramento guardadas com sucesso!";
    } else {
        $error_message = "Erro ao guardar informações: " . $stmt->error;
    }
    $stmt->close();
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
    $stmt->bind_param('isssssss', $selected_user, $titulo, $autores, $revista, $ano, $link, $status, $tipo);
    
    if ($stmt->execute()) {
        $success_message = "Artigo adicionado com sucesso!";
    } else {
        $error_message = "Erro ao adicionar artigo: " . $stmt->error;
    }
    $stmt->close();
}

// Eliminar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_artigo') {
    $artigo_id = intval($_POST['artigo_id']);
    
    $stmt = $db->prepare('DELETE FROM phd_artigos WHERE id = ?');
    $stmt->bind_param('i', $artigo_id);
    
    if ($stmt->execute()) {
        $success_message = "Artigo eliminado com sucesso!";
    } else {
        $error_message = "Erro ao eliminar artigo: " . $stmt->error;
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

// Obter informações do doutoramento do utilizador selecionado
$stmt = $db->prepare('SELECT * FROM phd_info WHERE user_id = ?');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$phd_info_result = $stmt->get_result();
$phd_info = $phd_info_result->fetch_assoc();
$stmt->close();

// Obter artigos do utilizador selecionado
$stmt = $db->prepare('SELECT * FROM phd_artigos WHERE user_id = ? ORDER BY ano DESC, created_at DESC');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$artigos_result = $stmt->get_result();
$artigos = [];
while ($row = $artigos_result->fetch_assoc()) {
    $artigos[] = $row;
}
$stmt->close();

// Obter tarefas do utilizador selecionado agrupadas por estágio (apenas projeto_id = 9999)
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
        WHERE t.estagio = ? AND (t.autor = ? OR t.responsavel = ?) AND t.projeto_id = ?
        ORDER BY t.created_at DESC
    ');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('siii', $stage, $selected_user, $selected_user, $projeto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tasks_by_stage[$stage] = [];
    while ($row = $result->fetch_assoc()) {
        $tasks_by_stage[$stage][] = $row;
    }
    $stmt->close();
}

// Função para calcular dias restantes
function calcular_dias_restantes($data_limite) {
    if (empty($data_limite)) return null;
    
    $hoje = new DateTime();
    $limite = new DateTime($data_limite);
    $diferenca = $hoje->diff($limite);
    
    $dias = $diferenca->days;
    if ($hoje > $limite) {
        return -$dias; // Negativo se já passou
    }
    return $dias;
}

// Função para obter cor do badge baseado nos dias restantes
function get_deadline_badge_class($dias) {
    if ($dias === null) return 'bg-secondary';
    if ($dias < 0) return 'bg-danger';
    if ($dias <= 3) return 'bg-warning text-dark';
    if ($dias <= 7) return 'bg-info';
    return 'bg-success';
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
    <h2><i class="bi bi-mortarboard-fill"></i> Gestão de Doutoramento</h2>
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

<!-- Informações do Doutoramento -->
<div class="card mb-4">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informações do Doutoramento</h5>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#phdInfoModal">
            <i class="bi bi-pencil"></i> Editar
        </button>
    </div>
    <div class="card-body">
        <?php if ($phd_info): ?>
            <div class="row">
                <div class="col-md-6">
                    <p><strong><i class="bi bi-calendar-check"></i> Data de Início:</strong> 
                        <?= $phd_info['data_inicio'] ? date('d/m/Y', strtotime($phd_info['data_inicio'])) : 'N/A' ?>
                        <?php if ($phd_info['data_inicio']): 
                            $inicio = new DateTime($phd_info['data_inicio']);
                            $hoje = new DateTime();
                            $diff = $inicio->diff($hoje);
                            $anos = $diff->y;
                            $meses = $diff->m;
                        ?>
                            <span class="badge bg-primary ms-2">
                                <?= $anos ?> ano(s) e <?= $meses ?> mês(es)
                            </span>
                        <?php endif; ?>
                    </p>
                    <p><strong><i class="bi bi-person"></i> Orientador:</strong> <?= htmlspecialchars($phd_info['orientador'] ?: 'N/A') ?></p>
                    <p><strong><i class="bi bi-person-plus"></i> Coorientador:</strong> <?= htmlspecialchars($phd_info['coorientador'] ?: 'N/A') ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong><i class="bi bi-building"></i> Instituição:</strong> <?= htmlspecialchars($phd_info['instituicao'] ?: 'N/A') ?></p>
                    <p><strong><i class="bi bi-diagram-3"></i> Departamento:</strong> <?= htmlspecialchars($phd_info['departamento'] ?: 'N/A') ?></p>
                    <?php if ($phd_info['link_tese']): ?>
                        <p><strong><i class="bi bi-link-45deg"></i> Tese:</strong> 
                            <a href="<?= htmlspecialchars($phd_info['link_tese']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-earmark-text"></i> Aceder
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($phd_info['titulo_doutoramento']): ?>
                <hr>
                <p><strong><i class="bi bi-journal-text"></i> Título:</strong></p>
                <p class="text-muted"><?= nl2br(htmlspecialchars($phd_info['titulo_doutoramento'])) ?></p>
            <?php endif; ?>
            <?php if ($phd_info['notas']): ?>
                <hr>
                <p><strong><i class="bi bi-sticky"></i> Notas:</strong></p>
                <p class="text-muted"><?= nl2br(htmlspecialchars($phd_info['notas'])) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center text-muted py-3">
                <i class="bi bi-info-circle" style="font-size: 2rem;"></i>
                <p>Nenhuma informação registada. Clique em "Editar" para adicionar.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Artigos Produzidos -->
<div class="card mb-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Artigos Produzidos (<?= count($artigos) ?>)</h5>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addArtigoModal">
            <i class="bi bi-plus-circle"></i> Adicionar Artigo
        </button>
    </div>
    <div class="card-body">
        <?php if (!empty($artigos)): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Título</th>
                            <th>Autores</th>
                            <th>Revista/Conferência</th>
                            <th>Ano</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($artigos as $artigo): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($artigo['titulo']) ?></strong></td>
                                <td><?= htmlspecialchars($artigo['autores']) ?></td>
                                <td><?= htmlspecialchars($artigo['revista_conferencia']) ?></td>
                                <td><?= $artigo['ano'] ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= htmlspecialchars(ucfirst($artigo['tipo'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $artigo['status'] === 'publicado' ? 'bg-success' : ($artigo['status'] === 'aceite' ? 'bg-primary' : 'bg-warning text-dark') ?>">
                                        <?= htmlspecialchars(ucfirst($artigo['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($artigo['link']): ?>
                                            <a href="<?= htmlspecialchars($artigo['link']) ?>" target="_blank" class="btn btn-outline-primary" title="Ver artigo">
                                                <i class="bi bi-link-45deg"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-danger delete-artigo-btn" data-artigo-id="<?= $artigo['id'] ?>" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-3">
                <i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i>
                <p>Nenhum artigo registado. Clique em "Adicionar Artigo" para começar.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Kanban Board -->
<h4 class="mb-3"><i class="bi bi-kanban"></i> Board de Tarefas</h4>
<div class="row g-3 mb-4">
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
                    <?php foreach ($tasks_by_stage[$stage] as $task): 
                        $dias_restantes = calcular_dias_restantes($task['data_limite']);
                        $badge_class = get_deadline_badge_class($dias_restantes);
                    ?>
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
                                
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php if ($task['data_limite']): ?>
                                        <span class="badge <?= $badge_class ?>">
                                            <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                            <?php if ($dias_restantes !== null): ?>
                                                (<?= $dias_restantes >= 0 ? $dias_restantes . ' dias' : 'Atrasada' ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="badge bg-light text-dark" title="Última atualização">
                                        <i class="bi bi-clock-history"></i> 
                                        <?php
                                        $updated = new DateTime($task['updated_at']);
                                        $diff = $updated->diff(new DateTime());
                                        if ($diff->days > 0) {
                                            echo $diff->days . ' dia(s)';
                                        } elseif ($diff->h > 0) {
                                            echo $diff->h . ' hora(s)';
                                        } else {
                                            echo $diff->i . ' min';
                                        }
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <?php if ($task['responsavel_nome']): ?>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($task['responsavel_nome']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span></span>
                                    <?php endif; ?>
                                    
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

<!-- Modal para editar informações do doutoramento -->
<div class="modal fade" id="phdInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="save_phd_info">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-mortarboard-fill"></i> Informações do Doutoramento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="data_inicio" class="form-label">Data de Início</label>
                            <input type="date" class="form-control" id="data_inicio" name="data_inicio" 
                                   value="<?= $phd_info['data_inicio'] ?? '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="instituicao" class="form-label">Instituição</label>
                            <input type="text" class="form-control" id="instituicao" name="instituicao" 
                                   value="<?= htmlspecialchars($phd_info['instituicao'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="orientador" class="form-label">Orientador</label>
                            <input type="text" class="form-control" id="orientador" name="orientador" 
                                   value="<?= htmlspecialchars($phd_info['orientador'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="coorientador" class="form-label">Coorientador</label>
                            <input type="text" class="form-control" id="coorientador" name="coorientador" 
                                   value="<?= htmlspecialchars($phd_info['coorientador'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="departamento" class="form-label">Departamento</label>
                        <input type="text" class="form-control" id="departamento" name="departamento" 
                               value="<?= htmlspecialchars($phd_info['departamento'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="titulo_doutoramento" class="form-label">Título do Doutoramento</label>
                        <textarea class="form-control" id="titulo_doutoramento" name="titulo_doutoramento" rows="3"><?= htmlspecialchars($phd_info['titulo_doutoramento'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="link_tese" class="form-label">Link para a Tese</label>
                        <input type="url" class="form-control" id="link_tese" name="link_tese" 
                               value="<?= htmlspecialchars($phd_info['link_tese'] ?? '') ?>" 
                               placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label for="notas" class="form-label">Notas Adicionais</label>
                        <textarea class="form-control" id="notas" name="notas" rows="4"><?= htmlspecialchars($phd_info['notas'] ?? '') ?></textarea>
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

<!-- Modal para adicionar artigo -->
<div class="modal fade" id="addArtigoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_artigo">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-plus"></i> Adicionar Artigo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titulo_artigo" class="form-label">Título do Artigo *</label>
                        <input type="text" class="form-control" id="titulo_artigo" name="titulo_artigo" required>
                    </div>
                    <div class="mb-3">
                        <label for="autores" class="form-label">Autores *</label>
                        <input type="text" class="form-control" id="autores" name="autores" 
                               placeholder="Ex: Silva, J.; Santos, M.; Costa, P." required>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="revista_conferencia" class="form-label">Revista/Conferência *</label>
                            <input type="text" class="form-control" id="revista_conferencia" name="revista_conferencia" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="ano" class="form-label">Ano *</label>
                            <input type="number" class="form-control" id="ano" name="ano" 
                                   min="2000" max="2099" value="<?= date('Y') ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="tipo_artigo" class="form-label">Tipo</label>
                            <select class="form-select" id="tipo_artigo" name="tipo_artigo">
                                <option value="artigo">Artigo</option>
                                <option value="conferencia">Conferência</option>
                                <option value="poster">Poster</option>
                                <option value="workshop">Workshop</option>
                                <option value="capitulo">Capítulo de Livro</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status_artigo" class="form-label">Status</label>
                            <select class="form-select" id="status_artigo" name="status_artigo">
                                <option value="publicado">Publicado</option>
                                <option value="aceite">Aceite</option>
                                <option value="submetido">Submetido</option>
                                <option value="em_preparacao">Em Preparação</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="link_artigo" class="form-label">Link (DOI, URL, etc.)</label>
                        <input type="url" class="form-control" id="link_artigo" name="link_artigo" 
                               placeholder="https://...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Adicionar Artigo</button>
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
            urlParams.set('tab', 'phd_kanban');
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
                        
                        // Calcular dias restantes
                        let diasInfo = '';
                        if (task.data_limite) {
                            const hoje = new Date();
                            const limite = new Date(task.data_limite);
                            const diffTime = limite - hoje;
                            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                            
                            let badgeClass = 'secondary';
                            if (diffDays < 0) badgeClass = 'danger';
                            else if (diffDays <= 3) badgeClass = 'warning';
                            else if (diffDays <= 7) badgeClass = 'info';
                            else badgeClass = 'success';
                            
                            diasInfo = `<span class="badge bg-${badgeClass}">
                                ${diffDays >= 0 ? diffDays + ' dias restantes' : 'Atrasada'}
                            </span>`;
                        }
                        
                        const content = `
                            <div class="task-details">
                                <h4>${task.titulo}</h4>
                                ${task.descritivo ? `<p class="text-muted">${task.descritivo}</p>` : ''}
                                <hr>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Autor:</strong> ${task.autor_nome || 'N/A'}</p>
                                        <p><strong>Responsável:</strong> ${task.responsavel_nome || 'N/A'}</p>
                                        <p><strong>Estágio:</strong> <span class="badge bg-info">${task.estagio}</span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Data Limite:</strong> ${task.data_limite ? new Date(task.data_limite).toLocaleDateString('pt-PT') : 'N/A'} ${diasInfo}</p>
                                        <p><strong>Estado:</strong> <span class="badge bg-secondary">${task.estado}</span></p>
                                    </div>
                                </div>
                                <hr>
                                <p class="small text-muted">
                                    <strong>Criada em:</strong> ${new Date(task.created_at).toLocaleString('pt-PT')}<br>
                                    <strong>Última atualização:</strong> ${new Date(task.updated_at).toLocaleString('pt-PT')}
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