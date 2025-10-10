<?php
/**
 * Script OPCIONAL para adicionar Foreign Key para tabela todos
 * Execute este script APENAS se:
 * 1. Já tiver criado a tabela 'todos' no sistema
 * 2. Quiser estabelecer a relação entre user_story_tasks e todos
 */

include_once __DIR__ . '/config.php';

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
    
    // Verificar se a tabela todos existe
    $result = $pdo->query("SHOW TABLES LIKE 'todos'");
    
    if ($result->rowCount() === 0) {
        $errors[] = "A tabela 'todos' ainda não existe no sistema.";
        $errors[] = "Crie primeiro a tabela 'todos' antes de executar este script.";
    } else {
        $success[] = "✓ Tabela 'todos' encontrada!";
        
        // Verificar se a foreign key já existe
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = '$db_name' 
            AND TABLE_NAME = 'user_story_tasks' 
            AND REFERENCED_TABLE_NAME = 'todos'
        ");
        
        if ($stmt->rowCount() > 0) {
            $errors[] = "A Foreign Key para 'todos' já existe!";
        } else {
            // Adicionar a Foreign Key
            $pdo->exec("
                ALTER TABLE user_story_tasks
                ADD CONSTRAINT fk_user_story_tasks_task_id
                FOREIGN KEY (task_id) REFERENCES todos(id) ON DELETE CASCADE
            ");
            
            $success[] = "✓ Foreign Key adicionada com sucesso!";
            $success[] = "✓ A tabela user_story_tasks está agora totalmente ligada à tabela todos.";
            
            // Adicionar unique constraint se não existir
            try {
                $pdo->exec("
                    ALTER TABLE user_story_tasks
                    ADD UNIQUE KEY unique_story_task (story_id, task_id)
                ");
                $success[] = "✓ Constraint UNIQUE adicionada (previne duplicados).";
            } catch (PDOException $e) {
                // Constraint já existe, não é erro crítico
                if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    $success[] = "✓ Constraint UNIQUE já existe.";
                }
            }
        }
    }
    
} catch (PDOException $e) {
    $errors[] = "Erro na base de dados: " . $e->getMessage();
} catch (Exception $e) {
    $errors[] = "Erro: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adicionar Foreign Key - Tasks</title>
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
        
        .success h3, .error h3 {
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
            <h1>Foreign Key Adicionada!</h1>
            <p class="subtitle">A ligação com a tabela tasks foi estabelecida</p>
            
            <div class="success">
                <h3>✓ Operações realizadas:</h3>
                <ul>
                    <?php foreach ($success as $msg): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="info-box">
                <h4>🎉 Pronto!</h4>
                <p>
                    Agora as User Stories podem ser ligadas às Tasks do sistema.<br>
                    Quando uma Task for eliminada, a ligação será automaticamente removida (CASCADE).<br><br>
                    <strong>Pode eliminar este ficheiro</strong> <code>add_tasks_foreign_key.php</code>
                </p>
            </div>
            
            <a href="tabs/prototypes/prototypesv2.php" class="btn">📋 Abrir Protótipos</a>
            
        <?php else: ?>
            <div class="status-icon">⚠️</div>
            <h1>Não Foi Possível Adicionar</h1>
            <p class="subtitle">Verifique os requisitos abaixo</p>
            
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <h3>✗ Problemas encontrados:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
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
                <h4>💡 Como criar a tabela tasks:</h4>
                <p>
                    Execute este SQL para criar uma tabela tasks básica:<br><br>
                    <code style="display: block; white-space: pre-wrap; padding: 10px; margin-top: 10px;">
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    priority VARCHAR(50) DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
                    </code>
                </p>
            </div>
            
            <a href="javascript:history.back()" class="btn">← Voltar</a>
        <?php endif; ?>
    </div>
</body>
</html>