<?php
/**
 * API para obter todos os dados de uma task
 * Retorna: task, checklist e ficheiros
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$task_id = (int)($_GET['id'] ?? 0);

if ($task_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

// Obter task
$stmt = $pdo->prepare('SELECT * FROM todos WHERE id = ?');
$stmt->execute([$task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    echo json_encode(['success' => false, 'error' => 'Task não encontrada']);
    exit;
}

// Verificar permissão para EDIÇÃO (não para visualização)
// Regras:
// - Autores podem sempre editar
// - Responsáveis podem editar
// - Se não houver responsável, qualquer um pode editar
$is_author = ($task['autor'] == $_SESSION['user_id']);
$is_responsible = (!empty($task['responsavel']) && $task['responsavel'] == $_SESSION['user_id']);
$no_responsible = empty($task['responsavel']) || is_null($task['responsavel']);

// TODOS podem visualizar, mas nem todos podem editar
$can_edit = $is_author || $is_responsible || $no_responsible;

// Nota: Não bloqueamos a visualização, apenas retornamos se pode editar
// O frontend pode decidir se permite ou não a edição com base no flag can_edit

// Obter checklist
$stmt = $pdo->prepare('SELECT * FROM task_checklist WHERE todo_id = ? ORDER BY position');
$stmt->execute([$task_id]);
$checklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Converter para formato esperado pelo frontend
$checklist = array_map(function($item) {
    return [
        'text' => $item['item_text'],
        'checked' => (bool)$item['is_checked']
    ];
}, $checklist);

// Obter ficheiros
$stmt = $pdo->prepare('SELECT id as file_id, file_name, file_path, uploaded_at FROM task_files WHERE todo_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$task_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'task' => $task,
    'checklist' => $checklist,
    'files' => $files,
    'can_edit' => $can_edit
]);