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

            
            error_log("Assembly_Price: " . $assemblyPrice);

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


            $message = "Assembly criada com sucesso!";
            $status = 'ok';
            header(
                "Location: ?tab=bomlist/bomlist"
                . "&entity=assembly"
                . "&msg="   . urlencode($message)
                . "&status=". urlencode($status)
                );
            exit;

        } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {

            // Print debug information
            error_log("Valores recebidos: ");
            error_log("Component_Father_ID: " . $compFather);
            error_log("assembly_id: " . $assemFather);

            $parentAssemblyId = (int) $_POST['assembly_id']; // assembly principal (já existente)
            $stmtSelect = $pdo->prepare("SELECT Price FROM T_Assembly WHERE Assembly_ID = ?");
            $stmtSelect->execute([$parentAssemblyId]);
            $assemblyPrice = (float) $stmtSelect->fetchColumn();
            
            // Verifica se a associação é de componente ou de assembly ou delete
            $message = $_POST['remove_type'] ?? '';
            if(!empty($_POST['remove_type'])){
                if ($_POST['remove_type'] === 'component' && !empty($_POST['remove_component_id'])) {
                    // Remover associação de componente
                    $stmt = $pdo->prepare("DELETE FROM T_Assembly_Component WHERE Assembly_ID = ? AND Component_ID = ?");
                    $stmt->execute([$parentAssemblyId, $_POST['remove_component_id']]);
                    $stmt2 = $pdo->prepare("SELECT Price FROM T_Component WHERE Component_ID = ?");
                    $stmt2->execute([$_POST['remove_component_id']]);
                    $compPrice = (float) $stmt2->fetchColumn();
                    $assemblyPrice -= $compPrice;
                    updateAllAssemblyPrices($pdo, $parentAssemblyId , -$compPrice);
                    $message = "Associação de Assembly e Componente removida com sucesso!";
                } elseif ($_POST['remove_type'] === 'assembly' && !empty($_POST['remove_assembly_id'])) {
                    $toRemoveId = (int) $_POST['remove_assembly_id'];

                    // 1) Apagar recursivamente a sub-árvore seleccionada
                    deleteAssemblySubtree($pdo, $toRemoveId);

                    // 2) Apagar a associação pai→filho específica
                    $stmt = $pdo->prepare("
                    DELETE FROM T_Assembly_Assembly
                    WHERE Parent_Assembly_ID = ?
                        AND Child_Assembly_ID  = ?
                    ");
                    $stmt->execute([$parentAssemblyId, $toRemoveId]);
                                      $stmt2 = $pdo->prepare("SELECT Price FROM T_Assembly WHERE Assembly_ID = ?");
                    $stmt2->execute([$_POST['remove_assembly_id']]);
                    $assemPrice = (float) $stmt2->fetchColumn();
                    $assemblyPrice -= $assemPrice;
                    updateAllAssemblyPrices($pdo, $parentAssemblyId , -$assemPrice);

                    $message = "Todas as sub-assemblies e suas associações foram removidas com sucesso!";
                }
                else {
                    $url = "?tab=bomlist/bomlist&entity=assembly";
                    $status = "error";
                    $message = "Erro: Tipo de remoção inválido ou ID ausente.";
                }
            } elseif (!empty($_POST['component_father_id'])) {
                // Associação com componente:
                $compFatherRecord = findComponentById($components, $compFather);
                $assemblyId = $_POST['assembly_id']; // assembly principal (já existente)
                $componentId = $_POST['component_father_id'];
                $quantity = $_POST['component_quantity'] ?: 1;

                $priceCompFather = ($compFatherRecord !== null) ? getComponentPrice($compFatherRecord) * $compQty : 0;
                $assemblyPrice += $priceCompFather;
                // now we update all parent assemblies prices too
                updateAllAssemblyPrices($pdo, $assemblyId , $priceCompFather);

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
               
                $raw = $_POST['associated_assembly'];     // assembly filha
                $quantity = $_POST['assembly_quantity'] ?: 1;

                // Verifica se o ID da assembly pai é um protótipo
                if (strpos($raw, 'prototype') !== false) {
                    // Remove a string " prototype" e converte para inteiro
                    $protoId = (int) str_replace(' prototype', '', $raw);

                    // procurar o protótipo pai
                    $st = $pdo->prepare("SELECT Prototype_ID FROM T_Assembly WHERE Assembly_ID = ?");
                    $st->execute([$parentAssemblyId]);
                    $parentProtoId = (int)$st->fetchColumn();
                    
                    // se forem iguais, não deixa adicionar 
                    if ($parentProtoId === $protoId) {
                        $message = "Associação inválida: Não é possível associar um protótipo à sua própria assembly.";
                        $status  = "error";
                        header(
                            "Location: ?tab=bomlist/bomlist"
                        . "&entity=assembly"
                        . "&msg="   . urlencode($message)
                        . "&status=". urlencode($status)
                        );
                        exit;
                    }

                    // traz todas as assemblies de nível 0 (raízes) desse protótipo
                    $childAssemblyIds = getAssembliesByPrototypeAndLevel(
                        $pdo,
                        $protoId,
                        0
                    );

                    if(empty($childAssemblyIds)){
                        throw new RuntimeException("Não existem assemblies para o protótipo $protoId");
                    }

                    error_log("IDs de assemblies no nível {$parentLevel}: " . implode(', ', $childAssemblyIds));
                }
                else{
                    $childAssemblyIds = [ (int)$raw ];
                }

                foreach($childAssemblyIds as $childAssemblyId){
                    // para não deixar associar à própria assembly
                    if ($childAssemblyId === $parentAssemblyId) {
                        $message = "Associação inválida: não é possível associar uma assembly a si própria.";
                        $status  = "error";
                        header(
                            "Location: ?tab=bomlist/bomlist"
                        . "&entity=assembly"
                        . "&msg="   . urlencode($message)
                        . "&status=". urlencode($status)
                        );
                        exit;
                    }

                    // para verificar se não há recursividade infinita
                    if (checkInfRecursion($pdo, $childAssemblyId, $parentAssemblyId)) {
                        $childAssemb = findAssemblyById($assemblies, $childAssemblyId);
                        $fatherAssemb = findAssemblyById($assemblies, $parentAssemblyId);

                        $childName  = htmlspecialchars($childAssemb['Assembly_Designation'], ENT_QUOTES, 'UTF-8');
                        $parentName = htmlspecialchars($fatherAssemb['Assembly_Designation'], ENT_QUOTES, 'UTF-8');

                        $message = "Adição inválida [Ciclo infinito]: “{$parentName}” é sub-assembly de “{$childName}”.";
                    
                        $status  = "error";   // força a classe alert-danger
                        header(
                            "Location: ?tab=bomlist/bomlist"
                            ."&entity=assembly"
                            ."&msg="   . urlencode($message)
                            ."&status=". urlencode($status)
                        );
                        exit;
                    } else {
                        // Obter todos os IDs recursivamente para as relações existentes

                        $assemblyAssemblies = getAssemblyAssemblies($pdo, $childAssemblyId);
                        // Se houver assembly filho, obtém suas subassemblies
                        if (!empty($childAssemblyId)) {
                            $childAssembly = findAssemblyById($assemblies, $childAssemblyId);
                            error_log("childAssembly: " . print_r($childAssembly, true));
                        }

                        // Novo Prototype_ID para associar as subassemblies
                        $stmt = $pdo->prepare("SELECT Prototype_ID FROM T_Assembly WHERE Assembly_ID = ?");
                        $stmt->execute([$parentAssemblyId]);
                        $newPrototypeId = $stmt->fetchColumn();

                        //  Prototype_ID da assembly filha (a que será duplicada)
                        $stmt = $pdo->prepare("SELECT Prototype_ID FROM T_Assembly WHERE Assembly_ID = ?");
                        $stmt->execute([$childAssemblyId]);
                        $childPrototypeId = $stmt->fetchColumn();

                        // Se os Prototype_IDs forem diferentes, duplicamos; caso contrário, não duplicamos.
                        if ((int)$newPrototypeId !== (int)$childPrototypeId) {
                            $childAssemblyId = duplicateAssemblyTree($pdo, $childAssemblyId, (int)$newPrototypeId);
                        } else {
                            error_log("Prototype_ID da subassembly " . $childPrototypeId . " já é igual ao novo Prototype_ID: " . $newPrototypeId);
                        }

                        $priceAssemFather = ($childAssemblyId !== '' && !is_null($childAssemblyId)) ? getAssemblyPrice($childAssembly) * $quantity : 0;
                        $assemblyPrice += $priceAssemFather;
                        updateAllAssemblyPrices($pdo, $parentAssemblyId , $priceAssemFather);

                        $stmt = $pdo->prepare("
                            INSERT INTO T_Assembly_Assembly (Parent_Assembly_ID, Child_Assembly_ID, Quantity)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$parentAssemblyId, $childAssemblyId, $quantity]);

                        // ─── aqui definimos o nível da filha ───
                        // 1) encontrar nível do pai
                        $st1 = $pdo->prepare("SELECT Assembly_Level FROM T_Assembly WHERE Assembly_ID = ?");
                        $st1->execute([$parentAssemblyId]);
                        $parentLevel = (int)$st1->fetchColumn();
                        // 2) childLevel = parentLevel + 1
                        $childLevel = $parentLevel + 1;
                        // 3) grava na tabela
                        $st2 = $pdo->prepare("UPDATE T_Assembly SET Assembly_Level = ? WHERE Assembly_ID = ?");
                        $st2->execute([$childLevel, $childAssemblyId]);

                        $message = "Associação de Assembly criada com sucesso!";
                        // ─── Recalcular e atualizar o nível do assembly pai ───
                    }
                }
            } else {
                $message = "Nenhuma associação foi selecionada.";
                $status  = "error";
                header(
                    "Location: ?tab=bomlist/bomlist"
                    . "&entity=assembly"
                    . "&msg="   . urlencode($message)
                    . "&status=". urlencode($status)
                );
                exit;
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
            $message = "Assembly eliminada com sucesso!";
            header("Location: ?tab=bomlist/bomlist&entity=assembly"
            ."&msg="   . urlencode($message)
            ."&status=". urlencode($status));
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
                        "%$query%", "%$query%", "%$query%", "%$query%", 
                        "%$query%", "%$query%", "%$query%"
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
            
            // Store results globally instead of echoing
            $GLOBALS['search_results'] = $results ?? [];
            $GLOBALS['search_query'] = $query;
            $GLOBALS['search_area'] = $area;
        }
        break;

    default:
            $message = "Ação não reconhecida.";
            $status  = "error";
            header(
                "Location: ?tab=bomlist/bomlist"
              . "&entity=component"
              . "&msg="   . urlencode($message)
              . "&status=". urlencode($status)
            );
            break;
    }
    } catch (Exception $e) {
        // log server-side e devolve mensagem genérica
        error_log("processor error: " . $e->getMessage());
        $message = "Erro no processamento: " . $e->getMessage();
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
