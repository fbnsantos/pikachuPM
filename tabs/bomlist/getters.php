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

// Função para buscar todas as montagens
function getAssemblies($pdo) {
    $stmt = $pdo->query("
        SELECT a.*, 
               p.Name AS Prototype_Name,
               p.Version AS Prototype_Version,
               cf.Denomination AS Component_Father_Designation,
               cc.Denomination AS Component_Child_Designation,
               af.Assembly_Designation AS Assembly_Father_Designation,
               ac.Assembly_Designation AS Assembly_Child_Designation
        FROM T_Assembly a
        JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
        LEFT JOIN T_Component cf ON a.Component_Father_ID = cf.Component_ID
        LEFT JOIN T_Component cc ON a.Component_Child_ID = cc.Component_ID
        LEFT JOIN T_Assembly af ON a.Assembly_Father_ID = af.Assembly_ID
        LEFT JOIN T_Assembly ac ON a.Assembly_Child_ID = ac.Assembly_ID
        ORDER BY p.Name, p.Version
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar a árvore de montagens de um protótipo
// Tem de se alterar para ir buscar outras assemblies em vez de só componentes
function getAssemblyTree($pdo, $prototypeId, $assemblyId = null) {
    if ($assemblyId === null) {
        // Encontrar todas as montagens (não excluir nenhuma montagem)
        $stmt = $pdo->prepare("
            SELECT a.*
            FROM T_Assembly a
            WHERE a.Prototype_ID = ?
            ORDER BY a.Created_Date ASC
        ");
        $stmt->execute([$prototypeId]);
        $assemblies = $stmt->fetchAll(PDO::FETCH_ASSOC); 

        // Construir a árvore a partir das montagens com o nível mais alto
        $tree = [];
        foreach ($assemblies as $assembly) {
            if ($assembly['Assembly_Father_ID'] === null) {
                $tree[] = buildAssemblyTree($assemblies, $assembly);
            }
        }
        return $tree;
    }
}

/**
 * Função auxiliar para construir a árvore recursivamente
 */
function buildAssemblyTree($assemblies, $parent) {
    $children = [];
    foreach ($assemblies as $assembly) {
        if ($assembly['Assembly_Father_ID'] === $parent['Assembly_ID']) {

            $children[] = buildAssemblyTree($assemblies, $assembly);
        }
    }
    $parent['children'] = $children;
    return $parent;
}



?>