<?php

require_once 'database/database.php';


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

/**
 * Busca todas as assemblies (montagens) com informações do protótipo
 */
function getAssemblies($pdo) {
    $stmt = $pdo->query("
        SELECT a.*, 
               p.Name AS Prototype_Name,
               p.Version AS Prototype_Version
        FROM T_Assembly a
        JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
        ORDER BY p.Name, p.Version
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca os componentes associados a uma assembly a partir da tabela de junção T_Assembly_Component
 */
function getAssemblyComponents($pdo, $assemblyID) {
    $stmt = $pdo->prepare("
        SELECT ac.Quantity, c.*,
               m.Denomination AS Manufacturer_Name,
               s.Denomination AS Supplier_Name
        FROM T_Assembly_Component ac
        JOIN T_Component c ON ac.Component_ID = c.Component_ID
        LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID
        LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID
        WHERE ac.Assembly_ID = ?
        ORDER BY c.Denomination
    ");
    $stmt->execute([$assemblyID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca as subassemblies (ou assemblies relacionadas) associadas a uma dada assembly
 * utilizando a tabela T_Assembly_Assembly.
 */
function getAssemblyAssemblies($pdo, $parentAssemblyID) {
    $stmt = $pdo->prepare("
        SELECT aa.Quantity, a.*,
               p.Name AS Prototype_Name,
               p.Version AS Prototype_Version
        FROM T_Assembly_Assembly aa
        JOIN T_Assembly a ON aa.Child_Assembly_ID = a.Assembly_ID
        JOIN T_Prototype p ON a.Prototype_ID = p.Prototype_ID
        WHERE aa.Parent_Assembly_ID = ?
        ORDER BY a.Assembly_Designation
    ");
    $stmt->execute([$parentAssemblyID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Função para buscar a árvore de montagens de um protótipo
// Tem de se alterar para ir buscar outras assemblies em vez de só componentes
/*function getAssemblyTree($pdo, $prototypeId, $assemblyId = null) {
    if ($assemblyId === null) {
        // Encontrar todas as montagens (não excluir nenhuma montagem)
        $stmt = $pdo->prepare("
            SELECT a.*
            FROM T_Assembly a
            WHERE a.Prototype_ID = ?
            ORDER BY a.Assembly_Level DESC, a.Assembly_Designation ASC
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
}*/

/**
 * Função auxiliar para construir a árvore recursivamente
 */
/*function buildAssemblyTree($assemblies, $parent) {
    $children = [];
    foreach ($assemblies as $assembly) {
        if ($assembly['Assembly_Father_ID'] === $parent['Assembly_ID']) {
            $children[] = buildAssemblyTree($assemblies, $assembly);
        }
    }
    $parent['children'] = $children;
    return $parent;
}*/

/**
 * Retorna recursivamente todas as subassemblies de uma assembly mãe
 * 
 * @param array $assemblies Array com todas as assemblies
 * @param int   $parentAssemblyId Assembly_ID da assembly mãe
 * @return array Lista das subassemblies (cada uma com seus filhos, se houver)
 */
function getSubassemblies(array $assemblies, $parentAssemblyId) {
    $children = [];
    foreach ($assemblies as $assembly) {
        if ($assembly['Assembly_Father_ID'] == $parentAssemblyId) {
            // Chamada recursiva para obter os filhos dessa assembly
            $assembly['children'] = getSubassemblies($assemblies, $assembly['Assembly_ID']);
            $children[] = $assembly;
        }
    }
    return $children;
}

/**
 * Retorna uma lista (planificada) dos IDs da assembly e todas as subassemblies,
 * seguindo as relações definidas na tabela T_Assembly_Assembly.
 *
 * @param array $assocAssems Lista de relações (cada item com Parent_Assembly_ID e Child_Assembly_ID).
 * @param int   $parentAssemblyId ID da assembly pai (ponto de partida).
 * @return array Lista de Assembly_IDs (incluindo o próprio $parentAssemblyId).
 */
function getAllSubAssemblyIDs(array $records, int $parentId): array {
    $ids = [];
    foreach ($records as $rec) {
        // só prossegue se existir Parent_Assembly_ID e for igual ao pai
        if (isset($rec['Parent_Assembly_ID']) 
            && (int)$rec['Parent_Assembly_ID'] === $parentId 
            && !empty($rec['Child_Assembly_ID'])
        ) {
            $child = (int)$rec['Child_Assembly_ID'];
            if (!in_array($child, $ids, true)) {
                $ids[] = $child;
                // recursão protegida
                $ids = array_merge($ids, getAllSubAssemblyIDs($records, $child));
            }
        }
    }
    return $ids;
}

/**
 * Função auxiliar para encontrar uma assembly/componente pelo seu ID dentro do array de assemblies/componentes.
 *
 * @param array $assemblies
 * @param int $id
 * @return array|null
 */
function findAssemblyById(array $assemblies, $id) {
    foreach ($assemblies as $asm) {
        if ($asm['Assembly_ID'] == $id) {
            return $asm;
        }
    }
    return null;
}

function findComponentById(array $components, $id) {
    foreach ($components as $component) {
        if ($component['Component_ID'] == $id) {
            return $component;
        }
    }
    return null;
}


/**
 * Retorna o preço acumulado da assembly / componente.
 *
 * @param array $assembly Assembly atual / Componente atual.
 * @return float Preço.
 */
function getAssemblyPrice(array $assembly) {
    if (empty($assembly) || !isset($assembly['Price'])) {
        return 0.0;
    }
    return (float) ($assembly['Price'] ?? 0);
}

function getComponentPrice(array $component = null): float {
    if (!$component) {
        return 0; 
    }
    return (float) ($component['Price'] ?? 0);
}

/**
 * Procura um componente pelo campo Reference.
 *
 * @param array $components Array com os componentes.
 * @param string $reference A referência.
 * @return array|null Retorna o componente ou null se não encontrado.
 */
function getComponentByReference(array $components, string $reference): ?array {
    foreach ($components as $component) {
        if (isset($component['Reference']) && $component['Reference'] === $reference) {
            return $component;
        }
    }
    return null;
}




/**
 * Renderiza a árvore de assemblies em HTML usando indentação baseada na propriedade 'depth'.
 *
 * @param array $assemblies Árvore hierárquica (com 'children' e 'depth').
 * @return string HTML gerado.
 */
function renderAssemblyTree(array $assemblies): string {
    $html = '<ul>';
    foreach ($assemblies as $assembly) {
        // Cria a indentação: você pode usar &nbsp; ou CSS para espaçamento
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $assembly['depth']);
        // Se não for raiz, adiciona o símbolo "└─" (ou similar)
        $branch = $assembly['depth'] > 0 ? '└─ ' : '';
        $html .= '<li>';
        $html .= $indent . $branch . '<strong>' . htmlspecialchars($assembly['Assembly_Designation']) . '</strong>';
        if (isset($assembly['Price'])) {
            $html .= ' - ' . number_format($assembly['Price'], 2) . '€';
        }
        if (!empty($assembly['children'])) {
            $html .= renderAssemblyTree($assembly['children']);
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Constrói recursivamente a árvore mista de uma assembly.
 * Verifica para cada lado: se existe um componente, usa-o; caso contrário, usa a assembly.
 *
 * @param array $assemblies Lista plana de assemblies.
 * @param array $components Lista de componentes.
 * @param array $parent Nó atual (assembly).
 * @return array Nó com filhos adicionados (key 'children')
 */
function buildAssemblyTreeMixed(array $assemblies, array $components, array $parent): array {
    if (!isset($parent['depth'])) {
        $parent['depth'] = 0;
    }
    $children = [];
    
    // Lado esquerdo: tenta primeiro pelo componente, se existir; senão assembly
    if (!empty($parent['Component_Father_ID'])) {
        $childComp = findComponentById($components, $parent['Component_Father_ID']);
        if ($childComp) {
            $childComp['depth'] = $parent['depth'] + 1;
            $childComp['node_type'] = 'component';
            $children[] = $childComp;
        }
    }
    if (!empty($parent['Assembly_Father_ID'])) {
        $childAsm = findAssemblyById($assemblies, $parent['Assembly_Father_ID']);
        if ($childAsm) {
            $childAsm['depth'] = $parent['depth'] + 1;
            $childAsm['node_type'] = 'assembly';
            // Recursão para construir filhos
            $childAsm = buildAssemblyTreeMixed($assemblies, $components, $childAsm);
            $children[] = $childAsm;
        }
    }
    
    // Lado direito: componente ou assembly
    if (!empty($parent['Component_Child_ID'])) {
        $childComp = findComponentById($components, $parent['Component_Child_ID']);
        if ($childComp) {
            $childComp['depth'] = $parent['depth'] + 1;
            $childComp['node_type'] = 'component';
            $children[] = $childComp;
        }
    } elseif (!empty($parent['Assembly_Child_ID'])) {
        $childAsm = findAssemblyById($assemblies, $parent['Assembly_Child_ID']);
        if ($childAsm) {
            $childAsm['depth'] = $parent['depth'] + 1;
            $childAsm['node_type'] = 'assembly';
            $childAsm = buildAssemblyTreeMixed($assemblies, $components, $childAsm);
            $children[] = $childAsm;
        }
    }
    
    $parent['children'] = $children;
    return $parent;
}

/**
 * Constrói a árvore mista completa a partir das assemblies filtradas.
 * Um nó raiz é aquele que não aparece em nenhum dos campos de filho (componente ou assembly).
 *
 * @param array $assemblies Lista de assemblies.
 * @param array $components Lista de componentes.
 * @return array Árvore mista de assemblies.
 */
function getAssemblyTreeMixed(array $assemblies, array $components): array {
    $childIDs = [];
    foreach ($assemblies as $asm) {
        if (!empty($asm['Assembly_Father_ID'])) {
            $childIDs[] = trim($asm['Assembly_Father_ID']);
        }
        if (!empty($asm['Assembly_Child_ID'])) {
            $childIDs[] = trim($asm['Assembly_Child_ID']);
        }
        if (!empty($asm['Component_Father_ID'])) {
            $childIDs[] = trim($asm['Component_Father_ID']);
        }
        if (!empty($asm['Component_Child_ID'])) {
            $childIDs[] = trim($asm['Component_Child_ID']);
        }
    }
    
    $tree = [];
    foreach ($assemblies as $asm) {
        if (!in_array($asm['Assembly_ID'], $childIDs)) {
            $asm['depth'] = 0;
            $asm['node_type'] = 'assembly';
            $tree[] = buildAssemblyTreeMixed($assemblies, $components, $asm);
        }
    }
    return $tree;
}

/**
 * Renderiza a árvore mista em HTML.
 * Se o nó for do tipo "component" será exibido com um estilo (ex: cor azul) e sem recursão.
 *
 * @param array $nodes Árvore mista.
 * @return string HTML gerado.
 */
function renderAssemblyTreeMixed(array $nodes): string {
    $html = '<ul>';
    foreach ($nodes as $node) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $node['depth']);
        $branch = $node['depth'] > 0 ? '└─ ' : '';
        $html .= '<li>';
        if (isset($node['node_type']) && $node['node_type'] === 'component') {
            // Exibe o componente e o seu preço
            $html .= $indent . $branch . '<span class="assembly-component">' . htmlspecialchars($node['Denomination']) . '</span>';
            if (isset($node['Price'])) {
                $html .= ' - <span class="assembly-price">' . number_format($node['Price'], 2) . '€</span>';
            }
        } else {
            // Nó assembly
            $html .= $indent . $branch . '<strong>' . htmlspecialchars($node['Assembly_Designation']) . '</strong>';
            if (isset($node['Price'])) {
                $html .= ' - <span class="assembly-price">' . number_format($node['Price'], 2) . '€</span>';
            }
        }
        if (!empty($node['children'])) {
            $html .= renderAssemblyTreeMixed($node['children']);
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}


/**
 * Busca todos os componentes associados a um fabricante.
 *
 * @param PDO $pdo
 * @param int $manufacturerId
 * @return array
 */
function getComponentsByManufacturer(PDO $pdo, int $manufacturerId): array {
    $stmt = $pdo->prepare("
        SELECT c.*,
               m.Denomination AS Manufacturer_Name,
               s.Denomination AS Supplier_Name
        FROM T_Component c
        LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID
        LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID
        WHERE c.Manufacturer_ID = ?
        ORDER BY c.Denomination
    ");
    $stmt->execute([$manufacturerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca todos os componentes associados a um fornecedor.
 *
 * @param PDO $pdo
 * @param int $supplierId
 * @return array
 */
function getComponentsBySupplier(PDO $pdo, int $supplierId): array {
    $stmt = $pdo->prepare("
        SELECT c.*,
               m.Denomination AS Manufacturer_Name,
               s.Denomination AS Supplier_Name
        FROM T_Component c
        LEFT JOIN T_Manufacturer m ON c.Manufacturer_ID = m.Manufacturer_ID
        LEFT JOIN T_Supplier s ON c.Supplier_ID = s.Supplier_ID
        WHERE c.Supplier_ID = ?
        ORDER BY c.Denomination
    ");
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAssemblyComponentsByIds(PDO $pdo, array $assemblyIds): array {
    if (empty($assemblyIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($assemblyIds), '?'));
    $sql = "
        SELECT ac.Assembly_ID,
               ac.Component_ID,
               ac.Quantity,
               c.Denomination,
               c.Price
        FROM T_Assembly_Component ac
        JOIN T_Component c ON ac.Component_ID = c.Component_ID
        WHERE ac.Assembly_ID IN ($placeholders)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($assemblyIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildTreeFromList(
    array $assemblies,
    array $assocComps,
    array $assocAssems,
    int $parentId,
    int $depth = 0,
    array $visited = []
): array {
    // Se já estivermos aqui, ciclo detectado — retorna sem filhos
    if (in_array($parentId, $visited, true)) {
        return [];
    }
    // marca este nó como visitado
    $visited[] = $parentId;
 
    // Busca o próprio nó
    $node = [];
    foreach ($assemblies as $asm) {
        if ((int)$asm['Assembly_ID'] === $parentId) {
            $node = $asm;
            break;
        }
    }
    if (empty($node)) {
        return [];
    }
    $node['depth']     = $depth;
    $node['node_type'] = 'assembly';
 
    // Monta filhos
    $children = [];
 
    // Componentes
    foreach ($assocComps as $ac) {
        if ((int)$ac['Assembly_ID'] === $parentId) {
            $children[] = [
                'depth'      => $depth + 1,
                'node_type'  => 'component',
                'Component_ID'  => $ac['Component_ID'],
                'Quantity'      => $ac['Quantity'],
                'Denomination'  => $ac['Denomination'],
                'Price'         => $ac['Price'],
            ];
        }
    }
 
    // Sub-assemblies
    foreach ($assocAssems as $aa) {
        if ((int)$aa['Parent_Assembly_ID'] === $parentId) {
            $subTree = buildTreeFromList(
                $assemblies,
                $assocComps,
                $assocAssems,
                (int)$aa['Child_Assembly_ID'],
                $depth + 1,
                $visited      // passa o histórico adiante
            );
            if (!empty($subTree)) {
                $subTree['association_quantity'] = $aa['Quantity'];
                $children[] = $subTree;
            }
        }
    }
 
    $node['children'] = $children;
    return $node;
}

/**
 * Identifica raízes e dispara a montagem da árvore.
 */
function getAssemblyTreeFromList(array $assemblies, array $assocComps, array $assocAssems): array {
    $childIds = array_map('intval', array_column($assocAssems, 'Child_Assembly_ID'));
    $tree = [];
    foreach ($assemblies as $asm) {
        $id = (int)$asm['Assembly_ID'];
        if (!in_array($id, $childIds, true)) {
            // passe vazio como visited na raiz
            $tree[] = buildTreeFromList($assemblies, $assocComps, $assocAssems, $id, 0, []);
        }
    }
    return $tree;
}


// functions and code to help with component and assembly removal

function getAssociatedComps($pdo , int $assemblyID): array{
    $stmt = $pdo->prepare("SELECT T_Component.Component_ID , Denomination , Reference FROM T_Assembly_Component JOIN T_Component ON T_Assembly_Component.Component_ID = T_Component.Component_ID WHERE Assembly_ID = ?");
    $stmt->execute([$assemblyID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAssociatedAssems($pdo , int $assemblyID): array{
    $stmt = $pdo->prepare("SELECT a.Assembly_ID , a.Assembly_Designation , a.Assembly_Reference FROM T_Assembly_Assembly JOIN T_Assembly a ON T_Assembly_Assembly.Child_Assembly_ID = a.Assembly_ID WHERE Parent_Assembly_ID = ?");
    $stmt->execute([$assemblyID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


$pdo = connectDB();

    $json_data = file_get_contents('php://input');
    $input = json_decode($json_data, true);
if ($input['action'] === 'getAssociatedComps' && isset($input['assemblyID'])) {
    $assemblyID = (int)$input['assemblyID'];
    $associatedComps = getAssociatedComps($pdo , $assemblyID);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($associatedComps);
    exit;
}
else if ($input['action'] === 'getAssociatedAssemblies' && isset($input['assemblyID'])) {
    $assemblyID = (int)$input['assemblyID'];
    $associatedAssems = getAssociatedAssems($pdo , $assemblyID);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($associatedAssems);
    exit;
}



?>