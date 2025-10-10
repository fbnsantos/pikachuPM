<?php
// tabs/prototypes.php - Gestão de Protótipos (integrado como todos.php)

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Criar tabelas se não existirem
    $db->query('CREATE TABLE IF NOT EXISTS prototypes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        short_name VARCHAR(100) NOT NULL,
        title VARCHAR(255) NOT NULL,
        vision TEXT,
        target_group TEXT,
        needs TEXT,
        product_description TEXT,
        business_goals TEXT,
        sentence TEXT,
        repo_links TEXT,
        documentation_links TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_short_name (short_name),
        INDEX idx_title (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    
    $db->query('CREATE TABLE IF NOT EXISTS user_stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prototype_id INT NOT NULL,
        story_text TEXT NOT NULL,
        moscow_priority ENUM("Must", "Should", "Could", "Won\'t") NOT NULL DEFAULT "Should",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
        INDEX idx_prototype (prototype_id),
        INDEX idx_priority (moscow_priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    
    // Verificar se tabela todos existe antes de criar FK
    $todosExists = $db->query("SHOW TABLES LIKE 'todos'")->num_rows > 0;
    
    if ($todosExists) {
        // Verificar se tabela user_story_tasks já existe
        $tableExists = $db->query("SHOW TABLES LIKE 'user_story_tasks'")->num_rows > 0;
        
        if (!$tableExists) {
            $db->query('CREATE TABLE IF NOT EXISTS user_story_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                story_id INT NOT NULL,
                task_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES todos(id) ON DELETE CASCADE,
                UNIQUE KEY unique_story_task (story_id, task_id),
                INDEX idx_story (story_id),
                INDEX idx_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    } else {
        // Criar sem FK para todos
        $db->query('CREATE TABLE IF NOT EXISTS user_story_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            task_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            INDEX idx_story (story_id),
            INDEX idx_task (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    
} catch (Exception $e) {
    die("Erro ao conectar à base de dados: " . $e->getMessage());
}

// Obter user_id da sessão
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    try {
        switch($action) {
            case 'get_prototypes':
                $search = $_POST['search'] ?? '';
                $sql = "SELECT * FROM prototypes";
                if ($search) {
                    $sql .= " WHERE short_name LIKE ? OR title LIKE ?";
                    $stmt = $db->prepare($sql);
                    $searchParam = "%$search%";
                    $stmt->bind_param('ss', $searchParam, $searchParam);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } else {
                    $result = $db->query($sql);
                }
                $prototypes = $result->fetch_all(MYSQLI_ASSOC);
                echo json_encode(['success' => true, 'data' => $prototypes]);
                exit;
                
            case 'get_prototype':
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT * FROM prototypes WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $prototype = $stmt->get_result()->fetch_assoc();
                echo json_encode(['success' => true, 'data' => $prototype]);
                exit;
                
            case 'create_prototype':
                $short_name = $_POST['short_name'] ?? '';
                $title = $_POST['title'] ?? '';
                
                $stmt = $db->prepare("
                    INSERT INTO prototypes (short_name, title, vision, target_group, needs, 
                                           product_description, business_goals, sentence, 
                                           repo_links, documentation_links)
                    VALUES (?, ?, '', '', '', '', '', '', '', '')
                ");
                $stmt->bind_param('ss', $short_name, $title);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'id' => $db->insert_id]);
                exit;
                
            case 'update_prototype':
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("
                    UPDATE prototypes 
                    SET short_name=?, title=?, vision=?, target_group=?, needs=?,
                        product_description=?, business_goals=?, sentence=?,
                        repo_links=?, documentation_links=?
                    WHERE id=?
                ");
                $stmt->bind_param('ssssssssssi',
                    $_POST['short_name'], $_POST['title'], $_POST['vision'],
                    $_POST['target_group'], $_POST['needs'], $_POST['product_description'],
                    $_POST['business_goals'], $_POST['sentence'], $_POST['repo_links'],
                    $_POST['documentation_links'], $id
                );
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete_prototype':
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("DELETE FROM prototypes WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'get_stories':
                $prototype_id = (int)$_POST['prototype_id'];
                $priority = $_POST['priority'] ?? '';
                
                $sql = "SELECT * FROM user_stories WHERE prototype_id = ?";
                if ($priority) {
                    $sql .= " AND moscow_priority = ?";
                }
                $sql .= " ORDER BY FIELD(moscow_priority, 'Must', 'Should', 'Could', 'Won\\'t')";
                
                $stmt = $db->prepare($sql);
                if ($priority) {
                    $stmt->bind_param('is', $prototype_id, $priority);
                } else {
                    $stmt->bind_param('i', $prototype_id);
                }
                $stmt->execute();
                $stories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $stories]);
                exit;
                
            case 'create_story':
                $prototype_id = (int)$_POST['prototype_id'];
                $story_text = $_POST['story_text'] ?? '';
                $priority = $_POST['moscow_priority'] ?? 'Should';
                
                $stmt = $db->prepare("
                    INSERT INTO user_stories (prototype_id, story_text, moscow_priority)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param('iss', $prototype_id, $story_text, $priority);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'id' => $db->insert_id]);
                exit;
                
            case 'update_story':
                $id = (int)$_POST['id'];
                $story_text = $_POST['story_text'] ?? '';
                $priority = $_POST['moscow_priority'] ?? 'Should';
                
                $stmt = $db->prepare("
                    UPDATE user_stories 
                    SET story_text=?, moscow_priority=?
                    WHERE id=?
                ");
                $stmt->bind_param('ssi', $story_text, $priority, $id);
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete_story':
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("DELETE FROM user_stories WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'create_task_from_story':
                $story_id = (int)$_POST['story_id'];
                $title = $_POST['title'] ?? '';
                $description = $_POST['description'] ?? '';
                
                // Verificar/criar token do usuário
                $stmt = $db->prepare("SELECT token FROM user_tokens WHERE user_id = ?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $token = bin2hex(random_bytes(16));
                    $stmt = $db->prepare("INSERT INTO user_tokens (user_id, username, token) VALUES (?, ?, ?)");
                    $stmt->bind_param('iss', $user_id, $username, $token);
                    $stmt->execute();
                }
                
                // Criar tarefa
                $stmt = $db->prepare("
                    INSERT INTO todos (titulo, descritivo, estado, autor, created_at)
                    VALUES (?, ?, 'aberta', ?, NOW())
                ");
                $stmt->bind_param('ssi', $title, $description, $user_id);
                $stmt->execute();
                $task_id = $db->insert_id;
                
                // Associar à story
                $stmt = $db->prepare("
                    INSERT INTO user_story_tasks (story_id, task_id)
                    VALUES (?, ?)
                ");
                $stmt->bind_param('ii', $story_id, $task_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'task_id' => $task_id]);
                exit;
                
            case 'get_story_tasks':
                $story_id = (int)$_POST['story_id'];
                $stmt = $db->prepare("
                    SELECT t.*, ust.id as link_id 
                    FROM todos t
                    JOIN user_story_tasks ust ON t.id = ust.task_id
                    WHERE ust.story_id = ?
                ");
                $stmt->bind_param('i', $story_id);
                $stmt->execute();
                $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $tasks]);
                exit;
                
            case 'unlink_task':
                $link_id = (int)$_POST['link_id'];
                $stmt = $db->prepare("DELETE FROM user_story_tasks WHERE id = ?");
                $stmt->bind_param('i', $link_id);
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$db->close();
?>

<!-- Incluir o HTML do prototypesv2.php -->
<?php include __DIR__ . '/prototypes/prototypesv2.php'; ?>

<script>
// Sobrescrever o API_PATH para usar AJAX no mesmo ficheiro
window.PROTOTYPES_API_PATH = '?tab=prototypes';

// Adaptar todas as chamadas fetch para usar POST com ajax_action
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
    // Se for uma chamada à API de prototypes
    if (url.includes('tab=prototypes') || url.includes('prototypes_api')) {
        // Extrair action do URL
        const urlObj = new URL(url, window.location.origin);
        const action = urlObj.searchParams.get('action');
        
        if (action) {
            // Converter para POST com FormData
            const formData = new FormData();
            formData.append('ajax_action', action);
            
            // Se for POST com JSON, converter para FormData
            if (options.method === 'POST' && options.body) {
                try {
                    const jsonData = JSON.parse(options.body);
                    Object.keys(jsonData).forEach(key => {
                        formData.append(key, jsonData[key]);
                    });
                } catch(e) {
                    // Não é JSON, ignorar
                }
            }
            
            // Adicionar parâmetros do URL ao FormData
            for (const [key, value] of urlObj.searchParams.entries()) {
                if (key !== 'action' && key !== 'tab') {
                    formData.append(key, value);
                }
            }
            
            // Fazer requisição POST para o próprio ficheiro
            return originalFetch('?tab=prototypes', {
                method: 'POST',
                body: formData
            }).then(response => {
                return response.json().then(data => {
                    // Adaptar resposta para o formato esperado
                    if (data.success && data.data !== undefined) {
                        return new Response(JSON.stringify(data.data), {
                            status: 200,
                            headers: {'Content-Type': 'application/json'}
                        });
                    } else if (data.success) {
                        return new Response(JSON.stringify(data), {
                            status: 200,
                            headers: {'Content-Type': 'application/json'}
                        });
                    } else {
                        return new Response(JSON.stringify({error: data.error}), {
                            status: 500,
                            headers: {'Content-Type': 'application/json'}
                        });
                    }
                });
            });
        }
    }
    
    // Chamada normal
    return originalFetch(url, options);
};

console.log('Prototypes integrated mode - using same session as todos.php');
</script>