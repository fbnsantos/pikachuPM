<?php
/**
 * Prototypes Management System - Complete Standalone Version
 * Sistema completo de gestão de protótipos com user stories, sprints e equipa
 */

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
    die("<div class='alert alert-danger'>Erro de conexão: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Get current user
$current_user_id = $_SESSION['user_id'] ?? null;
$current_username = $_SESSION['username'] ?? '';

if (!$current_user_id) {
    die("<div class='alert alert-danger'>Sessão inválida. Faça login novamente.</div>");
}

// Criar/verificar tabelas necessárias
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
    
    // Adicionar coluna responsavel_id se não existir
    $checkResp = $pdo->query("SHOW COLUMNS FROM prototypes LIKE 'responsavel_id'")->fetch();
    if (!$checkResp) {
        $pdo->exec("ALTER TABLE prototypes ADD COLUMN responsavel_id INT NULL AFTER short_name");
    }
    
    // Adicionar colunas status e completion_percentage em user_stories se não existirem
    $checkStatus = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'status'")->fetch();
    if (!$checkStatus) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN status ENUM('open', 'closed') DEFAULT 'open'");
    }
    
    $checkPerc = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'completion_percentage'")->fetch();
    if (!$checkPerc) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN completion_percentage INT DEFAULT 0");
    }
    
    // Tabela user_story_sprints
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_story_sprints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            sprint_id INT NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_sprint (story_id, sprint_id),
            INDEX idx_story (story_id),
            INDEX idx_sprint (sprint_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
} catch (PDOException $e) {
    error_log("Database setup error: " . $e->getMessage());
}

// Processar ações
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_prototype':
                $stmt = $pdo->prepare("
                    INSERT INTO prototypes (short_name, title, vision, target_group, needs,
                                          product_description, business_goals, sentence,
                                          repo_links, documentation_links, responsavel_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_POST['short_name'], $_POST['title'], $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '', $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '', $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '', $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '', $_POST['responsavel_id'] ?? null
                ]);
                $message = "Protótipo criado com sucesso!";
                break;
                
            case 'update_prototype':
                $stmt = $pdo->prepare("
                    UPDATE prototypes 
                    SET short_name=?, title=?, vision=?, target_group=?, needs=?,
                        product_description=?, business_goals=?, sentence=?,
                        repo_links=?, documentation_links=?, responsavel_id=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['short_name'], $_POST['title'], $_POST['vision'] ?? '',
                    $_POST['target_group'] ?? '', $_POST['needs'] ?? '',
                    $_POST['product_description'] ?? '', $_POST['business_goals'] ?? '',
                    $_POST['sentence'] ?? '', $_POST['repo_links'] ?? '',
                    $_POST['documentation_links'] ?? '', $_POST['responsavel_id'] ?? null,
                    $_POST['prototype_id']
                ]);
                $message = "Protótipo atualizado!";
                break;
                
            case 'delete_prototype':
                $stmt = $pdo->prepare("DELETE FROM prototypes WHERE id = ?");
                $stmt->execute([$_POST['prototype_id']]);
                $message = "Protótipo removido!";
                break;
                
            case 'add_member':
                $stmt = $pdo->prepare("
                    INSERT INTO prototype_members (prototype_id, user_id, role)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$_POST['prototype_id'], $_POST['user_id'], $_POST['role'] ?? 'member']);
                $message = "Membro adicionado!";
                break;
                
            case 'remove_member':
                $stmt = $pdo->prepare("DELETE FROM prototype_members WHERE id = ?");
                $stmt->execute([$_POST['member_id']]);
                $message = "Membro removido!";
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obter filtros
$filter_my_prototypes = isset($_GET['filter_my']) && $_GET['filter_my'] == '1';
$filter_responsible = isset($_GET['filter_resp']) && $_GET['filter_resp'] == '1';

// Buscar protótipos
try {
    if ($filter_responsible) {
        $query = "
            SELECT p.*, u.username as responsavel_nome,
                   1 as is_responsible,
                   CASE WHEN pm.user_id IS NOT NULL THEN 1 ELSE 0 END as is_member
            FROM prototypes p
            LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
            LEFT JOIN prototype_members pm ON p.id = pm.prototype_id AND pm.user_id = ?
            WHERE p.responsavel_id = ?
            ORDER BY p.short_name ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$current_user_id, $current_user_id]);
    } elseif ($filter_my_prototypes) {
        $query = "
            SELECT p.*, u.username as responsavel_nome,
                   CASE WHEN p.responsavel_id = ? THEN 1 ELSE 0 END as is_responsible,
                   1 as is_member
            FROM prototypes p
            LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
            INNER JOIN prototype_members pm ON p.id = pm.prototype_id
            WHERE pm.user_id = ? OR p.responsavel_id = ?
            ORDER BY p.short_name ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    } else {
        $query = "
            SELECT p.*, u.username as responsavel_nome,
                   CASE WHEN p.responsavel_id = ? THEN 1 ELSE 0 END as is_responsible,
                   CASE WHEN pm.user_id IS NOT NULL THEN 1 ELSE 0 END as is_member
            FROM prototypes p
            LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
            LEFT JOIN prototype_members pm ON p.id = pm.prototype_id AND pm.user_id = ?
            ORDER BY p.short_name ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$current_user_id, $current_user_id]);
    }
    $prototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prototypes = [];
    error_log("Error loading prototypes: " . $e->getMessage());
}

// Buscar todos os utilizadores para dropdown
try {
    $users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Buscar sprints para associação
try {
    $sprints = $pdo->query("SELECT id, nome, estado FROM sprints WHERE estado != 'fechada' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sprints = [];
}

// Protótipo selecionado
$selected_prototype = null;
$prototype_members = [];
if (isset($_GET['prototype_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, u.username as responsavel_nome
            FROM prototypes p
            LEFT JOIN user_tokens u ON p.responsavel_id = u.user_id
            WHERE p.id = ?
        ");
        $stmt->execute([$_GET['prototype_id']]);
        $selected_prototype = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_prototype) {
            // Buscar membros
            $stmt = $pdo->prepare("
                SELECT pm.*, u.username
                FROM prototype_members pm
                JOIN user_tokens u ON pm.user_id = u.user_id
                WHERE pm.prototype_id = ?
                ORDER BY u.username
            ");
            $stmt->execute([$selected_prototype['id']]);
            $prototype_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error loading prototype: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prototypes Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
            line-height: 1.6;
        }

        .container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Left Panel */
        .left-panel {
            width: 350px;
            background: white;
            border-right: 1px solid #e1e8ed;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 20px;
            border-bottom: 1px solid #e1e8ed;
            background: #f8f9fa;
        }

        .panel-header h2 {
            font-size: 22px;
            margin-bottom: 15px;
            color: #1a202c;
        }

        .filter-buttons {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #e1e8ed;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            color: #2c3e50;
        }

        .filter-btn:hover {
            background: #f8f9fa;
            border-color: #3b82f6;
        }

        .filter-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .search-box {
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: #e1e8ed;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .prototypes-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .prototype-item {
            padding: 12px;
            margin-bottom: 8px;
            background: #f9fafb;
            border: 2px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .prototype-item:hover {
            background: #f3f4f6;
            border-color: #e5e7eb;
        }

        .prototype-item.active {
            background: #eff6ff;
            border-color: #3b82f6;
        }

        .prototype-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 5px;
        }

        .prototype-title {
            font-size: 14px;
            font-weight: 600;
            color: #1a202c;
            flex: 1;
        }

        .prototype-badges {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-responsible {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-member {
            background: #d1fae5;
            color: #065f46;
        }

        .prototype-subtitle {
            font-size: 12px;
            color: #64748b;
        }

        /* Right Panel */
        .right-panel {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }

        .section-header h3 {
            font-size: 18px;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #4a5568;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 16px;
            color: #1a202c;
            white-space: pre-wrap;
        }

        /* Members Table */
        .members-table {
            width: 100%;
            border-collapse: collapse;
        }

        .members-table th,
        .members-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .members-table th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 13px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .members-table tbody tr:hover {
            background: #f9fafb;
        }

        /* User Stories */
        .story-item {
            background: #f9fafb;
            border-left: 4px solid #94a3b8;
            padding: 15px;
            margin-bottom: 15px;
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

        .story-item.story-closed {
            opacity: 0.7;
            background: #f1f5f9;
        }

        .story-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .story-priority {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .story-status {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            background: #e0f2fe;
            color: #075985;
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
            margin-bottom: 10px;
        }

        .story-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .story-progress {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }

        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 6px;
        }

        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
            border-radius: 4px;
        }

        .progress-text {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
            line-height: 1;
            padding: 0;
            width: 30px;
            height: 30px;
        }

        .close-modal:hover {
            color: #475569;
        }

        .action-bar {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e1e8ed;
        }

        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .left-panel {
                width: 100%;
                max-height: 40vh;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="container">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="panel-header">
                <h2>Prototypes</h2>
                
                <div class="filter-buttons">
                    <a href="?<?= http_build_query(array_merge($_GET, ['filter_my' => '0', 'filter_resp' => '0'])) ?>" 
                       class="filter-btn <?= !$filter_my_prototypes && !$filter_responsible ? 'active' : '' ?>">
                        📋 All
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['filter_my' => '1', 'filter_resp' => '0'])) ?>" 
                       class="filter-btn <?= $filter_my_prototypes ? 'active' : '' ?>">
                        👤 My Prototypes
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['filter_my' => '0', 'filter_resp' => '1'])) ?>" 
                       class="filter-btn <?= $filter_responsible ? 'active' : '' ?>">
                        ⭐ Responsible
                    </a>
                </div>

                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search..." onkeyup="filterPrototypes()">
                    <button class="btn btn-primary" onclick="openCreateModal()">+ New</button>
                </div>
            </div>

            <div class="prototypes-list" id="prototypesList">
                <?php if (empty($prototypes)): ?>
                    <div class="empty-state">
                        <h3>No prototypes found</h3>
                        <p>Create your first prototype</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($prototypes as $proto): ?>
                        <div class="prototype-item <?= $selected_prototype && $selected_prototype['id'] == $proto['id'] ? 'active' : '' ?>"
                             onclick="window.location.href='?<?= http_build_query(array_merge($_GET, ['prototype_id' => $proto['id']])) ?>'">
                            <div class="prototype-header">
                                <div class="prototype-title"><?= htmlspecialchars($proto['short_name']) ?></div>
                                <div class="prototype-badges">
                                    <?php if ($proto['is_responsible']): ?>
                                        <span class="badge badge-responsible">⭐</span>
                                    <?php endif; ?>
                                    <?php if ($proto['is_member']): ?>
                                        <span class="badge badge-member">👤</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="prototype-subtitle"><?= htmlspecialchars(substr($proto['title'] ?? '', 0, 50)) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel" id="detailPanel">
            <?php if (!$selected_prototype): ?>
                <div class="empty-state">
                    <h3>Select a prototype</h3>
                    <p>Choose a prototype from the list to view details</p>
                </div>
            <?php else: ?>
                <!-- Basic Information -->
                <div class="detail-section">
                    <div class="section-header">
                        <h3>📋 Basic Information</h3>
                        <button class="btn btn-secondary btn-small" onclick="openEditModal()">✏️ Edit</button>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Short Name</div>
                            <div class="info-value"><?= htmlspecialchars($selected_prototype['short_name']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Title</div>
                            <div class="info-value"><?= htmlspecialchars($selected_prototype['title'] ?? '-') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Responsible</div>
                            <div class="info-value"><?= htmlspecialchars($selected_prototype['responsavel_nome'] ?? 'Not assigned') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Created</div>
                            <div class="info-value"><?= date('d/m/Y', strtotime($selected_prototype['created_at'])) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Product Vision Board -->
                <div class="detail-section">
                    <div class="section-header">
                        <h3>🎯 Product Vision Board</h3>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Vision</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selected_prototype['vision'] ?? '-')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Target Group</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selected_prototype['target_group'] ?? '-')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Needs</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selected_prototype['needs'] ?? '-')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Product Description</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selected_prototype['product_description'] ?? '-')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Business Goals</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($selected_prototype['business_goals'] ?? '-')) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Team Members -->
                <div class="detail-section">
                    <div class="section-header">
                        <h3>👥 Team Members</h3>
                        <button class="btn btn-success btn-small" onclick="openAddMemberModal()">+ Add Member</button>
                    </div>
                    <?php if (empty($prototype_members)): ?>
                        <div class="empty-state">
                            <p>No team members yet. Add members to collaborate.</p>
                        </div>
                    <?php else: ?>
                        <table class="members-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prototype_members as $member): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($member['username']) ?></td>
                                        <td><?= htmlspecialchars($member['role']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($member['joined_at'])) ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this member?')">
                                                <input type="hidden" name="action" value="remove_member">
                                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-small">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- User Stories (API based) -->
                <div class="detail-section">
                    <div class="section-header">
                        <h3>📝 User Stories</h3>
                    </div>
                    <div class="filter-bar">
                        <select id="statusFilter" onchange="loadStories()">
                            <option value="">All Status</option>
                            <option value="open" selected>Open Stories</option>
                            <option value="closed">Closed Stories</option>
                        </select>
                        <select id="priorityFilter" onchange="loadStories()">
                            <option value="">All Priorities</option>
                            <option value="Must">Must Have</option>
                            <option value="Should">Should Have</option>
                            <option value="Could">Could Have</option>
                            <option value="Won't">Won't Have</option>
                        </select>
                        <button class="btn btn-primary btn-small" onclick="openStoryModal()">+ Add Story</button>
                    </div>
                    <div id="storiesList">
                        <div class="empty-state">
                            <p>Loading stories...</p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="detail-section">
                    <div class="action-bar">
                        <button class="btn btn-success" onclick="exportMarkdown()">📄 Export MD</button>
                        <button class="btn btn-danger" onclick="deletePrototype()">🗑️ Delete Prototype</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for Create/Edit Prototype -->
    <div class="modal" id="prototypeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">New Prototype</h3>
                <button class="close-modal" onclick="closePrototypeModal()">&times;</button>
            </div>
            <form method="POST" id="prototypeForm">
                <input type="hidden" name="action" id="formAction" value="create_prototype">
                <input type="hidden" name="prototype_id" id="prototypeId">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Short Name *</label>
                        <input type="text" name="short_name" id="short_name" required>
                    </div>
                    <div class="form-group">
                        <label>Responsible</label>
                        <select name="responsavel_id" id="responsavel_id">
                            <option value="">Not assigned</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="title" required>
                </div>
                
                <div class="form-group">
                    <label>Vision</label>
                    <textarea name="vision" id="vision"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Target Group</label>
                    <textarea name="target_group" id="target_group"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Needs</label>
                    <textarea name="needs" id="needs"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Product Description</label>
                    <textarea name="product_description" id="product_description"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Business Goals</label>
                    <textarea name="business_goals" id="business_goals"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Product Statement</label>
                    <textarea name="sentence" id="sentence"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Repository Links</label>
                    <textarea name="repo_links" id="repo_links" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Documentation Links</label>
                    <textarea name="documentation_links" id="documentation_links" rows="3"></textarea>
                </div>
                
                <div class="action-bar">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="closePrototypeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for Add Member -->
    <div class="modal" id="memberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Team Member</h3>
                <button class="close-modal" onclick="closeMemberModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="prototype_id" value="<?= $selected_prototype['id'] ?? '' ?>">
                
                <div class="form-group">
                    <label>User *</label>
                    <select name="user_id" required>
                        <option value="">Select user...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="member">Member</option>
                        <option value="lead">Lead</option>
                        <option value="contributor">Contributor</option>
                    </select>
                </div>
                
                <div class="action-bar">
                    <button type="submit" class="btn btn-primary">Add Member</button>
                    <button type="button" class="btn btn-secondary" onclick="closeMemberModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for User Story -->
    <div class="modal" id="storyModal">
        <div class="modal-content">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>

    <!-- Modal for Tasks -->
    <div class="modal" id="taskModal">
        <div class="modal-content">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>

    <!-- Modal for Sprints -->
    <div class="modal" id="sprintModal">
        <div class="modal-content">
            <!-- Content loaded via JavaScript -->
        </div>
    </div>

    <script>
        const PROTOTYPE_ID = <?= $selected_prototype['id'] ?? 'null' ?>;
        const API_PATH = 'prototypes_api.php';
        
        let stories = [];
        let currentStory = null;

        // Prototype Management
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'New Prototype';
            document.getElementById('formAction').value = 'create_prototype';
            document.getElementById('prototypeForm').reset();
            document.getElementById('prototypeModal').classList.add('active');
        }

        function openEditModal() {
            <?php if ($selected_prototype): ?>
                document.getElementById('modalTitle').textContent = 'Edit Prototype';
                document.getElementById('formAction').value = 'update_prototype';
                document.getElementById('prototypeId').value = '<?= $selected_prototype['id'] ?>';
                document.getElementById('short_name').value = <?= json_encode($selected_prototype['short_name']) ?>;
                document.getElementById('title').value = <?= json_encode($selected_prototype['title'] ?? '') ?>;
                document.getElementById('responsavel_id').value = '<?= $selected_prototype['responsavel_id'] ?? '' ?>';
                document.getElementById('vision').value = <?= json_encode($selected_prototype['vision'] ?? '') ?>;
                document.getElementById('target_group').value = <?= json_encode($selected_prototype['target_group'] ?? '') ?>;
                document.getElementById('needs').value = <?= json_encode($selected_prototype['needs'] ?? '') ?>;
                document.getElementById('product_description').value = <?= json_encode($selected_prototype['product_description'] ?? '') ?>;
                document.getElementById('business_goals').value = <?= json_encode($selected_prototype['business_goals'] ?? '') ?>;
                document.getElementById('sentence').value = <?= json_encode($selected_prototype['sentence'] ?? '') ?>;
                document.getElementById('repo_links').value = <?= json_encode($selected_prototype['repo_links'] ?? '') ?>;
                document.getElementById('documentation_links').value = <?= json_encode($selected_prototype['documentation_links'] ?? '') ?>;
                document.getElementById('prototypeModal').classList.add('active');
            <?php endif; ?>
        }

        function closePrototypeModal() {
            document.getElementById('prototypeModal').classList.remove('active');
        }

        function openAddMemberModal() {
            document.getElementById('memberModal').classList.add('active');
        }

        function closeMemberModal() {
            document.getElementById('memberModal').classList.remove('active');
        }

        function deletePrototype() {
            if (!confirm('Delete this prototype? This will also delete all user stories!')) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_prototype">
                <input type="hidden" name="prototype_id" value="${PROTOTYPE_ID}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function filterPrototypes() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const items = document.querySelectorAll('.prototype-item');
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(search) ? 'block' : 'none';
            });
        }

        // User Stories Management (via API)
        async function loadStories() {
            if (!PROTOTYPE_ID) return;
            
            const priority = document.getElementById('priorityFilter')?.value || '';
            const status = document.getElementById('statusFilter')?.value || 'open';
            
            try {
                const url = `${API_PATH}?action=get_stories&prototype_id=${PROTOTYPE_ID}${priority ? `&priority=${priority}` : ''}${status ? `&status=${status}` : ''}`;
                const response = await fetch(url);
                
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                
                stories = Array.isArray(data) ? data : [];
                renderStories();
            } catch (error) {
                console.error('Error loading stories:', error);
                document.getElementById('storiesList').innerHTML = `
                    <div class="empty-state">
                        <h3>⚠️ Error loading stories</h3>
                        <p>${error.message}</p>
                        <button class="btn btn-primary" onclick="loadStories()">🔄 Retry</button>
                    </div>
                `;
            }
        }

        function renderStories() {
            const listEl = document.getElementById('storiesList');
            
            if (stories.length === 0) {
                const status = document.getElementById('statusFilter')?.value || '';
                listEl.innerHTML = `
                    <div class="empty-state">
                        <h3>No user stories found</h3>
                        <p>${status === 'open' ? 'Add your first user story' : 'No closed stories yet'}</p>
                    </div>
                `;
                return;
            }
            
            listEl.innerHTML = stories.map(story => {
                const storyStatus = story.status || 'open';
                const completionPercentage = story.completion_percentage || 0;
                const totalTasks = story.total_tasks || 0;
                const completedTasks = story.completed_tasks || 0;
                
                const statusIcon = storyStatus === 'closed' ? '✅' : '📖';
                const statusClass = storyStatus === 'closed' ? 'story-closed' : 'story-open';
                const progressColor = completionPercentage >= 75 ? '#10b981' : 
                                     completionPercentage >= 50 ? '#f59e0b' : 
                                     completionPercentage >= 25 ? '#3b82f6' : '#94a3b8';
                
                return `
                    <div class="story-item ${story.moscow_priority.toLowerCase()} ${statusClass}">
                        <div class="story-header">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="story-priority priority-${story.moscow_priority.toLowerCase()}">${story.moscow_priority}</span>
                                <span class="story-status">${statusIcon} ${storyStatus === 'closed' ? 'Closed' : 'Open'}</span>
                            </div>
                            <div class="story-actions">
                                <button class="btn btn-secondary btn-small" onclick="viewStoryTasks(${story.id})" title="Manage Tasks">📋 Tasks (${totalTasks})</button>
                                <button class="btn btn-secondary btn-small" onclick="viewStorySprints(${story.id})" title="Manage Sprints">🏃 Sprints</button>
                                <button class="btn btn-secondary btn-small" onclick="toggleStoryStatus(${story.id})" title="${storyStatus === 'open' ? 'Mark as Closed' : 'Reopen Story'}">${storyStatus === 'open' ? '✓' : '↩'}</button>
                                <button class="btn btn-secondary btn-small" onclick="editStory(${story.id})">✏️</button>
                                <button class="btn btn-danger btn-small" onclick="deleteStory(${story.id})">🗑️</button>
                            </div>
                        </div>
                        <div class="story-text">${escapeHtml(story.story_text)}</div>
                        <div class="story-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${completionPercentage}%; background-color: ${progressColor};"></div>
                            </div>
                            <span class="progress-text">${completionPercentage}% Complete (${completedTasks}/${totalTasks} tasks)</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openStoryModal(storyId = null) {
            currentStory = storyId ? stories.find(s => s.id === storyId) : null;
            
            const modal = document.getElementById('storyModal');
            modal.querySelector('.modal-content').innerHTML = `
                <div class="modal-header">
                    <h3>${currentStory ? 'Edit' : 'New'} User Story</h3>
                    <button class="close-modal" onclick="closeStoryModal()">&times;</button>
                </div>
                <div class="form-group">
                    <label>Story Text</label>
                    <textarea id="storyText" placeholder="As a [user type], I want to [action], so that I [benefit]">${currentStory?.story_text || ''}</textarea>
                </div>
                <div class="form-group">
                    <label>MoSCoW Priority</label>
                    <select id="storyPriority">
                        <option value="Must" ${currentStory?.moscow_priority === 'Must' ? 'selected' : ''}>Must Have</option>
                        <option value="Should" ${!currentStory || currentStory?.moscow_priority === 'Should' ? 'selected' : ''}>Should Have</option>
                        <option value="Could" ${currentStory?.moscow_priority === 'Could' ? 'selected' : ''}>Could Have</option>
                        <option value="Won't" ${currentStory?.moscow_priority === "Won't" ? 'selected' : ''}>Won't Have</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="storyStatus">
                        <option value="open" ${!currentStory || currentStory?.status === 'open' ? 'selected' : ''}>Open</option>
                        <option value="closed" ${currentStory?.status === 'closed' ? 'selected' : ''}>Closed</option>
                    </select>
                </div>
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="saveStory()">Save Story</button>
                    <button class="btn btn-secondary" onclick="closeStoryModal()">Cancel</button>
                </div>
            `;
            modal.classList.add('active');
        }

        function closeStoryModal() {
            document.getElementById('storyModal').classList.remove('active');
            currentStory = null;
        }

        function editStory(id) {
            openStoryModal(id);
        }

        async function saveStory() {
            const storyText = document.getElementById('storyText').value.trim();
            const priority = document.getElementById('storyPriority').value;
            const status = document.getElementById('storyStatus').value;
            
            if (!storyText) {
                alert('Please enter story text');
                return;
            }
            
            const data = {
                prototype_id: PROTOTYPE_ID,
                story_text: storyText,
                moscow_priority: priority,
                status: status
            };
            
            if (currentStory) {
                data.id = currentStory.id;
            }
            
            try {
                const action = currentStory ? 'update_story' : 'create_story';
                const response = await fetch(`${API_PATH}?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                if (result.success) {
                    closeStoryModal();
                    loadStories();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error saving story:', error);
                alert('Error saving story');
            }
        }

        async function toggleStoryStatus(id) {
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_story_status');
                formData.append('id', id);
                
                const response = await fetch(API_PATH, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    loadStories();
                }
            } catch (error) {
                console.error('Error toggling status:', error);
            }
        }

        async function deleteStory(id) {
            if (!confirm('Delete this user story?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_story');
                formData.append('id', id);
                
                const response = await fetch(API_PATH, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    loadStories();
                }
            } catch (error) {
                console.error('Error deleting story:', error);
            }
        }

        // Tasks Management
        async function viewStoryTasks(storyId) {
            currentStory = stories.find(s => s.id === storyId);
            
            try {
                const response = await fetch(`${API_PATH}?action=get_story_tasks&story_id=${storyId}`);
                const tasks = await response.json();
                
                const tasksList = tasks.length > 0 ? tasks.map(task => {
                    const statusBadge = task.estado === 'concluida' ? 'success' : 
                                      task.estado === 'em_execucao' ? 'warning' : 'info';
                    return `
                        <div class="story-item">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>${escapeHtml(task.titulo || 'Task #' + task.id)}</strong>
                                    <span class="badge badge-${statusBadge}">${escapeHtml(task.estado || 'aberta')}</span>
                                    ${task.descritivo ? `<div style="font-size: 12px; color: #64748b; margin-top: 4px;">${escapeHtml(task.descritivo).substring(0, 100)}...</div>` : ''}
                                </div>
                                <button class="btn btn-danger btn-small" onclick="unlinkTask(${task.link_id})">Unlink</button>
                            </div>
                        </div>
                    `;
                }).join('') : '<p>No tasks linked to this story yet.</p>';
                
                const modal = document.getElementById('taskModal');
                modal.querySelector('.modal-content').innerHTML = `
                    <div class="modal-header">
                        <h3>Tasks for Story #${storyId}</h3>
                        <button class="close-modal" onclick="closeTaskModal()">&times;</button>
                    </div>
                    <div>${tasksList}</div>
                    <div class="action-bar">
                        <button class="btn btn-primary" onclick="openCreateTaskForm(${storyId})">+ Create New Task</button>
                        <button class="btn btn-secondary" onclick="closeTaskModal()">Close</button>
                    </div>
                `;
                modal.classList.add('active');
            } catch (error) {
                console.error('Error loading tasks:', error);
            }
        }

        function openCreateTaskForm(storyId) {
            currentStory = stories.find(s => s.id === storyId);
            
            const modal = document.getElementById('taskModal');
            modal.querySelector('.modal-content').innerHTML = `
                <div class="modal-header">
                    <h3>Create Task from Story #${storyId}</h3>
                    <button class="close-modal" onclick="closeTaskModal()">&times;</button>
                </div>
                <div class="form-group">
                    <label>Task Title</label>
                    <input type="text" id="taskTitle" placeholder="Task title">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="taskDescription" placeholder="Task description"></textarea>
                </div>
                <div class="action-bar">
                    <button class="btn btn-primary" onclick="createTaskFromStory()">Create Task</button>
                    <button class="btn btn-secondary" onclick="viewStoryTasks(${storyId})">← Back</button>
                </div>
            `;
        }

        async function createTaskFromStory() {
            const title = document.getElementById('taskTitle').value.trim();
            const description = document.getElementById('taskDescription').value.trim();
            
            if (!title) {
                alert('Please enter task title');
                return;
            }
            
            const data = {
                story_id: currentStory.id,
                title: title,
                description: description
            };
            
            try {
                const response = await fetch(`${API_PATH}?action=create_task_from_story`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                if (result.success) {
                    viewStoryTasks(currentStory.id);
                    loadStories();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error creating task:', error);
                alert('Error creating task');
            }
        }

        async function unlinkTask(linkId) {
            if (!confirm('Unlink this task from the story?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'unlink_task');
                formData.append('id', linkId);
                
                const response = await fetch(API_PATH, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    viewStoryTasks(currentStory.id);
                    loadStories();
                }
            } catch (error) {
                console.error('Error unlinking task:', error);
            }
        }

        function closeTaskModal() {
            document.getElementById('taskModal').classList.remove('active');
        }

        // Sprints Management
        async function viewStorySprints(storyId) {
            currentStory = stories.find(s => s.id === storyId);
            
            try {
                const response = await fetch(`${API_PATH}?action=get_story_sprints&story_id=${storyId}`);
                const sprints = await response.json();
                
                const sprintsList = sprints.length > 0 ? sprints.map(sprint => `
                    <div class="story-item">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>${escapeHtml(sprint.nome)}</strong>
                                <span class="badge badge-${sprint.estado}">${escapeHtml(sprint.estado)}</span>
                                ${sprint.data_inicio ? `<div style="font-size: 12px; color: #64748b;">📅 ${sprint.data_inicio} → ${sprint.data_fim || '...'}</div>` : ''}
                            </div>
                            <button class="btn btn-danger btn-small" onclick="unlinkSprint(${sprint.link_id})">Unlink</button>
                        </div>
                    </div>
                `).join('') : '<p>No sprints linked to this story yet.</p>';
                
                const modal = document.getElementById('sprintModal');
                modal.querySelector('.modal-content').innerHTML = `
                    <div class="modal-header">
                        <h3>Sprints for Story #${storyId}</h3>
                        <button class="close-modal" onclick="closeSprintModal()">&times;</button>
                    </div>
                    <div>${sprintsList}</div>
                    <div class="action-bar">
                        <button class="btn btn-primary" onclick="showLinkSprintForm(${storyId})">+ Link Sprint</button>
                        <button class="btn btn-secondary" onclick="closeSprintModal()">Close</button>
                    </div>
                `;
                modal.classList.add('active');
            } catch (error) {
                console.error('Error loading sprints:', error);
            }
        }

        async function showLinkSprintForm(storyId) {
            try {
                const response = await fetch(`${API_PATH}?action=get_available_sprints&story_id=${storyId}`);
                const availableSprints = await response.json();
                
                if (availableSprints.length === 0) {
                    alert('No available sprints to link.');
                    return;
                }
                
                const sprintOptions = availableSprints.map(sprint => 
                    `<option value="${sprint.id}">${escapeHtml(sprint.nome)} (${sprint.estado})</option>`
                ).join('');
                
                const modal = document.getElementById('sprintModal');
                modal.querySelector('.modal-content').innerHTML = `
                    <div class="modal-header">
                        <h3>Link Sprint to Story #${storyId}</h3>
                        <button class="close-modal" onclick="closeSprintModal()">&times;</button>
                    </div>
                    <div class="form-group">
                        <label>Select Sprint</label>
                        <select id="selectSprint">${sprintOptions}</select>
                    </div>
                    <div class="action-bar">
                        <button class="btn btn-primary" onclick="linkSprint(${storyId})">Link Sprint</button>
                        <button class="btn btn-secondary" onclick="viewStorySprints(${storyId})">← Back</button>
                    </div>
                `;
            } catch (error) {
                console.error('Error loading sprints:', error);
            }
        }

        async function linkSprint(storyId) {
            const sprintId = document.getElementById('selectSprint').value;
            
            try {
                const response = await fetch(`${API_PATH}?action=link_sprint`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ story_id: storyId, sprint_id: sprintId })
                });
                
                const result = await response.json();
                if (result.success) {
                    viewStorySprints(storyId);
                }
            } catch (error) {
                console.error('Error linking sprint:', error);
            }
        }

        async function unlinkSprint(linkId) {
            if (!confirm('Unlink this sprint from the story?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'unlink_sprint');
                formData.append('id', linkId);
                
                const response = await fetch(API_PATH, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    viewStorySprints(currentStory.id);
                }
            } catch (error) {
                console.error('Error unlinking sprint:', error);
            }
        }

        function closeSprintModal() {
            document.getElementById('sprintModal').classList.remove('active');
        }

        // Export
        function exportMarkdown() {
            if (!PROTOTYPE_ID) return;
            window.location.href = `${API_PATH}?action=export_markdown&id=${PROTOTYPE_ID}`;
        }

        // Utility
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load stories on page load if prototype is selected
        if (PROTOTYPE_ID) {
            loadStories();
        }
    </script>
</body>
</html>