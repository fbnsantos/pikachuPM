<?php
// skills_ajax.php — AJAX endpoint para Competências da Equipa
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

$cur_uid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id=?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

$act = $_POST['sk_action'] ?? $_GET['sk_action'] ?? '';

try {
    switch ($act) {

        case 'set_level':
            $target_uid = (int)($_POST['user_id'] ?? 0);
            $comp_id    = (int)($_POST['competency_id'] ?? 0);
            $level      = $_POST['level'] ?? '';

            if ($target_uid !== $cur_uid && !$is_admin) {
                echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit;
            }
            if (!$comp_id) { echo json_encode(['ok'=>false,'msg'=>'Competência inválida']); exit; }

            $allowed = ['L','C','S','A','I',''];
            if (!in_array($level, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Nível inválido']); exit; }

            if ($level === 'L') {
                $s = $pdo->prepare("SELECT user_id FROM skills_matrix WHERE competency_id=? AND level='L' AND user_id!=?");
                $s->execute([$comp_id, $target_uid]);
                if ($s->fetch()) {
                    echo json_encode(['ok'=>false,'msg'=>'Já existe um Líder (L) nesta competência. Só pode haver um por coluna.']); exit;
                }
            }

            if ($level === '') {
                $pdo->prepare("DELETE FROM skills_matrix WHERE user_id=? AND competency_id=?")
                    ->execute([$target_uid, $comp_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO skills_matrix (user_id, competency_id, level, updated_by)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by), updated_at=NOW()
                ")->execute([$target_uid, $comp_id, $level, $cur_uid]);
            }
            echo json_encode(['ok'=>true]);
            break;

        case 'set_role':
            $target_uid = (int)($_POST['user_id'] ?? 0);
            $role       = trim($_POST['team_role'] ?? '');

            if ($target_uid !== $cur_uid && !$is_admin) {
                echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit;
            }

            $pdo->prepare("
                INSERT INTO skills_profiles (user_id, team_role) VALUES (?,?)
                ON DUPLICATE KEY UPDATE team_role=VALUES(team_role), updated_at=NOW()
            ")->execute([$target_uid, $role]);
            echo json_encode(['ok'=>true]);
            break;

        case 'toggle_member':
            if (!$is_admin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
            $uid      = (int)($_POST['user_id'] ?? 0);
            $included = (int)($_POST['included'] ?? 1);
            if ($included) {
                $pdo->prepare("DELETE FROM skills_excluded WHERE user_id=?")->execute([$uid]);
            } else {
                $pdo->prepare("INSERT IGNORE INTO skills_excluded (user_id, excluded_by) VALUES (?,?)")
                    ->execute([$uid, $cur_uid]);
            }
            echo json_encode(['ok'=>true]);
            break;

        // API pública: devolve os níveis de um utilizador (para outros módulos)
        case 'get_user_skills':
            $uid = (int)($_GET['user_id'] ?? 0);
            $s = $pdo->prepare("
                SELECT c.category, c.name, m.level
                FROM skills_matrix m
                JOIN skills_competencies c ON c.id = m.competency_id
                WHERE m.user_id = ?
                ORDER BY c.sort_order
            ");
            $s->execute([$uid]);
            echo json_encode(['ok'=>true, 'skills'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // API pública: devolve quem tem determinada competência acima de certo nível
        case 'get_competency_team':
            $comp_name  = trim($_GET['competency'] ?? '');
            $min_levels = $_GET['min_level'] ?? 'S'; // L,C,S,A,I
            $order = ['L'=>1,'C'=>2,'S'=>3,'A'=>4,'I'=>5];
            $min_n = $order[$min_levels] ?? 3;
            $s = $pdo->prepare("
                SELECT u.user_id, u.username, COALESCE(p.team_role,'') as team_role, m.level
                FROM skills_matrix m
                JOIN skills_competencies c ON c.id = m.competency_id
                JOIN user_tokens u ON u.user_id = m.user_id
                LEFT JOIN skills_profiles p ON p.user_id = m.user_id
                WHERE c.name = ?
                ORDER BY FIELD(m.level,'L','C','S','A','I')
            ");
            $s->execute([$comp_name]);
            $all = $s->fetchAll(PDO::FETCH_ASSOC);
            $filtered = array_filter($all, fn($r) => ($order[$r['level']] ?? 99) <= $min_n);
            echo json_encode(['ok'=>true, 'members'=>array_values($filtered)]);
            break;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida: '.$act]);
    }
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
