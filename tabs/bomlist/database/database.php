<?php



function connectDB(){
    include __DIR__ . '/../../../config.php';
    // Database configuration
    //$db_host = 'localhost';
    //$db_user = 'pkmt_user';
    //$db_pass = 'pikachu123'; 
   // $db_name_boom = 'pkmt_boomlist';
    
    $sqlFile = __DIR__ . '/database.sql';
    $sql = file_get_contents($sqlFile);

    try {
         //echo $db_pass;
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name_boom;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Split and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if ($statement) {
                $pdo->exec($statement);
            }
        }
        return $pdo;
    } catch (PDOException $e) {
        echo "Erro: " . $e->getMessage();
        return false;
    }
}
?>