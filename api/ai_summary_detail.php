<?php
// api/ai_summary_detail.php — Returns full text of a saved AI summary
// GET ?id=N
// Auth: Bearer token (admin or delegate)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
    http_response_code(401); echo json_encode(['error'=>'Bearer token required']); exit;
}
$token = trim($m[1]);

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>'DB error']); exit;
}

$stTok = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ? AND expires_at > NOW()");
$stTok->execute([$token]);
$tokenRow = $stTok->fetch(PDO::FETCH_ASSOC);
if (!$tokenRow) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }

$stAdm = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stAdm->execute([$tokenRow['user_id']]);
$isAdmin = (bool)$stAdm->fetch();
if (!$isAdmin) {
    $stDel = $pdo->prepare("SELECT id FROM ai_delegates WHERE user_id = ?");
    $stDel->execute([$tokenRow['user_id']]);
    if (!$stDel->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

$st = $pdo->prepare("SELECT * FROM ai_summaries WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

echo json_encode($row, JSON_UNESCAPED_UNICODE);
