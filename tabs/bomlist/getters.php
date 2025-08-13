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
 * seguindo as relações definidas em Assembly_Father_ID e Assembly_Child_ID.
 *
 * @param array $assemblies Array com todas as assemblies.
 * @param array $assembly Assembly atual.
 * @return array Lista de Assembly_IDs.
 */
function getAllSubAssemblyIDs(array $assemblies, array $assembly) {
    // Base: se não há relação nem para Father nem para Child, retorna só o próprio ID.
    if (empty($assembly['Assembly_Father_ID']) && empty($assembly['Assembly_Child_ID'])) {
        return [$assembly['Assembly_ID']];
    }
    
    // Inicia a lista com o ID da assembly atual
    $list = [$assembly['Assembly_ID']];
    
    // Se Assembly_Father_ID não está definido, pega a relação de Child
    if (empty($assembly['Assembly_Father_ID']) && !empty($assembly['Assembly_Child_ID'])) {
        $child = findAssemblyById($assemblies, $assembly['Assembly_Child_ID']);
        if ($child) {
            $list = array_merge($list, getAllSubAssemblyIDs($assemblies, $child));
        }
    }
    // Se Assembly_Child_ID não está definido, pega a relação de Father
    elseif (empty($assembly['Assembly_Child_ID']) && !empty($assembly['Assembly_Father_ID'])) {
        $father = findAssemblyById($assemblies, $assembly['Assembly_Father_ID']);
        if ($father) {
            $list = array_merge($list, getAllSubAssemblyIDs($assemblies, $father));
        }
    }
    // Se ambos estão definidos, faz para os dois
    elseif (!empty($assembly['Assembly_Father_ID']) && !empty($assembly['Assembly_Child_ID'])) {
        $father = findAssemblyById($assemblies, $assembly['Assembly_Father_ID']);
        $child = findAssemblyById($assemblies, $assembly['Assembly_Child_ID']);
        
        if ($father) {
            $list = array_merge($list, getAllSubAssemblyIDs($assemblies, $father));
        }
        if ($child) {
            $list = array_merge($list, getAllSubAssemblyIDs($assemblies, $child));
        }
    }

    return $list;
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
    return (float) ($assembly['Price'] ?? 0);
}

function getComponentPrice(array $component) {
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
 * Função auxiliar recursiva para construir a árvore de assemblies 
 *
 * @param array $assemblies Lista completa de assemblies.
 * @param array $parent Assembly pai.
 * @return array Assembly pai com os filhos preenchidos em 'children'.
 */
function buildAssemblyTreeDual(array $assemblies, array $parent): array {
    $children = [];

    // Se existir uma subassembly 1 (Assembly_Father_ID)
    if (!empty($parent['Assembly_Father_ID'])) {
        $child1 = findAssemblyById($assemblies, $parent['Assembly_Father_ID']);
        if ($child1) {
            $child1['depth'] = $parent['depth'] + 1;
            $child1 = buildAssemblyTreeDual($assemblies, $child1);
            $children[] = $child1;
        }
    }

    // Se existir uma subassembly 2 (Assembly_Child_ID)
    if (!empty($parent['Assembly_Child_ID'])) {
        $child2 = findAssemblyById($assemblies, $parent['Assembly_Child_ID']);
        if ($child2) {
            $child2['depth'] = $parent['depth'] + 1;
            $child2 = buildAssemblyTreeDual($assemblies, $child2);
            $children[] = $child2;
        }
    }

    $parent['children'] = $children;
    return $parent;
}

/**
 * Constrói a árvore completa de assemblies usando a relação dual: 
 * - Assembly_Father_ID representa a subassembly 1
 * - Assembly_Child_ID representa a subassembly 2
 *
 * Os registros que não aparecem em nenhum desses campos serão considerados nós raiz.
 *
 * @param array $assemblies Lista plana de assemblies.
 * @return array Árvore hierárquica de assemblies.
 */
function getAssemblyTreeDual(array $assemblies): array {
    // Primeiro, reúna todos os IDs que aparecem nos campos de subassemblies
    $childIDs = [];
    foreach ($assemblies as $asm) {
        if (!empty($asm['Assembly_Father_ID'])) {
            $childIDs[] = trim($asm['Assembly_Father_ID']);
        }
        if (!empty($asm['Assembly_Child_ID'])) {
            $childIDs[] = trim($asm['Assembly_Child_ID']);
        }
    }
    // Os nós raiz são os que não aparecem como subassembly em lado nenhum
    $tree = [];
    foreach ($assemblies as $asm) {
        if (!in_array($asm['Assembly_ID'], $childIDs)) {
            $asm['depth'] = 0;
            $tree[] = buildAssemblyTreeDual($assemblies, $asm);
        }
    }
    return $tree;
}
?>