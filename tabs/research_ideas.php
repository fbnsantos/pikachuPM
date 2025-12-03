<?php
// tabs/research_ideas.php - Tab for managing research ideas

// Incluir arquivo de configuração
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    include_once $configPath;
} else {
    die('Erro: Arquivo de configuração não encontrado em: ' . $configPath);
}


// Usar variáveis do config.php
global $db_host, $db_user, $db_pass, $db_name;

// Criar NOVA conexão PDO (não recomendado, mas funcional)
try {
    $conn = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}
// Usar diretamente as variáveis globais que existem no config.php
//global $conn;

// Verificar se a conexão existe
if (!isset($conn)) {
    die('Erro: Conexão com banco de dados não encontrada. Verifique seu arquivo config.php.');
}

// Criar tabelas se não existirem
try {
    // Tabela principal de ideias
    $conn->exec("CREATE TABLE IF NOT EXISTS research_ideas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(500) NOT NULL,
        description TEXT,
        author VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        status ENUM('nova', 'em análise', 'aprovada', 'arquivada') DEFAULT 'nova',
        priority ENUM('baixa', 'normal', 'alta', 'urgente') DEFAULT 'normal',
        INDEX idx_author (author),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tabela de interessados
    $conn->exec("CREATE TABLE IF NOT EXISTS research_idea_interested (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idea_id INT NOT NULL,
        user_login VARCHAR(100) NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        FOREIGN KEY (idea_id) REFERENCES research_ideas(id) ON DELETE CASCADE,
        UNIQUE KEY unique_interested (idea_id, user_login),
        INDEX idx_idea (idea_id),
        INDEX idx_user (user_login)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tabela de links
    $conn->exec("CREATE TABLE IF NOT EXISTS research_idea_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idea_id INT NOT NULL,
        url VARCHAR(2048) NOT NULL,
        title VARCHAR(500),
        description TEXT,
        added_by VARCHAR(100) NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (idea_id) REFERENCES research_ideas(id) ON DELETE CASCADE,
        INDEX idx_idea (idea_id),
        INDEX idx_added_by (added_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
} catch (PDOException $e) {
    error_log("Erro ao criar tabelas de research ideas: " . $e->getMessage());
}

// Variáveis de mensagem
$message = '';
$messageType = '';

// ========================================
// PROCESSAR AÇÕES DO FORMULÁRIO
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create_idea':
                $stmt = $conn->prepare("
                    INSERT INTO research_ideas (title, description, author, status, priority)
                    VALUES (:title, :description, :author, :status, :priority)
                ");
                $stmt->execute([
                    ':title' => $_POST['title'],
                    ':description' => $_POST['description'] ?? '',
                    ':author' => $_SESSION['username'],
                    ':status' => $_POST['status'] ?? 'nova',
                    ':priority' => $_POST['priority'] ?? 'normal'
                ]);
                $message = "Ideia criada com sucesso!";
                $messageType = "success";
                break;
                
            case 'update_idea':
                $stmt = $conn->prepare("
                    UPDATE research_ideas 
                    SET title = :title, 
                        description = :description,
                        status = :status,
                        priority = :priority
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $_POST['title'],
                    ':description' => $_POST['description'] ?? '',
                    ':status' => $_POST['status'] ?? 'nova',
                    ':priority' => $_POST['priority'] ?? 'normal',
                    ':id' => $_POST['idea_id']
                ]);
                $message = "Ideia atualizada com sucesso!";
                $messageType = "success";
                break;
                
            case 'delete_idea':
                $stmt = $conn->prepare("DELETE FROM research_ideas WHERE id = :id");
                $stmt->execute([':id' => $_POST['idea_id']]);
                $message = "Ideia excluída com sucesso!";
                $messageType = "success";
                // Redirecionar para evitar resubmissão
                header("Location: ?tab=research_ideas");
                exit;
                
            case 'add_interested':
                $stmt = $conn->prepare("
                    INSERT INTO research_idea_interested (idea_id, user_login, notes)
                    VALUES (:idea_id, :user_login, :notes)
                    ON DUPLICATE KEY UPDATE notes = :notes
                ");
                $stmt->execute([
                    ':idea_id' => $_POST['idea_id'],
                    ':user_login' => $_POST['user_login'],
                    ':notes' => $_POST['notes'] ?? ''
                ]);
                $message = "Interessado adicionado com sucesso!";
                $messageType = "success";
                break;
                
            case 'remove_interested':
                $stmt = $conn->prepare("
                    DELETE FROM research_idea_interested 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $_POST['interested_id']]);
                $message = "Interessado removido com sucesso!";
                $messageType = "success";
                break;
                
            case 'add_link':
                $stmt = $conn->prepare("
                    INSERT INTO research_idea_links (idea_id, url, title, description, added_by)
                    VALUES (:idea_id, :url, :title, :description, :added_by)
                ");
                $stmt->execute([
                    ':idea_id' => $_POST['idea_id'],
                    ':url' => $_POST['url'],
                    ':title' => $_POST['link_title'] ?? '',
                    ':description' => $_POST['link_description'] ?? '',
                    ':added_by' => $_SESSION['username']
                ]);
                $message = "Link adicionado com sucesso!";
                $messageType = "success";
                break;
                
            case 'remove_link':
                $stmt = $conn->prepare("DELETE FROM research_idea_links WHERE id = :id");
                $stmt->execute([':id' => $_POST['link_id']]);
                $message = "Link removido com sucesso!";
                $messageType = "success";
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = "danger";
        error_log("Erro em research_ideas.php: " . $e->getMessage());
    }
}

// ========================================
// BUSCAR DADOS
// ========================================

// Buscar todas as ideias com contagens
try {
    $stmt = $conn->query("
        SELECT 
            ri.*,
            COUNT(DISTINCT rii.id) as interested_count,
            COUNT(DISTINCT ril.id) as link_count
        FROM research_ideas ri
        LEFT JOIN research_idea_interested rii ON ri.id = rii.idea_id
        LEFT JOIN research_idea_links ril ON ri.id = ril.idea_id
        GROUP BY ri.id
        ORDER BY ri.created_at DESC
    ");
    $ideas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ideas = [];
    error_log("Erro ao buscar ideias: " . $e->getMessage());
}

// Se uma ideia foi selecionada, buscar detalhes completos
$selectedIdea = null;
$interestedUsers = [];
$links = [];

if (isset($_GET['idea_id'])) {
    $ideaId = $_GET['idea_id'];
    
    try {
        // Buscar ideia
        $stmt = $conn->prepare("SELECT * FROM research_ideas WHERE id = :id");
        $stmt->execute([':id' => $ideaId]);
        $selectedIdea = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selectedIdea) {
            // Buscar interessados
            $stmt = $conn->prepare("
                SELECT * FROM research_idea_interested 
                WHERE idea_id = :id 
                ORDER BY added_at DESC
            ");
            $stmt->execute([':id' => $ideaId]);
            $interestedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Buscar links
            $stmt = $conn->prepare("
                SELECT * FROM research_idea_links 
                WHERE idea_id = :id 
                ORDER BY added_at DESC
            ");
            $stmt->execute([':id' => $ideaId]);
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar detalhes da ideia: " . $e->getMessage());
    }
}

// Buscar lista de usuários para dropdown de interessados
$users = [];
try {
    $stmt = $conn->query("SELECT login, CONCAT(firstname, ' ', lastname) as name FROM users ORDER BY firstname, lastname");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar usuários: " . $e->getMessage());
}

// ========================================
// FUNÇÕES AUXILIARES
// ========================================

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'nova': return 'bg-primary';
        case 'em análise': return 'bg-info';
        case 'aprovada': return 'bg-success';
        case 'arquivada': return 'bg-secondary';
        default: return 'bg-secondary';
    }
}

function getPriorityBadgeClass($priority) {
    switch ($priority) {
        case 'baixa': return 'bg-success';
        case 'normal': return 'bg-info';
        case 'alta': return 'bg-warning';
        case 'urgente': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function truncateText($text, $maxLength = 100) {
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, $maxLength) . '...';
}
?>

<!-- Incluir marked.js para renderizar Markdown -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<style>
    .research-ideas-container {
        display: flex;
        height: calc(100vh - 200px);
        gap: 20px;
    }
    
    .ideas-list {
        width: 350px;
        overflow-y: auto;
        border-right: 1px solid #dee2e6;
        padding-right: 20px;
    }
    
    .idea-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: white;
    }
    
    .idea-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .idea-card.active {
        border-color: #667eea;
        background: #f8f9ff;
        box-shadow: 0 4px 12px rgba(102,126,234,0.2);
    }
    
    .idea-details {
        flex: 1;
        overflow-y: auto;
        padding-left: 20px;
    }
    
    .markdown-content {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin: 15px 0;
        min-height: 200px;
    }
    
    .markdown-content h1 { font-size: 1.8em; margin-top: 0.5em; }
    .markdown-content h2 { font-size: 1.5em; margin-top: 0.5em; }
    .markdown-content h3 { font-size: 1.2em; margin-top: 0.5em; }
    .markdown-content pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
    .markdown-content code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; }
    .markdown-content blockquote { border-left: 4px solid #667eea; padding-left: 15px; color: #6c757d; margin: 15px 0; }
    
    .interested-user-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 6px;
        margin-bottom: 8px;
    }
    
    .link-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 6px;
        margin-bottom: 10px;
        border-left: 3px solid #667eea;
    }
    
    .link-item:hover {
        background: #e9ecef;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    
    .empty-state i {
        font-size: 4em;
        margin-bottom: 20px;
        opacity: 0.3;
    }
</style>

<!-- Mensagens de Feedback -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Cabeçalho com Botão de Nova Ideia -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-lightbulb"></i> Ideias de Investigação</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newIdeaModal">
        <i class="bi bi-plus-circle"></i> Nova Ideia
    </button>
</div>

<!-- Container Principal -->
<div class="research-ideas-container">
    <!-- Lista de Ideias (Esquerda) -->
    <div class="ideas-list">
        <?php if (empty($ideas)): ?>
            <div class="empty-state">
                <i class="bi bi-lightbulb-off"></i>
                <p>Nenhuma ideia registrada ainda.</p>
                <p><small>Clique em "Nova Ideia" para começar!</small></p>
            </div>
        <?php else: ?>
            <?php foreach ($ideas as $idea): ?>
                <div class="idea-card <?= isset($_GET['idea_id']) && $_GET['idea_id'] == $idea['id'] ? 'active' : '' ?>" 
                     onclick="window.location.href='?tab=research_ideas&idea_id=<?= $idea['id'] ?>'">
                    <h5 class="mb-2"><?= htmlspecialchars($idea['title']) ?></h5>
                    <div class="mb-2">
                        <span class="badge <?= getStatusBadgeClass($idea['status']) ?>"><?= $idea['status'] ?></span>
                        <span class="badge <?= getPriorityBadgeClass($idea['priority']) ?>"><?= $idea['priority'] ?></span>
                    </div>
                    <p class="text-muted mb-2" style="font-size: 0.9em;">
                        <i class="bi bi-person"></i> <?= htmlspecialchars($idea['author']) ?>
                    </p>
                    <div class="d-flex justify-content-between" style="font-size: 0.85em; color: #6c757d;">
                        <span><i class="bi bi-people"></i> <?= $idea['interested_count'] ?> interessados</span>
                        <span><i class="bi bi-link-45deg"></i> <?= $idea['link_count'] ?> links</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Detalhes da Ideia (Direita) -->
    <div class="idea-details">
        <?php if ($selectedIdea): ?>
            <!-- Cabeçalho da Ideia -->
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h3><?= htmlspecialchars($selectedIdea['title']) ?></h3>
                    <p class="text-muted">
                        <i class="bi bi-person"></i> <?= htmlspecialchars($selectedIdea['author']) ?> | 
                        <i class="bi bi-calendar"></i> <?= date('d/m/Y H:i', strtotime($selectedIdea['created_at'])) ?>
                    </p>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editIdeaModal">
                        <i class="bi bi-pencil"></i> Editar
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $selectedIdea['id'] ?>)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            
            <!-- Badges de Status e Prioridade -->
            <div class="mb-3">
                <span class="badge <?= getStatusBadgeClass($selectedIdea['status']) ?>"><?= $selectedIdea['status'] ?></span>
                <span class="badge <?= getPriorityBadgeClass($selectedIdea['priority']) ?>"><?= $selectedIdea['priority'] ?></span>
            </div>
            
            <!-- Descrição em Markdown -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="bi bi-file-text"></i> Descrição</h5>
                </div>
                <div class="card-body">
                    <div class="markdown-content" id="markdownContent"></div>
                </div>
            </div>
            
            <!-- Interessados -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-people"></i> Potenciais Interessados</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addInterestedModal">
                        <i class="bi bi-plus"></i> Adicionar
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($interestedUsers)): ?>
                        <p class="text-muted">Nenhum interessado registrado.</p>
                    <?php else: ?>
                        <?php foreach ($interestedUsers as $interested): ?>
                            <div class="interested-user-item">
                                <div>
                                    <strong><?= htmlspecialchars($interested['user_login']) ?></strong>
                                    <?php if ($interested['notes']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($interested['notes']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Remover este interessado?')">
                                    <input type="hidden" name="action" value="remove_interested">
                                    <input type="hidden" name="interested_id" value="<?= $interested['id'] ?>">
                                    <input type="hidden" name="idea_id" value="<?= $selectedIdea['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Links Relacionados -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-link-45deg"></i> Links Relacionados</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                        <i class="bi bi-plus"></i> Adicionar Link
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($links)): ?>
                        <p class="text-muted">Nenhum link adicionado.</p>
                    <?php else: ?>
                        <?php foreach ($links as $link): ?>
                            <div class="link-item">
                                <div class="flex-grow-1">
                                    <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" class="fw-bold">
                                        <?= $link['title'] ? htmlspecialchars($link['title']) : htmlspecialchars($link['url']) ?>
                                        <i class="bi bi-box-arrow-up-right ms-1"></i>
                                    </a>
                                    <?php if ($link['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($link['description']) ?></small>
                                    <?php endif; ?>
                                    <br><small class="text-muted">
                                        Adicionado por <?= htmlspecialchars($link['added_by']) ?> em <?= date('d/m/Y', strtotime($link['added_at'])) ?>
                                    </small>
                                </div>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Remover este link?')">
                                    <input type="hidden" name="action" value="remove_link">
                                    <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                    <input type="hidden" name="idea_id" value="<?= $selectedIdea['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Script para renderizar Markdown -->
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const markdownText = <?= json_encode($selectedIdea['description'] ?? '') ?>;
                    const contentDiv = document.getElementById('markdownContent');
                    if (markdownText) {
                        contentDiv.innerHTML = marked.parse(markdownText);
                    } else {
                        contentDiv.innerHTML = '<p class="text-muted">Nenhuma descrição disponível.</p>';
                    }
                });
            </script>
            
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-arrow-left"></i>
                <h4>Selecione uma ideia</h4>
                <p>Clique em uma ideia na lista à esquerda para ver os detalhes.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Nova Ideia -->
<div class="modal fade" id="newIdeaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lightbulb"></i> Nova Ideia de Investigação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="?tab=research_ideas">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_idea">
                    
                    <div class="mb-3">
                        <label for="title" class="form-label">Título da Ideia *</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Descrição (Markdown)</label>
                        <textarea class="form-control" id="description" name="description" rows="10" 
                                  placeholder="Descreva a ideia usando Markdown..."></textarea>
                        <small class="form-text text-muted">
                            Suporte para Markdown: **negrito**, *itálico*, # títulos, - listas, [links](url), etc.
                        </small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="nova" selected>Nova</option>
                                    <option value="em análise">Em Análise</option>
                                    <option value="aprovada">Aprovada</option>
                                    <option value="arquivada">Arquivada</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="priority" class="form-label">Prioridade</label>
                                <select class="form-select" id="priority" name="priority">
                                    <option value="baixa">Baixa</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="alta">Alta</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Ideia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Ideia -->
<?php if ($selectedIdea): ?>
<div class="modal fade" id="editIdeaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Editar Ideia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="?tab=research_ideas&idea_id=<?= $selectedIdea['id'] ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_idea">
                    <input type="hidden" name="idea_id" value="<?= $selectedIdea['id'] ?>">
                    
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Título da Ideia *</label>
                        <input type="text" class="form-control" id="edit_title" name="title" 
                               value="<?= htmlspecialchars($selectedIdea['title']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Descrição (Markdown)</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="10"><?= htmlspecialchars($selectedIdea['description']) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_status" class="form-label">Status</label>
                                <select class="form-select" id="edit_status" name="status">
                                    <option value="nova" <?= $selectedIdea['status'] == 'nova' ? 'selected' : '' ?>>Nova</option>
                                    <option value="em análise" <?= $selectedIdea['status'] == 'em análise' ? 'selected' : '' ?>>Em Análise</option>
                                    <option value="aprovada" <?= $selectedIdea['status'] == 'aprovada' ? 'selected' : '' ?>>Aprovada</option>
                                    <option value="arquivada" <?= $selectedIdea['status'] == 'arquivada' ? 'selected' : '' ?>>Arquivada</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_priority" class="form-label">Prioridade</label>
                                <select class="form-select" id="edit_priority" name="priority">
                                    <option value="baixa" <?= $selectedIdea['priority'] == 'baixa' ? 'selected' : '' ?>>Baixa</option>
                                    <option value="normal" <?= $selectedIdea['priority'] == 'normal' ? 'selected' : '' ?>>Normal</option>
                                    <option value="alta" <?= $selectedIdea['priority'] == 'alta' ? 'selected' : '' ?>>Alta</option>
                                    <option value="urgente" <?= $selectedIdea['priority'] == 'urgente' ? 'selected' : '' ?>>Urgente</option>
                                </select>
                            </div>
                        </div>
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

<!-- Modal: Adicionar Interessado -->
<div class="modal fade" id="addInterestedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Adicionar Interessado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="?tab=research_ideas&idea_id=<?= $selectedIdea['id'] ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_interested">
                    <input type="hidden" name="idea_id" value="<?= $selectedIdea['id'] ?>">
                    
                    <div class="mb-3">
                        <label for="user_login" class="form-label">Utilizador *</label>
                        <select class="form-select" id="user_login" name="user_login" required>
                            <option value="">Selecione um utilizador...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= htmlspecialchars($user['login']) ?>">
                                    <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['login']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notas</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Motivo do interesse, área de expertise, etc."></textarea>
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

<!-- Modal: Adicionar Link -->
<div class="modal fade" id="addLinkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Adicionar Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="?tab=research_ideas&idea_id=<?= $selectedIdea['id'] ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_link">
                    <input type="hidden" name="idea_id" value="<?= $selectedIdea['id'] ?>">
                    
                    <div class="mb-3">
                        <label for="url" class="form-label">URL *</label>
                        <input type="url" class="form-control" id="url" name="url" 
                               placeholder="https://exemplo.com" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="link_title" class="form-label">Título do Link</label>
                        <input type="text" class="form-control" id="link_title" name="link_title"
                               placeholder="Título descritivo (opcional)">
                    </div>
                    
                    <div class="mb-3">
                        <label for="link_description" class="form-label">Descrição</label>
                        <textarea class="form-control" id="link_description" name="link_description" rows="3"
                                  placeholder="Breve descrição do conteúdo do link (opcional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar Link</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Script para confirmação de delete -->
<script>
function confirmDelete(ideaId) {
    if (confirm('Tem certeza que deseja excluir esta ideia? Esta ação não pode ser desfeita.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?tab=research_ideas';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_idea';
        
        const ideaInput = document.createElement('input');
        ideaInput.type = 'hidden';
        ideaInput.name = 'idea_id';
        ideaInput.value = ideaId;
        
        form.appendChild(actionInput);
        form.appendChild(ideaInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>