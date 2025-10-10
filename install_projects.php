<?php
/**
 * Script de instalação do módulo de Projetos
 * Execute este ficheiro UMA VEZ para criar as tabelas necessárias
 */

include_once __DIR__ . '/config.php';

$errors = [];
$success = [];

// Conectar à base de dados
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
    $errors[] = "Erro ao conectar: " . $e->getMessage();
}

if (empty($errors)) {
    try {
        // Opção de reinstalação
        $forceReinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === 'true';
        
        if ($forceReinstall) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("DROP TABLE IF EXISTS deliverable_tasks");
            $pdo->exec("DROP TABLE IF EXISTS project_prototypes");
            $pdo->exec("DROP TABLE IF EXISTS project_deliverables");
            $pdo->exec("DROP TABLE IF EXISTS project_members");
            $pdo->exec("DROP TABLE IF EXISTS project_links");
            $pdo->exec("DROP TABLE IF EXISTS projects");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $success[] = "✓ Tabelas antigas removidas";
        }
        
        // Verificar se já existem
        $tables = ['projects', 'project_links', 'project_members', 'project_deliverables', 'deliverable_tasks', 'project_prototypes'];
        $existingTables = [];
        
        foreach ($tables as $table) {
            $result = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($result->rowCount() > 0) {
                $existingTables[] = $table;
            }
        }
        
        if (!empty($existingTables) && !$forceReinstall) {
            $errors[] = "⚠️ Tabelas já existem: " . implode(', ', $existingTables);
            $errors[] = "<a href='?reinstall=true' onclick='return confirm(\"ATENÇÃO: Isto irá APAGAR TODOS OS DADOS! Confirma?\")' style='color: #dc2626; font-weight: bold;'>Clique aqui para REINSTALAR</a>";
        } else {
            // Criar tabelas
            
            // 1. Tabela principal de projetos
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                short_name VARCHAR(50) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                owner_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_owner (owner_id),
                INDEX idx_short_name (short_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $success[] = "✓ Tabela 'projects' criada";
            
            // 2. Links/recursos do projeto
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                url TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                INDEX idx_project (project_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $success[] = "✓ Tabela 'project_links' criada";
            
            // 3. Membros da equipa
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) DEFAULT 'member',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                UNIQUE KEY unique_member (project_id, user_id),
                INDEX idx_project (project_id),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $success[] = "✓ Tabela 'project_members' criada";
            
            // 4. Entregáveis
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_deliverables (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                due_date DATE,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                INDEX idx_project (project_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $success[] = "✓ Tabela 'project_deliverables' criada";
            
            // 4b. Tabela para associar múltiplas tasks aos entregáveis
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS deliverable_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                deliverable_id INT NOT NULL,
                todo_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (deliverable_id) REFERENCES project_deliverables(id) ON DELETE CASCADE,
                UNIQUE KEY unique_deliverable_task (deliverable_id, todo_id),
                INDEX idx_deliverable (deliverable_id),
                INDEX idx_todo (todo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $success[] = "✓ Tabela 'deliverable_tasks' criada";
            
            // 5. Associação com protótipos (se a tabela existir)
            $checkPrototypes = $pdo->query("SHOW TABLES LIKE 'prototypes'")->fetch();
            
            if ($checkPrototypes) {
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS project_prototypes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    prototype_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                    FOREIGN KEY (prototype_id) REFERENCES prototypes(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_project_prototype (project_id, prototype_id),
                    INDEX idx_project (project_id),
                    INDEX idx_prototype (prototype_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $success[] = "✓ Tabela 'project_prototypes' criada (com FK para prototypes)";
            } else {
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS project_prototypes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    prototype_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_project_prototype (project_id, prototype_id),
                    INDEX idx_project (project_id),
                    INDEX idx_prototype (prototype_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $success[] = "✓ Tabela 'project_prototypes' criada (sem FK para prototypes - tabela não existe)";
                $errors[] = "⚠️ Aviso: Tabela 'prototypes' não encontrada. Instale o módulo de protótipos primeiro para usar a funcionalidade completa.";
            }
            
            $success[] = "✓ Instalação concluída com sucesso!";
        }
        
    } catch (PDOException $e) {
        $errors[] = "Erro: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Módulo de Projetos</title>
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
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        .status-icon {
            font-size: 64px;
            text-align: center;
            margin-bottom: 20px;
        }
        h1 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #718096;
            text-align: center;
            margin-bottom: 30px;
        }
        .success, .error, .warning {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #059669;
        }
        .error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
        }
        .success h3, .error h3, .warning h3 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        ul {
            margin-left: 20px;
        }
        li {
            margin: 5px 0;
            color: #4a5568;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .info-box {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .info-box h4 {
            color: #2d3748;
            margin-bottom: 10px;
        }
        code {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (empty($errors)): ?>
            <div class="status-icon">✅</div>
            <h1>Instalação Concluída!</h1>
            <p class="subtitle">O módulo de Projetos foi instalado com sucesso</p>
            
            <div class="success">
                <h3>✓ Operações realizadas:</h3>
                <ul>
                    <?php foreach ($success as $msg): ?>
                        <li><?= $msg ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="info-box">
                <h4>🚀 Próximos Passos:</h4>
                <p>
                    1. O módulo está acessível em <code>?tab=projectos</code><br>
                    2. Elimine este ficheiro <code>install_projects.php</code> por segurança<br>
                    3. Comece a criar seus projetos!
                </p>
            </div>
            
            <a href="index.php?tab=projectos" class="btn">📁 Abrir Módulo de Projetos</a>
            
        <?php else: ?>
            <div class="status-icon"><?= empty($success) ? '❌' : '⚠️' ?></div>
            <h1><?= empty($success) ? 'Erro na Instalação' : 'Aviso' ?></h1>
            <p class="subtitle">Alguns problemas foram encontrados</p>
            
            <?php if (!empty($errors)): ?>
                <div class="<?= empty($success) ? 'error' : 'warning' ?>">
                    <h3><?= empty($success) ? '✗' : '⚠' ?> Avisos/Erros:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= $error ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="success">
                    <h3>✓ Operações bem-sucedidas:</h3>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?= $msg ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h4>💡 Sugestões:</h4>
                <p>
                    - Verifique se o ficheiro <code>config.php</code> existe<br>
                    - Confirme as permissões da base de dados<br>
                    - Instale o módulo de protótipos se quiser a integração completa
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>