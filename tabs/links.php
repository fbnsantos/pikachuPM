<?php
// links.php — Gestão de links com edição, filtro, exportação, importação, ordenação e destaque visual
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_path = __DIR__ . '/../links2.sqlite';
$nova_base = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($nova_base) {
        $db->exec("CREATE TABLE links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL,
            titulo TEXT,
            categoria TEXT,
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
            ordem INTEGER
        )");
    }
} catch (Exception $e) {
    die("Erro ao abrir/criar base de dados: " . $e->getMessage());
}

// Reordenar via JS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!empty($json['editar'])) {
        $stmt = $db->prepare("UPDATE links SET titulo = :titulo, categoria = :categoria WHERE id = :id");
        $stmt->execute([
            ':titulo' => $json['titulo'],
            ':categoria' => $json['categoria'],
            ':id' => $json['id']
        ]);
        http_response_code(200);
        exit;
    }
    if (!empty($json['reordenar']) && is_array($json['ordem'])) {
        foreach ($json['ordem'] as $index => $id) {
            $stmt = $db->prepare("UPDATE links SET ordem = :ordem WHERE id = :id");
            $stmt->execute([':ordem' => $index, ':id' => $id]);
        }
        http_response_code(200);
        exit;
    }
}

// Exportar CSV
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="links.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'URL', 'Título', 'Categoria', 'Criado em']);
    foreach ($db->query("SELECT * FROM links") as $linha) {
        fputcsv($out, $linha);
    }
    fclose($out);
    exit;
}

// Importar CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ficheiro_csv'])) {
    $ficheiro = $_FILES['ficheiro_csv']['tmp_name'];
    if (($handle = fopen($ficheiro, 'r')) !== false) {
        fgetcsv($handle); // ignora cabeçalho
        while (($linha = fgetcsv($handle)) !== false) {
            [$id, $url, $titulo, $categoria, $criado_em] = $linha;
            $stmt = $db->prepare("INSERT INTO links (url, titulo, categoria, criado_em) VALUES (?, ?, ?, ?)");
            $stmt->execute([$url, $titulo, $categoria, $criado_em]);
        }
        fclose($handle);
    }
    header('Location: index.php?tab=links');
    exit;
}

// Inserção de novo link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $stmt = $db->prepare("INSERT INTO links (url, titulo, categoria, ordem) VALUES (:url, :titulo, :categoria, :ordem)");
    $ordem = $db->query("SELECT COUNT(*) FROM links")->fetchColumn();
    $stmt->execute([
        ':url' => trim($_POST['url']),
        ':titulo' => trim($_POST['titulo']),
        ':categoria' => trim($_POST['categoria']),
        ':ordem' => $ordem
    ]);
    header('Location: index.php?tab=links');
    exit;
}

// Remoção
if (isset($_POST['apagar'])) {
    $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
    $stmt->execute([':id' => (int)$_POST['apagar']]);
    header('Location: index.php?tab=links');
    exit;
}

$filtro = $_GET['filtro'] ?? '';
$stmt = $db->prepare("SELECT * FROM links WHERE categoria LIKE :filtro ORDER BY ordem ASC, criado_em DESC");
$stmt->execute([':filtro' => "%$filtro%"]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container mt-4">
    <h2>📚 Gestão de Links Web</h2>
    <p class="text-muted">Arraste para reordenar, edite títulos/categorias ou clique para abrir o link.</p>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" name="filtro" value="<?= htmlspecialchars($filtro) ?>" class="form-control" placeholder="Filtrar por categoria">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Filtrar</button>
        </div>
        <div class="col-auto ms-auto">
            <a href="?exportar=1" class="btn btn-outline-primary">📤 Exportar CSV</a>
        </div>
    </form>

    <form method="post" enctype="multipart/form-data" class="row g-2 mb-4">
        <div class="col-md-5">
            <input type="file" name="ficheiro_csv" accept=".csv" class="form-control" required>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-success">📥 Importar CSV</button>
        </div>
    </form>

    <button class="btn btn-outline-secondary mb-3" type="button" onclick="document.getElementById('formAdicionar').classList.toggle('d-none')">➕ Adicionar link</button>
<form method="post" id="formAdicionar" class="row g-3 mb-4 d-none">
        <div class="col-md-5">
            <input type="url" name="url" class="form-control" placeholder="URL" required>
        </div>
        <div class="col-md-3">
            <input type="text" name="titulo" class="form-control" placeholder="Título opcional">
        </div>
        <div class="col-md-2">
            <input type="text" name="categoria" class="form-control" placeholder="Categoria">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Guardar</button>
        </div>
    </form>

    <ul class="list-group" id="sortable">
        <?php foreach ($links as $link): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start" data-id="<?= $link['id'] ?>" style="cursor: grab;">
                <div class="me-auto" onclick="window.open('<?= htmlspecialchars($link['url']) ?>', '_blank')">
                    <div class="fw-bold fs-5" id="titulo-<?= $link['id'] ?>" contenteditable="true">
                        <?= htmlspecialchars($link['titulo'] ?: $link['url']) ?>
                    </div>
                    <div class="text-muted small">
                        🔗 <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" title="<?= htmlspecialchars($link['url']) ?>">
    <?= $isSecure ? '🔒 ' : '' ?>Press here
</a>
                    </div>
                    <small class="text-muted">
                        Categoria: <span id="categoria-<?= $link['id'] ?>" contenteditable="true">
                            <?= htmlspecialchars($link['categoria']) ?>
                        </span> | <?= $link['criado_em'] ?>
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary edit-btn ms-2" data-id="<?= $link['id'] ?>">✏️ Editar</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Apagar este link?');">
                        <input type="hidden" name="apagar" value="<?= $link['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const titulo = document.getElementById('titulo-' + id).innerText;
        const categoria = document.getElementById('categoria-' + id).innerText;

        fetch('index.php?tab=links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ editar: true, id, titulo, categoria })
        }).then(res => {
            if (res.ok) alert('✅ Alterado com sucesso!');
            else alert('❌ Erro ao atualizar');
        });
    });
});

const lista = document.getElementById("sortable");
Sortable.create(lista, {
    animation: 150,
    onEnd: function () {
        const ids = Array.from(lista.children).map(li => li.dataset.id);
        fetch("index.php?tab=links", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ reordenar: true, ordem: ids })
        });
    }
});
</script>

<?php
// ======================================================================
// SECÇÃO DE GESTÃO DE FICHEIROS
// ======================================================================

// Conectar à base de dados MySQL dos ficheiros
require_once __DIR__ . '/config.php';

try {
    $pdo_files = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Erro ao conectar à BD de ficheiros: " . $e->getMessage());
}

// Processar adição de notas aos ficheiros
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_file_note') {
    header('Content-Type: application/json');
    $file_id = (int)$_POST['file_id'];
    $note = trim($_POST['note']);
    
    // Verificar se coluna 'notes' existe, senão criar
    try {
        $pdo_files->exec("ALTER TABLE task_files ADD COLUMN IF NOT EXISTS notes TEXT");
    } catch (PDOException $e) {
        // Coluna já existe
    }
    
    $stmt = $pdo_files->prepare("UPDATE task_files SET notes = ? WHERE id = ?");
    $stmt->execute([$note, $file_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Pesquisar ficheiros
$search_term = $_GET['search_files'] ?? '';
$file_query = "
    SELECT 
        tf.id as file_id,
        tf.file_name,
        tf.file_path,
        tf.file_size,
        tf.uploaded_at,
        tf.notes,
        tf.todo_id,
        t.titulo as task_title,
        t.estado as task_status,
        u.nome as uploaded_by_name
    FROM task_files tf
    LEFT JOIN todos t ON tf.todo_id = t.id
    LEFT JOIN user_tokens u ON tf.uploaded_by = u.user_id
    WHERE tf.file_name LIKE :search OR tf.notes LIKE :search OR t.titulo LIKE :search
    ORDER BY tf.uploaded_at DESC
";

$stmt = $pdo_files->prepare($file_query);
$stmt->execute([':search' => "%$search_term%"]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Função para formatar tamanho de ficheiro
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<hr class="my-5">

<div class="container mt-5">
    <h2>📁 Gestão de Ficheiros do Sistema</h2>
    <p class="text-muted">Todos os ficheiros carregados nas tarefas. Pesquise, adicione notas e aceda às tarefas associadas.</p>

    <form method="get" class="row g-2 mb-4">
        <input type="hidden" name="tab" value="links">
        <div class="col-md-6">
            <input type="text" name="search_files" value="<?= htmlspecialchars($search_term) ?>" 
                   class="form-control" placeholder="🔍 Pesquisar por nome, notas ou tarefa...">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Pesquisar</button>
        </div>
        <?php if ($search_term): ?>
        <div class="col-auto">
            <a href="?tab=links" class="btn btn-outline-secondary">Limpar</a>
        </div>
        <?php endif; ?>
    </form>

    <div class="alert alert-info">
        <strong>📊 Total:</strong> <?= count($files) ?> ficheiro(s) encontrado(s)
    </div>

    <?php if (empty($files)): ?>
        <div class="alert alert-warning">
            <strong>⚠️ Nenhum ficheiro encontrado</strong>
            <?php if ($search_term): ?>
                <p class="mb-0">Tente uma pesquisa diferente.</p>
            <?php else: ?>
                <p class="mb-0">Ainda não foram carregados ficheiros nas tarefas.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <div class="table-responsive">
        <table class="table table-hover table-bordered">
            <thead class="table-dark">
                <tr>
                    <th width="5%">📄</th>
                    <th width="20%">Nome do Ficheiro</th>
                    <th width="10%">Tamanho</th>
                    <th width="15%">Tarefa Associada</th>
                    <th width="25%">Notas</th>
                    <th width="12%">Carregado em</th>
                    <th width="13%">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file): ?>
                <tr id="file-row-<?= $file['file_id'] ?>">
                    <td class="text-center">
                        <?php
                        $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                        $icon = '📄';
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) $icon = '🖼️';
                        elseif (in_array($ext, ['pdf'])) $icon = '📕';
                        elseif (in_array($ext, ['doc', 'docx'])) $icon = '📘';
                        elseif (in_array($ext, ['xls', 'xlsx'])) $icon = '📗';
                        elseif (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) $icon = '🗜️';
                        elseif (in_array($ext, ['mp4', 'avi', 'mov', 'wmv'])) $icon = '🎬';
                        elseif (in_array($ext, ['mp3', 'wav', 'ogg'])) $icon = '🎵';
                        echo $icon;
                        ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($file['file_name']) ?></strong><br>
                        <small class="text-muted">.<?= $ext ?></small>
                    </td>
                    <td><?= formatFileSize($file['file_size']) ?></td>
                    <td>
                        <?php if ($file['todo_id']): ?>
                            <button class="btn btn-sm btn-outline-primary" 
                                    onclick="openTaskEditor(<?= $file['todo_id'] ?>)"
                                    title="Clique para editar a tarefa">
                                📋 <?= htmlspecialchars($file['task_title']) ?>
                            </button>
                            <br>
                            <small class="badge bg-<?= 
                                $file['task_status'] === 'concluida' ? 'success' : 
                                ($file['task_status'] === 'em_execucao' ? 'warning' : 'secondary') 
                            ?>">
                                <?= htmlspecialchars($file['task_status']) ?>
                            </small>
                        <?php else: ?>
                            <em class="text-muted">Sem tarefa</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <input type="text" 
                                   class="form-control form-control-sm file-note" 
                                   id="note-<?= $file['file_id'] ?>"
                                   value="<?= htmlspecialchars($file['notes'] ?? '') ?>"
                                   placeholder="Adicionar nota...">
                            <button class="btn btn-outline-success btn-sm" 
                                    onclick="saveFileNote(<?= $file['file_id'] ?>)">
                                💾
                            </button>
                        </div>
                    </td>
                    <td>
                        <?= date('d/m/Y H:i', strtotime($file['uploaded_at'])) ?><br>
                        <small class="text-muted">por <?= htmlspecialchars($file['uploaded_by_name'] ?? 'Desconhecido') ?></small>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($file['file_path']) ?>" 
                           target="_blank" 
                           class="btn btn-sm btn-primary"
                           title="Ver/Download">
                            👁️ Ver
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<script>
// Função para guardar nota do ficheiro
function saveFileNote(fileId) {
    const noteInput = document.getElementById('note-' + fileId);
    const note = noteInput.value;
    
    const formData = new FormData();
    formData.append('action', 'add_file_note');
    formData.append('file_id', fileId);
    formData.append('note', note);
    
    fetch('index.php?tab=links', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Feedback visual
            noteInput.classList.add('border-success');
            setTimeout(() => {
                noteInput.classList.remove('border-success');
            }, 1500);
            
            // Toast notification
            showToast('✅ Nota guardada com sucesso!');
        } else {
            alert('❌ Erro ao guardar nota');
        }
    })
    .catch(err => {
        console.error(err);
        alert('❌ Erro ao guardar nota');
    });
}

// Toast notification simples
function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'position-fixed top-0 end-0 m-3 alert alert-success alert-dismissible fade show';
    toast.setAttribute('role', 'alert');
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Enter para guardar nota
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.file-note').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const fileId = this.id.replace('note-', '');
                saveFileNote(fileId);
            }
        });
    });
});
</script>

<style>
.file-note:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.border-success {
    border-color: #198754 !important;
    box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25) !important;
}
</style>