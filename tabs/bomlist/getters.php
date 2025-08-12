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
 * Função auxiliar para encontrar uma assembly pelo seu ID dentro do array de assemblies.
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
 * Função que retorna o valor total (soma) do preço da assembly e de todas as suas subassemblies.
 *
 * @param array $assemblies Array com todas as assemblies.
 * @param array $assembly Assembly atual.
 * @return float Valor total somado dos preços.
 */
function getTotalSubAssembliesPrice(array $assemblies, array $assembly) {
    // Se a assembly não tiver definida uma propriedade 'Price', assume 0.
    $total = isset($assembly['Price']) ? $assembly['Price'] : 0;

    // Obter as subassemblies diretamente relacionadas à assembly atual.
    $subassemblies = getSubassemblies($assemblies, $assembly['Assembly_ID']);
    foreach ($subassemblies as $sub) {
        // Soma o total da subassembly, recursivamente.
        $total += getTotalSubAssembliesPrice($assemblies, $sub);
    }
    
    return $total;
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

