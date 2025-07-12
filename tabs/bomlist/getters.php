<?php
// Função para buscar todos os fabricantes
function getManufacturers($pdo) {
    $stmt = $pdo->query("SELECT * FROM T_Manufacturer ORDER BY Denomination");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar todos os fornecedores
function getSuppliers($pdo) {
    $stmt = $pdo->query("SELECT * FROM T_Supplier ORDER BY Denomination");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar todos os protótipos
function getPrototypes($pdo) {
    $stmt = $pdo->query("SELECT * FROM T_Prototype ORDER BY Name, Version");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar todos os componentes
function getComponents($pdo) {
    $stmt = $pdo->query("
        SELECT c.*, 
               m.Denomination as Manufacturer_Name,
               s.Denomination as Supplier_Name
        FROM T_Component c
        LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID
        LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID
        ORDER BY c.Denomination
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAssemblies($pdo) {

// Buscar assemblies com informações detalhadas
    $stmt = $pdo->query("
        SELECT a.*, 
               p.Name as Prototype_Name,
               p.Version as Prototype_Version,
                cf.Denomination as Father_Name,
            cc.Denomination as Child_Name
        FROM T_Assembly a
        JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
        LEFT JOIN T_Component cf ON a.Father_ID = cf.Component_ID
        JOIN T_Component cc ON a.Child_ID = cc.Component_ID
        ORDER BY p.Name, p.Version, cf.Denomination, cc.Denomination
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>