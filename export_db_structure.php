<?php
/**
 * export_db_structure.php
 * 
 * Script para exportar a estrutura completa da base de dados
 * Gera um ficheiro setup_database.php que pode ser usado para
 * criar a base de dados inicial sem dados
 * 
 * COMO USAR:
 * 1. Certifique-se que o ficheiro config.php existe
 * 2. Execute: php export_db_structure.php
 * 3. Será gerado o ficheiro: setup_database.php
 */

// ========================================
// CONFIGURAÇÃO
// ========================================

// Carregar configurações do ficheiro config.php
if (file_exists(__DIR__ . '/config.php')) {
    include_once __DIR__ . '/config.php';
    echo "✅ Configurações carregadas de config.php\n";
} else {
    die("❌ ERRO: Ficheiro config.php não encontrado!\n\nCertifique-se que o ficheiro config.php existe no diretório atual.\n");
}

// Verificar se as variáveis foram carregadas
if (!isset($db_host) || !isset($db_name) || !isset($db_user)) {
    die("❌ ERRO: Variáveis de configuração não encontradas em config.php!\n\nVerifique se config.php contém:\n- \$db_host\n- \$db_name\n- \$db_user\n- \$db_pass\n");
}

echo "   Host: $db_host\n";
echo "   Database: $db_name\n";
echo "   User: $db_user\n\n";

// Nome do ficheiro de saída
$output_file = 'setup_database.php';

// ========================================
// CONEXÃO
// ========================================

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado à base de dados '$db_name'\n\n";
} catch (PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage() . "\n");
}

// ========================================
// OBTER LISTA DE TABELAS
// ========================================

echo "📋 A obter lista de tabelas...\n";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    die("❌ Nenhuma tabela encontrada na base de dados.\n");
}

echo "✅ Encontradas " . count($tables) . " tabelas\n\n";

// ========================================
// GERAR SQL DE CRIAÇÃO
// ========================================

$sql_output = [];
$table_info = [];

foreach ($tables as $table) {
    echo "📦 Processando tabela: $table\n";
    
    // Obter CREATE TABLE
    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $create_sql = $row['Create Table'];
    
    // Limpar e formatar
    $create_sql = str_replace("\n", "\n        ", $create_sql);
    $sql_output[] = $create_sql;
    
    // Obter informações da tabela
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("DESCRIBE `$table`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $table_info[$table] = [
        'rows' => $count,
        'columns' => count($columns),
        'column_names' => array_column($columns, 'Field')
    ];
}

echo "\n✅ Estrutura de todas as tabelas extraída com sucesso!\n\n";

// ========================================
// GERAR FICHEIRO PHP
// ========================================

echo "📝 A gerar ficheiro $output_file...\n";

$php_content = '<?php
/**
 * setup_database.php
 * 
 * Script de configuração inicial da base de dados PikachuPM
 * Gerado automaticamente em: ' . date('Y-m-d H:i:s') . '
 * 
 * COMO USAR:
 * 1. Crie uma base de dados vazia no MySQL
 * 2. Configure as credenciais abaixo
 * 3. Execute: php setup_database.php
 * 4. A estrutura completa será criada
 * 
 * ATENÇÃO: Este script NÃO contém dados, apenas a estrutura das tabelas
 */

// ========================================
// CONFIGURAÇÃO
// ========================================

$db_host = \'localhost\';
$db_name = \'pikachupm_new\';  // Nome da nova base de dados
$db_user = \'root\';            // Utilizador MySQL
$db_pass = \'\';                // Password MySQL

// ========================================
// ESTATÍSTICAS DA BD ORIGINAL
// ========================================

/*
Total de tabelas: ' . count($tables) . '

Tabelas encontradas:
';

foreach ($table_info as $table => $info) {
    $php_content .= "- $table ({$info['columns']} colunas, {$info['rows']} registos na BD original)\n";
}

$php_content .= '
*/

// ========================================
// CONEXÃO
// ========================================

echo "🔧 PikachuPM - Setup da Base de Dados\n";
echo "=====================================\n\n";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado à base de dados \'$db_name\'\n\n";
} catch (PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage() . "\n\nVerifique se:\n1. A base de dados existe\n2. As credenciais estão corretas\n3. O MySQL está a correr\n");
}

// Desativar verificação de chaves estrangeiras temporariamente
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// ========================================
// CRIAR TABELAS
// ========================================

$tables_created = 0;
$tables_failed = 0;

';

// Adicionar SQL de cada tabela
foreach ($sql_output as $index => $sql) {
    $table_name = $tables[$index];
    $php_content .= "// Tabela: $table_name\n";
    $php_content .= "echo \"📦 Criando tabela: $table_name...\";\n";
    $php_content .= "try {\n";
    $php_content .= "    \$pdo->exec(\"\n        $sql\n    \");\n";
    $php_content .= "    echo \" ✅\\n\";\n";
    $php_content .= "    \$tables_created++;\n";
    $php_content .= "} catch (PDOException \$e) {\n";
    $php_content .= "    echo \" ❌\\n\";\n";
    $php_content .= "    echo \"   Erro: \" . \$e->getMessage() . \"\\n\";\n";
    $php_content .= "    \$tables_failed++;\n";
    $php_content .= "}\n\n";
}

$php_content .= '
// Reativar verificação de chaves estrangeiras
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// ========================================
// RELATÓRIO FINAL
// ========================================

echo "\n=====================================\n";
echo "📊 RELATÓRIO FINAL\n";
echo "=====================================\n\n";

echo "✅ Tabelas criadas com sucesso: $tables_created\n";
if ($tables_failed > 0) {
    echo "❌ Tabelas com erros: $tables_failed\n";
}

echo "\n";

if ($tables_failed == 0) {
    echo "🎉 Base de dados configurada com sucesso!\n";
    echo "\nPróximos passos:\n";
    echo "1. Configure o ficheiro config.php com estas credenciais\n";
    echo "2. Execute admin_permissions.sql para criar o primeiro admin\n";
    echo "3. (Opcional) Execute auth_local_tables.sql para login local\n";
} else {
    echo "⚠️  Algumas tabelas não foram criadas.\n";
    echo "Verifique os erros acima e corrija-os.\n";
}

echo "\n=====================================\n";

// ========================================
// VERIFICAÇÃO FINAL
// ========================================

echo "\n🔍 Verificando estrutura criada...\n\n";

$stmt = $pdo->query("SHOW TABLES");
$created_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($created_tables as $table) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)[\'count\'];
    echo "  - $table: $count registos\n";
}

echo "\n✅ Verificação concluída!\n";
';

// Escrever ficheiro
file_put_contents($output_file, $php_content);

echo "✅ Ficheiro gerado com sucesso: $output_file\n";
echo "📏 Tamanho: " . number_format(filesize($output_file) / 1024, 2) . " KB\n\n";

// ========================================
// RELATÓRIO FINAL
// ========================================

echo "=====================================\n";
echo "📊 RELATÓRIO DA EXPORTAÇÃO\n";
echo "=====================================\n\n";

echo "📦 Tabelas exportadas: " . count($tables) . "\n";
echo "📄 Ficheiro gerado: $output_file\n";
echo "🗓️  Data: " . date('Y-m-d H:i:s') . "\n\n";

echo "Estrutura das tabelas:\n";
foreach ($table_info as $table => $info) {
    echo "  - $table:\n";
    echo "      Colunas: {$info['columns']}\n";
    echo "      Registos na BD original: {$info['rows']}\n";
}

echo "\n=====================================\n";
echo "🎉 EXPORTAÇÃO CONCLUÍDA!\n";
echo "=====================================\n\n";

echo "Como usar o ficheiro gerado:\n";
echo "1. Crie uma nova base de dados vazia no MySQL\n";
echo "2. Edite $output_file e configure as credenciais\n";
echo "3. Execute: php $output_file\n";
echo "4. A estrutura completa será criada na nova BD\n\n";

echo "⚠️  NOTA: O ficheiro gerado contém APENAS a estrutura.\n";
echo "   Não inclui nenhum dado das tabelas.\n\n";
?>