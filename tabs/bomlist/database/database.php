<?php



function connectDB(){
    include __DIR__ . '/../../../config.php';
    // Database configuration
    //$db_host = 'localhost';
    //$db_user = 'pkmt_user';
    //$db_pass = 'pikachu123'; 
    $db_name = 'pkmt_boomlist';
    
    $sqlFile = __DIR__ . '/database.sql';
    $sql = file_get_contents($sqlFile);

    try {
         //echo $db_pass;
         echo "2";
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        echo "2";
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