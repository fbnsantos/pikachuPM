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
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

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
                    <button class="nav-link <?= $entity === 'prototypes' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=prototypes'">
                        <i class="bi bi-diagram-3"></i> Protótipos
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $entity === 'assembly' ? 'active' : '' ?>" 
                            onclick="location.href='?tab=bomlist/bomlist&entity=assembly'">
                        <i class="bi bi-diagram-2"></i> Assembly (BOM)
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
                            
                            <!-- Botões para tipo de montagem -->
                            <div class="mb-3" id="assembly-type-selection">
                                <label class="form-label">Tipo de Assembly</label><br>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_component_component" value="component_component" required>
                                    <label class="form-check-label" for="type_component_component">Componente - Componente</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_component_assembly" value="component_assembly">
                                    <label class="form-check-label" for="type_component_assembly">Componente - Assembly</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assembly_type" id="type_assembly_assembly" value="assembly_assembly">
                                    <label class="form-check-label" for="type_assembly_assembly">Assembly - Assembly</label>
                                </div>
                            </div>


                            <!-- Load dynamically the fields based on previous selection --> 
                            <div class="mb-3" id="field-component-father">
                                <label for="component_father_id" class="form-label">Componente 1 *</label>
                                <div class="input-group">
                                    <select class="form-select" name="component_father_id" id="component_father_id" required>
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
                            <div class="mb-3" id="field-component-father-quantity">
                                <label for="component_father_quantity" class="form-label">Quantidade (Componente 1) *</label>
                                <input type="number" class="form-control" name="component_father_quantity" value="1" required min="1">
                            </div>
                            <div class="mb-3" id="field-component-child">
                                <label for="component_child_id" class="form-label">Componente 2 *</label>
                                <div class="input-group">
                                    <select class="form-select" name="component_child_id" id="component_child_id">
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
                                    <input type="text" class="form-control" name="component_child_custom_ref" placeholder="Referência">
                                    <button type="button" id="componentChildDetailsBtn" class="btn btn-outline-info" disabled>
                                        <i class="bi bi-info-circle"></i> Ver Detalhes
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3" id="field-component-child-quantity">
                                <label for="component_child_quantity" class="form-label">Quantidade (Componente 2) *</label>
                                <input type="number" class="form-control" name="component_child_quantity" value="1" required min="1">
                            </div>

                            <!-- NOVOS CAMPOS PARA ASSEMBLY -->

                            <div class="mb-3" id="field-assembly-father">
                                <label for="assembly_father_id" class="form-label">Assembly 1 *</label>
                                <select class="form-select" name="assembly_father_id">
                                    <option value="">Selecionar assembly...</option>
                                    <?php foreach ($assemblies as $assembly): ?>
                                        <option value="<?= $assembly['Assembly_ID'] ?>">
                                            <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                            - <?= $assembly['Assembly_Designation'] ? htmlspecialchars($assembly['Assembly_Designation']) : 'Nível raiz' ?>
                                        </option>
                                    <?php endforeach; ?>
                                        <?php foreach ($prototypes as $prototype): ?>
                                        <option value="<?= $prototype['Prototype_ID'] ?> prototype">
                                            <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>    
                            <div class="mb-3" id="field-assembly-father-quantity">
                                <label for="assembly_father_quantity" class="form-label">Quantidade (Assembly 1) *</label>
                                <input type="number" class="form-control" name="assembly_father_quantity" value="1" required min="1">
                            </div>                      

                            <div class="mb-3" id="field-assembly-child">
                                <label for="assembly_child_id" class="form-label">Assembly 2 *</label>
                                <select class="form-select" name="assembly_child_id">
                                    <option value="">Selecionar assembly...</option>
                                    <?php foreach ($assemblies as $assembly): ?>
                                        <option value="<?= $assembly['Assembly_ID'] ?>">
                                            <?= htmlspecialchars($assembly['Prototype_Name']) ?> v<?= $assembly['Prototype_Version'] ?>
                                            - <?= $assembly['Assembly_Designation'] ? htmlspecialchars($assembly['Assembly_Designation']) : 'Nível raiz' ?>
                                        </option> 
                                    <?php endforeach; ?>
                                    <?php foreach ($prototypes as $prototype): ?>
                                        <option value="<?= $prototype['Prototype_ID'] ?> prototype">
                                            <?= htmlspecialchars($prototype['Name']) ?> v<?= $prototype['Version'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3" id="field-assembly-child-quantity">
                                <label for="assembly_child_quantity" class="form-label">Quantidade (Assembly 2) *</label>
                                <input type="number" class="form-control" name="assembly_child_quantity" value="1" required min="1">
                            </div>
                
                            <!-- FIM DOS NOVOS CAMPOS PARA ASSEMBLY -->

                            <div class="mb-3" id="field-notes">
                                <label for="notes" class="form-label">Notas</label>
                                <textarea class="form-control" name="notes" rows="2"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Adicionar à Assembly
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
    $filteredAssemblies = array_filter($assemblies, function($asm) {
        return $asm['Prototype_ID'] == $_GET['prototype_id'];
    });
    // Constrói a árvore utilizando a relação dual
    $assemblyTree = getAssemblyTreeDual($filteredAssemblies);
    echo '<div id="assembly-tree">';
    echo renderAssemblyTree($assemblyTree);
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
                                        <th>Designação</th>
                                        <th>Protótipo</th>
                                        <th>Componente 1</th>
                                        <th>Qtd (Componente 1)</th>
                                        <th>Componente 2</th>
                                        <th>Qtd (Componente 2)</th>
                                        <th>Assembly 1</th>
                                        <th>Qtd (Assembly 1)</th>
                                        <th>Assembly 2</th>
                                        <th>Qtd (Assembly 2)</th>
                                        <th>Preço</th>
                                        <th>Nível de Assembly</th>
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
                                                <?= $assembly['Component_Father_Designation'] ? htmlspecialchars($assembly['Component_Father_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Component_Father_Quantity'] ?></span>
                                            <td>
                                                <?= !empty($assembly['Component_Child_Designation']) ? htmlspecialchars($assembly['Component_Child_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Component_Child_Quantity'] ?></span>
                                            </td>
                                    
                                            <td>
                                                <?= $assembly['Assembly_Father_Designation'] ? htmlspecialchars($assembly['Assembly_Father_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Assembly_Father_Quantity'] ?></span>
                                            <td>
                                                <?= $assembly['Assembly_Child_Designation'] ? htmlspecialchars($assembly['Assembly_Child_Designation']) : '-' ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $assembly['Assembly_Child_Quantity'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= $assembly['Price'] ? number_format($assembly['Price'], 2) . '€' : '-' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= $assembly['Assembly_Level'] ?>
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
                                           SUM(a.Component_Father_Quantity + a.Component_Child_Quantity) as Total_Quantity,
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
</div>

<!-- Link to assemblyLoader.js -->

<script>
    const components = <?= json_encode($components) ?>;
    const prototypes = <?= json_encode($prototypes) ?>;
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
