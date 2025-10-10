<?php
/**
 * PIKACHUPM - PROTOTYPES MODULE INSTALLER
 * Script de instalação para o módulo de Protótipos
 * Cria todas as tabelas necessárias no banco de dados
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

try {
    // ===== VERIFICAR SE AS TABELAS JÁ EXISTEM =====
    $tables = ['prototypes', 'user_stories', 'user_story_tasks', 'prototype_participants'];
    $existingTables = [];
    
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($result->rowCount() > 0) {
            $existingTables[] = $table;
        }
    }
    
    // Se houver instalação parcial, avisar
    if (!empty($existingTables) && count($existingTables) < count($tables)) {
        $errors[] = "Instalação incompleta detectada! Tabelas existentes: " . implode(', ', $existingTables);
        $errors[] = "Recomenda-se reinstalar completamente.";
    }
    
    // Se todas as tabelas já existirem, avisar
    if (count($existingTables) === count($tables)) {
        $errors[] = "As tabelas do módulo já existem: " . implode(', ', $existingTables);
        $errors[] = "Para reinstalar, <a href='#' onclick='confirmReinstall(event)' style='color: #dc2626; font-weight: bold; text-decoration: underline;'>clique aqui para REINSTALAR</a> (irá apagar todas as tabelas e dados!)";
    }
    
    if (empty($existingTables)) {
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
        
        // ===== TABELA USER_STORY_TASKS (ligação com todos) =====
        $sql_user_story_tasks = "
        CREATE TABLE IF NOT EXISTS user_story_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            story_id INT NOT NULL,
            task_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES todos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_story_task (story_id, task_id),
            INDEX idx_story (story_id),
            INDEX idx_task (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_user_story_tasks);
        $success[] = "Tabela 'user_story_tasks' criada com sucesso!";
        
        // ===== TABELA PROTOTYPE_PARTICIPANTS (NOVA!) =====
        $sql_participants = "
        CREATE TABLE IF NOT EXISTS prototype_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prototype_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(50) DEFAULT 'member',
            is_leader BOOLEAN DEFAULT FALSE,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES user_tokens(user_id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_prototype (prototype_id, user_id),
            INDEX idx_prototype (prototype_id),
            INDEX idx_user (user_id),
            INDEX idx_leader (is_leader)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_participants);
        $success[] = "Tabela 'prototype_participants' criada com sucesso!";
        
        // ===== TRIGGERS PARA GARANTIR UM ÚNICO LÍDER =====
        // Trigger 1: Before Insert
        $sql_trigger_insert = "
        CREATE TRIGGER before_insert_participant_leader
        BEFORE INSERT ON prototype_participants
        FOR EACH ROW
        BEGIN
            IF NEW.is_leader = TRUE THEN
                UPDATE prototype_participants 
                SET is_leader = FALSE 
                WHERE prototype_id = NEW.prototype_id;
            END IF;
        END;
        ";
        
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS before_insert_participant_leader");
            $pdo->exec($sql_trigger_insert);
            $success[] = "Trigger 'before_insert_participant_leader' criado com sucesso!";
        } catch (Exception $e) {
            $errors[] = "Erro ao criar trigger de insert: " . $e->getMessage();
        }
        
        // Trigger 2: Before Update
        $sql_trigger_update = "
        CREATE TRIGGER before_update_participant_leader
        BEFORE UPDATE ON prototype_participants
        FOR EACH ROW
        BEGIN
            IF NEW.is_leader = TRUE AND OLD.is_leader = FALSE THEN
                UPDATE prototype_participants 
                SET is_leader = FALSE 
                WHERE prototype_id = NEW.prototype_id AND id != NEW.id;
            END IF;
        END;
        ";
        
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS before_update_participant_leader");
            $pdo->exec($sql_trigger_update);
            $success[] = "Trigger 'before_update_participant_leader' criado com sucesso!";
        } catch (Exception $e) {
            $errors[] = "Erro ao criar trigger de update: " . $e->getMessage();
        }
        
        // ===== DADOS DE EXEMPLO (OPCIONAL) =====
        // Descomente para adicionar dados de exemplo
        /*
        $stmt = $pdo->prepare("
            INSERT INTO prototypes (short_name, title, vision, target_group, needs, product_description, business_goals, sentence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'PikachuPM',
            'Sistema de Gestão de Projetos',
            'Criar uma plataforma intuitiva de gestão de projetos',
            'Equipas de desenvolvimento e gestores de projeto',
            'Organização de tarefas, colaboração em tempo real, tracking de progresso',
            'Sistema web para gestão ágil de projetos',
            'Aumentar produtividade das equipas em 40%',
            'Para equipas de desenvolvimento que precisam de organizar projetos, PikachuPM é uma plataforma de gestão que oferece colaboração em tempo real'
        ]);
        $success[] = "Dados de exemplo inseridos com sucesso!";
        */
    }
    
} catch (PDOException $e) {
    $errors[] = "Erro de base de dados: " . $e->getMessage();
} catch (Exception $e) {
    $errors[] = "Erro geral: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PikachuPM - Instalação do Módulo de Protótipos</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
            padding: 40px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .status-icon {
            font-size: 80px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        h1 {
            text-align: center;
            color: #1a202c;
            margin-bottom: 10px;
            font-size: 32px;
        }
        
        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 30px;
            font-size: 16px;
        }
        
        .success, .warning, .error {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .success {
            background: #d1fae5;
            border: 2px solid #34d399;
            color: #065f46;
        }
        
        .warning {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            color: #78350f;
        }
        
        .error {
            background: #fee2e2;
            border: 2px solid #f87171;
            color: #7f1d1d;
        }
        
        .success h3, .warning h3, .error h3 {
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        ul {
            margin-left: 20px;
        }
        
        li {
            margin: 8px 0;
            line-height: 1.6;
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            margin-top: 30px;
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .btn-danger:hover {
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4);
        }
        
        .info-box {
            background: #e0e7ff;
            border: 2px solid #818cf8;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .info-box h4 {
            color: #3730a3;
            margin-bottom: 10px;
        }
        
        .info-box p {
            color: #4338ca;
            line-height: 1.6;
        }
        
        code {
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            color: #dc2626;
        }
        
        .feature-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .feature-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .feature-item strong {
            color: #3b82f6;
            display: block;
            margin-bottom: 5px;
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
                <h3>✓ Tabelas criadas com sucesso:</h3>
                <ul>
                    <?php foreach ($success as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="info-box">
                <h4>🎉 Novas Funcionalidades Disponíveis:</h4>
                <div class="feature-list">
                    <div class="feature-item">
                        <strong>📋 Protótipos</strong>
                        Gestão completa de protótipos de produto
                    </div>
                    <div class="feature-item">
                        <strong>📝 User Stories</strong>
                        Método MoSCoW integrado
                    </div>
                    <div class="feature-item">
                        <strong>👥 Participantes</strong>
                        Gestão de equipa com líder
                    </div>
                    <div class="feature-item">
                        <strong>🔗 Links Clicáveis</strong>
                        URLs automáticos em repositórios
                    </div>
                    <div class="feature-item">
                        <strong>✅ Tarefas</strong>
                        Integração com todos
                    </div>
                    <div class="feature-item">
                        <strong>📥 Export</strong>
                        Documentação em Markdown
                    </div>
                </div>
            </div>
            
            <div class="info-box">
                <h4>📖 Próximos Passos:</h4>
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
                        <li><?php echo $error; // Já contém HTML do link ?></li>
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
            
            <div class="info-box" style="background: #fef3c7; border-color: #fcd34d;">
                <h4 style="color: #92400e;">⚠️ Atenção - Reinstalação</h4>
                <p style="color: #78350f;">
                    <strong>Ao reinstalar, TODOS os dados serão perdidos:</strong><br>
                    • Todos os protótipos criados<br>
                    • Todas as user stories<br>
                    • Todos os participantes<br>
                    • Todas as ligações com todos<br><br>
                    <strong>Esta ação é IRREVERSÍVEL!</strong><br>
                    Certifique-se de fazer backup antes de prosseguir.
                </p>
            </div>
            
            <a href="tabs/prototypes/prototypesv2.php" class="btn">📋 Abrir Módulo de Protótipos</a>
            
            <form method="POST" action="?reinstall=1" onsubmit="return confirm('⚠️ ATENÇÃO!\n\nTem CERTEZA ABSOLUTA que deseja APAGAR TODOS OS DADOS?\n\nEsta ação é IRREVERSÍVEL!\n\nClique OK para continuar com a reinstalação.');" style="margin-top: 20px;">
                <button type="submit" class="btn btn-danger">🗑️ REINSTALAR (Apagar Tudo)</button>
            </form>
            
        <?php else: ?>
            <div class="status-icon">❌</div>
            <h1>Erro na Instalação</h1>
            <p class="subtitle">Ocorreram erros durante a instalação</p>
            
            <div class="error">
                <h3>✗ Erros encontrados:</h3>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
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
                <h4>🔧 Sugestões:</h4>
                <p>
                    • Verifique as permissões da base de dados<br>
                    • Confirme que o ficheiro <code>db.php</code> está configurado corretamente<br>
                    • Verifique os logs de erro do PHP/MySQL<br>
                    • Entre em contacto com o suporte técnico se o problema persistir
                </p>
            </div>
            
            <a href="?" class="btn">🔄 Tentar Novamente</a>
        <?php endif; ?>
    </div>
    
    <script>
        function confirmReinstall(event) {
            event.preventDefault();
            if (confirm('⚠️ ATENÇÃO!\n\nTem CERTEZA ABSOLUTA que deseja APAGAR TODOS OS DADOS?\n\n• Todos os protótipos\n• Todas as user stories\n• Todos os participantes\n• Todas as ligações\n\nEsta ação é IRREVERSÍVEL!\n\nClique OK para ver o botão de reinstalação.')) {
                window.scrollTo(0, document.body.scrollHeight);
                alert('Role a página até ao final para ver o botão de reinstalação.');
            }
        }
    </script>
</body>
</html>

<?php
// ===== REINSTALAÇÃO =====
if (isset($_GET['reinstall']) && $_GET['reinstall'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Apagar triggers primeiro
        $pdo->exec("DROP TRIGGER IF EXISTS before_insert_participant_leader");
        $pdo->exec("DROP TRIGGER IF EXISTS before_update_participant_leader");
        
        // Apagar tabelas na ordem correta (devido às foreign keys)
        $pdo->exec("DROP TABLE IF EXISTS user_story_tasks");
        $pdo->exec("DROP TABLE IF EXISTS prototype_participants");
        $pdo->exec("DROP TABLE IF EXISTS user_stories");
        $pdo->exec("DROP TABLE IF EXISTS prototypes");
        
        // Redirecionar para reinstalar
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        die("Erro ao reinstalar: " . $e->getMessage());
    }
}
?>