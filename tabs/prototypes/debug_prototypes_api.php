<?php
/**
 * Script de debug para prototypes_api.php
 * Mostra erros detalhados do PHP
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Debug Prototypes API</h1>";
echo "<pre>";

// 1. Verificar configuração
echo "=== 1. VERIFICAR CONFIG.PHP ===\n";
$configPath = __DIR__ . '/../../config.php';
if (file_exists($configPath)) {
    echo "✓ config.php existe\n";
    include_once $configPath;
    echo "DB Host: $db_host\n";
    echo "DB Name: $db_name\n";
    echo "DB User: $db_user\n";
} else {
    echo "✗ config.php NÃO ENCONTRADO em: $configPath\n";
    die("Configure o caminho correto!\n");
}

// 2. Testar conexão
echo "\n=== 2. TESTAR CONEXÃO ===\n";
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
    echo "✓ Conexão estabelecida\n";
} catch (PDOException $e) {
    echo "✗ ERRO DE CONEXÃO: " . $e->getMessage() . "\n";
    die();
}

// 3. Verificar tabela prototypes
echo "\n=== 3. VERIFICAR TABELA PROTOTYPES ===\n";
try {
    $result = $pdo->query("SHOW TABLES LIKE 'prototypes'");
    if ($result->rowCount() > 0) {
        echo "✓ Tabela 'prototypes' existe\n";
        
        // Mostrar estrutura
        echo "\nEstrutura da tabela:\n";
        $columns = $pdo->query("SHOW COLUMNS FROM prototypes")->fetchAll();
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
        
        // Contar registros
        $count = $pdo->query("SELECT COUNT(*) as total FROM prototypes")->fetch();
        echo "\nTotal de protótipos: {$count['total']}\n";
        
    } else {
        echo "✗ Tabela 'prototypes' NÃO EXISTE\n";
        echo "Execute install_prototypes.php primeiro!\n";
        die();
    }
} catch (PDOException $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n";
    die();
}

// 4. Testar query básica
echo "\n=== 4. TESTAR QUERY BÁSICA ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM prototypes LIMIT 1");
    $row = $stmt->fetch();
    if ($row) {
        echo "✓ Query funciona. Exemplo de registro:\n";
        print_r($row);
    } else {
        echo "✓ Query funciona mas não há registros\n";
    }
} catch (PDOException $e) {
    echo "✗ ERRO NA QUERY: " . $e->getMessage() . "\n";
}

// 5. Testar query com search
echo "\n=== 5. TESTAR QUERY COM SEARCH ===\n";
try {
    $search = 'pic';
    $columns = $pdo->query("SHOW COLUMNS FROM prototypes")->fetchAll(PDO::FETCH_COLUMN);
    
    $searchConditions = [];
    if (in_array('name', $columns)) $searchConditions[] = "name LIKE :search";
    if (in_array('identifier', $columns)) $searchConditions[] = "identifier LIKE :search";
    if (in_array('description', $columns)) $searchConditions[] = "description LIKE :search";
    
    $sql = "SELECT * FROM prototypes";
    if (!empty($searchConditions)) {
        $sql .= " WHERE " . implode(" OR ", $searchConditions);
    }
    
    echo "SQL gerado: $sql\n\n";
    
    $stmt = $pdo->prepare($sql);
    $searchParam = "%$search%";
    $stmt->execute(['search' => $searchParam]);
    
    $results = $stmt->fetchAll();
    echo "✓ Query com search funciona. Resultados: " . count($results) . "\n";
    
    if (!empty($results)) {
        echo "\nPrimeiro resultado:\n";
        print_r($results[0]);
    }
    
} catch (PDOException $e) {
    echo "✗ ERRO NA QUERY COM SEARCH: " . $e->getMessage() . "\n";
}

// 6. Testar processamento de participantes
echo "\n=== 6. TESTAR PROCESSAMENTO JSON ===\n";
try {
    $stmt = $pdo->query("SELECT id, name, participants FROM prototypes LIMIT 3");
    $rows = $stmt->fetchAll();
    
    foreach ($rows as $row) {
        echo "ID {$row['id']}: ";
        if (isset($row['participants']) && $row['participants']) {
            $decoded = json_decode($row['participants'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "JSON válido - " . count($decoded) . " participantes\n";
            } else {
                echo "ERRO JSON: " . json_last_error_msg() . " - Valor: {$row['participants']}\n";
            }
        } else {
            echo "Sem participantes\n";
        }
    }
} catch (PDOException $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n";
}

// 7. Verificar user_tokens
echo "\n=== 7. VERIFICAR USER_TOKENS ===\n";
try {
    $result = $pdo->query("SHOW TABLES LIKE 'user_tokens'");
    if ($result->rowCount() > 0) {
        echo "✓ Tabela 'user_tokens' existe\n";
        $count = $pdo->query("SELECT COUNT(*) as total FROM user_tokens")->fetch();
        echo "Total de usuários: {$count['total']}\n";
        
        // Mostrar alguns usuários
        $users = $pdo->query("SELECT user_id, username, email FROM user_tokens LIMIT 5")->fetchAll();
        echo "\nExemplos de usuários:\n";
        foreach ($users as $user) {
            echo "  - {$user['username']} (ID: {$user['user_id']})\n";
        }
    } else {
        echo "✗ Tabela 'user_tokens' NÃO EXISTE\n";
    }
} catch (PDOException $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== DEBUG COMPLETO ===\n";
echo "Se todos os testes passaram, o problema pode ser:\n";
echo "1. Sessão não iniciada (session_start)\n";
echo "2. Variável \$_SESSION não definida\n";
echo "3. Caminho do config.php incorreto\n";
echo "4. JSON malformado nos dados\n";
echo "\n";
echo "Próximo passo: Testar diretamente a API\n";
echo "URL: tabs/prototypes/prototypes_api.php?action=get_prototypes\n";

echo "</pre>";
?>