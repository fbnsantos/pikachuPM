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
}

// Criar tabelas de notas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        user_id INT NOT NULL,
        note_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
        INDEX idx_ln_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_note_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES lead_notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// Criar tabela lead_tasks separadamente (após verificar que todos existe)
$todos_exists = $pdo->query("SHOW TABLES LIKE 'todos'")->rowCount() > 0;
$lead_tasks_check = $pdo->query("SHOW TABLES LIKE 'lead_tasks'")->rowCount();

if ($todos_exists && $lead_tasks_check == 0) {
    try {
        $pdo->exec("
            CREATE TABLE lead_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NOT NULL,
                todo_id INT NOT NULL,
                coluna ENUM('aberta', 'em execução', 'suspensa', 'concluída') DEFAULT 'aberta',
                posicao INT DEFAULT 0,
                adicionado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
                FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
                UNIQUE KEY unique_lead_task (lead_id, todo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        // Se houver erro ao criar, não bloquear a página
        error_log("Erro ao criar lead_tasks: " . $e->getMessage());
    }
}

$current_user_id = $_SESSION['user_id'] ?? null;
$message = $_GET['message'] ?? '';
$messageType = $_GET['type'] ?? 'success';

// Verificar se módulo todos está disponível (APÓS tentativa de criação de lead_tasks)
$todos_module_available = $pdo->query("SHOW TABLES LIKE 'todos'")->rowCount() > 0;
$lead_tasks_available = $pdo->query("SHOW TABLES LIKE 'lead_tasks'")->rowCount() > 0;

// Função para detectar estrutura da coluna e converter valores
// NOTA: Esta função não é mais usada para o Kanban, que agora usa diretamente o estado da tabela todos
// Mantida para compatibilidade com histórico de código
function detectarEConverterColuna($pdo, $valor_novo) {
    static $estrutura_cache = null;
    static $mapa_conversao = null;
    
    // Cache da detecção
    if ($estrutura_cache === null) {
        try {
            $result = $pdo->query("SHOW COLUMNS FROM lead_tasks LIKE 'coluna'")->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $type = $result['Type'];
                // Verificar se ainda está com estrutura antiga (sem espaços/acentos)
                if (strpos($type, 'todo') !== false || strpos($type, 'doing') !== false || strpos($type, 'em_execucao') !== false) {
                    $estrutura_cache = 'antiga';
                    $mapa_conversao = [
                        'aberta' => 'todo',
                        'em execução' => 'doing',
                        'suspensa' => 'todo', // Mapear para 'todo' já que não existe na estrutura antiga
                        'concluída' => 'done'
                    ];
                } else {
                    $estrutura_cache = 'nova';
                    $mapa_conversao = null; // Não precisa converter
                }
            }
        } catch (PDOException $e) {
            $estrutura_cache = 'nova'; // Assumir nova em caso de erro
        }
    }
    
    // Se for estrutura antiga, converter
    if ($estrutura_cache === 'antiga' && isset($mapa_conversao[$valor_novo])) {
        return $mapa_conversao[$valor_novo];
    }
    
    // Caso contrário, retornar o valor original
    return $valor_novo;
}

// Função para obter valor seguro para inserir no campo coluna
// Esta função garante que sempre inserimos um valor válido, independente da estrutura
function obterValorSeguroColuna($pdo, $estado) {
    try {
        $result = $pdo->query("SHOW COLUMNS FROM lead_tasks LIKE 'coluna'")->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $type = $result['Type'];
            
            // Extrair valores válidos do ENUM
            preg_match("/^enum\(\'(.*)\'\)$/", $type, $matches);
            if (isset($matches[1])) {
                $valid_values = explode("','", $matches[1]);
                
                // Se o estado já é um valor válido, usar ele
                if (in_array($estado, $valid_values)) {
                    return $estado;
                }
                
                // Mapear estado para valor válido
                $mapa = [
                    'aberta' => ['aberta', 'todo'],
                    'em execução' => ['em execução', 'em_execucao', 'doing'],
                    'suspensa' => ['suspensa', 'todo'],
                    'concluída' => ['concluída', 'concluida', 'done']
                ];
                
                if (isset($mapa[$estado])) {
                    foreach ($mapa[$estado] as $possivel) {
                        if (in_array($possivel, $valid_values)) {
                            return $possivel;
                        }
                    }
                }
                
                // Fallback: usar o primeiro valor válido
                return $valid_values[0];
            }
        }
    } catch (PDOException $e) {
        // Em caso de erro, retornar 'aberta' como fallback
    }
    
    return 'aberta'; // Fallback seguro
}

// Função reversa: converter valores do banco para valores do código
function converterColunaReverso($pdo, $valor_banco) {
    static $estrutura_cache = null;
    static $mapa_reverso = null;
    
    // Cache da detecção
    if ($estrutura_cache === null) {
        try {
            $result = $pdo->query("SHOW COLUMNS FROM lead_tasks LIKE 'coluna'")->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $type = $result['Type'];
                // Verificar se ainda está com estrutura antiga (sem espaços/acentos)
                if (strpos($type, 'todo') !== false || strpos($type, 'doing') !== false || strpos($type, 'em_execucao') !== false) {
                    $estrutura_cache = 'antiga';
                    $mapa_reverso = [
                        'todo' => 'aberta',
                        'doing' => 'em execução',
                        'done' => 'concluída'
                    ];
                } else {
                    $estrutura_cache = 'nova';
                    $mapa_reverso = null; // Não precisa converter
                }
            }
        } catch (PDOException $e) {
            $estrutura_cache = 'nova';
        }
    }
    
    // Se for estrutura antiga, converter de volta
    if ($estrutura_cache === 'antiga' && isset($mapa_reverso[$valor_banco])) {
        return $mapa_reverso[$valor_banco];
    }
    
    // Caso contrário, retornar o valor original
    return $valor_banco;
}

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
                // Verificar se tabelas necessárias existem
                if ($pdo->query("SHOW TABLES LIKE 'todos'")->rowCount() == 0) {
                    $message = "Erro: Tabela 'todos' não existe! Por favor, acesse o módulo ToDos primeiro.";
                    $messageType = 'danger';
                    break;
                }
                
                if ($pdo->query("SHOW TABLES LIKE 'lead_tasks'")->rowCount() == 0) {
                    $message = "Erro: Tabela 'lead_tasks' não existe! Recarregue a página para criar as tabelas.";
                    $messageType = 'danger';
                    break;
                }
                
                // Criar nova task na tabela todos
                // Estado inicial baseado na coluna onde está sendo adicionada
                $estado_inicial = $_POST['kanban_coluna'];
                $stmt = $pdo->prepare("
                    INSERT INTO todos (titulo, descritivo, estado, autor, projeto_id)
                    VALUES (?, ?, ?, ?, NULL)
                ");
                $stmt->execute([
                    $_POST['kanban_titulo'],
                    'Task criada para lead: ' . $_POST['lead_id'],
                    $estado_inicial,
                    $current_user_id
                ]);
                $todo_id = $pdo->lastInsertId();
                
                // Associar task ao lead 
                // IMPORTANTE: O campo coluna não é mais usado para exibição, mas precisa ter valor válido
                $coluna_segura = obterValorSeguroColuna($pdo, $estado_inicial);
                $stmt = $pdo->prepare("INSERT INTO lead_tasks (lead_id, todo_id, coluna) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['lead_id'], $todo_id, $coluna_segura]);
                $message = "Task adicionada ao Kanban!";
                break;
            
            case 'associate_existing_task':
                // Associar task existente ao lead
                // Buscar estado atual da task
                $stmt = $pdo->prepare("SELECT estado FROM todos WHERE id = ?");
                $stmt->execute([$_POST['todo_id']]);
                $todo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($todo) {
                    $coluna_segura = obterValorSeguroColuna($pdo, $todo['estado']);
                    $stmt = $pdo->prepare("INSERT IGNORE INTO lead_tasks (lead_id, todo_id, coluna) VALUES (?, ?, ?)");
                    $stmt->execute([$_POST['lead_id'], $_POST['todo_id'], $coluna_segura]);
                    $message = "Task associada ao Lead!";
                } else {
                    $message = "Erro: Task não encontrada.";
                    $messageType = 'danger';
                }
                break;
                
            case 'update_kanban_item':
                // Buscar o todo_id associado ao lead_task_id
                $stmt = $pdo->prepare("SELECT todo_id FROM lead_tasks WHERE id=?");
                $stmt->execute([$_POST['kanban_id']]);
                $lead_task = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lead_task) {
                    // Atualizar o estado do todo na tabela todos
                    $novo_estado = $_POST['kanban_coluna'];
                    $stmt = $pdo->prepare("UPDATE todos SET estado=? WHERE id=?");
                    $stmt->execute([$novo_estado, $lead_task['todo_id']]);
                    $message = "Task movida! Estado atualizado.";
                } else {
                    $message = "Erro: Task não encontrada.";
                    $messageType = 'danger';
                }
                break;
                
            case 'remove_kanban_item':
                $stmt = $pdo->prepare("DELETE FROM lead_tasks WHERE id=?");
                $stmt->execute([$_POST['kanban_id']]);
                $message = "Task removida do Kanban!";
                break;

            case 'add_lead_note':
                $noteText = trim($_POST['note_text'] ?? '');
                $leadId   = (int)$_POST['lead_id'];
                if ($noteText !== '' || !empty($_FILES['note_images']['name'][0])) {
                    $stmt = $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note_text) VALUES (?,?,?)");
                    $stmt->execute([$leadId, $current_user_id, $noteText]);
                    $noteId = (int)$pdo->lastInsertId();
                    // Guardar imagens
                    if (!empty($_FILES['note_images']['name'][0])) {
                        $uploadDir = __DIR__ . '/../files/lead_notes/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                        foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                            if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                            $mime = mime_content_type($tmp);
                            if (!in_array($mime, $allowed)) continue;
                            $ext  = pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION);
                            $name = 'ln_' . $noteId . '_' . $i . '_' . uniqid() . '.' . $ext;
                            if (move_uploaded_file($tmp, $uploadDir . $name)) {
                                $pdo->prepare("INSERT INTO lead_note_images (note_id, file_path, original_name) VALUES (?,?,?)")
                                    ->execute([$noteId, 'files/lead_notes/' . $name, $_FILES['note_images']['name'][$i]]);
                            }
                        }
                    }
                    $message = "Nota adicionada!";
                } else {
                    $message = "Nota vazia.";
                }
                break;

            case 'delete_lead_note':
                $nid = (int)$_POST['note_id'];
                // Apagar imagens do disco
                $imgs = $pdo->prepare("SELECT file_path FROM lead_note_images WHERE note_id=?");
                $imgs->execute([$nid]);
                foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                    @unlink(__DIR__ . '/../' . $fp);
                }
                $pdo->prepare("DELETE FROM lead_notes WHERE id=? AND user_id=?")->execute([$nid, $current_user_id]);
                $message = "Nota eliminada!";
                break;

            case 'delete_lead_note_image':
                $iid = (int)$_POST['image_id'];
                $row = $pdo->prepare("SELECT lni.file_path, ln.user_id FROM lead_note_images lni JOIN lead_notes ln ON lni.note_id=ln.id WHERE lni.id=?");
                $row->execute([$iid]);
                $img = $row->fetch(PDO::FETCH_ASSOC);
                if ($img && $img['user_id'] == $current_user_id) {
                    @unlink(__DIR__ . '/../' . $img['file_path']);
                    $pdo->prepare("DELETE FROM lead_note_images WHERE id=?")->execute([$iid]);
                }
                $message = "Imagem removida!";
                break;

            case 'edit_lead_note':
                $nid = (int)$_POST['note_id'];
                $pdo->prepare("UPDATE lead_notes SET note_text=? WHERE id=? AND user_id=?")
                    ->execute([trim($_POST['note_text'] ?? ''), $nid, $current_user_id]);
                if (!empty($_FILES['note_images']['name'][0])) {
                    $uploadDir = __DIR__ . '/../files/lead_notes/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                    foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                        if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $mime = mime_content_type($tmp);
                        if (!in_array($mime, $allowed)) continue;
                        $ext  = pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION);
                        $name = 'ln_' . $nid . '_e' . $i . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($tmp, $uploadDir . $name)) {
                            $pdo->prepare("INSERT INTO lead_note_images (note_id, file_path, original_name) VALUES (?,?,?)")
                                ->execute([$nid, 'files/lead_notes/' . $name, $_FILES['note_images']['name'][$i]]);
                        }
                    }
                }
                $message = "Nota atualizada!";
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
$show_closed = isset($_GET['show_closed']) ? $_GET['show_closed'] === '1' : false;
$order_by = isset($_GET['order_by']) ? $_GET['order_by'] : 'relevancia'; // relevancia, dias_fim, titulo
$selected_lead_id = $_GET['lead_id'] ?? null;

// Buscar leads
$query = "
    SELECT DISTINCT l.*, u.username as responsavel_nome,
           CASE WHEN l.responsavel_id = ? THEN 1 ELSE 0 END as is_responsible,
           CASE WHEN lm.user_id IS NOT NULL THEN 1 ELSE 0 END as is_member,
           DATEDIFF(l.data_fim, CURDATE()) as dias_restantes
    FROM leads l
    LEFT JOIN user_tokens u ON l.responsavel_id = u.user_id
    LEFT JOIN lead_members lm ON l.id = lm.lead_id AND lm.user_id = ?
    WHERE 1=1
";

$params = [$current_user_id, $current_user_id];

// Filtrar leads fechadas por padrão
if (!$show_closed) {
    $query .= " AND l.estado != 'fechada'";
}

if ($filter_my_leads) {
    $query .= " AND l.responsavel_id = ?";
    $params[] = $current_user_id;
} elseif ($filter_involved) {
    $query .= " AND (l.responsavel_id = ? OR lm.user_id = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

// Ordenação
switch ($order_by) {
    case 'dias_fim':
        $query .= " ORDER BY 
                    CASE WHEN l.data_fim IS NULL THEN 1 ELSE 0 END,
                    l.data_fim ASC, 
                    l.estado ASC";
        break;
    case 'titulo':
        $query .= " ORDER BY l.titulo ASC";
        break;
    case 'relevancia':
    default:
        $query .= " ORDER BY l.estado ASC, l.relevancia DESC, l.data_fim ASC";
        break;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se um lead foi selecionado, buscar seus detalhes
$selected_lead = null;
$lead_members = [];
$lead_links = [];
$lead_kanban = [];
$lead_notes_by_user = [];

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

        // Buscar notas agrupadas por utilizador
        $lead_notes_by_user = [];
        try {
            $nStmt = $pdo->prepare("
                SELECT ln.id, ln.user_id, ln.note_text, ln.created_at, u.username
                FROM lead_notes ln
                JOIN user_tokens u ON ln.user_id = u.user_id
                WHERE ln.lead_id = ?
                ORDER BY ln.created_at DESC
            ");
            $nStmt->execute([$selected_lead_id]);
            $allNotes = $nStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allNotes as $n) {
                $iStmt = $pdo->prepare("SELECT id, file_path, original_name FROM lead_note_images WHERE note_id=? ORDER BY created_at ASC");
                $iStmt->execute([$n['id']]);
                $n['images'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);
                $lead_notes_by_user[$n['user_id']]['username'] = $n['username'];
                $lead_notes_by_user[$n['user_id']]['notes'][]  = $n;
            }
        } catch (PDOException $e) {}
        
        // Buscar tasks do kanban (verificar se tabela existe)
        $lead_kanban = [];
        $lead_tasks_exists = $pdo->query("SHOW TABLES LIKE 'lead_tasks'")->rowCount() > 0;
        
        if ($lead_tasks_exists) {
            try {
                $stmt = $pdo->prepare("
                    SELECT lt.id as lead_task_id, t.estado as coluna, lt.posicao,
                           t.id as todo_id, t.titulo, t.estado, t.descritivo
                    FROM lead_tasks lt
                    JOIN todos t ON lt.todo_id = t.id
                    WHERE lt.lead_id = ?
                    ORDER BY lt.posicao ASC, lt.adicionado_em ASC
                ");
                $stmt->execute([$selected_lead_id]);
                $lead_kanban = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Não precisa mais converter - já vem com o estado correto do todo
            } catch (PDOException $e) {
                // Se houver erro, deixa array vazio
                $lead_kanban = [];
            }
        }
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
    
    /* Cores específicas para cada coluna */
    .kanban-column:nth-child(1) h5 {
        color: #0d6efd;
        border-bottom-color: #0d6efd;
    }
    
    .kanban-column:nth-child(2) h5 {
        color: #fd7e14;
        border-bottom-color: #fd7e14;
    }
    
    .kanban-column:nth-child(3) h5 {
        color: #ffc107;
        border-bottom-color: #ffc107;
    }
    
    .kanban-column:nth-child(4) h5 {
        color: #198754;
        border-bottom-color: #198754;
    }
    
    .kanban-column h5 {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
    }
    
    .kanban-item {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 10px;
        margin-bottom: 10px;
        cursor: move;
        transition: all 0.2s ease;
    }
    
    .kanban-item:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transform: translateY(-2px);
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
    
    <?php if (!$todos_module_available): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Atenção:</strong> O módulo <strong>ToDos</strong> não está instalado. 
            O Kanban board não estará disponível até que você acesse o módulo <a href="?tab=todos" class="alert-link">ToDos</a> primeiro.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (!$lead_tasks_available): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <i class="bi bi-info-circle"></i>
            A tabela de associação de tasks será criada automaticamente. Por favor, <a href="javascript:location.reload()" class="alert-link">recarregue a página</a>.
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
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="me-3">
                    <strong>Filtrar:</strong>
                </div>
                <a href="?tab=leads&order_by=<?= $order_by ?><?= $show_closed ? '&show_closed=1' : '' ?>" class="btn btn-sm <?= !$filter_my_leads && !$filter_involved ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-list-ul"></i> Todos os Leads
                </a>
                <a href="?tab=leads&filter_my_leads=1&order_by=<?= $order_by ?><?= $show_closed ? '&show_closed=1' : '' ?>" class="btn btn-sm <?= $filter_my_leads ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-person-check"></i> Meus Leads
                </a>
                <a href="?tab=leads&filter_involved=1&order_by=<?= $order_by ?><?= $show_closed ? '&show_closed=1' : '' ?>" class="btn btn-sm <?= $filter_involved ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="bi bi-people"></i> Estou Envolvido
                </a>
                
                <div class="vr"></div>
                
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showClosedCheck" 
                           <?= $show_closed ? 'checked' : '' ?>
                           onchange="window.location.href='?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&order_by=<?= $order_by ?><?= $show_closed ? '' : '&show_closed=1' ?><?= $selected_lead_id ? '&lead_id='.$selected_lead_id : '' ?>'">
                    <label class="form-check-label" for="showClosedCheck">
                        Mostrar Fechadas
                    </label>
                </div>
                
                <div class="ms-auto d-flex gap-2 align-items-center">
                    <strong>Ordenar:</strong>
                    <a href="?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&order_by=relevancia<?= $show_closed ? '&show_closed=1' : '' ?>" 
                       class="btn btn-sm <?= $order_by == 'relevancia' ? 'btn-success' : 'btn-outline-success' ?>">
                        <i class="bi bi-star"></i> Relevância
                    </a>
                    <a href="?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&order_by=dias_fim<?= $show_closed ? '&show_closed=1' : '' ?>" 
                       class="btn btn-sm <?= $order_by == 'dias_fim' ? 'btn-success' : 'btn-outline-success' ?>">
                        <i class="bi bi-calendar-event"></i> Dias Restantes
                    </a>
                    <a href="?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&order_by=titulo<?= $show_closed ? '&show_closed=1' : '' ?>" 
                       class="btn btn-sm <?= $order_by == 'titulo' ? 'btn-success' : 'btn-outline-success' ?>">
                        <i class="bi bi-sort-alpha-down"></i> Título
                    </a>
                </div>
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
                         onclick="window.location.href='?tab=leads<?= $filter_my_leads ? '&filter_my_leads=1' : '' ?><?= $filter_involved ? '&filter_involved=1' : '' ?>&order_by=<?= $order_by ?><?= $show_closed ? '&show_closed=1' : '' ?>&lead_id=<?= $lead['id'] ?>'">>
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
                                
                                <?php if ($lead['dias_restantes'] !== null): ?>
                                    <?php if ($lead['dias_restantes'] < 0): ?>
                                        <span class="badge bg-danger ms-2">
                                            <i class="bi bi-exclamation-triangle"></i> Atrasado <?= abs($lead['dias_restantes']) ?> dias
                                        </span>
                                    <?php elseif ($lead['dias_restantes'] == 0): ?>
                                        <span class="badge bg-warning ms-2">
                                            <i class="bi bi-clock"></i> Hoje
                                        </span>
                                    <?php elseif ($lead['dias_restantes'] <= 3): ?>
                                        <span class="badge bg-warning ms-2">
                                            <i class="bi bi-clock"></i> <?= $lead['dias_restantes'] ?> dias
                                        </span>
                                    <?php elseif ($lead['dias_restantes'] <= 7): ?>
                                        <span class="badge bg-info ms-2">
                                            <i class="bi bi-clock"></i> <?= $lead['dias_restantes'] ?> dias
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary ms-2">
                                            <i class="bi bi-clock"></i> <?= $lead['dias_restantes'] ?> dias
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
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
                
                <!-- Notas -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-journal-text"></i> Notas</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="lnToggleAdd()" id="ln-add-btn">
                            <i class="bi bi-plus"></i> Adicionar nota
                        </button>
                    </div>
                    <div class="card-body p-2">
                        <!-- Formulário de adicionar nota (oculto por default) -->
                        <div id="ln-add-form" style="display:none;" class="mb-3 p-3 bg-light rounded border">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="add_lead_note">
                                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                <div class="mb-2">
                                    <div class="ln-md-toolbar">
                                        <button type="button" onclick="lnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                                        <button type="button" onclick="lnMdWrap(this,'*','*')" title="Itálico"><em>I</em></button>
                                        <button type="button" onclick="lnMdWrap(this,'***','***')" title="Negrito + Itálico"><b><em>BI</em></b></button>
                                        <button type="button" onclick="lnMdWrap(this,'~~','~~')" title="Riscado"><s>S</s></button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdWrap(this,'`','`')" title="Código inline"><code>c</code></button>
                                        <button type="button" onclick="lnMdBlock(this,'```\n','\n```')" title="Bloco de código"><code>```</code></button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'# ')" title="Título H1" style="font-weight:700;">H1</button>
                                        <button type="button" onclick="lnMdInsert(this,'## ')" title="Título H2" style="font-weight:700;">H2</button>
                                        <button type="button" onclick="lnMdInsert(this,'### ')" title="Título H3" style="font-weight:700;">H3</button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'- ')" title="Lista não ordenada">• ≡</button>
                                        <button type="button" onclick="lnMdInsert(this,'1. ')" title="Lista ordenada">1.</button>
                                        <button type="button" onclick="lnMdInsert(this,'- [ ] ')" title="Checklist">☐</button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'> ')" title="Citação / Blockquote">❝</button>
                                        <button type="button" onclick="lnMdWrap(this,'[','](url)')" title="Link">🔗</button>
                                        <button type="button" onclick="lnMdInsertLine(this,'---')" title="Linha horizontal">—</button>
                                        <button type="button" onclick="lnMdTable(this)" title="Inserir tabela">⊞</button>
                                        <span class="ln-md-hint">Markdown</span>
                                    </div>
                                    <textarea name="note_text" class="form-control ln-md-textarea" rows="4"
                                              placeholder="Suporta **Markdown**…" style="font-size:13px;font-family:monospace;"></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small text-muted mb-1">Imagens (opcional)</label>
                                    <input type="file" name="note_images[]" class="form-control form-control-sm"
                                           accept="image/*" multiple id="ln-file-input"
                                           onchange="lnPreviewImages(this,'ln-img-preview')">
                                    <div id="ln-img-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-lg"></i> Guardar
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="lnToggleAdd()">
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>

                        <?php if (empty($lead_notes_by_user)): ?>
                            <p class="text-muted text-center small py-2">Nenhuma nota ainda.</p>
                        <?php else: ?>
                            <?php foreach ($lead_notes_by_user as $uid => $udata): ?>
                            <?php $isMe = ($uid == $current_user_id); ?>
                            <div class="ln-user-block mb-2">
                                <!-- Cabeçalho clicável — todos colapsados por default -->
                                <div class="ln-user-header ln-collapsed" onclick="lnToggleUser(this)">
                                    <span class="ln-avatar <?= $isMe ? 'ln-avatar-me' : '' ?>">
                                        <?= strtoupper(substr($udata['username'], 0, 1)) ?>
                                    </span>
                                    <span class="fw-semibold" style="font-size:14px;">
                                        <?= htmlspecialchars($udata['username']) ?>
                                        <?php if ($isMe): ?><span class="badge bg-secondary ms-1" style="font-size:10px;font-weight:400;">Eu</span><?php endif; ?>
                                    </span>
                                    <span class="ln-count text-muted ms-1" style="font-size:12px;">
                                        (<?= count($udata['notes']) ?>)
                                    </span>
                                    <i class="bi bi-chevron-down ln-chevron ms-auto"></i>
                                </div>
                                <!-- Notas do utilizador — todas ocultas por default -->
                                <div class="ln-user-notes d-none">
                                    <?php foreach ($udata['notes'] as $note): ?>
                                    <div class="ln-note-item" id="ln-note-<?= $note['id'] ?>">
                                        <div class="ln-note-meta">
                                            <small class="text-muted">
                                                <?= date('d/m/Y H:i', strtotime($note['created_at'])) ?>
                                            </small>
                                            <?php if ($note['user_id'] == $current_user_id): ?>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1"
                                                        onclick="lnStartEdit(<?= $note['id'] ?>)" title="Editar nota">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar esta nota?')">
                                                    <input type="hidden" name="action" value="delete_lead_note">
                                                    <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                                    <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" title="Eliminar nota">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Vista MD (renderizado) -->
                                        <div class="ln-note-md-view"
                                             data-raw="<?= htmlspecialchars($note['note_text'], ENT_QUOTES) ?>"></div>

                                        <!-- Formulário de edição (oculto) -->
                                        <?php if ($note['user_id'] == $current_user_id): ?>
                                        <div class="ln-note-edit-area" style="display:none;">
                                            <form method="POST" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="edit_lead_note">
                                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                <div class="ln-md-toolbar mb-1">
                                                    <button type="button" onclick="lnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                                                    <button type="button" onclick="lnMdWrap(this,'*','*')" title="Itálico"><em>I</em></button>
                                                    <button type="button" onclick="lnMdWrap(this,'***','***')" title="Negrito+Itálico"><b><em>BI</em></b></button>
                                                    <button type="button" onclick="lnMdWrap(this,'~~','~~')" title="Riscado"><s>S</s></button>
                                                    <span class="ln-sep"></span>
                                                    <button type="button" onclick="lnMdWrap(this,'`','`')" title="Código inline"><code>c</code></button>
                                                    <button type="button" onclick="lnMdBlock(this,'```\n','\n```')" title="Bloco de código"><code>```</code></button>
                                                    <span class="ln-sep"></span>
                                                    <button type="button" onclick="lnMdInsert(this,'# ')" title="H1" style="font-weight:700;">H1</button>
                                                    <button type="button" onclick="lnMdInsert(this,'## ')" title="H2" style="font-weight:700;">H2</button>
                                                    <button type="button" onclick="lnMdInsert(this,'### ')" title="H3" style="font-weight:700;">H3</button>
                                                    <span class="ln-sep"></span>
                                                    <button type="button" onclick="lnMdInsert(this,'- ')" title="Lista não ordenada">• ≡</button>
                                                    <button type="button" onclick="lnMdInsert(this,'1. ')" title="Lista ordenada">1.</button>
                                                    <button type="button" onclick="lnMdInsert(this,'- [ ] ')" title="Checklist">☐</button>
                                                    <span class="ln-sep"></span>
                                                    <button type="button" onclick="lnMdInsert(this,'> ')" title="Blockquote">❝</button>
                                                    <button type="button" onclick="lnMdWrap(this,'[','](url)')" title="Link">🔗</button>
                                                    <button type="button" onclick="lnMdInsertLine(this,'---')" title="Linha horizontal">—</button>
                                                    <button type="button" onclick="lnMdTable(this)" title="Tabela">⊞</button>
                                                    <span class="ln-md-hint">Markdown</span>
                                                </div>
                                                <textarea name="note_text" class="form-control ln-md-textarea" rows="4"
                                                          style="font-size:13px;font-family:monospace;"><?= htmlspecialchars($note['note_text'], ENT_QUOTES) ?></textarea>
                                                <div class="mt-2 mb-1">
                                                    <label class="form-label small text-muted mb-1">Adicionar imagens</label>
                                                    <input type="file" name="note_images[]" class="form-control form-control-sm"
                                                           accept="image/*" multiple
                                                           onchange="lnPreviewImages(this,'ln-edit-preview-<?= $note['id'] ?>')">
                                                    <div id="ln-edit-preview-<?= $note['id'] ?>" class="d-flex flex-wrap gap-2 mt-1"></div>
                                                </div>
                                                <div class="d-flex gap-2 mt-2">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-check-lg"></i> Guardar
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary"
                                                            onclick="lnCancelEdit(<?= $note['id'] ?>)">
                                                        Cancelar
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($note['images'])): ?>
                                        <div class="ln-note-images mt-2">
                                            <?php foreach ($note['images'] as $img): ?>
                                            <div class="ln-img-wrap">
                                                <img src="<?= htmlspecialchars($img['file_path']) ?>"
                                                     alt="<?= htmlspecialchars($img['original_name']) ?>"
                                                     onclick="lnLightbox(this.src)"
                                                     title="<?= htmlspecialchars($img['original_name']) ?>">
                                                <?php if ($note['user_id'] == $current_user_id): ?>
                                                <form method="POST" class="ln-img-del">
                                                    <input type="hidden" name="action" value="delete_lead_note_image">
                                                    <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                                    <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                    <button type="submit" class="ln-del-img" title="Remover imagem" onclick="return confirm('Remover imagem?')">×</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Lightbox simples -->
                <div id="ln-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;cursor:zoom-out;align-items:center;justify-content:center;"
                     onclick="this.style.display='none'">
                    <img id="ln-lightbox-img" style="max-width:90vw;max-height:90vh;border-radius:6px;box-shadow:0 4px 32px rgba(0,0,0,.5);" src="" alt="">
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
                                'aberta' => ['nome' => 'Aberta', 'icon' => 'bi-circle'],
                                'em execução' => ['nome' => 'Em Execução', 'icon' => 'bi-arrow-clockwise'],
                                'suspensa' => ['nome' => 'Suspensa', 'icon' => 'bi-pause-circle'],
                                'concluída' => ['nome' => 'Concluída', 'icon' => 'bi-check-circle-fill']
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
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="flex-grow-1">
                                                    <strong><?= htmlspecialchars($item['titulo']) ?></strong>
                                                    <?php if ($item['descritivo']): ?>
                                                        <div class="small text-muted mt-1">
                                                            <?= htmlspecialchars(substr($item['descritivo'], 0, 50)) ?>
                                                            <?= strlen($item['descritivo']) > 50 ? '...' : '' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="small mt-1">
                                                        <span class="badge bg-<?= $item['estado'] == 'aberta' ? 'warning' : ($item['estado'] == 'em_progresso' ? 'info' : 'success') ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $item['estado'])) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link" data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <button class="dropdown-item edit-task-btn" data-task-id="<?= $item['todo_id'] ?>" onclick="openTaskEditor(<?= $item['todo_id'] ?>)">
                                                                <i class="bi bi-pencil"></i> Editar Task
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <?php foreach ($colunas as $col_id => $col_info): ?>
                                                            <?php if ($col_id != $coluna_id): ?>
                                                                <li>
                                                                    <form method="post" class="dropdown-item">
                                                                        <input type="hidden" name="action" value="update_kanban_item">
                                                                        <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                                        <input type="hidden" name="kanban_id" value="<?= $item['lead_task_id'] ?>">
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
                                                            <form method="post" class="dropdown-item" onsubmit="return confirm('Remover esta task do lead?')">
                                                                <input type="hidden" name="action" value="remove_kanban_item">
                                                                <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                                                                <input type="hidden" name="kanban_id" value="<?= $item['lead_task_id'] ?>">
                                                                <button type="submit" class="btn btn-link p-0 text-decoration-none text-danger">
                                                                    <i class="bi bi-trash"></i> Remover do Lead
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <button class="btn btn-sm btn-outline-secondary w-100 mt-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#addKanbanModal<?= $coluna_id ?>"
                                            <?= !$todos_module_available || !$lead_tasks_available ? 'disabled' : '' ?>>
                                        <i class="bi bi-plus"></i> Adicionar
                                    </button>
                                    <?php if (!$todos_module_available || !$lead_tasks_available): ?>
                                        <small class="text-muted d-block mt-1">Módulo ToDos necessário</small>
                                    <?php endif; ?>
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
<?php 
// Buscar todas as tasks disponíveis
$all_todos = [];
if ($selected_lead && $pdo->query("SHOW TABLES LIKE 'todos'")->rowCount() > 0) {
    try {
        $all_todos = $pdo->query("
            SELECT t.id, t.titulo, t.estado, u.username as autor_nome
            FROM todos t
            LEFT JOIN user_tokens u ON t.autor = u.user_id
            ORDER BY t.criado_em DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $all_todos = [];
    }
}

if ($selected_lead):
foreach (['aberta', 'em execução', 'suspensa', 'concluída'] as $col): 
?>
<div class="modal fade" id="addKanbanModal<?= $col ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="nova-task-tab-<?= $col ?>" data-bs-toggle="tab" data-bs-target="#nova-task-<?= $col ?>" type="button">
                            <i class="bi bi-plus-circle"></i> Criar Nova Task
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="associar-task-tab-<?= $col ?>" data-bs-toggle="tab" data-bs-target="#associar-task-<?= $col ?>" type="button">
                            <i class="bi bi-link-45deg"></i> Associar Task Existente
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Tab: Criar Nova Task -->
                    <div class="tab-pane fade show active" id="nova-task-<?= $col ?>">
                        <form method="post">
                            <input type="hidden" name="action" value="add_kanban_item">
                            <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                            <input type="hidden" name="kanban_coluna" value="<?= $col ?>">
                            <div class="mb-3">
                                <label class="form-label">Título da Task</label>
                                <input type="text" name="kanban_titulo" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus"></i> Criar e Adicionar
                            </button>
                        </form>
                    </div>
                    
                    <!-- Tab: Associar Task Existente -->
                    <div class="tab-pane fade" id="associar-task-<?= $col ?>">
                        <form method="post">
                            <input type="hidden" name="action" value="associate_existing_task">
                            <input type="hidden" name="lead_id" value="<?= $selected_lead['id'] ?>">
                            <input type="hidden" name="kanban_coluna" value="<?= $col ?>">
                            <div class="mb-3">
                                <label class="form-label">Selecionar Task</label>
                                <select name="todo_id" class="form-select" required size="8">
                                    <?php if (empty($all_todos)): ?>
                                        <option disabled>Nenhuma task disponível</option>
                                    <?php else: ?>
                                        <?php foreach ($all_todos as $todo): ?>
                                            <option value="<?= $todo['id'] ?>">
                                                #<?= $todo['id'] ?> - <?= htmlspecialchars($todo['titulo']) ?>
                                                (<?= ucfirst(str_replace('_', ' ', $todo['estado'])) ?>)
                                                <?php if ($todo['autor_nome']): ?>
                                                    - <?= htmlspecialchars($todo['autor_nome']) ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <small class="text-muted">Mostrando últimas 100 tasks</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" <?= empty($all_todos) ? 'disabled' : '' ?>>
                                <i class="bi bi-link-45deg"></i> Associar Task
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; // Fim do if($selected_lead) ?>

<?php endif; // Fim do if($selected_lead) para todos os modais ?>

<?php
// Incluir editor universal no final da página
include __DIR__ . '/../edit_task.php';
?>

<style>
/* ── Lead Notes ───────────────────────────────────────────── */
.ln-user-block { border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; }
.ln-user-header {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; cursor: pointer; background: #f8f9fa;
    user-select: none; transition: background .15s;
}
.ln-user-header:hover { background: #e9ecef; }
.ln-user-header.ln-collapsed .ln-chevron { transform: rotate(-90deg); }
.ln-chevron { transition: transform .2s; font-size: 13px; color: #6c757d; }
.ln-avatar {
    width: 28px; height: 28px; border-radius: 50%; background: #6c757d;
    color: #fff; font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ln-avatar-me { background: #0d6efd; }
.ln-user-notes { padding: 0 8px 8px; }
.ln-note-item {
    margin-top: 8px; padding: 8px 10px; background: #fff;
    border: 1px solid #e9ecef; border-radius: 6px;
}
.ln-note-meta {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 4px;
}
/* Markdown rendered output */
.ln-note-md-view { font-size: 13px; color: #333; line-height: 1.6; }
.ln-note-md-view p { margin: 0 0 .4em; }
.ln-note-md-view ul, .ln-note-md-view ol { padding-left: 1.4em; margin: .3em 0; }
.ln-note-md-view code { background: #f0f0f0; border-radius: 3px; padding: 1px 4px; font-size: 12px; }
.ln-note-md-view pre code { display: block; padding: 8px; }
.ln-note-md-view h1,.ln-note-md-view h2,.ln-note-md-view h3 { font-size: 14px; font-weight: 700; margin: .5em 0 .2em; }
.ln-note-md-view blockquote { border-left: 3px solid #ccc; margin: .3em 0; padding-left: 8px; color: #666; }
.ln-note-md-view a { color: #0d6efd; }
/* MD toolbar */
.ln-md-toolbar {
    display: flex; align-items: center; gap: 3px; flex-wrap: wrap;
    background: #f1f3f5; border: 1px solid #dee2e6;
    border-bottom: none; border-radius: 4px 4px 0 0; padding: 4px 6px;
}
.ln-md-toolbar + .ln-md-textarea { border-radius: 0 0 4px 4px; }
.ln-md-toolbar button {
    border: 1px solid #ced4da; background: #fff; border-radius: 3px;
    padding: 1px 6px; font-size: 11px; cursor: pointer; line-height: 1.6;
    white-space: nowrap;
}
.ln-md-toolbar button:hover { background: #e9ecef; }
.ln-md-hint { font-size: 10px; color: #adb5bd; margin-left: auto; }
.ln-sep { width: 1px; height: 16px; background: #ced4da; margin: 0 2px; flex-shrink: 0; }
/* Images */
.ln-note-images { display: flex; flex-wrap: wrap; gap: 6px; }
.ln-img-wrap { position: relative; display: inline-block; }
.ln-img-wrap img {
    width: 80px; height: 80px; object-fit: cover; border-radius: 4px;
    cursor: zoom-in; border: 1px solid #dee2e6; transition: opacity .15s;
}
.ln-img-wrap img:hover { opacity: .85; }
.ln-del-img {
    position: absolute; top: -5px; right: -5px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #dc3545; color: #fff; border: none; cursor: pointer;
    font-size: 13px; line-height: 1; display: flex; align-items: center; justify-content: center;
}
.ln-img-del { margin: 0; }
[id^="ln-img-preview"] img,
[id^="ln-edit-preview"] img { width: 72px; height: 72px; object-fit: cover; border-radius: 4px; border: 1px solid #dee2e6; }
</style>

<script>
/* ── Render Markdown on all note views ── */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof marked === 'undefined') return;
    marked.setOptions({ breaks: true, gfm: true });
    document.querySelectorAll('.ln-note-md-view').forEach(function(el) {
        var raw = el.dataset.raw || '';
        el.innerHTML = raw ? marked.parse(raw) : '';
    });
});

function lnToggleAdd() {
    var f = document.getElementById('ln-add-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
    if (f.style.display === 'block') f.querySelector('textarea').focus();
}

function lnToggleUser(header) {
    var notes = header.nextElementSibling;
    var collapsed = header.classList.toggle('ln-collapsed');
    notes.classList.toggle('d-none', collapsed);
}

function lnStartEdit(noteId) {
    var item = document.getElementById('ln-note-' + noteId);
    item.querySelector('.ln-note-md-view').style.display = 'none';
    var editArea = item.querySelector('.ln-note-edit-area');
    editArea.style.display = 'block';
    editArea.querySelector('textarea').focus();
    // hide edit/delete buttons while editing
    item.querySelector('.ln-note-meta .d-flex').style.visibility = 'hidden';
}

function lnCancelEdit(noteId) {
    var item = document.getElementById('ln-note-' + noteId);
    item.querySelector('.ln-note-md-view').style.display = '';
    item.querySelector('.ln-note-edit-area').style.display = 'none';
    item.querySelector('.ln-note-meta .d-flex').style.visibility = '';
}

/* MD toolbar helpers — work on the nearest textarea */
function lnMdWrap(btn, before, after) {
    var ta = btn.closest('.ln-md-toolbar').nextElementSibling;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.substring(s, e) || 'texto';
    ta.value = ta.value.substring(0, s) + before + sel + after + ta.value.substring(e);
    ta.focus();
    ta.selectionStart = s + before.length;
    ta.selectionEnd   = s + before.length + sel.length;
}

function lnMdInsert(btn, prefix) {
    var ta = btn.closest('.ln-md-toolbar').nextElementSibling;
    var s = ta.selectionStart;
    var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
    ta.value = ta.value.substring(0, lineStart) + prefix + ta.value.substring(lineStart);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = lineStart + prefix.length;
}

/* Wraps selection with before/after, ensuring they sit on their own lines */
function lnMdBlock(btn, before, after) {
    var ta = btn.closest('.ln-md-toolbar').nextElementSibling;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.substring(s, e) || 'código';
    var needsBefore = s > 0 && ta.value[s - 1] !== '\n' ? '\n' : '';
    var needsAfter  = e < ta.value.length && ta.value[e] !== '\n' ? '\n' : '';
    var insertion = needsBefore + before + sel + after + needsAfter;
    ta.value = ta.value.substring(0, s) + insertion + ta.value.substring(e);
    ta.focus();
    var innerStart = s + needsBefore.length + before.length;
    ta.selectionStart = innerStart;
    ta.selectionEnd   = innerStart + sel.length;
}

/* Inserts text on its own line (e.g. --- ) */
function lnMdInsertLine(btn, text) {
    var ta = btn.closest('.ln-md-toolbar').nextElementSibling;
    var s = ta.selectionStart;
    var before = s > 0 && ta.value[s - 1] !== '\n' ? '\n' : '';
    var after  = s < ta.value.length && ta.value[s] !== '\n' ? '\n' : '';
    var ins = before + text + after;
    ta.value = ta.value.substring(0, s) + ins + ta.value.substring(s);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + ins.length;
}

/* Inserts a markdown table template */
function lnMdTable(btn) {
    var ta = btn.closest('.ln-md-toolbar').nextElementSibling;
    var s = ta.selectionStart;
    var tpl = '\n| Coluna 1 | Coluna 2 | Coluna 3 |\n| --- | --- | --- |\n| Célula | Célula | Célula |\n';
    var before = s > 0 && ta.value[s - 1] !== '\n' ? '\n' : '';
    ta.value = ta.value.substring(0, s) + before + tpl + ta.value.substring(s);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + before.length + tpl.length;
}

function lnPreviewImages(input, previewId) {
    var preview = document.getElementById(previewId || 'ln-img-preview');
    if (!preview) return;
    preview.innerHTML = '';
    Array.from(input.files).forEach(function(f) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.createElement('img');
            img.src = e.target.result;
            img.style.cssText = 'width:72px;height:72px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;';
            preview.appendChild(img);
        };
        reader.readAsDataURL(f);
    });
}

function lnLightbox(src) {
    var lb = document.getElementById('ln-lightbox');
    document.getElementById('ln-lightbox-img').src = src;
    lb.style.display = 'flex';
}
</script>