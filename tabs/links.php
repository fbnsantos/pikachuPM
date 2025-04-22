<?php
// links.php — Gestão de links com edição, filtro, exportação, importação, ordenação e destaque visual
session_start();

$db_path = __DIR__ . '/../links.sqlite';
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

    <form method="post" class="row g-3 mb-4">
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
                        🔗 <?= htmlspecialchars($link['url']) ?>
                    </div>
                    <small class="text-muted">
                        Categoria: <span id="categoria-<?= $link['id'] ?>" contenteditable="true">
                            <?= htmlspecialchars($link['categoria']) ?>
                        </span> | <?= $link['criado_em'] ?>
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-success edit-btn ms-2" data-id="<?= $link['id'] ?>">💾</button>
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