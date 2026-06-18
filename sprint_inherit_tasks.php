<?php
// sprint_inherit_tasks.php - AJAX: tasks disponíveis para herdar de outra sprint
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

include_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$sourceId = (int)($_GET['source_sprint_id'] ?? 0);
$targetId = (int)($_GET['target_sprint_id'] ?? 0);

if (!$sourceId || !$targetId) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.titulo, t.estado, t.data_limite,
               u.username as responsavel_nome
        FROM sprint_tasks st
        JOIN todos t ON st.todo_id = t.id
        LEFT JOIN user_tokens u ON t.responsavel = u.user_id
        WHERE st.sprint_id = ?
          AND t.estado != 'completada'
          AND t.id NOT IN (SELECT todo_id FROM sprint_tasks WHERE sprint_id = ?)
        ORDER BY t.titulo
    ");
    $stmt->execute([$sourceId, $targetId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tasks = [];
}

header('Content-Type: application/json');
echo json_encode($tasks);
