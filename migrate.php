<?php
/**
 * Script de Migração: SQLite → MySQL
 * 
 * Este script migra todos os eventos da base de dados SQLite (eventos.sqlite)
 * para a nova tabela MySQL (calendar_eventos).
 * 
 * ATENÇÃO: Execute este script apenas UMA VEZ!
 * 
 * Uso:
 * 1. Coloque este ficheiro na raiz do projeto
 * 2. Execute via browser: http://seu-site.com/migrate_calendar.php
 * 3. Ou via linha de comando: php migrate_calendar.php
 */

// Configuração
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Incluir configuração do MySQL
include_once __DIR__ . '/config.php';

echo "<h2>🔄 Migração de Calendário: SQLite → MySQL</h2>";
echo "<pre>";

// Verificar se o arquivo SQLite existe
$sqlite_path = __DIR__ . '/eventos.sqlite';
if (!file_exists($sqlite_path)) {
    die("❌ ERRO: Ficheiro SQLite não encontrado em: $sqlite_path\n");
}

echo "✓ Ficheiro SQLite encontrado\n\n";

// Conectar ao SQLite
try {
    $db_sqlite = new PDO('sqlite:' . $sqlite_path);
    $db_sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Conectado ao SQLite\n";
} catch (Exception $e) {
    die("❌ ERRO ao conectar ao SQLite: " . $e->getMessage() . "\n");
}

// Conectar ao MySQL
try {
    $db_mysql = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db_mysql->connect_error) {
        die("❌ ERRO ao conectar ao MySQL: " . $db_mysql->connect_error . "\n");
    }
    
    $db_mysql->set_charset("utf8mb4");
    echo "✓ Conectado ao MySQL\n\n";
} catch (Exception $e) {
    die("❌ ERRO: " . $e->getMessage() . "\n");
}

// Verificar se a tabela MySQL existe
$check_table = $db_mysql->query("SHOW TABLES LIKE 'calendar_eventos'");
if ($check_table->num_rows == 0) {
    echo "⚠️  Tabela 'calendar_eventos' não existe. Criando...\n";
    
    $create_table = "CREATE TABLE calendar_eventos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data DATE NOT NULL,
        tipo VARCHAR(50) NOT NULL,
        descricao TEXT,
        hora TIME DEFAULT NULL,
        criador VARCHAR(100),
        cor VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_data (data),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($db_mysql->query($create_table)) {
        echo "✓ Tabela criada com sucesso\n\n";
    } else {
        die("❌ ERRO ao criar tabela: " . $db_mysql->error . "\n");
    }
} else {
    echo "✓ Tabela 'calendar_eventos' já existe\n\n";
}

// Buscar todos os eventos do SQLite
try {
    $stmt = $db_sqlite->query("SELECT * FROM eventos ORDER BY data ASC");
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($eventos);
    echo "📊 Encontrados $total eventos para migrar\n\n";
    
    if ($total == 0) {
        die("ℹ️  Não há eventos para migrar.\n");
    }
    
} catch (Exception $e) {
    die("❌ ERRO ao buscar eventos do SQLite: " . $e->getMessage() . "\n");
}

// Verificar se já existem eventos no MySQL
$check_existing = $db_mysql->query("SELECT COUNT(*) as total FROM calendar_eventos");
$existing = $check_existing->fetch_assoc()['total'];

if ($existing > 0) {
    echo "⚠️  ATENÇÃO: Já existem $existing eventos na tabela MySQL!\n";
    echo "   Deseja continuar e adicionar os eventos do SQLite? (s/n): ";
    
    // Se executado via browser, pular esta verificação
    if (php_sapi_name() === 'cli') {
        $handle = fopen("php://stdin", "r");
        $response = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($response) !== 's') {
            die("❌ Migração cancelada pelo utilizador.\n");
        }
    } else {
        echo "\n   Continuando automaticamente (modo web)...\n";
    }
    echo "\n";
}

// Migrar cada evento
$migrados = 0;
$erros = 0;
$duplicados = 0;

echo "🚀 Iniciando migração...\n\n";

foreach ($eventos as $evento) {
    // Verificar se já existe um evento idêntico (para evitar duplicação)
    $check_stmt = $db_mysql->prepare(
        "SELECT id FROM calendar_eventos WHERE data = ? AND tipo = ? AND descricao = ?"
    );
    $check_stmt->bind_param('sss', $evento['data'], $evento['tipo'], $evento['descricao']);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $duplicados++;
        echo "⊘ Duplicado: {$evento['data']} - {$evento['tipo']} - {$evento['descricao']}\n";
        $check_stmt->close();
        continue;
    }
    $check_stmt->close();
    
    // Inserir evento no MySQL
    $insert_stmt = $db_mysql->prepare(
        "INSERT INTO calendar_eventos (data, tipo, descricao, criador, cor, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    
    $insert_stmt->bind_param(
        'sssss',
        $evento['data'],
        $evento['tipo'],
        $evento['descricao'],
        $evento['criador'],
        $evento['cor']
    );
    
    if ($insert_stmt->execute()) {
        $migrados++;
        echo "✓ Migrado: {$evento['data']} - {$evento['tipo']} - {$evento['descricao']}\n";
    } else {
        $erros++;
        echo "✗ Erro: {$evento['data']} - {$evento['tipo']} - " . $insert_stmt->error . "\n";
    }
    
    $insert_stmt->close();
}

// Resumo da migração
echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "📊 RESUMO DA MIGRAÇÃO\n";
echo "═══════════════════════════════════════════════════════\n";
echo "Total de eventos no SQLite: $total\n";
echo "Eventos migrados com sucesso: $migrados\n";
echo "Eventos duplicados (ignorados): $duplicados\n";
echo "Erros durante migração: $erros\n";
echo "═══════════════════════════════════════════════════════\n\n";

if ($erros == 0 && $migrados > 0) {
    echo "✅ Migração concluída com SUCESSO!\n\n";
    echo "Próximos passos:\n";
    echo "1. Verifique os dados no calendário\n";
    echo "2. Faça backup do ficheiro eventos.sqlite\n";
    echo "3. APAGUE este script (migrate_calendar.php) por segurança\n";
} elseif ($migrados == 0 && $duplicados > 0) {
    echo "ℹ️  Todos os eventos já existiam no MySQL.\n";
    echo "   Nenhuma alteração foi feita.\n";
} else {
    echo "⚠️  Migração concluída com alguns problemas.\n";
    echo "   Verifique os erros acima e corrija se necessário.\n";
}

// Fechar conexões
$db_sqlite = null;
$db_mysql->close();

echo "\n";
echo "</pre>";

// Script de auto-destruição (comentado por segurança)
/*
if ($migrados > 0 && $erros == 0) {
    echo "<p style='color: orange;'>🔥 Este script será auto-destruído em 10 segundos...</p>";
    echo "<script>setTimeout(function(){ window.location.href = 'delete_migrate.php'; }, 10000);</script>";
}
*/
?>