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
        if (!empty($assembly['children'])) {
            renderAssemblyTree($assembly['children']);
        }
        echo '</li>';
    }
    echo '</ul>';
}
?>