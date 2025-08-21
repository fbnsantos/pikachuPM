<?php
require_once 'database/database.php';
$pdo = connectDB();

// Query para buscar os registros das associações de componentes, com a designação do componente
$stmtComp = $pdo->query("
    SELECT ac.*, c.Denomination
    FROM T_Assembly_Component ac
    JOIN T_Component c ON ac.Component_ID = c.Component_ID
");
$components = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

// Query para buscar os registros das associações de assemblies, com a designação da assembly filha
$stmtAssem = $pdo->query("
    SELECT aa.*, a.Assembly_Designation
    FROM T_Assembly_Assembly aa
    JOIN T_Assembly a ON aa.Child_Assembly_ID = a.Assembly_ID
");
$assemblies = $stmtAssem->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "components" => $components,
    "assemblies"   => $assemblies
]);
?>