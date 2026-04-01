<?php
/**
 * gantt_ajax.php - Endpoint AJAX para atualização de sprints e entregáveis no Gantt
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'update_sprint_dates':
            $sprint_id = intval($_POST['sprint_id'] ?? 0);
            $data_inicio = $_POST['data_inicio'] ?? null;
            $data_fim    = $_POST['data_fim'] ?? null;

            if (!$sprint_id) {
                echo json_encode(['success' => false, 'message' => 'ID da sprint inválido']); exit;
            }
            if (!$data_inicio || !$data_fim) {
                echo json_encode(['success' => false, 'message' => 'Datas não fornecidas']); exit;
            }

            $start = DateTime::createFromFormat('Y-m-d', $data_inicio);
            $end   = DateTime::createFromFormat('Y-m-d', $data_fim);
            if (!$start || !$end) {
                echo json_encode(['success' => false, 'message' => 'Formato de data inválido']); exit;
            }
            if ($start > $end) {
                echo json_encode(['success' => false, 'message' => 'Data de início não pode ser posterior à data de fim']); exit;
            }

            $stmt = $pdo->prepare("UPDATE sprints SET data_inicio=?, data_fim=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$data_inicio, $data_fim, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Datas atualizadas']);
            break;

        case 'update_sprint_data_fim':
            $sprint_id = intval($_POST['sprint_id'] ?? 0);
            $data_fim  = $_POST['data_fim'] ?? null;

            if (!$sprint_id) {
                echo json_encode(['success' => false, 'message' => 'ID da sprint inválido']); exit;
            }
            $end = $data_fim ? DateTime::createFromFormat('Y-m-d', $data_fim) : null;
            if ($data_fim && !$end) {
                echo json_encode(['success' => false, 'message' => 'Formato de data inválido']); exit;
            }

            $stmt = $pdo->prepare("UPDATE sprints SET data_fim=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$data_fim ?: null, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Data de fecho atualizada']);
            break;

        case 'update_sprint_estado':
            $sprint_id = intval($_POST['sprint_id'] ?? 0);
            $estado    = $_POST['estado'] ?? '';
            $allowed   = ['aberta', 'em execução', 'suspensa', 'concluída'];

            if (!$sprint_id) {
                echo json_encode(['success' => false, 'message' => 'ID da sprint inválido']); exit;
            }
            if (!in_array($estado, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Estado inválido']); exit;
            }

            $stmt = $pdo->prepare("UPDATE sprints SET estado=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$estado, $sprint_id]);
            echo json_encode(['success' => true, 'message' => 'Estado atualizado']);
            break;

        case 'update_deliverable_status':
            $deliverable_id = intval($_POST['deliverable_id'] ?? 0);
            $status         = $_POST['status'] ?? '';
            $allowed        = ['pending', 'in-progress', 'completed'];

            if (!$deliverable_id) {
                echo json_encode(['success' => false, 'message' => 'ID do entregável inválido']); exit;
            }
            if (!in_array($status, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Estado inválido']); exit;
            }

            $stmt = $pdo->prepare("UPDATE project_deliverables SET status=? WHERE id=?");
            $stmt->execute([$status, $deliverable_id]);
            echo json_encode(['success' => true, 'message' => 'Estado atualizado']);
            break;

        case 'update_deliverable_due_date':
            $deliverable_id = intval($_POST['deliverable_id'] ?? 0);
            $due_date       = $_POST['due_date'] ?? null;

            if (!$deliverable_id) {
                echo json_encode(['success' => false, 'message' => 'ID do entregável inválido']); exit;
            }
            $d = $due_date ? DateTime::createFromFormat('Y-m-d', $due_date) : null;
            if ($due_date && !$d) {
                echo json_encode(['success' => false, 'message' => 'Formato de data inválido']); exit;
            }

            $stmt = $pdo->prepare("UPDATE project_deliverables SET due_date=? WHERE id=?");
            $stmt->execute([$due_date ?: null, $deliverable_id]);
            echo json_encode(['success' => true, 'message' => 'Data atualizada']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ação inválida: ' . htmlspecialchars($action)]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
exit;
