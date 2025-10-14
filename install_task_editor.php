<?php
/**
 * INSTALADOR DO MÓDULO DE EDIÇÃO DE TASKS
 * Cria as tabelas necessárias: task_checklist e task_files
 */

require_once __DIR__ . '/config.php';

$errors = [];
$success = [];

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $success[] = "✓ Conexão à base de dados estabelecida";
} catch (PDOException $e) {
    $errors[] = "❌ Erro ao conectar: " . $e->getMessage();
    die(implode('<br>', $errors));
}

// Verificar se as tabelas já existem
$forceReinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === 'true';

if ($forceReinstall) {
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS task_checklist");
        $pdo->exec("DROP TABLE IF EXISTS task_files");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $success[] = "✓ Tabelas antigas removidas (reinstalação)";
    } catch (PDOException $e) {
        $errors[] = "❌ Erro ao remover tabelas antigas: " . $e->getMessage();
    }
}

// Verificar existência das tabelas
$tables = ['task_checklist', 'task_files'];
$existingTables = [];

foreach ($tables as $table) {
    $result = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($result->rowCount() > 0) {
        $existingTables[] = $table;
    }
}

if (!empty($existingTables) && !$forceReinstall) {
    $errors[] = "⚠️ As seguintes tabelas já existem: " . implode(', ', $existingTables);
    $errors[] = "<a href='?reinstall=true' onclick='return confirm(\"ATENÇÃO: Isto irá APAGAR TODOS OS DADOS das tabelas task_checklist e task_files! Confirma?\")' style='color: #dc2626; font-weight: bold;'>Clique aqui para REINSTALAR (apaga dados existentes)</a>";
} else {
    try {
        // Criar tabela task_checklist
        $sql_checklist = "
        CREATE TABLE IF NOT EXISTS task_checklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            todo_id INT NOT NULL,
            item_text TEXT NOT NULL,
            is_checked BOOLEAN DEFAULT FALSE,
            position INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
            INDEX idx_todo (todo_id),
            INDEX idx_position (position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_checklist);
        $success[] = "✓ Tabela 'task_checklist' criada com sucesso!";
        
        // Criar tabela task_files
        $sql_files = "
        CREATE TABLE IF NOT EXISTS task_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            todo_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT DEFAULT 0,
            uploaded_by INT NOT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
            INDEX idx_todo (todo_id),
            INDEX idx_uploaded (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_files);
        $success[] = "✓ Tabela 'task_files' criada com sucesso!";
        
        // Criar diretório para ficheiros se não existir
        $files_dir = __DIR__ . '/files';
        if (!is_dir($files_dir)) {
            if (mkdir($files_dir, 0755, true)) {
                $success[] = "✓ Pasta 'files/' criada com sucesso!";
            } else {
                $errors[] = "⚠️ Não foi possível criar a pasta 'files/'. Crie manualmente com permissões 0755.";
            }
        } else {
            $success[] = "✓ Pasta 'files/' já existe";
        }
        
        // Criar .htaccess para proteger ficheiros (opcional mas recomendado)
        $htaccess_content = "# Proteger ficheiros sensíveis\n<Files ~ \"\\.(php|phtml|php3|php4|php5|phps|cgi|pl|exe)$\">\n    Order allow,deny\n    Deny from all\n</Files>\n";
        
        file_put_contents($files_dir . '/.htaccess', $htaccess_content);
        $success[] = "✓ Ficheiro .htaccess criado para segurança";
        
        $success[] = "<br><strong>🎉 INSTALAÇÃO CONCLUÍDA COM SUCESSO!</strong>";
        $success[] = "<br><strong>Próximos passos:</strong>";
        $success[] = "1. Inclua o editor em qualquer página PHP: <code>&lt;?php include 'edit_task.php'; ?&gt;</code>";
        $success[] = "2. Adicione botão para abrir: <code>&lt;button onclick=\"openTaskEditor(TASK_ID)\"&gt;Editar&lt;/button&gt;</code>";
        $success[] = "3. Teste editando uma task existente no módulo todos.php ou sprints.php";
        
    } catch (PDOException $e) {
        $errors[] = "❌ Erro ao criar tabelas: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador - Editor de Tasks</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 2rem;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        
        .message-box {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid;
        }
        
        .success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        
        .error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        
        .info {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        
        a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        a:hover {
            text-decoration: underline;
        }
        
        .feature-list {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .feature-list h3 {
            color: #667eea;
            margin-bottom: 15px;
        }
        
        .feature-list ul {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
        }
        
        .feature-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📝 Instalador - Editor de Tasks</h1>
        <p class="subtitle">Sistema universal de edição de tarefas com markdown, checklist e ficheiros</p>
        
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="message-box error">
                    <?= $error ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <?php foreach ($success as $msg): ?>
                <div class="message-box success">
                    <?= $msg ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (empty($errors) && empty($success)): ?>
            <div class="message-box info">
                ℹ️ Execute este script para instalar as tabelas necessárias para o editor de tasks.
            </div>
        <?php endif; ?>
        
        <div class="feature-list">
            <h3>🎨 Funcionalidades do Editor</h3>
            <ul>
                <li>Editor Markdown completo com barra de ferramentas</li>
                <li>Checklist de itens com possibilidade de riscar quando concluídos</li>
                <li>Upload e gestão de ficheiros anexados</li>
                <li>Interface modal sobre a página principal</li>
                <li>Edição de todos os campos (exceto ID único)</li>
                <li>Validação de permissões (apenas autor/responsável)</li>
                <li>Design moderno e responsivo</li>
                <li>Compatível com todos os módulos (todo.php, sprints.php, etc)</li>
            </ul>
        </div>
        
        <div class="feature-list">
            <h3>📋 Estrutura das Tabelas</h3>
            <ul>
                <li><strong>task_checklist:</strong> Armazena itens de checklist para cada task</li>
                <li><strong>task_files:</strong> Armazena informações dos ficheiros anexados</li>
                <li><strong>Pasta files/:</strong> Diretório onde os ficheiros são guardados</li>
            </ul>
        </div>
        
        <?php if (!empty($success) && empty($errors)): ?>
            <a href="tabs/todos.php" class="btn">🚀 Testar no Módulo ToDos</a>
        <?php endif; ?>
    </div>
</body>
</html>