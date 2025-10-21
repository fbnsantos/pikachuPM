<?php
/**
 * gantt_ajax.php - Endpoint AJAX para atualização de datas das sprints
 * 
 * Este arquivo processa APENAS requisições AJAX, sem renderizar HTML
 */

session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

// Incluir configuração
include_once __DIR__ . '/config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Processar apenas requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Verificar ação
$action = $_POST['action'] ?? '';

if ($action !== 'update_sprint_dates') {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ação inválida']);
    exit;
}

// Processar atualização de datas
header('Content-Type: application/json');

try {
    $sprint_id = intval($_POST['sprint_id'] ?? 0);
    $data_inicio = $_POST['data_inicio'] ?? null;
    $data_fim = $_POST['data_fim'] ?? null;
    
    if (!$sprint_id) {
        echo json_encode(['success' => false, 'message' => 'ID da sprint inválido']);
        exit;
    }
    
    if (!$data_inicio || !$data_fim) {
        echo json_encode(['success' => false, 'message' => 'Datas não fornecidas']);
        exit;
    }
    
    // Validar formato das datas
    $start = DateTime::createFromFormat('Y-m-d', $data_inicio);
    $end = DateTime::createFromFormat('Y-m-d', $data_fim);
    
    if (!$start || !$end) {
        echo json_encode(['success' => false, 'message' => 'Formato de data inválido']);
        exit;
    }
    
    if ($start > $end) {
        echo json_encode(['success' => false, 'message' => 'Data de início não pode ser posterior à data de fim']);
        exit;
    }
    
    // Atualizar no banco de dados
    $stmt = $pdo->prepare("UPDATE sprints SET data_inicio = ?, data_fim = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$data_inicio, $data_fim, $sprint_id]);
    
    if ($result) {
        echo json_encode([
            'success' => true, 
            'message' => 'Datas atualizadas com sucesso',
            'sprint_id' => $sprint_id,
            'data_inicio' => $data_inicio,
            'data_fim' => $data_fim
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao executar atualização no banco']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
exit;