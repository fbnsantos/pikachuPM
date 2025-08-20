<?php
include_once __DIR__ . '/../config.php';

function connectDB() {
    global $db_host, $db_user, $db_pass, $db_name;
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Erro na ligação à base de dados: " . $e->getMessage());
    }
}

$pdo = connectDB();

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_file'])) {
    $sqlFile = $_FILES['sql_file']['tmp_name'];
    $command = "mysql -h $db_host -u $db_user -p$db_pass $db_name < $sqlFile";
    system($command, $result);
    echo $result === 0 ? "<p>Base de dados restaurada com sucesso.</p>" : "<p>Erro ao restaurar base de dados.</p>";
}

// Handle download
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $filename = "backup_" . date("Y-m-d_H-i-s") . ".sql";
    $command = "mysqldump -h $db_host -u $db_user -p$db_pass $db_name > $filename";
    system($command);
    header('Content-Type: application/sql');
    header("Content-Disposition: attachment; filename=$filename");
    readfile($filename);
    unlink($filename);
    exit;
}
?>

<h2>Administração da Base de Dados</h2>

<h3>Tabelas e Número de Registos</h3>
<table border="1" cellpadding="5">
    <tr><th>Tabela</th><th>Número de Linhas</th></tr>
    <?php
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "<tr><td>$table</td><td>$count</td></tr>";
    }
    ?>
</table>

<h3>Download da Base de Dados</h3>
<form method="get">
    <button type="submit" name="download" value="1">Descarregar Backup (.sql)</button>
</form>

<h3>Upload e Restauração</h3>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="sql_file" accept=".sql" required>
    <button type="submit">Restaurar Base de Dados</button>
</form>