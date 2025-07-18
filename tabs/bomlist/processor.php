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
                    $stmt = $pdo->prepare("
                        INSERT INTO T_Assembly (
                            Prototype_ID, 
                            Assembly_Designation, 
                            Component_Father_ID,
                            Component_Father_Quantity,
                            Component_Child_ID, 
                            Component_Child_Quantity, 
                            Assembly_Father_ID, 
                            Assembly_Father_Quantity,
                            Assembly_Child_ID, 
                            Assembly_Child_Quantity, 
                            Assembly_Level_Depth, 
                            Notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newPrototypeId,
                        $assembly['Assembly_Designation'],
                        $assembly['Component_Father_ID'],
                        $assembly['Component_Father_Quantity'],
                        $assembly['Component_Child_ID'],
                        $assembly['Component_Child_Quantity'],
                        $assembly['Assembly_Father_ID'],
                        $assembly['Assembly_Father_Quantity'],
                        $assembly['Assembly_Child_ID'],
                        $assembly['Assembly_Child_Quantity'],
                        $assembly['Assembly_Level_Depth'],
                        $assembly['Notes']
                    ]);
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

            // Obter os valores enviados
            $assemFather = trim($_POST['assembly_father_id'] ?? '');
            $assemChild  = trim($_POST['assembly_child_id'] ?? '');
            $compFather = trim($_POST['component_father_id'] ?? '');
            $compChild  = trim($_POST['component_child_id'] ?? '');

            $assemblyLevel = 0;

            // Verificar se a assembly possui apenas componentes
            if ((!is_null($compFather) || $compFather !== '') && (!is_null($compChild) || $compChild !== '') && (is_null($assemFather) || $assemFather === '') && (is_null($assemChild) || $assemChild === '')) {
                $assemblyLevel = 0; // Assembly com apenas componentes
                error_log("Assembly possui apenas componentes. Nível definido como 0.");
            } else {
                // Verificar o maior nível das assemblies associadas
                $maxLevel = 0;

                if (!is_null($assemFather) || $assemFather !== '') {
                    $stmt = $pdo->prepare("SELECT Assembly_Level FROM T_Assembly WHERE Assembly_ID = ?");
                    $stmt->execute([$assemFather]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result) {
                        $maxLevel = max($maxLevel, (int)$result['Assembly_Level']);
                    }
                }

                if (!is_null($assemChild) || $assemChild !== '') {
                    $stmt = $pdo->prepare("SELECT Assembly_Level FROM T_Assembly WHERE Assembly_ID = ?");
                    $stmt->execute([$assemChild]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result) {
                        $maxLevel = max($maxLevel, (int)$result['Assembly_Level']);
                    }
                }

                // Definir o nível da nova assembly como o maior nível + 1
                $assemblyLevel = $maxLevel + 1;
                error_log("Assembly possui outras assemblies associadas. Nível definido como: " . $assemblyLevel);
            }
            // Verificar se é um protótipo ou montagem

            if (strpos($assemFather, 'prototype') !== false) {
                $assemFather = str_replace(' prototype', '', $assemFather);
                $stmt = $pdo->prepare("
                    SELECT a.Assembly_ID
                    FROM T_Assembly a
                    INNER JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
                    WHERE p.Prototype_ID = ?
                    ORDER BY a.Assembly_Level DESC
                    LIMIT 1
                ");
                $stmt->execute([(int)$assemFather]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    $assemFather = (int)$result['Assembly_ID'];
                    error_log("ID da assembly com o maior nível associado ao protótipo (assemFather): " . $assemFather);
                } else {
                    error_log("Nenhuma assembly encontrada para o protótipo com ID: " . $assemFather);
                    $assemFather = null; // Caso não encontre nenhuma assembly
                }
            }
            if (strpos($assemChild, 'prototype') !== false) {
                $assemChild = str_replace(' prototype', '', $assemChild);

                // Buscar o ID da assembly com o maior nível associada ao protótipo
                $stmt = $pdo->prepare("
                    SELECT a.Assembly_ID
                    FROM T_Assembly a
                    INNER JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
                    WHERE p.Prototype_ID = ?
                    ORDER BY a.Assembly_Level DESC
                    LIMIT 1
                ");
                $stmt->execute([(int)$assemChild]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    $assemChild = (int)$result['Assembly_ID'];
                    error_log("ID da assembly com o maior nível associado ao protótipo (assemChild): " . $assemChild);
                } else {
                    error_log("Nenhuma assembly encontrada para o protótipo com ID: " . $assemChild);
                    $assemChild = null; // Caso não encontre nenhuma assembly
                }
            }
       
            
            /*if ((!is_null($assemFather) || $assemFather !== '') && !is_numeric($assemFather)) {
                die("Erro: O valor de Assembly_Father_ID deve ser numérico.");
            }
            if ((!is_null($assemChild) || $assemChild !== '') && !is_numeric($assemChild)) {
                die("Erro: O valor de Assembly_Child_ID deve ser numérico.");
            }*/
            
            
            $valid = false;
            
            // Print debug information
            error_log("Valores recebidos: ");
            error_log("Component_Father_ID: " . $compFather);
            error_log("Component_Child_ID: " . $compChild);
            error_log("Assembly_Father_ID: " . $assemFather);
            error_log("Assembly_Child_ID: " . $assemChild);

            // Verificar combinações válidas de campos
            // Opção 1: Componente-filho e componente-pai
            if ($compFather !== '' && $compChild !== '' && $assemFather === '' && $assemChild === '') {
                $valid = true;
            }
            // Opção 2: Componente-pai e montagem-pai
            elseif (($compFather !== '' && $compFather !== null) && ($compChild === '' || $compChild === null) && ($assemFather !== '' && $assemFather !== null) && ($assemChild === '' || $assemChild === null)) {
                $valid = true;
            }

            // Opção 3: Montagem-filho e montagem-pai
            elseif ($compFather === '' && $compChild === '' && $assemFather !== '' && $assemChild !== '') {
                $valid = true;
            }
            
            if (!$valid) {
                die("Erro: Combinação inválida de campos para montagem.");
            }

            // Verificar se os campos estão vazios e definir como NULL

            $stmt = $pdo->prepare("
            INSERT INTO T_Assembly (
                Prototype_ID, Assembly_Designation, Component_Father_ID, Component_Father_Quantity, Component_Child_ID, 
                Component_Child_Quantity, Assembly_Father_ID, Assembly_Father_Quantity, Assembly_Child_ID, Assembly_Child_Quantity, Assembly_Level,
                Notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE  Assembly_Designation = VALUES(Assembly_Designation), Component_Father_Quantity=VALUES(Component_Father_Quantity), Component_Child_Quantity=VALUES(Component_Child_Quantity), Assembly_Father_Quantity=VALUES(Assembly_Father_Quantity), Assembly_Child_Quantity=VALUES(Assembly_Child_Quantity), Assembly_Level=VALUES(Assembly_Level), Notes=VALUES(Notes)");
            $stmt->execute([
                $_POST['prototype_id'], 
                $_POST['assembly_designation'] ?: null,
                (empty($_POST['component_father_id']) ? null : $_POST['component_father_id']),
                (empty($_POST['component_father_quantity']) ? 0 : $_POST['component_father_quantity']),
                (empty($_POST['component_child_id']) ? null : $_POST['component_child_id']),
                (empty($_POST['component_child_quantity']) ? 0 : $_POST['component_child_quantity']),
                (empty($assemFather)) ? null : $assemFather,
                (empty($_POST['assembly_father_quantity']) ? 0 : $_POST['assembly_father_quantity']),
                (empty($assemChild)) ? null : $assemChild,
                (empty($_POST['assembly_child_quantity']) ? 0 : $_POST['assembly_child_quantity']),
                (empty($assemblyLevel)) ? null : $assemblyLevel,
                $_POST['notes'],
            ]);

            $message = "Montagem criada/atualizada com sucesso!";
            header("Location: ?tab=bomlist/bomlist&entity=assembly");
            exit;

        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare("
                UPDATE T_Assembly 
                    SET 
                        Assembly_Designation = ?, 
                        Component_Father_ID = ?, 
                        Component_Father_Quantity = ?, 
                        Component_Child_ID = ?, 
                        Component_Child_Quantity = ?, 
                        Assembly_Father_ID = ?, 
                        Assembly_Father_Quantity = ?,
                        Assembly_Child_ID = ?, 
                        Assembly_Child_Quantity = ?, 
                        Assembly_Level_DepTH = ?, 
                        Notes = ?, 
                        Is_Prototype = ?
                    WHERE Assembly_ID = ?
                ");
                $stmt->execute([
                    $_POST['assembly_designation'] ?: null,
                    (empty($_POST['component_father_id']) ? null : $_POST['component_father_id']),
                    (empty($_POST['component_father_quantity']) ? 0 : $_POST['component_father_quantity']),
                    (empty($_POST['component_child_id']) ? null : $_POST['component_child_id']),
                    (empty($_POST['component_child_quantity']) ? 0 : $_POST['component_child_quantity']),
                    (empty($_POST['assembly_father_id']) ? null : $_POST['assembly_father_id']),
                    (empty($_POST['assembly_father_quantity']) ? 0 : $_POST['assembly_father_quantity']),
                    (empty($_POST['assembly_child_id']) ? null : $_POST['assembly_child_id']),
                    (empty($_POST['assembly_child_quantity']) ? 0 : $_POST['assembly_child_quantity']),
                    (empty($_POST['assembly_level_depth']) ? 0 : $_POST['assembly_level_depth']),
                    $_POST['notes'],
                    $_POST['is_prototype'] ?? 0, // Adiciona o valor do campo Is_Prototype
                    $_POST['id']
                ]);
                $message = "Montagem atualizada com sucesso!";
                header("Location: ?tab=bomlist/bomlist&entity=assembly");
                exit;


        } elseif ($action === 'delete' && isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM T_Assembly WHERE Assembly_ID=?");
            $stmt->execute([$_GET['id']]);
            $message = "Montagem eliminada com sucesso!";
            header("Location: ?tab=bomlist/bomlist&entity=assembly");
            exit;
        }
        break;
        
        default:
            $message = "Ação não reconhecida.";
            break;
    }
    return $message;
}
?>