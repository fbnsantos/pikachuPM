<?php

function connectDB(){
    // Database configuration
    $db_host = '127.0.0.1';
    $db_user = 'pikachu';
    $db_pass = 'pikachu123'; 
    $db_name = 'pikachuPM';
    
    $sqlFile = __DIR__ . '/database.sql';
    $sql = file_get_contents($sqlFile);

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Split and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if ($statement) {
                $pdo->exec($statement);
            }
        }
        echo "Importação concluída!";
        return $pdo;
    } catch (PDOException $e) {
        echo "Erro: " . $e->getMessage();
        return false;
    }
}
?>