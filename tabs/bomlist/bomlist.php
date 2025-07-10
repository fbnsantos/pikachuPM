<?php
// tabs/bomlist.php
// Sistema de Gestão de Bill of Materials (BOM)

// Incluir configuração da base de dados
// Incluir arquivo de configuração
include_once __DIR__ . '/../../PWA/RestAPI/config.php';

require_once 'getters.php';

// Configurar conexão com o banco de dados
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Estabelecer conexão com a base de dados
$sqlFile = __DIR__ . '/database/database.sql';
$sql = file_get_contents($sqlFile);

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Split and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if ($statement) {
            $pdo->exec($statement);
        }
    }
    echo "Importação concluída!";
} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage();
}



// Processar ações CRUD
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$entity = $_POST['entity'] ?? $_GET['entity'] ?? 'components';
$message = '';



// Processar ações CRUD baseadas no entity e action
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
                            Component_Child_ID, 
                            Component_Quantity, 
                            Assembly_Father_ID, 
                            Assembly_Child_ID, 
                            Assembly_Quantity, 
                            Assembly_Level_Depth, 
                            Notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newPrototypeId,
                        $assembly['Assembly_Designation'],
                        $assembly['Component_Father_ID'],
                        $assembly['Component_Child_ID'],
                        $assembly['Component_Quantity'],
                        $assembly['Assembly_Father_ID'],
                        $assembly['Assembly_Child_ID'],
                        $assembly['Assembly_Quantity'],
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
            $compFather = trim($_POST['component_father_id'] ?? '');
            $compChild  = trim($_POST['component_child_id'] ?? '');
            $assemFather = trim($_POST['assembly_father_id'] ?? '');
            $assemChild  = trim($_POST['assembly_child_id'] ?? '');

            $valid = false;
        
        // Opção 1: Componente-filho e componente-pai
        if ($compFather !== '' && $compChild !== '' && $assemFather === '' && $assemChild === '') {
            $valid = true;
        }
        // Opção 2: Componente-filho e montagem-pai
        elseif ($compFather === '' && $compChild !== '' && $assemFather !== '' && $assemChild === '') {
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
            
            $stmt = $pdo->prepare("INSERT INTO T_Assembly (Prototype_ID, Assembly_Designation, Component_Father_ID, Component_Child_ID, Component_Quantity, Assembly_Father_ID, Assembly_Child_ID, Assembly_Quantity, Notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE  Assembly_Designation = VALUES(Assembly_Designation), Component_Quantity=VALUES(Component_Quantity),Assembly_Quantity=VALUES(Assembly_Quantity), Notes=VALUES(Notes)");
            $stmt->execute([
                $_POST['prototype_id'], 
                $_POST['assembly_designation'] ?: null,
                (empty($_POST['component_father_id']) ? null : $_POST['component_father_id']),
                (empty($_POST['component_child_id']) ? null : $_POST['component_child_id']),
                (empty($_POST['component_quantity']) ? 0 : $_POST['component_quantity']),
                (empty($_POST['assembly_father_id']) ? null : $_POST['assembly_father_id']),
                (empty($_POST['assembly_child_id']) ? null : $_POST['assembly_child_id']),
                (empty($_POST['assembly_quantity']) ? 0 : $_POST['assembly_quantity']), 
                $_POST['notes']
            ]);
            $message = "Montagem criada/atualizada com sucesso!";
        } elseif ($action === 'delete' && isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM T_Assembly WHERE Assembly_ID=?");
            $stmt->execute([$_GET['id']]);
            $message = "Montagem eliminada com sucesso!";
        }
        break;
}

// Buscar dados para exibição
$manufacturers = getManufacturers($pdo);
$suppliers = getSuppliers($pdo);
$prototypes = getPrototypes($pdo);
$components = getComponents($pdo);
$assemblies = getAssemblies($pdo);

// Buscar assemblies com informações detalhadas
/*$stmt = $pdo->query("
    SELECT a.*, 
           p.Name as Prototype_Name,
           p.Version as Prototype_Version,
           cf.Denomination as Father_Name,
           cc.Denomination as Child_Name
    FROM T_Assembly a
    JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
    LEFT JOIN T_Component cf ON a.Component_Father_ID = cf.Component_ID
    JOIN T_Component cc ON a.Component_Child_ID = cc.Component_ID
    ORDER BY p.Name, p.Version, cf.Denomination, cc.Denomination
");
$assemblies = $stmt->fetchAll(PDO::FETCH_ASSOC); */
?>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-list-ul"></i> Sistema de Gestão BOM FFF (Bill of Materials)</h2>
            
            <!-- Navigation tabs -->
            <ul class="nav nav-tabs" id="bomTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'components' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=components'">
                        <i class="bi bi-cpu"></i> Componentes
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'prototypes' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=prototypes'">
                        <i class="bi bi-diagram-3"></i> Protótipos
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'assembly' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=assembly'">
                        <i class="bi bi-diagram-2"></i> Montagem (BOM)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'manufacturers' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=manufacturers'">
                        <i class="bi bi-building"></i> Fabricantes
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'suppliers' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=suppliers'">
                        <i class="bi bi-truck"></i> Fornecedores
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <!-- Content based on selected entity -->
    <?php if ($entity === 'components'): ?>
        <!-- COMPONENTES -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-plus-circle"></i> 
                            <?= ($action === 'edit' && isset($_GET['id'])) ? 'Editar' : 'Novo' ?> Componente
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $editComponent = null;
                        if ($action === 'edit' && isset($_GET['id'])) {
                            $stmt = $pdo->prepare("SELECT * FROM T_Component WHERE Component_ID=?");
                            $stmt->execute([$_GET['id']]);
                            $editComponent = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="<?= $editComponent ? 'update' : 'create' ?>">
                            <input type="hidden" name="entity" value="components">
                            <?php if ($editComponent): ?>
                                <input type="hidden" name="id" value="<?= $editComponent['Component_ID'] ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="denomination" class="form-label">Denominação *</label>
                                <input type="text" class="form-control" name="denomination" required 
                                       value="<?= $editComponent ? htmlspecialchars($editComponent['Denomination']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="general_type" class="form-label">Tipo Geral</label>
                                <input type="text" class="form-control" name="general_type" 
                                       value="<?= $editComponent ? htmlspecialchars($editComponent['General_Type']) : '' ?>">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="manufacturer_id" class="form-label">Fabricante</label>
                                        <select class="form-select" name="manufacturer_id">
                                            <option value="">Selecionar...</option>
                                            <?php foreach ($manufacturers as $manufacturer): ?>
                                                <option value="<?= $manufacturer['Manufacturer_ID'] ?>" 
                                                        <?= ($editComponent && $editComponent['Manufacturer_ID'] == $manufacturer['Manufacturer_ID']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($manufacturer['Denomination']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="manufacturer_ref" class="form-label">Ref. Fabricante</label>
                                        <input type="text" class="form-control" name="manufacturer_ref" 
                                               value="<?= $editComponent ? htmlspecialchars($editComponent['Manufacturer_ref']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="supplier_id" class="form-label">Fornecedor</label>
                                        <select class="form-select" name="supplier_id">
                                            <option value="">Selecionar...</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?= $supplier['Supplier_ID'] ?>" 
                                                        <?= ($editComponent && $editComponent['Supplier_ID'] == $supplier['Supplier_ID']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($supplier['Denomination']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="supplier_ref" class="form-label">Ref. Fornecedor</label>
                                        <input type="text" class="form-control" name="supplier_ref" 
                                               value="<?= $editComponent ? htmlspecialchars($editComponent['Supplier_ref']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="price" class="form-label">Preço (€)</label>
                                        <input type="number" step="0.01" class="form-control" name="price" 
                                               value="<?= $editComponent ? $editComponent['Price'] : '' ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="acquisition_date" class="form-label">Data Aquisição</label>
                                        <input type="date" class="form-control" name="acquisition_date" 
                                               value="<?= $editComponent ? $editComponent['Acquisition_Date'] : '' ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="stock_quantity" class="form-label">Stock Atual</label>
                                        <input type="number" class="form-control" name="stock_quantity" 
                                               value="<?= $editComponent ? $editComponent['Stock_Quantity'] : '0' ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="min_stock" class="form-label">Stock Mínimo</label>
                                        <input type="number" class="form-control" name="min_stock" 
                                               value="<?= $editComponent ? $editComponent['Min_Stock'] : '0' ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes_description" class="form-label">Descrição/Notas</label>
                                <textarea class="form-control" name="notes_description" rows="3"><?= $editComponent ? htmlspecialchars($editComponent['Notes_Description']) : '' ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 
                                <?= $editComponent ? 'Atualizar' : 'Criar' ?> Componente
                            </button>
                            
                            <?php if ($editComponent): ?>
                                <a href="?tab=bomlist/bomlist&entity=components" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list"></i> Lista de Componentes (<?= count($components) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Denominação</th>
                                        <th>Tipo</th>
                                        <th>Fabricante</th>
                                        <th>Fornecedor</th>
                                        <th>Preço</th>
                                        <th>Stock</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($components as $component): ?>
                                        <tr class="<?= ($component['Stock_Quantity'] <= $component['Min_Stock']) ? 'table-warning' : '' ?>">
                                            <td><?= $component['Component_ID'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($component['Denomination']) ?></strong>
                                                <?php if ($component['Notes_Description']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars(substr($component['Notes_Description'], 0, 50)) ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($component['General_Type']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($component['Manufacturer_Name']) ?>
                                                <?php if ($component['Manufacturer_ref']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($component['Manufacturer_ref']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($component['Supplier_Name']) ?>
                                                <?php if ($component['Supplier_ref']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($component['Supplier_ref']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $component['Price'] ? number_format($component['Price'], 2) . '€' : '-' ?></td>
                                            <td>
                                                <span class="badge <?= ($component['Stock_Quantity'] <= $component['Min_Stock']) ? 'bg-warning' : 'bg-success' ?>">
                                                    <?= $component['Stock_Quantity'] ?>/<?= $component['Min_Stock'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="?tab=bomlist/bomlist&entity=components&action=edit&id=<?= $component['Component_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="?tab=bomlist/bomlist&entity=components&action=delete&id=<?= $component['Component_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Tem a certeza que deseja eliminar este componente?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($entity === 'prototypes'): ?>
        <!-- PROTÓTIPOS -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-plus-circle"></i> 
                            <?= ($action === 'edit' && isset($_GET['id'])) ? 'Editar' : 'Novo' ?> Protótipo
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $editPrototype = null;
                        if ($action === 'edit' && isset($_GET['id'])) {
                            $stmt = $pdo->prepare("SELECT * FROM T_Prototype WHERE Prototype_ID=?");
                            $stmt->execute([$_GET['id']]);
                            $editPrototype = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="<?= $editPrototype ? 'update' : 'create' ?>">
                            <input type="hidden" name="entity" value="prototypes">
                            <?php if ($editPrototype): ?>
                                <input type="hidden" name="id" value="<?= $editPrototype['Prototype_ID'] ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Nome *</label>
                                <input type="text" class="form-control" name="name" required 
                                       value="<?= $editPrototype ? htmlspecialchars($editPrototype['Name']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="version" class="form-label">Versão</label>
                                <input type="text" class="form-control" name="version" 
                                       value="<?= $editPrototype ? htmlspecialchars($editPrototype['Version']) : '1.0' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Estado</label>
                                <select class="form-select" name="status">
                                    <option value="Development" <?= ($editPrototype && $editPrototype['Status'] === 'Development') ? 'selected' : '' ?>>Desenvolvimento</option>
                                    <option value="Testing" <?= ($editPrototype && $editPrototype['Status'] === 'Testing') ? 'selected' : '' ?>>Teste</option>
                                    <option value="Production" <?= ($editPrototype && $editPrototype['Status'] === 'Production') ? 'selected' : '' ?>>Produção</option>
                                    <option value="Archived" <?= ($editPrototype && $editPrototype['Status'] === 'Archived') ? 'selected' : '' ?>>Arquivado</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Descrição</label>
                                <textarea class="form-control" name="description" rows="4"><?= $editPrototype ? htmlspecialchars($editPrototype['Description']) : '' ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 
                                <?= $editPrototype ? 'Atualizar' : 'Criar' ?> Protótipo
                            </button>
                            
                            <?php if ($editPrototype): ?>
                                <a href="?tab=bomlist/bomlist&entity=prototypes" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list"></i> Lista de Protótipos (<?= count($prototypes) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>Versão</th>
                                        <th>Estado</th>
                                        <th>Criado</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($prototypes as $prototype): ?>
                                        <tr>
                                            <td><?= $prototype['Prototype_ID'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($prototype['Name']) ?></strong>
                                                <?php if ($prototype['Description']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars(substr($prototype['Description'], 0, 50)) ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-info"><?= htmlspecialchars($prototype['Version']) ?></span></td>
                                            <td>
                                                <?php 
                                                $statusClass = [
                                                    'Development' => 'bg-primary',
                                                    'Testing' => 'bg-warning',
                                                    'Production' => 'bg-success',
                                                    'Archived' => 'bg-secondary'
                                                ];
                                                ?>
                                                <span class="badge <?= $statusClass[$prototype['Status']] ?? 'bg-secondary' ?>">
                                                    <?= $prototype['Status'] ?>
                                                </span>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($prototype['Created_Date'])) ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="?tab=bomlist/bomlist&entity=prototypes&action=edit&id=<?= $prototype['Prototype_ID'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="?tab=bomlist/bomlist&entity=prototypes&action=clone&id=<?= $prototype['Prototype_ID'] ?>" 
                                                       class="btn btn-sm btn-outline-success" title="Clonar">
                                                        <i class="bi bi-files"></i>
                                                    </a>
                                                    <a href="?tab=bomlist/bomlist&entity=assembly&prototype_id=<?= $prototype['Prototype_ID'] ?>" 
                                                       class="btn btn-sm btn-outline-info" title="Ver BOM">
                                                        <i class="bi bi-diagram-2"></i>
                                                    </a>
                                                    <a href="?tab=bomlist/bomlist&entity=prototypes&action=delete&id=<?= $prototype['Prototype_ID'] ?>" 
                                                       class="btn btn-sm btn-outline-danger" title="Eliminar"
                                                       onclick="return confirm('Tem a certeza que deseja eliminar este protótipo?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($entity === 'assembly'): ?>
        <!-- MONTAGEM (BOM) -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-plus-circle"></i> Nova Montagem</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="entity" value="assembly">
                            
                            <div class="mb-3">
                                <label for="assembly_designation" class="form-label">Nome da Montagem *</label>
                                <input type="text" class="form-control" name="assembly_designation" placeholder="Ex: Montagem1" required>
                            </div>

                            <div class="mb-3">
                                <label for="prototype_id" class="form-label">Protótipo *</label>
                                <select class="form-select" name="prototype_id" required>
                                    <option value="">Selecionar protótipo...</option>
                                    <?php foreach ($prototypes as $prototype): ?>
                                        <option value="<?= $prototype['Prototype_ID'] ?>" 
                                                <?= (isset($_GET['prototype_id']) && $_GET['prototype_id'] == $prototype['Prototype_ID']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Botões para tipo de montagem -->
                            <div class="mb-3">
                                <label class="form-label">Tipo de Montagem</label><br>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_component_component" value="component_component" checked>
                                    <label class="form-check-label" for="type_component_component">Componente - Componente</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_component_assembly" value="component_assembly">
                                    <label class="form-check-label" for="type_component_assembly">Componente - Montagem</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_assembly_assembly" value="assembly_assembly">
                                    <label class="form-check-label" for="type_assembly_assembly">Montagem - Montagem</label>
                                </div>
                            </div>      

                            <div class="mb-3">
                                <label for="component_father_id" class="form-label">Componente Pai</label>
                                <select class="form-select" name="component_father_id">
                                    <option value="">Nível raiz...</option>
                                    <?php foreach ($components as $component): ?>
                                        <option value="<?= $component['Component_ID'] ?>">
                                            <?= htmlspecialchars($component['Denomination']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Deixar vazio para componentes de nível superior</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="component_child_id" class="form-label">Componente Filho *</label>
                                <select class="form-select" name="component_child_id">
                                    <option value="">Selecionar componente...</option>
                                    <?php foreach ($components as $component): ?>
                                        <option value="<?= $component['Component_ID'] ?>">
                                            <?= htmlspecialchars($component['Denomination']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="component_quantity" class="form-label">Quantidade (Componentes) *</label>
                                <input type="number" class="form-control" name="component_quantity" value="0" required min="0">
                            </div>

                            <!-- NOVOS CAMPOS PARA ASSEMBLY -->

                            <div class="mb-3">
                                <label for="assembly_father_id" class="form-label">Montagem-Pai</label>
                                <select class="form-select" name="assembly_father_id">
                                    <option value="">Montagem base</option>
                                    <?php foreach ($assemblies as $assembly): ?>
                                        <option value="<?= $assembly['Assembly_ID'] ?>">
                                            <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                            - <?= $assembly['Assembly_Designation'] ? htmlspecialchars($assembly['Assembly_Designation']) : 'Nível raiz' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Deixar vazio se é uma montagem atómica</div>
                            </div>                          

                            <div class="mb-3">
                                <label for="assembly_child_id" class="form-label">Montagem-Filho</label>
                                <select class="form-select" name="assembly_child_id">
                                    <option value="">Montagem base</option>
                                    <?php foreach ($assemblies as $assembly): ?>
                                        <option value="<?= $assembly['Assembly_ID'] ?>">
                                            <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                            - <?= $assembly['Assembly_Designation'] ? htmlspecialchars($assembly['Assembly_Designation']) : 'Nível raiz' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Deixar vazio se é uma montagem atómica</div>
                            </div>

                            <div class="mb-3">
                                <label for="assembly_quantity" class="form-label">Quantidade (Montagens)</label>
                                <input type="number" class="form-control" name="assembly_quantity" value="0" min="0">
                            </div>
                            
                            <div class="mb-3">
                                <label for="assembly_level_depth" class="form-label">Nível de Montagem</label>
                                <input type="number" class="form-control" name="assembly_level_depth" value="0" min="0">
                            </div>                            
                            
                            <!-- FIM DOS NOVOS CAMPOS PARA ASSEMBLY -->

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notas</label>
                                <textarea class="form-control" name="notes" rows="2"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Adicionar à Montagem
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Filtro de protótipo -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6><i class="bi bi-funnel"></i> Filtrar por Protótipo</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <input type="hidden" name="tab" value="bomlist/bomlist">
                            <input type="hidden" name="entity" value="assembly">
                            <select class="form-select" name="prototype_id" onchange="this.form.submit()">
                                <option value="">Todos os protótipos</option>
                                <?php foreach ($prototypes as $prototype): ?>
                                    <option value="<?= $prototype['Prototype_ID'] ?>" 
                                            <?= (isset($_GET['prototype_id']) && $_GET['prototype_id'] == $prototype['Prototype_ID']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-diagram-2"></i> Estrutura de Montagem</h5>
                        <?php if (isset($_GET['prototype_id']) && $_GET['prototype_id']): ?>
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM T_Prototype WHERE Prototype_ID=?");
                            $stmt->execute([$_GET['prototype_id']]);
                            $selectedPrototype = $stmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <div class="badge bg-info">
                                <?= htmlspecialchars($selectedPrototype['Name']) ?> v<?= $selectedPrototype['Version'] ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php 
                        // Filtrar assemblies se um protótipo específico foi selecionado
                        $filteredAssemblies = $assemblies;
                        if (isset($_GET['prototype_id']) && $_GET['prototype_id']) {
                            $filteredAssemblies = array_filter($assemblies, function($assembly) {
                                return $assembly['Prototype_ID'] == $_GET['prototype_id'];
                            });
                        }
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Designação</th>
                                        <th>Protótipo Associado</th>
                                        <th>Componente-Pai</th>
                                        <th>Componente-Filho</th>
                                        <th>Qtd (Componente)</th>
                                        <th>Montagem-Pai</th>
                                        <th>Montagem-Filho</th>
                                        <th>Qtd (Montagem)</th>
                                        <th>Nível de Montagem</th>
                                        <th>Notas</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredAssemblies as $assembly): ?>
                                        <tr>
                                            <td>
                                                <?= $assembly['Assembly_Designation'] ?? '-' ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($assembly['Prototype_Name']) ?></strong>
                                                <br><small class="text-muted">v<?= $assembly['Prototype_Version'] ?></small>
                                            </td>
                                            <td>
                                                <?= $assembly['Component_Father_Designation'] ? htmlspecialchars($assembly['Component_Father_Designation']) : '<em>Nível raiz</em>' ?>
                                            </td>
                                            <td>
                                                <strong><?= !empty($assembly['Component_Child_Designation']) ? htmlspecialchars($assembly['Component_Child_Designation']) : '-' ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Component_Quantity'] ?></span>
                                            </td>
                                            <td>
                                                <?= $assembly['Assembly_Father_Designation'] ? htmlspecialchars($assembly['Assembly_Father_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <?= $assembly['Assembly_Child_Designation'] ? htmlspecialchars($assembly['Assembly_Child_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Assembly_Quantity'] ?></span>
                                            </td>
                                            <td>
                                                <?= $assembly['Assembly_Level_Depth'] ?>
                                            </td>
                                            <td>
                                                <?= $assembly['Notes'] ? htmlspecialchars($assembly['Notes']) : '-' ?>
                                            </td>
                                            <td>
                                                <a href="?tab=bomlist/bomlist&entity=assembly&action=delete&id=<?= $assembly['Assembly_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Tem a certeza que deseja remover esta montagem?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($filteredAssemblies)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                <i class="bi bi-info-circle"></i> Nenhuma montagem encontrada
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if (isset($_GET['prototype_id']) && $_GET['prototype_id']): ?>
                            <!-- BOM Summary para o protótipo selecionado -->
                            <div class="mt-4">
                                <h6><i class="bi bi-list-check"></i> Resumo da BOM</h6>
                                <?php
                                // Calcular total de componentes necessários
                                $stmt = $pdo->prepare("
                                    SELECT cc.Component_ID, cc.Denomination, cc.General_Type, cc.Price,
                                           SUM(a.Component_Quantity) as Total_Quantity,
                                           cc.Stock_Quantity,
                                           m.Denomination as Manufacturer_Name,
                                           s.Denomination as Supplier_Name
                                    FROM T_Assembly a
                                    JOIN T_Component cc ON a.Component_Child_ID = cc.Component_ID
                                    LEFT JOIN T_Manufacturer m ON cc.Manufacturer_ID = m.Manufacturer_ID
                                    LEFT JOIN T_Supplier s ON cc.Supplier_ID = s.Supplier_ID
                                    WHERE a.Prototype_ID = ?
                                    GROUP BY cc.Component_ID
                                    ORDER BY cc.Denomination
                                ");
                                $stmt->execute([$_GET['prototype_id']]);
                                $bomSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if ($bomSummary):
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead class="table-secondary">
                                                <tr>
                                                    <th>Componente</th>
                                                    <th>Tipo</th>
                                                    <th>Fabricante/Fornecedor</th>
                                                    <th>Qtd Necessária</th>
                                                    <th>Stock</th>
                                                    <th>Preço Unit.</th>
                                                    <th>Preço Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $totalBOMPrice = 0;
                                                foreach ($bomSummary as $item): 
                                                    $itemTotal = $item['Price'] ? $item['Price'] * $item['Total_Quantity'] : 0;
                                                    $totalBOMPrice += $itemTotal;
                                                    $stockStatus = $item['Stock_Quantity'] >= $item['Total_Quantity'] ? 'success' : 'danger';
                                                ?>
                                                    <tr>
                                                        <td><strong><?= htmlspecialchars($item['Denomination']) ?></strong></td>
                                                        <td><?= htmlspecialchars($item['General_Type']) ?></td>
                                                        <td>
                                                            <?= htmlspecialchars($item['Manufacturer_Name']) ?>
                                                            <?php if ($item['Supplier_Name']): ?>
                                                                <br><small class="text-muted"><?= htmlspecialchars($item['Supplier_Name']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="badge bg-primary"><?= $item['Total_Quantity'] ?></span></td>
                                                        <td><span class="badge bg-<?= $stockStatus ?>"><?= $item['Stock_Quantity'] ?></span></td>
                                                        <td><?= $item['Price'] ? number_format($item['Price'], 2) . '€' : '-' ?></td>
                                                        <td><strong><?= $itemTotal ? number_format($itemTotal, 2) . '€' : '-' ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-info">
                                                <tr>
                                                    <th colspan="6">Total da BOM:</th>
                                                    <th><?= number_format($totalBOMPrice, 2) ?>€</th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> Nenhum componente definido para este protótipo.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($entity === 'manufacturers'): ?>
        <!-- FABRICANTES -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-plus-circle"></i> 
                            <?= ($action === 'edit' && isset($_GET['id'])) ? 'Editar' : 'Novo' ?> Fabricante
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $editManufacturer = null;
                        if ($action === 'edit' && isset($_GET['id'])) {
                            $stmt = $pdo->prepare("SELECT * FROM T_Manufacturer WHERE Manufacturer_ID=?");
                            $stmt->execute([$_GET['id']]);
                            $editManufacturer = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="<?= $editManufacturer ? 'update' : 'create' ?>">
                            <input type="hidden" name="entity" value="manufacturers">
                            <?php if ($editManufacturer): ?>
                                <input type="hidden" name="id" value="<?= $editManufacturer['Manufacturer_ID'] ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="denomination" class="form-label">Denominação *</label>
                                <input type="text" class="form-control" name="denomination" required 
                                       value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Denomination']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="origin_country" class="form-label">País de Origem</label>
                                <input type="text" class="form-control" name="origin_country" 
                                       value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Origin_Country']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control" name="website" 
                                       value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Website']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="contacts" class="form-label">Contactos</label>
                                <textarea class="form-control" name="contacts" rows="3"><?= $editManufacturer ? htmlspecialchars($editManufacturer['Contacts']) : '' ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 
                                <?= $editManufacturer ? 'Atualizar' : 'Criar' ?> Fabricante
                            </button>
                            
                            <?php if ($editManufacturer): ?>
                                <a href="?tab=bomlist/bomlist&entity=manufacturers" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list"></i> Lista de Fabricantes (<?= count($manufacturers) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Denominação</th>
                                        <th>País</th>
                                        <th>Website</th>
                                        <th>Componentes</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($manufacturers as $manufacturer): ?>
                                        <?php
                                        // Contar componentes do fabricante
                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM T_Component WHERE Manufacturer_ID=?");
                                        $stmt->execute([$manufacturer['Manufacturer_ID']]);
                                        $componentCount = $stmt->fetchColumn();
                                        ?>
                                        <tr>
                                            <td><?= $manufacturer['Manufacturer_ID'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($manufacturer['Denomination']) ?></strong>
                                                <?php if ($manufacturer['Contacts']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars(substr($manufacturer['Contacts'], 0, 30)) ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($manufacturer['Origin_Country']) ?></td>
                                            <td>
                                                <?php if ($manufacturer['Website']): ?>
                                                    <a href="<?= htmlspecialchars($manufacturer['Website']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-globe"></i>
                                                    </a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $componentCount ?></span>
                                            </td>
                                            <td>
                                                <a href="?tab=bomlist/bomlist&entity=manufacturers&action=edit&id=<?= $manufacturer['Manufacturer_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="?tab=bomlist/bomlist&entity=manufacturers&action=delete&id=<?= $manufacturer['Manufacturer_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Tem a certeza que deseja eliminar este fabricante?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($entity === 'suppliers'): ?>
        <!-- FORNECEDORES -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-plus-circle"></i> 
                            <?= ($action === 'edit' && isset($_GET['id'])) ? 'Editar' : 'Novo' ?> Fornecedor
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $editSupplier = null;
                        if ($action === 'edit' && isset($_GET['id'])) {
                            $stmt = $pdo->prepare("SELECT * FROM T_Supplier WHERE Supplier_ID=?");
                            $stmt->execute([$_GET['id']]);
                            $editSupplier = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="<?= $editSupplier ? 'update' : 'create' ?>">
                            <input type="hidden" name="entity" value="suppliers">
                            <?php if ($editSupplier): ?>
                                <input type="hidden" name="id" value="<?= $editSupplier['Supplier_ID'] ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="denomination" class="form-label">Denominação *</label>
                                <input type="text" class="form-control" name="denomination" required 
                                       value="<?= $editSupplier ? htmlspecialchars($editSupplier['Denomination']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="origin_country" class="form-label">País de Origem</label>
                                <input type="text" class="form-control" name="origin_country" 
                                       value="<?= $editSupplier ? htmlspecialchars($editSupplier['Origin_Country']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control" name="website" 
                                       value="<?= $editSupplier ? htmlspecialchars($editSupplier['Website']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="contacts" class="form-label">Contactos</label>
                                <textarea class="form-control" name="contacts" rows="3"><?= $editSupplier ? htmlspecialchars($editSupplier['Contacts']) : '' ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 
                                <?= $editSupplier ? 'Atualizar' : 'Criar' ?> Fornecedor
                            </button>
                            
                            <?php if ($editSupplier): ?>
                                <a href="?tab=bomlist/bomlist&entity=suppliers" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list"></i> Lista de Fornecedores (<?= count($suppliers) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Denominação</th>
                                        <th>País</th>
                                        <th>Website</th>
                                        <th>Componentes</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <?php
                                        // Contar componentes do fornecedor
                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM T_Component WHERE Supplier_ID=?");
                                        $stmt->execute([$supplier['Supplier_ID']]);
                                        $componentCount = $stmt->fetchColumn();
                                        ?>
                                        <tr>
                                            <td><?= $supplier['Supplier_ID'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($supplier['Denomination']) ?></strong>
                                                <?php if ($supplier['Contacts']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars(substr($supplier['Contacts'], 0, 30)) ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($supplier['Origin_Country']) ?></td>
                                            <td>
                                                <?php if ($supplier['Website']): ?>
                                                    <a href="<?= htmlspecialchars($supplier['Website']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-globe"></i>
                                                    </a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $componentCount ?></span>
                                            </td>
                                            <td>
                                                <a href="?tab=bomlist/bomlist&entity=suppliers&action=edit&id=<?= $supplier['Supplier_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="?tab=bomlist/bomlist&entity=suppliers&action=delete&id=<?= $supplier['Supplier_ID'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Tem a certeza que deseja eliminar este fornecedor?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <!-- Statistics Dashboard -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-graph-up"></i> Estatísticas do Sistema</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="h2 text-primary"><?= count($components) ?></div>
                                <div class="text-muted">Componentes</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="h2 text-success"><?= count($prototypes) ?></div>
                                <div class="text-muted">Protótipos</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="h2 text-info"><?= count($assemblies) ?></div>
                                <div class="text-muted">Montagens</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="h2 text-warning"><?= count($manufacturers) ?></div>
                                <div class="text-muted">Fabricantes</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="h2 text-secondary"><?= count($suppliers) ?></div>
                                <div class="text-muted">Fornecedores</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <?php
                                // Calcular componentes com stock baixo
                                $lowStockCount = 0;
                                foreach ($components as $component) {
                                    if ($component['Stock_Quantity'] <= $component['Min_Stock']) {
                                        $lowStockCount++;
                                    }
                                }
                                ?>
                                <div class="h2 text-danger"><?= $lowStockCount ?></div>
                                <div class="text-muted">Stock Baixo</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <h6><i class="bi bi-lightning"></i> Ações Rápidas</h6>
                            <div class="btn-group" role="group">
                                <a href="?tab=bomlist/bomlist&entity=components&action=create" class="btn btn-outline-primary">
                                    <i class="bi bi-cpu"></i> Novo Componente
                                </a>
                                <a href="?tab=bomlist/bomlist&entity=prototypes&action=create" class="btn btn-outline-success">
                                    <i class="bi bi-diagram-3"></i> Novo Protótipo
                                </a>
                                <a href="?tab=bomlist/bomlist&entity=assembly" class="btn btn-outline-info">
                                    <i class="bi bi-diagram-2"></i> Gerir BOM
                                </a>
                                <a href="?tab=bomlist/bomlist&entity=manufacturers&action=create" class="btn btn-outline-warning">
                                    <i class="bi bi-building"></i> Novo Fabricante
                                </a>
                                <a href="?tab=bomlist/bomlist&entity=suppliers&action=create" class="btn btn-outline-secondary">
                                    <i class="bi bi-truck"></i> Novo Fornecedor
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6><i class="bi bi-clock-history"></i> Componentes Recentes</h6>
                            <div class="list-group list-group-flush">
                                <?php
                                $stmt = $pdo->query("SELECT * FROM T_Component ORDER BY Created_Date DESC LIMIT 5");
                                $recentComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($recentComponents as $component):
                                ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($component['Denomination']) ?></strong>
                                            <small class="text-muted d-block"><?= date('d/m/Y', strtotime($component['Created_Date'])) ?></small>
                                        </div>
                                        <span class="badge bg-primary rounded-pill"><?= htmlspecialchars($component['General_Type']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6><i class="bi bi-exclamation-triangle"></i> Alertas de Stock</h6>
                            <div class="list-group list-group-flush">
                                <?php
                                $lowStockComponents = array_filter($components, function($component) {
                                    return $component['Stock_Quantity'] <= $component['Min_Stock'];
                                });
                                $lowStockComponents = array_slice($lowStockComponents, 0, 5);
                                
                                if (empty($lowStockComponents)):
                                ?>
                                    <div class="list-group-item text-success">
                                        <i class="bi bi-check-circle"></i> Todos os stocks estão adequados
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($lowStockComponents as $component): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($component['Denomination']) ?></strong>
                                                <small class="text-muted d-block">Stock: <?= $component['Stock_Quantity'] ?> / Mín: <?= $component['Min_Stock'] ?></small>
                                            </div>
                                            <span class="badge bg-danger rounded-pill">Baixo</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export/Import Tools -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-download"></i> Ferramentas de Exportação/Importação</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Exportação</h6>
                            <div class="btn-group" role="group">
                                <button class="btn btn-outline-success" onclick="exportToCSV('components')">
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Componentes CSV
                                </button>
                                <button class="btn btn-outline-info" onclick="exportToCSV('prototypes')">
                                    <i class="bi bi-file-earmark-text"></i> Protótipos CSV
                                </button>
                                <button class="btn btn-outline-warning" onclick="generateBOMReport()">
                                    <i class="bi bi-file-earmark-pdf"></i> Relatório BOM
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Importação</h6>
                            <div class="input-group">
                                <input type="file" class="form-control" id="importFile" accept=".csv,.xlsx">
                                <button class="btn btn-outline-primary" onclick="importFromFile()">
                                    <i class="bi bi-upload"></i> Importar
                                </button>
                            </div>
                            <small class="text-muted">Suporte para ficheiros CSV e Excel</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript para funcionalidades avançadas -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Função para exportar dados para CSV
    window.exportToCSV = function(type) {
        const data = [];
        let headers = [];
        
        switch(type) {
            case 'components':
                headers = ['ID', 'Denominação', 'Tipo', 'Fabricante', 'Fornecedor', 'Preço', 'Stock'];
                <?php foreach ($components as $component): ?>
                    data.push([
                        '<?= $component['Component_ID'] ?>',
                        '<?= addslashes($component['Denomination']) ?>',
                        '<?= addslashes($component['General_Type']) ?>',
                        '<?= addslashes($component['Manufacturer_Name']) ?>',
                        '<?= addslashes($component['Supplier_Name']) ?>',
                        '<?= $component['Price'] ?? 0 ?>',
                        '<?= $component['Stock_Quantity'] ?>'
                    ]);
                <?php endforeach; ?>
                break;
                
            case 'prototypes':
                headers = ['ID', 'Nome', 'Versão', 'Estado', 'Data Criação'];
                <?php foreach ($prototypes as $prototype): ?>
                    data.push([
                        '<?= $prototype['Prototype_ID'] ?>',
                        '<?= addslashes($prototype['Name']) ?>',
                        '<?= $prototype['Version'] ?>',
                        '<?= $prototype['Status'] ?>',
                        '<?= date('d/m/Y', strtotime($prototype['Created_Date'])) ?>'
                    ]);
                <?php endforeach; ?>
                break;
        }
        
        // Criar CSV
        let csvContent = headers.join(',') + '\n';
        data.forEach(row => {
            csvContent += row.map(field => `"${field}"`).join(',') + '\n';
        });
        
        // Download
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${type}_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };
    
    // Função para gerar relatório BOM
    window.generateBOMReport = function() {
        const selectedPrototype = '<?= $_GET['prototype_id'] ?? '' ?>';
        if (!selectedPrototype) {
            alert('Por favor, selecione um protótipo na aba de Montagem primeiro.');
            return;
        }
        
        // Redirecionar para página de relatório (seria implementada separadamente)
        window.open(`bom_report.php?prototype_id=${selectedPrototype}`, '_blank');
    };
    
    // Função para importar dados
    window.importFromFile = function() {
        const fileInput = document.getElementById('importFile');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Por favor, selecione um ficheiro para importar.');
            return;
        }
        
        // Aqui seria implementada a lógica de importação
        alert('Funcionalidade de importação em desenvolvimento.');
    };
    
    // Auto-refresh da página a cada 5 minutos para manter dados atualizados
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            // Verificar se há mudanças na base de dados (poderia ser implementado via AJAX)
        }
    }, 300000); // 5 minutos
    
    // Busca em tempo real
    const searchInputs = document.querySelectorAll('input[type="search"]');
    searchInputs.forEach(input => {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = this.closest('.card').querySelector('tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
    
    // Tooltips para botões
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Confirmação de eliminação melhorada
    const deleteLinks = document.querySelectorAll('a[href*="action=delete"]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const item = this.closest('tr').querySelector('strong').textContent;
            if (confirm(`Tem a certeza que deseja eliminar "${item}"?\n\nEsta ação não pode ser desfeita.`)) {
                window.location.href = this.href;
            }
        });
    });
    
    // Validação de formulários
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    valid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Por favor, preencha todos os campos obrigatórios.');
            }
        });
    });
    
    // Guardar rascunhos automaticamente
    const formInputs = document.querySelectorAll('form input, form select, form textarea');
    formInputs.forEach(input => {
        // Carregar rascunho salvo
        const savedValue = localStorage.getItem(`draft_${input.name}`);
        if (savedValue && !input.value) {
            input.value = savedValue;
        }
        
        // Guardar mudanças
        input.addEventListener('input', function() {
            localStorage.setItem(`draft_${this.name}`, this.value);
        });
    });
    
    // Limpar rascunhos quando formulário é submetido com sucesso
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                localStorage.removeItem(`draft_${input.name}`);
            });
        });
    });
});

// Função para calcular custos de protótipo em tempo real
function calculatePrototypeCost(prototypeId) {
    // Esta função seria expandida para calcular custos dinamicamente
    console.log('Calculando custo do protótipo:', prototypeId);
}

// Função para verificar disponibilidade de stock
function checkStockAvailability(componentId, quantity) {
    // Esta função verificaria se há stock suficiente
    console.log('Verificando stock para componente:', componentId, 'quantidade:', quantity);
}
</script>

<style>
/* Estilos adicionais para melhorar a aparência */
.table-hover tbody tr:hover {
    background-color: rgba(0,123,255,.075);
}

.badge {
    font-size: 0.85em;
}

.card-header h5 {
    margin-bottom: 0;
}

.btn-group .btn {
    margin-right: 5px;
}

.list-group-item {
    border-left: none;
    border-right: none;
}

.list-group-item:first-child {
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
        margin-right: 0;
    }
    
    .table-responsive {
        font-size: 0.85em;
    }
}

/* Status colors */
.text-development { color: #0d6efd; }
.text-testing { color: #fd7e14; }
.text-production { color: #198754; }
.text-archived { color: #6c757d; }

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Error states */
.is-invalid {
    border-color: #dc3545;
}

.is-invalid:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}
</style>

<?php
// Fechar conexão
$pdo = null;
?>