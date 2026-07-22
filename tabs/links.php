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
            ordem INTEGER,
            tipo TEXT NOT NULL DEFAULT 'link'
        )");
    } else {
        // migração silenciosa
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('tipo', $cols)) $db->exec("ALTER TABLE links ADD COLUMN tipo TEXT NOT NULL DEFAULT 'link'");
    }
} catch (Exception $e) {
    die("Erro ao abrir/criar base de dados: " . $e->getMessage());
}

define('LINKS_UPLOAD_DIR', __DIR__ . '/../files/links/');
if (!is_dir(LINKS_UPLOAD_DIR)) mkdir(LINKS_UPLOAD_DIR, 0755, true);

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

// Upload de ficheiro como "link"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['link_file'])) {
    $f = $_FILES['link_file'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $safe = preg_replace('/[^a-z0-9._-]/i', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $name = date('Ymd_His') . '_' . $safe . ($ext ? ".$ext" : '');
        $dest = LINKS_UPLOAD_DIR . $name;
        if (move_uploaded_file($f['tmp_name'], $dest)) {
            $titulo    = trim($_POST['titulo'] ?? '') ?: $f['name'];
            $categoria = trim($_POST['categoria'] ?? '');
            $ordem     = $db->query("SELECT COUNT(*) FROM links")->fetchColumn();
            $stmt = $db->prepare("INSERT INTO links (url, titulo, categoria, ordem, tipo) VALUES (?,?,?,?,?)");
            $stmt->execute(['files/links/' . $name, $titulo, $categoria, $ordem, 'ficheiro']);
        }
    }
    header('Location: index.php?tab=links');
    exit;
}

// Remoção
if (isset($_POST['apagar'])) {
    $row = $db->prepare("SELECT url, tipo FROM links WHERE id=?");
    $row->execute([(int)$_POST['apagar']]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['tipo'] === 'ficheiro') {
        $fp = __DIR__ . '/../' . $r['url'];
        if (file_exists($fp)) unlink($fp);
    }
    $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
    $stmt->execute([':id' => (int)$_POST['apagar']]);
    header('Location: index.php?tab=links');
    exit;
}

$filtro = $_GET['filtro'] ?? '';
$stmt = $db->prepare("SELECT * FROM links WHERE categoria LIKE :filtro ORDER BY ordem ASC, criado_em DESC");
$stmt->execute([':filtro' => "%$filtro%"]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── PESQUISA GLOBAL ──────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$search_results = [];

// Parse: palavras normais = AND; -palavra = excluir
$gsTerms = $gsExcludes = [];
foreach (preg_split('/\s+/', $q) as $w) {
    if ($w === '') continue;
    if ($w[0] === '-' && strlen($w) > 1) $gsExcludes[] = substr($w, 1);
    else                                   $gsTerms[]    = $w;
}

/**
 * Constrói cláusulas WHERE para múltiplos termos (AND) com exclusões.
 * $cols: array de colunas (ex: ['titulo','descritivo'])
 * Devolve ['sql_fragment', 'params_array']
 * — cada termo precisa aparecer em ALGUMA das colunas (OR dentro do termo, AND entre termos)
 * — cada exclusão não pode aparecer em NENHUMA coluna
 */
function gsWhere(array $cols, array $terms, array $excludes): array {
    $clauses = [];
    $params  = [];
    foreach ($terms as $t) {
        $parts = array_map(fn($c) => "$c LIKE ?", $cols);
        $clauses[] = '(' . implode(' OR ', $parts) . ')';
        foreach ($cols as $_) $params[] = "%$t%";
    }
    foreach ($excludes as $t) {
        $parts = array_map(fn($c) => "$c NOT LIKE ?", $cols);
        $clauses[] = '(' . implode(' AND ', $parts) . ')';
        foreach ($cols as $_) $params[] = "%$t%";
    }
    return [implode(' AND ', $clauses) ?: '1', $params];
}

// Mesmo mas para SQLite (usa named params de forma manual)
function gsWhereSQLite(array $cols, array $terms, array $excludes): array {
    $clauses = [];
    $params  = [];
    $i = 0;
    foreach ($terms as $t) {
        $parts = [];
        foreach ($cols as $c) { $k = ":gs$i"; $parts[] = "$c LIKE $k"; $params[$k] = "%$t%"; $i++; }
        $clauses[] = '(' . implode(' OR ', $parts) . ')';
    }
    foreach ($excludes as $t) {
        $parts = [];
        foreach ($cols as $c) { $k = ":gs$i"; $parts[] = "$c NOT LIKE $k"; $params[$k] = "%$t%"; $i++; }
        $clauses[] = '(' . implode(' AND ', $parts) . ')';
    }
    return [implode(' AND ', $clauses) ?: '1', $params];
}

function hl(string $text, string $terms): string {
    $escaped = htmlspecialchars($text);
    if ($terms === '') return $escaped;
    $words = array_filter(preg_split('/\s+/', $terms), fn($w) => $w !== '' && $w[0] !== '-');
    foreach ($words as $w) {
        $escaped = preg_replace('/(' . preg_quote(htmlspecialchars($w), '/') . ')/iu', '<mark>$1</mark>', $escaped);
    }
    return $escaped;
}
function snippet(string $text, string $terms, int $radius = 80): string {
    $words = array_filter(preg_split('/\s+/', $terms), fn($w) => $w !== '' && $w[0] !== '-');
    $pos = false;
    foreach ($words as $w) { $pos = mb_stripos($text, $w); if ($pos !== false) break; }
    if ($pos === false) return mb_substr($text, 0, $radius * 2);
    $start = max(0, $pos - $radius);
    return ($start > 0 ? '…' : '') . mb_substr($text, $start, $radius * 2 + 20) . '…';
}

if ($q !== '' && (!empty($gsTerms) || !empty($gsExcludes))) {

    // 1. Links (SQLite)
    try {
        [$w, $p] = gsWhereSQLite(['titulo','url','categoria'], $gsTerms, $gsExcludes);
        $s = $db->prepare("SELECT id, url, titulo, categoria FROM links WHERE $w ORDER BY titulo LIMIT 15");
        $s->execute($p);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $search_results['links'][] = $r;
    } catch (Exception $e) {}

    // MySQL sources
    $cfg2 = __DIR__ . '/../config.php';
    if (file_exists($cfg2)) {
        try {
            if (!isset($pdo_files)) {
                require_once $cfg2;
                $pdo_s = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } else {
                $pdo_s = $pdo_files;
            }

            // 2. Tasks
            [$w, $p] = gsWhere(['t.titulo','t.descritivo'], $gsTerms, $gsExcludes);
            $s = $pdo_s->prepare("SELECT t.id, t.titulo, t.descritivo, t.estado,
                                         COALESCE(u.username,'') as autor_nome
                                  FROM todos t LEFT JOIN user_tokens u ON t.autor = u.user_id
                                  WHERE $w ORDER BY t.created_at DESC LIMIT 15");
            $s->execute($p);
            $search_results['tasks'] = $s->fetchAll(PDO::FETCH_ASSOC);

            // 3. User Stories
            [$w, $p] = gsWhere(['us.story_text'], $gsTerms, $gsExcludes);
            $s = $pdo_s->prepare("SELECT us.id, us.story_text, us.moscow_priority, us.story_type,
                                         us.status, p.id as proto_id, p.short_name, p.title as proto_title
                                  FROM user_stories us JOIN prototypes p ON us.prototype_id = p.id
                                  WHERE $w ORDER BY us.created_at DESC LIMIT 15");
            $s->execute($p);
            $search_results['stories'] = $s->fetchAll(PDO::FETCH_ASSOC);

            // 4. Protótipos
            [$w, $p] = gsWhere(['short_name','title','vision','product_description'], $gsTerms, $gsExcludes);
            $s = $pdo_s->prepare("SELECT id, short_name, title, vision, product_description
                                  FROM prototypes WHERE $w ORDER BY short_name LIMIT 10");
            $s->execute($p);
            $search_results['prototypes'] = $s->fetchAll(PDO::FETCH_ASSOC);

            // 5. Sprints
            [$w, $p] = gsWhere(['nome'], $gsTerms, $gsExcludes);
            $s = $pdo_s->prepare("SELECT id, nome, estado, data_inicio, data_fim
                                  FROM sprints WHERE $w ORDER BY data_inicio DESC LIMIT 10");
            $s->execute($p);
            $search_results['sprints'] = $s->fetchAll(PDO::FETCH_ASSOC);

            // 6. Ficheiros
            $tbl = $pdo_s->query("SHOW TABLES LIKE 'task_files'")->rowCount();
            if ($tbl) {
                $hasNotes = $pdo_s->query("SHOW COLUMNS FROM task_files LIKE 'notes'")->rowCount() > 0;
                $notesSel = $hasNotes ? "tf.notes" : "'' as notes";
                $fileCols = $hasNotes ? ['tf.file_name','tf.notes','t.titulo'] : ['tf.file_name','t.titulo'];
                [$w, $p] = gsWhere($fileCols, $gsTerms, $gsExcludes);
                $s = $pdo_s->prepare("SELECT tf.id as file_id, tf.file_name, tf.file_path,
                                             tf.uploaded_at, $notesSel,
                                             COALESCE(t.titulo,'') as task_title, tf.todo_id
                                      FROM task_files tf LEFT JOIN todos t ON tf.todo_id = t.id
                                      WHERE $w ORDER BY tf.uploaded_at DESC LIMIT 15");
                $s->execute($p);
                $search_results['files'] = $s->fetchAll(PDO::FETCH_ASSOC);
            }

            // 7. Projectos
            if ($pdo_s->query("SHOW TABLES LIKE 'projects'")->rowCount()) {
                [$w, $p] = gsWhere(['p.short_name','p.title','p.description'], $gsTerms, $gsExcludes);
                $s = $pdo_s->prepare("SELECT p.id, p.short_name, p.title, p.description, p.estado,
                                             p.data_inicio, p.data_fim, COALESCE(u.username,'') as owner_name
                                      FROM projects p LEFT JOIN user_tokens u ON p.owner_id = u.user_id
                                      WHERE $w ORDER BY p.created_at DESC LIMIT 10");
                $s->execute($p);
                $search_results['projects'] = $s->fetchAll(PDO::FETCH_ASSOC);
            }

            // 8. Leads
            if ($pdo_s->query("SHOW TABLES LIKE 'leads'")->rowCount()) {
                [$w, $p] = gsWhere(['l.titulo','l.descricao'], $gsTerms, $gsExcludes);
                $s = $pdo_s->prepare("SELECT l.id, l.titulo, l.descricao, l.estado, l.relevancia,
                                             COALESCE(u.username,'') as responsavel
                                      FROM leads l LEFT JOIN user_tokens u ON l.responsavel_id = u.user_id
                                      WHERE $w ORDER BY l.criado_em DESC LIMIT 10");
                $s->execute($p);
                $search_results['leads'] = $s->fetchAll(PDO::FETCH_ASSOC);
            }

            // 8. Research Ideas
            if ($pdo_s->query("SHOW TABLES LIKE 'research_ideas'")->rowCount()) {
                [$w, $p] = gsWhere(['ri.title','ri.description'], $gsTerms, $gsExcludes);
                $s = $pdo_s->prepare("SELECT ri.id, ri.title, ri.description, ri.status, ri.priority, ri.author
                                      FROM research_ideas ri
                                      WHERE $w ORDER BY ri.created_at DESC LIMIT 10");
                $s->execute($p);
                $search_results['research'] = $s->fetchAll(PDO::FETCH_ASSOC);
            }

        } catch (PDOException $e) { /* ignorar */ }
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.gs-bar { background: linear-gradient(135deg,#1e293b,#0f172a); border-radius: 12px; padding: 28px 24px 24px; margin-bottom: 28px; }
.gs-bar h2 { color: #f8fafc; font-size: 18px; font-weight: 700; margin: 0 0 14px; letter-spacing: -.3px; }
.gs-input-wrap { display: flex; gap: 8px; }
.gs-input { flex: 1; font-size: 16px; padding: 10px 16px; border-radius: 8px; border: 2px solid #334155; background: #0f172a; color: #f1f5f9; outline: none; transition: border-color .2s; }
.gs-input:focus { border-color: #f59e0b; }
.gs-btn { padding: 10px 20px; border-radius: 8px; background: #f59e0b; border: none; color: #111; font-weight: 700; cursor: pointer; font-size: 15px; white-space: nowrap; }
.gs-btn:hover { background: #d97706; }
.gs-clear { padding: 10px 14px; border-radius: 8px; background: #334155; border: none; color: #94a3b8; cursor: pointer; font-size: 14px; }
.gs-section { margin-bottom: 20px; }
.gs-section-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.gs-item { display: flex; align-items: flex-start; gap: 12px; padding: 10px 12px; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 6px; background: #fff; transition: border-color .15s, box-shadow .15s; }
.gs-item:hover { border-color: #93c5fd; box-shadow: 0 2px 8px rgba(59,130,246,.1); }
.gs-icon { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
.gs-body { flex: 1; min-width: 0; }
.gs-title { font-weight: 600; font-size: 14px; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gs-title a { color: inherit; text-decoration: none; }
.gs-title a:hover { color: #2563eb; text-decoration: underline; }
.gs-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
.gs-snippet { font-size: 13px; color: #374151; margin-top: 4px; line-height: 1.5; }
.gs-snippet mark { background: #fef08a; color: #111; border-radius: 2px; padding: 0 2px; }
.gs-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; }
.gs-empty { color: #9ca3af; font-size: 13px; font-style: italic; }
.gs-total { font-size: 13px; color: #6b7280; margin-top: 10px; }
.gs-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.gs-filter-chip { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 20px; border: 1.5px solid #d1d5db; background: #f9fafb; color: #374151; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; }
.gs-filter-chip:hover { border-color: #6366f1; color: #4338ca; }
.gs-filter-chip.active { border-color: #f59e0b; background: #fef3c7; color: #92400e; }
.gs-filter-count { background: #e5e7eb; color: #6b7280; border-radius: 10px; padding: 0 6px; font-size: 10px; font-weight: 700; }
.gs-filter-chip.active .gs-filter-count { background: #fde68a; color: #92400e; }
.gs-hint { color: #94a3b8; font-size: 11px; align-self: center; white-space: nowrap; }
.gs-hint code { background: #1e293b; color: #f59e0b; border-radius: 3px; padding: 1px 4px; font-size: 11px; }
</style>

<div class="container mt-4">

<!-- ── PESQUISA GLOBAL ── -->
<div class="gs-bar">
    <h2>🔍 Pesquisa Global</h2>
    <form method="get" class="gs-input-wrap" autocomplete="off">
        <input type="hidden" name="tab" value="links">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
               class="gs-input" placeholder="rp2040 navibox · -excluir · vários termos = AND"
               autofocus>
        <small class="gs-hint">espaço = AND &nbsp;·&nbsp; <code>-palavra</code> = excluir</small>
        <button type="submit" class="gs-btn">Pesquisar</button>
        <?php if ($q !== ''): ?>
        <a href="?tab=links" class="gs-clear">✕</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($q !== ''): ?>
<?php
$total = array_sum(array_map('count', $search_results));
$sections = [
    'tasks'      => ['✅', 'Tasks'],
    'stories'    => ['📋', 'User Stories / Bugs / Features'],
    'prototypes' => ['🔬', 'Protótipos'],
    'sprints'    => ['🏃', 'Sprints'],
    'files'      => ['📎', 'Ficheiros'],
    'projects'   => ['📁', 'Projetos'],
    'leads'      => ['🎯', 'Leads'],
    'research'   => ['💡', 'Research Ideas'],
    'links'      => ['🔗', 'Links'],
];
$activeTypes = array_keys(array_filter($search_results, fn($r) => !empty($r)));
?>

<!-- filtros rápidos -->
<?php if ($total > 0 && count($activeTypes) > 1): ?>
<div class="gs-filters" id="gs-filters">
    <button class="gs-filter-chip active" data-type="all">Tudo <span class="gs-filter-count" id="gs-count-all"><?= $total ?></span></button>
    <?php foreach ($sections as $key => [$icon, $label]): ?>
    <?php if (!empty($search_results[$key])): ?>
    <button class="gs-filter-chip" data-type="<?= $key ?>"><?= $icon ?> <?= $label ?> <span class="gs-filter-count"><?= count($search_results[$key]) ?></span></button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="gs-total mb-3" id="gs-total-line">
    <span id="gs-visible-count"><?= $total ?></span> resultado(s) para "<strong><?= htmlspecialchars($q) ?></strong>"
    <?php if ($total === 0): ?><span class="gs-empty">— tente outros termos</span><?php endif; ?>
</div>

<?php foreach ($sections as $key => [$icon, $label]): ?>
<?php if (!empty($search_results[$key])): ?>
<div class="gs-section" data-gs-type="<?= $key ?>">
    <div class="gs-section-title"><?= $icon ?> <?= $label ?> <span class="gs-badge" style="background:#e0e7ff;color:#3730a3;"><?= count($search_results[$key]) ?></span></div>

    <?php foreach ($search_results[$key] as $r): ?>
    <div class="gs-item">
        <span class="gs-icon"><?= $icon ?></span>
        <div class="gs-body">

        <?php if ($key === 'links'): ?>
            <div class="gs-title"><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank"><?= hl($r['titulo'] ?: $r['url'], $q) ?></a></div>
            <div class="gs-meta"><?= hl($r['categoria'], $q) ?> · <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" style="color:#6b7280;"><?= hl(parse_url($r['url'], PHP_URL_HOST) ?: $r['url'], $q) ?></a></div>

        <?php elseif ($key === 'tasks'): ?>
            <div class="gs-title">
                <a href="#" onclick="event.preventDefault();openTaskEditor(<?= (int)$r['id'] ?>)"><?= hl($r['titulo'], $q) ?></a>
                <span class="gs-badge ms-1" style="background:#dbeafe;color:#1d4ed8;"><?= htmlspecialchars($r['estado']) ?></span>
            </div>
            <?php if ($r['autor_nome']): ?><div class="gs-meta">👤 <?= htmlspecialchars($r['autor_nome']) ?></div><?php endif; ?>
            <?php if ($r['descritivo']): ?><div class="gs-snippet"><?= hl(snippet($r['descritivo'], $q), $q) ?></div><?php endif; ?>

        <?php elseif ($key === 'stories'): ?>
            <?php $stColors = ['Bug'=>'#fee2e2;color:#991b1b','Feature'=>'#d1fae5;color:#065f46','Story'=>'#e5e7eb;color:#374151']; $st=$r['story_type']??'Story'; ?>
            <div class="gs-title">
                <a href="?tab=prototypes/prototypesv2&prototype_id=<?= $r['proto_id'] ?>"><?= htmlspecialchars($r['short_name']) ?></a>
                <span class="gs-badge ms-1" style="background:<?= $stColors[$st] ?? '#e5e7eb;color:#374151' ?>;"><?= $st ?></span>
                <span class="gs-badge ms-1" style="background:#e0e7ff;color:#3730a3;"><?= $r['moscow_priority'] ?></span>
            </div>
            <div class="gs-snippet"><?= hl(snippet($r['story_text'], $q), $q) ?></div>

        <?php elseif ($key === 'prototypes'): ?>
            <div class="gs-title"><a href="?tab=prototypes/prototypesv2&prototype_id=<?= $r['id'] ?>"><?= hl($r['short_name'], $q) ?> — <?= hl($r['title'], $q) ?></a></div>
            <?php $v = $r['vision'] ?: $r['product_description']; if ($v): ?>
            <div class="gs-snippet"><?= hl(snippet($v, $q), $q) ?></div>
            <?php endif; ?>

        <?php elseif ($key === 'sprints'): ?>
            <div class="gs-title"><a href="?tab=sprints"><?= hl($r['nome'], $q) ?></a></div>
            <div class="gs-meta">
                Estado: <strong><?= htmlspecialchars($r['estado']) ?></strong>
                <?php if ($r['data_inicio']): ?> · <?= date('d/m/Y', strtotime($r['data_inicio'])) ?> → <?= $r['data_fim'] ? date('d/m/Y', strtotime($r['data_fim'])) : '?' ?><?php endif; ?>
            </div>

        <?php elseif ($key === 'projects'): ?>
            <?php $estColor = ($r['estado'] ?? 'aberto') === 'aberto' ? '#d1fae5;color:#065f46' : '#e5e7eb;color:#374151'; ?>
            <div class="gs-title">
                <a href="?tab=projectos&project_id=<?= $r['id'] ?>"><?= hl($r['short_name'], $q) ?> — <?= hl($r['title'], $q) ?></a>
                <span class="gs-badge ms-1" style="background:<?= $estColor ?>;"><?= $r['estado'] ?? 'aberto' ?></span>
            </div>
            <div class="gs-meta">
                <?php if ($r['owner_name']): ?>👤 <?= htmlspecialchars($r['owner_name']) ?><?php endif; ?>
                <?php if ($r['data_inicio']): ?> · <?= date('d/m/Y', strtotime($r['data_inicio'])) ?> → <?= $r['data_fim'] ? date('d/m/Y', strtotime($r['data_fim'])) : '?' ?><?php endif; ?>
            </div>
            <?php if ($r['description']): ?><div class="gs-snippet"><?= hl(snippet($r['description'], $q), $q) ?></div><?php endif; ?>

        <?php elseif ($key === 'leads'): ?>
            <?php $estColor = $r['estado'] === 'aberta' ? '#d1fae5;color:#065f46' : '#e5e7eb;color:#374151'; ?>
            <div class="gs-title">
                <a href="?tab=leads"><?= hl($r['titulo'], $q) ?></a>
                <span class="gs-badge ms-1" style="background:<?= $estColor ?>;"><?= $r['estado'] ?></span>
                <span class="gs-badge ms-1" style="background:#fef3c7;color:#92400e;">⭐ <?= $r['relevancia'] ?>/10</span>
            </div>
            <?php if ($r['responsavel']): ?><div class="gs-meta">👤 <?= htmlspecialchars($r['responsavel']) ?></div><?php endif; ?>
            <?php if ($r['descricao']): ?><div class="gs-snippet"><?= hl(snippet($r['descricao'], $q), $q) ?></div><?php endif; ?>

        <?php elseif ($key === 'research'): ?>
            <?php
            $priColors = ['urgente'=>'#fee2e2;color:#991b1b','alta'=>'#fef3c7;color:#92400e','normal'=>'#e0e7ff;color:#3730a3','baixa'=>'#e5e7eb;color:#374151'];
            $stColors  = ['nova'=>'#dbeafe;color:#1d4ed8','em análise'=>'#fef3c7;color:#92400e','aprovada'=>'#d1fae5;color:#065f46','arquivada'=>'#e5e7eb;color:#374151'];
            $pri = $r['priority'] ?? 'normal'; $st = $r['status'] ?? 'nova';
            ?>
            <div class="gs-title">
                <a href="?tab=research_ideas"><?= hl($r['title'], $q) ?></a>
                <span class="gs-badge ms-1" style="background:<?= $priColors[$pri] ?? '#e5e7eb;color:#374151' ?>;"><?= $pri ?></span>
                <span class="gs-badge ms-1" style="background:<?= $stColors[$st]  ?? '#e5e7eb;color:#374151' ?>;"><?= $st ?></span>
            </div>
            <div class="gs-meta">👤 <?= htmlspecialchars($r['author']) ?></div>
            <?php if ($r['description']): ?><div class="gs-snippet"><?= hl(snippet($r['description'], $q), $q) ?></div><?php endif; ?>

        <?php elseif ($key === 'files'): ?>
            <?php $ext = strtolower(pathinfo($r['file_name'], PATHINFO_EXTENSION));
                  $ficon = in_array($ext,['jpg','jpeg','png','gif','webp']) ? '🖼️' : (in_array($ext,['pdf']) ? '📕' : (in_array($ext,['doc','docx']) ? '📘' : (in_array($ext,['xls','xlsx','csv']) ? '📗' : '📄'))); ?>
            <div class="gs-title"><?= $ficon ?> <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank"><?= hl($r['file_name'], $q) ?></a></div>
            <div class="gs-meta">
                <?php if ($r['task_title'] && $r['todo_id']): ?>
                    📌 <a href="#" onclick="event.preventDefault();openTaskEditor(<?= (int)$r['todo_id'] ?>)" style="color:#6b7280;"><?= hl($r['task_title'], $q) ?></a> ·
                <?php endif; ?>
                <?= date('d/m/Y', strtotime($r['uploaded_at'])) ?>
            </div>
            <?php if ($r['notes']): ?><div class="gs-snippet"><?= hl(snippet($r['notes'], $q), $q) ?></div><?php endif; ?>
        <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
(function() {
    const filters = document.querySelectorAll('#gs-filters .gs-filter-chip');
    if (!filters.length) return;
    const sections = document.querySelectorAll('.gs-section[data-gs-type]');
    const countEl  = document.getElementById('gs-visible-count');
    const totalAll = <?= $total ?>;
    const countsByType = <?= json_encode(array_map('count', array_filter($search_results))) ?>;

    filters.forEach(btn => {
        btn.addEventListener('click', () => {
            filters.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const type = btn.dataset.type;
            let visible = 0;
            sections.forEach(sec => {
                const show = type === 'all' || sec.dataset.gsType === type;
                sec.style.display = show ? '' : 'none';
                if (show) visible += countsByType[sec.dataset.gsType] || 0;
            });
            if (countEl) countEl.textContent = type === 'all' ? totalAll : visible;
        });
    });
})();
</script>

<hr class="my-4">
<?php endif; ?>

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

    <button class="btn btn-outline-secondary mb-3" type="button" id="btnToggleAdd"
            onclick="document.getElementById('formAdicionar').classList.toggle('d-none');this.textContent=this.textContent==='➕ Adicionar'?'✕ Fechar':'➕ Adicionar'">➕ Adicionar</button>

<div id="formAdicionar" class="card mb-4 d-none" style="border:1px solid #dee2e6;">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs" id="addTabs">
            <li class="nav-item">
                <button class="nav-link active" id="tabLinkBtn" onclick="switchAddTab('link')">🔗 Link URL</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="tabFileBtn" onclick="switchAddTab('file')">📎 Ficheiro</button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <!-- tab link -->
        <form method="post" id="panelLink" class="row g-2">
            <div class="col-md-5">
                <input type="url" name="url" class="form-control" placeholder="https://…" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="titulo" class="form-control" placeholder="Título (opcional)">
            </div>
            <div class="col-md-2">
                <input type="text" name="categoria" class="form-control" placeholder="Categoria">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Guardar</button>
            </div>
        </form>
        <!-- tab ficheiro -->
        <form method="post" enctype="multipart/form-data" id="panelFile" class="row g-2 d-none">
            <div class="col-md-5">
                <input type="file" name="link_file" class="form-control" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="titulo" class="form-control" placeholder="Título (opcional)">
            </div>
            <div class="col-md-2">
                <input type="text" name="categoria" class="form-control" placeholder="Categoria">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100">Upload</button>
            </div>
        </form>
    </div>
</div>
<script>
function switchAddTab(tab) {
    document.getElementById('panelLink').classList.toggle('d-none', tab !== 'link');
    document.getElementById('panelFile').classList.toggle('d-none', tab !== 'file');
    document.getElementById('tabLinkBtn').classList.toggle('active', tab === 'link');
    document.getElementById('tabFileBtn').classList.toggle('active', tab === 'file');
}
</script>

    <ul class="list-group" id="sortable">
        <?php foreach ($links as $link):
            $isFicheiro = ($link['tipo'] ?? 'link') === 'ficheiro';
            $ext = $isFicheiro ? strtolower(pathinfo($link['url'], PATHINFO_EXTENSION)) : '';
            $ficon = $isFicheiro ? (in_array($ext,['jpg','jpeg','png','gif','webp']) ? '🖼️' : (in_array($ext,['pdf']) ? '📕' : (in_array($ext,['doc','docx']) ? '📘' : (in_array($ext,['xls','xlsx','csv']) ? '📗' : '📎')))) : (str_starts_with($link['url'],'https') ? '🔒' : '🔗');
        ?>
            <li class="list-group-item d-flex justify-content-between align-items-start" data-id="<?= $link['id'] ?>" style="cursor: grab;">
                <div class="me-auto">
                    <div class="fw-bold fs-5" id="titulo-<?= $link['id'] ?>" contenteditable="true"
                         onclick="event.stopPropagation()">
                        <?= htmlspecialchars($link['titulo'] ?: ($isFicheiro ? basename($link['url']) : $link['url'])) ?>
                    </div>
                    <div class="text-muted small">
                        <?= $ficon ?>
                        <?php if ($isFicheiro): ?>
                            <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank">
                                <?= htmlspecialchars(basename($link['url'])) ?>
                            </a>
                            <span class="badge bg-secondary ms-1" style="font-size:10px;">ficheiro</span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank"
                               title="<?= htmlspecialchars($link['url']) ?>">
                                <?= htmlspecialchars(parse_url($link['url'], PHP_URL_HOST) ?: $link['url']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">
                        Categoria: <span id="categoria-<?= $link['id'] ?>" contenteditable="true"
                                         onclick="event.stopPropagation()">
                            <?= htmlspecialchars($link['categoria']) ?>
                        </span> | <?= $link['criado_em'] ?>
                    </small>
                </div>
                <div class="d-flex gap-1 align-items-center">
                    <?php if ($isFicheiro): ?>
                    <a href="<?= htmlspecialchars($link['url']) ?>" download
                       class="btn btn-sm btn-outline-success" title="Download">⬇️</a>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary edit-btn" data-id="<?= $link['id'] ?>">✏️</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Apagar?');">
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

$files = [];
$file_error = null;
$search_term = '';

// Tentar conectar à base de dados MySQL dos ficheiros
$config_path = __DIR__ . '/../config.php';

if (file_exists($config_path)) {
    try {
        require_once $config_path;
        
        $pdo_files = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Processar adição de notas aos ficheiros (AJAX)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_file_note') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $file_id = (int)$_POST['file_id'];
            $note = trim($_POST['note']);
            
            // Verificar/criar coluna notes
            try {
                $check_col = $pdo_files->query("SHOW COLUMNS FROM task_files LIKE 'notes'");
                if ($check_col->rowCount() == 0) {
                    $pdo_files->exec("ALTER TABLE task_files ADD COLUMN notes TEXT AFTER file_size");
                }
            } catch (PDOException $e) {
                // Ignorar erro
            }
            
            $stmt = $pdo_files->prepare("UPDATE task_files SET notes = ? WHERE id = ?");
            $stmt->execute([$note, $file_id]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // Verificar se tabela task_files existe
        $tables_check = $pdo_files->query("SHOW TABLES LIKE 'task_files'");
        
        if ($tables_check->rowCount() > 0) {
            // Verificar se coluna notes existe
            $columns_check = $pdo_files->query("SHOW COLUMNS FROM task_files LIKE 'notes'");
            $has_notes = ($columns_check->rowCount() > 0);
            
            // Pesquisar ficheiros
            $search_term = $_GET['search_files'] ?? '';
            
            // A tabela user_tokens tem as colunas: id, user_id, username, token, created_at
            $file_query = "
                SELECT 
                    tf.id as file_id,
                    tf.file_name,
                    tf.file_path,
                    tf.file_size,
                    tf.uploaded_at,
                    " . ($has_notes ? "tf.notes" : "'' as notes") . ",
                    tf.todo_id,
                    tf.uploaded_by,
                    t.titulo as task_title,
                    t.estado as task_status,
                    COALESCE(u.username, CONCAT('User #', tf.uploaded_by)) as uploaded_by_name
                FROM task_files tf
                LEFT JOIN todos t ON tf.todo_id = t.id
                LEFT JOIN user_tokens u ON tf.uploaded_by = u.user_id
                WHERE 1=1
            ";
            
            if ($search_term) {
                $file_query .= " AND (tf.file_name LIKE :search ";
                if ($has_notes) {
                    $file_query .= " OR tf.notes LIKE :search ";
                }
                $file_query .= " OR t.titulo LIKE :search)";
            }
            
            $file_query .= " ORDER BY tf.uploaded_at DESC LIMIT 500";
            
            $stmt = $pdo_files->prepare($file_query);
            if ($search_term) {
                $stmt->execute([':search' => "%$search_term%"]);
            } else {
                $stmt->execute();
            }
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            $file_error = "A tabela 'task_files' ainda não existe. Execute primeiro o instalador do editor de tarefas.";
        }
        
    } catch (PDOException $e) {
        $file_error = "Erro ao conectar à BD MySQL: " . htmlspecialchars($e->getMessage());
    } catch (Exception $e) {
        $file_error = "Erro: " . htmlspecialchars($e->getMessage());
    }
} else {
    $file_error = "Ficheiro config.php não encontrado em: " . htmlspecialchars($config_path);
}

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

    <?php if ($file_error): ?>
        <div class="alert alert-danger">
            <h5>⚠️ Erro ao carregar ficheiros</h5>
            <p><?= $file_error ?></p>
            <hr>
            <p class="mb-0"><strong>Para resolver:</strong></p>
            <ol class="mb-0">
                <li>Certifique-se que o ficheiro <code>config.php</code> existe no diretório correto</li>
                <li>Execute o instalador do editor de tarefas para criar a tabela <code>task_files</code></li>
                <li>Execute o script SQL: <code>add_file_notes_column.sql</code></li>
            </ol>
        </div>
    <?php else: ?>

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
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'])) $icon = '🖼️';
                        elseif (in_array($ext, ['pdf'])) $icon = '📕';
                        elseif (in_array($ext, ['doc', 'docx'])) $icon = '📘';
                        elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) $icon = '📗';
                        elseif (in_array($ext, ['ppt', 'pptx'])) $icon = '📙';
                        elseif (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) $icon = '🗜️';
                        elseif (in_array($ext, ['mp4', 'avi', 'mov', 'wmv', 'mkv'])) $icon = '🎬';
                        elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'flac'])) $icon = '🎵';
                        elseif (in_array($ext, ['txt', 'md', 'log'])) $icon = '📝';
                        echo $icon;
                        ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($file['file_name']) ?></strong><br>
                        <small class="text-muted">.<?= htmlspecialchars($ext) ?></small>
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
                                    onclick="saveFileNote(<?= $file['file_id'] ?>)"
                                    title="Guardar nota">
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
                           title="Ver/Download ficheiro">
                            👁️ Ver
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
    
    <?php endif; // Fim do else do $file_error ?>
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

.table-responsive {
    max-height: 800px;
    overflow-y: auto;
}

.table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
}
</style>

<?php include __DIR__ . '/../edit_task.php'; ?>