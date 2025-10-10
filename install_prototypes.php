<?php
/**
 * Script de instalação automática do módulo de Protótipos
 * Execute este ficheiro apenas uma vez para criar as tabelas necessárias
 */

// Incluir configuração do projeto
include_once __DIR__ . '/config.php';

$errors = [];
$success = [];

// Criar conexão PDO usando as variáveis do config.php
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $success[] = "Conexão à base de dados estabelecida com sucesso!";
} catch (PDOException $e) {
    $errors[] = "Erro ao conectar à base de dados: " . $e->getMessage();
    $errors[] = "Verifique as configurações em config.php (db_host: $db_host, db_name: $db_name, db_user: $db_user)";
}

// Só continuar se conseguiu conectar
if (empty($errors)) {
    try {
        // Verificar se as tabelas já existem
        $tables = ['prototypes', 'user_stories', 'user_story_tasks'];
        $existingTables = [];
        
        foreach ($tables as $table) {
            $result = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($result->rowCount() > 0) {
                $existingTables[] = $table;
            }
        }
        
        if (!empty($existingTables)) {
            $errors[] = "⚠️ Aviso: As seguintes tabelas já existem: " . implode(', ', $existingTables);
            $errors[] = "Se pretende reinstalar, elimine estas tabelas manualmente primeiro.";
        }
        
        // ===== TABELA PROTOTYPES =====
        $sql_prototypes = "
        CREATE TABLE IF NOT EXISTS prototypes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_prototypes);
        $success[] = "Tabela 'prototypes' criada com sucesso!";
        
        // ===== TABELA USER_STORIES =====
        $sql_user_stories = "
        CREATE TABLE IF NOT EXISTS user_stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prototype_id INT NOT NULL,
            story_text TEXT NOT NULL,
            moscow_priority ENUM('Must', 'Should', 'Could', 'Won''t') NOT NULL DEFAULT 'Should',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
            INDEX idx_prototype (prototype_id),
            INDEX idx_priority (moscow_priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_user_stories);
        $success[] = "Tabela 'user_stories' criada com sucesso!";
        
        // ===== TABELA USER_STORY_TASKS =====
        // Verificar se a tabela tasks existe antes de criar a constraint
        $result = $pdo->query("SHOW TABLES LIKE 'tasks'");
        
        if ($result->rowCount() > 0) {
            // Adicionar foreign key se a tabela tasks existe
            $sql_user_story_tasks = "
            CREATE TABLE IF NOT EXISTS user_story_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                story_id INT NOT NULL,
                task_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                UNIQUE KEY unique_story_task (story_id, task_id),
                INDEX idx_story (story_id),
                INDEX idx_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $success[] = "Tabela 'user_story_tasks' criada com Foreign Key para 'tasks'!";
        } else {
            // Criar sem foreign key para tasks
            $sql_user_story_tasks = "
            CREATE TABLE IF NOT EXISTS user_story_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                story_id INT NOT NULL,
                task_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
                INDEX idx_story (story_id),
                INDEX idx_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $errors[] = "⚠️ Aviso: Tabela 'tasks' não encontrada. A tabela 'user_story_tasks' foi criada sem a Foreign Key para tasks.";
        }
        
        $pdo->exec($sql_user_story_tasks);
        $success[] = "Tabela 'user_story_tasks' criada com sucesso!";
        
        // ===== INSERIR DADOS DE EXEMPLO (OPCIONAL) =====
        $insertExamples = false; // Altere para true se quiser dados de exemplo
        
        if ($insertExamples) {
            $stmt = $pdo->prepare("
                INSERT INTO prototypes (short_name, title, vision, target_group, needs, product_description, business_goals, sentence)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                'TaskManager',
                'Task Management System',
                'Simplificar a gestão de tarefas para equipas ágeis',
                'Equipas de desenvolvimento e gestores de projeto',
                'Necessidade de organizar tarefas, priorizar trabalho e acompanhar progresso',
                'Sistema web de gestão de tarefas com metodologias ágeis integradas',
                'Aumentar produtividade em 30%, reduzir tempo de planeamento',
                'For development teams, Who need to organize their work efficiently, The TaskManager Is a web application That provides agile task management. Unlike traditional tools, Our product integrates seamlessly with development workflows.'
            ]);
            
            $prototypeId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO user_stories (prototype_id, story_text, moscow_priority)
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([
                $prototypeId,
                'As a project manager, I want to create and assign tasks to team members, so that I can distribute work effectively.',
                'Must'
            ]);
            
            $stmt->execute([
                $prototypeId,
                'As a developer, I want to see my assigned tasks in a kanban board, so that I can visualize my workflow.',
                'Should'
            ]);
            
            $success[] = "Dados de exemplo inseridos com sucesso!";
        }
        
    } catch (PDOException $e) {
        $errors[] = "Erro na base de dados: " . $e->getMessage();
    } catch (Exception $e) {
        $errors[] = "Erro: " . $e->getMessage();
    }
}

// ===== EXIBIR RESULTADOS =====
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Módulo Protótipos</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #1a202c;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #64748b;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .success, .error {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        
        .error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        .success h3, .error h3, .warning h3 {
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        ul {
            margin-left: 20px;
        }
        
        li {
            margin: 5px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            margin-top: 20px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #2563eb;
        }
        
        .status-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .info-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .info-box h4 {
            color: #0369a1;
            margin-bottom: 10px;
        }
        
        .info-box p {
            color: #075985;
            font-size: 14px;
            line-height: 1.6;
        }
        
        code {
            background: #1e293b;
            color: #22d3ee;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (empty($errors) && !empty($success)): ?>
            <div class="status-icon">✅</div>
            <h1>Instalação Concluída!</h1>
            <p class="subtitle">O módulo de Protótipos foi instalado com sucesso</p>
            
            <div class="success">
                <h3>✓ Operações realizadas:</h3>
                <ul>
                    <?php foreach ($success as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="info-box">
                <h4>🚀 Próximos Passos:</h4>
                <p>
                    1. Aceda ao módulo através de <code>tabs/prototypes/prototypesv2.php</code><br>
                    2. Para integrar no menu do sistema, consulte o guia de integração<br>
                    3. <strong>Elimine este ficheiro</strong> <code>install_prototypes.php</code> por segurança
                </p>
            </div>
            
            <a href="tabs/prototypes/prototypesv2.php" class="btn">📋 Abrir Módulo de Protótipos</a>
            
        <?php elseif (!empty($errors) && str_contains(implode('', $errors), 'já existem')): ?>
            <div class="status-icon">⚠️</div>
            <h1>Tabelas Já Existem</h1>
            <p class="subtitle">O módulo já foi instalado anteriormente</p>
            
            <div class="warning">
                <h3>⚠ Avisos:</h3>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <?php if (!empty($success)): ?>
                <div class="success">
                    <h3>✓ Verificações bem-sucedidas:</h3>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h4>💡 O que fazer:</h4>
                <p>
                    Se pretende reinstalar:<br>
                    1. Aceda ao phpMyAdmin ou MySQL<br>
                    2. Execute: <code>DROP TABLE user_story_tasks, user_stories, prototypes;</code><br>
                    3. Execute novamente este instalador
                </p>
            </div>
            
            <a href="tabs/prototypes/prototypesv2.php" class="btn">📋 Ir para Protótipos</a>
            
        <?php else: ?>
            <div class="status-icon">❌</div>
            <h1>Erro na Instalação</h1>
            <p class="subtitle">Alguns erros foram encontrados</p>
            
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <h3>✗ Erros encontrados:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="success">
                    <h3>✓ Operações bem-sucedidas:</h3>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h4>💡 Sugestões:</h4>
                <p>
                    - Verifique se o ficheiro <code>config.php</code> existe na raiz<br>
                    - Confirme as permissões da base de dados<br>
                    - Verifique se as variáveis $db_host, $db_name, $db_user, $db_pass estão corretas<br>
                    - Consulte os logs de erro do servidor
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>