<?php
// tabs/phd_kanboard.php - Gestão de Tarefas do Doutoramento com Kanban Board

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// ID do projeto de doutoramento
define('PHD_PROJECT_ID', 9999);

// Mapeamento entre estados Todo e colunas Kanban
$estado_to_stage_map = [
    'aberta' => 'pensada',
    'em execução' => 'execucao',
    'suspensa' => 'espera',
    'concluída' => 'concluida'
];

$stage_to_estado_map = [
    'pensada' => 'aberta',
    'execucao' => 'em execução',
    'espera' => 'suspensa',
    'concluida' => 'concluída'
];

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Criar tabela para informações do doutoramento
    $db->query('CREATE TABLE IF NOT EXISTS phd_info (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        data_inicio DATE,
        titulo_doutoramento TEXT,
        orientador VARCHAR(255),
        coorientador VARCHAR(255),
        instituicao VARCHAR(255),
        departamento VARCHAR(255),
        link_tese TEXT,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id)
    )');
    
    // Criar tabela para artigos do doutoramento
    $db->query('CREATE TABLE IF NOT EXISTS phd_artigos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        titulo VARCHAR(500),
        autores TEXT,
        revista_conferencia VARCHAR(255),
        ano INT,
        link TEXT,
        status VARCHAR(50) DEFAULT "publicado",
        tipo VARCHAR(50) DEFAULT "artigo",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id)
    )');

    $db->query('CREATE TABLE IF NOT EXISTS phd_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phd_user_id INT NOT NULL,
        author_user_id INT NOT NULL,
        note_text TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phd_user (phd_user_id)
    )');

} catch (Exception $e) {
    die("Erro ao conectar à base de dados: " . $e->getMessage());
}

// ============================================
// PROCESSAR PEDIDOS AJAX PRIMEIRO (ANTES DE QUALQUER OUTPUT)
// ============================================

// Atualizar estágio da tarefa (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage' && isset($_POST['ajax'])) {
    
    // Limpar qualquer output buffer que possa existir
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    $task_id = intval($_POST['task_id']);
    $new_stage = $_POST['new_stage'];
    
    $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
    
    if (!in_array($new_stage, $valid_stages)) {
        echo json_encode(['success' => false, 'error' => 'Estágio inválido']);
        $db->close();
        exit;
    }
    
    $new_estado = $stage_to_estado_map[$new_stage];
    $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND projeto_id = ?');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('sii', $new_estado, $task_id, $projeto_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    
    $stmt->close();
    $db->close();
    exit; // IMPORTANTE: Parar execução aqui
}

// ============================================
// PROCESSAR AÇÕES NORMAIS (COM REDIRECT)
// ============================================

$success_message = '';
$error_message = '';
$user_id = $_SESSION['user_id'];

// Adicionar nova tarefa
// Associar task de sprint ao PhD kanban
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link_sprint_task') {
    $todo_id = (int)($_POST['todo_id'] ?? 0);
    $estagio = $_POST['estagio'] ?? 'pensada';
    $estado  = $stage_to_estado_map[$estagio] ?? 'aberta';
    if ($todo_id) {
        $phd_id = PHD_PROJECT_ID;
        $stmt = $db->prepare('UPDATE todos SET projeto_id = ?, estado = ? WHERE id = ?');
        $stmt->bind_param('isi', $phd_id, $estado, $todo_id);
        $stmt->execute();
        $stmt->close();
    }
    $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
    if (isset($_GET['user'])) $redirect_url .= '&user=' . intval($_GET['user']);
    $redirect_url .= '&success=task_added';
    header('Location: ' . $redirect_url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_task') {
    $titulo = trim($_POST['titulo']);
    $descritivo = trim($_POST['descritivo']);
    $data_limite = !empty($_POST['data_limite']) ? $_POST['data_limite'] : null;
    $responsavel = !empty($_POST['responsavel']) ? intval($_POST['responsavel']) : null;
    $estagio = isset($_POST['estagio']) ? $_POST['estagio'] : 'pensada';
    $estado = $stage_to_estado_map[$estagio];
    
    if (!empty($titulo)) {
        $stmt = $db->prepare('INSERT INTO todos (titulo, descritivo, data_limite, autor, responsavel, estado, projeto_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('sssiisi', $titulo, $descritivo, $data_limite, $user_id, $responsavel, $estado, $projeto_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            // Redirect para evitar resubmissão do formulário
            $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
            if (isset($_GET['user'])) {
                $redirect_url .= '&user=' . intval($_GET['user']);
            }
            $redirect_url .= '&success=task_added';
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $error_message = "Erro ao adicionar tarefa: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $error_message = "O título da tarefa é obrigatório.";
    }
}

// Verificar se há mensagem de sucesso no URL
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'task_added':
            $success_message = "Tarefa adicionada com sucesso!";
            break;
        case 'task_deleted':
            $success_message = "Tarefa eliminada com sucesso!";
            break;
        case 'phd_info_saved':
            $success_message = "Informações do doutoramento guardadas com sucesso!";
            break;
        case 'artigo_added':
            $success_message = "Artigo adicionado com sucesso!";
            break;
        case 'artigo_updated':
            $success_message = "Artigo atualizado com sucesso!";
            break;
        case 'artigo_deleted':
            $success_message = "Artigo eliminado com sucesso!";
            break;
    }
}

// Atualizar estágio da tarefa (via POST normal, não AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage' && !isset($_POST['ajax'])) {
    $task_id = intval($_POST['task_id']);
    $new_stage = $_POST['new_stage'];
    
    $valid_stages = ['pensada', 'execucao', 'espera', 'concluida'];
    if (in_array($new_stage, $valid_stages)) {
        $new_estado = $stage_to_estado_map[$new_stage];
        $stmt = $db->prepare('UPDATE todos SET estado = ? WHERE id = ? AND projeto_id = ?');
        $projeto_id = PHD_PROJECT_ID;
        $stmt->bind_param('sii', $new_estado, $task_id, $projeto_id);
        
        if ($stmt->execute()) {
            $success_message = "Estágio atualizado com sucesso!";
        } else {
            $error_message = "Erro ao atualizar estágio: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Estágio inválido";
    }
}

// Eliminar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    $task_id = intval($_POST['task_id']);
    
    $stmt = $db->prepare('DELETE FROM todos WHERE id = ? AND projeto_id = ?');
    $projeto_id = PHD_PROJECT_ID;
    $stmt->bind_param('ii', $task_id, $projeto_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect para evitar resubmissão
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=task_deleted';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao eliminar tarefa: " . $stmt->error;
        $stmt->close();
    }
}

// Guardar/Atualizar informações do doutoramento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_phd_info') {
    $data_inicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : null;
    $titulo = trim($_POST['titulo_doutoramento']);
    $orientador = trim($_POST['orientador']);
    $coorientador = trim($_POST['coorientador']);
    $instituicao = trim($_POST['instituicao']);
    $departamento = trim($_POST['departamento']);
    $link_tese = trim($_POST['link_tese']);
    $notas = trim($_POST['notas']);
    $selected_user = intval($_POST['selected_user']);
    
    $stmt = $db->prepare('SELECT id FROM phd_info WHERE user_id = ?');
    $stmt->bind_param('i', $selected_user);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $db->prepare('UPDATE phd_info SET data_inicio = ?, titulo_doutoramento = ?, orientador = ?, coorientador = ?, instituicao = ?, departamento = ?, link_tese = ?, notas = ? WHERE user_id = ?');
        $stmt->bind_param('ssssssssi', $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas, $selected_user);
    } else {
        $stmt = $db->prepare('INSERT INTO phd_info (user_id, data_inicio, titulo_doutoramento, orientador, coorientador, instituicao, departamento, link_tese, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssssss', $selected_user, $data_inicio, $titulo, $orientador, $coorientador, $instituicao, $departamento, $link_tese, $notas);
    }
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=phd_info_saved';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao guardar informações: " . $stmt->error;
        $stmt->close();
    }
}

// Adicionar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_artigo') {
    $titulo = trim($_POST['titulo_artigo']);
    $autores = trim($_POST['autores']);
    $revista = trim($_POST['revista_conferencia']);
    $ano = !empty($_POST['ano']) ? intval($_POST['ano']) : null;
    $link = trim($_POST['link_artigo']);
    $status = $_POST['status_artigo'];
    $tipo = $_POST['tipo_artigo'];
    $selected_user = intval($_POST['selected_user']);
    
    $stmt = $db->prepare('INSERT INTO phd_artigos (user_id, titulo, autores, revista_conferencia, ano, link, status, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssisss', $selected_user, $titulo, $autores, $revista, $ano, $link, $status, $tipo);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_added';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao adicionar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// Editar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_artigo') {
    $artigo_id = intval($_POST['artigo_id']);
    $titulo = trim($_POST['titulo_artigo_edit']);
    $autores = trim($_POST['autores_edit']);
    $revista = trim($_POST['revista_conferencia_edit']);
    $ano = !empty($_POST['ano_edit']) ? intval($_POST['ano_edit']) : null;
    $link = trim($_POST['link_artigo_edit']);
    $status = $_POST['status_artigo_edit'];
    $tipo = $_POST['tipo_artigo_edit'];
    
    $stmt = $db->prepare('UPDATE phd_artigos SET titulo = ?, autores = ?, revista_conferencia = ?, ano = ?, link = ?, status = ?, tipo = ? WHERE id = ?');
    $stmt->bind_param('sssssssi', $titulo, $autores, $revista, $ano, $link, $status, $tipo, $artigo_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_updated';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao atualizar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// Eliminar artigo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_artigo') {
    $artigo_id = intval($_POST['artigo_id']);
    
    $stmt = $db->prepare('DELETE FROM phd_artigos WHERE id = ?');
    $stmt->bind_param('i', $artigo_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Redirect
        $redirect_url = $_SERVER['PHP_SELF'] . '?tab=phd_kanban';
        if (isset($_GET['user'])) {
            $redirect_url .= '&user=' . intval($_GET['user']);
        }
        $redirect_url .= '&success=artigo_deleted';
        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error_message = "Erro ao eliminar artigo: " . $stmt->error;
        $stmt->close();
    }
}

// ── Notas do Doutoramento (PDO) ──────────────────────────────────────────────
function phnFileIconClass($ext) {
    $map = ['pdf'=>'bi-file-earmark-pdf text-danger','doc'=>'bi-file-earmark-word text-primary','docx'=>'bi-file-earmark-word text-primary','xls'=>'bi-file-earmark-excel text-success','xlsx'=>'bi-file-earmark-excel text-success','ppt'=>'bi-file-earmark-ppt text-warning','pptx'=>'bi-file-earmark-ppt text-warning','zip'=>'bi-file-earmark-zip text-secondary','rar'=>'bi-file-earmark-zip text-secondary','txt'=>'bi-file-earmark-text text-muted','csv'=>'bi-file-earmark-text text-muted'];
    return $map[$ext] ?? 'bi-file-earmark text-muted';
}
try {
    $pdo_phn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo_phn->exec("CREATE TABLE IF NOT EXISTS phd_note_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES phd_notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { $pdo_phn = null; }

$_phnAllowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','text/plain','text/csv','application/zip','application/x-zip-compressed'];

if ($pdo_phn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $phn_action = $_POST['action'] ?? '';
    $phn_uid = intval($_POST['phd_user_id'] ?? 0);
    $phn_redir = $_SERVER['PHP_SELF'].'?tab=phd_kanban'.(isset($_GET['user'])?'&user='.intval($_GET['user']):'').'#phd-notes';

    if ($phn_action === 'add_phd_note') {
        $txt = trim($_POST['note_text'] ?? '');
        if ($txt !== '' || !empty($_FILES['note_images']['name'][0])) {
            $s = $pdo_phn->prepare("INSERT INTO phd_notes (phd_user_id, author_user_id, note_text) VALUES (?,?,?)");
            $s->execute([$phn_uid, $user_id, $txt]);
            $nid = (int)$pdo_phn->lastInsertId();
            if (!empty($_FILES['note_images']['name'][0])) {
                $dir = __DIR__.'/../files/phd_notes/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                    if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $mime = mime_content_type($tmp);
                    if (!in_array($mime, $_phnAllowed)) continue;
                    $ext = pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION);
                    $fname = 'phn_'.$nid.'_'.$i.'_'.uniqid().'.'.$ext;
                    if (move_uploaded_file($tmp, $dir.$fname))
                        $pdo_phn->prepare("INSERT INTO phd_note_images (note_id, file_path, original_name) VALUES (?,?,?)")->execute([$nid, 'files/phd_notes/'.$fname, $_FILES['note_images']['name'][$i]]);
                }
            }
        }
        header('Location: '.$phn_redir); exit;
    }
    if ($phn_action === 'edit_phd_note') {
        $nid = (int)$_POST['note_id'];
        $pdo_phn->prepare("UPDATE phd_notes SET note_text=? WHERE id=? AND author_user_id=?")->execute([trim($_POST['note_text']??''), $nid, $user_id]);
        if (!empty($_FILES['note_images']['name'][0])) {
            $dir = __DIR__.'/../files/phd_notes/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            foreach ($_FILES['note_images']['tmp_name'] as $i => $tmp) {
                if ($_FILES['note_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $mime = mime_content_type($tmp);
                if (!in_array($mime, $_phnAllowed)) continue;
                $ext = pathinfo($_FILES['note_images']['name'][$i], PATHINFO_EXTENSION);
                $fname = 'phn_'.$nid.'_e'.$i.'_'.uniqid().'.'.$ext;
                if (move_uploaded_file($tmp, $dir.$fname))
                    $pdo_phn->prepare("INSERT INTO phd_note_images (note_id, file_path, original_name) VALUES (?,?,?)")->execute([$nid, 'files/phd_notes/'.$fname, $_FILES['note_images']['name'][$i]]);
            }
        }
        header('Location: '.$phn_redir); exit;
    }
    if ($phn_action === 'delete_phd_note') {
        $nid = (int)$_POST['note_id'];
        foreach ($pdo_phn->prepare("SELECT file_path FROM phd_note_images WHERE note_id=?")->execute([$nid]) ? $pdo_phn->query("SELECT file_path FROM phd_note_images WHERE note_id=$nid")->fetchAll(PDO::FETCH_COLUMN) : [] as $fp)
            @unlink(__DIR__.'/../'.$fp);
        $pdo_phn->prepare("DELETE FROM phd_notes WHERE id=? AND author_user_id=?")->execute([$nid, $user_id]);
        header('Location: '.$phn_redir); exit;
    }
    if ($phn_action === 'delete_phd_note_image') {
        $iid = (int)$_POST['image_id'];
        $r = $pdo_phn->prepare("SELECT pni.file_path, pn.author_user_id FROM phd_note_images pni JOIN phd_notes pn ON pni.note_id=pn.id WHERE pni.id=?");
        $r->execute([$iid]); $img = $r->fetch(PDO::FETCH_ASSOC);
        if ($img && $img['author_user_id'] == $user_id) {
            @unlink(__DIR__.'/../'.$img['file_path']);
            $pdo_phn->prepare("DELETE FROM phd_note_images WHERE id=?")->execute([$iid]);
        }
        header('Location: '.$phn_redir); exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Buscar todos os utilizadores com prioridade para quem tem info de doutoramento
$all_users = [];
$stmt = $db->query('
    SELECT ut.user_id, ut.username,
           CASE WHEN pi.id IS NOT NULL THEN 1 ELSE 0 END as has_phd_info
    FROM user_tokens ut 
    LEFT JOIN phd_info pi ON ut.user_id = pi.user_id
    ORDER BY has_phd_info DESC, ut.username ASC
');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $all_users[] = $row;
    }
}

// Determinar utilizador selecionado
$selected_user = $user_id;
if (isset($_GET['user']) && !empty($_GET['user'])) {
    $selected_user = intval($_GET['user']);
}

// Buscar informações do doutoramento
$phd_info = null;
$stmt = $db->prepare('SELECT * FROM phd_info WHERE user_id = ?');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $phd_info = $result->fetch_assoc();
}
$stmt->close();

// Buscar notas do doutoramento (com ficheiros)
$phd_notes_by_author = [];
if ($pdo_phn) {
    try {
        $nst = $pdo_phn->prepare("SELECT pn.*, ut.username as author_name FROM phd_notes pn LEFT JOIN user_tokens ut ON pn.author_user_id = ut.user_id WHERE pn.phd_user_id = ? ORDER BY pn.created_at DESC");
        $nst->execute([$selected_user]);
        foreach ($nst->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $ist = $pdo_phn->prepare("SELECT id, file_path, original_name FROM phd_note_images WHERE note_id=? ORDER BY created_at ASC");
            $ist->execute([$n['id']]);
            $n['images'] = $ist->fetchAll(PDO::FETCH_ASSOC);
            $aid = $n['author_user_id'];
            if (!isset($phd_notes_by_author[$aid])) $phd_notes_by_author[$aid] = ['username'=>$n['author_name'], 'notes'=>[]];
            $phd_notes_by_author[$aid]['notes'][] = $n;
        }
    } catch (Exception $e) {}
}

// Buscar artigos
$artigos = [];
$stmt = $db->prepare('SELECT * FROM phd_artigos WHERE user_id = ? ORDER BY ano DESC, titulo ASC');
$stmt->bind_param('i', $selected_user);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $artigos[] = $row;
}
$stmt->close();

// Buscar tarefas
$tasks_by_stage = [
    'pensada' => [],
    'execucao' => [],
    'espera' => [],
    'concluida' => []
];

$stmt = $db->prepare('
    SELECT t.*, u.username as responsavel_nome 
    FROM todos t 
    LEFT JOIN user_tokens u ON t.responsavel = u.user_id 
    WHERE t.projeto_id = ? AND (t.autor = ? OR t.responsavel = ?)
    ORDER BY t.data_limite ASC
');
$projeto_id = PHD_PROJECT_ID;
$stmt->bind_param('iii', $projeto_id, $selected_user, $selected_user);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Mapear estado para estágio do Kanban
    $estagio = $estado_to_stage_map[$row['estado']] ?? 'pensada';
    if (isset($tasks_by_stage[$estagio])) {
        $tasks_by_stage[$estagio][] = $row;
    }
}
$stmt->close();

// Carregar sprints com tasks disponíveis para associar ao PhD (agrupadas por protótipo)
$sprintTasksForModal = [];
try {
    $phd_id = PHD_PROJECT_ID;
    $res = $db->query("
        SELECT
            s.id as sprint_id, s.nome as sprint_nome, s.estado as sprint_estado,
            t.id as task_id, t.titulo as task_titulo, t.estado as task_estado,
            p.id as proto_id, p.short_name as proto_name, p.title as proto_title
        FROM sprint_tasks st
        JOIN todos t ON st.todo_id = t.id
        JOIN sprints s ON st.sprint_id = s.id
        LEFT JOIN story_tasks stk ON stk.todo_id = t.id
        LEFT JOIN user_stories us ON stk.story_id = us.id
        LEFT JOIN prototypes p ON us.prototype_id = p.id
        WHERE (t.projeto_id != $phd_id OR t.projeto_id IS NULL)
          AND t.estado != 'concluída'
        ORDER BY p.short_name, s.nome, t.titulo
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $protoKey = $row['proto_id'] ? $row['proto_id'] : 0;
            if (!isset($sprintTasksForModal[$protoKey])) {
                $sprintTasksForModal[$protoKey] = [
                    'name'    => $row['proto_name'] ?? '(sem protótipo)',
                    'title'   => $row['proto_title'] ?? '',
                    'sprints' => []
                ];
            }
            $sid = $row['sprint_id'];
            if (!isset($sprintTasksForModal[$protoKey]['sprints'][$sid])) {
                $sprintTasksForModal[$protoKey]['sprints'][$sid] = [
                    'nome'   => $row['sprint_nome'],
                    'estado' => $row['sprint_estado'],
                    'tasks'  => []
                ];
            }
            $sprintTasksForModal[$protoKey]['sprints'][$sid]['tasks'][] = [
                'id'     => $row['task_id'],
                'titulo' => $row['task_titulo'],
                'estado' => $row['task_estado'],
            ];
        }
    }
} catch (Exception $e) {}

// Estatísticas
$total_tasks = count($tasks_by_stage['pensada']) + count($tasks_by_stage['execucao']) + count($tasks_by_stage['espera']) + count($tasks_by_stage['concluida']);
$completed_tasks = count($tasks_by_stage['concluida']);
$progress_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
$total_artigos = count($artigos);

?>

<style>
.kanban-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.kanban-column {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    min-height: 400px;
}

.kanban-column-header {
    font-weight: bold;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.kanban-card {
    background: white;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
    cursor: move;
}

.kanban-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.stat-item {
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
}

.stat-number {
    font-size: 2em;
    font-weight: bold;
}

.artigo-item {
    border-left: 3px solid #667eea;
    padding: 15px;
    margin-bottom: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}

@media (max-width: 1200px) {
    .kanban-board {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .kanban-board {
        grid-template-columns: 1fr;
    }
}
.pjn-user-block { border:1px solid #e9ecef; border-radius:8px; overflow:hidden; }
.pjn-ub-header { display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; background:#f8f9fa; user-select:none; transition:background .15s; }
.pjn-ub-header:hover { background:#e9ecef; }
.pjn-ub-header.pjn-collapsed .pjn-chevron { transform:rotate(-90deg); }
.pjn-chevron { transition:transform .2s; font-size:13px; color:#6c757d; }
.pjn-user-notes { padding:6px 8px 8px; }
.pjn-note-item { background:#fff; border:1px solid #e9ecef; border-radius:6px; padding:8px 10px; font-size:.9rem; margin-top:6px; }
.pjn-note-meta { display:flex; align-items:center; margin-bottom:4px; }
.pjn-avatar { width:28px; height:28px; border-radius:50%; background:#6c757d; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.pjn-avatar-me { background:#0d6efd; }
/* ln-notes shared styles (notes section) */
.ln-user-block { border:1px solid #e9ecef; border-radius:8px; overflow:hidden; margin-bottom:6px; }
.ln-user-header { display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; background:#f8f9fa; user-select:none; transition:background .15s; }
.ln-user-header:hover { background:#e9ecef; }
.ln-user-header.ln-collapsed .ln-chevron { transform:rotate(-90deg); }
.ln-chevron { transition:transform .2s; font-size:13px; color:#6c757d; }
.ln-user-notes { padding:4px 8px 8px; }
.ln-note-item { background:#fff; border:1px solid #e9ecef; border-radius:6px; padding:8px 10px; font-size:.9rem; margin-top:6px; }
.ln-note-meta { display:flex; align-items:center; gap:4px; margin-bottom:6px; flex-wrap:wrap; }
.ln-avatar { width:28px; height:28px; border-radius:50%; background:#6c757d; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.ln-avatar-me { background:#0d6efd; }
/* Markdown editor */
.ln-editor-wrap { border:1px solid #dee2e6; border-radius:6px; overflow:hidden; margin-bottom:4px; }
.ln-editor-tabs { display:flex; gap:0; border-bottom:1px solid #dee2e6; background:#f8f9fa; }
.ln-tab { border:none; background:none; padding:4px 14px; font-size:13px; cursor:pointer; color:#6c757d; border-bottom:2px solid transparent; }
.ln-tab.active { color:#0d6efd; border-bottom-color:#0d6efd; font-weight:600; }
.ln-md-toolbar { display:flex; flex-wrap:wrap; gap:2px; padding:4px 6px; background:#f8f9fa; border-bottom:1px solid #dee2e6; }
.ln-md-toolbar button { border:1px solid #dee2e6; background:#fff; border-radius:3px; padding:1px 6px; font-size:12px; cursor:pointer; line-height:1.5; }
.ln-md-toolbar button:hover { background:#e9ecef; }
.ln-sep { width:1px; background:#dee2e6; margin:2px 2px; }
.ln-md-hint { font-size:11px; color:#adb5bd; margin-left:auto; align-self:center; }
.ln-md-textarea { border:0; border-radius:0; resize:vertical; }
.ln-md-live-preview { padding:8px 12px; min-height:80px; font-size:.9rem; }
/* Markdown rendered view */
.ln-note-md-view { font-size:.9rem; line-height:1.55; }
.ln-note-md-view h1,.ln-note-md-view h2,.ln-note-md-view h3 { font-size:1rem; font-weight:700; margin:.6em 0 .3em; }
.ln-note-md-view p { margin:0 0 .5em; }
.ln-note-md-view ul,.ln-note-md-view ol { padding-left:1.4em; margin:0 0 .5em; }
.ln-note-md-view code { background:#f0f0f0; padding:1px 4px; border-radius:3px; font-size:.85em; }
.ln-note-md-view pre { background:#f0f0f0; padding:8px; border-radius:4px; overflow-x:auto; font-size:.85em; }
.ln-note-md-view blockquote { border-left:3px solid #dee2e6; padding-left:10px; color:#6c757d; margin:0 0 .5em; }
.ln-note-md-view input[type=checkbox] { pointer-events:none; }
.ln-note-md-view table { border-collapse:collapse; width:100%; font-size:.85em; margin:.4em 0; }
.ln-note-md-view th,.ln-note-md-view td { border:1px solid #dee2e6; padding:3px 8px; }
.ln-note-md-view th { background:#f8f9fa; }
/* Images and file chips */
.ln-note-images { display:flex; flex-wrap:wrap; gap:6px; }
.ln-img-wrap { position:relative; display:inline-block; }
.ln-img-wrap img { max-width:90px; max-height:80px; border-radius:4px; cursor:pointer; border:1px solid #dee2e6; object-fit:cover; }
.ln-img-del { position:absolute; top:-4px; right:-4px; margin:0; padding:0; }
.ln-del-img { background:#dc3545; color:#fff; border:none; border-radius:50%; width:16px; height:16px; font-size:12px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.note-file-chip { display:inline-flex; align-items:center; gap:4px; background:#f0f4ff; border:1px solid #c7d2fe; border-radius:4px; padding:2px 8px; font-size:12px; }
.note-chip-del { background:none; border:none; color:#dc3545; font-size:14px; cursor:pointer; line-height:1; padding:0 0 0 4px; }
/* Edit gallery */
.ln-edit-img-ref { position:relative; width:60px; height:60px; border:1px solid #dee2e6; border-radius:4px; overflow:hidden; cursor:pointer; }
.ln-edit-img-ref img { width:100%; height:100%; object-fit:cover; }
.ln-edit-img-ref-overlay { position:absolute; inset:0; background:rgba(0,0,0,.4); display:none; align-items:center; justify-content:center; color:#fff; font-size:20px; }
.ln-edit-img-ref:hover .ln-edit-img-ref-overlay { display:flex; }
.ln-edit-file-ref { display:flex; flex-direction:column; align-items:center; gap:2px; padding:6px 8px; border:1px solid #dee2e6; border-radius:4px; cursor:pointer; font-size:11px; text-align:center; width:70px; overflow:hidden; }
.ln-edit-file-ref:hover { background:#f8f9fa; }
.ln-file-ref-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:64px; }
/* Lightbox */
#ln-lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:9999; align-items:center; justify-content:center; cursor:zoom-out; }
#ln-lightbox img { max-width:90vw; max-height:90vh; border-radius:4px; }
.btn-xs { font-size:.7rem; padding:.1rem .3rem; }
</style>

<div class="container-fluid mt-4">
    
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="bi bi-mortarboard"></i> Gestão do Doutoramento</h2>
        <div class="d-flex gap-2">
            <select class="form-select" id="userSelector" style="width: 250px;">
                <?php foreach ($all_users as $u): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $selected_user ? 'selected' : '' ?>>
                        <?= $u['has_phd_info'] ? '⭐ ' : '' ?><?= htmlspecialchars($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                <i class="bi bi-plus-circle"></i> Nova Tarefa
            </button>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#linkSprintTaskModal">
                <i class="bi bi-link-45deg"></i> Associar Task de Sprint
            </button>
        </div>
    </div>
    
    <div class="stats-card">
        <h4><i class="bi bi-graph-up"></i> Estatísticas</h4>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?= $total_tasks ?></div>
                <div class="stat-label">Tarefas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $completed_tasks ?></div>
                <div class="stat-label">Concluídas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $progress_percentage ?>%</div>
                <div class="stat-label">Progresso</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $total_artigos ?></div>
                <div class="stat-label">KPIs</div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="bi bi-info-circle"></i> Informações do Doutoramento</h4>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#phdInfoModal">
                <i class="bi bi-pencil"></i> Editar
            </button>
        </div>
        <div class="card-body">
            <?php if ($phd_info): ?>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Título:</strong> <?= htmlspecialchars($phd_info['titulo_doutoramento'] ?: 'N/A') ?></p>
                        <p><strong>Data de Início:</strong> <?= $phd_info['data_inicio'] ? date('d/m/Y', strtotime($phd_info['data_inicio'])) : 'N/A' ?></p>
                        <p><strong>Orientador:</strong> <?= htmlspecialchars($phd_info['orientador'] ?: 'N/A') ?></p>
                        <p><strong>Coorientador:</strong> <?= htmlspecialchars($phd_info['coorientador'] ?: 'N/A') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Instituição:</strong> <?= htmlspecialchars($phd_info['instituicao'] ?: 'N/A') ?></p>
                        <p><strong>Departamento:</strong> <?= htmlspecialchars($phd_info['departamento'] ?: 'N/A') ?></p>
                        <?php if ($phd_info['link_tese']): ?>
                            <p><strong>Link:</strong> <a href="<?= htmlspecialchars($phd_info['link_tese']) ?>" target="_blank">Ver</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted">Nenhuma informação registada.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <h4 class="mt-4 mb-3"><i class="bi bi-kanban"></i> Quadro Kanban</h4>
    <div class="kanban-board">
        <?php 
        $stage_info = [
            'pensada' => ['title' => 'Pensadas', 'icon' => 'lightbulb', 'color' => 'secondary'],
            'execucao' => ['title' => 'Em Execução', 'icon' => 'play-circle', 'color' => 'primary'],
            'espera' => ['title' => 'Em Espera', 'icon' => 'pause-circle', 'color' => 'warning'],
            'concluida' => ['title' => 'Concluídas', 'icon' => 'check-circle', 'color' => 'success']
        ];
        
        foreach ($stage_info as $stage => $info): 
            $tasks = $tasks_by_stage[$stage];
        ?>
        <div class="kanban-column" data-stage="<?= $stage ?>">
            <div class="kanban-column-header">
                <span><i class="bi bi-<?= $info['icon'] ?>"></i> <?= $info['title'] ?></span>
                <span class="badge bg-<?= $info['color'] ?>"><?= count($tasks) ?></span>
            </div>
            
            <div class="kanban-cards-container">
                <?php if (empty($tasks)): ?>
                    <p class="text-muted text-center">Nenhuma tarefa</p>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <div class="kanban-card" data-task-id="<?= $task['id'] ?>" draggable="true">
                            <div style="font-weight: 600; margin-bottom: 8px;">
                                <?= htmlspecialchars($task['titulo']) ?>
                            </div>
                            
                            <?php if ($task['descritivo']): ?>
                                <div class="text-muted" style="font-size: 0.85em; margin-bottom: 8px;">
                                    <?= htmlspecialchars(mb_substr($task['descritivo'], 0, 100)) ?>
                                    <?= mb_strlen($task['descritivo']) > 100 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small>
                                    <?php if ($task['data_limite']): ?>
                                        <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($task['data_limite'])) ?>
                                    <?php endif; ?>
                                </small>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary edit-task-btn" data-task-id="<?= $task['id'] ?>" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-task-btn" data-task-id="<?= $task['id'] ?>" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Notas do Doutoramento -->
    <div class="card mt-4" id="phd-notes">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="bi bi-chat-left-text"></i> Notas</h4>
            <button class="btn btn-sm btn-outline-primary" onclick="phnToggleAdd()" id="phn-add-btn">
                <i class="bi bi-plus"></i> Adicionar nota
            </button>
        </div>
        <div class="card-body p-2">
            <!-- Formulário de nova nota -->
            <div id="phn-add-form" style="display:none;" class="mb-3 p-3 bg-light rounded border">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_phd_note">
                    <input type="hidden" name="phd_user_id" value="<?= $selected_user ?>">
                    <div class="mb-2">
                        <div class="ln-editor-wrap">
                        <div class="ln-editor-tabs">
                            <button type="button" class="ln-tab active" onclick="lnEditorTab(this,'write')">Escrever</button>
                            <button type="button" class="ln-tab" onclick="lnEditorTab(this,'preview')">Pré-visualizar</button>
                        </div>
                        <div class="ln-md-toolbar">
                            <button type="button" onclick="lnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                            <button type="button" onclick="lnMdWrap(this,'*','*')" title="Itálico"><em>I</em></button>
                            <button type="button" onclick="lnMdWrap(this,'***','***')" title="Negrito+Itálico"><b><em>BI</em></b></button>
                            <button type="button" onclick="lnMdWrap(this,'~~','~~')" title="Riscado"><s>S</s></button>
                            <span class="ln-sep"></span>
                            <button type="button" onclick="lnMdWrap(this,'`','`')" title="Código inline"><code>c</code></button>
                            <button type="button" onclick="lnMdBlock(this,'```\n','\n```')" title="Bloco de código"><code>```</code></button>
                            <span class="ln-sep"></span>
                            <button type="button" onclick="lnMdInsert(this,'# ')" title="H1" style="font-weight:700;">H1</button>
                            <button type="button" onclick="lnMdInsert(this,'## ')" title="H2" style="font-weight:700;">H2</button>
                            <button type="button" onclick="lnMdInsert(this,'### ')" title="H3" style="font-weight:700;">H3</button>
                            <span class="ln-sep"></span>
                            <button type="button" onclick="lnMdInsert(this,'- ')" title="Lista">• ≡</button>
                            <button type="button" onclick="lnMdInsert(this,'1. ')" title="Lista numerada">1.</button>
                            <button type="button" onclick="lnMdInsert(this,'- [ ] ')" title="Checklist">☐</button>
                            <span class="ln-sep"></span>
                            <button type="button" onclick="lnMdInsert(this,'> ')" title="Citação">❝</button>
                            <button type="button" onclick="lnMdWrap(this,'[','](url)')" title="Link">🔗</button>
                            <button type="button" onclick="lnMdInsertLine(this,'---')" title="Linha">—</button>
                            <button type="button" onclick="lnMdTable(this)" title="Tabela">⊞</button>
                            <span class="ln-md-hint">Markdown</span>
                        </div>
                        <textarea name="note_text" class="form-control ln-md-textarea" rows="4"
                                  placeholder="Suporta **Markdown**…" style="font-size:13px;font-family:monospace;"></textarea>
                        <div class="ln-md-live-preview" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Ficheiros (opcional)</label>
                        <input type="file" name="note_images[]" class="form-control form-control-sm"
                               accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" multiple
                               onchange="lnPreviewImages(this,'phn-img-preview')">
                        <div id="phn-img-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="phnToggleAdd()">Cancelar</button>
                    </div>
                </form>
            </div>

            <?php if (empty($phd_notes_by_author)): ?>
                <p class="text-muted text-center small py-2">Nenhuma nota ainda.</p>
            <?php else: ?>
                <?php foreach ($phd_notes_by_author as $aid => $udata): ?>
                <?php $isMe = ($aid == $user_id); ?>
                <div class="ln-user-block mb-2">
                    <div class="ln-user-header <?= $isMe ? '' : 'ln-collapsed' ?>" onclick="lnToggleUser(this)">
                        <span class="ln-avatar <?= $isMe ? 'ln-avatar-me' : '' ?>"><?= strtoupper(substr($udata['username']??'?',0,1)) ?></span>
                        <span class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($udata['username']??'Desconhecido') ?><?= $isMe?' <span class="badge bg-secondary ms-1" style="font-size:10px;font-weight:400;">Eu</span>':'' ?></span>
                        <span class="ln-count text-muted ms-1" style="font-size:12px;">(<?= count($udata['notes']) ?>)</span>
                        <i class="bi bi-chevron-down ln-chevron ms-auto"></i>
                    </div>
                    <div class="ln-user-notes <?= $isMe?'':'d-none' ?>">
                        <?php foreach ($udata['notes'] as $note): ?>
                        <div class="ln-note-item" id="phn-note-<?= $note['id'] ?>">
                            <div class="ln-note-meta">
                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($note['created_at'])) ?></small>
                                <?php if ($note['author_user_id'] == $user_id): ?>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1"
                                            onclick="phnStartEdit(<?= $note['id'] ?>)" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar esta nota?')">
                                        <input type="hidden" name="action" value="delete_phd_note">
                                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                        <input type="hidden" name="phd_user_id" value="<?= $selected_user ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Vista Markdown -->
                            <div class="ln-note-md-view" data-raw="<?= htmlspecialchars($note['note_text'], ENT_QUOTES) ?>"></div>

                            <!-- Ficheiros em vista -->
                            <?php if (!empty($note['images'])): ?>
                            <div class="ln-note-images mt-2">
                                <?php foreach ($note['images'] as $img):
                                    $_ext = strtolower(pathinfo($img['original_name'], PATHINFO_EXTENSION));
                                    $_isImg = in_array($_ext, ['jpg','jpeg','png','gif','webp','svg']);
                                ?>
                                <?php if ($_isImg): ?>
                                <div class="ln-img-wrap">
                                    <img src="<?= htmlspecialchars($img['file_path']) ?>" alt="<?= htmlspecialchars($img['original_name']) ?>" onclick="lnLightbox(this.src)" title="<?= htmlspecialchars($img['original_name']) ?>">
                                    <?php if ($note['author_user_id'] == $user_id): ?>
                                    <form method="POST" class="ln-img-del">
                                        <input type="hidden" name="action" value="delete_phd_note_image">
                                        <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                        <input type="hidden" name="phd_user_id" value="<?= $selected_user ?>">
                                        <button type="submit" class="ln-del-img" title="Remover" onclick="return confirm('Remover ficheiro?')">×</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="note-file-chip">
                                    <i class="bi <?= phnFileIconClass($_ext) ?>"></i>
                                    <a href="<?= htmlspecialchars($img['file_path']) ?>" target="_blank"><?= htmlspecialchars($img['original_name']) ?></a>
                                    <?php if ($note['author_user_id'] == $user_id): ?>
                                    <form method="POST" style="display:inline;margin:0;">
                                        <input type="hidden" name="action" value="delete_phd_note_image">
                                        <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                        <input type="hidden" name="phd_user_id" value="<?= $selected_user ?>">
                                        <button type="submit" class="note-chip-del" title="Remover" onclick="return confirm('Remover ficheiro?')">×</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Formulário de edição (oculto) -->
                            <?php if ($note['author_user_id'] == $user_id): ?>
                            <div class="ln-note-edit-area" style="display:none;">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="edit_phd_note">
                                    <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                    <input type="hidden" name="phd_user_id" value="<?= $selected_user ?>">
                                    <div class="ln-editor-wrap">
                                    <div class="ln-editor-tabs">
                                        <button type="button" class="ln-tab active" onclick="lnEditorTab(this,'write')">Escrever</button>
                                        <button type="button" class="ln-tab" onclick="lnEditorTab(this,'preview')">Pré-visualizar</button>
                                    </div>
                                    <div class="ln-md-toolbar mb-1">
                                        <button type="button" onclick="lnMdWrap(this,'**','**')" title="Negrito"><b>B</b></button>
                                        <button type="button" onclick="lnMdWrap(this,'*','*')" title="Itálico"><em>I</em></button>
                                        <button type="button" onclick="lnMdWrap(this,'***','***')" title="Negrito+Itálico"><b><em>BI</em></b></button>
                                        <button type="button" onclick="lnMdWrap(this,'~~','~~')" title="Riscado"><s>S</s></button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdWrap(this,'`','`')" title="Código inline"><code>c</code></button>
                                        <button type="button" onclick="lnMdBlock(this,'```\n','\n```')" title="Bloco de código"><code>```</code></button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'# ')" style="font-weight:700;">H1</button>
                                        <button type="button" onclick="lnMdInsert(this,'## ')" style="font-weight:700;">H2</button>
                                        <button type="button" onclick="lnMdInsert(this,'### ')" style="font-weight:700;">H3</button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'- ')">• ≡</button>
                                        <button type="button" onclick="lnMdInsert(this,'1. ')">1.</button>
                                        <button type="button" onclick="lnMdInsert(this,'- [ ] ')">☐</button>
                                        <span class="ln-sep"></span>
                                        <button type="button" onclick="lnMdInsert(this,'> ')">❝</button>
                                        <button type="button" onclick="lnMdWrap(this,'[','](url)')">🔗</button>
                                        <button type="button" onclick="lnMdInsertLine(this,'---')">—</button>
                                        <button type="button" onclick="lnMdTable(this)">⊞</button>
                                        <span class="ln-md-hint">Markdown</span>
                                    </div>
                                    <textarea name="note_text" class="form-control ln-md-textarea" rows="4"
                                              style="font-size:13px;font-family:monospace;"><?= htmlspecialchars($note['note_text'], ENT_QUOTES) ?></textarea>
                                    <div class="ln-md-live-preview" style="display:none;"></div>
                                    </div>
                                    <?php if (!empty($note['images'])): ?>
                                    <div class="ln-edit-img-gallery mt-2">
                                        <div class="small text-muted mb-1">Clica num ficheiro para o inserir:</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($note['images'] as $img):
                                                $_ext = strtolower(pathinfo($img['original_name'], PATHINFO_EXTENSION));
                                                $_isImg = in_array($_ext, ['jpg','jpeg','png','gif','webp','svg']);
                                            ?>
                                            <?php if ($_isImg): ?>
                                            <div class="ln-edit-img-ref"
                                                 onclick="lnInsertImgRef(<?= $note['id'] ?>, '<?= addslashes($img['file_path']) ?>', '<?= addslashes($img['original_name']) ?>')"
                                                 title="Inserir imagem">
                                                <img src="<?= htmlspecialchars($img['file_path']) ?>" alt="">
                                                <div class="ln-edit-img-ref-overlay"><i class="bi bi-plus-circle-fill"></i></div>
                                            </div>
                                            <?php else: ?>
                                            <div class="ln-edit-file-ref"
                                                 onclick="lnInsertFileRef(<?= $note['id'] ?>, '<?= addslashes($img['file_path']) ?>', '<?= addslashes($img['original_name']) ?>')"
                                                 title="Inserir link">
                                                <i class="bi <?= phnFileIconClass($_ext) ?> fs-4"></i>
                                                <div class="ln-file-ref-name"><?= htmlspecialchars($img['original_name']) ?></div>
                                            </div>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="mt-2 mb-1">
                                        <label class="form-label small text-muted mb-1">Adicionar ficheiros</label>
                                        <input type="file" name="note_images[]" class="form-control form-control-sm"
                                               accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" multiple
                                               onchange="lnPreviewImages(this,'phn-edit-preview-<?= $note['id'] ?>')">
                                        <div id="phn-edit-preview-<?= $note['id'] ?>" class="d-flex flex-wrap gap-2 mt-1"></div>
                                    </div>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="phnCancelEdit(<?= $note['id'] ?>)">Cancelar</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="bi bi-journal-code"></i> Produção Científica</h4>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addArtigoModal">
                <i class="bi bi-plus-circle"></i> Adicionar
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($artigos)): ?>
                <p class="text-muted">Nenhuma produção científica registada.</p>
            <?php else: ?>
                <?php 
                // Agrupar por tipo
                $artigos_por_tipo = [
                    'artigo' => [],
                    'conferencia' => [],
                    'codigo' => [],
                    'dataset' => [],
                    'patente' => [],
                    'capitulo' => [],
                    'poster' => [],
                    'outro' => []
                ];
                
                foreach ($artigos as $artigo) {
                    $tipo = $artigo['tipo'] ?: 'outro';
                    if (isset($artigos_por_tipo[$tipo])) {
                        $artigos_por_tipo[$tipo][] = $artigo;
                    } else {
                        $artigos_por_tipo['outro'][] = $artigo;
                    }
                }
                
                $tipo_icons = [
                    'artigo' => 'file-text',
                    'conferencia' => 'calendar-event',
                    'codigo' => 'code-slash',
                    'dataset' => 'database',
                    'patente' => 'award',
                    'capitulo' => 'book',
                    'poster' => 'image',
                    'outro' => 'file-earmark'
                ];
                
                $tipo_labels = [
                    'artigo' => 'Artigos',
                    'conferencia' => 'Conferências',
                    'codigo' => 'Código',
                    'dataset' => 'Datasets',
                    'patente' => 'Patentes',
                    'capitulo' => 'Capítulos',
                    'poster' => 'Posters',
                    'outro' => 'Outros'
                ];
                
                foreach ($artigos_por_tipo as $tipo => $items):
                    if (empty($items)) continue;
                ?>
                    <h5 class="mt-3 mb-3">
                        <i class="bi bi-<?= $tipo_icons[$tipo] ?>"></i> 
                        <?= $tipo_labels[$tipo] ?> 
                        <span class="badge bg-secondary"><?= count($items) ?></span>
                    </h5>
                    
                    <?php foreach ($items as $artigo): ?>
                        <div class="artigo-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div style="font-weight: bold; margin-bottom: 8px;"><?= htmlspecialchars($artigo['titulo']) ?></div>
                                    <div style="font-size: 0.9em; color: #666;">
                                        <?php if ($artigo['autores']): ?>
                                            <strong>Autores:</strong> <?= htmlspecialchars($artigo['autores']) ?><br>
                                        <?php endif; ?>
                                        <?php if ($artigo['revista_conferencia']): ?>
                                            <strong><?= in_array($tipo, ['codigo', 'dataset']) ? 'Repositório:' : 'Publicado em:' ?></strong> 
                                            <?= htmlspecialchars($artigo['revista_conferencia']) ?><br>
                                        <?php endif; ?>
                                        <?php if ($artigo['ano']): ?>
                                            <strong>Ano:</strong> <?= $artigo['ano'] ?> | 
                                        <?php endif; ?>
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?= $artigo['status'] == 'publicado' ? 'success' : ($artigo['status'] == 'submetido' ? 'warning' : 'secondary') ?>">
                                            <?= htmlspecialchars($artigo['status']) ?>
                                        </span>
                                        <?php if ($artigo['link']): ?>
                                            <br><a href="<?= htmlspecialchars($artigo['link']) ?>" target="_blank">
                                                <i class="bi bi-link"></i> <?= in_array($tipo, ['codigo', 'dataset']) ? 'Aceder ao repositório' : 'Ver publicação' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary edit-artigo-btn" data-artigo-id="<?= $artigo['id'] ?>" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-artigo-btn" data-artigo-id="<?= $artigo['id'] ?>" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Adicionar Tarefa -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_task">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" name="descritivo" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Data Limite</label>
                        <input type="date" class="form-control" name="data_limite">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select class="form-select" name="responsavel">
                            <option value="">Nenhum</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $selected_user ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Estágio</label>
                        <select class="form-select" name="estagio">
                            <option value="pensada">Pensada</option>
                            <option value="execucao">Em Execução</option>
                            <option value="espera">Em Espera</option>
                            <option value="concluida">Concluída</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Informações PhD -->
<div class="modal fade" id="phdInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="save_phd_info">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Informações do Doutoramento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Título</label>
                            <input type="text" class="form-control" name="titulo_doutoramento" 
                                   value="<?= htmlspecialchars($phd_info['titulo_doutoramento'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data de Início</label>
                            <input type="date" class="form-control" name="data_inicio" 
                                   value="<?= $phd_info['data_inicio'] ?? '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Orientador</label>
                            <input type="text" class="form-control" name="orientador" 
                                   value="<?= htmlspecialchars($phd_info['orientador'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Coorientador</label>
                            <input type="text" class="form-control" name="coorientador" 
                                   value="<?= htmlspecialchars($phd_info['coorientador'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instituição</label>
                            <input type="text" class="form-control" name="instituicao" 
                                   value="<?= htmlspecialchars($phd_info['instituicao'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Departamento</label>
                            <input type="text" class="form-control" name="departamento" 
                                   value="<?= htmlspecialchars($phd_info['departamento'] ?? '') ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Link da Tese</label>
                            <input type="url" class="form-control" name="link_tese" 
                                   value="<?= htmlspecialchars($phd_info['link_tese'] ?? '') ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Notas</label>
                            <textarea class="form-control" name="notas" rows="4"><?= htmlspecialchars($phd_info['notas'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Adicionar Artigo -->
<div class="modal fade" id="addArtigoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_artigo">
                <input type="hidden" name="selected_user" value="<?= $selected_user ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Produção Científica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select class="form-select" name="tipo_artigo" id="tipo_artigo_add" onchange="updateFieldLabels('add')">
                            <option value="artigo">Artigo Científico</option>
                            <option value="conferencia">Conferência</option>
                            <option value="codigo">Código/Software</option>
                            <option value="dataset">Dataset</option>
                            <option value="patente">Patente</option>
                            <option value="capitulo">Capítulo de Livro</option>
                            <option value="poster">Poster</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo_artigo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="autores_label_add">Autores *</label>
                        <input type="text" class="form-control" name="autores" required 
                               placeholder="Nome1, Nome2, Nome3">
                        <small class="text-muted" id="autores_help_add">Separe os nomes por vírgulas</small>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label" id="revista_label_add">Revista/Conferência</label>
                            <input type="text" class="form-control" name="revista_conferencia" id="revista_add">
                            <small class="text-muted" id="revista_help_add">Ex: Nature, IEEE, arXiv</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ano</label>
                            <input type="number" class="form-control" name="ano" value="<?= date('Y') ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status_artigo">
                                <option value="publicado">Publicado</option>
                                <option value="submetido">Submetido</option>
                                <option value="em_preparacao">Em Preparação</option>
                                <option value="aceite">Aceite</option>
                                <option value="em_desenvolvimento">Em Desenvolvimento</option>
                                <option value="disponivel">Disponível</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="link_label_add">Link/URL</label>
                        <input type="url" class="form-control" name="link_artigo" id="link_add"
                               placeholder="https://">
                        <small class="text-muted" id="link_help_add">DOI, GitHub, Zenodo, etc.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Artigo -->
<div class="modal fade" id="editArtigoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_artigo">
                <input type="hidden" name="artigo_id" id="edit_artigo_id">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Produção Científica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select class="form-select" name="tipo_artigo_edit" id="tipo_artigo_edit" onchange="updateFieldLabels('edit')">
                            <option value="artigo">Artigo Científico</option>
                            <option value="conferencia">Conferência</option>
                            <option value="codigo">Código/Software</option>
                            <option value="dataset">Dataset</option>
                            <option value="patente">Patente</option>
                            <option value="capitulo">Capítulo de Livro</option>
                            <option value="poster">Poster</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo_artigo_edit" id="titulo_artigo_edit" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="autores_label_edit">Autores *</label>
                        <input type="text" class="form-control" name="autores_edit" id="autores_edit" required>
                        <small class="text-muted" id="autores_help_edit">Separe os nomes por vírgulas</small>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label" id="revista_label_edit">Revista/Conferência</label>
                            <input type="text" class="form-control" name="revista_conferencia_edit" id="revista_conferencia_edit">
                            <small class="text-muted" id="revista_help_edit">Ex: Nature, IEEE, arXiv</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ano</label>
                            <input type="number" class="form-control" name="ano_edit" id="ano_edit">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status_artigo_edit" id="status_artigo_edit">
                                <option value="publicado">Publicado</option>
                                <option value="submetido">Submetido</option>
                                <option value="em_preparacao">Em Preparação</option>
                                <option value="aceite">Aceite</option>
                                <option value="em_desenvolvimento">Em Desenvolvimento</option>
                                <option value="disponivel">Disponível</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="link_label_edit">Link/URL</label>
                        <input type="url" class="form-control" name="link_artigo_edit" id="link_artigo_edit">
                        <small class="text-muted" id="link_help_edit">DOI, GitHub, Zenodo, etc.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Função para atualizar labels baseado no tipo selecionado
function updateFieldLabels(mode) {
    const tipo = document.getElementById('tipo_artigo_' + mode).value;
    
    const labels = {
        autores: {
            codigo: 'Autores/Desenvolvedores *',
            dataset: 'Autores/Criadores *',
            patente: 'Inventores *',
            default: 'Autores *'
        },
        revista: {
            codigo: 'Repositório',
            dataset: 'Repositório',
            patente: 'Escritório de Patentes',
            conferencia: 'Conferência',
            default: 'Revista/Publicação'
        },
        link: {
            codigo: 'Link (GitHub, GitLab, etc.)',
            dataset: 'Link (Zenodo, Figshare, etc.)',
            patente: 'Link/Número da Patente',
            default: 'Link/DOI'
        },
        revista_help: {
            codigo: 'Ex: GitHub, GitLab, Bitbucket',
            dataset: 'Ex: Zenodo, Figshare, OSF',
            patente: 'Ex: INPI, EPO, USPTO',
            default: 'Ex: Nature, IEEE, arXiv'
        },
        link_help: {
            codigo: 'URL do repositório',
            dataset: 'URL do repositório de dados',
            patente: 'URL ou número oficial',
            default: 'DOI, arXiv, ou URL'
        }
    };
    
    // Atualizar label de autores
    document.getElementById('autores_label_' + mode).textContent = 
        labels.autores[tipo] || labels.autores.default;
    
    // Atualizar label de revista
    document.getElementById('revista_label_' + mode).textContent = 
        labels.revista[tipo] || labels.revista.default;
    
    // Atualizar placeholder de revista
    const revistaField = document.getElementById(mode === 'add' ? 'revista_add' : 'revista_conferencia_edit');
    if (revistaField) {
        revistaField.placeholder = labels.revista[tipo] || labels.revista.default;
    }
    
    // Atualizar label de link
    document.getElementById('link_label_' + mode).textContent = 
        labels.link[tipo] || labels.link.default;
    
    // Atualizar help text
    document.getElementById('revista_help_' + mode).textContent = 
        labels.revista_help[tipo] || labels.revista_help.default;
    
    document.getElementById('link_help_' + mode).textContent = 
        labels.link_help[tipo] || labels.link_help.default;
}

document.addEventListener('DOMContentLoaded', function() {
    // Seletor de utilizador
    document.getElementById('userSelector').addEventListener('change', function() {
        const userId = this.value;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('tab', 'phd_kanban');
        currentUrl.searchParams.set('user', userId);
        window.location.href = currentUrl.toString();
    });
    
    // Drag and Drop
    let draggedElement = null;
    
    document.querySelectorAll('.kanban-card').forEach(card => {
        card.addEventListener('dragstart', function(e) {
            draggedElement = this;
            this.style.opacity = '0.5';
        });
        
        card.addEventListener('dragend', function(e) {
            this.style.opacity = '';
        });
    });
    
    document.querySelectorAll('.kanban-column').forEach(column => {
        column.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.background = '#e9ecef';
        });
        
        column.addEventListener('dragleave', function(e) {
            this.style.background = '';
        });
        
        column.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.background = '';
            
            if (draggedElement) {
                const taskId = draggedElement.dataset.taskId;
                const newStage = this.dataset.stage;
                
                const formData = new FormData();
                formData.append('action', 'update_stage');
                formData.append('task_id', taskId);
                formData.append('new_stage', newStage);
                
                fetch('phd_kanban_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Sucesso - recarregar página
                        location.reload();
                    } else {
                        console.error('Erro:', data.error);
                        alert('Erro ao mover tarefa: ' + data.error);
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Erro de rede:', error);
                    alert('Erro de conexão. A tarefa pode ter sido movida. Recarregando...');
                    location.reload();
                });
            }
        });
    });
    
    // Botões de editar tarefa - usar função openTaskEditor
    document.querySelectorAll('.edit-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.dataset.taskId;
            openTaskEditor(taskId);
        });
    });
    
    // Botões de eliminar tarefa
    document.querySelectorAll('.delete-task-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Tem a certeza que deseja eliminar esta tarefa?')) {
                const taskId = this.dataset.taskId;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_task">
                    <input type="hidden" name="task_id" value="${taskId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
    
    // Botões de editar artigo
    document.querySelectorAll('.edit-artigo-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const artigoId = this.dataset.artigoId;
            
            fetch(`get_artigo_details.php?id=${artigoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const artigo = data.artigo;
                        
                        document.getElementById('edit_artigo_id').value = artigo.id;
                        document.getElementById('titulo_artigo_edit').value = artigo.titulo;
                        document.getElementById('autores_edit').value = artigo.autores;
                        document.getElementById('revista_conferencia_edit').value = artigo.revista_conferencia || '';
                        document.getElementById('ano_edit').value = artigo.ano || '';
                        document.getElementById('link_artigo_edit').value = artigo.link || '';
                        document.getElementById('tipo_artigo_edit').value = artigo.tipo || 'artigo';
                        document.getElementById('status_artigo_edit').value = artigo.status || 'publicado';
                        
                        const modal = new bootstrap.Modal(document.getElementById('editArtigoModal'));
                        modal.show();
                    } else {
                        alert('Erro ao carregar dados: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do artigo');
                });
        });
    });
    
    // Botões de eliminar artigo
    document.querySelectorAll('.delete-artigo-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Tem a certeza que deseja eliminar este artigo?')) {
                const artigoId = this.dataset.artigoId;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_artigo">
                    <input type="hidden" name="artigo_id" value="${artigoId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
});
</script>

<?php
$db->close();

// Incluir editor universal de tasks
include __DIR__ . '/../edit_task.php';
?>

<!-- Modal: Associar Task de Sprint ao PhD Kanban -->
<div class="modal fade" id="linkSprintTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Associar Task de Sprint ao PhD Plan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="link_sprint_task">

                    <div class="row g-3 mb-3">
                        <!-- Col 1: Protótipo -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="bi bi-cpu"></i> Protótipo</label>
                            <select id="lstProto" class="form-select" onchange="lstOnProto()">
                                <option value="">— todos —</option>
                                <?php foreach ($sprintTasksForModal as $pid => $proto): ?>
                                <?php if ($pid > 0): ?>
                                <option value="<?= $pid ?>"><?= htmlspecialchars($proto['name']) ?> — <?= htmlspecialchars($proto['title']) ?></option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (isset($sprintTasksForModal[0])): ?>
                                <option value="0">(sem protótipo)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <!-- Col 2: Sprint -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="bi bi-lightning-charge"></i> Sprint</label>
                            <select id="lstSprint" class="form-select" onchange="lstOnSprint()" disabled>
                                <option value="">— selecione protótipo —</option>
                            </select>
                        </div>
                        <!-- Col 3: Task -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="bi bi-check2-square"></i> Task</label>
                            <select id="lstTask" name="todo_id" class="form-select" required disabled>
                                <option value="">— selecione sprint —</option>
                            </select>
                        </div>
                    </div>

                    <div id="lstTaskPreview" class="alert alert-light border" style="display:none; font-size:13px;">
                        <strong>Task selecionada:</strong> <span id="lstTaskName"></span>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Estágio no PhD Kanban</label>
                        <select name="estagio" class="form-select">
                            <option value="pensada">Pensada</option>
                            <option value="execucao" selected>Em Execução</option>
                            <option value="espera">Em Espera</option>
                            <option value="concluida">Concluída</option>
                        </select>
                    </div>

                    <div class="alert alert-warning py-2 mb-0" style="font-size:12px;">
                        <i class="bi bi-info-circle"></i> A task continuará na sua sprint original e passará a aparecer também no PhD Kanban.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Associar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const lstData = <?= json_encode($sprintTasksForModal, JSON_UNESCAPED_UNICODE) ?>;

function lstOnProto() {
    const pid    = document.getElementById('lstProto').value;
    const sSel   = document.getElementById('lstSprint');
    const tSel   = document.getElementById('lstTask');
    sSel.innerHTML = '<option value="">— selecione sprint —</option>';
    tSel.innerHTML = '<option value="">— selecione sprint —</option>';
    sSel.disabled = true; tSel.disabled = true;
    document.getElementById('lstTaskPreview').style.display = 'none';

    // Juntar sprints dos protótipos selecionados (ou todos se vazio)
    const protos = pid === '' ? Object.values(lstData) : (lstData[pid] ? [lstData[pid]] : []);
    const sprintMap = {};
    protos.forEach(proto => {
        Object.entries(proto.sprints || {}).forEach(([sid, sprint]) => {
            if (!sprintMap[sid]) sprintMap[sid] = {...sprint, id: sid};
            else sprintMap[sid].tasks = sprintMap[sid].tasks.concat(sprint.tasks);
        });
    });
    const stateEmoji = {'aberta':'🟡','em execução':'🔵','suspensa':'🟠','concluída':'✅','fechada':'🔒'};
    Object.values(sprintMap).sort((a,b) => a.nome.localeCompare(b.nome)).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = (stateEmoji[s.estado] || '•') + ' ' + s.nome;
        opt.dataset.tasks = JSON.stringify(s.tasks);
        sSel.appendChild(opt);
    });
    sSel.disabled = false;
}

function lstOnSprint() {
    const sSel = document.getElementById('lstSprint');
    const tSel = document.getElementById('lstTask');
    tSel.innerHTML = '<option value="">— selecione task —</option>';
    tSel.disabled = true;
    document.getElementById('lstTaskPreview').style.display = 'none';
    const opt = sSel.options[sSel.selectedIndex];
    if (!opt || !opt.dataset.tasks) return;
    const tasks = JSON.parse(opt.dataset.tasks);
    const stateEmoji = {'aberta':'🟡','em execução':'🔵','suspensa':'🟠','concluída':'✅'};
    tasks.forEach(t => {
        const o = document.createElement('option');
        o.value = t.id;
        o.textContent = (stateEmoji[t.estado] || '•') + ' ' + t.titulo;
        tSel.appendChild(o);
    });
    tSel.disabled = false;
    tSel.onchange = function() {
        const name = tSel.options[tSel.selectedIndex]?.textContent || '';
        document.getElementById('lstTaskName').textContent = name;
        document.getElementById('lstTaskPreview').style.display = name ? '' : 'none';
    };
}

/* ===== PHD NOTES JS ===== */
function phnToggleAdd() {
    const f = document.getElementById('phn-add-form');
    f.style.display = f.style.display === 'none' ? '' : 'none';
}
function phnStartEdit(noteId) {
    const item = document.getElementById('phn-note-' + noteId);
    item.querySelector('.ln-note-md-view').style.display = 'none';
    const imgs = item.querySelector('.ln-note-images');
    if (imgs) imgs.style.display = 'none';
    item.querySelector('.ln-note-edit-area').style.display = '';
    item.querySelector('.ln-note-meta .d-flex')?.style && (item.querySelector('.ln-note-meta .d-flex').style.display = 'none');
}
function phnCancelEdit(noteId) {
    const item = document.getElementById('phn-note-' + noteId);
    item.querySelector('.ln-note-md-view').style.display = '';
    const imgs = item.querySelector('.ln-note-images');
    if (imgs) imgs.style.display = '';
    item.querySelector('.ln-note-edit-area').style.display = 'none';
    const btns = item.querySelector('.ln-note-meta .d-flex');
    if (btns) btns.style.display = '';
}
function lnToggleUser(header) {
    header.classList.toggle('ln-collapsed');
    const notes = header.nextElementSibling;
    notes.classList.toggle('d-none', header.classList.contains('ln-collapsed'));
}
function lnEditorTab(btn, mode) {
    const wrap = btn.closest('.ln-editor-wrap');
    const ta = wrap.querySelector('.ln-md-textarea');
    const preview = wrap.querySelector('.ln-md-live-preview');
    wrap.querySelectorAll('.ln-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    if (mode === 'preview') {
        preview.style.display = '';
        ta.style.display = 'none';
        preview.innerHTML = typeof marked !== 'undefined' ? marked.parse(ta.value || '') : ta.value;
    } else {
        preview.style.display = 'none';
        ta.style.display = '';
        ta.focus();
    }
}
function lnGetTa(btn) {
    const wrap = btn.closest('.ln-editor-wrap');
    return wrap.querySelector('.ln-md-textarea');
}
function lnMdWrap(btn, before, after) {
    const ta = lnGetTa(btn); ta.focus();
    const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    const sel = v.slice(s, e) || 'texto';
    ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + sel.length;
}
function lnMdBlock(btn, before, after) {
    const ta = lnGetTa(btn); ta.focus();
    const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    const sel = v.slice(s, e) || 'código';
    ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + sel.length;
}
function lnMdInsert(btn, prefix) {
    const ta = lnGetTa(btn); ta.focus();
    const s = ta.selectionStart, v = ta.value;
    const lineStart = v.lastIndexOf('\n', s - 1) + 1;
    ta.value = v.slice(0, lineStart) + prefix + v.slice(lineStart);
    ta.selectionStart = ta.selectionEnd = lineStart + prefix.length;
}
function lnMdInsertLine(btn, text) {
    const ta = lnGetTa(btn); ta.focus();
    const s = ta.selectionStart, v = ta.value;
    ta.value = v.slice(0, s) + '\n' + text + '\n' + v.slice(s);
    ta.selectionStart = ta.selectionEnd = s + text.length + 2;
}
function lnMdTable(btn) {
    const ta = lnGetTa(btn); ta.focus();
    const tbl = '\n| Col1 | Col2 | Col3 |\n|------|------|------|\n| A    | B    | C    |\n';
    const s = ta.selectionStart;
    ta.value = ta.value.slice(0, s) + tbl + ta.value.slice(s);
    ta.selectionStart = ta.selectionEnd = s + tbl.length;
}
function lnPreviewImages(input, containerId) {
    const cont = document.getElementById(containerId);
    if (!cont) return;
    cont.innerHTML = '';
    Array.from(input.files).forEach(f => {
        const isImg = f.type.startsWith('image/');
        if (isImg) {
            const img = document.createElement('img');
            img.style.cssText = 'max-width:80px;max-height:70px;border-radius:4px;border:1px solid #dee2e6;object-fit:cover;';
            img.src = URL.createObjectURL(f);
            cont.appendChild(img);
        } else {
            const chip = document.createElement('div');
            chip.className = 'note-file-chip';
            chip.innerHTML = '<i class="bi bi-file-earmark"></i> ' + f.name;
            cont.appendChild(chip);
        }
    });
}
function lnLightbox(src) {
    let lb = document.getElementById('ln-lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'ln-lightbox';
        lb.innerHTML = '<img>';
        lb.onclick = () => lb.style.display = 'none';
        document.body.appendChild(lb);
    }
    lb.querySelector('img').src = src;
    lb.style.display = 'flex';
}
function lnInsertImgRef(noteId, path, name) {
    const ta = document.querySelector('#phn-note-' + noteId + ' .ln-note-edit-area .ln-md-textarea');
    if (!ta) return;
    const md = '![' + name + '](' + path + ')';
    const s = ta.selectionStart;
    ta.value = ta.value.slice(0, s) + md + ta.value.slice(s);
    ta.selectionStart = ta.selectionEnd = s + md.length;
    ta.focus();
}
function lnInsertFileRef(noteId, path, name) {
    const ta = document.querySelector('#phn-note-' + noteId + ' .ln-note-edit-area .ln-md-textarea');
    if (!ta) return;
    const md = '[' + name + '](' + path + ')';
    const s = ta.selectionStart;
    ta.value = ta.value.slice(0, s) + md + ta.value.slice(s);
    ta.selectionStart = ta.selectionEnd = s + md.length;
    ta.focus();
}
/* Render markdown on all .ln-note-md-view elements */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof marked === 'undefined') return;
    marked.setOptions({ breaks: true });
    document.querySelectorAll('.ln-note-md-view').forEach(function(el) {
        const raw = el.getAttribute('data-raw') || '';
        el.innerHTML = marked.parse(raw);
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js" onload="
  if(typeof marked!=='undefined'){
    marked.setOptions({breaks:true});
    document.querySelectorAll('.ln-note-md-view').forEach(function(el){
      el.innerHTML=marked.parse(el.getAttribute('data-raw')||'');
    });
  }
"></script>