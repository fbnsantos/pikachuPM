<?php
// Função para renderizar a árvore de assemblies

function renderAssemblyTree($assemblies) {
    if (empty($assemblies)) {
        return;
    }

    echo '<ul>';
    foreach ($assemblies as $assembly) {
        echo '<li>';
        echo '<strong>' . htmlspecialchars($assembly['Assembly_Designation']) . '</strong>';
        echo ' (Nível: ' . $assembly['Assembly_Level'] . ')';
        if (!empty($assembly['children'])) {
            renderAssemblyTree($assembly['children']);
        }
        echo '</li>';
    }
    echo '</ul>';
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