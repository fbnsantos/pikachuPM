<?php

// Desativar impressão de warnings no browser (logs continuam)
//ini_set('display_errors', '0');
//ini_set('log_errors', '1');
//error_reporting(E_ALL);

$GLOBALS['last_created'] = null;

require_once 'getters.php';
require_once 'helpers.php';

function processCRUD($pdo, $entity , $action){
    $message = "";
    try {   
    switch ($entity) {
    case 'manufacturers':
        if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $denomination   = $_POST['denomination']   ?? null;
            $origin_country = $_POST['origin_country'] ?? null;
            $website        = $_POST['website']        ?? null;
            $contacts       = $_POST['contacts']       ?? null;
            $morada         = $_POST['morada']         ?? null;
            $notes          = $_POST['notes']          ?? null;
            
            // evitar duplicados pelo mesmo nome
            $chk = $pdo->prepare("SELECT Manufacturer_ID FROM T_Manufacturer WHERE Denomination = ?");
            $chk->execute([$denomination]);
            if ($chk->fetch()) {
                $message = "Fabricante já existe.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO T_Manufacturer (Denomination, Origin_Country, Website, Contacts, Address , Notes) VALUES (?, ?, ?, ?,?,?)");
                $stmt->execute([$denomination, $origin_country, $website, $contacts, $morada, $notes]);
                $lastId = $pdo->lastInsertId();
                $message = "Fabricante criado com sucesso!";
                $GLOBALS['last_created'] = ['entity'=>'manufacturers','id'=>$lastId,'denomination'=>$denomination];
            }
        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $denomination   = $_POST['denomination']   ?? null;
            $origin_country = $_POST['origin_country'] ?? null;
            $website        = $_POST['website']        ?? null;
            $contacts       = $_POST['contacts']       ?? null;
            $morada         = $_POST['morada']         ?? null;
            $notes          = $_POST['notes']          ?? null;
            $id             = $_POST['id']             ?? null;

            $stmt = $pdo->prepare("UPDATE T_Manufacturer SET Denomination=?, Origin_Country=?, Website=?, Contacts=?, Address=?, Notes=? WHERE Manufacturer_ID=?");
            $stmt->execute([$denomination, $origin_country, $website, $contacts, $morada, $notes, $id]);
            $message = "Fabricante atualizado com sucesso!";
        } elseif ($action === 'delete' && isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM T_Manufacturer WHERE Manufacturer_ID=?");
            $stmt->execute([$_GET['id']]);
            $message = "Fabricante eliminado com sucesso!";
        }
        break;
        
    case 'suppliers':
            if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $denomination   = $_POST['denomination']   ?? null;
            $origin_country = $_POST['origin_country'] ?? null;
            $website        = $_POST['website']        ?? null;
            $contacts       = $_POST['contacts']       ?? null;
            $morada         = $_POST['morada']         ?? null;
            $notes          = $_POST['notes']          ?? null;

            // evitar duplicados pelo mesmo nome
            $chk = $pdo->prepare("SELECT Supplier_ID FROM T_Supplier WHERE Denomination = ?");
            $chk->execute([$denomination]);
            if ($chk->fetch()) {
                $message = "Fornecedor já existe.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO T_Supplier (Denomination, Origin_Country, Website, Contacts , Address , Notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$denomination, $origin_country, $website, $contacts, $morada, $notes]);
                $lastId = $pdo->lastInsertId();
                $message = "Fornecedor criado com sucesso!";
                // guarda info do criado para a resposta AJAX
                $GLOBALS['last_created'] = [
                    'entity' => 'suppliers',
                    'id' => $lastId,
                    'denomination' => $denomination
                ];
            }
        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $denomination   = $_POST['denomination']   ?? null;
            $origin_country = $_POST['origin_country'] ?? null;
            $website        = $_POST['website']        ?? null;
            $contacts       = $_POST['contacts']       ?? null;
            $morada         = $_POST['morada']         ?? null;
            $notes          = $_POST['notes']          ?? null;
            $id             = $_POST['id']             ?? null;

            $stmt = $pdo->prepare("UPDATE T_Supplier SET Denomination=?, Origin_Country=?, Website=?, Contacts=?, Address=?, Notes=? WHERE Supplier_ID=?");
            $stmt->execute([$denomination, $origin_country, $website, $contacts, $morada, $notes, $id]);
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
                            assembly_id, 
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
                        $assembly['Assembly_id'],
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
            if (empty($_POST['manufacturer_id']) && empty($_POST['supplier_id'])) {
                die("Erro: é necessário vincular pelo menos um Fabricante ou Fornecedor.");
            }

            // Captura o valor inserido no input de referência manual
            $compFatherCustomRef = trim($_POST['component_father_custom_ref'] ?? '');

            // Se houver valor, tentativa de encontrar o componente pela referência
            if (!empty($compFatherCustomRef)) {
                // Função auxiliar para buscar por referência (implemente-a se ainda não existir)
                $compFatherRecord = getComponentByReference($components, $compFatherCustomRef);
                if ($compFatherRecord) {
                    // Sobrescreve o ID selecionado pelo select com o ID encontrado
                    $compFather = $compFatherRecord['Component_ID'];
                } else {
                    // Se não encontrar, pode lançar um erro ou definir como null
                    die("Erro: Nenhum componente encontrado com a referência \"$compFatherCustomRef\".");
                }
            }

            $reference = generateComponentReference($pdo, $_POST['general_type']);

            $stmt = $pdo->prepare("INSERT INTO T_Component (Denomination, Reference, Manufacturer_ID, Manufacturer_ref, Supplier_ID, Supplier_ref, General_Type, Price, Acquisition_Date, Notes_Description, Stock_Quantity, Min_Stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['denomination'], 
                $reference,
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
            $message = "Componente criado com sucesso! Referência: $reference";

        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Recupera a referência atual do componente
            $stmtSelect = $pdo->prepare("SELECT Reference FROM T_Component WHERE Component_ID = ?");
            $stmtSelect->execute([$_POST['id']]);
            $currentReference = $stmtSelect->fetchColumn();

            $stmt = $pdo->prepare("UPDATE T_Component SET Denomination=?, Reference=?, Manufacturer_ID=?, Manufacturer_ref=?, Supplier_ID=?, Supplier_ref=?, General_Type=?, Price=?, Acquisition_Date=?, Notes_Description=?, Stock_Quantity=?, Min_Stock=? WHERE Component_ID=?");
            $stmt->execute([
                $_POST['denomination'],
                $currentReference, 
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

        $assemblies = getAssemblies($pdo);
        $components = getComponents($pdo);
        
        // Obter os valores enviados
        $assemFather = trim($_POST['assembly_id'] ?? '');
        $compFather = trim($_POST['component_father_id'] ?? '');

        
        // Obter quantidades
        $compQty = trim($_POST['component_quantity'] ?? 0);
        $assemFatherQty = trim($_POST['assembly_quantity'] ?? 0);

        // Print debug information
        error_log("Valores recebidos: ");
        error_log("Component_Father_ID: " . $compFather);

        error_log("assembly_id: " . $assemFather);


        if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {

            $assemblyLevel = 0;
            $assemblyPrice = 0;
            
            if (!is_null($assemFather) || $assemFather !== '') {
                $stmt = $pdo->prepare("SELECT Assembly_Level FROM T_Assembly WHERE Assembly_ID = ?");
                $stmt->execute([$assemFather]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $maxLevel = max($maxLevel, (int)$result['Assembly_Level']);
                }
            }
                
            // Definir o nível da nova assembly como o maior nível + 1
            //$assemblyLevel = $maxLevel + 1;
            error_log("Assembly criada. Nível definido como: " . $assemblyLevel);

            
            // Obter todos os IDs recursivamente para as relações existentes
            $allSubIDs = [];

            // Se houver assembly pai, obtém suas subassemblies
            if (!empty($assemFather)) {
                $fatherAssembly = findAssemblyById($assemblies, $assemFather);
                if ($fatherAssembly) {
                    $allSubIDs = array_merge($allSubIDs, getAllSubAssemblyIDs($assemblies, $fatherAssembly));
                }
            }

            // Se houver assembly filho, obtém suas subassemblies
            if (!empty($assemChild)) {
                $childAssembly = findAssemblyById($assemblies, $assemChild);
                if ($childAssembly) {
                    $allSubIDs = array_merge($allSubIDs, getAllSubAssemblyIDs($assemblies, $childAssembly));
                }
            }
            error_log("IDs das assemblies recursivas: " . print_r($allSubIDs, true));

            
            error_log("Assembly_Price: " . $assemblyPrice);
            $valid = false;

            $stmt = $pdo->prepare("
                INSERT INTO T_Assembly (
                    Prototype_ID, 
                    Assembly_Designation,
                    Assembly_Reference,
                    Assembly_Level,
                    Price,
                    Notes
                ) VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE  
                    Assembly_Designation = VALUES(Assembly_Designation),
                    Assembly_Level = VALUES(Assembly_Level),
                    Price = VALUES(Price), 
                    Notes = VALUES(Notes),
                    Assembly_Reference = VALUES(Assembly_Reference)
            ");

            $stmt->execute([
                $_POST['prototype_id'],
                $_POST['assembly_designation'] ?: null,
                generateAssemblyReference($pdo, $_POST['prototype_id'], $_POST['assembly_designation']),
                (empty($assemblyLevel)) ? 0 : $assemblyLevel,
                $assemblyPrice, 
                $_POST['notes']
            ]);

            // Preparar a query para selecionar os dados da subassembly original
            $stmtSelect = $pdo->prepare("SELECT * FROM T_Assembly WHERE Assembly_ID = ?");

            // Preparar a query para inserir a nova subassembly duplicada com o novo Prototype_ID
            $stmtInsert = $pdo->prepare("
                INSERT INTO T_Assembly (
                Prototype_ID, 
                Assembly_Designation,
                Assembly_Reference,
                Assembly_Level,
                Price,
                Notes,
                Created_Date
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            // Novo Prototype_ID para o qual você quer associar as subassemblies
            $newPrototypeID = $_POST['prototype_id'];

            if (!empty($allSubIDs)) {
                foreach ($allSubIDs as $subAssemblyID) {
                    // Buscar registro da subassembly original
                    $stmtSelect->execute([$subAssemblyID]);
                    $subAssembly = $stmtSelect->fetch(PDO::FETCH_ASSOC);
                        if ($subAssembly) {
                            // Verificar se o Prototype_ID da subassembly é diferente do novoPrototypeID
                            if ((int)$newPrototypeID !== (int)$subAssembly['Prototype_ID']) {
                                // Inserir o registro duplicado com o novo Prototype_ID
                                $stmtInsert->execute([
                                    $newPrototypeID,
                                    $subAssembly['Assembly_Designation'],
                                    $subAssembly['Component_Father_ID'],
                                    $subAssembly['Component_Father_Quantity'],
                                    $subAssembly['Component_Child_ID'],
                                    $subAssembly['Component_Child_Quantity'],
                                    $subAssembly['assembly_id'],
                                    $subAssembly['Assembly_Father_Quantity'],
                                    $subAssembly['Assembly_Child_ID'],
                                    $subAssembly['Assembly_Child_Quantity'],
                                    $subAssembly['Assembly_Level'],
                                    $subAssembly['Price'],
                                    $subAssembly['Notes']
                                ]);
                            } else {
                                error_log("Prototype_ID da subassembly " . $subAssembly['Assembly_ID'] . " já é igual ao novo Prototype_ID.");
                            }
                        }
                }
            }

            $message = "Montagem criada/atualizada com sucesso!";
            header("Location: ?tab=bomlist/bomlist&entity=assembly");
            exit;

        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {

            $parentAssemblyId = (int) $_POST['assembly_id']; // assembly principal (já existente)
            $stmtSelect = $pdo->prepare("SELECT Price FROM T_Assembly WHERE Assembly_ID = ?");
            $stmtSelect->execute([$parentAssemblyId]);
            $assemblyPrice = (float) $stmtSelect->fetchColumn();
            
            // Verifica se a associação é de componente ou de assembly
            if (!empty($_POST['component_father_id'])) {
                // Associação com componente:
                $compFatherRecord = findComponentById($components, $compFather);
                $assemblyId = $_POST['assembly_id']; // assembly principal (já existente)
                $componentId = $_POST['component_father_id'];
                $quantity = $_POST['component_quantity'] ?: 1;

                $priceCompFather = ($compFatherRecord !== null) ? getComponentPrice($compFatherRecord) * $compQty : 0;
                $assemblyPrice += $priceCompFather;

                $stmt = $pdo->prepare("
                    INSERT INTO T_Assembly_Component (Assembly_ID, Component_ID, Quantity)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$assemblyId, $componentId, $quantity]);
                $message = "Associação de Componente criada com sucesso!";
            } 
            elseif (!empty($_POST['associated_assembly']))
            {
                // Associação com outra assembly:
               
                $childAssemblyId  = (int) $_POST['associated_assembly'];     // assembly filha
                $quantity = $_POST['assembly_quantity'] ?: 1;

                if (checkInfRecursion($pdo, $childAssemblyId, $parentAssemblyId)) {
                    $message = "Adição Inválida: Recursividade Infinita";
                } else {
                    // Verifica se o ID da assembly pai é um protótipo
                    if (strpos($childAssemblyId, 'prototype') !== false) {
                        // Remove a string " prototype" e converte para inteiro
                        $childAssemblyId = str_replace(' prototype', '', $childAssemblyId);

                        // Busca o ID da assembly com o maior nível associado ao protótipo recebido
                        $stmt = $pdo->prepare("
                            SELECT a.Assembly_ID
                            FROM T_Assembly a
                            INNER JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
                            WHERE p.Prototype_ID = ?
                            ORDER BY a.Assembly_Level DESC
                            LIMIT 1
                        ");
                        $stmt->execute([(int)$childAssemblyId]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($result) {
                            $childAssemblyId = (int)$result['Assembly_ID'];
                            error_log("ID da assembly com o maior nível associado ao protótipo (childAssemblyId): " . $childAssemblyId);
                        } else {
                            error_log("Nenhuma assembly encontrada para o protótipo com ID: " . $childAssemblyId);
                            $childAssemblyId = null; // Caso não encontre nenhuma assembly
                        }
                    }
                    $priceAssemFather = ($childAssemblyId !== '' && !is_null($childAssemblyId)) ? getAssemblyPrice(findAssemblyById($assemblies, $childAssemblyId)) * $quantity : 0;
                    $assemblyPrice += $priceAssemFather;
                    $stmt = $pdo->prepare("
                        INSERT INTO T_Assembly_Assembly (Parent_Assembly_ID, Child_Assembly_ID, Quantity)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$parentAssemblyId, $childAssemblyId, $quantity]);
                    $message = "Associação de Assembly criada com sucesso!";
                }
            } else {
                $message = "Nenhuma associação foi especificada.";
            }
            
            error_log("Assembly_Price: " . $assemblyPrice);
            
            $stmt = $pdo->prepare("UPDATE T_Assembly SET Price = ? WHERE Assembly_ID = ?");
            $stmt->execute([$assemblyPrice, $parentAssemblyId]);

            error_log("Preço da montagem atualizado: " . $assemblyPrice);

            $status = 'ok';
            header("Location: ?tab=bomlist/bomlist&entity=assembly"
            ."&msg="   . urlencode($message)
            ."&status=". urlencode($status));
            exit;
        }
        elseif ($action === 'delete' && isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM T_Assembly WHERE Assembly_ID=?");
            $stmt->execute([$_GET['id']]);
            $message = "Montagem eliminada com sucesso!";
            header("Location: ?tab=bomlist/bomlist&entity=assembly");
            exit;
        }
        break;
        
    case 'search':
        if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET'){
            $query = $_GET['query'] ?? '';
            $area = $_GET['area'] ?? '';

            switch ($area) {
                case 'components':
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT c.*, 
                               m.Denomination as Manufacturer_Name,
                               s.Denomination as Supplier_Name
                        FROM T_Component c
                        LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID
                        LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID
                        WHERE c.Denomination LIKE ? 
                           OR c.Reference LIKE ? 
                           OR c.Manufacturer_ref LIKE ? 
                           OR c.Supplier_ref LIKE ?
                           OR m.Denomination LIKE ?
                           OR s.Denomination LIKE ?
                           OR c.Component_ID LIKE ?
                    ");
                    $stmt->execute([
                        "%$query%",  // Component denomination
                        "%$query%",  // Component reference
                        "%$query%",  // Manufacturer reference
                        "%$query%",  // Supplier reference
                        "%$query%",  // Manufacturer denomination
                        "%$query%",  // Supplier denomination
                        "%$query%"   // Component ID
                    ]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                    
                case 'assemblies':
                    $stmt = $pdo->prepare("SELECT * FROM T_Assembly WHERE Assembly_Designation LIKE ? OR Assembly_ID LIKE ? OR Assembly_Reference LIKE ?");
                    $stmt->execute(["%$query%", "%$query%", "%$query%"]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                    
                case 'manufacturers':
                    $stmt = $pdo->prepare("SELECT * FROM T_Manufacturer WHERE Denomination LIKE ? OR Origin_Country LIKE ? OR Address LIKE ? OR Manufacturer_ID LIKE ?");
                    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                    
                case 'suppliers':
                    $stmt = $pdo->prepare("SELECT * FROM T_Supplier WHERE Denomination LIKE ? OR Origin_Country LIKE ? OR Address LIKE ? OR Supplier_ID LIKE ?");
                    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                    
                case 'prototypes':
                    $stmt = $pdo->prepare("SELECT * FROM T_Prototype WHERE Name LIKE ? OR Description LIKE ? OR Status LIKE ? OR Prototype_ID LIKE ?");
                    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
            }
            
            // Display search results
            if (!empty($results)) {
                echo "<div class='row mt-4'>";
                echo "<div class='col-12'>";
                echo "<div class='card'>";
                echo "<div class='card-header'>";
                echo "<h5><i class='bi bi-search'></i> Resultados da Pesquisa (" . count($results) . ")</h5>";
                echo "</div>";
                echo "<div class='card-body'>";
                
                foreach ($results as $row) {
                    echo "<div class='border-bottom pb-2 mb-2'>";
                    
                    if ($area === 'components') {
                        echo "<h6>" . htmlspecialchars($row['Denomination']) . " <small class='text-muted'>(" . htmlspecialchars($row['Reference']) . ")</small></h6>";
                        if (!empty($row['Manufacturer_Name'])) {
                            echo "<small class='text-muted'>Fabricante: " . htmlspecialchars($row['Manufacturer_Name']) . "</small><br>";
                        }
                        if (!empty($row['Supplier_Name'])) {
                            echo "<small class='text-muted'>Fornecedor: " . htmlspecialchars($row['Supplier_Name']) . "</small><br>";
                        }
                        echo "<p class='mb-0'>" . htmlspecialchars($row['Notes_Description'] ?? '') . "</p>";
                    } else {
                        echo "<h6>" . htmlspecialchars($row['Denomination'] ?? $row['Name'] ?? $row['Assembly_Designation']) . "</h6>";
                        echo "<p class='mb-0'>" . htmlspecialchars($row['Notes'] ?? $row['Description'] ?? '') . "</p>";
                    }
                    
                    echo "</div>";
                }
                
                echo "</div>";
                echo "</div>";
                echo "</div>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-info mt-4'>";
                echo "<i class='bi bi-info-circle'></i> Nenhum resultado encontrado para '<strong>" . htmlspecialchars($query) . "</strong>' em " . ucfirst($area) . ".";
                echo "</div>";
            }
        }
        break;

    default:
            $message = "Ação não reconhecida.";
            break;
    }
    } catch (Exception $e) {
        // log server-side e devolve mensagem genérica
        error_log("processor error: " . $e->getMessage());
        $message = "Erro no processamento (ver logs).";
    }
    return $message;

    

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['entity']) && isset($_POST['action']))) {
    require_once 'database/database.php';
    $pdo = connectDB();
    $entity = $_POST['entity'];
    $action = $_POST['action'];
    $message = processCRUD($pdo, $entity, $action);

    // resposta JSON para AJAX — inclui created se existir
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        $response = [
            'status' => $message ? 'ok' : 'error',
            'message' => $message
        ];
        if (!empty($GLOBALS['last_created'])) {
            $response['created'] = $GLOBALS['last_created'];
        }
        echo json_encode($response);
        exit;
    }

    // fallback: redirect de volta para a página que enviou o form (evita sempre ir para components)
    $return = $_POST['return'] ?? $_SERVER['HTTP_REFERER'] ?? null;
    $allowed = ['manufacturers','suppliers','components','assembly','prototypes','search'];
    $defaultEntity = in_array($entity, $allowed, true) ? $entity : 'manufacturers';
    $default = '?tab=bomlist/bomlist&entity=' . $defaultEntity;

    // validar / normalizar $return — permitir URLs relativas ('?' ou '/' ou referer do mesmo host
    $validReturn = $default;
    if (!empty($return)) {
        $r = trim($return);
        if ($r !== '') {
            if ($r[0] === '?' || $r[0] === '/') {
                $validReturn = $r;
            } else {
                $parts = parse_url($r);
                if (!empty($parts['host']) && $parts['host'] === $_SERVER['HTTP_HOST']) {
                    $validReturn = $r;
                }
            }
        }
    }

    $sep = (strpos($validReturn, '?') === false) ? '?' : '&';

    // determinar status para o redirect (ok / warning / error)
    $status = 'ok';
    if (empty($message)) {
        $status = 'error';
    } elseif (stripos($message, 'já existe') !== false || stripos($message, 'já existe') !== false) {
        $status = 'warning';
    } elseif (stripos($message, 'erro') !== false) {
        $status = 'error';
    }
    
    header('Location: ' . $validReturn . $sep . 'msg=' . urlencode($message));
    exit;
}

?>