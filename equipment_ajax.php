<?php
// equipment_ajax.php — AJAX endpoint para Gestor de Equipamentos
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
$act      = $_POST['eq_action'] ?? $_GET['eq_action'] ?? '';

$UPLOAD_DIR = __DIR__ . '/uploads/equipment/';
$UPLOAD_URL = 'uploads/equipment/';
if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);

try {
    switch ($act) {

        // ── Listar itens ──────────────────────────────────────────────────
        case 'list':
            $class  = $_GET['class']  ?? '';
            $status = $_GET['status'] ?? '';
            $where  = ['1=1'];
            $params = [];
            if ($class)  { $where[] = 'class=?';  $params[] = $class; }
            if ($status) { $where[] = 'status=?'; $params[] = $status; }
            $sql = "SELECT e.*,
                    (SELECT COUNT(*) FROM equipment_issues i WHERE i.equipment_id=e.id AND i.status!='resolvido') as open_issues,
                    (SELECT COUNT(*) FROM equipment_attachments a WHERE a.equipment_id=e.id) as attach_count
                    FROM equipment_items e WHERE ".implode(' AND ',$where)." ORDER BY e.class, e.name";
            $s = $pdo->prepare($sql);
            $s->execute($params);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['prototype_refs'] = $it['prototype_refs'] ? json_decode($it['prototype_refs'],true) : [];
            }
            echo json_encode(['ok'=>true,'items'=>$items]);
            break;

        // ── Detalhe de um item ────────────────────────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $s  = $pdo->prepare("SELECT * FROM equipment_items WHERE id=?");
            $s->execute([$id]);
            $item = $s->fetch(PDO::FETCH_ASSOC);
            if (!$item) { echo json_encode(['ok'=>false,'msg'=>'Não encontrado']); exit; }
            $item['prototype_refs'] = $item['prototype_refs'] ? json_decode($item['prototype_refs'],true) : [];

            $s = $pdo->prepare("SELECT * FROM equipment_issues WHERE equipment_id=? ORDER BY FIELD(priority,'critica','alta','media','baixa'), created_at DESC");
            $s->execute([$id]);
            $item['issues'] = $s->fetchAll(PDO::FETCH_ASSOC);

            $s = $pdo->prepare("SELECT * FROM equipment_attachments WHERE equipment_id=? ORDER BY uploaded_at DESC");
            $s->execute([$id]);
            $item['attachments'] = $s->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok'=>true,'item'=>$item]);
            break;

        // ── Criar/actualizar item ─────────────────────────────────────────
        case 'save':
            $id       = (int)($_POST['id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $class    = $_POST['class'] ?? 'equipamento';
            $desc     = trim($_POST['description'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $qty      = max(1,(int)($_POST['quantity'] ?? 1));
            $resp     = trim($_POST['responsible'] ?? '');
            $resp_uid = (int)($_POST['responsible_uid'] ?? 0) ?: null;
            $status   = $_POST['status'] ?? 'funcional';
            $refs_raw = $_POST['prototype_refs'] ?? '[]';
            $refs     = json_decode($refs_raw, true) ?: [];
            $refs_json = json_encode($refs);

            if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Nome obrigatório']); exit; }
            $allowed_class  = ['equipamento','demonstrador','infraestrutura'];
            $allowed_status = ['funcional','parcial','inativo','manutencao'];
            if (!in_array($class,$allowed_class))   $class  = 'equipamento';
            if (!in_array($status,$allowed_status)) $status = 'funcional';

            if ($id) {
                $s = $pdo->prepare("UPDATE equipment_items SET name=?,class=?,description=?,location=?,quantity=?,responsible=?,responsible_uid=?,status=?,prototype_refs=?,updated_at=NOW() WHERE id=?");
                $s->execute([$name,$class,$desc,$location,$qty,$resp,$resp_uid,$status,$refs_json,$id]);
            } else {
                $s = $pdo->prepare("INSERT INTO equipment_items (name,class,description,location,quantity,responsible,responsible_uid,status,prototype_refs,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $s->execute([$name,$class,$desc,$location,$qty,$resp,$resp_uid,$status,$refs_json,$cur_uid]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
            break;

        // ── Apagar item ───────────────────────────────────────────────────
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            // Apagar ficheiros
            $s = $pdo->prepare("SELECT filename FROM equipment_attachments WHERE equipment_id=?");
            $s->execute([$id]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fn) {
                $path = $UPLOAD_DIR.$fn;
                if (file_exists($path)) unlink($path);
            }
            $pdo->prepare("DELETE FROM equipment_attachments WHERE equipment_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM equipment_issues WHERE equipment_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM equipment_items WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
            break;

        // ── Issues ────────────────────────────────────────────────────────
        case 'add_issue':
            $eid  = (int)($_POST['equipment_id'] ?? 0);
            $desc = trim($_POST['description'] ?? '');
            $prio = $_POST['priority'] ?? 'media';
            if (!$eid || !$desc) { echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit; }
            $allowed_prio = ['baixa','media','alta','critica'];
            if (!in_array($prio,$allowed_prio)) $prio='media';
            $s = $pdo->prepare("INSERT INTO equipment_issues (equipment_id,description,priority,status,created_by) VALUES (?,?,?,'aberto',?)");
            $s->execute([$eid,$desc,$prio,$cur_uid]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
            break;

        case 'update_issue':
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? null;
            $desc   = isset($_POST['description']) && $_POST['description'] !== '_keep' ? trim($_POST['description']) : null;
            $prio   = isset($_POST['priority'])    && $_POST['priority']    !== '_keep' ? $_POST['priority']          : null;
            $allowed_stat = ['aberto','em_progresso','resolvido'];
            $allowed_prio = ['baixa','media','alta','critica'];
            $sets = []; $params = [];
            if ($desc !== null)                              { $sets[] = 'description=?'; $params[] = $desc; }
            if ($prio !== null && in_array($prio,$allowed_prio)) { $sets[] = 'priority=?';    $params[] = $prio; }
            if ($status && in_array($status,$allowed_stat)) { $sets[] = 'status=?';       $params[] = $status; }
            if ($sets) {
                $params[] = $id;
                $pdo->prepare("UPDATE equipment_issues SET ".implode(',',$sets)." WHERE id=?")->execute($params);
            }
            echo json_encode(['ok'=>true]);
            break;

        case 'delete_issue':
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM equipment_issues WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
            break;

        // ── Upload de ficheiro ────────────────────────────────────────────
        case 'upload':
            $eid  = (int)($_POST['equipment_id'] ?? 0);
            $type = $_POST['attach_type'] ?? 'document'; // photo | document
            if (!$eid || !isset($_FILES['file'])) { echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit; }
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'msg'=>'Erro no upload']); exit; }
            if ($file['size'] > 20 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'Ficheiro demasiado grande (max 20MB)']); exit; }

            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','csv'];
            if (!in_array($ext,$allowed_ext)) { echo json_encode(['ok'=>false,'msg'=>'Tipo de ficheiro não permitido']); exit; }

            $fname = $eid.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            if (!move_uploaded_file($file['tmp_name'], $UPLOAD_DIR.$fname)) {
                echo json_encode(['ok'=>false,'msg'=>'Falha ao guardar ficheiro']); exit;
            }

            $is_photo = in_array($ext,['jpg','jpeg','png','gif','webp']) ? 1 : 0;
            $s = $pdo->prepare("INSERT INTO equipment_attachments (equipment_id,filename,original_name,is_photo,uploaded_by) VALUES (?,?,?,?,?)");
            $s->execute([$eid,$fname,$file['name'],$is_photo,$cur_uid]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'filename'=>$fname,'original_name'=>$file['name'],'is_photo'=>$is_photo,'url'=>$UPLOAD_URL.$fname]);
            break;

        case 'delete_attach':
            $id = (int)($_POST['id'] ?? 0);
            $s  = $pdo->prepare("SELECT filename,equipment_id FROM equipment_attachments WHERE id=?");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $path = $UPLOAD_DIR.$row['filename'];
                if (file_exists($path)) unlink($path);
                $pdo->prepare("DELETE FROM equipment_attachments WHERE id=?")->execute([$id]);
            }
            echo json_encode(['ok'=>true]);
            break;

        // ── Utilizadores para dropdown ────────────────────────────────────
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
