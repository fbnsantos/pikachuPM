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
/*
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
}*/

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

/**
 * Gera uma referência para o componente com base na categoria.
 *
 * As categorias e respectivos prefixos:
 * - "Mecânica e Suportes Estruturais" => "MS"
 * - "Transmissão e movimento"      => "TM"
 * - "Sistema elétrico"             => "SE"
 * - "Eletrónica e controlo"        => "EC"
 *
 * A referência segue o formato: PREFIX-0000, onde o número é incremental.
 *
 * @param PDO $pdo Conexão com o banco.
 * @param string $category Categoria do componente.
 * @return string|null A referência gerada ou null se a categoria não for reconhecida.
 */
function generateComponentReference(PDO $pdo, string $category): ?string {
    $mapping = [
        "Mecânica e Suportes Estruturais" => "MS",
        "Transmissão e movimento" => "TM",
        "Sistema elétrico" => "SE",
        "Eletrónica e controlo" => "EC"
    ];
    
    if (!isset($mapping[$category])) {
        return null;
    }
    
    $prefix = $mapping[$category];
    
    // Buscar a referência máxima já gerada para este prefixo
    $stmt = $pdo->prepare("SELECT MAX(Reference) AS maxRef FROM T_Component WHERE Reference LIKE ?");
    $stmt->execute([$prefix . "-%"]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['maxRef']) {
        // Remove o prefixo e o hífen, e incrementa o valor numérico
        $num = (int)substr($row['maxRef'], strlen($prefix) + 1);
        $num++;
    } else {
        $num = 1;
    }

    // Formata sempre com 6 dígitos
    return sprintf("%s-%06d", $prefix, $num);
}

function generateAssemblyReference(PDO $pdo, string $category): ?string {
    $prefix = "AS"; 

    $stmt = $pdo->prepare("SELECT MAX(Assembly_Reference) AS maxRef FROM T_Assembly WHERE Assembly_Reference LIKE ?");
    $stmt->execute([$prefix . "-%"]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['maxRef']) {
        // Remove o prefixo e o hífen, e incrementa o valor numérico
        $num = (int)substr($row['maxRef'], strlen($prefix) + 1);
        $num++;
    } else {
        $num = 1;
    }

    // Formata sempre com 6 dígitos
    return sprintf("%s-%06d", $prefix, $num);
}

function addAssembly(PDO $pdo, array $data) {
    // Exemplo de inserção na tabela T_Assembly – ajuste os campos conforme sua estrutura atual
    $stmt = $pdo->prepare("
        INSERT INTO T_Assembly (Assembly_Designation, Prototype_ID, Assembly_Reference, Assembly_Quantity, Notes, Created_Date)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $result = $stmt->execute([
        $data['assembly_designation'],
        $data['prototype_id'],
        $data['assembly_ref'],
        $data['assembly_quantity'],
        $data['notes']
    ]);
    if ($result) {
        return $pdo->lastInsertId();
    } else {
        return false;
    }
}

 /**
 * Verifica se $searchId aparece em qualquer descendente de $startId.
 * Retorna true se encontrar (logo, inserir $parent→$child criaria ciclo).
 *
 * @param PDO $pdo
 * @param int $startId     Assembly onde começamos a descer (o "filho" proposto).
 * @param int $searchId    Assembly que não podemos encontrar abaixo (o "pai" proposto).
 * @return bool
 */
function checkInfRecursion(PDO $pdo, int $startId, int $searchId): bool {
    $stmt = $pdo->prepare("
        SELECT Child_Assembly_ID
        FROM T_Assembly_Assembly
        WHERE Parent_Assembly_ID = ?
    ");
    $stmt->execute([$startId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($children as $childId) {
        if ((int)$childId === $searchId) {
            return true;
        }
        // recursão: desce um nível
        if (checkInfRecursion($pdo, (int)$childId, $searchId)) {
            return true;
        }
    }
    return false;
}

 /**
 * Duplica recursivamente uma assembly (com suas relações) e retorna o novo Assembly_ID.
 *
 * @param PDO $pdo Conexão.
 * @param int $sourceAssemblyId ID da assembly original (fonte).
 * @param int $newPrototypeId Novo Prototype_ID que deve ser associado à cópia.
 * @return int Novo Assembly_ID duplicado.
 * @throws Exception Se não encontrar a assembly fonte.
 */
function duplicateAssemblyTree(PDO $pdo, int $sourceAssemblyId, int $newPrototypeId): int {
    // Buscar a assembly fonte
    $stmt = $pdo->prepare("SELECT * FROM T_Assembly WHERE Assembly_ID = ?");
    $stmt->execute([$sourceAssemblyId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        throw new Exception("Assembly não encontrada: $sourceAssemblyId");
    }
    
    // Gerar uma nova referência para a cópia (pode usar a função já existente)
    $newRef = generateAssemblyReference($pdo, $newPrototypeId, $source['Assembly_Designation']);
    $createdDate = date('Y-m-d H:i:s');
    
    // Inserir a nova assembly com os mesmos dados, mas com Prototype_ID novo
    $stmtInsert = $pdo->prepare("
        INSERT INTO T_Assembly (
            Prototype_ID, 
            Assembly_Designation, 
            Assembly_Reference, 
            Assembly_Level, 
            Price, 
            Notes, 
            Created_Date
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $newLevel = max(1, (int)$source['Assembly_Level'] + 1);

    $stmtInsert->execute([
        $newPrototypeId,
        $source['Assembly_Designation'],
        $newRef,
        $newLevel,
        $source['Price'],
        $source['Notes'],
        $createdDate
    ]);
    $newAssemblyId = (int)$pdo->lastInsertId();

    // Duplicar as relações com componentes (na tabela T_Assembly_Component)
    $stmtGetComps = $pdo->prepare("SELECT * FROM T_Assembly_Component WHERE Assembly_ID = ?");
    $stmtGetComps->execute([$sourceAssemblyId]);
    $components = $stmtGetComps->fetchAll(PDO::FETCH_ASSOC);
    $stmtInsertComp = $pdo->prepare("INSERT INTO T_Assembly_Component (Assembly_ID, Component_ID, Quantity) VALUES (?, ?, ?)");
    foreach ($components as $comp) {
        $stmtInsertComp->execute([$newAssemblyId, $comp['Component_ID'], $comp['Quantity']]);
    }

    // Duplicar as subassemblies (na tabela T_Assembly_Assembly)
    $stmtGetSub = $pdo->prepare("SELECT * FROM T_Assembly_Assembly WHERE Parent_Assembly_ID = ?");
    $stmtGetSub->execute([$sourceAssemblyId]);
    $childRelations = $stmtGetSub->fetchAll(PDO::FETCH_ASSOC);
    $stmtInsertSub = $pdo->prepare("INSERT INTO T_Assembly_Assembly (Parent_Assembly_ID, Child_Assembly_ID, Quantity) VALUES (?, ?, ?)");
    foreach ($childRelations as $rel) {
        // Duplica a assembly filha recursivamente
        $childNewId = duplicateAssemblyTree($pdo, (int)$rel['Child_Assembly_ID'], $newPrototypeId);
        // Cria a nova relação usando o novo ID da assembly duplicada
        $stmtInsertSub->execute([$newAssemblyId, $childNewId, $rel['Quantity']]);
    }
    
    return $newAssemblyId;
}

/**
 * Retorna o maior Assembly_Level de todos os descendentes de uma assembly.
 *
 * @param PDO $pdo
 * @param int $parentId
 * @return int
 */
function getMaxAssemblyLevelUnder(PDO $pdo, int $parentId): int {
    // busca filhos diretos
    $stmt = $pdo->prepare("
        SELECT Child_Assembly_ID
        FROM T_Assembly_Assembly
        WHERE Parent_Assembly_ID = ?
    ");
    $stmt->execute([$parentId]);
    $childIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $max = 0;
    foreach ($childIds as $childId) {
        // nível da própria child
        $stmt2 = $pdo->prepare("
            SELECT Assembly_Level
            FROM T_Assembly
            WHERE Assembly_ID = ?
        ");
        $stmt2->execute([(int)$childId]);
        $lvl = (int)$stmt2->fetchColumn();
        // recursivamente busca níveis abaixo
        $subMax = getMaxAssemblyLevelUnder($pdo, (int)$childId);
        $max = max($max, $lvl, $subMax);
    }
    return $max;
}