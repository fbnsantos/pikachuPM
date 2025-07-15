<?php
function processCRUD($pdo, $entity , $action){
    $message = "";
    switch ($entity) {
        case 'manufacturers':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("INSERT INTO T_Manufacturer (Denomination, Origin_Country, Website, Contacts) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['denomination'], $_POST['origin_country'], $_POST['website'], $_POST['contacts']]);
                $message = "Fabricante criado com sucesso!";
            } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("UPDATE T_Manufacturer SET Denomination=?, Origin_Country=?, Website=?, Contacts=? WHERE Manufacturer_ID=?");
                $stmt->execute([$_POST['denomination'], $_POST['origin_country'], $_POST['website'], $_POST['contacts'], $_POST['id']]);
                $message = "Fabricante atualizado com sucesso!";
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                $stmt = $pdo->prepare("DELETE FROM T_Manufacturer WHERE Manufacturer_ID=?");
                $stmt->execute([$_GET['id']]);
                $message = "Fabricante eliminado com sucesso!";
            }
            break;
        
        case 'suppliers':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("INSERT INTO T_Supplier (Denomination, Origin_Country, Website, Contacts) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['denomination'], $_POST['origin_country'], $_POST['website'], $_POST['contacts']]);
                $message = "Fornecedor criado com sucesso!";
            } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("UPDATE T_Supplier SET Denomination=?, Origin_Country=?, Website=?, Contacts=? WHERE Supplier_ID=?");
                $stmt->execute([$_POST['denomination'], $_POST['origin_country'], $_POST['website'], $_POST['contacts'], $_POST['id']]);
                $message = "Fornecedor atualizado com sucesso!";
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                $stmt = $pdo->prepare("DELETE FROM T_Supplier WHERE Supplier_ID=?");
                $stmt->execute([$_GET['id']]);
                $message = "Fornecedor eliminado com sucesso!";
            }
            break;
        
        case 'prototypes':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("INSERT INTO T_Prototype (Name, Version, Description, Status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['version'], $_POST['description'], $_POST['status']]);
                $message = "Protótipo criado com sucesso!";
            } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("UPDATE T_Prototype SET Name=?, Version=?, Description=?, Status=? WHERE Prototype_ID=?");
                $stmt->execute([$_POST['name'], $_POST['version'], $_POST['description'], $_POST['status'], $_POST['id']]);
                $message = "Protótipo atualizado com sucesso!";
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                $stmt = $pdo->prepare("DELETE FROM T_Prototype WHERE Prototype_ID=?");
                $stmt->execute([$_GET['id']]);
                $message = "Protótipo eliminado com sucesso!";
            } elseif ($action === 'clone' && isset($_GET['id'])) {
                // Clonar protótipo
                $stmt = $pdo->prepare("SELECT * FROM T_Prototype WHERE Prototype_ID=?");
                $stmt->execute([$_GET['id']]);
                $prototype = $stmt->fetch(PDO::FETCH_ASSOC);
            
                if ($prototype) {
                    $newVersion = floatval($prototype['Version']) + 0.1;
                    $stmt = $pdo->prepare("INSERT INTO T_Prototype (Name, Version, Description, Status) VALUES (?, ?, ?, 'Development')");
                    $stmt->execute([$prototype['Name'], number_format($newVersion, 1), $prototype['Description'] . ' (Clonado)', ]);
                    $newPrototypeId = $pdo->lastInsertId();
                
                    // Clonar assembly
                    $stmt = $pdo->prepare("SELECT * FROM T_Assembly WHERE Prototype_ID=?");
                    $stmt->execute([$_GET['id']]);
                    $assemblies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                    foreach ($assemblies as $assembly) {
                        $stmt = $pdo->prepare("INSERT INTO T_Assembly (Prototype_ID, Father_ID, Child_ID, Quantity, Level_Depth, Notes) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$newPrototypeId, $assembly['Father_ID'], $assembly['Child_ID'], $assembly['Quantity'], $assembly['Level_Depth'], $assembly['Notes']]);
                    }
                
                    $message = "Protótipo clonado com sucesso!";
                }
            }
            break;
        
        case 'components':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("INSERT INTO T_Component (Denomination, Manufacturer_ID, Manufacturer_ref, Supplier_ID, Supplier_ref, General_Type, Price, Acquisition_Date, Notes_Description, Stock_Quantity, Min_Stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['denomination'], 
                    $_POST['manufacturer_id'] ?: null, 
                    $_POST['manufacturer_ref'], 
                    $_POST['supplier_id'] ?: null, 
                    $_POST['supplier_ref'], 
                    $_POST['general_type'], 
                    $_POST['price'] ?: null, 
                    $_POST['acquisition_date'] ?: null, 
                    $_POST['notes_description'],
                    $_POST['stock_quantity'] ?: 0,
                    $_POST['min_stock'] ?: 0
                ]);
                $message = "Componente criado com sucesso!";
            } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("UPDATE T_Component SET Denomination=?, Manufacturer_ID=?, Manufacturer_ref=?, Supplier_ID=?, Supplier_ref=?, General_Type=?, Price=?, Acquisition_Date=?, Notes_Description=?, Stock_Quantity=?, Min_Stock=? WHERE Component_ID=?");
                $stmt->execute([
                    $_POST['denomination'], 
                    $_POST['manufacturer_id'] ?: null, 
                    $_POST['manufacturer_ref'], 
                    $_POST['supplier_id'] ?: null, 
                    $_POST['supplier_ref'], 
                    $_POST['general_type'], 
                    $_POST['price'] ?: null, 
                    $_POST['acquisition_date'] ?: null, 
                    $_POST['notes_description'],
                    $_POST['stock_quantity'] ?: 0,
                    $_POST['min_stock'] ?: 0,
                    $_POST['id']
                ]);
                $message = "Componente atualizado com sucesso!";
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                $stmt = $pdo->prepare("DELETE FROM T_Component WHERE Component_ID=?");
                $stmt->execute([$_GET['id']]);
                $message = "Componente eliminado com sucesso!";
            }
            break;
        
        case 'assembly':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->prepare("INSERT INTO T_Assembly (Prototype_ID, Assembly_Designation, Component_Father_ID, Component_Child_ID, Component_Quantity, Assembly_Father_ID , Assembly_Child_ID, Assembly_Quantity, Notes) VALUES (?, ?, ?, ?, ?, ?,?,?,?) ON DUPLICATE KEY UPDATE Component_Quantity=VALUES(Component_Quantity), Notes=VALUES(Notes), Assembly_Quantity=VALUES(Assembly_Quantity)");
                $stmt->execute([
                    $_POST['prototype_id'], 
                    $_POST['assembly_designation'] ?: null, 
                    $_POST['component_father_id'] ?: null, 
                    $_POST['component_child_id'] ?: null, 
                    $_POST['component_quantity'] ?: 0, 
                    $_POST['assembly_father_id'] ?: null, 
                    $_POST['assembly_child_id'] ?: null, 
                    $_POST['assembly_quantity'] ?: 0, 
                    $_POST['notes'] ?: null
                ]);
                $message = "Montagem criada com sucesso!";
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                $stmt = $pdo->prepare("DELETE FROM T_Assembly WHERE Assembly_ID=?");
                $stmt->execute([$_GET['id']]);
                $message = "Montagem eliminada com sucesso!";
            }
            break;
        default:
            $message = "Ação não reconhecida.";
            break;
    }
    return $message;
}
?>