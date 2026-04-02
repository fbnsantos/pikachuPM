<?php
/**
 * gantt_ajax.php - Endpoint AJAX para Gantt: sprints, entregáveis e notas
 */

session_start();

if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

include_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Garantir que a tabela de notas existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gantt_notes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        ref_type    ENUM('deliverable','sprint') NOT NULL,
        ref_id      INT NOT NULL,
        nota        TEXT NOT NULL,
        autor_id    INT DEFAULT NULL,
        autor_name  VARCHAR(100) DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ref (ref_type, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { /* já existe */ }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

header('Content-Type: application/json');

$action   = $_POST['action'] ?? '';
$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'Desconhecido';

try {
    switch ($action) {

        // ── Notas: listar ─────────────────────────────────────────────────
        case 'get_notes':
            $ref_type = $_POST['ref_type'] ?? '';
            $ref_id   = intval($_POST['ref_id'] ?? 0);
            if (!in_array($ref_type, ['deliverable','sprint']) || !$ref_id) {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']); exit;
            }
            $st = $pdo->prepare("SELECT * FROM gantt_notes WHERE ref_type=? AND ref_id=? ORDER BY created_at DESC");
            $st->execute([$ref_type, $ref_id]);
            $notes = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'notes' => $notes]);
            break;

        // ── Notas: guardar ───────────────────────────────────────────────
        case 'save_note':
            $ref_type = $_POST['ref_type'] ?? '';
            $ref_id   = intval($_POST['ref_id'] ?? 0);
            $nota     = trim($_POST['nota'] ?? '');
            if (!in_array($ref_type, ['deliverable','sprint']) || !$ref_id || $nota === '') {
                echo json_encode(['success' => false, 'message' => 'Nota vazia ou parâmetros inválidos']); exit;
            }
            $st = $pdo->prepare("INSERT INTO gantt_notes (ref_type, ref_id, nota, autor_id, autor_name) VALUES (?,?,?,?,?)");
            $st->execute([$ref_type, $ref_id, $nota, $user_id, $username]);
            $new_id = $pdo->lastInsertId();
            $st2 = $pdo->prepare("SELECT * FROM gantt_notes WHERE id=?");
            $st2->execute([$new_id]);
            echo json_encode(['success' => true, 'note' => $st2->fetch(PDO::FETCH_ASSOC)]);
            break;

        // ── Notas: apagar ────────────────────────────────────────────────
        case 'delete_note':
            $note_id = intval($_POST['note_id'] ?? 0);
            if (!$note_id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); exit; }
            $st = $pdo->prepare("DELETE FROM gantt_notes WHERE id=?");
            $st->execute([$note_id]);
            echo json_encode(['success' => true]);
            break;

        // ── Notas: criar tarefa a partir de nota ─────────────────────────
        case 'create_task_from_note':
            $titulo       = trim($_POST['titulo'] ?? '');
            $descritivo   = trim($_POST['descritivo'] ?? '');
            $data_limite  = $_POST['data_limite'] ?? null;
            $responsavel  = intval($_POST['responsavel'] ?? 0) ?: null;
            $projeto_id   = intval($_POST['projeto_id'] ?? 0) ?: null;

            if (!$titulo) { echo json_encode(['success' => false, 'message' => 'Título obrigatório']); exit; }
            if (!tableExists($pdo, 'todos')) { echo json_encode(['success' => false, 'message' => "Tabela 'todos' não existe"]); exit; }

            $st = $pdo->prepare("INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, projeto_id, estado)
                                 VALUES (?, ?, ?, ?, ?, ?, 'aberta')");
            $st->execute([$titulo, $descritivo, $data_limite ?: null, $user_id, $responsavel, $projeto_id]);
            $task_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'task_id' => $task_id, 'message' => "Tarefa #{$task_id} criada!"]);
            break;

        // ── Sprints: actualizar datas ─────────────────────────────────────
        case 'update_sprint_dates':
            $sprint_id   = intval($_POST['sprint_id'] ?? 0);
            $data_inicio = $_POST['data_inicio'] ?? null;
            $data_fim    = $_POST['data_fim'] ?? null;
            if (!$sprint_id || !$data_inicio || !$data_fim) {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']); exit;
            }
            $start = DateTime::createFromFormat('Y-m-d', $data_inicio);
            $end   = DateTime::createFromFormat('Y-m-d', $data_fim);
            if (!$start || !$end || $start > $end) {
                echo json_encode(['success' => false, 'message' => 'Datas inválidas']); exit;
            }
            $st = $pdo->prepare("UPDATE sprints SET data_inicio=?, data_fim=?, updated_at=NOW() WHERE id=?");
            $st->execute([$data_inicio, $data_fim, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Datas atualizadas']);
            break;

        case 'update_sprint_data_fim':
            $sprint_id = intval($_POST['sprint_id'] ?? 0);
            $data_fim  = $_POST['data_fim'] ?? null;
            if (!$sprint_id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); exit; }
            $st = $pdo->prepare("UPDATE sprints SET data_fim=?, updated_at=NOW() WHERE id=?");
            $st->execute([$data_fim ?: null, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Data de fecho atualizada']);
            break;

        case 'update_sprint_estado':
            $sprint_id = intval($_POST['sprint_id'] ?? 0);
            $estado    = $_POST['estado'] ?? '';
            $allowed   = ['aberta', 'em execução', 'suspensa', 'concluída'];
            if (!$sprint_id || !in_array($estado, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']); exit;
            }
            $st = $pdo->prepare("UPDATE sprints SET estado=?, updated_at=NOW() WHERE id=?");
            $st->execute([$estado, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Estado atualizado']);
            break;

        // ── Entregáveis: actualizar ───────────────────────────────────────
        case 'update_deliverable_status':
            $deliverable_id = intval($_POST['deliverable_id'] ?? 0);
            $status         = $_POST['status'] ?? '';
            $allowed        = ['pending', 'in-progress', 'completed'];
            if (!$deliverable_id || !in_array($status, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']); exit;
            }
            $st = $pdo->prepare("UPDATE project_deliverables SET status=? WHERE id=?");
            $st->execute([$status, $deliverable_id]);
            echo json_encode(['success' => true, 'message' => 'Estado atualizado']);
            break;

        case 'update_deliverable_due_date':
            $deliverable_id = intval($_POST['deliverable_id'] ?? 0);
            $due_date       = $_POST['due_date'] ?? null;
            if (!$deliverable_id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); exit; }
            $st = $pdo->prepare("UPDATE project_deliverables SET due_date=? WHERE id=?");
            $st->execute([$due_date ?: null, $deliverable_id]);
            echo json_encode(['success' => true, 'message' => 'Data atualizada']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ação inválida: ' . htmlspecialchars($action)]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}

function tableExists($pdo, $table) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE '$table'")->rowCount(); }
    catch (Exception $e) { return false; }
}

exit;
