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

// Verificar se tabela sprints existe
$checkSprints = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'sprints'");
    $checkSprints = $result->rowCount() > 0;
} catch (PDOException $e) {
    $checkSprints = false;
}

// Verificar se tabela todos existe
$checkTodos = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'todos'");
    $checkTodos = $result->rowCount() > 0;
} catch (PDOException $e) {
    $checkTodos = false;
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
    
    // Tabela user_story_sprints (se sprints existe)
    if ($checkSprints) {
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
    }
    
    // Verificar e adicionar coluna responsavel_id à tabela prototypes se não existir
    $checkColumn = $pdo->query("SHOW COLUMNS FROM prototypes LIKE 'responsavel_id'")->fetch();
    if (!$checkColumn) {
        $pdo->exec("ALTER TABLE prototypes ADD COLUMN responsavel_id INT NULL AFTER name");
    }
    
    // Verificar e adicionar coluna parent_id para subprotótipos
    $checkParentColumn = $pdo->query("SHOW COLUMNS FROM prototypes LIKE 'parent_id'")->fetch();
    if (!$checkParentColumn) {
        $pdo->exec("ALTER TABLE prototypes ADD COLUMN parent_id INT NULL AFTER id");
        $pdo->exec("ALTER TABLE prototypes ADD INDEX idx_parent_id (parent_id)");
    }
    
    // Verificar e adicionar coluna completion_percentage à tabela user_stories
    $checkCompletionColumn = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'completion_percentage'")->fetch();
    if (!$checkCompletionColumn) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN completion_percentage INT DEFAULT 0 AFTER status");
    }

    // Verificar e adicionar coluna created_by à tabela user_stories
    $checkCreatedBy = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'created_by'")->fetch();
    if (!$checkCreatedBy) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN created_by INT NULL AFTER created_at");
    }

    // Verificar e adicionar coluna closed_at à tabela user_stories
    $checkClosedAt = $pdo->query("SHOW COLUMNS FROM user_stories LIKE 'closed_at'")->fetch();
    if (!$checkClosedAt) {
        $pdo->exec("ALTER TABLE user_stories ADD COLUMN closed_at DATETIME NULL AFTER created_by");
    }
    // Backfill sempre que existam stories fechadas sem closed_at (cobre migração incremental)
    $pdo->exec("UPDATE user_stories SET closed_at = COALESCE(updated_at, created_at) WHERE status = 'closed' AND closed_at IS NULL");
    
    // Criar tabela story_tasks para associar tasks a stories (se todos existe)
    if ($checkTodos) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS story_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            todo_id INT NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_task (story_id, todo_id),
            INDEX idx_story (story_id),
            INDEX idx_task (todo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    // Tabela de versões de protótipo
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prototype_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prototype_id INT NOT NULL,
            version_name VARCHAR(50) NOT NULL,
            released_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
            INDEX idx_prototype (prototype_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Tabela junction versão ↔ user story
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS version_stories (
            version_id INT NOT NULL,
            story_id INT NOT NULL,
            PRIMARY KEY (version_id, story_id),
            FOREIGN KEY (version_id) REFERENCES prototype_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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

// Obter lista de sprints (se existir)
$sprints = [];
if ($checkSprints) {
    try {
        $stmt = $pdo->query("SELECT id, nome, estado FROM sprints WHERE estado != 'fechada' ORDER BY nome");
        $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignorar erro
    }
}

// Obter lista de projetos (se existir)
$projects = [];
try {
    $result = $pdo->query("SHOW TABLES LIKE 'projects'");
    if ($result->rowCount() > 0) {
        $stmt = $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignorar erro
}

// Helper global: parse links suportando formato antigo ["url"] e novo [{"url":...,"label":...}]
function parseLinksArr($json) {
    if (empty($json)) return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    $result = [];
    foreach ($decoded as $item) {
        if (is_string($item) && trim($item) !== '') {
            $result[] = ['url' => $item, 'label' => ''];
        } elseif (is_array($item) && !empty($item['url'])) {
            $result[] = ['url' => $item['url'], 'label' => $item['label'] ?? ''];
        }
    }
    return $result;
}

// Obter filtros e prototype selecionado (necessário antes de processar POSTs para redirects)
$filterMine = isset($_GET['filter_mine']) ? $_GET['filter_mine'] === 'true' : false;
$filterParticipate = isset($_GET['filter_participate']) ? $_GET['filter_participate'] === 'true' : false;
$showClosedStories = isset($_GET['show_closed']) ? $_GET['show_closed'] === 'true' : false;
$selectedPrototypeId = $_GET['prototype_id'] ?? null;
$currentUserId = $_SESSION['user_id'] ?? null;

// Processar ações
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch($action) {
            case 'create_prototype':
                $stmt = $pdo->prepare("
                    INSERT INTO prototypes (parent_id, short_name, title, vision, target_group, needs, 
                                          product_description, business_goals, sentence, 
                                          repo_links, documentation_links, name, responsavel_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_POST['parent_id'] ?: null,
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
                
            case 'update_prototype_relations':
                $prototypeId = intval($_POST['prototype_id']);
                $newParentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
                
                // Validar que não é o mesmo protótipo
                if ($newParentId === $prototypeId) {
                    throw new Exception("Um protótipo não pode ser subprotótipo de si mesmo!");
                }
                
                // Validar que não cria ciclo (verificar se newParentId é descendente de prototypeId)
                if ($newParentId !== null) {
                    $checkCycle = $pdo->prepare("
                        WITH RECURSIVE prototype_tree AS (
                            SELECT id, parent_id FROM prototypes WHERE id = ?
                            UNION ALL
                            SELECT p.id, p.parent_id 
                            FROM prototypes p
                            INNER JOIN prototype_tree pt ON p.parent_id = pt.id
                        )
                        SELECT COUNT(*) as has_cycle FROM prototype_tree WHERE id = ?
                    ");
                    $checkCycle->execute([$newParentId, $prototypeId]);
                    if ($checkCycle->fetchColumn() > 0) {
                        throw new Exception("Esta relação criaria um ciclo! Um protótipo não pode ser pai de seus ancestrais.");
                    }
                }
                
                // Atualizar parent_id
                $stmt = $pdo->prepare("UPDATE prototypes SET parent_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newParentId, $prototypeId]);
                
                $message = "Relações do protótipo atualizadas com sucesso!";
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
                
            case 'create_story':
                $stmt = $pdo->prepare("
                    INSERT INTO user_stories (prototype_id, story_text, moscow_priority, status, completion_percentage, created_at, created_by)
                    VALUES (?, ?, ?, 'open', 0, NOW(), ?)
                ");
                $stmt->execute([
                    $_POST['prototype_id'],
                    $_POST['story_text'],
                    $_POST['moscow_priority'] ?? 'Should',
                    $currentUserId
                ]);
                $message = "User Story criada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar para evitar resubmissão do formulário
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $_POST['prototype_id'];
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'update_story':
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET 
                        story_text=?, moscow_priority=?, status=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['story_text'],
                    $_POST['moscow_priority'],
                    $_POST['status'],
                    $_POST['story_id']
                ]);
                $message = "User Story atualizada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar para evitar resubmissão do formulário
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'update_story_percentage':
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET 
                        completion_percentage=?, updated_at=NOW()
                    WHERE id=?
                ");
                $percentage = max(0, min(100, (int)$_POST['percentage']));
                $stmt->execute([$percentage, $_POST['story_id']]);
                $message = "Percentagem atualizada com sucesso!";
                $messageType = 'success';
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'toggle_story_status':
                // Alternar status entre open e closed
                $stmt = $pdo->prepare("
                    UPDATE user_stories SET
                        status = CASE
                            WHEN status = 'open' THEN 'closed'
                            ELSE 'open'
                        END,
                        closed_at = CASE
                            WHEN status = 'open' THEN NOW()
                            ELSE NULL
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['story_id']]);
                $message = "Status da story atualizado com sucesso!";
                $messageType = 'success';
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $selectedPrototypeId;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'delete_story':
                $stmt = $pdo->prepare("DELETE FROM user_stories WHERE id=?");
                $stmt->execute([$_POST['story_id']]);
                
                // Obter prototype_id do POST (enviado pelo formulário)
                $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                
                // Redirecionar
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl);
                exit;
                break;
                
            case 'create_task_from_story':
                if ($checkTodos) {
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    if (!$current_user_id) {
                        throw new Exception('Sessão expirada. Por favor, faça login novamente.');
                    }
                    
                    // Criar a task
                    $stmt = $pdo->prepare("
                        INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, projeto_id, estado, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'aberta', NOW())
                    ");
                    $stmt->execute([
                        $_POST['titulo'],
                        $_POST['descritivo'] ?? '',
                        $_POST['data_limite'] ?: null,
                        $current_user_id,
                        $_POST['responsavel'] ?: null,
                        $_POST['projeto_id'] ?: null
                    ]);
                    
                    $todo_id = $pdo->lastInsertId();
                    
                    // Associar automaticamente à user story
                    if (!empty($_POST['story_id'])) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO story_tasks (story_id, todo_id) VALUES (?, ?)");
                        $stmt->execute([$_POST['story_id'], $todo_id]);
                    }
                    
                    // Se foi selecionada uma sprint, associar automaticamente
                    if (!empty($_POST['sprint_id']) && $checkSprints) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO sprint_tasks (sprint_id, todo_id) VALUES (?, ?)");
                        $stmt->execute([$_POST['sprint_id'], $todo_id]);
                    }
                    
                    $message = "Task criada e associada com sucesso!";
                    $messageType = 'success';
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Tasks não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'add_story_to_sprint':
                if ($checkSprints) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO user_story_sprints (story_id, sprint_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([
                        $_POST['story_id'],
                        $_POST['sprint_id']
                    ]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Sprints não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'remove_story_from_sprint':
                if ($checkSprints) {
                    $stmt = $pdo->prepare("DELETE FROM user_story_sprints WHERE id=?");
                    $stmt->execute([$_POST['association_id']]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                }
                break;
                
            case 'add_task_to_story':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO story_tasks (story_id, todo_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([
                        $_POST['story_id'],
                        $_POST['todo_id']
                    ]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $message = "Módulo de Tasks não está disponível!";
                    $messageType = 'warning';
                }
                break;
                
            case 'create_version':
                $protoId   = (int)($_POST['prototype_id'] ?? 0);
                $vName     = trim($_POST['version_name'] ?? '');
                $relDate   = !empty($_POST['released_at']) ? $_POST['released_at'] : null;
                $notes     = trim($_POST['version_notes'] ?? '');
                $storyIds  = !empty($_POST['version_story_ids']) ? array_map('intval', $_POST['version_story_ids']) : [];
                if ($protoId && $vName) {
                    $stmt = $pdo->prepare("INSERT INTO prototype_versions (prototype_id, version_name, released_at, notes) VALUES (?,?,?,?)");
                    $stmt->execute([$protoId, $vName, $relDate, $notes ?: null]);
                    $versionId = (int)$pdo->lastInsertId();
                    foreach ($storyIds as $sid) {
                        $pdo->prepare("INSERT IGNORE INTO version_stories (version_id, story_id) VALUES (?,?)")->execute([$versionId, $sid]);
                    }
                    $message = "Versão \"$vName\" criada com " . count($storyIds) . " user " . (count($storyIds) != 1 ? 'stories' : 'story') . "!";
                } else {
                    $message = "Nome da versão e protótipo são obrigatórios.";
                    $messageType = 'warning';
                }
                $prototypeIdForRedirect = $protoId ?: $selectedPrototypeId;
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                if ($filterMine) $redirectUrl .= "&filter_mine=true";
                if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                header("Location: " . $redirectUrl . "&message=" . urlencode($message) . "&type=" . ($messageType ?? 'success'));
                exit;

            case 'delete_version':
                $vid = (int)($_POST['version_id'] ?? 0);
                $protoId = (int)($_POST['prototype_id'] ?? $selectedPrototypeId);
                if ($vid) {
                    $pdo->prepare("DELETE FROM prototype_versions WHERE id=?")->execute([$vid]);
                    $message = "Versão eliminada.";
                }
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=$protoId";
                header("Location: " . $redirectUrl);
                exit;

            case 'update_version_stories':
                $vid      = (int)($_POST['version_id'] ?? 0);
                $protoId  = (int)($_POST['prototype_id'] ?? $selectedPrototypeId);
                $storyIds = !empty($_POST['version_story_ids']) ? array_map('intval', $_POST['version_story_ids']) : [];
                if ($vid) {
                    $pdo->prepare("DELETE FROM version_stories WHERE version_id=?")->execute([$vid]);
                    foreach ($storyIds as $sid) {
                        $pdo->prepare("INSERT IGNORE INTO version_stories (version_id, story_id) VALUES (?,?)")->execute([$vid, $sid]);
                    }
                    $message = "Stories da versão atualizadas!";
                }
                $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=$protoId";
                header("Location: " . $redirectUrl);
                exit;

            case 'remove_task_from_story':
                if ($checkTodos) {
                    $stmt = $pdo->prepare("DELETE FROM story_tasks WHERE id=?");
                    $stmt->execute([$_POST['association_id']]);
                    
                    // Obter prototype_id do POST
                    $prototypeIdForRedirect = $_POST['prototype_id'] ?? $selectedPrototypeId;
                    
                    // Redirecionar
                    $redirectUrl = "?tab=prototypes/prototypesv2&prototype_id=" . $prototypeIdForRedirect;
                    if ($filterMine) $redirectUrl .= "&filter_mine=true";
                    if ($filterParticipate) $redirectUrl .= "&filter_participate=true";
                    if ($showClosedStories) $redirectUrl .= "&show_closed=true";
                    header("Location: " . $redirectUrl);
                    exit;
                }
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

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
    ORDER BY p.parent_id ASC, p.short_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allPrototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Função para construir árvore hierárquica
function buildPrototypeTree($prototypes, $parentId = null) {
    $branch = [];
    foreach ($prototypes as $prototype) {
        if ($prototype['parent_id'] == $parentId) {
            $children = buildPrototypeTree($prototypes, $prototype['id']);
            if ($children) {
                $prototype['children'] = $children;
            }
            $branch[] = $prototype;
        }
    }
    return $branch;
}

// Construir árvore (apenas protótipos raiz para a lista lateral)
$prototypes = buildPrototypeTree($allPrototypes);

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
        
        // Obter user stories (com filtro de status)
        $statusCondition = $showClosedStories ? "" : "AND us.status = 'open'";
        $stmt = $pdo->prepare("
            SELECT us.*, u.username as created_by_name
            FROM user_stories us
            LEFT JOIN user_tokens u ON us.created_by = u.user_id
            WHERE us.prototype_id = ? $statusCondition
            ORDER BY FIELD(us.moscow_priority, 'Must', 'Should', 'Could', 'Won''t'), us.id
        ");
        $stmt->execute([$selectedPrototypeId]);
        $selectedPrototype['stories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Estatísticas de stories (total, fechadas, última fechada)
        $statsStmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(status = 'closed') as closed_count,
                MAX(closed_at) as last_closed_at
            FROM user_stories
            WHERE prototype_id = ?
        ");
        $statsStmt->execute([$selectedPrototypeId]);
        $selectedPrototype['story_stats'] = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Para cada story, obter sprints associadas (se módulo sprints existe)
        if ($checkSprints) {
            foreach ($selectedPrototype['stories'] as &$story) {
                $stmt = $pdo->prepare("
                    SELECT uss.id as association_id, s.id as sprint_id, s.nome, s.estado
                    FROM user_story_sprints uss
                    JOIN sprints s ON uss.sprint_id = s.id
                    WHERE uss.story_id = ?
                    ORDER BY s.nome
                ");
                $stmt->execute([$story['id']]);
                $story['sprints'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($story); // CRÍTICO: Destruir a referência após o loop!
        }
        
        // Para cada story, obter tasks associadas (se módulo todos existe)
        // Versões do protótipo com as suas stories
        $selectedPrototype['versions'] = [];
        try {
            $vStmt = $pdo->prepare("
                SELECT pv.*, GROUP_CONCAT(vs.story_id) as story_ids_csv
                FROM prototype_versions pv
                LEFT JOIN version_stories vs ON vs.version_id = pv.id
                WHERE pv.prototype_id = ?
                GROUP BY pv.id
                ORDER BY pv.released_at DESC, pv.created_at DESC
            ");
            $vStmt->execute([$selectedPrototypeId]);
            foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $vrow) {
                $vrow['story_ids'] = $vrow['story_ids_csv'] ? array_map('intval', explode(',', $vrow['story_ids_csv'])) : [];
                $selectedPrototype['versions'][] = $vrow;
            }
        } catch (PDOException $e) {}

        if ($checkTodos) {
            foreach ($selectedPrototype['stories'] as &$story) {
                $stmt = $pdo->prepare("
                    SELECT st.id as association_id, t.*, u1.username as autor_nome, u2.username as responsavel_nome
                    FROM story_tasks st
                    JOIN todos t ON st.todo_id = t.id
                    LEFT JOIN user_tokens u1 ON t.autor = u1.user_id
                    LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
                    WHERE st.story_id = ?
                    ORDER BY t.created_at DESC
                ");
                $stmt->execute([$story['id']]);
                $story['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($story); // CRÍTICO: Destruir a referência após o loop!
        }
    }
}

// Overview: estatísticas de todos os protótipos (para o painel de boas-vindas)
$overviewData = [];
if (!$selectedPrototype) {
    try {
        $ovSql = "
            SELECT
                p.id, p.short_name, p.title, p.parent_id,
                p.updated_at as proto_updated_at,
                p.repo_links, p.documentation_links,
                u.username as responsavel_nome,
                COUNT(DISTINCT us.id)                                        as total_stories,
                SUM(us.status = 'closed')                                    as closed_stories,
                MAX(us.closed_at)                                            as last_closed_at,
                GREATEST(
                    COALESCE(MAX(us.updated_at), '2000-01-01'),
                    COALESCE(MAX(us.created_at), '2000-01-01'),
                    COALESCE(p.updated_at,       '2000-01-01'),
                    COALESCE(p.created_at,       '2000-01-01')
                )                                                            as last_activity_at,
                COUNT(DISTINCT pm.id)                                        as member_count
            FROM prototypes p
            LEFT JOIN user_tokens u   ON p.responsavel_id = u.user_id
            LEFT JOIN user_stories us ON us.prototype_id = p.id
            LEFT JOIN prototype_members pm ON pm.prototype_id = p.id
            " . ($whereClause ?: '') . "
            GROUP BY p.id, p.short_name, p.title, p.parent_id, p.updated_at, p.created_at, u.username
            ORDER BY p.parent_id ASC, p.short_name ASC
        ";
        $ovStmt = $pdo->prepare($ovSql);
        $ovStmt->execute($params);
        $rows = $ovStmt->fetchAll(PDO::FETCH_ASSOC);

        // Sprints por protótipo
        $sprintCounts = [];
        if ($checkSprints) {
            $scStmt = $pdo->query("
                SELECT us.prototype_id, COUNT(DISTINCT uss.sprint_id) as sprint_count
                FROM user_story_sprints uss
                JOIN user_stories us ON uss.story_id = us.id
                GROUP BY us.prototype_id
            ");
            foreach ($scStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                $sprintCounts[$sc['prototype_id']] = (int)$sc['sprint_count'];
            }
        }

        // Projetos por protótipo (via tasks → projeto_id)
        $projectCounts = [];
        if ($checkTodos && !empty($projects)) {
            try {
                $pcStmt = $pdo->query("
                    SELECT us.prototype_id, COUNT(DISTINCT t.projeto_id) as proj_count
                    FROM story_tasks st
                    JOIN todos t ON st.todo_id = t.id
                    JOIN user_stories us ON st.story_id = us.id
                    WHERE t.projeto_id IS NOT NULL
                    GROUP BY us.prototype_id
                ");
                foreach ($pcStmt->fetchAll(PDO::FETCH_ASSOC) as $pc) {
                    $projectCounts[$pc['prototype_id']] = (int)$pc['proj_count'];
                }
            } catch (PDOException $e) {}
        }

        // Índice por id + enriquecer
        $ovById = [];
        foreach ($rows as &$row) {
            $row['sprint_count']  = $sprintCounts[$row['id']]  ?? 0;
            $row['project_count'] = $projectCounts[$row['id']] ?? 0;
            // Activo = atividade nos últimos 30 dias
            $row['is_active'] = $row['last_activity_at'] && (time() - strtotime($row['last_activity_at'])) < 2592000;
            $row['children']  = [];
            $ovById[$row['id']] = &$row;
        }
        unset($row);

        // Construir árvore
        $ovRoots = [];
        foreach ($rows as &$row) {
            if ($row['parent_id'] && isset($ovById[$row['parent_id']])) {
                $ovById[$row['parent_id']]['children'][] = &$row;
                // pai ativo se filho ativo
                if ($row['is_active']) $ovById[$row['parent_id']]['is_active'] = true;
            } else {
                $ovRoots[] = &$row;
            }
        }
        unset($row);
        $overviewData = $ovRoots;
    } catch (PDOException $e) {
        $overviewData = [];
    }
}

// Buscar tasks disponíveis para associar (se um protótipo está selecionado e todos existe)
$availableTasks = [];
if ($selectedPrototype && $checkTodos) {
    try {
        $stmt = $pdo->query("
            SELECT t.id, t.titulo, t.estado, t.data_limite,
                   u1.username as autor_nome, u2.username as responsavel_nome
            FROM todos t 
            LEFT JOIN user_tokens u1 ON t.autor = u1.user_id 
            LEFT JOIN user_tokens u2 ON t.responsavel = u2.user_id
            WHERE t.estado != 'completada'
            ORDER BY t.created_at DESC
            LIMIT 200
        ");
        $availableTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $availableTasks = [];
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

/* Hierarquia de Prototypes */
.prototype-tree {
    list-style: none;
    padding: 0;
    margin: 0;
}

.prototype-tree-item {
    margin-bottom: 5px;
}

.prototype-header {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    background: white;
    border-radius: 6px;
    border: 2px solid #e1e8ed;
    transition: all 0.2s;
}

.prototype-header:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.1);
}

.prototype-header.active {
    border-color: #3b82f6;
    background: #eff6ff;
}

.prototype-expand-btn {
    width: 20px;
    height: 20px;
    min-width: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 8px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #6b7280;
    font-size: 14px;
    padding: 0;
    transition: transform 0.2s;
}

.prototype-expand-btn.expanded {
    transform: rotate(90deg);
}

.prototype-expand-btn:hover {
    color: #3b82f6;
}

.prototype-expand-btn.no-children {
    opacity: 0;
    pointer-events: none;
}

.prototype-info {
    flex: 1;
    min-width: 0;
    cursor: pointer;
}

.prototype-info h5 {
    margin: 0 0 3px 0;
    font-size: 14px;
    color: #1a202c;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.prototype-info p {
    margin: 0;
    font-size: 12px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.prototype-info .badge {
    font-size: 10px;
    padding: 2px 6px;
    margin-top: 4px;
}

.prototype-actions {
    display: flex;
    gap: 5px;
    margin-left: 8px;
}

.btn-add-sub {
    padding: 4px 8px !important;
    font-size: 11px !important;
    border: none;
    background: #10b981 !important;
    color: white !important;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-add-sub:hover {
    background: #059669 !important;
}

.prototype-children {
    margin-left: 24px;
    margin-top: 5px;
    padding-left: 12px;
    border-left: 2px solid #e5e7eb;
    display: none;
}

.prototype-children.expanded {
    display: block;
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

.story-item.closed {
    opacity: 0.6;
    background: #f8f9fa;
}

.story-item.closed .story-text {
    text-decoration: line-through;
    color: #6b7280;
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

.story-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.story-sprints {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
}

.sprint-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 12px;
}

.sprint-badge.aberta {
    border-color: #10b981;
    background: #d1fae5;
    color: #065f46;
}

.sprint-badge.pausa {
    border-color: #f59e0b;
    background: #fef3c7;
    color: #92400e;
}

.sprint-badge.fechada {
    border-color: #6b7280;
    background: #f3f4f6;
    color: #374151;
}

.sprint-badge a {
    color: inherit;
    text-decoration: none;
}

.sprint-badge a:hover {
    text-decoration: underline;
}

.story-progress {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
}

.progress-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar-container {
    flex: 1;
    height: 20px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    transition: width 0.3s ease;
    border-radius: 10px;
}

.progress-percentage {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    min-width: 40px;
    text-align: right;
}

.progress-edit-btn {
    padding: 2px 8px;
    font-size: 11px;
}

.story-tasks {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
}

.task-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 8px;
    transition: all 0.2s;
    cursor: pointer;
}

.task-badge:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
    background: #f9fafb;
}

.task-badge.aberta {
    border-left: 3px solid #fbbf24;
}

.task-badge.em-execucao {
    border-left: 3px solid #3b82f6;
}

.task-badge.suspensa {
    border-left: 3px solid #f59e0b;
}

.task-badge.completada {
    border-left: 3px solid #10b981;
    opacity: 0.7;
}

.task-info {
    flex: 1;
}

.task-title {
    font-weight: 500;
    color: #1a202c;
    margin-bottom: 4px;
}

.task-meta {
    font-size: 12px;
    color: #6b7280;
}

.filter-select {
    margin-bottom: 10px;
}

.filter-select input {
    width: 100%;
    padding: 8px;
    border: 1px solid #e1e8ed;
    border-radius: 4px;
    font-size: 13px;
}

.filter-select input:focus {
    outline: none;
    border-color: #3b82f6;
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
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showClosedStories" 
                           <?= $showClosedStories ? 'checked' : '' ?>
                           onchange="updateFilters()">
                    <label class="form-check-label" for="showClosedStories">
                        Mostrar Stories Fechadas
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
                <?php
                // Função para renderizar árvore de prototypes
                function renderPrototypeTree($prototypes, $selectedId, $filterMine, $filterParticipate, $level = 0) {
                    $filterParams = ($filterMine ? '&filter_mine=true' : '') . ($filterParticipate ? '&filter_participate=true' : '');
                    
                    foreach ($prototypes as $proto) {
                        $hasChildren = !empty($proto['children']);
                        $isActive = $proto['id'] == $selectedId;
                        ?>
                        <div class="prototype-tree-item" data-id="<?= $proto['id'] ?>">
                            <div class="prototype-header <?= $isActive ? 'active' : '' ?>">
                                <button class="prototype-expand-btn <?= $hasChildren ? '' : 'no-children' ?>" 
                                        onclick="togglePrototype(event, <?= $proto['id'] ?>)">
                                    ▶
                                </button>
                                <div class="prototype-info" 
                                     onclick="window.location.href='?tab=prototypes/prototypesv2&prototype_id=<?= $proto['id'] ?><?= $filterParams ?>'">
                                    <h5><?= htmlspecialchars($proto['short_name']) ?></h5>
                                    <p><?= htmlspecialchars($proto['title']) ?></p>
                                    <?php if ($proto['responsavel_nome']): ?>
                                        <span class="badge bg-primary">👤 <?= htmlspecialchars($proto['responsavel_nome']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="prototype-actions">
                                    <button class="btn-add-sub" 
                                            onclick="openAddSubprototype(event, <?= $proto['id'] ?>, '<?= htmlspecialchars(addslashes($proto['short_name'])) ?>')" 
                                            title="Adicionar Subprotótipo">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if ($hasChildren): ?>
                                <div class="prototype-children" id="children-<?= $proto['id'] ?>">
                                    <?php renderPrototypeTree($proto['children'], $selectedId, $filterMine, $filterParticipate, $level + 1); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php
                    }
                }
                
                // Renderizar árvore
                renderPrototypeTree($prototypes, $selectedPrototypeId, $filterMine, $filterParticipate);
                ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Conteúdo Principal -->
    <div class="prototypes-content">
        <?php if (!$selectedPrototype): ?>
            <?php if (empty($overviewData)): ?>
            <div class="empty-state">
                <h3>Nenhum protótipo criado</h3>
                <p>Clique no <strong>+</strong> para criar o primeiro protótipo</p>
            </div>
            <?php else:
            $filterParams = ($filterMine ? '&filter_mine=true' : '') . ($filterParticipate ? '&filter_participate=true' : '');

            function relativeTime($datetime) {
                if (!$datetime) return ['Nenhuma fechada', 'secondary'];
                $diff = time() - strtotime($datetime);
                if ($diff < 3600)    return ['há menos de 1h', 'success'];
                if ($diff < 86400)   return ['há ' . floor($diff/3600) . 'h', 'success'];
                if ($diff < 604800)  return ['há ' . floor($diff/86400) . 'd', 'warning'];
                if ($diff < 2592000) return ['há ' . floor($diff/604800) . 'sem', 'warning'];
                return ['há ' . floor($diff/2592000) . 'meses', 'danger'];
            }

            function renderOvCard($ov, $filterParams, $depth = 0) {
                $total  = (int)$ov['total_stories'];
                $closed = (int)$ov['closed_stories'];
                $open   = $total - $closed;
                $pct    = $total > 0 ? round($closed / $total * 100) : 0;
                [$relText, $relClass] = relativeTime($ov['last_closed_at']);
                $indent = $depth * 20;
                $isChild = $depth > 0;
                ?>
                <div style="margin-left:<?= $indent ?>px; margin-bottom:8px;">
                    <div onclick="window.location='?tab=prototypes/prototypesv2&prototype_id=<?= $ov['id'] . $filterParams ?>'"
                         style="background:<?= $isChild ? '#fafafa' : '#fff' ?>; border:1px solid <?= $isChild ? '#e9ecef' : '#e5e7eb' ?>; border-left:3px solid <?= $ov['is_active'] ? '#10b981' : '#d1d5db' ?>; border-radius:6px; padding:11px 14px; cursor:pointer; transition:border-color .15s, box-shadow .15s;"
                         onmouseover="this.style.borderColor='#3b82f6';this.style.boxShadow='0 2px 6px rgba(59,130,246,.1)'"
                         onmouseout="this.style.borderColor='<?= $isChild ? '#e9ecef' : '#e5e7eb' ?>';this.style.boxShadow='none'">

                        <!-- Linha 1 -->
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <?php if ($isChild): ?>
                                    <span style="font-size:11px; color:#9ca3af;">↳</span>
                                <?php endif; ?>
                                <span style="font-weight:700; font-size:<?= $isChild ? '13' : '14' ?>px; color:#1a202c;"><?= htmlspecialchars($ov['short_name']) ?></span>
                                <span style="font-size:12px; color:#6b7280;"><?= htmlspecialchars($ov['title']) ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <?php if ($ov['responsavel_nome']): ?>
                                <span style="font-size:11px; color:#4b5563; background:#f3f4f6; border-radius:20px; padding:2px 8px; white-space:nowrap;">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($ov['responsavel_nome']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($ov['member_count'] > 0): ?>
                                <span style="font-size:11px; color:#6b7280;" title="Membros"><i class="bi bi-people"></i> <?= $ov['member_count'] ?></span>
                                <?php endif; ?>
                                <?php if ($ov['sprint_count'] > 0): ?>
                                <span style="font-size:11px; color:#6b7280;" title="Sprints"><i class="bi bi-lightning"></i> <?= $ov['sprint_count'] ?></span>
                                <?php endif; ?>
                                <?php if ($ov['project_count'] > 0): ?>
                                <span style="font-size:11px; color:#6b7280;" title="Projetos"><i class="bi bi-folder"></i> <?= $ov['project_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Linha 2: stories -->
                        <?php if ($total > 0): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size:11px; color:#10b981; font-weight:600; white-space:nowrap;"><i class="bi bi-check-circle"></i> <?= $closed ?> fechadas</span>
                            <span style="font-size:11px; color:#3b82f6; font-weight:600; white-space:nowrap;"><i class="bi bi-circle"></i> <?= $open ?> abertas</span>
                            <div style="flex:1; height:5px; background:#e5e7eb; border-radius:3px; overflow:hidden; min-width:40px;">
                                <div style="width:<?= $pct ?>%; height:100%; background:linear-gradient(90deg,#10b981,#059669); border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px; color:#6b7280; white-space:nowrap;"><?= $pct ?>%</span>
                            <span style="font-size:11px; white-space:nowrap;" class="text-<?= $relClass ?>"><i class="bi bi-clock"></i> <?= $relText ?></span>
                        </div>
                        <?php else: ?>
                        <span style="font-size:11px; color:#9ca3af;"><i class="bi bi-inbox"></i> Sem user stories</span>
                        <?php endif; ?>

                        <?php
                        $ovRepoLinks = parseLinksArr($ov['repo_links'] ?? '');
                        $ovDocLinks  = parseLinksArr($ov['documentation_links'] ?? '');
                        if (!empty($ovRepoLinks) || !empty($ovDocLinks)):
                        ?>
                        <div class="d-flex flex-wrap gap-1 mt-2" onclick="event.stopPropagation()">
                            <?php foreach ($ovRepoLinks as $lnk): ?>
                                <a href="<?= htmlspecialchars($lnk['url']) ?>" target="_blank"
                                   style="display:inline-flex; align-items:center; gap:4px; background:#1a202c; color:#fff; border-radius:4px; padding:2px 8px; font-size:11px; text-decoration:none; font-weight:500; white-space:nowrap;"
                                   title="<?= htmlspecialchars($lnk['url']) ?>">
                                    <i class="bi bi-github"></i>
                                    <?= htmlspecialchars($lnk['label'] ?: parse_url($lnk['url'], PHP_URL_HOST) ?: $lnk['url']) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach ($ovDocLinks as $lnk): ?>
                                <a href="<?= htmlspecialchars($lnk['url']) ?>" target="_blank"
                                   style="display:inline-flex; align-items:center; gap:4px; background:#4b5563; color:#fff; border-radius:4px; padding:2px 8px; font-size:11px; text-decoration:none; font-weight:500; white-space:nowrap;"
                                   title="<?= htmlspecialchars($lnk['url']) ?>">
                                    <i class="bi bi-link-45deg"></i>
                                    <?= htmlspecialchars($lnk['label'] ?: parse_url($lnk['url'], PHP_URL_HOST) ?: $lnk['url']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($ov['children'] as $child): ?>
                        <?php renderOvCard($child, $filterParams, $depth + 1); ?>
                    <?php endforeach; ?>
                </div>
                <?php
            }

            // Separar ativos e adormecidos (recursivo: pai ativo se tiver filhos ativos)
            $active   = array_filter($overviewData, fn($o) => $o['is_active']);
            $dormant  = array_filter($overviewData, fn($o) => !$o['is_active']);
            $totalFlat = count($overviewData);
            ?>
            <div style="padding:4px 0;">
                <?php if (!empty($active)): ?>
                <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">
                    <i class="bi bi-activity"></i> Ativos (últimos 30 dias) — <?= count($active) ?>
                </div>
                <?php foreach ($active as $ov): renderOvCard($ov, $filterParams); endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($dormant)): ?>
                <div style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; margin:<?= empty($active) ? '0' : '16px' ?> 0 8px;">
                    <i class="bi bi-moon"></i> Adormecidos — <?= count($dormant) ?>
                </div>
                <?php foreach ($dormant as $ov): renderOvCard($ov, $filterParams); endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <?php
            $repoLinksArr = parseLinksArr($selectedPrototype['repo_links'] ?? '');
            $docLinksArr  = parseLinksArr($selectedPrototype['documentation_links'] ?? '');
            ?>

            <!-- Versões -->
            <div class="mb-3 d-flex align-items-center flex-wrap gap-2">
                <?php foreach ($selectedPrototype['versions'] as $ver): ?>
                <?php
                    $storyCount = count($ver['story_ids']);
                    $relLabel   = $ver['released_at'] ? date('d/m/Y', strtotime($ver['released_at'])) : 'sem data';
                ?>
                <span class="badge d-inline-flex align-items-center gap-1 py-2 px-3"
                      style="background:#1d4ed8; color:#fff; font-size:13px; border-radius:20px; cursor:pointer;"
                      title="<?= htmlspecialchars($ver['notes'] ?? '') ?>"
                      data-bs-toggle="modal" data-bs-target="#versionDetailModal"
                      data-version-id="<?= $ver['id'] ?>"
                      data-version-name="<?= htmlspecialchars($ver['version_name']) ?>"
                      data-version-date="<?= htmlspecialchars($ver['released_at'] ?? '') ?>"
                      data-version-notes="<?= htmlspecialchars($ver['notes'] ?? '') ?>"
                      data-story-ids="<?= htmlspecialchars(implode(',', $ver['story_ids'])) ?>">
                    <i class="bi bi-tag-fill" style="font-size:11px;"></i>
                    <?= htmlspecialchars($ver['version_name']) ?>
                    <span style="opacity:.75; font-size:11px;"><?= $relLabel ?></span>
                    <?php if ($storyCount > 0): ?>
                    <span class="badge bg-white text-primary" style="font-size:10px;"><?= $storyCount ?></span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createVersionModal"
                        style="border-radius:20px; font-size:13px; padding:4px 14px;">
                    <i class="bi bi-plus-lg"></i> Nova versão
                </button>
            </div>

            <!-- Informações Básicas -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-info-circle"></i> Informações Básicas</h5>
                    <div>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editPrototypeModal">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editRelationsModal">
                            <i class="bi bi-diagram-3"></i> Relações
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="if(confirm('Tem certeza?')) { document.getElementById('deleteForm').submit(); }">
                            <i class="bi bi-trash"></i> Eliminar
                        </button>
                    </div>
                </div>

                <!-- Links: sempre visíveis no topo -->
                <?php if (!empty($repoLinksArr) || !empty($docLinksArr)): ?>
                <div class="mb-3 pb-3" style="border-bottom: 1px solid #e5e7eb;">
                    <?php if (!empty($repoLinksArr)): ?>
                    <div class="mb-2">
                        <div class="info-label mb-1"><i class="bi bi-github"></i> Repositórios GIT</div>
                        <?php foreach ($repoLinksArr as $lnk): ?>
                            <a href="<?= htmlspecialchars($lnk['url']) ?>" target="_blank"
                               class="d-inline-flex align-items-center gap-1 me-2 mb-1 text-decoration-none"
                               style="background:#1a202c; color:#fff; border-radius:6px; padding:5px 12px; font-size:13px; font-weight:500;">
                                <i class="bi bi-github"></i>
                                <?= htmlspecialchars($lnk['label'] ?: $lnk['url']) ?>
                                <i class="bi bi-box-arrow-up-right" style="font-size:11px; opacity:.7;"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($docLinksArr)): ?>
                    <div>
                        <div class="info-label mb-1"><i class="bi bi-link-45deg"></i> Outros Links</div>
                        <?php foreach ($docLinksArr as $lnk): ?>
                            <a href="<?= htmlspecialchars($lnk['url']) ?>" target="_blank"
                               class="d-inline-flex align-items-center gap-1 me-2 mb-1 text-decoration-none"
                               style="background:#4b5563; color:#fff; border-radius:6px; padding:5px 12px; font-size:13px; font-weight:500;">
                                <i class="bi bi-link-45deg"></i>
                                <?= htmlspecialchars($lnk['label'] ?: $lnk['url']) ?>
                                <i class="bi bi-box-arrow-up-right" style="font-size:11px; opacity:.7;"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Campos sempre visíveis -->
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
                </div>

                <!-- Botão Mais Informação -->
                <button class="btn btn-sm btn-outline-secondary mt-2" type="button"
                        onclick="toggleMoreInfo(this)" id="moreInfoBtn">
                    <i class="bi bi-chevron-down"></i> Mais informação
                </button>

                <!-- Secção colapsável -->
                <div id="moreInfoSection" style="display:none;" class="mt-3">
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-label">Protótipo Pai</div>
                            <div class="info-value">
                                <?php if ($selectedPrototype['parent_id']): ?>
                                    <?php
                                    $parentStmt = $pdo->prepare("SELECT short_name FROM prototypes WHERE id = ?");
                                    $parentStmt->execute([$selectedPrototype['parent_id']]);
                                    $parentName = $parentStmt->fetchColumn();
                                    ?>
                                    <a href="?tab=prototypes/prototypesv2&prototype_id=<?= $selectedPrototype['parent_id'] ?>"
                                       class="text-primary">
                                        🔗 <?= htmlspecialchars($parentName) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Protótipo raiz</span>
                                <?php endif; ?>
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
                        <p class="mb-0" style="font-style:italic;"><?= nl2br(htmlspecialchars($selectedPrototype['sentence'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($selectedPrototype['business_goals']): ?>
                    <div class="mt-3">
                        <div class="info-label">Objetivos de Negócio</div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['business_goals'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($selectedPrototype['product_description']): ?>
                    <div class="mt-3">
                        <div class="info-label">Descrição do Produto</div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['product_description'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($selectedPrototype['needs']): ?>
                    <div class="mt-3">
                        <div class="info-label">Necessidades</div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['needs'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($selectedPrototype['target_group']): ?>
                    <div class="mt-3">
                        <div class="info-label">Grupo Alvo</div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($selectedPrototype['target_group'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Membros da Equipa (dentro das Informações Básicas) -->
                <div class="mt-3 pt-3" style="border-top:1px solid #e5e7eb;">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="info-label mb-0"><i class="bi bi-people"></i> Equipa</div>
                        <button class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:12px;" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                            <i class="bi bi-person-plus"></i> Adicionar
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if (!empty($selectedPrototype['members'])): ?>
                            <?php foreach ($selectedPrototype['members'] as $member): ?>
                                <span class="d-inline-flex align-items-center gap-1"
                                      style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:20px; padding:3px 10px 3px 8px; font-size:13px;">
                                    <i class="bi bi-person-circle" style="color:#6b7280;"></i>
                                    <?= htmlspecialchars($member['username']) ?>
                                    <?php if ($member['role'] !== 'member'): ?>
                                        <span style="font-size:11px; color:#6b7280; margin-left:2px;">(<?= htmlspecialchars($member['role']) ?>)</span>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remover <?= htmlspecialchars(addslashes($member['username'])) ?>?')">
                                        <input type="hidden" name="action" value="remove_member">
                                        <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                        <button type="submit" style="background:none; border:none; padding:0; margin-left:2px; color:#9ca3af; cursor:pointer; line-height:1;" title="Remover">
                                            <i class="bi bi-x" style="font-size:14px;"></i>
                                        </button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:13px;">Nenhum membro adicionado</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- User Stories -->
            <div class="detail-section">
                <div class="section-header">
                    <h5><i class="bi bi-book"></i> User Stories</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newStoryModal">
                        <i class="bi bi-plus-lg"></i> Nova Story
                    </button>
                </div>

                <?php
                $stats = $selectedPrototype['story_stats'] ?? [];
                $totalStories   = (int)($stats['total'] ?? 0);
                $closedStories  = (int)($stats['closed_count'] ?? 0);
                $openStories    = $totalStories - $closedStories;
                $lastClosedAt   = $stats['last_closed_at'] ?? null;

                if ($totalStories > 0):
                    // Tempo desde a última story fechada
                    $lastClosedText = 'Nenhuma fechada ainda';
                    $lastClosedClass = 'text-danger';
                    if ($lastClosedAt) {
                        $diff = time() - strtotime($lastClosedAt);
                        if ($diff < 3600)        { $lastClosedText = 'há menos de 1 hora'; $lastClosedClass = 'text-success'; }
                        elseif ($diff < 86400)   { $lastClosedText = 'há ' . floor($diff/3600) . 'h'; $lastClosedClass = 'text-success'; }
                        elseif ($diff < 604800)  { $lastClosedText = 'há ' . floor($diff/86400) . ' dias'; $lastClosedClass = 'text-warning'; }
                        elseif ($diff < 2592000) { $lastClosedText = 'há ' . floor($diff/604800) . ' semanas'; $lastClosedClass = 'text-warning'; }
                        else                     { $lastClosedText = 'há ' . floor($diff/2592000) . ' meses'; $lastClosedClass = 'text-danger'; }
                    }
                    $pct = $totalStories > 0 ? round($closedStories / $totalStories * 100) : 0;
                ?>
                <div class="d-flex align-items-center gap-3 mb-3 p-3 rounded" style="background:#f1f5f9; border:1px solid #e2e8f0;">
                    <div class="text-center" style="min-width:60px;">
                        <div style="font-size:22px; font-weight:700; color:#10b981;"><?= $closedStories ?></div>
                        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Fechadas</div>
                    </div>
                    <div style="width:1px; height:40px; background:#e2e8f0;"></div>
                    <div class="text-center" style="min-width:60px;">
                        <div style="font-size:22px; font-weight:700; color:#3b82f6;"><?= $openStories ?></div>
                        <div style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Abertas</div>
                    </div>
                    <div style="width:1px; height:40px; background:#e2e8f0;"></div>
                    <div class="flex-grow-1">
                        <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">
                            <strong><?= $pct ?>%</strong> concluídas · Última fechada: <span class="<?= $lastClosedClass ?>"><?= $lastClosedText ?></span>
                        </div>
                        <div style="height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                            <div style="width:<?= $pct ?>%; height:100%; background:linear-gradient(90deg,#10b981,#059669); border-radius:4px; transition:width .3s;"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="story-list">
                    <?php if (!empty($selectedPrototype['stories'])): ?>
                        <?php foreach ($selectedPrototype['stories'] as $story): ?>
                            <div class="story-item <?= strtolower($story['moscow_priority']) ?> <?= $story['status'] === 'closed' ? 'closed' : '' ?>">
                                <div class="story-header">
                                    <span class="story-priority priority-<?= strtolower($story['moscow_priority']) ?>">
                                        <?= htmlspecialchars($story['moscow_priority']) ?> Have
                                    </span>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($story['created_by_name'])): ?>
                                        <span style="font-size:12px; color:#6b7280;">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($story['created_by_name']) ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($story['created_at'])): ?>
                                        <span style="font-size:12px; color:#9ca3af;" title="<?= htmlspecialchars($story['created_at']) ?>">
                                            <?= date('d/m/Y', strtotime($story['created_at'])) ?>
                                        </span>
                                        <?php endif; ?>
                                        <span class="badge <?= $story['status'] === 'closed' ? 'bg-secondary' : 'bg-info' ?>">
                                            <?= $story['status'] === 'closed' ? 'Fechada' : 'Aberta' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="story-text">
                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                </div>
                                
                                <!-- Barra de Progresso -->
                                <div class="story-progress">
                                    <div class="progress-container">
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill" style="width: <?= $story['completion_percentage'] ?? 0 ?>%"></div>
                                        </div>
                                        <span class="progress-percentage"><?= $story['completion_percentage'] ?? 0 ?>%</span>
                                        <button class="btn btn-sm btn-outline-secondary progress-edit-btn" 
                                                onclick="editPercentage(<?= $story['id'] ?>, <?= $story['completion_percentage'] ?? 0 ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Sprints Associadas -->
                                <?php if ($checkSprints && !empty($story['sprints'])): ?>
                                <div class="story-sprints">
                                    <small class="text-muted">Sprints:</small>
                                    <?php foreach ($story['sprints'] as $sprint): ?>
                                        <span class="sprint-badge <?= $sprint['estado'] ?>">
                                            <a href="https://criis-projects.inesctec.pt/PK/index.php?tab=sprints&sprint_id=<?= $sprint['sprint_id'] ?>" target="_blank">
                                                🏃 <?= htmlspecialchars($sprint['nome']) ?>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover desta sprint?')">
                                                <input type="hidden" name="action" value="remove_story_from_sprint">
                                                <input type="hidden" name="association_id" value="<?= $sprint['association_id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="line-height: 1;">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Tasks Associadas -->
                                <?php if ($checkTodos && !empty($story['tasks'])): ?>
                                <div class="story-tasks">
                                    <small class="text-muted mb-2 d-block">Tasks Associadas:</small>
                                    <?php foreach ($story['tasks'] as $task): ?>
                                        <div class="task-badge <?= $task['estado'] ?>">
                                            <div class="task-info" onclick="openTaskEditor(<?= $task['id'] ?>)" style="cursor: pointer; flex: 1;">
                                                <div class="task-title">
                                                    <?= htmlspecialchars($task['titulo']) ?>
                                                </div>
                                                <div class="task-meta">
                                                    <span class="badge badge-sm bg-secondary"><?= htmlspecialchars($task['estado']) ?></span>
                                                    <?php if ($task['responsavel_nome']): ?>
                                                        👤 <?= htmlspecialchars($task['responsavel_nome']) ?>
                                                    <?php endif; ?>
                                                    <?php if ($task['data_limite']): ?>
                                                        📅 <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-primary" onclick="openTaskEditor(<?= $task['id'] ?>)" title="Editar Task">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover esta task?')">
                                                <input type="hidden" name="action" value="remove_task_from_story">
                                                <input type="hidden" name="association_id" value="<?= $task['association_id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Remover da Story">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Ações -->
                                <div class="story-actions">
                                    <button class="btn btn-sm btn-primary" onclick="editStory(<?= $story['id'] ?>, '<?= htmlspecialchars(addslashes($story['story_text'])) ?>', '<?= $story['moscow_priority'] ?>', '<?= $story['status'] ?>')">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <?php if ($story['status'] === 'open'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_story_status">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-circle"></i> Fechar Story
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_story_status">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reabrir Story
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($checkSprints): ?>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#addStoryToSprintModal<?= $story['id'] ?>">
                                        <i class="bi bi-link-45deg"></i> Associar Sprint
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($checkTodos): ?>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#addTaskToStoryModal<?= $story['id'] ?>">
                                        <i class="bi bi-link-45deg"></i> Associar Task
                                    </button>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createTaskModal<?= $story['id'] ?>">
                                        <i class="bi bi-plus-circle"></i> Criar Task
                                    </button>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar esta story?')">
                                        <input type="hidden" name="action" value="delete_story">
                                        <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                        <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i> Eliminar
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Modal para associar à sprint (um por story) -->
                            <?php if ($checkSprints): ?>
                            <div class="modal fade" id="addStoryToSprintModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Associar Story à Sprint</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="add_story_to_sprint">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Filtrar Sprints</label>
                                                    <input type="text" class="form-control" id="filterSprintInput<?= $story['id'] ?>" 
                                                           placeholder="Digite para filtrar..." 
                                                           onkeyup="filterSprintOptions(<?= $story['id'] ?>)">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Sprint</label>
                                                    <select name="sprint_id" id="sprintSelect<?= $story['id'] ?>" class="form-select" required size="8">
                                                        <option value="">Selecione...</option>
                                                        <?php foreach ($sprints as $sprint): ?>
                                                            <option value="<?= $sprint['id'] ?>">
                                                                <?= htmlspecialchars($sprint['nome']) ?> 
                                                                (<?= htmlspecialchars($sprint['estado']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-success">Associar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Modal para associar task existente (um por story) -->
                            <?php if ($checkTodos): ?>
                            <div class="modal fade" id="addTaskToStoryModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Associar Task à User Story</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="add_task_to_story">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Filtrar Tasks</label>
                                                    <input type="text" class="form-control" id="filterTaskInput<?= $story['id'] ?>" 
                                                           placeholder="Digite para filtrar..." 
                                                           onkeyup="filterTaskOptions(<?= $story['id'] ?>)">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Task</label>
                                                    <select name="todo_id" id="taskSelect<?= $story['id'] ?>" class="form-select" required size="12">
                                                        <option value="">Selecione uma task...</option>
                                                        <?php 
                                                        // Obter IDs de tasks já associadas a esta story
                                                        $associatedTaskIds = array_column($story['tasks'] ?? [], 'id');
                                                        
                                                        foreach ($availableTasks as $task): 
                                                            // Não mostrar tasks já associadas
                                                            if (in_array($task['id'], $associatedTaskIds)) continue;
                                                        ?>
                                                            <option value="<?= $task['id'] ?>">
                                                                [<?= htmlspecialchars($task['estado']) ?>] <?= htmlspecialchars($task['titulo']) ?>
                                                                <?php if ($task['responsavel_nome']): ?>
                                                                    - 👤 <?= htmlspecialchars($task['responsavel_nome']) ?>
                                                                <?php endif; ?>
                                                                <?php if ($task['data_limite']): ?>
                                                                    - 📅 <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                                                <?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="text-muted">
                                                        <?= count($availableTasks) - count($associatedTaskIds) ?> tasks disponíveis
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-success">Associar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Modal para criar task (um por story) -->
                            <?php if ($checkTodos): ?>
                            <div class="modal fade" id="createTaskModal<?= $story['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Criar Task da User Story</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="create_task_from_story">
                                                <input type="hidden" name="story_id" value="<?= $story['id'] ?>">
                                                <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                                                
                                                <div class="alert alert-info">
                                                    <strong>User Story:</strong><br>
                                                    <?= nl2br(htmlspecialchars($story['story_text'])) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Título da Task *</label>
                                                    <input type="text" name="titulo" class="form-control" required 
                                                           placeholder="Ex: Implementar funcionalidade X">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Descrição</label>
                                                    <textarea name="descritivo" class="form-control" rows="4" 
                                                              placeholder="Detalhes da implementação..."></textarea>
                                                    <small class="text-muted">Suporta Markdown</small>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Responsável</label>
                                                        <select name="responsavel" class="form-select">
                                                            <option value="">Não atribuído</option>
                                                            <?php foreach ($users as $user): ?>
                                                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Data Limite</label>
                                                        <input type="date" name="data_limite" class="form-control">
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($projects)): ?>
                                                <div class="mb-3">
                                                    <label class="form-label">Projeto</label>
                                                    <select name="projeto_id" class="form-select">
                                                        <option value="">Nenhum</option>
                                                        <?php foreach ($projects as $project): ?>
                                                            <option value="<?= $project['id'] ?>">
                                                                <?= htmlspecialchars($project['short_name']) ?> - <?= htmlspecialchars($project['title']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($checkSprints && !empty($story['sprints'])): ?>
                                                <div class="mb-3">
                                                    <label class="form-label">Associar à Sprint</label>
                                                    <select name="sprint_id" class="form-select">
                                                        <option value="">Não associar</option>
                                                        <?php foreach ($story['sprints'] as $sprint): ?>
                                                            <option value="<?= $sprint['sprint_id'] ?>">
                                                                <?= htmlspecialchars($sprint['nome']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="text-muted">Sprints associadas a esta user story</small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-success">Criar Task</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nenhuma user story criada</p>
                    <?php endif; ?>
                </div>
            </div>
            
            
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
                    <input type="hidden" name="parent_id" value="">
                    
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

                    <hr>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-github"></i> Repositórios GIT</label>
                        <div id="new-repo-links-container"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLinkRow('new-repo-links-container', 'https://github.com/...')">
                            <i class="bi bi-plus"></i> Adicionar Repositório
                        </button>
                        <input type="hidden" name="repo_links" id="new-repo-links-json">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-link-45deg"></i> Outros Links</label>
                        <div id="new-doc-links-container"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLinkRow('new-doc-links-container', 'https://...')">
                            <i class="bi bi-plus"></i> Adicionar Link
                        </button>
                        <input type="hidden" name="documentation_links" id="new-doc-links-json">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" onclick="serializeLinks('new-repo-links-container','new-repo-links-json'); serializeLinks('new-doc-links-container','new-doc-links-json'); return true;">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Novo Subprotótipo -->
<div class="modal fade" id="addSubprototypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Subprotótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_prototype">
                    <input type="hidden" name="parent_id" id="sub_parent_id" value="">
                    
                    <div class="alert alert-info">
                        <strong>Protótipo Pai:</strong> <span id="sub_parent_name"></span>
                    </div>
                    
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
                        <label class="form-label">Descrição do Produto</label>
                        <textarea name="product_description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Criar Subprotótipo</button>
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

                    <hr>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-github"></i> Repositórios GIT</label>
                        <div id="edit-repo-links-container">
                            <?php foreach ($repoLinksArr as $lnk): ?>
                            <div class="d-flex gap-2 mb-2 link-row">
                                <input type="text" class="form-control link-url" placeholder="https://github.com/..." value="<?= htmlspecialchars($lnk['url']) ?>">
                                <input type="text" class="form-control link-label" placeholder="Descrição" value="<?= htmlspecialchars($lnk['label']) ?>" style="max-width:180px;">
                                <button type="button" class="btn btn-outline-danger" onclick="removeLinkRow(this)"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLinkRow('edit-repo-links-container', 'https://github.com/...')">
                            <i class="bi bi-plus"></i> Adicionar Repositório
                        </button>
                        <input type="hidden" name="repo_links" id="edit-repo-links-json">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-link-45deg"></i> Outros Links</label>
                        <div id="edit-doc-links-container">
                            <?php foreach ($docLinksArr as $lnk): ?>
                            <div class="d-flex gap-2 mb-2 link-row">
                                <input type="text" class="form-control link-url" placeholder="https://..." value="<?= htmlspecialchars($lnk['url']) ?>">
                                <input type="text" class="form-control link-label" placeholder="Descrição" value="<?= htmlspecialchars($lnk['label']) ?>" style="max-width:180px;">
                                <button type="button" class="btn btn-outline-danger" onclick="removeLinkRow(this)"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLinkRow('edit-doc-links-container', 'https://...')">
                            <i class="bi bi-plus"></i> Adicionar Link
                        </button>
                        <input type="hidden" name="documentation_links" id="edit-doc-links-json">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" onclick="serializeLinks('edit-repo-links-container','edit-repo-links-json'); serializeLinks('edit-doc-links-container','edit-doc-links-json');">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Relações do Protótipo -->
<div class="modal fade" id="editRelationsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configurar Relações: <?= htmlspecialchars($selectedPrototype['short_name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_prototype_relations">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        Configure se este protótipo é subprotótipo de outro ou é um protótipo raiz.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Protótipo Pai</label>
                        <select name="parent_id" class="form-select">
                            <option value="">Nenhum (Protótipo Raiz)</option>
                            <?php
                            // Buscar todos os protótipos exceto o atual e seus descendentes
                            $allPrototypesStmt = $pdo->prepare("
                                WITH RECURSIVE descendants AS (
                                    SELECT id FROM prototypes WHERE id = ?
                                    UNION ALL
                                    SELECT p.id FROM prototypes p
                                    INNER JOIN descendants d ON p.parent_id = d.id
                                )
                                SELECT p.id, p.short_name, p.title, p.parent_id
                                FROM prototypes p
                                WHERE p.id NOT IN (SELECT id FROM descendants)
                                ORDER BY p.short_name
                            ");
                            $allPrototypesStmt->execute([$selectedPrototype['id']]);
                            $availablePrototypes = $allPrototypesStmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Criar estrutura hierárquica para exibição
                            function buildParentOptions($prototypes, $currentParentId, $prefix = '') {
                                $tree = [];
                                foreach ($prototypes as $p) {
                                    if ($p['parent_id'] === null) {
                                        $tree[] = [
                                            'prototype' => $p,
                                            'prefix' => $prefix
                                        ];
                                        // Buscar filhos
                                        $tree = array_merge($tree, buildParentOptionsChildren($prototypes, $p['id'], $prefix . '└─ '));
                                    }
                                }
                                return $tree;
                            }
                            
                            function buildParentOptionsChildren($prototypes, $parentId, $prefix) {
                                $children = [];
                                foreach ($prototypes as $p) {
                                    if ($p['parent_id'] === $parentId) {
                                        $children[] = [
                                            'prototype' => $p,
                                            'prefix' => $prefix
                                        ];
                                        $children = array_merge($children, buildParentOptionsChildren($prototypes, $p['id'], $prefix . '  '));
                                    }
                                }
                                return $children;
                            }
                            
                            $hierarchicalOptions = buildParentOptions($availablePrototypes, $selectedPrototype['parent_id']);
                            
                            foreach ($hierarchicalOptions as $item):
                                $p = $item['prototype'];
                                $prefix = $item['prefix'];
                                $isSelected = $p['id'] == $selectedPrototype['parent_id'];
                            ?>
                                <option value="<?= $p['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prefix . $p['short_name']) ?> - <?= htmlspecialchars($p['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Selecione o protótipo pai caso este seja um subprotótipo. 
                            Deixe vazio se for um protótipo raiz independente.
                        </small>
                    </div>
                    
                    <?php
                    // Mostrar subprotótipos atuais
                    $childrenStmt = $pdo->prepare("
                        SELECT id, short_name, title 
                        FROM prototypes 
                        WHERE parent_id = ? 
                        ORDER BY short_name
                    ");
                    $childrenStmt->execute([$selectedPrototype['id']]);
                    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if (!empty($children)): ?>
                        <div class="mt-4">
                            <label class="form-label">Subprotótipos Atuais</label>
                            <div class="list-group">
                                <?php foreach ($children as $child): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($child['short_name']) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($child['title']) ?></small>
                                        </div>
                                        <a href="?tab=prototypes/prototypesv2&prototype_id=<?= $child['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted mt-2 d-block">
                                Para remover um subprotótipo, edite as relações do próprio subprotótipo.
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Relações</button>
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

<!-- Modal: Nova User Story -->
<div class="modal fade" id="newStoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova User Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_story">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Story Text *</label>
                        <textarea name="story_text" class="form-control" rows="4" required 
                                  placeholder="Como [tipo de usuário], eu quero [objetivo], para [benefício]"></textarea>
                        <small class="text-muted">Formato: Como [usuário], eu quero [ação], para [benefício]</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">MoSCoW Priority</label>
                        <select name="moscow_priority" class="form-select">
                            <option value="Must">Must Have</option>
                            <option value="Should" selected>Should Have</option>
                            <option value="Could">Could Have</option>
                            <option value="Won't">Won't Have</option>
                        </select>
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

<!-- Modal: Editar User Story -->
<div class="modal fade" id="editStoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar User Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_story">
                    <input type="hidden" name="story_id" id="edit_story_id">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Story Text *</label>
                        <textarea name="story_text" id="edit_story_text" class="form-control" rows="4" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">MoSCoW Priority</label>
                        <select name="moscow_priority" id="edit_story_priority" class="form-select">
                            <option value="Must">Must Have</option>
                            <option value="Should">Should Have</option>
                            <option value="Could">Could Have</option>
                            <option value="Won't">Won't Have</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_story_status" class="form-select">
                            <option value="open">Aberta</option>
                            <option value="closed">Fechada</option>
                        </select>
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

<!-- Modal: Editar Percentagem -->
<div class="modal fade" id="editPercentageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Percentagem</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_story_percentage">
                    <input type="hidden" name="story_id" id="edit_percentage_story_id">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Percentagem de Conclusão *</label>
                        <input type="number" name="percentage" id="edit_percentage_value" 
                               class="form-control" min="0" max="100" step="5" required>
                        <small class="text-muted">0 a 100%</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Atualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php if ($selectedPrototype): ?>

<!-- Modal: Criar Nova Versão -->
<div class="modal fade" id="createVersionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1d4ed8; color:#fff;">
                <h5 class="modal-title"><i class="bi bi-tag-fill"></i> Nova Versão</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_version">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome da Versão *</label>
                            <input type="text" name="version_name" class="form-control" required placeholder="Ex: v1.0, 2024-Q1, Release 3…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Data de lançamento</label>
                            <input type="date" name="released_at" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notas / Changelog</label>
                        <textarea name="version_notes" class="form-control" rows="3" placeholder="Resumo do que foi entregue nesta versão…"></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">User Stories incluídas nesta versão</label>
                        <div class="text-muted small mb-2">Selecione as user stories (fechadas ou abertas) que fazem parte desta versão.</div>
                        <div style="max-height:280px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px; padding:8px;">
                            <?php
                            $allStoriesForVersion = array_merge(
                                array_filter($selectedPrototype['stories'], fn($s) => $s['status'] === 'closed'),
                                array_filter($selectedPrototype['stories'], fn($s) => $s['status'] !== 'closed')
                            );
                            $moscowV = ['Must'=>'danger','Should'=>'warning','Could'=>'info',"Won't"=>'secondary'];
                            foreach ($allStoriesForVersion as $vs): ?>
                            <div class="form-check py-1 border-bottom">
                                <input class="form-check-input" type="checkbox" name="version_story_ids[]"
                                       value="<?= $vs['id'] ?>" id="vs_<?= $vs['id'] ?>"
                                       <?= $vs['status'] === 'closed' ? 'checked' : '' ?>>
                                <label class="form-check-label d-flex align-items-center gap-2 flex-wrap" for="vs_<?= $vs['id'] ?>">
                                    <span class="badge bg-<?= $moscowV[$vs['moscow_priority']] ?? 'secondary' ?>" style="font-size:10px;"><?= $vs['moscow_priority'] ?></span>
                                    <span class="<?= $vs['status'] === 'closed' ? 'text-decoration-line-through text-muted' : '' ?>">
                                        <?= htmlspecialchars(mb_strimwidth($vs['story_text'], 0, 90, '…')) ?>
                                    </span>
                                    <?php if ($vs['status'] === 'closed'): ?>
                                    <span class="badge bg-secondary" style="font-size:10px;">Fechada</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($allStoriesForVersion)): ?>
                            <div class="text-muted small p-2">Sem user stories neste protótipo.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-tag-fill"></i> Criar Versão</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Detalhe de Versão -->
<div class="modal fade" id="versionDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1d4ed8; color:#fff;">
                <h5 class="modal-title"><i class="bi bi-tag-fill"></i> <span id="vdName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-3 mb-3 text-muted small">
                    <span><i class="bi bi-calendar"></i> <span id="vdDate">—</span></span>
                </div>
                <div id="vdNotes" class="mb-3 fst-italic text-muted" style="display:none;"></div>

                <form method="POST" id="vdForm">
                    <input type="hidden" name="action" value="update_version_stories">
                    <input type="hidden" name="version_id" id="vdId">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">

                    <label class="form-label fw-semibold">User Stories desta versão</label>
                    <div style="max-height:300px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px; padding:8px;" id="vdStoryList">
                        <?php foreach ($allStoriesForVersion as $vs): ?>
                        <div class="form-check py-1 border-bottom">
                            <input class="form-check-input vd-story-check" type="checkbox"
                                   name="version_story_ids[]" value="<?= $vs['id'] ?>"
                                   id="vds_<?= $vs['id'] ?>">
                            <label class="form-check-label d-flex align-items-center gap-2 flex-wrap" for="vds_<?= $vs['id'] ?>">
                                <span class="badge bg-<?= $moscowV[$vs['moscow_priority']] ?? 'secondary' ?>" style="font-size:10px;"><?= $vs['moscow_priority'] ?></span>
                                <span class="<?= $vs['status'] === 'closed' ? 'text-decoration-line-through text-muted' : '' ?>">
                                    <?= htmlspecialchars(mb_strimwidth($vs['story_text'], 0, 90, '…')) ?>
                                </span>
                                <?php if ($vs['status'] === 'closed'): ?>
                                <span class="badge bg-secondary" style="font-size:10px;">Fechada</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
            <div class="modal-footer justify-content-between">
                <form method="POST" onsubmit="return confirm('Eliminar esta versão?')">
                    <input type="hidden" name="action" value="delete_version">
                    <input type="hidden" name="version_id" id="vdDeleteId">
                    <input type="hidden" name="prototype_id" value="<?= $selectedPrototype['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Eliminar versão</button>
                </form>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" form="vdForm" class="btn btn-primary"><i class="bi bi-save"></i> Guardar alterações</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('versionDetailModal').addEventListener('show.bs.modal', function(e) {
    const btn  = e.relatedTarget;
    const id   = btn.dataset.versionId;
    const name = btn.dataset.versionName;
    const date = btn.dataset.versionDate;
    const notes = btn.dataset.versionNotes;
    const storyIds = btn.dataset.storyIds ? btn.dataset.storyIds.split(',').map(Number) : [];

    document.getElementById('vdName').textContent   = name;
    document.getElementById('vdId').value           = id;
    document.getElementById('vdDeleteId').value     = id;
    document.getElementById('vdDate').textContent   = date ? date.split('-').reverse().join('/') : '—';

    const notesEl = document.getElementById('vdNotes');
    if (notes) { notesEl.textContent = notes; notesEl.style.display = ''; }
    else { notesEl.style.display = 'none'; }

    // Marcar checkboxes conforme as stories desta versão
    document.querySelectorAll('.vd-story-check').forEach(cb => {
        cb.checked = storyIds.includes(parseInt(cb.value));
    });
});
</script>

<?php endif; ?>

<script>
// Funções para árvore de prototypes
function togglePrototype(event, prototypeId) {
    event.stopPropagation();
    const children = document.getElementById('children-' + prototypeId);
    const btn = event.target.closest('.prototype-expand-btn');
    
    if (children) {
        children.classList.toggle('expanded');
        btn.classList.toggle('expanded');
    }
}

function openAddSubprototype(event, parentId, parentName) {
    event.stopPropagation();
    document.getElementById('sub_parent_id').value = parentId;
    document.getElementById('sub_parent_name').textContent = parentName;
    const modal = new bootstrap.Modal(document.getElementById('addSubprototypeModal'));
    modal.show();
}

// Expandir automaticamente o path até o protótipo selecionado
document.addEventListener('DOMContentLoaded', function() {
    const activeItem = document.querySelector('.prototype-header.active');
    if (activeItem) {
        let parent = activeItem.closest('.prototype-children');
        while (parent) {
            parent.classList.add('expanded');
            const parentItem = parent.parentElement;
            const expandBtn = parentItem.querySelector('.prototype-expand-btn');
            if (expandBtn) {
                expandBtn.classList.add('expanded');
            }
            parent = parentItem.parentElement.closest('.prototype-children');
        }
    }
});

function updateFilters() {
    const filterMine = document.getElementById('filterMine').checked;
    const filterParticipate = document.getElementById('filterParticipate').checked;
    const showClosedStories = document.getElementById('showClosedStories').checked;
    const prototypeId = <?= $selectedPrototypeId ?? 'null' ?>;
    
    let url = '?tab=prototypes/prototypesv2';
    if (prototypeId) url += '&prototype_id=' + prototypeId;
    if (filterMine) url += '&filter_mine=true';
    if (filterParticipate) url += '&filter_participate=true';
    if (showClosedStories) url += '&show_closed=true';
    
    window.location.href = url;
}

function filterPrototypes() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const items = document.querySelectorAll('.prototype-tree-item');
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(search)) {
            item.style.display = 'block';
            // Expandir parent se estiver a mostrar
            const parent = item.closest('.prototype-children');
            if (parent) {
                parent.classList.add('expanded');
                const parentItem = parent.parentElement;
                const expandBtn = parentItem.querySelector('.prototype-expand-btn');
                if (expandBtn) {
                    expandBtn.classList.add('expanded');
                }
            }
        } else {
            item.style.display = 'none';
        }
    });
}

function editStory(id, text, priority, status) {
    document.getElementById('edit_story_id').value = id;
    document.getElementById('edit_story_text').value = text;
    document.getElementById('edit_story_priority').value = priority;
    document.getElementById('edit_story_status').value = status;
    
    const modal = new bootstrap.Modal(document.getElementById('editStoryModal'));
    modal.show();
}

function editPercentage(storyId, currentPercentage) {
    document.getElementById('edit_percentage_story_id').value = storyId;
    document.getElementById('edit_percentage_value').value = currentPercentage;
    
    const modal = new bootstrap.Modal(document.getElementById('editPercentageModal'));
    modal.show();
}

function filterSprintOptions(storyId) {
    const input = document.getElementById('filterSprintInput' + storyId);
    const select = document.getElementById('sprintSelect' + storyId);
    const filter = input.value.toLowerCase();
    const options = select.getElementsByTagName('option');
    
    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toLowerCase().indexOf(filter) > -1 || options[i].value === '') {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}

function addLinkRow(containerId, placeholder) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'd-flex gap-2 mb-2 link-row';
    row.innerHTML = `<input type="text" class="form-control link-url" placeholder="${placeholder}">
                     <input type="text" class="form-control link-label" placeholder="Descrição" style="max-width:180px;">
                     <button type="button" class="btn btn-outline-danger" onclick="removeLinkRow(this)"><i class="bi bi-x-lg"></i></button>`;
    container.appendChild(row);
    row.querySelector('.link-url').focus();
}

function removeLinkRow(btn) {
    btn.closest('.link-row').remove();
}

function serializeLinks(containerId, hiddenInputId) {
    const container = document.getElementById(containerId);
    const rows = container.querySelectorAll('.link-row');
    const links = [];
    rows.forEach(row => {
        const url = (row.querySelector('.link-url')?.value || '').trim();
        const label = (row.querySelector('.link-label')?.value || '').trim();
        if (url) links.push({ url, label });
    });
    document.getElementById(hiddenInputId).value = JSON.stringify(links);
}

function toggleMoreInfo(btn) {
    const section = document.getElementById('moreInfoSection');
    const icon = btn.querySelector('i');
    const isHidden = section.style.display === 'none';
    section.style.display = isHidden ? 'block' : 'none';
    icon.className = isHidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
    btn.innerHTML = btn.innerHTML.replace(
        isHidden ? 'Mais informação' : 'Menos informação',
        isHidden ? 'Menos informação' : 'Mais informação'
    );
}

function filterTaskOptions(storyId) {
    const input = document.getElementById('filterTaskInput' + storyId);
    const select = document.getElementById('taskSelect' + storyId);
    const filter = input.value.toLowerCase();
    const options = select.getElementsByTagName('option');
    
    for (let i = 0; i < options.length; i++) {
        const txtValue = options[i].textContent || options[i].innerText;
        if (txtValue.toLowerCase().indexOf(filter) > -1 || options[i].value === '') {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}
</script>

<?php
// Incluir editor universal de tasks
if ($checkTodos && file_exists(__DIR__ . '/../../edit_task.php')) {
    include __DIR__ . '/../../edit_task.php';
}
?>