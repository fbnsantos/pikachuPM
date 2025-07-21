<?php
// Função para renderizar a árvore de assemblies

function getMaxDepth($assemblies) {
    if (empty($assemblies)) {
        return 0;
    }
    
    $maxDepth = 0;
    foreach ($assemblies as $assembly) {
        if (!empty($assembly['children'])) {
            $childDepth = getMaxDepth($assembly['children']);
            $maxDepth = max($maxDepth, $childDepth + 1);
        }
    }
    return $maxDepth;
}

function renderAssemblyTree($assemblies, $currentDepth = null, $maxDepth = null) {
    if (empty($assemblies)) {
        return;
    }
    
    // Calculate max depth on first call
    if ($maxDepth === null) {
        $maxDepth = getMaxDepth($assemblies);
        $currentDepth = 0; // Start from 0 for top-level assemblies
    }
    
    // First, collect all items in the correct order (children first)
    $orderedItems = [];
    collectItemsInOrder($assemblies, $currentDepth, $maxDepth, $orderedItems);
    
    // Then render them with proper indentation
    foreach ($orderedItems as $item) {
        // If it's a leaf node (no children), it should have 0 indentation
        // If it's a parent node, its indentation is based on depth from leaf nodes
        if (!$item['hasChildren']) {
            $level = 0; // Leaf nodes have no indentation
        } else {
            // Parent nodes: calculate how many levels deep they are from their deepest child
            $level = $maxDepth - $item['depth'];
        }
        
        echo '<div style="padding-left: ' . ($level * 20) . 'px;">';
        
        if ($level > 0) {
            echo '<span style="color: #6c757d;">└─ </span>';
        }
        
        echo '<strong style="color: #007bff;">' . htmlspecialchars($item['assembly']['Assembly_Designation']) . '</strong>';
        echo '</div>';
    }
}

function collectItemsInOrder($assemblies, $currentDepth, $maxDepth, &$orderedItems) {
    foreach ($assemblies as $assembly) {
        // First collect children (leaf nodes first)
        if (!empty($assembly['children'])) {
            collectItemsInOrder($assembly['children'], $currentDepth + 1, $maxDepth, $orderedItems);
        }
        
        // Then add current assembly
        $orderedItems[] = [
            'assembly' => $assembly,
            'depth' => $currentDepth,
            'hasChildren' => !empty($assembly['children'])
        ];
    }
    foreach ($orderedItems as $item) {
        echo $item['assembly']['Assembly_Designation'] . "\n";
        echo "Depth: " . $item['depth'] . "\n";
        echo "Has Children: " . ($item['hasChildren'] ? 'Yes' : 'No') . "\n";
    }
}


function calculateAssemblyLevel($pdo, $assemblyId) {
    // Obter o nível da montagem pai
    $stmt = $pdo->prepare("
        SELECT Assembly_Level 
        FROM T_Assembly 
        WHERE Assembly_ID = (SELECT Assembly_Father_ID FROM T_Assembly WHERE Assembly_ID = ?)
    ");
    $stmt->execute([$assemblyId]);
    $fatherLevel = $stmt->fetchColumn();

    // Obter o nível da montagem filho
    $stmt = $pdo->prepare("
        SELECT Assembly_Level 
        FROM T_Assembly 
        WHERE Assembly_ID = (SELECT Assembly_Child_ID FROM T_Assembly WHERE Assembly_ID = ?)
    ");
    $stmt->execute([$assemblyId]);
    $childLevel = $stmt->fetchColumn();

    // Calcular o nível atual
    $fatherLevel = $fatherLevel !== false ? $fatherLevel : 0;
    $childLevel = $childLevel !== false ? $childLevel : 0;

    return max($fatherLevel, $childLevel) + 1;
}

?>