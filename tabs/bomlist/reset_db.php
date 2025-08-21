<?php
include_once __DIR__ . '/../../config.php';

echo "<h2>Database Reset Debug</h2>";
echo "<strong>Config values:</strong><br>";
echo "Host: $db_host<br>";
echo "Database: $db_name_boom<br>";
echo "User: $db_user<br>";
echo "Password: " . ($db_pass ? '[SET]' : '[EMPTY]') . "<br><br>";

try {
    // Connect without database
    echo "Attempting to connect to MySQL...<br>";
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to MySQL<br><br>";
    
    // Check if database exists before dropping
    echo "Checking if database exists...<br>";
    $stmt = $pdo->query("SHOW DATABASES LIKE '$db_name_boom'");
    $exists = $stmt->fetch();
    echo "Database exists: " . ($exists ? 'YES' : 'NO') . "<br><br>";
    
    // Drop and recreate database
    echo "Dropping database if exists...<br>";
    $result = $pdo->exec("DROP DATABASE IF EXISTS `$db_name_boom`");
    echo "Drop result: $result<br>";
    
    echo "Creating new database...<br>";
    $result = $pdo->exec("CREATE DATABASE `$db_name_boom`");
    echo "Create result: $result<br>";

    $result = $pdo->exec("USE `$db_name_boom`");
    echo "Use database result: $result<br><br>";
    
    // Check SQL file
    $sqlFile = __DIR__ . '/database/database.sql';
    echo "SQL file path: $sqlFile<br>";
    echo "SQL file exists: " . (file_exists($sqlFile) ? 'YES' : 'NO') . "<br>";
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found at: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    echo "SQL file size: " . strlen($sql) . " bytes<br>";
    echo "First 200 chars: " . htmlspecialchars(substr($sql, 0, 200)) . "<br><br>";
    
    echo "Executing SQL statements...<br>";
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    echo "Number of statements: " . count($statements) . "<br><br>";
    
    $executed = 0;
    foreach ($statements as $i => $statement) {
        if ($statement && !empty(trim($statement))) {
            try {
                $result = $pdo->exec($statement);
                echo "✓ Statement " . ($i+1) . ": " . substr($statement, 0, 80) . "... (affected: $result)<br>";
                $executed++;
            } catch (PDOException $e) {
                echo "⚠ Statement " . ($i+1) . " Warning: " . $e->getMessage() . "<br>";
                echo "Statement was: " . htmlspecialchars(substr($statement, 0, 100)) . "<br>";
            }
        }
    }
    
    echo "<br>Executed $executed statements successfully<br>";
    
    // Verify tables were created
    echo "<br>Checking created tables...<br>";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables created: " . implode(', ', $tables) . "<br>";
    
    echo "<h3 style='color: green;'>✅ Database reset completed!</h3>";
    echo "<a href='../../index.php?tab=bomlist/bomlist'>Go to BOM List</a>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ Error: " . $e->getMessage() . "</h3>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>