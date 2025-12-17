<?php
/**
 * LAB MANAGEMENT - Gestão de Assuntos do Laboratório
 * Acesso restrito a utilizadores autorizados
 */

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar à base de dados
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $db->set_charset('utf8mb4');
    
    if ($db->connect_error) {
        throw new Exception("Erro de conexão: " . $db->connect_error);
    }
} catch (Exception $e) {
    die('<div class="alert alert-danger">Erro ao conectar à base de dados: ' . $e->getMessage() . '</div>');
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$success_message = '';
$error_message = '';

// Verificar se as tabelas existem
$tabelas_necessarias = [
    'lab_authorized_users',
    'lab_issues',
    'lab_issue_updates',
    'lab_issue_tasks',
    'lab_issue_participants'
];

$tabelas_faltam = [];
foreach ($tabelas_necessarias as $tabela) {
    $result = $db->query("SHOW TABLES LIKE '$tabela'");
    if ($result->num_rows == 0) {
        $tabelas_faltam[] = $tabela;
    }
}

if (!empty($tabelas_faltam)) {
    echo '<div class="alert alert-warning">';
    echo '<h5><i class="bi bi-exclamation-triangle"></i> Configuração Inicial Necessária</h5>';
    echo '<p>As seguintes tabelas precisam ser criadas na base de dados:</p>';
    echo '<ul>';
    foreach ($tabelas_faltam as $tabela) {
        echo "<li><code>$tabela</code></li>";
    }
    echo '</ul>';
    echo '<p class="mb-0"><strong>Execute o ficheiro SQL fornecido (lab_management_tables.sql) na sua base de dados MySQL.</strong></p>';
    echo '</div>';
    $db->close();
    return;
}

// Verificar se o utilizador está autorizado
$stmt = $db->prepare("SELECT COUNT(*) as total FROM lab_authorized_users");
$stmt->execute();
$result = $stmt->get_result();
$total_autorizados = $result->fetch_assoc()['total'];
$stmt->close();

// Se não há ninguém autorizado, qualquer um pode se autorizar (configuração inicial)
$is_authorized = false;
$can_authorize_others = false;

if ($total_autorizados == 0) {
    // Primeira configuração - qualquer utilizador pode se adicionar
    $stmt = $db->prepare("INSERT IGNORE INTO lab_authorized_users (user_id, username, authorized_by) VALUES (?, ?, NULL)");
    $stmt->bind_param('is', $user_id, $username);
    $stmt->execute();
    $stmt->close();
    $is_authorized = true;
    $can_authorize_others = true;
    $success_message = "Bem-vindo! Você foi configurado como o primeiro administrador da Gestão do Laboratório.";
} else {
    // Verificar se o utilizador está autorizado
    $stmt = $db->prepare("SELECT id FROM lab_authorized_users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $is_authorized = $result->num_rows > 0;
    $can_authorize_others = $is_authorized; // Qualquer utilizador autorizado pode adicionar outros
    $stmt->close();
}

// Se não está autorizado, mostrar mensagem e sair
if (!$is_authorized) {
    echo '<div class="alert alert-danger">';
    echo '<i class="bi bi-lock"></i> <strong>Acesso Negado</strong><br>';
    echo 'Esta área é restrita a utilizadores autorizados. Contacte um administrador da Gestão do Laboratório para obter acesso.';
    echo '</div>';
    $db->close();
    return;
}

// Obter todos os utilizadores do sistema
$all_users = [];
$stmt = $db->prepare('SELECT user_id, username FROM user_tokens ORDER BY username');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_users[$row['user_id']] = $row['username'];
}
$stmt->close();

// Obter utilizadores autorizados
$authorized_users = [];
$stmt = $db->prepare('SELECT user_id FROM lab_authorized_users');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $authorized_users[] = $row['user_id'];
}
$stmt->close();

// ===== PROCESSAR AÇÕES =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // AUTORIZAR NOVO UTILIZADOR
    if ($action === 'authorize_user' && $can_authorize_others) {
        $new_user_id = (int)$_POST['user_id'];
        
        if (!in_array($new_user_id, $authorized_users)) {
            $stmt = $db->prepare("INSERT INTO lab_authorized_users (user_id, username, authorized_by) VALUES (?, ?, ?)");
            $new_username = $all_users[$new_user_id] ?? 'Unknown';
            $stmt->bind_param('isi', $new_user_id, $new_username, $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Utilizador autorizado com sucesso!";
                $authorized_users[] = $new_user_id;
            } else {
                $error_message = "Erro ao autorizar utilizador.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management");
        exit;
    }
    
    // REMOVER AUTORIZAÇÃO
    if ($action === 'remove_authorization' && $can_authorize_others) {
        $remove_user_id = (int)$_POST['user_id'];
        
        // Não permitir remover a si próprio se for o único autorizado
        if ($remove_user_id == $user_id && count($authorized_users) == 1) {
            $error_message = "Não pode remover a sua própria autorização se for o único utilizador autorizado.";
        } else {
            $stmt = $db->prepare("DELETE FROM lab_authorized_users WHERE user_id = ?");
            $stmt->bind_param('i', $remove_user_id);
            
            if ($stmt->execute()) {
                $success_message = "Autorização removida com sucesso!";
                $authorized_users = array_diff($authorized_users, [$remove_user_id]);
            } else {
                $error_message = "Erro ao remover autorização.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management");
        exit;
    }
    
    // CRIAR NOVO ASSUNTO
    if ($action === 'create_issue') {
        $titulo = trim($_POST['titulo']);
        $descricao = trim($_POST['descricao'] ?? '');
        $prioridade = $_POST['prioridade'] ?? 'media';
        
        if (!empty($titulo)) {
            $stmt = $db->prepare("INSERT INTO lab_issues (titulo, descricao, prioridade, criado_por) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $titulo, $descricao, $prioridade, $user_id);
            
            if ($stmt->execute()) {
                $issue_id = $db->insert_id;
                
                // Adicionar o criador como participante responsável
                $stmt2 = $db->prepare("INSERT INTO lab_issue_participants (issue_id, user_id, role) VALUES (?, ?, 'responsavel')");
                $stmt2->bind_param('ii', $issue_id, $user_id);
                $stmt2->execute();
                $stmt2->close();
                
                $success_message = "Assunto criado com sucesso!";
            } else {
                $error_message = "Erro ao criar assunto.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management");
        exit;
    }
    
    // ATUALIZAR STATUS DO ASSUNTO
    if ($action === 'update_status') {
        $issue_id = (int)$_POST['issue_id'];
        $new_status = $_POST['status'];
        
        $valid_status = ['ativo', 'suspenso', 'resolvido'];
        if (in_array($new_status, $valid_status)) {
            if ($new_status === 'resolvido') {
                $stmt = $db->prepare("UPDATE lab_issues SET status = ?, resolvido_em = NOW(), resolvido_por = ? WHERE id = ?");
                $stmt->bind_param('sii', $new_status, $user_id, $issue_id);
            } else {
                $stmt = $db->prepare("UPDATE lab_issues SET status = ?, resolvido_em = NULL, resolvido_por = NULL WHERE id = ?");
                $stmt->bind_param('si', $new_status, $issue_id);
            }
            
            if ($stmt->execute()) {
                $success_message = "Status atualizado com sucesso!";
            } else {
                $error_message = "Erro ao atualizar status.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management");
        exit;
    }
    
    // ADICIONAR DECISÃO/COMENTÁRIO
    if ($action === 'add_update') {
        $issue_id = (int)$_POST['issue_id'];
        $tipo = $_POST['tipo'] ?? 'comentario';
        $conteudo = trim($_POST['conteudo']);
        
        if (!empty($conteudo)) {
            $stmt = $db->prepare("INSERT INTO lab_issue_updates (issue_id, user_id, tipo, conteudo) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiss', $issue_id, $user_id, $tipo, $conteudo);
            
            if ($stmt->execute()) {
                $success_message = "Atualização adicionada com sucesso!";
            } else {
                $error_message = "Erro ao adicionar atualização.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management&issue_id=$issue_id");
        exit;
    }
    
    // CRIAR TAREFA ASSOCIADA
    if ($action === 'create_task') {
        $issue_id = (int)$_POST['issue_id'];
        $task_titulo = trim($_POST['task_titulo']);
        $task_descricao = trim($_POST['task_descricao'] ?? '');
        $task_responsavel = (int)$_POST['task_responsavel'];
        $task_data_limite = $_POST['task_data_limite'] ?: null;
        
        if (!empty($task_titulo)) {
            // Criar a tarefa na tabela todos
            $stmt = $db->prepare("INSERT INTO todos (titulo, descritivo, autor, responsavel, estado, data_limite, projeto_id) VALUES (?, ?, ?, ?, 'aberta', ?, 0)");
            $stmt->bind_param('ssiis', $task_titulo, $task_descricao, $user_id, $task_responsavel, $task_data_limite);
            
            if ($stmt->execute()) {
                $todo_id = $db->insert_id;
                
                // Associar a tarefa ao assunto
                $stmt2 = $db->prepare("INSERT INTO lab_issue_tasks (issue_id, todo_id) VALUES (?, ?)");
                $stmt2->bind_param('ii', $issue_id, $todo_id);
                $stmt2->execute();
                $stmt2->close();
                
                $success_message = "Tarefa criada e associada com sucesso!";
            } else {
                $error_message = "Erro ao criar tarefa.";
            }
            $stmt->close();
        }
        
        header("Location: ?tab=lab_management&issue_id=$issue_id");
        exit;
    }
}

// ===== OBTER DADOS PARA EXIBIÇÃO =====

// Verificar se estamos visualizando um assunto específico
$view_issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : null;

if ($view_issue_id) {
    // VISUALIZAÇÃO DETALHADA DE UM ASSUNTO
    
    // Obter informações do assunto
    $stmt = $db->prepare("
        SELECT i.*, u.username as criador_nome, r.username as resolvido_por_nome
        FROM lab_issues i
        LEFT JOIN user_tokens u ON i.criado_por = u.user_id
        LEFT JOIN user_tokens r ON i.resolvido_por = r.user_id
        WHERE i.id = ?
    ");
    $stmt->bind_param('i', $view_issue_id);
    $stmt->execute();
    $issue = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$issue) {
        echo '<div class="alert alert-danger">Assunto não encontrado.</div>';
        $db->close();
        return;
    }
    
    // Obter atualizações/decisões
    $stmt = $db->prepare("
        SELECT u.*, t.username
        FROM lab_issue_updates u
        LEFT JOIN user_tokens t ON u.user_id = t.user_id
        WHERE u.issue_id = ?
        ORDER BY u.criado_em DESC
    ");
    $stmt->bind_param('i', $view_issue_id);
    $stmt->execute();
    $updates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Obter tarefas associadas
    $stmt = $db->prepare("
        SELECT t.*, lit.id as link_id, u1.username as autor_nome, u2.username as responsavel_nome
        FROM lab_issue_tasks lit
        JOIN todos t ON lit.todo_id = t.id
        LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
        LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
        WHERE lit.issue_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->bind_param('i', $view_issue_id);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Obter participantes
    $stmt = $db->prepare("
        SELECT p.*, u.username
        FROM lab_issue_participants p
        LEFT JOIN user_tokens u ON p.user_id = u.user_id
        WHERE p.issue_id = ?
        ORDER BY 
            CASE p.role 
                WHEN 'responsavel' THEN 1 
                WHEN 'colaborador' THEN 2 
                WHEN 'observador' THEN 3 
            END,
            u.username
    ");
    $stmt->bind_param('i', $view_issue_id);
    $stmt->execute();
    $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} else {
    // LISTAGEM DE ASSUNTOS
    
    // Filtro de status
    $filter_status = $_GET['filter'] ?? 'all';
    
    $query = "
        SELECT i.*, u.username as criador_nome, 
               COUNT(DISTINCT lit.id) as num_tarefas,
               COUNT(DISTINCT liu.id) as num_updates
        FROM lab_issues i
        LEFT JOIN user_tokens u ON i.criado_por = u.user_id
        LEFT JOIN lab_issue_tasks lit ON i.id = lit.issue_id
        LEFT JOIN lab_issue_updates liu ON i.id = liu.issue_id
    ";
    
    if ($filter_status !== 'all') {
        $query .= " WHERE i.status = ?";
    }
    
    $query .= " GROUP BY i.id ORDER BY i.atualizado_em DESC";
    
    $stmt = $db->prepare($query);
    
    if ($filter_status !== 'all') {
        $stmt->bind_param('s', $filter_status);
    }
    
    $stmt->execute();
    $issues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

?>

<!-- CSS específico do Lab Management -->
<style>
    .lab-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .issue-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .issue-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .issue-card.status-ativo {
            border-left-color: #28a745;
        }
        
        .issue-card.status-suspenso {
            border-left-color: #ffc107;
        }
        
        .issue-card.status-resolvido {
            border-left-color: #6c757d;
            opacity: 0.7;
        }
        
        .priority-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .update-item {
            border-left: 3px solid #e9ecef;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        
        .update-item.decisao {
            border-left-color: #667eea;
            background-color: #f8f9ff;
        }
        
        .task-item {
            border-left: 3px solid #17a2b8;
            padding-left: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .participant-badge {
            margin: 0.2rem;
        }
        
        .auth-section {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($view_issue_id && $issue): ?>
    <!-- VISUALIZAÇÃO DETALHADA DE UM ASSUNTO -->
    
    <div class="mb-3">
        <a href="?tab=lab_management" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Voltar à Lista
        </a>
    </div>
    
    <div class="lab-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2">
                    <i class="bi bi-folder-open"></i> <?= htmlspecialchars($issue['titulo']) ?>
                </h2>
                <p class="mb-0">
                    <small>
                        Criado por <?= htmlspecialchars($issue['criador_nome']) ?> 
                        em <?= date('d/m/Y H:i', strtotime($issue['criado_em'])) ?>
                    </small>
                </p>
            </div>
            <div class="col-md-4 text-end">
                <?php
                $status_classes = [
                    'ativo' => 'success',
                    'suspenso' => 'warning',
                    'resolvido' => 'secondary'
                ];
                $status_icons = [
                    'ativo' => 'play-circle',
                    'suspenso' => 'pause-circle',
                    'resolvido' => 'check-circle'
                ];
                ?>
                <span class="badge bg-<?= $status_classes[$issue['status']] ?> fs-5">
                    <i class="bi bi-<?= $status_icons[$issue['status']] ?>"></i>
                    <?= ucfirst($issue['status']) ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Descrição -->
    <?php if (!empty($issue['descricao'])): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-file-text"></i> Descrição</h5>
        </div>
        <div class="card-body">
            <?= nl2br(htmlspecialchars($issue['descricao'])) ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Alterar Status -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-sliders"></i> Alterar Status</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="issue_id" value="<?= $view_issue_id ?>">
                <div class="col-md-6">
                    <select name="status" class="form-select" required>
                        <option value="ativo" <?= $issue['status'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="suspenso" <?= $issue['status'] === 'suspenso' ? 'selected' : '' ?>>Suspenso</option>
                        <option value="resolvido" <?= $issue['status'] === 'resolvido' ? 'selected' : '' ?>>Resolvido</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Atualizar Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Decisões e Comentários -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-chat-dots"></i> Decisões e Comentários</h5>
        </div>
        <div class="card-body">
            <!-- Formulário para adicionar -->
            <form method="post" class="mb-4">
                <input type="hidden" name="action" value="add_update">
                <input type="hidden" name="issue_id" value="<?= $view_issue_id ?>">
                <div class="mb-3">
                    <label class="form-label">Tipo:</label>
                    <select name="tipo" class="form-select" required>
                        <option value="comentario">Comentário</option>
                        <option value="decisao">Decisão</option>
                        <option value="update">Atualização</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Conteúdo:</label>
                    <textarea name="conteudo" class="form-control" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Adicionar
                </button>
            </form>
            
            <hr>
            
            <!-- Lista de atualizações -->
            <?php if (empty($updates)): ?>
                <p class="text-muted">Nenhuma atualização registada ainda.</p>
            <?php else: ?>
                <?php foreach ($updates as $update): ?>
                    <div class="update-item <?= $update['tipo'] ?>">
                        <div class="d-flex justify-content-between">
                            <strong>
                                <?php
                                $tipo_icons = ['comentario' => 'chat', 'decisao' => 'check-circle', 'update' => 'info-circle'];
                                ?>
                                <i class="bi bi-<?= $tipo_icons[$update['tipo']] ?>"></i>
                                <?= htmlspecialchars($update['username']) ?>
                                <?php if ($update['tipo'] === 'decisao'): ?>
                                    <span class="badge bg-primary">Decisão</span>
                                <?php endif; ?>
                            </strong>
                            <small class="text-muted">
                                <?= date('d/m/Y H:i', strtotime($update['criado_em'])) ?>
                            </small>
                        </div>
                        <p class="mb-1 mt-2"><?= nl2br(htmlspecialchars($update['conteudo'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tarefas Associadas -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-list-check"></i> Tarefas Associadas</h5>
        </div>
        <div class="card-body">
            <!-- Formulário para criar tarefa -->
            <button class="btn btn-success mb-3" data-bs-toggle="collapse" data-bs-target="#createTaskForm">
                <i class="bi bi-plus-circle"></i> Nova Tarefa
            </button>
            
            <div class="collapse mb-3" id="createTaskForm">
                <div class="card card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="create_task">
                        <input type="hidden" name="issue_id" value="<?= $view_issue_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Título da Tarefa:</label>
                            <input type="text" name="task_titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição:</label>
                            <textarea name="task_descricao" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Responsável:</label>
                                <select name="task_responsavel" class="form-select" required>
                                    <?php foreach ($all_users as $uid => $uname): ?>
                                        <option value="<?= $uid ?>" <?= $uid == $user_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($uname) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Limite:</label>
                                <input type="date" name="task_data_limite" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Criar Tarefa
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Lista de tarefas -->
            <?php if (empty($tasks)): ?>
                <p class="text-muted">Nenhuma tarefa associada ainda.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Tarefa</th>
                                <th>Responsável</th>
                                <th>Estado</th>
                                <th>Data Limite</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($task['titulo']) ?></strong>
                                        <?php if (!empty($task['descritivo'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(substr($task['descritivo'], 0, 50)) ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($task['responsavel_nome']) ?></td>
                                    <td>
                                        <?php
                                        $estado_badges = [
                                            'aberta' => 'secondary',
                                            'em execução' => 'primary',
                                            'suspensa' => 'warning',
                                            'concluída' => 'success'
                                        ];
                                        $badge_class = $estado_badges[$task['estado']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $badge_class ?>">
                                            <?= ucfirst($task['estado']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($task['data_limite']): ?>
                                            <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?tab=todos#task-<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- LISTAGEM DE ASSUNTOS -->
    
    <div class="lab-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-0"><i class="bi bi-building"></i> Gestão do Laboratório</h2>
                <p class="mb-0 mt-2">Sistema de gestão de assuntos e decisões do laboratório</p>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#createIssueModal">
                    <i class="bi bi-plus-circle"></i> Novo Assunto
                </button>
            </div>
        </div>
    </div>
    
    <!-- Gestão de Utilizadores Autorizados -->
    <?php if ($can_authorize_others): ?>
    <div class="auth-section mb-4">
        <h5><i class="bi bi-shield-check"></i> Utilizadores Autorizados</h5>
        <p class="small mb-3">
            Gerir quem tem acesso à Gestão do Laboratório. 
            <span class="badge bg-info"><?= count($authorized_users) ?> utilizador(es) autorizado(s)</span>
        </p>
        
        <button class="btn btn-sm btn-warning mb-2" data-bs-toggle="collapse" data-bs-target="#manageAuth">
            <i class="bi bi-gear"></i> Gerir Autorizações
        </button>
        
        <div class="collapse" id="manageAuth">
            <div class="row mt-3">
                <div class="col-md-6">
                    <h6>Adicionar Utilizador</h6>
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="action" value="authorize_user">
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value="">Selecionar utilizador...</option>
                            <?php foreach ($all_users as $uid => $uname): ?>
                                <?php if (!in_array($uid, $authorized_users)): ?>
                                    <option value="<?= $uid ?>"><?= htmlspecialchars($uname) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-plus"></i> Adicionar
                        </button>
                    </form>
                </div>
                <div class="col-md-6">
                    <h6>Utilizadores Autorizados</h6>
                    <div class="list-group">
                        <?php foreach ($authorized_users as $auth_user_id): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="bi bi-person-check"></i>
                                    <?= htmlspecialchars($all_users[$auth_user_id] ?? 'Unknown') ?>
                                    <?php if ($auth_user_id == $user_id): ?>
                                        <span class="badge bg-primary">Você</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($auth_user_id != $user_id || count($authorized_users) > 1): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remover autorização deste utilizador?')">
                                        <input type="hidden" name="action" value="remove_authorization">
                                        <input type="hidden" name="user_id" value="<?= $auth_user_id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="mb-3 d-flex gap-2">
        <a href="?tab=lab_management&filter=all" class="btn btn-sm <?= $filter_status === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="bi bi-list"></i> Todos
        </a>
        <a href="?tab=lab_management&filter=ativo" class="btn btn-sm <?= $filter_status === 'ativo' ? 'btn-success' : 'btn-outline-success' ?>">
            <i class="bi bi-play-circle"></i> Ativos
        </a>
        <a href="?tab=lab_management&filter=suspenso" class="btn btn-sm <?= $filter_status === 'suspenso' ? 'btn-warning' : 'btn-outline-warning' ?>">
            <i class="bi bi-pause-circle"></i> Suspensos
        </a>
        <a href="?tab=lab_management&filter=resolvido" class="btn btn-sm <?= $filter_status === 'resolvido' ? 'btn-secondary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-check-circle"></i> Resolvidos
        </a>
    </div>
    
    <!-- Lista de Assuntos -->
    <?php if (empty($issues)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Nenhum assunto encontrado. Crie o primeiro assunto usando o botão acima.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($issues as $iss): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card issue-card status-<?= $iss['status'] ?>" onclick="window.location='?tab=lab_management&issue_id=<?= $iss['id'] ?>'">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?= htmlspecialchars($iss['titulo']) ?></h5>
                                <?php
                                $priority_colors = [
                                    'baixa' => 'secondary',
                                    'media' => 'info',
                                    'alta' => 'warning',
                                    'urgente' => 'danger'
                                ];
                                ?>
                                <span class="badge bg-<?= $priority_colors[$iss['prioridade']] ?> priority-badge">
                                    <?= ucfirst($iss['prioridade']) ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($iss['descricao'])): ?>
                                <p class="card-text text-muted small">
                                    <?= htmlspecialchars(substr($iss['descricao'], 0, 100)) ?>
                                    <?= strlen($iss['descricao']) > 100 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($iss['criador_nome']) ?>
                                </small>
                                <div>
                                    <?php if ($iss['num_tarefas'] > 0): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-list-check"></i> <?= $iss['num_tarefas'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($iss['num_updates'] > 0): ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-chat"></i> <?= $iss['num_updates'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <hr class="my-2">
                            
                            <small class="text-muted">
                                <i class="bi bi-clock"></i>
                                Atualizado <?= date('d/m/Y H:i', strtotime($iss['atualizado_em'])) ?>
                            </small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Modal para Criar Novo Assunto -->
    <div class="modal fade" id="createIssueModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Novo Assunto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="create_issue">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Título:</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição:</label>
                            <textarea name="descricao" class="form-control" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prioridade:</label>
                            <select name="prioridade" class="form-select">
                                <option value="baixa">Baixa</option>
                                <option value="media" selected>Média</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Criar Assunto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php $db->close(); ?>

<!-- Script específico para Lab Management -->
<script>
// Adicionar aqui scripts JavaScript específicos se necessário no futuro
</script>