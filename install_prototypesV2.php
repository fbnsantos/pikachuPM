<?php
/**
 * PIKACHUPM - PROTOTYPES MODULE INSTALLER/UPDATER
 * Script de instalação e atualização para o módulo de Protótipos
 * Cria tabelas e adiciona colunas novas SEM apagar dados existentes
 */

// Incluir configuração do projeto
include_once __DIR__ . '/config.php';

$errors = [];
$success = [];
$updates = [];

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
    $success[] = "✓ Conexão à base de dados estabelecida com sucesso!";
} catch (PDOException $e) {
    $errors[] = "Erro ao conectar à base de dados: " . $e->getMessage();
    $errors[] = "Verifique as configurações em config.php (db_host: $db_host, db_name: $db_name, db_user: $db_user)";
}

// Função auxiliar para verificar se coluna existe
function columnExists($pdo, $table, $column) {
    try {
        $result = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Função auxiliar para verificar se tabela existe
function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '$table'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Verificar modo de operação
$forceReinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === 'true';

if ($forceReinstall) {
    // MODO REINSTALAÇÃO COMPLETA (apaga tudo)
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $tables = ['user_story_tasks', 'prototype_participants', 'user_stories', 'prototypes'];
        foreach ($tables as $table) {
            if (tableExists($pdo, $table)) {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
                $success[] = "✓ Tabela '$table' removida";
            }
        }
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $success[] = "✓ Todas as tabelas removidas. Prosseguindo com instalação limpa...";
        
    } catch (PDOException $e) {
        $errors[] = "Erro ao remover tabelas: " . $e->getMessage();
    }
}

try {
    // ===== CRIAR/ATUALIZAR TABELA PROTOTYPES =====
    if (!tableExists($pdo, 'prototypes')) {
        // Criar tabela nova com TODAS as colunas (incluindo responsible e participants)
        $sql_prototypes = "
        CREATE TABLE prototypes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            identifier VARCHAR(100),
            description TEXT,
            short_name VARCHAR(100),
            title VARCHAR(255),
            vision TEXT,
            target_group TEXT,
            needs TEXT,
            product_description TEXT,
            business_goals TEXT,
            sentence TEXT,
            repo_links TEXT,
            documentation_links TEXT,
            responsible VARCHAR(255) DEFAULT NULL COMMENT 'Nome do responsável pelo protótipo',
            participants TEXT DEFAULT NULL COMMENT 'Array JSON com lista de participantes',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_identifier (identifier),
            INDEX idx_responsible (responsible)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_prototypes);
        $success[] = "✓ Tabela 'prototypes' criada com sucesso (com campos responsible e participants)!";
        
    } else {
        // Tabela existe - fazer UPDATE das colunas
        $updates[] = "→ Tabela 'prototypes' já existe. Verificando colunas...";
        
        // Adicionar coluna 'name' se não existir (compatibilidade)
        if (!columnExists($pdo, 'prototypes', 'name')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN name VARCHAR(255) NOT NULL AFTER id");
            $updates[] = "✓ Coluna 'name' adicionada";
        }
        
        // Adicionar coluna 'identifier' se não existir
        if (!columnExists($pdo, 'prototypes', 'identifier')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN identifier VARCHAR(100) AFTER name");
            $updates[] = "✓ Coluna 'identifier' adicionada";
        }
        
        // Adicionar coluna 'description' se não existir
        if (!columnExists($pdo, 'prototypes', 'description')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN description TEXT AFTER identifier");
            $updates[] = "✓ Coluna 'description' adicionada";
        }
        
        // ⭐ ADICIONAR COLUNA 'responsible' (NOVA!)
        if (!columnExists($pdo, 'prototypes', 'responsible')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN responsible VARCHAR(255) DEFAULT NULL COMMENT 'Nome do responsável pelo protótipo'");
            $updates[] = "✓ Coluna 'responsible' adicionada (NOVA FUNCIONALIDADE!)";
            
            // Criar índice para buscas
            $pdo->exec("CREATE INDEX idx_responsible ON prototypes(responsible)");
            $updates[] = "✓ Índice 'idx_responsible' criado";
        } else {
            $updates[] = "→ Coluna 'responsible' já existe";
        }
        
        // ⭐ ADICIONAR COLUNA 'participants' (NOVA!)
        if (!columnExists($pdo, 'prototypes', 'participants')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN participants TEXT DEFAULT NULL COMMENT 'Array JSON com lista de participantes'");
            $updates[] = "✓ Coluna 'participants' adicionada (NOVA FUNCIONALIDADE!)";
        } else {
            $updates[] = "→ Coluna 'participants' já existe";
        }
        
        // Adicionar timestamps se não existirem
        if (!columnExists($pdo, 'prototypes', 'created_at')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            $updates[] = "✓ Coluna 'created_at' adicionada";
        }
        
        if (!columnExists($pdo, 'prototypes', 'updated_at')) {
            $pdo->exec("ALTER TABLE prototypes ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            $updates[] = "✓ Coluna 'updated_at' adicionada";
        }
        
        $success[] = "✓ Tabela 'prototypes' atualizada com sucesso!";
    }
    
    // ===== CRIAR/ATUALIZAR TABELA USER_STORIES =====
    if (!tableExists($pdo, 'user_stories')) {
        $sql_user_stories = "
        CREATE TABLE user_stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prototype_id INT NOT NULL,
            story_text TEXT NOT NULL,
            moscow_priority ENUM('Must', 'Should', 'Could', 'Won''t') NOT NULL DEFAULT 'Should',
            priority VARCHAR(50) DEFAULT 'Should',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
            INDEX idx_prototype (prototype_id),
            INDEX idx_priority (moscow_priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql_user_stories);
        $success[] = "✓ Tabela 'user_stories' criada com sucesso!";
    } else {
        $updates[] = "→ Tabela 'user_stories' já existe";
        
        // Adicionar coluna 'priority' se não existir (compatibilidade com versões antigas)
        if (!columnExists($pdo, 'user_stories', 'priority')) {
            $pdo->exec("ALTER TABLE user_stories ADD COLUMN priority VARCHAR(50) DEFAULT 'Should'");
            $updates[] = "✓ Coluna 'priority' adicionada à tabela 'user_stories'";
        }
    }
    
    // ===== CRIAR TABELA USER_STORY_TASKS (ligação com todos) =====
    if (!tableExists($pdo, 'user_story_tasks')) {
        // Verificar se tabela 'todos' existe antes de criar FK
        $hasTodosTable = tableExists($pdo, 'todos');
        
        if ($hasTodosTable) {
            $sql_user_story_tasks = "
            CREATE TABLE user_story_tasks (
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
        } else {
            $sql_user_story_tasks = "
            CREATE TABLE user_story_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                story_id INT NOT NULL,
                task_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (story_id) REFERENCES user_stories(id) ON DELETE CASCADE,
                UNIQUE KEY unique_story_task (story_id, task_id),
                INDEX idx_story (story_id),
                INDEX idx_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $updates[] = "⚠️ Tabela 'todos' não encontrada - FK para tasks não criada";
        }
        
        $pdo->exec($sql_user_story_tasks);
        $success[] = "✓ Tabela 'user_story_tasks' criada com sucesso!";
    } else {
        $updates[] = "→ Tabela 'user_story_tasks' já existe";
    }
    
    // ===== CRIAR TABELA PROTOTYPE_PARTICIPANTS =====
    if (!tableExists($pdo, 'prototype_participants')) {
        // Verificar se tabela 'user_tokens' existe (para FK)
        $hasUserTokens = tableExists($pdo, 'user_tokens');
        
        if ($hasUserTokens) {
            $sql_participants = "
            CREATE TABLE prototype_participants (
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
        } else {
            $sql_participants = "
            CREATE TABLE prototype_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prototype_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) DEFAULT 'member',
                is_leader BOOLEAN DEFAULT FALSE,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
                UNIQUE KEY unique_user_prototype (prototype_id, user_id),
                INDEX idx_prototype (prototype_id),
                INDEX idx_user (user_id),
                INDEX idx_leader (is_leader)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $updates[] = "⚠️ Tabela 'user_tokens' não encontrada - FK para users não criada";
        }
        
        $pdo->exec($sql_participants);
        $success[] = "✓ Tabela 'prototype_participants' criada com sucesso!";
        
        // Criar triggers para garantir um único líder
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS before_insert_participant_leader");
            $pdo->exec("DROP TRIGGER IF EXISTS before_update_participant_leader");
            
            $pdo->exec("
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
            ");
            
            $pdo->exec("
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
            ");
            
            $success[] = "✓ Triggers para líder único criados com sucesso!";
        } catch (PDOException $e) {
            $updates[] = "⚠️ Aviso: Não foi possível criar triggers (podem já existir)";
        }
        
    } else {
        $updates[] = "→ Tabela 'prototype_participants' já existe";
    }
    
    // ===== VERIFICAÇÃO FINAL =====
    $allTables = ['prototypes', 'user_stories', 'user_story_tasks', 'prototype_participants'];
    $allExist = true;
    foreach ($allTables as $table) {
        if (!tableExists($pdo, $table)) {
            $allExist = false;
            $errors[] = "Tabela '$table' não foi criada corretamente";
        }
    }
    
    if ($allExist) {
        $success[] = "✅ Todas as tabelas verificadas e operacionais!";
    }
    
} catch (PDOException $e) {
    $errors[] = "Erro durante instalação/atualização: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prototypes Module - Installer/Updater</title>
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
            border-radius: 16px;
            padding: 40px;
            max-width: 800px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .status-icon {
            font-size: 64px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        h1 {
            font-size: 32px;
            text-align: center;
            color: #1a202c;
            margin-bottom: 10px;
        }
        
        .subtitle {
            text-align: center;
            color: #64748b;
            font-size: 16px;
            margin-bottom: 30px;
        }
        
        .success, .error, .update, .info-box {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #d1fae5;
            border: 2px solid #10b981;
        }
        
        .error {
            background: #fee2e2;
            border: 2px solid #ef4444;
        }
        
        .update {
            background: #dbeafe;
            border: 2px solid #3b82f6;
        }
        
        .info-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
        }
        
        h3 {
            font-size: 18px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        ul {
            list-style: none;
            padding: 0;
        }
        
        li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        li:last-child {
            border-bottom: none;
        }
        
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        
        .btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-danger {
            background: #ef4444;
            margin-left: 10px;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .action-bar {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        a {
            color: #3b82f6;
            text-decoration: none;
        }
        
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (empty($errors) && !empty($success)): ?>
            <div class="status-icon">✅</div>
            <h1><?php echo $forceReinstall ? 'Reinstalação' : 'Instalação/Atualização'; ?> Concluída!</h1>
            <p class="subtitle">O módulo de protótipos está pronto para uso</p>
            
            <div class="success">
                <h3>✓ Operações bem-sucedidas:</h3>
                <ul>
                    <?php foreach ($success as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <?php if (!empty($updates)): ?>
                <div class="update">
                    <h3>🔄 Atualizações realizadas:</h3>
                    <ul>
                        <?php foreach ($updates as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h4>🎉 Novas Funcionalidades Disponíveis:</h4>
                <ul>
                    <li>✅ Campo <strong>Responsável</strong> - Defina quem é o responsável por cada protótipo</li>
                    <li>✅ Campo <strong>Participantes</strong> - Liste todos os participantes do protótipo</li>
                    <li>✅ Filtro <strong>"Apenas meus protótipos"</strong> - Veja apenas protótipos onde você é responsável</li>
                    <li>✅ Ordenação <strong>alfabética</strong> - Organize protótipos de A-Z</li>
                </ul>
            </div>
            
            <div class="action-bar">
                <a href="tabs/prototypes/prototypesv2.php" class="btn">📋 Abrir Módulo de Protótipos</a>
                <?php if (!$forceReinstall): ?>
                    <a href="?reinstall=true" class="btn btn-danger" 
                       onclick="return confirm('⚠️ ATENÇÃO!\n\nIsto irá APAGAR TODOS OS DADOS!\n\nTem certeza?')">
                        🗑️ Reinstalar (Apagar Tudo)
                    </a>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <div class="status-icon">⚠️</div>
            <h1>Problemas Encontrados</h1>
            <p class="subtitle">Verifique os erros abaixo</p>
            
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <h3>✗ Erros:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="success">
                    <h3>✓ Operações parcialmente bem-sucedidas:</h3>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($updates)): ?>
                <div class="update">
                    <h3>🔄 Atualizações realizadas:</h3>
                    <ul>
                        <?php foreach ($updates as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="action-bar">
                <a href="javascript:location.reload()" class="btn">🔄 Tentar Novamente</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>