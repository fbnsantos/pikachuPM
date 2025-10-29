<?php
// tabs/prototypes/prototypesv2.php - Sistema Completo de Gestão de Protótipos
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once __DIR__ . '/../../config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro de conexão à base de dados: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Criar tabelas necessárias
try {
    // Tabela prototype_members
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS prototype_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prototype_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
        UNIQUE KEY unique_prototype_user (prototype_id, user_id),
        INDEX idx_prototype (prototype_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Verificar e adicionar coluna responsavel_id à tabela prototypes se não existir
    $checkColumn = $pdo->query("SHOW COLUMNS FROM prototypes LIKE 'responsavel_id'")->fetch();
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE prototypes ADD COLUMN responsavel_id INT NULL AFTER name");
    }
} catch (PDOException $e) {
    // Tabelas já existem
}

// Obter lista de utilizadores
$users = [];
try {
    $stmt = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignorar erro
}

// Processar ações
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'create_prototype':
                $stmt = $pdo->prepare("
                    INSERT INTO prototypes (short_name, title, vision, target_group, needs, 
                                          product_description, business_goals, sentence, 
                                          repo_links, documentation_links, name, responsavel_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '',
                    $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '',
                    $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '',
                    $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['responsavel_id'] ?: null
                ]);
                $message = "Protótipo criado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_prototype':
                $stmt = $pdo->prepare("
                    UPDATE prototypes SET 
                        short_name=?, title=?, vision=?, target_group=?, needs=?,
                        product_description=?, business_goals=?, sentence=?,
                        repo_links=?, documentation_links=?, name=?, responsavel_id=?,
                        updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['short_name'],
                    $_POST['title'],
                    $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '',
                    $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '',
                    $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '',
                    $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['responsavel_id'] ?: null,
                    $_POST['prototype_id']
                ]);
                $message = "Protótipo atualizado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'delete_prototype':
                $stmt = $pdo->prepare("DELETE FROM prototypes WHERE id=?");
                $stmt->execute([$_POST['prototype_id']]);
                $message = "Protótipo eliminado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("
                    INSERT INTO prototype_members (prototype_id, user_id, role)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE role=VALUES(role)
                ");
                $stmt->execute([
                    $_POST['prototype_id'],
                    $_POST['user_id'],
                    $_POST['role'] ?? 'member'
                ]);
                $message = "Membro adicionado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM prototype_members WHERE id=?");
                $stmt->execute([$_POST['member_id']]);
                $message = "Membro removido com sucesso!";
                $messageType = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obter filtros
$filterMine = isset($_GET['filter_mine']) ? $_GET['filter_mine'] === 'true' : false;
$filterParticipate = isset($_GET['filter_participate']) ? $_GET['filter_participate'] === 'true' : false;
$selectedPrototypeId = $_GET['prototype_id'] ?? null;
$currentUserId = $_SESSION['user_id'] ?? null;

// Buscar protótipos
$whereConditions = [];
$params = [];

if ($filterMine && $currentUserId) {
    $whereConditions[] = "p.responsavel_id = ?";
    $params[] = $currentUserId;
}

if ($filterParticipate && $currentUserId) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM prototype_members pm WHERE pm.prototype_id = p.id AND pm.user_id = ?)";
    $params[] = $currentUserId;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' OR ', $whereConditions) : '';

$sql = "
    SELECT p.*, u.username as responsavel_nome
    FROM prototypes p
    LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
    $whereClause
    ORDER BY p.short_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se um protótipo está selecionado, carregar seus detalhes
$selectedPrototype = null;
if ($selectedPrototypeId) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username as responsavel_nome
        FROM prototypes p
        LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$selectedPrototypeId]);
    $selectedPrototype = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPrototype) {
        // Obter membros
        $stmt = $pdo->prepare("
            SELECT pm.*, u.username
            FROM prototype_members pm
            JOIN user_tokens u ON pm.user_id = u.user_id
            WHERE pm.prototype_id = ?
            ORDER BY u.username
        ");
        $stmt->execute([$selectedPrototypeId]);
        $selectedPrototype['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obter user stories
        $stmt = $pdo->prepare("
            SELECT * FROM user_stories 
            WHERE prototype_id = ? 
            ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won''t'), id
        ");
        $stmt->execute([$selectedPrototypeId]);
        $selectedPrototype['stories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<style>
.prototypes-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 150px);
}

.prototypes-sidebar {
    width: 350px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.sidebar-header {
    margin-bottom: 15px;
}

.sidebar-header h4 {
    margin: 0 0 10px 0;
    color: #1a202c;
}

.filter-container {
    padding: 12px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    margin-bottom: 15px;
}

.filter-container .form-check {
    margin-bottom: 8px;
}

.filter-container .form-check:last-child {
    margin-bottom: 0;
}

.filter-container .form-check-input {
    cursor: pointer;
}

.filter-container .form-check-label {
    cursor: pointer;
    font-weight: 500;
    color: #374151;
}

.search-box {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.search-box input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #e1e8ed;
    border-radius: 6px;
    font-size: 14px;
}

.prototype-item {
    padding: 12px;
    margin-bottom: 10px;
    background: white;
    border-radius: 6px;
    border: 2px solid #e1e8ed;
    cursor: pointer;
    transition: all 0.2s;
}

.prototype-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.1);
}

.prototype-item.active {
    border-color: #3b82f6;
    background: #eff6ff;
}

.prototype-item h5 {
    margin: 0 0 5px 0;
    font-size: 15px;
    color: #1a202c;
    font-weight: 600;
}

.prototype-item p {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
}

.prototype-item .badge {
    font-size: 10px;
    padding: 3px 6px;
    margin-top: 5px;
}

.prototypes-content {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: white;
    border-radius: 8px;
}

.detail-section {
    background: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
}

.detail-section h5 {
    margin: 0 0 15px 0;
    color: #1a202c;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-card {
    background: white;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #1a202c;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 14px;
    color: #4a5568;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e1e8ed;
    border-radius: 6px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.member-list,
.story-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.member-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}

.member-badge span:first-child {
    flex: 1;
    font-weight: 500;
    color: #1a202c;
}

.story-item {
    background: white;
    border-left: 4px solid #94a3b8;
    padding: 15px;
    border-radius: 6px;
    transition: all 0.2s;
}

.story-item.must {
    border-left-color: #ef4444;
}

.story-item.should {
    border-left-color: #f59e0b;
}

.story-item.could {
    border-left-color: #3b82f6;
}

.story-item.wont {
    border-left-color: #94a3b8;
}

.story-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.story-priority {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.priority-must {
    background: #fee2e2;
    color: #991b1b;
}

.priority-should {
    background: #fed7aa;
    color: #92400e;
}

.priority-could {
    background: #dbeafe;
    color: #1e40af;
}

.priority-wont {
    background: #e2e8f0;
    color: #475569;
}

.story-text {
    font-size: 14px;
    line-height: 1.6;
    color: #374151;
}

.btn-group {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

.empty-state h3 {
    font-size: 24px;
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 16px;
}

@media (max-width: 768px) {
    .prototypes-container {
        flex-direction: column;
    }
    
    .prototypes-sidebar {
        width: 100%;
        max-height: 40vh;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="prototypes-container">
    <!-- Sidebar Esquerda -->
    <div class="prototypes-sidebar">
        <div class="sidebar-header">
            <h4>Protótipos</h4>
            
            <!-- Filtros -->
            <div class="filter-container">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterMine" 
                           <?= $filterMine ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="filterMine">
                        Sou Responsável
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterParticipate" 
                           <?= $filterParticipate ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="filterParticipate">
                        Participo
                    </label>
                </div>
            </div>
            
            <!-- Busca e Adicionar -->
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Buscar..." onkeyup="filterPrototypes()">
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newPrototypeModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </div>
        
        <!-- Lista de Protótipos -->
        <div id="prototypesList">
            <?php if (empty($prototypes)): ?>
                <p class="text-muted text-center">Nenhum protótipo encontrado</p>
            <?php else: ?>
                <?php foreach ($prototypes as $proto): ?>
                    <div class="prototype-item <?= $proto['id'] == $selectedPrototypeId ? 'active' : '' ?>"
                         onclick="window.location.href='?tab=prototypes/prototypesv2&prototype_id=<?= $proto['id'] ?><?= $filterMine ? '&filter_mine=true' : '' ?><?= $filterParticipate ? '&filter_participate=true' : '' ?>'">
                        <h5><?= htmlspecialchars($proto['short_name']) ?></h5>
                        <p><?= htmlspecialchars($proto['title']) ?></p>
                        <?php if ($proto['responsavel_nome']): ?>
                            <span class="badge bg-primary">👤 <?= htmlspecialchars($proto['responsavel_nome']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Conteúdo Principal -->
    <div class="prototypes-content">
        <?php if (!$selectedPrototype): ?>
            <div class="empty-state">
                <h3>Selecione um protótipo</h3>
                <p>Escolha um protótipo da lista para ver os detalhes</p>
            </div>
        <?php else: ?>
            <!-- Informações Básicas -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-info-circle"></i> Informações Básicas</h5>
                    <div>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editPrototypeModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="if(confirm('Tem certeza?')) { document.getElementById('deleteForm').submit(); }">
                            <i class="bi bi-trash"></i> Eliminar
                        </button>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Nome Curto</div>
                        <div class="info-value"><?= htmlspecialchars($selectedPrototype['short_name']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Título</div>
                        <div class="info-value"><?= htmlspecialchars($selectedPrototype['title']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Responsável</div>
                        <div class="info-value">
                            <?= $selectedPrototype['responsavel_nome'] ? '👤 ' . htmlspecialchars($selectedPrototype['responsavel_nome']) : 'Não atribuído' ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Criado em</div>
                        <div class="info-value">
                            <?= $selectedPrototype['created_at'] ? date('d/m/Y H:i', strtotime($selectedPrototype['created_at'])) : '-' ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($selectedPrototype['vision']): ?>
                <div class="mt-3">
                    <div class="info-label">Visão</div>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['vision'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedPrototype['sentence']): ?>
                <div class="mt-3">
                    <div class="info-label">Frase de Posicionamento</div>
                    <p class="mb-0" style="font-style: italic;"><?= nl2br(htmlspecialchars($selectedPrototype['sentence'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Membros -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-people"></i> Membros da Equipa</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="bi bi-person-plus"></i> Adicionar
                    </button>
                </div>
                
                <div class="member-list">
                    <?php if (!empty($selectedPrototype['members'])): ?>
                        <?php foreach ($selectedPrototype['members'] as $member): ?>
                            <div class="member-badge">
                                <span>👤 <?= htmlspecialchars($member['username']) ?></span>
                                <?php if ($member['role'] !== 'member'): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($member['role']) ?></span>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este membro?')">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nenhum membro adicionado</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Stories -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-book"></i> User Stories</h5>
                </div>
                
                <div class="story-list">
                    <?php if (!empty($selectedPrototype['stories'])): ?>
                        <?php foreach ($selectedPrototype['stories'] as $story): ?>
                            <div class="story-item <?= strtolower($story['moscow_priority']) ?>">
                                <div class="story-header">
                                    <span class="story-priority priority-<?= strtolower($story['moscow_priority']) ?>">
                                        <?= htmlspecialchars($story['moscow_priority']) ?> Have
                                    </span>
                                </div>
                                <div class="story-text">
                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nenhuma user story criada</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Links e Recursos -->
            <?php if ($selectedPrototype['repo_links'] || $selectedPrototype['documentation_links']): ?>
            <div class="detail-section">
                <h5><i class="bi bi-link-45deg"></i> Links e Recursos</h5>
                
                <?php if ($selectedPrototype['repo_links']): ?>
                <div class="mb-3">
                    <div class="info-label">Repositórios</div>
                    <?php 
                    $repoLinks = json_decode($selectedPrototype['repo_links'], true);
                    if (is_array($repoLinks)):
                        foreach ($repoLinks as $link):
                    ?>
                        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="d-block mb-1">
                            🔗 <?= htmlspecialchars($link) ?>
                        </a>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedPrototype['documentation_links']): ?>
                <div>
                    <div class="info-label">Documentação</div>
                    <?php 
                    $docLinks = json_decode($selectedPrototype['documentation_links'], true);
                    if (is_array($docLinks)):
                        foreach ($docLinks as $link):
                    ?>
                        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="d-block mb-1">
                            📄 <?= htmlspecialchars($link) ?>
                        </a>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Form oculto para eliminar -->
            <form id="deleteForm" method="POST" style="display: none;">
                <input type="hidden" name="action" value="delete_prototype">
                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Novo Protótipo -->
<div class="modal fade" id="newPrototypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_prototype">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome Curto *</label>
                            <input type="text" name="short_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Não atribuído</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visão</label>
                        <textarea name="vision" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Grupo Alvo</label>
                        <textarea name="target_group" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Necessidades</label>
                        <textarea name="needs" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição do Produto</label>
                        <textarea name="product_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Objetivos de Negócio</label>
                        <textarea name="business_goals" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Frase de Posicionamento</label>
                        <textarea name="sentence" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Protótipo -->
<?php if ($selectedPrototype): ?>
<div class="modal fade" id="editPrototypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_prototype">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome Curto *</label>
                            <input type="text" name="short_name" class="form-control" 
                                   value="<?= htmlspecialchars($selectedPrototype['short_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsável</label>
                            <select name="responsavel_id" class="form-select">
                                <option value="">Não atribuído</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" 
                                            <?= $user['user_id'] == $selectedPrototype['responsavel_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" class="form-control" 
                               value="<?= htmlspecialchars($selectedPrototype['title']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visão</label>
                        <textarea name="vision" class="form-control" rows="3"><?= htmlspecialchars($selectedPrototype['vision']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Grupo Alvo</label>
                        <textarea name="target_group" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['target_group']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Necessidades</label>
                        <textarea name="needs" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['needs']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição do Produto</label>
                        <textarea name="product_description" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['product_description']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Objetivos de Negócio</label>
                        <textarea name="business_goals" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['business_goals']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Frase de Posicionamento</label>
                        <textarea name="sentence" class="form-control" rows="2"><?= htmlspecialchars($selectedPrototype['sentence']) ?></textarea>
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

<!-- Modal: Adicionar Membro -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Membro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Utilizador</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Papel</label>
                        <select name="role" class="form-select">
                            <option value="member">Member</option>
                            <option value="developer">Developer</option>
                            <option value="designer">Designer</option>
                            <option value="tester">Tester</option>
                            <option value="lead">Lead</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateFilters() {
    const filterMine = document.getElementById('filterMine').checked;
    const filterParticipate = document.getElementById('filterParticipate').checked;
    const prototypeId = <?= $selectedPrototypeId ?? 'null' ?>;
    
    let url = '?tab=prototypes/prototypesv2';
    if (prototypeId) url += '&prototype_id=' + prototypeId;
    if (filterMine) url += '&filter_mine=true';
    if (filterParticipate) url += '&filter_participate=true';
    
    window.location.href = url;
}

function filterPrototypes() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const items = document.querySelectorAll('.prototype-item');
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}
</script>