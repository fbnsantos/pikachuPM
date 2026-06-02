<?php
// api/personal_notes.php — Notas pessoais privadas (por utilizador)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function json_error($code, $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function get_bearer_token() {
    $headers = getallheaders();
    if (isset($headers['Authorization']) &&
        preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) {
        return $m[1];
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];
$token  = get_bearer_token();
if (!$token) json_error(401, 'Token não fornecido');

include_once __DIR__ . '/../config.php';

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) throw new Exception($db->connect_error);
    $db->set_charset('utf8mb4');

    // Criar tabela na primeira utilização
    $db->query('CREATE TABLE IF NOT EXISTS personal_notes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        text        VARCHAR(500) NOT NULL,
        done        TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_updated (user_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Autenticar
    $stmt = $db->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) json_error(401, 'Token inválido');
    $user_id = (int)$user['user_id'];

} catch (Exception $e) {
    json_error(500, 'Erro de ligação: ' . $e->getMessage());
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($method) {

    // ── GET — listar notas do utilizador ──────────────────────────────────────
    case 'GET':
        $stmt = $db->prepare(
            'SELECT id, text, done, created_at, updated_at
             FROM personal_notes
             WHERE user_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($notes as &$n) {
            $n['id']   = (int)$n['id'];
            $n['done'] = (bool)$n['done'];
        }
        echo json_encode(['notes' => $notes]);
        break;

    // ── POST — criar nota ─────────────────────────────────────────────────────
    case 'POST':
        $text = trim($input['text'] ?? '');
        if ($text === '') json_error(400, 'Texto obrigatório');
        if (mb_strlen($text) > 500) json_error(400, 'Máximo 500 caracteres');

        $stmt = $db->prepare('INSERT INTO personal_notes (user_id, text) VALUES (?, ?)');
        $stmt->bind_param('is', $user_id, $text);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        echo json_encode(['id' => (int)$id, 'text' => $text, 'done' => false]);
        break;

    // ── PUT — actualizar nota (texto e/ou done) ───────────────────────────────
    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error(400, 'ID obrigatório');

        $sets   = [];
        $params = [];
        $types  = '';

        if (array_key_exists('text', $input)) {
            $text = trim($input['text']);
            if ($text === '') json_error(400, 'Texto não pode ser vazio');
            $sets[]   = 'text = ?';
            $params[] = $text;
            $types   .= 's';
        }
        if (array_key_exists('done', $input)) {
            $done     = $input['done'] ? 1 : 0;
            $sets[]   = 'done = ?';
            $params[] = $done;
            $types   .= 'i';
        }
        if (!$sets) json_error(400, 'Nada para actualizar');

        $params[] = $id;
        $params[] = $user_id;
        $types   .= 'ii';

        $stmt = $db->prepare(
            'UPDATE personal_notes SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if (!$stmt->affected_rows) json_error(404, 'Nota não encontrada');
        $stmt->close();

        echo json_encode(['ok' => true]);
        break;

    // ── DELETE — apagar nota ──────────────────────────────────────────────────
    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_error(400, 'ID obrigatório');

        $stmt = $db->prepare('DELETE FROM personal_notes WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $user_id);
        $stmt->execute();
        if (!$stmt->affected_rows) json_error(404, 'Nota não encontrada');
        $stmt->close();

        echo json_encode(['ok' => true]);
        break;

    default:
        json_error(405, 'Método não suportado');
}
