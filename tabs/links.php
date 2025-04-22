<?php
// links.php — Gestão de links web
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
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {
    die("Erro ao abrir/criar base de dados: " . $e->getMessage());
}

// Inserção de novo link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $stmt = $db->prepare("INSERT INTO links (url, titulo, categoria) VALUES (:url, :titulo, :categoria)");
    $stmt->execute([
        ':url' => trim($_POST['url']),
        ':titulo' => trim($_POST['titulo']),
        ':categoria' => trim($_POST['categoria'])
    ]);
    header('Location: links.php');
    exit;
}

// Carregamento de links existentes
$links = $db->query("SELECT * FROM links ORDER BY criado_em DESC")->fetchAll(PDO::FETCH_ASSOC);

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container mt-4">
    <h2>📚 Gestão de Links Web</h2>

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

    <h4>🔗 Links guardados:</h4>
    <ul class="list-group">
        <?php foreach ($links as $link): ?>
            <li class="list-group-item">
                <strong><?= htmlspecialchars($link['titulo'] ?: $link['url']) ?></strong>
                <br>
                <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank"><?= htmlspecialchars($link['url']) ?></a><br>
                <small class="text-muted">Categoria: <?= htmlspecialchars($link['categoria']) ?> | <?= $link['criado_em'] ?></small>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
