<?php
// tabs/leads.php - Gestão de Leads/Oportunidades de Projetos
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro de conexão: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Verificar e criar tabelas necessárias
$tables_check = $pdo->query("SHOW TABLES LIKE 'leads'")->rowCount();
if ($tables_check == 0) {
    $pdo->exec("
        CREATE TABLE leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(255) NOT NULL,
            descricao TEXT,
            relevancia INT DEFAULT 5,
            responsavel_id INT,
            data_inicio DATE,
            data_fim DATE,
            estado ENUM('aberta', 'fechada') DEFAULT 'aberta',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (responsavel_id) REFERENCES user_tokens(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE lead_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            user_id INT NOT NULL,
            adicionado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
            UNIQUE KEY unique_member (lead_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE lead_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            url TEXT NOT NULL,
            adicionado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE lead_kanban (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            coluna ENUM('todo', 'doing', 'done') DEFAULT 'todo',
            posicao INT DEFAULT 0,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$current_user_id = $_SESSION['user_id'] ?? null;
$message = $_GET['message'] ?? '';
$messageType = $_GET['type'] ?? 'success';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_lead':
                $stmt = $pdo->prepare("
                    INSERT INTO leads (titulo, descricao, relevancia, responsavel_id, data_inicio, data_fim, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['titulo'],
                    $_POST['descricao'] ?? '',
                    $_POST['relevancia'] ?? 5,
                    $_POST['responsavel_id'] ?: null,
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    'aberta'
                ]);
                $message = "Lead criado com sucesso!";
                break;
                
            case 'update_lead':
                $stmt = $pdo->prepare("
                    UPDATE leads 
                    SET titulo=?, descricao=?, relevancia=?, responsavel_id=?, data_inicio=?, data_fim=?, estado=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['titulo'],
                    $_POST['descricao'] ?? '',
                    $_POST['relevancia'] ?? 5,
                    $_POST['responsavel_id'] ?: null,
                    $_POST['data_inicio'] ?: null,
                    $_POST['data_fim'] ?: null,
                    $_POST['estado'] ?? 'aberta',
                    $_POST['lead_id']
                ]);
                $message = "Lead atualizado com sucesso!";
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("INSERT IGNORE INTO lead_members (lead_id, user_id) VALUES (?, ?)");
                $stmt->execute([$_POST['lead_id'], $_POST['user_id']]);
                $message = "Membro adicionado!";
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM lead_members WHERE lead_id=? AND user_id=?");
                $stmt->execute([$_POST['lead_id'], $_POST['user_id']]);
                $message = "Membro removido!";
                break;
                
            case 'add_link':
                $stmt = $pdo->prepare("INSERT INTO lead_links (lead_id, titulo, url) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['lead_id'], $_POST['link_titulo'], $_POST['link_url']]);
                $message = "Link adicionado!";
                break;
                
            case 'remove_link':
                $stmt = $pdo->prepare("DELETE FROM lead_links WHERE id=?");
                $stmt->execute([$_POST['link_id']]);
                $message = "Link removido!";
                break;
                
            case 'add_kanban_item':
                $stmt = $pdo->prepare("INSERT INTO lead_kanban (lead_id, titulo, coluna) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['lead_id'], $_POST['kanban_titulo'], $_POST['kanban_coluna']]);
                $message = "Item adicionado ao Kanban!";
                break;
                
            case 'update_kanban_item':
                $stmt = $pdo->prepare("UPDATE lead_kanban SET coluna=? WHERE id=?");
                $stmt->execute([$_POST['kanban_coluna'], $_POST['kanban_id']]);
                $message = "Item movido!";
                break;
                
            case 'delete_kanban_item':
                $stmt = $pdo->prepare("DELETE FROM lead_kanban WHERE id=?");
                $stmt->execute([$_POST['kanban_id']]);
                $message = "Item removido do Kanban!";
                break;
        }
        
        if (!headers_sent()) {
            header("Location: ?tab=leads&message=" . urlencode($message) . "&type=success&lead_id=" . ($_POST['lead_id'] ?? ''));
            exit;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obter filtros
$filter_my_leads = isset($_GET['filter_my_leads']) ? $_GET['filter_my_leads'] === '1' : false;
$filter_involved = isset($_GET['filter_involved']) ? $_GET['filter_involved'] === '1' : false;
$selected_lead_id = $_GET['lead_id'] ?? null;

// Buscar leads
$query = "
    SELECT DISTINCT l.*, u.username as responsavel_nome,
           CASE WHEN l.responsavel_id = ? THEN 1 ELSE 0 END as is_responsible,
           CASE WHEN lm.user_id IS NOT NULL THEN 1 ELSE 0 END as is_member
    FROM leads l
    LEFT JOIN user_tokens u ON l.responsavel_id = u.user_id
    LEFT JOIN lead_members lm ON l.id = lm.lead_id AND lm.user_id = ?
    WHERE 1=1
";

$params = [$current_user_id, $current_user_id];

if ($filter_my_leads) {
    $query .= " AND l.responsavel_id = ?";
    $params[] = $current_user_id;
} elseif ($filter_involved) {
    $query .= " AND (l.responsavel_id = ? OR lm.user_id = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

$query .= " ORDER BY l.estado ASC, l.relevancia DESC, l.data_fim ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se um lead foi selecionado, buscar seus detalhes
$selected_lead = null;
$lead_members = [];
$lead_links = [];
$lead_kanban = [];

if ($selected_lead_id) {
    $stmt = $pdo->prepare("SELECT l.*, u.username as responsavel_nome FROM leads l LEFT JOIN user_tokens u ON l.responsavel_id = u.user_id WHERE l.id = ?");
    $stmt->execute([$selected_lead_id]);
    $selected_lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_lead) {
        // Buscar membros
        $stmt = $pdo->prepare("
            SELECT lm.*, u.username 
            FROM lead_members lm 
            JOIN user_tokens u ON lm.user_id = u.user_id 
            WHERE lm.lead_id = ?
        ");
        $stmt->execute([$selected_lead_id]);
        $lead_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar links
        $stmt = $pdo->prepare("SELECT * FROM lead_links WHERE lead_id = ? ORDER BY adicionado_em DESC");
        $stmt->execute([$selected_lead_id]);
        $lead_links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar itens do kanban
        $stmt = $pdo->prepare("SELECT * FROM lead_kanban WHERE lead_id = ? ORDER BY posicao ASC, criado_em ASC");
        $stmt->execute([$selected_lead_id]);
        $lead_kanban = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Buscar todos os usuários para seleção
$all_users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .leads-container {
        display: flex;
        height: calc(100vh - 200px);
        gap: 15px;
    }
    
    .leads-sidebar {
        width: 350px;
        min-width: 350px;
        border-right: 1px solid #dee2e6;
        overflow-y: auto;
        padding-right: 15px;
    }
    
    .leads-content {
        flex: 1;
        overflow-y: auto;
        padding-left: 15px;
    }
    
    .lead-item {
        padding: 12px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .lead-item:hover {
        background-color: #f8f9fa;
        border-color: #0d6efd;
        transform: translateX(5px);
    }
    
    .lead-item.active {
        background-color: #e7f1ff;
        border-color: #0d6efd;
        border-width: 2px;
    }
    
    .lead-item.fechada {
        opacity: 0.6;
    }
    
    .relevancia-badge {
        font-weight: bold;
        min-width: 30px;
        text-align: center;
    }
    
    .kanban-board {
        display: flex;
        gap: 15px;
        margin-top: 20px;
    }
    
    .kanban-column {
        flex: 1;
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        min-height: 300px;
    }
    
    .kanban-column h5 {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid #dee2e6;
    }
    
    .kanban-item {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 10px;
        margin-bottom: 10px;
        cursor: move;
    }
    
    .kanban-item:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.5;
    }
</style>

<div class="container-fluid mt-4">
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="bi bi-bullseye"></i> Gestão de Leads / Oportunidades</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createLeadModal">
            <i class="bi bi-plus-circle"></i> Novo Lead
        </button>
    </div>
    
    <!-- Filtros -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex gap-2 flex-wrap">
                <a href="?tab=leads" class="btn btn-sm <?= !$filter_my_leads && !$filter_involved ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-list-ul"></i> Todos os Leads
                </a>
                <a href="?tab=leads&filter_my_leads=1" class="btn btn-sm <?= $filter_my_leads ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-person-check"></i> Meus Leads (Responsável)
                </a>
                <a href="?tab=leads&filter_involved=1" class="btn btn-sm <?= $filter_involved ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-people"></i> Estou Envolvido
                </a>
            </div>
        </div>
    </div>
    
    <div class="leads-container">
        <!-- Sidebar com lista de leads -->
        <div class="leads-sidebar">
            <h5 class="mb-3">Leads (<?= count($leads) ?>)</h5>
            
            <?php if (empty($leads)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <p class="mt-3">Nenhum lead encontrado</p>
                </div>
            <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                    <div class="lead-item <?= $lead['estado'] ?> <?= $selected_lead_id == $lead['id'] ? 'active' : '' ?>" 
                         onclick="window.location.href='?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&lead_id=<?= $lead['id'] ?>'">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <strong class="flex-grow-1"><?= htmlspecialchars($lead['titulo']) ?></strong>
                            <span class="badge relevancia-badge bg-<?= $lead['relevancia'] >= 8 ? 'danger' : ($lead['relevancia'] >= 5 ? 'warning' : 'secondary') ?>">
                                <?= $lead['relevancia'] ?>
                            </span>
                        </div>
                        
                        <div class="small text-muted">
                            <?php if ($lead['responsavel_nome']): ?>
                                <i class="bi bi-person"></i> <?= htmlspecialchars($lead['responsavel_nome']) ?>
                            <?php endif; ?>
                            
                            <?php if ($lead['is_member']): ?>
                                <span class="badge bg-info ms-2">Membro</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="small text-muted mt-1">
                            <?php if ($lead['data_fim']): ?>
                                <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($lead['data_fim'])) ?>
                            <?php endif; ?>
                            
                            <span class="badge bg-<?= $lead['estado'] == 'aberta' ? 'success' : 'secondary' ?> ms-2">
                                <?= ucfirst($lead['estado']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Conteúdo principal com detalhes do lead -->
        <div class="leads-content">
            <?php if (!$selected_lead): ?>
                <div class="empty-state">
                    <i class="bi bi-arrow-left-circle"></i>
                    <h4>Selecione um lead ao lado para ver os detalhes</h4>
                    <p>Ou crie um novo lead usando o botão acima</p>
                </div>
            <?php else: ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><?= htmlspecialchars($selected_lead['titulo']) ?></h4>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editLeadModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                    </div>
                    <div class="card-body">
                        <!-- Relevância -->
                        <div class="mb-3">
                            <strong>Relevância:</strong>
                            <span class="badge bg-<?= $selected_lead['relevancia'] >= 8 ? 'danger' : ($selected_lead['relevancia'] >= 5 ? 'warning' : 'secondary') ?> ms-2">
                                <?= $selected_lead['relevancia'] ?>/10
                            </span>
                        </div>
                        
                        <!-- Responsável -->
                        <div class="mb-3">
                            <strong>Responsável:</strong>
                            <?php if ($selected_lead['responsavel_nome']): ?>
                                <span class="badge bg-primary ms-2">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($selected_lead['responsavel_nome']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted ms-2">Não atribuído</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Membros -->
                        <div class="mb-3">
                            <strong>Membros:</strong>
                            <div class="mt-2">
                                <?php foreach ($lead_members as $member): ?>
                                    <span class="badge bg-info me-1 mb-1">
                                        <?= htmlspecialchars($member['username']) ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Remover este membro?')">
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                            <input type="hidden" name="user_id" value="<?= $member['user_id'] ?>">
                                            <button type="submit" class="btn-close btn-close-white" style="font-size: 0.7rem;"></button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                                
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                                    <i class="bi bi-plus"></i> Adicionar
                                </button>
                            </div>
                        </div>
                        
                        <!-- Datas -->
                        <div class="mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Data Início:</strong>
                                    <?= $selected_lead['data_inicio'] ? date('d/m/Y', strtotime($selected_lead['data_inicio'])) : '<span class="text-muted">Não definida</span>' ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Data Fim:</strong>
                                    <?= $selected_lead['data_fim'] ? date('d/m/Y', strtotime($selected_lead['data_fim'])) : '<span class="text-muted">Não definida</span>' ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Estado -->
                        <div class="mb-3">
                            <strong>Estado:</strong>
                            <span class="badge bg-<?= $selected_lead['estado'] == 'aberta' ? 'success' : 'secondary' ?> ms-2">
                                <?= ucfirst($selected_lead['estado']) ?>
                            </span>
                        </div>
                        
                        <!-- Descrição -->
                        <?php if ($selected_lead['descricao']): ?>
                        <div class="mb-3">
                            <strong>Descrição:</strong>
                            <div class="mt-2 p-3 bg-light rounded">
                                <?= nl2br(htmlspecialchars($selected_lead['descricao'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Links -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-link-45deg"></i> Links</h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                            <i class="bi bi-plus"></i> Adicionar
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($lead_links)): ?>
                            <p class="text-muted text-center">Nenhum link adicionado</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($lead_links as $link): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($link['titulo']) ?>
                                        </a>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Remover este link?')">
                                            <input type="hidden" name="action" value="remove_link">
                                            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                            <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Kanban -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-kanban"></i> Kanban Board</h5>
                    </div>
                    <div class="card-body">
                        <div class="kanban-board">
                            <?php
                            $colunas = [
                                'todo' => ['nome' => 'A Fazer', 'icon' => 'bi-circle'],
                                'doing' => ['nome' => 'Em Progresso', 'icon' => 'bi-arrow-clockwise'],
                                'done' => ['nome' => 'Concluído', 'icon' => 'bi-check-circle']
                            ];
                            
                            foreach ($colunas as $coluna_id => $coluna_info):
                                $items = array_filter($lead_kanban, fn($item) => $item['coluna'] == $coluna_id);
                            ?>
                                <div class="kanban-column">
                                    <h5>
                                        <i class="bi <?= $coluna_info['icon'] ?>"></i> 
                                        <?= $coluna_info['nome'] ?> 
                                        <span class="badge bg-secondary"><?= count($items) ?></span>
                                    </h5>
                                    
                                    <?php foreach ($items as $item): ?>
                                        <div class="kanban-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1"><?= htmlspecialchars($item['titulo']) ?></div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link" data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <?php foreach ($colunas as $col_id => $col_info): ?>
                                                            <?php if ($col_id != $coluna_id): ?>
                                                                <li>
                                                                    <form method="post" class="dropdown-item">
                                                                        <input type="hidden" name="action" value="update_kanban_item">
                                                                        <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                                        <input type="hidden" name="kanban_id" value="<?= $item['id'] ?>">
                                                                        <input type="hidden" name="kanban_coluna" value="<?= $col_id ?>">
                                                                        <button type="submit" class="btn btn-link p-0 text-decoration-none text-dark">
                                                                            <i class="bi <?= $col_info['icon'] ?>"></i> Mover para <?= $col_info['nome'] ?>
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="post" class="dropdown-item" onsubmit="return confirm('Remover este item?')">
                                                                <input type="hidden" name="action" value="delete_kanban_item">
                                                                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                                <input type="hidden" name="kanban_id" value="<?= $item['id'] ?>">
                                                                <button type="submit" class="btn btn-link p-0 text-decoration-none text-danger">
                                                                    <i class="bi bi-trash"></i> Remover
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <button class="btn btn-sm btn-outline-secondary w-100 mt-2" data-bs-toggle="modal" data-bs-target="#addKanbanModal<?= $coluna_id ?>">
                                        <i class="bi bi-plus"></i> Adicionar
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Criar Lead -->
<div class="modal fade" id="createLeadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="create_lead">
                <div class="modal-header">
                    <h5 class="modal-title">Novo Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="titulo" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Relevância (1-10)</label>
                            <input type="number" name="relevancia" class="form-control" min="1" max="10" value="5">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Nenhum</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-control">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Fim</label>
                            <input type="date" name="data_fim" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Lead -->
<?php if ($selected_lead): ?>
<div class="modal fade" id="editLeadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="update_lead">
                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($selected_lead['titulo']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="4"><?= htmlspecialchars($selected_lead['descricao']) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Relevância (1-10)</label>
                            <input type="number" name="relevancia" class="form-control" min="1" max="10" value="<?= $selected_lead['relevancia'] ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Nenhum</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" <?= $selected_lead['responsavel_id'] == $user['user_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?= $selected_lead['data_inicio'] ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Fim</label>
                            <input type="date" name="data_fim" class="form-control" value="<?= $selected_lead['data_fim'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="aberta" <?= $selected_lead['estado'] == 'aberta' ? 'selected' : '' ?>>Aberta</option>
                            <option value="fechada" <?= $selected_lead['estado'] == 'fechada' ? 'selected' : '' ?>>Fechada</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Adicionar Membro -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Membro</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Selecionar Usuário</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Escolher...</option>
                            <?php foreach ($all_users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
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

<!-- Modal Adicionar Link -->
<div class="modal fade" id="addLinkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="add_link">
                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título do Link</label>
                        <input type="text" name="link_titulo" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="url" name="link_url" class="form-control" placeholder="https://..." required>
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

<!-- Modals para Adicionar Itens ao Kanban (um para cada coluna) -->
<?php foreach (['todo', 'doing', 'done'] as $col): ?>
<div class="modal fade" id="addKanbanModal<?= $col ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="add_kanban_item">
                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                <input type="hidden" name="kanban_coluna" value="<?= $col ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título do Item</label>
                        <input type="text" name="kanban_titulo" class="form-control" required>
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
<?php endforeach; ?>

<?php endif; ?>

<?php
// Incluir editor universal no final da página
include __DIR__ . '/../edit_task.php';
?>