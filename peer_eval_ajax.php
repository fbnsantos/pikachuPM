<?php
// peer_eval_ajax.php — AJAX endpoint para Avaliação entre Pares
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['ok'=>false,'msg'=>'Não autenticado']); exit;
}

include_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>'Erro BD']); exit;
}

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);
$cur_user = $_SESSION['username'] ?? '';

$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

$act = $_POST['pe_action'] ?? $_GET['pe_action'] ?? '';

$admin_only = ['create_campaign','add_participant','remove_participant','toggle_public'];
if (in_array($act, $admin_only) && !$is_admin) {
    echo json_encode(['ok'=>false,'msg'=>'Acesso restrito a administradores']); exit;
}

try {
    switch ($act) {

        case 'create_campaign':
            $title = trim($_POST['title'] ?? 'Avaliação entre Pares');
            $year  = trim($_POST['year']  ?? date('Y'));
            $pdo->exec("UPDATE peer_eval_campaigns SET is_active=0");
            $s = $pdo->prepare("INSERT INTO peer_eval_campaigns (title,year_label,created_by) VALUES (?,?,?)");
            $s->execute([$title,$year,$cur_uid]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
            break;

        case 'add_participant':
            $cid    = (int)$_POST['campaign_id'];
            $uid    = (int)$_POST['user_id'];
            $uname  = trim($_POST['username']);
            $can_ev = isset($_POST['can_evaluate'])    ? 1 : 0;
            $can_be = isset($_POST['can_be_evaluated']) ? 1 : 0;
            $s = $pdo->prepare("
                INSERT INTO peer_eval_participants
                    (campaign_id,user_id,username,can_evaluate,can_be_evaluated,added_by)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    can_evaluate=VALUES(can_evaluate),
                    can_be_evaluated=VALUES(can_be_evaluated)
            ");
            $s->execute([$cid,$uid,$uname,$can_ev,$can_be,$cur_uid]);
            echo json_encode(['ok'=>true]);
            break;

        case 'remove_participant':
            $cid = (int)$_POST['campaign_id'];
            $pid = (int)$_POST['participant_id'];
            $s = $pdo->prepare("DELETE FROM peer_eval_participants WHERE id=? AND campaign_id=?");
            $s->execute([$pid,$cid]);
            echo json_encode(['ok'=>true]);
            break;

        case 'toggle_public':
            $cid    = (int)$_POST['campaign_id'];
            $is_pub = (int)$_POST['is_public'];
            $s = $pdo->prepare("UPDATE peer_eval_campaigns SET is_public=? WHERE id=?");
            $s->execute([$is_pub,$cid]);
            echo json_encode(['ok'=>true]);
            break;

        case 'save_response':
            $cid     = (int)$_POST['campaign_id'];
            $eval_id = (int)$_POST['evaluatee_id'];
            $fkey    = preg_replace('/[^a-z0-9_]/','', $_POST['field_key'] ?? '');
            $fval    = $_POST['field_value'] ?? '';
            $is_skip = (int)($_POST['is_skip'] ?? 0);

            $s = $pdo->prepare("SELECT id FROM peer_eval_participants WHERE campaign_id=? AND user_id=? AND can_evaluate=1");
            $s->execute([$cid,$cur_uid]);
            if (!$s->fetch()) {
                echo json_encode(['ok'=>false,'msg'=>'Não é avaliador desta campanha']); exit;
            }
            $s = $pdo->prepare("
                INSERT INTO peer_eval_responses
                    (campaign_id,evaluator_id,evaluatee_id,field_key,field_value,is_skip)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE field_value=VALUES(field_value), is_skip=VALUES(is_skip)
            ");
            $s->execute([$cid,$cur_uid,$eval_id,$fkey,$fval,$is_skip]);
            echo json_encode(['ok'=>true]);
            break;

        case 'get_users':
            $s = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username");
            echo json_encode(['ok'=>true,'users'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida: '.$act]);
    }
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
