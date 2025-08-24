<?php
// tabs/bomlist.php
// Sistema de Gestão de Bill of Materials (BOM)

// Incluir configuração da base de dados
// Incluir arquivo de configuração
//include_once __DIR__ . '/../../PWA/RestAPI/config.php';
include_once __DIR__ . '/../../config.php';
require_once 'helpers.php';
require_once 'getters.php';
require_once 'database/database.php';
require_once 'processor.php';


$pdo = connectDB();


// Processar ações CRUD
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$entity = $_POST['entity'] ?? $_GET['entity'] ?? 'components';
$message = '';

// Processar ações CRUD
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$entity = $_POST['entity'] ?? $_GET['entity'] ?? 'components';
$message = '';



// Processar ações CRUD baseadas no entity e action
$message = processCRUD($pdo, $entity, $action);


// Buscar dados para exibição
$manufacturers = getManufacturers($pdo);
$suppliers = getSuppliers($pdo);
$prototypes = getPrototypes($pdo);
$components = getComponents($pdo);
$assemblies = getAssemblies($pdo);

//error_log(print_r($assemblies, true)); // Log assemblies for debugging

?>

<div class="container-fluid">
    <?php
    // para ter mensagens tanto quando se cria fabricantes/fornecedores normalmente como quando se cria na tab componentes
    // garante que $message e $status existem
    $message = $message ?? '';
    $status = $_GET['status'] ?? $status ?? 'ok';

    if (!empty($_GET['msg'])) {
        $message = $_GET['msg'];
        $status = $_GET['status'] ?? $status;
    }

    if (!empty($message)) {
        $type = ($status === 'ok') ? 'success' : (($status === 'warning') ? 'warning' : 'danger');
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    ?>

    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-list-ul"></i> Sistema de Gestão BOM FFI (Bill of Materials)</h2>
            
            <!-- Navigation tabs -->
             
            <ul class="nav nav-tabs" id="bomTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'components' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=components'">
                        <i class="bi bi-cpu"></i> Componentes
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'manufacturers' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=manufacturers'">
                        <i class="bi bi-house"></i> Fabricantes
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'suppliers' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=suppliers'">
                        <i class="bi bi-truck"></i> Fornecedores
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'assembly' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=assembly'">
                        <i class="bi bi-diagram-2"></i> Assembly (BOM)
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'prototypes' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=prototypes'">
                        <i class="bi bi-diagram-3"></i> Protótipos
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'search' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=search'">
                        <i class="bi bi-search"></i> Pesquisa
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
                                <select class="form-select" name="general_type" id="general_type" required>
                                    <option value="">Selecionar...</option>
                                    <option value="Mecânica e Suportes Estruturais" <?= ($editComponent && $editComponent['General_Type'] === 'Mecânica e Suportes Estruturais') ? 'selected' : '' ?>>
                                        Mecânica e Suportes Estruturais
                                    </option>
                                    <option value="Transmissão e movimento" <?= ($editComponent && $editComponent['General_Type'] === 'Transmissão e movimento') ? 'selected' : '' ?>>
                                        Transmissão e movimento
                                    </option>
                                    <option value="Sistema elétrico" <?= ($editComponent && $editComponent['General_Type'] === 'Sistema elétrico') ? 'selected' : '' ?>>
                                        Sistema elétrico
                                    </option>
                                    <option value="Eletrónica e controlo" <?= ($editComponent && $editComponent['General_Type'] === 'Eletrónica e controlo') ? 'selected' : '' ?>>
                                        Eletrónica e controlo
                                    </option>
                                </select>
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
                                    <!-- Botão alinhado abaixo do select -->
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-primary btn-sm btn-compact" data-bs-toggle="modal" data-bs-target="#newManufacturerModal">
                                            <i class="bi bi-plus"></i> Novo Fabricante
                                        </button>
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
                                    <!-- Botão alinhado abaixo do select -->
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-primary btn-sm btn-compact" data-bs-toggle="modal" data-bs-target="#newSupplierModal">
                                            <i class="bi bi-plus"></i> Novo Fornecedor
                                        </button>
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
                                        <input type="number" step="0.01" min="0.5" class="form-control" name="price" 
                                               value="<?= $editComponent ? $editComponent['Price'] : '' ?>" required>
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
                                        <th>Referência</th>
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
                                            <td><?= $component['Reference'] ?></td>
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
                        <h5><i class="bi bi-plus-circle"></i> Nova Assembly</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="entity" value="assembly">
                            
                            <div class="mb-3">
                                <label for="assembly_designation" class="form-label">Nome da Assembly *</label>
                                <input type="text" class="form-control" name="assembly_designation" placeholder="Ex: Assembly1" required>
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

                            

                            <!-- NOVOS CAMPOS PARA ASSEMBLY -->

                            <div id="assembly-fields" style="display:none;">
                                <div class="mb-3">
                                    <label for="assembly_select" class="form-label">Assembly</label>
                                    <select id="assembly_select" name="assembly_id" class="form-select">
                                        <option value="">Selecionar Assembly...</option>
                                        <!-- Options gerados dinamicamente -->
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="assembly_ref" class="form-label">Referência</label>
                                    <input type="text" id="assembly_ref" name="assembly_ref" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="assembly_quantity" class="form-label">Quantidade</label>
                                    <input type="number" id="assembly_quantity" name="assembly_quantity" class="form-control" value="1" min="1">
                                </div>
                            </div>                      

                            <!-- FIM DOS NOVOS CAMPOS PARA ASSEMBLY -->

                            <div class="mb-3" id="field-notes">
                                <label for="notes" class="form-label">Notas</label>
                                <textarea class="form-control" name="notes" rows="2"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Criar Assembly
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h6><i class="bi bi-pencil-square"></i> Editar Assembly</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="tab" value="bomlist/bomlist">
                            <input type="hidden" name="entity" value="assembly">
                            <input type="hidden" name="action" value="update">
                            <div class="mb-3">
                                <label for="assembly_name" class="form-label">Assembly</label>
                                <select id="assembly_name" name="assembly_id" class="form-select"
                                    onchange="showAssociationFields(this.value)">
                                <option value="">Selecionar assembly...</option>
                                <?php foreach ($assemblies as $assembly): ?>
                                    <option value="<?= $assembly['Assembly_ID'] ?>">
                                        <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                        – <?= $assembly['Assembly_Designation'] ?: 'Nível raiz' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Campo de referência ao lado do select -->
                            <div class="input-group mt-2">
                                <input type="text"
                                    class="form-control"
                                    name="assembly_father_custom_ref"
                                    placeholder="Referência">
                                <button type="button"
                                        id="assemblyDetailsBtn"
                                        class="btn btn-outline-info"
                                        disabled>
                                    <i class="bi bi-info-circle"></i> Ver Detalhes
                                </button>
                            </div>
                                <!-- Campos para associação que iniciam ocultos -->
                                <div id="association-fields" style="display:none;" class="mt-3">
                                    <h6>Opções de Associação</h6>
                                    
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="showAssemblyFields()">
                                            <i class="bi bi-diagram-2"></i> Associar Assembly
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm" onclick="showComponentFields()">
                                            <i class="bi bi-cpu"></i> Associar Componente
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="showRemoveFields()">
                                            <i class="bi bi-x-circle"></i> Remover Associação
                                        </button>
                                    </div>

                                    <!-- Campos para Assembly -->   
                                    <div id="assembly-association-fields" style="display:none;">
                                    <div class="mb-3">
                                        <label for="associated_assembly" class="form-label">Selecionar Assembly:</label>
                                        <select class="form-select" name="associated_assembly" id="associated_assembly">
                                        <option value="">Selecionar assembly...</option>
                                        <?php foreach ($assemblies as $assembly): ?>
                                            <option value="<?= $assembly['Assembly_ID'] ?>">
                                            <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                            – <?= $assembly['Assembly_Designation'] ?: 'Nível raiz' ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php foreach ($prototypes as $prototype): ?>
                                            <option value="<?= $prototype['Prototype_ID'] ?> prototype">
                                            <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <div class="input-group">
                                        <input type="text"
                                                class="form-control"
                                                id="assembly_ref_assoc"
                                                name="assembly_father_custom_ref"
                                                placeholder="Referência">
                                        <button type="button"
                                                id="assemblyDetailsBtnAssoc"
                                                class="btn btn-outline-info"
                                                disabled>
                                            <i class="bi bi-info-circle"></i> Ver Detalhes
                                        </button>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="assembly_quantity" class="form-label">Quantidade</label>
                                        <input type="number" class="form-control" name="assembly_quantity" min="1" value="1">
                                    </div>
                                    </div>

                                    <!-- Campos para Componente -->
                                    <div id="component-association-fields" style="display:none;">
                                        <div class="mb-3">
                                            <label for="component_father_id" class="form-label">Selecionar Componente:</label>
                                        <div class="input-group">
                                            <select class="form-select" name="component_father_id" id="component_father_id">
                                                <option value="">Selecionar componente...</option>
                                                <?php foreach ($components as $component): ?>
                                                    <option value="<?= $component['Component_ID'] ?>">
                                                        <?= htmlspecialchars($component['Denomination']) ?> 
                                                        <?php if (!empty($component['Reference'])): ?>
                                                            (<?= htmlspecialchars($component['Reference']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <!-- Campo para escrever manualmente a referência -->
                                            <input type="text" class="form-control" name="component_father_custom_ref" placeholder="Referência">
                                            <button type="button" id="componentDetailsBtn" class="btn btn-outline-info" disabled>
                                                <i class="bi bi-info-circle"></i> Ver Detalhes
                                            </button>
                                        </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="component_quantity" class="form-label">Quantidade</label>
                                            <input type="number" class="form-control" name="component_quantity" min="1" value="1">
                                        </div>
                                    </div>
                                </div>
                                <!-- Campos para remoção de associação -->
                                 <div id="remove-association-fields" style="display:none;" class="mt-3">
                                    <div class="mb-3">
                                        <label for="remove_type" class="form-label">Tipo de Associação a Remover</label>
                                        <select class="form-select" name="remove_type" id="remove_type" onchange="showRemoveOptions(this.value)">
                                            <option value="">Selecionar...</option>
                                            <option value="assembly">Assembly</option>
                                            <option value="component">Componente</option>
                                        </select>
                                    </div>
                                    <div class="mb-3" id="remove_assembly_div" style="display:none;">
                                     <!-- Loaded dynamically from js -->
                                    </div>
                                    <div class="mb-3" id="remove_component_div" style="display:none;">
                                     <!-- Loaded dynamically from js -->
                                    </div>              
                                </div>


                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Guardar Alterações
                            </button>
                        </form>
                    </div>
                </div>


            </div>
            
            <div class="col-md-8">
                <div class="card mt-4">
                    <div class="card-header">
                        <h5><i class="bi bi-diagram-3"></i> Estrutura de Assembly (Árvore)</h5>
                    </div>
                    <form method="GET" action="">
                        <input type="hidden" name="tab" value="bomlist/bomlist">
                        <input type="hidden" name="entity" value="assembly">
                        <div class="mb-3">
                            <label for="prototype_id" class="form-label">Selecione um Protótipo</label>
                            <select class="form-select" name="prototype_id" onchange="this.form.submit()">
                                <option value="">-- Escolha um Protótipo --</option>
                                <?php foreach ($prototypes as $prototype): ?>
                                    <option value="<?= $prototype['Prototype_ID'] ?>" <?= (isset($_GET['prototype_id']) && $_GET['prototype_id'] == $prototype['Prototype_ID']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                    

                    <?php
                    if (isset($_GET['prototype_id']) && $_GET['prototype_id']) {
                        // Filtrar as assemblies para o protótipo selecionado
                        $filteredAssemblies = array_filter($assemblies, function($assembly) {
                            return $assembly['Prototype_ID'] == $_GET['prototype_id'];
                        });
                        // Constrói a árvore mista a partir das assemblies filtradas e dos componentes
                         $ids = array_column($filteredAssemblies, 'Assembly_ID');
                        if (count($ids) > 0) {
                            $stmt = $pdo->prepare(
                                "SELECT * FROM T_Assembly_Assembly WHERE Parent_Assembly_ID IN (" .
                                implode(',', array_fill(0, count($ids), '?')) . ")"
                            );
                            $stmt->execute($ids);
                            $assocAssems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $assocAssems = [];
                        }
                        $assocComps = getAssemblyComponentsByIds($pdo, $ids);
                        $tree = getAssemblyTreeFromList($filteredAssemblies, $assocComps, $assocAssems);
                        echo '<div id="assembly-tree">';
                        echo renderAssemblyTreeMixed($tree);
                        echo '</div>';
                    } else {
                        echo "<p>Selecione um protótipo para visualizar a árvore de assembly.</p>";
                    }
                    ?>
                    
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-diagram-2"></i> Estrutura de Assembly</h5>
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
                                        <th>Referência</th>
                                        <th>Designação</th>
                                        <th>Protótipo</th>
                                        <th>Preço</th>
                                        <th>Notas</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredAssemblies as $assembly): ?>
                                        <tr>
                                            <td><?= $assembly['Assembly_Reference'] ?></td>
                                            <td class = "assembly-designation" data-assembly-id="<?= $assembly['Assembly_ID'] ?>">
                                                <?= $assembly['Assembly_Designation'] ?? '-' ?>
                                                <button 
                                                    type="button" 
                                                    class="btn btn-sm btn-outline-info ms-2" 
                                                    onclick="showAssemblyAssociations(<?= $assembly['Assembly_ID'] ?>)">
                                                    <i class="bi bi-info-circle"></i> Ver Detalhes
                                                </button>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($assembly['Prototype_Name']) ?></strong>
                                                <br><small class="text-muted">v<?= $assembly['Prototype_Version'] ?></small>
                                            </td>
                                    
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= $assembly['Price'] ? number_format($assembly['Price'], 2) . '€' : '-' ?>
                                                </span>
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


        // Calcular total de componentes necessários usando a nova tabela de junção


        $stmt = $pdo->prepare("


            SELECT 


                c.Component_ID,


                c.Denomination,


                c.General_Type,


                c.Price,


                SUM(ac.Quantity) as Total_Quantity,


                c.Stock_Quantity,


                m.Denomination as Manufacturer_Name,


                s.Denomination as Supplier_Name


            FROM T_Assembly_Component ac


            JOIN T_Assembly a ON ac.Assembly_ID = a.Assembly_ID


            JOIN T_Component c ON ac.Component_ID = c.Component_ID


            LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID


            LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID


            WHERE a.Prototype_ID = ?


            GROUP BY c.Component_ID


            ORDER BY c.Denomination


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
                <?php
                /*if (isset($_GET['prototype_id']) && $_GET['prototype_id']) {
                    $prototypeId = $_GET['prototype_id'];
                    $assemblyTree = getAssemblyTree($pdo, $prototypeId);
                }*/ ?>
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
                            <?php
                            // mostrar componentes do fabricante (quando a edição está aberta ou existe ?manufacturer_id)
                            $showManufacturerId = null;
                            if (!empty($editManufacturer) && !empty($editManufacturer['Manufacturer_ID'])) {
                                $showManufacturerId = (int)$editManufacturer['Manufacturer_ID'];
                            } elseif (!empty($_GET['manufacturer_id'])) {
                                $showManufacturerId = (int)$_GET['manufacturer_id'];
                            }

                            if ($showManufacturerId) {
                                // usa a função de getters.php
                                $componentsByManufacturer = getComponentsByManufacturer($pdo, $showManufacturerId);

                                // nome do fabricante (tentativa por $editManufacturer ou consulta rápida)
                                $manufacturerName = $editManufacturer['Denomination'] ?? null;
                                if (!$manufacturerName) {
                                    $s = $pdo->prepare("SELECT Denomination FROM T_Manufacturer WHERE Manufacturer_ID=?");
                                    $s->execute([$showManufacturerId]);
                                    $r = $s->fetch(PDO::FETCH_ASSOC);
                                    $manufacturerName = $r['Denomination'] ?? '—';
                                }
                            }
                            ?>

                            <div class="mb-3">
                                <label for="denomination" class="form-label">Denominação *</label>
                                <input type="text" class="form-control" name="denomination" required 
                                       value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Denomination']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="origin_country" class="form-label">País de Origem</label>
                                <select id="origin_country" name="origin_country" class="form-control">
                                    <option value="Afghanistan">Afghanistan</option>
                                    <option value="Åland Islands">Åland Islands</option>
                                    <option value="Albania">Albania</option>
                                    <option value="Algeria">Algeria</option>
                                    <option value="American Samoa">American Samoa</option>
                                    <option value="Andorra">Andorra</option>
                                    <option value="Angola">Angola</option>
                                    <option value="Anguilla">Anguilla</option>
                                    <option value="Antarctica">Antarctica</option>
                                    <option value="Antigua and Barbuda">Antigua and Barbuda</option>
                                    <option value="Argentina">Argentina</option>
                                    <option value="Armenia">Armenia</option>
                                    <option value="Aruba">Aruba</option>
                                    <option value="Australia">Australia</option>
                                    <option value="Austria">Austria</option>
                                    <option value="Azerbaijan">Azerbaijan</option>
                                    <option value="Bahamas">Bahamas</option>
                                    <option value="Bahrain">Bahrain</option>
                                    <option value="Bangladesh">Bangladesh</option>
                                    <option value="Barbados">Barbados</option>
                                    <option value="Belarus">Belarus</option>
                                    <option value="Belgium">Belgium</option>
                                    <option value="Belize">Belize</option>
                                    <option value="Benin">Benin</option>
                                    <option value="Bermuda">Bermuda</option>
                                    <option value="Bhutan">Bhutan</option>
                                    <option value="Bolivia">Bolivia</option>
                                    <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
                                    <option value="Botswana">Botswana</option>
                                    <option value="Bouvet Island">Bouvet Island</option>
                                    <option value="Brazil">Brazil</option>
                                    <option value="British Indian Ocean Territory">British Indian Ocean Territory</option>
                                    <option value="Brunei Darussalam">Brunei Darussalam</option>
                                    <option value="Bulgaria">Bulgaria</option>
                                    <option value="Burkina Faso">Burkina Faso</option>
                                    <option value="Burundi">Burundi</option>
                                    <option value="Cambodia">Cambodia</option>
                                    <option value="Cameroon">Cameroon</option>
                                    <option value="Canada">Canada</option>
                                    <option value="Cape Verde">Cape Verde</option>
                                    <option value="Cayman Islands">Cayman Islands</option>
                                    <option value="Central African Republic">Central African Republic</option>
                                    <option value="Chad">Chad</option>
                                    <option value="Chile">Chile</option>
                                    <option value="China">China</option>
                                    <option value="Christmas Island">Christmas Island</option>
                                    <option value="Cocos (Keeling) Islands">Cocos (Keeling) Islands</option>
                                    <option value="Colombia">Colombia</option>
                                    <option value="Comoros">Comoros</option>
                                    <option value="Congo">Congo</option>
                                    <option value="Congo, The Democratic Republic of The">Congo, The Democratic Republic of The</option>
                                    <option value="Cook Islands">Cook Islands</option>
                                    <option value="Costa Rica">Costa Rica</option>
                                    <option value="Cote D'ivoire">Cote D'ivoire</option>
                                    <option value="Croatia">Croatia</option>
                                    <option value="Cuba">Cuba</option>
                                    <option value="Cyprus">Cyprus</option>
                                    <option value="Czech Republic">Czech Republic</option>
                                    <option value="Denmark">Denmark</option>
                                    <option value="Djibouti">Djibouti</option>
                                    <option value="Dominica">Dominica</option>
                                    <option value="Dominican Republic">Dominican Republic</option>
                                    <option value="Ecuador">Ecuador</option>
                                    <option value="Egypt">Egypt</option>
                                    <option value="El Salvador">El Salvador</option>
                                    <option value="Equatorial Guinea">Equatorial Guinea</option>
                                    <option value="Eritrea">Eritrea</option>
                                    <option value="Estonia">Estonia</option>
                                    <option value="Ethiopia">Ethiopia</option>
                                    <option value="Falkland Islands (Malvinas)">Falkland Islands (Malvinas)</option>
                                    <option value="Faroe Islands">Faroe Islands</option>
                                    <option value="Fiji">Fiji</option>
                                    <option value="Finland">Finland</option>
                                    <option value="France">France</option>
                                    <option value="French Guiana">French Guiana</option>
                                    <option value="French Polynesia">French Polynesia</option>
                                    <option value="French Southern Territories">French Southern Territories</option>
                                    <option value="Gabon">Gabon</option>
                                    <option value="Gambia">Gambia</option>
                                    <option value="Georgia">Georgia</option>
                                    <option value="Germany">Germany</option>
                                    <option value="Ghana">Ghana</option>
                                    <option value="Gibraltar">Gibraltar</option>
                                    <option value="Greece">Greece</option>
                                    <option value="Greenland">Greenland</option>
                                    <option value="Grenada">Grenada</option>
                                    <option value="Guadeloupe">Guadeloupe</option>
                                    <option value="Guam">Guam</option>
                                    <option value="Guatemala">Guatemala</option>
                                    <option value="Guernsey">Guernsey</option>
                                    <option value="Guinea">Guinea</option>
                                    <option value="Guinea-bissau">Guinea-bissau</option>
                                    <option value="Guyana">Guyana</option>
                                    <option value="Haiti">Haiti</option>
                                    <option value="Heard Island and Mcdonald Islands">Heard Island and Mcdonald Islands</option>
                                    <option value="Holy See (Vatican City State)">Holy See (Vatican City State)</option>
                                    <option value="Honduras">Honduras</option>
                                    <option value="Hong Kong">Hong Kong</option>
                                    <option value="Hungary">Hungary</option>
                                    <option value="Iceland">Iceland</option>
                                    <option value="India">India</option>
                                    <option value="Indonesia">Indonesia</option>
                                    <option value="Iran, Islamic Republic of">Iran, Islamic Republic of</option>
                                    <option value="Iraq">Iraq</option>
                                    <option value="Ireland">Ireland</option>
                                    <option value="Isle of Man">Isle of Man</option>
                                    <option value="Israel">Israel</option>
                                    <option value="Italy">Italy</option>
                                    <option value="Jamaica">Jamaica</option>
                                    <option value="Japan">Japan</option>
                                    <option value="Jersey">Jersey</option>
                                    <option value="Jordan">Jordan</option>
                                    <option value="Kazakhstan">Kazakhstan</option>
                                    <option value="Kenya">Kenya</option>
                                    <option value="Kiribati">Kiribati</option>
                                    <option value="Korea, Democratic People's Republic of">Korea, Democratic People's Republic of</option>
                                    <option value="Korea, Republic of">Korea, Republic of</option>
                                    <option value="Kuwait">Kuwait</option>
                                    <option value="Kyrgyzstan">Kyrgyzstan</option>
                                    <option value="Lao People's Democratic Republic">Lao People's Democratic Republic</option>
                                    <option value="Latvia">Latvia</option>
                                    <option value="Lebanon">Lebanon</option>
                                    <option value="Lesotho">Lesotho</option>
                                    <option value="Liberia">Liberia</option>
                                    <option value="Libyan Arab Jamahiriya">Libyan Arab Jamahiriya</option>
                                    <option value="Liechtenstein">Liechtenstein</option>
                                    <option value="Lithuania">Lithuania</option>
                                    <option value="Luxembourg">Luxembourg</option>
                                    <option value="Macao">Macao</option>
                                    <option value="Macedonia, The Former Yugoslav Republic of">Macedonia, The Former Yugoslav Republic of</option>
                                    <option value="Madagascar">Madagascar</option>
                                    <option value="Malawi">Malawi</option>
                                    <option value="Malaysia">Malaysia</option>
                                    <option value="Maldives">Maldives</option>
                                    <option value="Mali">Mali</option>
                                    <option value="Malta">Malta</option>
                                    <option value="Marshall Islands">Marshall Islands</option>
                                    <option value="Martinique">Martinique</option>
                                    <option value="Mauritania">Mauritania</option>
                                    <option value="Mauritius">Mauritius</option>
                                    <option value="Mayotte">Mayotte</option>
                                    <option value="Mexico">Mexico</option>
                                    <option value="Micronesia, Federated States of">Micronesia, Federated States of</option>
                                    <option value="Moldova, Republic of">Moldova, Republic of</option>
                                    <option value="Monaco">Monaco</option>
                                    <option value="Mongolia">Mongolia</option>
                                    <option value="Montenegro">Montenegro</option>
                                    <option value="Montserrat">Montserrat</option>
                                    <option value="Morocco">Morocco</option>
                                    <option value="Mozambique">Mozambique</option>
                                    <option value="Myanmar">Myanmar</option>
                                    <option value="Namibia">Namibia</option>
                                    <option value="Nauru">Nauru</option>
                                    <option value="Nepal">Nepal</option>
                                    <option value="Netherlands">Netherlands</option>
                                    <option value="Netherlands Antilles">Netherlands Antilles</option>
                                    <option value="New Caledonia">New Caledonia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="Nicaragua">Nicaragua</option>
                                    <option value="Niger">Niger</option>
                                    <option value="Nigeria">Nigeria</option>
                                    <option value="Niue">Niue</option>
                                    <option value="Norfolk Island">Norfolk Island</option>
                                    <option value="Northern Mariana Islands">Northern Mariana Islands</option>
                                    <option value="Norway">Norway</option>
                                    <option value="Oman">Oman</option>
                                    <option value="Pakistan">Pakistan</option>
                                    <option value="Palau">Palau</option>
                                    <option value="Palestinian Territory, Occupied">Palestinian Territory, Occupied</option>
                                    <option value="Panama">Panama</option>
                                    <option value="Papua New Guinea">Papua New Guinea</option>
                                    <option value="Paraguay">Paraguay</option>
                                    <option value="Peru">Peru</option>
                                    <option value="Philippines">Philippines</option>
                                    <option value="Pitcairn">Pitcairn</option>
                                    <option value="Poland">Poland</option>
                                    <option value="Portugal" selected>Portugal</option>
                                    <option value="Puerto Rico">Puerto Rico</option>
                                    <option value="Qatar">Qatar</option>
                                    <option value="Reunion">Reunion</option>
                                    <option value="Romania">Romania</option>
                                    <option value="Russian Federation">Russian Federation</option>
                                    <option value="Rwanda">Rwanda</option>
                                    <option value="Saint Helena">Saint Helena</option>
                                    <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
                                    <option value="Saint Lucia">Saint Lucia</option>
                                    <option value="Saint Pierre and Miquelon">Saint Pierre and Miquelon</option>
                                    <option value="Saint Vincent and The Grenadines">Saint Vincent and The Grenadines</option>
                                    <option value="Samoa">Samoa</option>
                                    <option value="San Marino">San Marino</option>
                                    <option value="Sao Tome and Principe">Sao Tome and Principe</option>
                                    <option value="Saudi Arabia">Saudi Arabia</option>
                                    <option value="Senegal">Senegal</option>
                                    <option value="Serbia">Serbia</option>
                                    <option value="Seychelles">Seychelles</option>
                                    <option value="Sierra Leone">Sierra Leone</option>
                                    <option value="Singapore">Singapore</option>
                                    <option value="Slovakia">Slovakia</option>
                                    <option value="Slovenia">Slovenia</option>
                                    <option value="Solomon Islands">Solomon Islands</option>
                                    <option value="Somalia">Somalia</option>
                                    <option value="South Africa">South Africa</option>
                                    <option value="South Georgia and The South Sandwich Islands">South Georgia and The South Sandwich Islands</option>
                                    <option value="Spain">Spain</option>
                                    <option value="Sri Lanka">Sri Lanka</option>
                                    <option value="Sudan">Sudan</option>
                                    <option value="Suriname">Suriname</option>
                                    <option value="Svalbard and Jan Mayen">Svalbard and Jan Mayen</option>
                                    <option value="Swaziland">Swaziland</option>
                                    <option value="Sweden">Sweden</option>
                                    <option value="Switzerland">Switzerland</option>
                                    <option value="Syrian Arab Republic">Syrian Arab Republic</option>
                                    <option value="Taiwan">Taiwan</option>
                                    <option value="Tajikistan">Tajikistan</option>
                                    <option value="Tanzania, United Republic of">Tanzania, United Republic of</option>
                                    <option value="Thailand">Thailand</option>
                                    <option value="Timor-leste">Timor-leste</option>
                                    <option value="Togo">Togo</option>
                                    <option value="Tokelau">Tokelau</option>
                                    <option value="Tonga">Tonga</option>
                                    <option value="Trinidad and Tobago">Trinidad and Tobago</option>
                                    <option value="Tunisia">Tunisia</option>
                                    <option value="Turkey">Turkey</option>
                                    <option value="Turkmenistan">Turkmenistan</option>
                                    <option value="Turks and Caicos Islands">Turks and Caicos Islands</option>
                                    <option value="Tuvalu">Tuvalu</option>
                                    <option value="Uganda">Uganda</option>
                                    <option value="Ukraine">Ukraine</option>
                                    <option value="United Arab Emirates">United Arab Emirates</option>
                                    <option value="United Kingdom">United Kingdom</option>
                                    <option value="United States">United States</option>
                                    <option value="United States Minor Outlying Islands">United States Minor Outlying Islands</option>
                                    <option value="Uruguay">Uruguay</option>
                                    <option value="Uzbekistan">Uzbekistan</option>
                                    <option value="Vanuatu">Vanuatu</option>
                                    <option value="Venezuela">Venezuela</option>
                                    <option value="Viet Nam">Viet Nam</option>
                                    <option value="Virgin Islands, British">Virgin Islands, British</option>
                                    <option value="Virgin Islands, U.S.">Virgin Islands, U.S.</option>
                                    <option value="Wallis and Futuna">Wallis and Futuna</option>
                                    <option value="Western Sahara">Western Sahara</option>
                                    <option value="Yemen">Yemen</option>
                                    <option value="Zambia">Zambia</option>
                                    <option value="Zimbabwe">Zimbabwe</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="website" class="form-label">Website</label>
                                <input type="text" class="form-control" name="website" 
                                        pattern="[a-zA-Z0-9.-\/\]+\.[a-zA-Z0-9.-\/\]+"
                                       value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Website']) : '' ?>">
                            </div>

                            <div class="mb-3">
                                <label for="morada" class="form-label">Morada/Região</label>
                                <input type="text" class="form-control" name="morada" 
                                    value="<?= $editManufacturer ? htmlspecialchars($editManufacturer['Address'] ?? $editManufacturer['Morada'] ?? '') : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="contacts" class="form-label">Contactos</label>
                                <textarea class="form-control" name="contacts" rows="3"><?= $editManufacturer ? htmlspecialchars($editManufacturer['Contacts']) : '' ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notas</label>
                                <textarea class="form-control" name="notes" rows="3"><?= $editManufacturer ? htmlspecialchars($editManufacturer['Notes']) : '' ?></textarea>
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
                                        <th>Morada/Região</th>
                                        <th>Website</th>
                                        <th>Componentes</th>
                                        <th>Notas</th>
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
                                            <td><?= htmlspecialchars($manufacturer['Address']) ?></td>                                            
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
                                                <button type="button" 
                                                        class="btn btn-link p-0 m-0" 
                                                        onclick="showManufacturerComponents(<?= $manufacturer['Manufacturer_ID'] ?>)">
                                                    <span class="badge bg-info"><?= $componentCount ?></span>
                                                </button>
                                            </td>
                                                                                        <td>
                                                <?php if ($manufacturer['Notes']): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars(substr($manufacturer['Notes'], 0, 30)) ?>...</span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
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
                            
                            <!-- Alterar -->
                            <div class="mb-3">
                                <label for="origin_country" class="form-label">País de Origem</label>
                                <select id="origin_country" name="origin_country" class="form-control">
                                    <option value="Afghanistan">Afghanistan</option>
                                    <option value="Åland Islands">Åland Islands</option>
                <option value="Albania">Albania</option>
                <option value="Algeria">Algeria</option>
                <option value="American Samoa">American Samoa</option>
                <option value="Andorra">Andorra</option>
                <option value="Angola">Angola</option>
                <option value="Anguilla">Anguilla</option>
                <option value="Antarctica">Antarctica</option>
                <option value="Antigua and Barbuda">Antigua and Barbuda</option>
                <option value="Argentina">Argentina</option>
                <option value="Armenia">Armenia</option>
                <option value="Aruba">Aruba</option>
                <option value="Australia">Australia</option>
                <option value="Austria">Austria</option>
                <option value="Azerbaijan">Azerbaijan</option>
                <option value="Bahamas">Bahamas</option>
                <option value="Bahrain">Bahrain</option>
                <option value="Bangladesh">Bangladesh</option>
                <option value="Barbados">Barbados</option>
                <option value="Belarus">Belarus</option>
                <option value="Belgium">Belgium</option>
                <option value="Belize">Belize</option>
                <option value="Benin">Benin</option>
                <option value="Bermuda">Bermuda</option>
                <option value="Bhutan">Bhutan</option>
                <option value="Bolivia">Bolivia</option>
                <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
                <option value="Botswana">Botswana</option>
                <option value="Bouvet Island">Bouvet Island</option>
                <option value="Brazil">Brazil</option>
                <option value="British Indian Ocean Territory">British Indian Ocean Territory</option>
                <option value="Brunei Darussalam">Brunei Darussalam</option>
                <option value="Bulgaria">Bulgaria</option>
                <option value="Burkina Faso">Burkina Faso</option>
                <option value="Burundi">Burundi</option>
                <option value="Cambodia">Cambodia</option>
                <option value="Cameroon">Cameroon</option>
                <option value="Canada">Canada</option>
                <option value="Cape Verde">Cape Verde</option>
                <option value="Cayman Islands">Cayman Islands</option>
                <option value="Central African Republic">Central African Republic</option>
                <option value="Chad">Chad</option>
                <option value="Chile">Chile</option>
                <option value="China">China</option>
                <option value="Christmas Island">Christmas Island</option>
                <option value="Cocos (Keeling) Islands">Cocos (Keeling) Islands</option>
                <option value="Colombia">Colombia</option>
                <option value="Comoros">Comoros</option>
                <option value="Congo">Congo</option>
                <option value="Congo, The Democratic Republic of The">Congo, The Democratic Republic of The</option>
                <option value="Cook Islands">Cook Islands</option>
                <option value="Costa Rica">Costa Rica</option>
                <option value="Cote D'ivoire">Cote D'ivoire</option>
                <option value="Croatia">Croatia</option>
                <option value="Cuba">Cuba</option>
                <option value="Cyprus">Cyprus</option>
                <option value="Czech Republic">Czech Republic</option>
                <option value="Denmark">Denmark</option>
                <option value="Djibouti">Djibouti</option>
                <option value="Dominica">Dominica</option>
                <option value="Dominican Republic">Dominican Republic</option>
                <option value="Ecuador">Ecuador</option>
                <option value="Egypt">Egypt</option>
                <option value="El Salvador">El Salvador</option>
                <option value="Equatorial Guinea">Equatorial Guinea</option>
                <option value="Eritrea">Eritrea</option>
                <option value="Estonia">Estonia</option>
                <option value="Ethiopia">Ethiopia</option>
                <option value="Falkland Islands (Malvinas)">Falkland Islands (Malvinas)</option>
                <option value="Faroe Islands">Faroe Islands</option>
                <option value="Fiji">Fiji</option>
                <option value="Finland">Finland</option>
                <option value="France">France</option>
                <option value="French Guiana">French Guiana</option>
                <option value="French Polynesia">French Polynesia</option>
                <option value="French Southern Territories">French Southern Territories</option>
                <option value="Gabon">Gabon</option>
                <option value="Gambia">Gambia</option>
                <option value="Georgia">Georgia</option>
                <option value="Germany">Germany</option>
                <option value="Ghana">Ghana</option>
                <option value="Gibraltar">Gibraltar</option>
                <option value="Greece">Greece</option>
                <option value="Greenland">Greenland</option>
                <option value="Grenada">Grenada</option>
                <option value="Guadeloupe">Guadeloupe</option>
                <option value="Guam">Guam</option>
                <option value="Guatemala">Guatemala</option>
                <option value="Guernsey">Guernsey</option>
                <option value="Guinea">Guinea</option>
                <option value="Guinea-bissau">Guinea-bissau</option>
                <option value="Guyana">Guyana</option>
                <option value="Haiti">Haiti</option>
                <option value="Heard Island and Mcdonald Islands">Heard Island and Mcdonald Islands</option>
                <option value="Holy See (Vatican City State)">Holy See (Vatican City State)</option>
                <option value="Honduras">Honduras</option>
                <option value="Hong Kong">Hong Kong</option>
                <option value="Hungary">Hungary</option>
                <option value="Iceland">Iceland</option>
                <option value="India">India</option>
                <option value="Indonesia">Indonesia</option>
                <option value="Iran, Islamic Republic of">Iran, Islamic Republic of</option>
                <option value="Iraq">Iraq</option>
                <option value="Ireland">Ireland</option>
                <option value="Isle of Man">Isle of Man</option>
                <option value="Israel">Israel</option>
                <option value="Italy">Italy</option>
                <option value="Jamaica">Jamaica</option>
                <option value="Japan">Japan</option>
                <option value="Jersey">Jersey</option>
                <option value="Jordan">Jordan</option>
                <option value="Kazakhstan">Kazakhstan</option>
                <option value="Kenya">Kenya</option>
                <option value="Kiribati">Kiribati</option>
                <option value="Korea, Democratic People's Republic of">Korea, Democratic People's Republic of</option>
                <option value="Korea, Republic of">Korea, Republic of</option>
                <option value="Kuwait">Kuwait</option>
                <option value="Kyrgyzstan">Kyrgyzstan</option>
                <option value="Lao People's Democratic Republic">Lao People's Democratic Republic</option>
                <option value="Latvia">Latvia</option>
                <option value="Lebanon">Lebanon</option>
                <option value="Lesotho">Lesotho</option>
                <option value="Liberia">Liberia</option>
                <option value="Libyan Arab Jamahiriya">Libyan Arab Jamahiriya</option>
                <option value="Liechtenstein">Liechtenstein</option>
                <option value="Lithuania">Lithuania</option>
                <option value="Luxembourg">Luxembourg</option>
                <option value="Macao">Macao</option>
                <option value="Macedonia, The Former Yugoslav Republic of">Macedonia, The Former Yugoslav Republic of</option>
                <option value="Madagascar">Madagascar</option>
                <option value="Malawi">Malawi</option>
                <option value="Malaysia">Malaysia</option>
                <option value="Maldives">Maldives</option>
                <option value="Mali">Mali</option>
                <option value="Malta">Malta</option>
                <option value="Marshall Islands">Marshall Islands</option>
                <option value="Martinique">Martinique</option>
                <option value="Mauritania">Mauritania</option>
                <option value="Mauritius">Mauritius</option>
                <option value="Mayotte">Mayotte</option>
                <option value="Mexico">Mexico</option>
                <option value="Micronesia, Federated States of">Micronesia, Federated States of</option>
                <option value="Moldova, Republic of">Moldova, Republic of</option>
                <option value="Monaco">Monaco</option>
                <option value="Mongolia">Mongolia</option>
                <option value="Montenegro">Montenegro</option>
                <option value="Montserrat">Montserrat</option>
                <option value="Morocco">Morocco</option>
                <option value="Mozambique">Mozambique</option>
                <option value="Myanmar">Myanmar</option>
                <option value="Namibia">Namibia</option>
                <option value="Nauru">Nauru</option>
                <option value="Nepal">Nepal</option>
                <option value="Netherlands">Netherlands</option>
                <option value="Netherlands Antilles">Netherlands Antilles</option>
                <option value="New Caledonia">New Caledonia</option>
                <option value="New Zealand">New Zealand</option>
                <option value="Nicaragua">Nicaragua</option>
                <option value="Niger">Niger</option>
                <option value="Nigeria">Nigeria</option>
                <option value="Niue">Niue</option>
                <option value="Norfolk Island">Norfolk Island</option>
                <option value="Northern Mariana Islands">Northern Mariana Islands</option>
                <option value="Norway">Norway</option>
                <option value="Oman">Oman</option>
                <option value="Pakistan">Pakistan</option>
                <option value="Palau">Palau</option>
                <option value="Palestinian Territory, Occupied">Palestinian Territory, Occupied</option>
                <option value="Panama">Panama</option>
                <option value="Papua New Guinea">Papua New Guinea</option>
                <option value="Paraguay">Paraguay</option>
                <option value="Peru">Peru</option>
                <option value="Philippines">Philippines</option>
                <option value="Pitcairn">Pitcairn</option>
                <option value="Poland">Poland</option>
                <option value="Portugal" selected>Portugal</option>
                <option value="Puerto Rico">Puerto Rico</option>
                <option value="Qatar">Qatar</option>
                <option value="Reunion">Reunion</option>
                <option value="Romania">Romania</option>
                <option value="Russian Federation">Russian Federation</option>
                <option value="Rwanda">Rwanda</option>
                <option value="Saint Helena">Saint Helena</option>
                <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
                <option value="Saint Lucia">Saint Lucia</option>
                <option value="Saint Pierre and Miquelon">Saint Pierre and Miquelon</option>
                <option value="Saint Vincent and The Grenadines">Saint Vincent and The Grenadines</option>
                <option value="Samoa">Samoa</option>
                <option value="San Marino">San Marino</option>
                <option value="Sao Tome and Principe">Sao Tome and Principe</option>
                <option value="Saudi Arabia">Saudi Arabia</option>
                <option value="Senegal">Senegal</option>
                <option value="Serbia">Serbia</option>
                <option value="Seychelles">Seychelles</option>
                <option value="Sierra Leone">Sierra Leone</option>
                <option value="Singapore">Singapore</option>
                <option value="Slovakia">Slovakia</option>
                <option value="Slovenia">Slovenia</option>
                <option value="Solomon Islands">Solomon Islands</option>
                <option value="Somalia">Somalia</option>
                <option value="South Africa">South Africa</option>
                <option value="South Georgia and The South Sandwich Islands">South Georgia and The South Sandwich Islands</option>
                <option value="Spain">Spain</option>
                <option value="Sri Lanka">Sri Lanka</option>
                <option value="Sudan">Sudan</option>
                <option value="Suriname">Suriname</option>
                <option value="Svalbard and Jan Mayen">Svalbard and Jan Mayen</option>
                <option value="Swaziland">Swaziland</option>
                <option value="Sweden">Sweden</option>
                <option value="Switzerland">Switzerland</option>
                <option value="Syrian Arab Republic">Syrian Arab Republic</option>
                <option value="Taiwan">Taiwan</option>
                <option value="Tajikistan">Tajikistan</option>
                <option value="Tanzania, United Republic of">Tanzania, United Republic of</option>
                <option value="Thailand">Thailand</option>
                <option value="Timor-leste">Timor-leste</option>
                <option value="Togo">Togo</option>
                <option value="Tokelau">Tokelau</option>
                <option value="Tonga">Tonga</option>
                <option value="Trinidad and Tobago">Trinidad and Tobago</option>
                <option value="Tunisia">Tunisia</option>
                <option value="Turkey">Turkey</option>
                <option value="Turkmenistan">Turkmenistan</option>
                <option value="Turks and Caicos Islands">Turks and Caicos Islands</option>
                <option value="Tuvalu">Tuvalu</option>
                <option value="Uganda">Uganda</option>
                <option value="Ukraine">Ukraine</option>
                <option value="United Arab Emirates">United Arab Emirates</option>
                <option value="United Kingdom">United Kingdom</option>
                <option value="United States">United States</option>
                <option value="United States Minor Outlying Islands">United States Minor Outlying Islands</option>
                <option value="Uruguay">Uruguay</option>
                <option value="Uzbekistan">Uzbekistan</option>
                <option value="Vanuatu">Vanuatu</option>
                <option value="Venezuela">Venezuela</option>
                <option value="Viet Nam">Viet Nam</option>
                <option value="Virgin Islands, British">Virgin Islands, British</option>
                <option value="Virgin Islands, U.S.">Virgin Islands, U.S.</option>
                <option value="Wallis and Futuna">Wallis and Futuna</option>
                <option value="Western Sahara">Western Sahara</option>
                <option value="Yemen">Yemen</option>
                <option value="Zambia">Zambia</option>
                <option value="Zimbabwe">Zimbabwe</option>
            </select>
                            </div>
                            
                            <!-- Alterar -->
                            <div class="mb-3">
                                <label for="website" class="form-label">Website</label>
                                <input type="text" class="form-control" name="website" 
                                pattern="[a-zA-Z0-9.-\/\]+\.[a-zA-Z0-9.-\/\]+"
                                       value="<?= $editSupplier ? htmlspecialchars($editSupplier['Website']) : '' ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="morada" class="form-label">Morada/Região</label>
                                <input type="text" class="form-control" name="morada" 
                                       value="<?= $editSupplier ? htmlspecialchars($editSupplier['Morada']) : '' ?>">
                            </div>

                            <div class="mb-3">
                                <label for="contacts" class="form-label">Contactos</label>
                                <textarea class="form-control" name="contacts" rows="3"><?= $editSupplier ? htmlspecialchars($editSupplier['Contacts']) : '' ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notas</label>
                                <textarea class="form-control" name="notes" rows="3"><?= $editSupplier ? htmlspecialchars($editSupplier['Notes']) : '' ?></textarea>
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
                                        <th>Morada/Região</th>
                                        <th>Website</th>
                                        <th>Componentes</th>
                                        <th>Ações</th>
                                        <th>Notas</th>
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
                                            <td><?= htmlspecialchars($supplier['Address']) ?></td>
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
                                                <button type="button" 
                                                        class="btn btn-link p-0 m-0" 
                                                        onclick="showSupplierComponents(<?= $supplier['Supplier_ID'] ?>)">
                                                    <span class="badge bg-info"><?= $componentCount ?></span>
                                                </button>
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
                                            <td>
                                                <?php if ($supplier['Notes']): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars(substr($supplier['Notes'], 0, 30)) ?>...</span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
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

<?php elseif ($entity === 'search'): ?>
    <!-- SEARCH -->
    <!-- Search Form - Always visible -->
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-search"></i> Pesquisar</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="tab" value="bomlist/bomlist">
                        <input type="hidden" name="entity" value="search">
                        <input type="hidden" name="action" value="search">
                        <div class="mb-3">
                            <label for="query" class="form-label">Termo de Pesquisa</label>
                            <input type="text" class="form-control" name="query" id="query" 
                                   value="<?= isset($GLOBALS['search_query']) ? htmlspecialchars($GLOBALS['search_query']) : '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="area" class="form-label">Pesquisar em</label>
                            <select class="form-select" name="area" id="area">
                                <option value="components" <?= (isset($GLOBALS['search_area']) && $GLOBALS['search_area'] === 'components') ? 'selected' : '' ?>>Componentes</option>
                                <option value="prototypes" <?= (isset($GLOBALS['search_area']) && $GLOBALS['search_area'] === 'prototypes') ? 'selected' : '' ?>>Protótipos</option>
                                <option value="assemblies" <?= (isset($GLOBALS['search_area']) && $GLOBALS['search_area'] === 'assemblies') ? 'selected' : '' ?>>Assemblies</option>
                                <option value="manufacturers" <?= (isset($GLOBALS['search_area']) && $GLOBALS['search_area'] === 'manufacturers') ? 'selected' : '' ?>>Fabricantes</option>
                                <option value="suppliers" <?= (isset($GLOBALS['search_area']) && $GLOBALS['search_area'] === 'suppliers') ? 'selected' : '' ?>>Fornecedores</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Pesquisar
                        </button>
                        <?php if (isset($GLOBALS['search_query'])): ?>
                            <a href="?tab=bomlist/bomlist&entity=search" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Limpar
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Results -->
    <?php if (!empty($GLOBALS['search_results'])): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-search"></i> Resultados da Pesquisa (<?= count($GLOBALS['search_results']) ?>)</h5>
                        <small class="text-muted">Pesquisa por "<?= htmlspecialchars($GLOBALS['search_query']) ?>" em <?= ucfirst($GLOBALS['search_area']) ?></small>
                    </div>
                    <div class="card-body">
                        <?php foreach ($GLOBALS['search_results'] as $row): ?>
                            <div class="border-bottom pb-2 mb-2">
                                <?php if ($GLOBALS['search_area'] === 'components'): ?>
                                    <h6><?= htmlspecialchars($row['Denomination']) ?> <small class="text-muted">(<?= htmlspecialchars($row['Reference']) ?>)</small></h6>
                                    <?php if (!empty($row['Manufacturer_Name'])): ?>
                                        <small class="text-muted">Fabricante: <?= htmlspecialchars($row['Manufacturer_Name']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($row['Supplier_Name'])): ?>
                                        <small class="text-muted">Fornecedor: <?= htmlspecialchars($row['Supplier_Name']) ?></small><br>
                                    <?php endif; ?>
                                    <button type='button' class='btn btn-outline-info btn-sm' onclick='showComponentDetails(<?= htmlspecialchars($row['Component_ID']) ?>)'>
                                        <i class='bi bi-info-circle'></i> Ver Detalhes
                                    </button>
                                <?php elseif ($GLOBALS['search_area'] === 'assemblies'): ?>
                                    <h6><?= htmlspecialchars($row['Assembly_Designation']) ?> <small class="text-muted">(<?= htmlspecialchars($row['Assembly_Reference']) ?>)</small></h6>
                                    <button type='button' class='btn btn-outline-info btn-sm' onclick="showAssemblyAssociations(<?= $row['Assembly_ID'] ?>)">
                                        <i class='bi bi-info-circle'></i> Ver Detalhes
                                    </button>
                                <?php elseif ($GLOBALS['search_area'] === 'suppliers' || $GLOBALS['search_area'] === 'manufacturers'): ?>
                                    <h6><?= htmlspecialchars($row['Denomination']) ?></h6>
                                    <p class="mb-0"><?= htmlspecialchars($row['Address'] ?? '') ?></p>
                                    <p class="text-muted"><?= htmlspecialchars($row['Origin_Country'] ?? '') ?></p>
                                    <?php if (!empty($row['Website'])): ?>
                                        <a href="<?= htmlspecialchars($row['Website']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-1">
                                            <i class="bi bi-globe"></i> Website
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <h6><?= htmlspecialchars($row['Denomination'] ?? $row['Name'] ?? $row['Assembly_Designation']) ?></h6>
                                    <p class="mb-0"><?= htmlspecialchars($row['Notes'] ?? $row['Description'] ?? '') ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (isset($GLOBALS['search_query'])): ?>
        <div class="alert alert-info mt-4">
            <i class="bi bi-info-circle"></i> Nenhum resultado encontrado para '<strong><?= htmlspecialchars($GLOBALS['search_query']) ?></strong>' em <?= ucfirst($GLOBALS['search_area']) ?>.
        </div>
    <?php endif; ?>
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
    
    <!-- Modal para detalhes do componente -->
    <div class="modal fade" id="componentDetailsModal" tabindex="-1" aria-labelledby="componentDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="componentDetailsModalLabel">Detalhes do Componente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="componentDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para detalhes da Assembly -->
    <div class="modal fade" id="assemblyDetailsModal" tabindex="-1" aria-labelledby="assemblyDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assemblyDetailsModalLabel">Detalhes da Assembly</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="assemblyDetailsContent">
                <div class="text-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
            </div>
        </div>
    </div>

    <!-- Modal para ver Componentes associados a um Fabricante -->
    <div class="modal fade" id="associatedComponentsModal" tabindex="-1" aria-labelledby="associatedComponentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="associatedComponentsModalLabel">Componentes Associados</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="associatedComponentsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para componentes associados a um fornecedor -->
    <div class="modal fade" id="associatedSupplierComponentsModal" tabindex="-1" aria-labelledby="associatedSupplierComponentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="associatedSupplierComponentsModalLabel">Componentes do Fornecedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="associatedSupplierComponentsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>



</div>



<!-- Modal para Novo Fabricante (tab Componentes) -->
<div class="modal fade" id="newManufacturerModal" tabindex="-1" aria-labelledby="newManufacturerModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST">
      <input type="hidden" name="entity" value="manufacturers">
      <input type="hidden" name="action" value="create">
    <form id="newManufacturerForm" method="POST" action="tabs/bomlist/processor.php">
      <input type="hidden" name="entity" value="manufacturers">
      <input type="hidden" name="action" value="create">
       <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="newManufacturerModalLabel">Novo Fabricante</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="denomination" class="form-label">Denominação *</label>
            <input type="text" class="form-control" id="denomination" name="denomination" required>
          </div>
                            
          <div class="mb-3">
            <label for="origin_country" class="form-label">País de Origem</label>
            <select id="origin_country" name="origin_country" class="form-control">
              <option value="Afghanistan">Afghanistan</option>
              <option value="Åland Islands">Åland Islands</option>
              <option value="Albania">Albania</option>
              <option value="Algeria">Algeria</option>
              <option value="American Samoa">American Samoa</option>
              <option value="Andorra">Andorra</option>
              <option value="Angola">Angola</option>
              <option value="Anguilla">Anguilla</option>
              <option value="Antarctica">Antarctica</option>
              <option value="Antigua and Barbuda">Antigua and Barbuda</option>
              <option value="Argentina">Argentina</option>
              <option value="Armenia">Armenia</option>
              <option value="Aruba">Aruba</option>
              <option value="Australia">Australia</option>
              <option value="Austria">Austria</option>
              <option value="Azerbaijan">Azerbaijan</option>
              <option value="Bahamas">Bahamas</option>
              <option value="Bahrain">Bahrain</option>
              <option value="Bangladesh">Bangladesh</option>
              <option value="Barbados">Barbados</option>
              <option value="Belarus">Belarus</option>
              <option value="Belgium">Belgium</option>
              <option value="Belize">Belize</option>
              <option value="Benin">Benin</option>
              <option value="Bermuda">Bermuda</option>
              <option value="Bhutan">Bhutan</option>
              <option value="Bolivia">Bolivia</option>
              <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
              <option value="Botswana">Botswana</option>
              <option value="Bouvet Island">Bouvet Island</option>
              <option value="Brazil">Brazil</option>
              <option value="British Indian Ocean Territory">British Indian Ocean Territory</option>
              <option value="Brunei Darussalam">Brunei Darussalam</option>
              <option value="Bulgaria">Bulgaria</option>
              <option value="Burkina Faso">Burkina Faso</option>
              <option value="Burundi">Burundi</option>
              <option value="Cambodia">Cambodia</option>
              <option value="Cameroon">Cameroon</option>
              <option value="Canada">Canada</option>
              <option value="Cape Verde">Cape Verde</option>
              <option value="Cayman Islands">Cayman Islands</option>
              <option value="Central African Republic">Central African Republic</option>
              <option value="Chad">Chad</option>
              <option value="Chile">Chile</option>
              <option value="China">China</option>
              <option value="Christmas Island">Christmas Island</option>
              <option value="Cocos (Keeling) Islands">Cocos (Keeling) Islands</option>
              <option value="Colombia">Colombia</option>
              <option value="Comoros">Comoros</option>
              <option value="Congo">Congo</option>
              <option value="Congo, The Democratic Republic of The">Congo, The Democratic Republic of The</option>
              <option value="Cook Islands">Cook Islands</option>
              <option value="Costa Rica">Costa Rica</option>
              <option value="Cote D'ivoire">Cote D'ivoire</option>
              <option value="Croatia">Croatia</option>
              <option value="Cuba">Cuba</option>
              <option value="Cyprus">Cyprus</option>
              <option value="Czech Republic">Czech Republic</option>
              <option value="Denmark">Denmark</option>
              <option value="Djibouti">Djibouti</option>
              <option value="Dominica">Dominica</option>
              <option value="Dominican Republic">Dominican Republic</option>
              <option value="Ecuador">Ecuador</option>
              <option value="Egypt">Egypt</option>
              <option value="El Salvador">El Salvador</option>
              <option value="Equatorial Guinea">Equatorial Guinea</option>
              <option value="Eritrea">Eritrea</option>
              <option value="Estonia">Estonia</option>
              <option value="Ethiopia">Ethiopia</option>
              <option value="Falkland Islands (Malvinas)">Falkland Islands (Malvinas)</option>
              <option value="Faroe Islands">Faroe Islands</option>
              <option value="Fiji">Fiji</option>
              <option value="Finland">Finland</option>
              <option value="France">France</option>
              <option value="French Guiana">French Guiana</option>
              <option value="French Polynesia">French Polynesia</option>
              <option value="French Southern Territories">French Southern Territories</option>
              <option value="Gabon">Gabon</option>
              <option value="Gambia">Gambia</option>
              <option value="Georgia">Georgia</option>
              <option value="Germany">Germany</option>
              <option value="Ghana">Ghana</option>
              <option value="Gibraltar">Gibraltar</option>
              <option value="Greece">Greece</option>
              <option value="Greenland">Greenland</option>
              <option value="Grenada">Grenada</option>
              <option value="Guadeloupe">Guadeloupe</option>
              <option value="Guam">Guam</option>
              <option value="Guatemala">Guatemala</option>
              <option value="Guernsey">Guernsey</option>
              <option value="Guinea">Guinea</option>
              <option value="Guinea-bissau">Guinea-bissau</option>
              <option value="Guyana">Guyana</option>
              <option value="Haiti">Haiti</option>
              <option value="Heard Island and Mcdonald Islands">Heard Island and Mcdonald Islands</option>
              <option value="Holy See (Vatican City State)">Holy See (Vatican City State)</option>
              <option value="Honduras">Honduras</option>
              <option value="Hong Kong">Hong Kong</option>
              <option value="Hungary">Hungary</option>
              <option value="Iceland">Iceland</option>
              <option value="India">India</option>
              <option value="Indonesia">Indonesia</option>
              <option value="Iran, Islamic Republic of">Iran, Islamic Republic of</option>
              <option value="Iraq">Iraq</option>
              <option value="Ireland">Ireland</option>
              <option value="Isle of Man">Isle of Man</option>
              <option value="Israel">Israel</option>
              <option value="Italy">Italy</option>
              <option value="Jamaica">Jamaica</option>
              <option value="Japan">Japan</option>
              <option value="Jersey">Jersey</option>
              <option value="Jordan">Jordan</option>
              <option value="Kazakhstan">Kazakhstan</option>
              <option value="Kenya">Kenya</option>
              <option value="Kiribati">Kiribati</option>
              <option value="Korea, Democratic People's Republic of">Korea, Democratic People's Republic of</option>
              <option value="Korea, Republic of">Korea, Republic of</option>
              <option value="Kuwait">Kuwait</option>
              <option value="Kyrgyzstan">Kyrgyzstan</option>
              <option value="Lao People's Democratic Republic">Lao People's Democratic Republic</option>
              <option value="Latvia">Latvia</option>
              <option value="Lebanon">Lebanon</option>
              <option value="Lesotho">Lesotho</option>
              <option value="Liberia">Liberia</option>
              <option value="Libyan Arab Jamahiriya">Libyan Arab Jamahiriya</option>
              <option value="Liechtenstein">Liechtenstein</option>
              <option value="Lithuania">Lithuania</option>
              <option value="Luxembourg">Luxembourg</option>
              <option value="Macao">Macao</option>
              <option value="Macedonia, The Former Yugoslav Republic of">Macedonia, The Former Yugoslav Republic of</option>
              <option value="Madagascar">Madagascar</option>
              <option value="Malawi">Malawi</option>
              <option value="Malaysia">Malaysia</option>
              <option value="Maldives">Maldives</option>
              <option value="Mali">Mali</option>
              <option value="Malta">Malta</option>
              <option value="Marshall Islands">Marshall Islands</option>
              <option value="Martinique">Martinique</option>
              <option value="Mauritania">Mauritania</option>
              <option value="Mauritius">Mauritius</option>
              <option value="Mayotte">Mayotte</option>
              <option value="Mexico">Mexico</option>
              <option value="Micronesia, Federated States of">Micronesia, Federated States of</option>
              <option value="Moldova, Republic of">Moldova, Republic of</option>
              <option value="Monaco">Monaco</option>
              <option value="Mongolia">Mongolia</option>
              <option value="Montenegro">Montenegro</option>
              <option value="Montserrat">Montserrat</option>
              <option value="Morocco">Morocco</option>
              <option value="Mozambique">Mozambique</option>
              <option value="Myanmar">Myanmar</option>
              <option value="Namibia">Namibia</option>
              <option value="Nauru">Nauru</option>
              <option value="Nepal">Nepal</option>
              <option value="Netherlands">Netherlands</option>
              <option value="Netherlands Antilles">Netherlands Antilles</option>
              <option value="New Caledonia">New Caledonia</option>
              <option value="New Zealand">New Zealand</option>
              <option value="Nicaragua">Nicaragua</option>
              <option value="Niger">Niger</option>
              <option value="Nigeria">Nigeria</option>
              <option value="Niue">Niue</option>
              <option value="Norfolk Island">Norfolk Island</option>
              <option value="Northern Mariana Islands">Northern Mariana Islands</option>
              <option value="Norway">Norway</option>
              <option value="Oman">Oman</option>
              <option value="Pakistan">Pakistan</option>
              <option value="Palau">Palau</option>
              <option value="Palestinian Territory, Occupied">Palestinian Territory, Occupied</option>
              <option value="Panama">Panama</option>
              <option value="Papua New Guinea">Papua New Guinea</option>
              <option value="Paraguay">Paraguay</option>
              <option value="Peru">Peru</option>
              <option value="Philippines">Philippines</option>
              <option value="Pitcairn">Pitcairn</option>
              <option value="Poland">Poland</option>
              <option value="Portugal" selected>Portugal</option>
              <option value="Puerto Rico">Puerto Rico</option>
              <option value="Qatar">Qatar</option>
              <option value="Reunion">Reunion</option>
              <option value="Romania">Romania</option>
              <option value="Russian Federation">Russian Federation</option>
              <option value="Rwanda">Rwanda</option>
              <option value="Saint Helena">Saint Helena</option>
              <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
              <option value="Saint Lucia">Saint Lucia</option>
              <option value="Saint Pierre and Miquelon">Saint Pierre and Miquelon</option>
              <option value="Saint Vincent and The Grenadines">Saint Vincent and The Grenadines</option>
              <option value="Samoa">Samoa</option>
              <option value="San Marino">San Marino</option>
              <option value="Sao Tome and Principe">Sao Tome and Principe</option>
              <option value="Saudi Arabia">Saudi Arabia</option>
              <option value="Senegal">Senegal</option>
              <option value="Serbia">Serbia</option>
              <option value="Seychelles">Seychelles</option>
              <option value="Sierra Leone">Sierra Leone</option>
              <option value="Singapore">Singapore</option>
              <option value="Slovakia">Slovakia</option>
              <option value="Slovenia">Slovenia</option>
              <option value="Solomon Islands">Solomon Islands</option>
              <option value="Somalia">Somalia</option>
              <option value="South Africa">South Africa</option>
              <option value="South Georgia and The South Sandwich Islands">South Georgia and The South Sandwich Islands</option>
              <option value="Spain">Spain</option>
              <option value="Sri Lanka">Sri Lanka</option>
              <option value="Sudan">Sudan</option>
              <option value="Suriname">Suriname</option>
              <option value="Svalbard and Jan Mayen">Svalbard and Jan Mayen</option>
              <option value="Swaziland">Swaziland</option>
              <option value="Sweden">Sweden</option>
              <option value="Switzerland">Switzerland</option>
              <option value="Syrian Arab Republic">Syrian Arab Republic</option>
              <option value="Taiwan">Taiwan</option>
              <option value="Tajikistan">Tajikistan</option>
              <option value="Tanzania, United Republic of">Tanzania, United Republic of</option>
              <option value="Thailand">Thailand</option>
              <option value="Timor-leste">Timor-leste</option>
              <option value="Togo">Togo</option>
              <option value="Tokelau">Tokelau</option>
              <option value="Tonga">Tonga</option>
              <option value="Trinidad and Tobago">Trinidad and Tobago</option>
              <option value="Tunisia">Tunisia</option>
              <option value="Turkey">Turkey</option>
              <option value="Turkmenistan">Turkmenistan</option>
              <option value="Turks and Caicos Islands">Turks and Caicos Islands</option>
              <option value="Tuvalu">Tuvalu</option>
              <option value="Uganda">Uganda</option>
              <option value="Ukraine">Ukraine</option>
              <option value="United Arab Emirates">United Arab Emirates</option>
              <option value="United Kingdom">United Kingdom</option>
              <option value="United States">United States</option>
              <option value="United States Minor Outlying Islands">United States Minor Outlying Islands</option>
              <option value="Uruguay">Uruguay</option>
              <option value="Uzbekistan">Uzbekistan</option>
              <option value="Vanuatu">Vanuatu</option>
              <option value="Venezuela">Venezuela</option>
              <option value="Viet Nam">Viet Nam</option>
              <option value="Virgin Islands, British">Virgin Islands, British</option>
              <option value="Virgin Islands, U.S.">Virgin Islands, U.S.</option>
              <option value="Wallis and Futuna">Wallis and Futuna</option>
              <option value="Western Sahara">Western Sahara</option>
              <option value="Yemen">Yemen</option>
              <option value="Zambia">Zambia</option>
              <option value="Zimbabwe">Zimbabwe</option>
            </select>
          </div>
                            
          <div class="mb-3">
            <label for="website" class="form-label">Website</label>
            <input type="text" class="form-control" name="website"
                   pattern="[a-zA-Z0-9.-\/\]+\.[a-zA-Z0-9.-\/\]+"
                   id="website" value="">
          </div>

          <div class="mb-3">
            <label for="morada" class="form-label">Morada/Região</label>
            <input type="text" class="form-control" name="morada" id="morada" value="">
          </div>
                            
          <div class="mb-3">
            <label for="contacts" class="form-label">Contactos</label>
            <textarea class="form-control" name="contacts" id="contacts" rows="3"></textarea>
          </div>

          <div class="mb-3">
            <label for="notes" class="form-label">Notas</label>
            <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" data-ajax-trigger class="btn btn-primary">
            <i class="bi bi-save"></i> Criar Fabricante
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

 <!-- Modal para Novo Fornecedor (tab Fornecedores) -->
 <div class="modal fade" id="newSupplierModal" tabindex="-1" aria-labelledby="newSupplierModalLabel" aria-hidden="true">
   <div class="modal-dialog">
    <form method="POST">
      <input type="hidden" name="entity" value="suppliers">
      <input type="hidden" name="action" value="create">
    <form id="newSupplierForm" method="POST" action="tabs/bomlist/processor.php">
      <input type="hidden" name="entity" value="suppliers">
      <input type="hidden" name="action" value="create">
       <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="newSupplierModalLabel">Novo Fornecedor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="denominationSupplier" class="form-label">Denominação *</label>
            <input type="text" class="form-control" id="denominationSupplier" name="denomination" required>
          </div>
          
          <div class="mb-3">
            <label for="origin_countrySupplier" class="form-label">País de Origem</label>
            <select id="origin_countrySupplier" name="origin_country" class="form-control">
              <option value="Afghanistan">Afghanistan</option>
              <option value="Åland Islands">Åland Islands</option>
              <option value="Albania">Albania</option>
              <option value="Algeria">Algeria</option>
              <option value="American Samoa">American Samoa</option>
              <option value="Andorra">Andorra</option>
              <option value="Angola">Angola</option>
              <option value="Anguilla">Anguilla</option>
              <option value="Antarctica">Antarctica</option>
              <option value="Antigua and Barbuda">Antigua and Barbuda</option>
              <option value="Argentina">Argentina</option>
              <option value="Armenia">Armenia</option>
              <option value="Aruba">Aruba</option>
              <option value="Australia">Australia</option>
              <option value="Austria">Austria</option>
              <option value="Azerbaijan">Azerbaijan</option>
              <option value="Bahamas">Bahamas</option>
              <option value="Bahrain">Bahrain</option>
              <option value="Bangladesh">Bangladesh</option>
              <option value="Barbados">Barbados</option>
              <option value="Belarus">Belarus</option>
              <option value="Belgium">Belgium</option>
              <option value="Belize">Belize</option>
              <option value="Benin">Benin</option>
              <option value="Bermuda">Bermuda</option>
              <option value="Bhutan">Bhutan</option>
              <option value="Bolivia">Bolivia</option>
              <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
              <option value="Botswana">Botswana</option>
              <option value="Bouvet Island">Bouvet Island</option>
              <option value="Brazil">Brazil</option>
              <option value="British Indian Ocean Territory">British Indian Ocean Territory</option>
              <option value="Brunei Darussalam">Brunei Darussalam</option>
              <option value="Bulgaria">Bulgaria</option>
              <option value="Burkina Faso">Burkina Faso</option>
              <option value="Burundi">Burundi</option>
              <option value="Cambodia">Cambodia</option>
              <option value="Cameroon">Cameroon</option>
              <option value="Canada">Canada</option>
              <option value="Cape Verde">Cape Verde</option>
              <option value="Cayman Islands">Cayman Islands</option>
              <option value="Central African Republic">Central African Republic</option>
              <option value="Chad">Chad</option>
              <option value="Chile">Chile</option>
              <option value="China">China</option>
              <option value="Christmas Island">Christmas Island</option>
              <option value="Cocos (Keeling) Islands">Cocos (Keeling) Islands</option>
              <option value="Colombia">Colombia</option>
              <option value="Comoros">Comoros</option>
              <option value="Congo">Congo</option>
              <option value="Congo, The Democratic Republic of The">Congo, The Democratic Republic of The</option>
              <option value="Cook Islands">Cook Islands</option>
              <option value="Costa Rica">Costa Rica</option>
              <option value="Cote D'ivoire">Cote D'ivoire</option>
              <option value="Croatia">Croatia</option>
              <option value="Cuba">Cuba</option>
              <option value="Cyprus">Cyprus</option>
              <option value="Czech Republic">Czech Republic</option>
              <option value="Denmark">Denmark</option>
              <option value="Djibouti">Djibouti</option>
              <option value="Dominica">Dominica</option>
              <option value="Dominican Republic">Dominican Republic</option>
              <option value="Ecuador">Ecuador</option>
              <option value="Egypt">Egypt</option>
              <option value="El Salvador">El Salvador</option>
              <option value="Equatorial Guinea">Equatorial Guinea</option>
              <option value="Eritrea">Eritrea</option>
              <option value="Estonia">Estonia</option>
              <option value="Ethiopia">Ethiopia</option>
              <option value="Falkland Islands (Malvinas)">Falkland Islands (Malvinas)</option>
              <option value="Faroe Islands">Faroe Islands</option>
              <option value="Fiji">Fiji</option>
              <option value="Finland">Finland</option>
              <option value="France">France</option>
              <option value="French Guiana">French Guiana</option>
              <option value="French Polynesia">French Polynesia</option>
              <option value="French Southern Territories">French Southern Territories</option>
              <option value="Gabon">Gabon</option>
              <option value="Gambia">Gambia</option>
              <option value="Georgia">Georgia</option>
              <option value="Germany">Germany</option>
              <option value="Ghana">Ghana</option>
              <option value="Gibraltar">Gibraltar</option>
              <option value="Greece">Greece</option>
              <option value="Greenland">Greenland</option>
              <option value="Grenada">Grenada</option>
              <option value="Guadeloupe">Guadeloupe</option>
              <option value="Guam">Guam</option>
              <option value="Guatemala">Guatemala</option>
              <option value="Guernsey">Guernsey</option>
              <option value="Guinea">Guinea</option>
              <option value="Guinea-bissau">Guinea-bissau</option>
              <option value="Guyana">Guyana</option>
              <option value="Haiti">Haiti</option>
              <option value="Heard Island and Mcdonald Islands">Heard Island and Mcdonald Islands</option>
              <option value="Holy See (Vatican City State)">Holy See (Vatican City State)</option>
              <option value="Honduras">Honduras</option>
              <option value="Hong Kong">Hong Kong</option>
              <option value="Hungary">Hungary</option>
              <option value="Iceland">Iceland</option>
              <option value="India">India</option>
              <option value="Indonesia">Indonesia</option>
              <option value="Iran, Islamic Republic of">Iran, Islamic Republic of</option>
              <option value="Iraq">Iraq</option>
              <option value="Ireland">Ireland</option>
              <option value="Isle of Man">Isle of Man</option>
              <option value="Israel">Israel</option>
              <option value="Italy">Italy</option>
              <option value="Jamaica">Jamaica</option>
              <option value="Japan">Japan</option>
              <option value="Jersey">Jersey</option>
              <option value="Jordan">Jordan</option>
              <option value="Kazakhstan">Kazakhstan</option>
              <option value="Kenya">Kenya</option>
              <option value="Kiribati">Kiribati</option>
              <option value="Korea, Democratic People's Republic of">Korea, Democratic People's Republic of</option>
              <option value="Korea, Republic of">Korea, Republic of</option>
              <option value="Kuwait">Kuwait</option>
              <option value="Kyrgyzstan">Kyrgyzstan</option>
              <option value="Lao People's Democratic Republic">Lao People's Democratic Republic</option>
              <option value="Latvia">Latvia</option>
              <option value="Lebanon">Lebanon</option>
              <option value="Lesotho">Lesotho</option>
              <option value="Liberia">Liberia</option>
              <option value="Libyan Arab Jamahiriya">Libyan Arab Jamahiriya</option>
              <option value="Liechtenstein">Liechtenstein</option>
              <option value="Lithuania">Lithuania</option>
              <option value="Luxembourg">Luxembourg</option>
              <option value="Macao">Macao</option>
              <option value="Macedonia, The Former Yugoslav Republic of">Macedonia, The Former Yugoslav Republic of</option>
              <option value="Madagascar">Madagascar</option>
              <option value="Malawi">Malawi</option>
              <option value="Malaysia">Malaysia</option>
              <option value="Maldives">Maldives</option>
              <option value="Mali">Mali</option>
              <option value="Malta">Malta</option>
              <option value="Marshall Islands">Marshall Islands</option>
              <option value="Martinique">Martinique</option>
              <option value="Mauritania">Mauritania</option>
              <option value="Mauritius">Mauritius</option>
              <option value="Mayotte">Mayotte</option>
              <option value="Mexico">Mexico</option>
              <option value="Micronesia, Federated States of">Micronesia, Federated States of</option>
              <option value="Moldova, Republic of">Moldova, Republic of</option>
              <option value="Monaco">Monaco</option>
              <option value="Mongolia">Mongolia</option>
              <option value="Montenegro">Montenegro</option>
              <option value="Montserrat">Montserrat</option>
              <option value="Morocco">Morocco</option>
              <option value="Mozambique">Mozambique</option>
              <option value="Myanmar">Myanmar</option>
              <option value="Namibia">Namibia</option>
              <option value="Nauru">Nauru</option>
              <option value="Nepal">Nepal</option>
              <option value="Netherlands">Netherlands</option>
              <option value="Netherlands Antilles">Netherlands Antilles</option>
              <option value="New Caledonia">New Caledonia</option>
              <option value="New Zealand">New Zealand</option>
              <option value="Nicaragua">Nicaragua</option>
              <option value="Niger">Niger</option>
              <option value="Nigeria">Nigeria</option>
              <option value="Niue">Niue</option>
              <option value="Norfolk Island">Norfolk Island</option>
              <option value="Northern Mariana Islands">Northern Mariana Islands</option>
              <option value="Norway">Norway</option>
              <option value="Oman">Oman</option>
              <option value="Pakistan">Pakistan</option>
              <option value="Palau">Palau</option>
              <option value="Palestinian Territory, Occupied">Palestinian Territory, Occupied</option>
              <option value="Panama">Panama</option>
              <option value="Papua New Guinea">Papua New Guinea</option>
              <option value="Paraguay">Paraguay</option>
              <option value="Peru">Peru</option>
              <option value="Philippines">Philippines</option>
              <option value="Pitcairn">Pitcairn</option>
              <option value="Poland">Poland</option>
              <option value="Portugal" selected>Portugal</option>
              <option value="Puerto Rico">Puerto Rico</option>
              <option value="Qatar">Qatar</option>
              <option value="Reunion">Reunion</option>
              <option value="Romania">Romania</option>
              <option value="Russian Federation">Russian Federation</option>
              <option value="Rwanda">Rwanda</option>
              <option value="Saint Helena">Saint Helena</option>
              <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
              <option value="Saint Lucia">Saint Lucia</option>
              <option value="Saint Pierre and Miquelon">Saint Pierre and Miquelon</option>
              <option value="Saint Vincent and The Grenadines">Saint Vincent and The Grenadines</option>
              <option value="Samoa">Samoa</option>
              <option value="San Marino">San Marino</option>
              <option value="Sao Tome and Principe">Sao Tome and Principe</option>
              <option value="Saudi Arabia">Saudi Arabia</option>
              <option value="Senegal">Senegal</option>
              <option value="Serbia">Serbia</option>
              <option value="Seychelles">Seychelles</option>
              <option value="Sierra Leone">Sierra Leone</option>
              <option value="Singapore">Singapore</option>
              <option value="Slovakia">Slovakia</option>
              <option value="Slovenia">Slovenia</option>
              <option value="Solomon Islands">Solomon Islands</option>
              <option value="Somalia">Somalia</option>
              <option value="South Africa">South Africa</option>
              <option value="South Georgia and The South Sandwich Islands">South Georgia and The South Sandwich Islands</option>
              <option value="Spain">Spain</option>
              <option value="Sri Lanka">Sri Lanka</option>
              <option value="Sudan">Sudan</option>
              <option value="Suriname">Suriname</option>
              <option value="Svalbard and Jan Mayen">Svalbard and Jan Mayen</option>
              <option value="Swaziland">Swaziland</option>
              <option value="Sweden">Sweden</option>
              <option value="Switzerland">Switzerland</option>
              <option value="Syrian Arab Republic">Syrian Arab Republic</option>
              <option value="Taiwan">Taiwan</option>
              <option value="Tajikistan">Tajikistan</option>
              <option value="Tanzania, United Republic of">Tanzania, United Republic of</option>
              <option value="Thailand">Thailand</option>
              <option value="Timor-leste">Timor-leste</option>
              <option value="Togo">Togo</option>
              <option value="Tokelau">Tokelau</option>
              <option value="Tonga">Tonga</option>
              <option value="Trinidad and Tobago">Trinidad and Tobago</option>
              <option value="Tunisia">Tunisia</option>
              <option value="Turkey">Turkey</option>
              <option value="Turkmenistan">Turkmenistan</option>
              <option value="Turks and Caicos Islands">Turks and Caicos Islands</option>
              <option value="Tuvalu">Tuvalu</option>
              <option value="Uganda">Uganda</option>
              <option value="Ukraine">Ukraine</option>
              <option value="United Arab Emirates">United Arab Emirates</option>
              <option value="United Kingdom">United Kingdom</option>
              <option value="United States">United States</option>
              <option value="United States Minor Outlying Islands">United States Minor Outlying Islands</option>
              <option value="Uruguay">Uruguay</option>
              <option value="Uzbekistan">Uzbekistan</option>
              <option value="Vanuatu">Vanuatu</option>
              <option value="Venezuela">Venezuela</option>
              <option value="Viet Nam">Viet Nam</option>
              <option value="Virgin Islands, British">Virgin Islands, British</option>
              <option value="Virgin Islands, U.S.">Virgin Islands, U.S.</option>
              <option value="Wallis and Futuna">Wallis and Futuna</option>
              <option value="Western Sahara">Western Sahara</option>
              <option value="Yemen">Yemen</option>
              <option value="Zambia">Zambia</option>
              <option value="Zimbabwe">Zimbabwe</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label for="websiteSupplier" class="form-label">Website</label>
            <input type="text" class="form-control" name="website" id="websiteSupplier"
                   pattern="[a-zA-Z0-9.-\/\]+\.[a-zA-Z0-9.-\/\]+"
                   value="">
          </div>

          <div class="mb-3">
            <label for="moradaSupplier" class="form-label">Morada/Região</label>
            <input type="text" class="form-control" name="morada" id="moradaSupplier" value="">
          </div>
                            
          <div class="mb-3">
            <label for="contactsSupplier" class="form-label">Contactos</label>
            <textarea class="form-control" name="contacts" id="contactsSupplier" rows="3"></textarea>
          </div>

          <div class="mb-3">
            <label for="notesSupplier" class="form-label">Notas</label>
            <textarea class="form-control" name="notes" id="notesSupplier" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" data-ajax-trigger class="btn btn-primary">
            <i class="bi bi-save"></i> Criar Fornecedor
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal para ver associações de uma Assembly -->

<div class="modal fade" id="associatedAssemblyModal" tabindex="-1" aria-labelledby="associatedAssemblyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="associatedAssemblyModalLabel">Associações da Assembly</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="associatedAssemblyContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Link to assemblyLoader.js -->

<script>
    const components = <?= json_encode($components) ?>;
    const prototypes = <?= json_encode($prototypes) ?>;
    window.assemblies = <?= json_encode($assemblies, JSON_HEX_TAG) ?>;
</script>
<script>
    const selectedPrototype = <?= json_encode($_GET['prototype_id'] ?? '') ?>;
</script>
<!-- Link to CSS and JS -->
<link rel="stylesheet" href="tabs/bomlist/bomlist.css">
<script src="tabs/bomlist/assemblyLoader.js"></script>

<?php
// Fechar conexão
$pdo = null;
?>
