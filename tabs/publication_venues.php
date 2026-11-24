<?php
// tabs/publication_venues.php — Oportunidades de publicação (jornais, conferências,
// special issues, workshops) com deadlines, ranking e local. Submenu de "Research".

$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) include_once $configPath;
global $db_host, $db_user, $db_pass, $db_name;

try {
    $pvdb = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Erro de ligação à base de dados.</div>';
    return;
}

try {
    $pvdb->exec("CREATE TABLE IF NOT EXISTS publication_venues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('journal','conference','special_issue','workshop') NOT NULL DEFAULT 'conference',
        name VARCHAR(500) NOT NULL,
        acronym VARCHAR(50) NULL,
        publisher VARCHAR(200) NULL,
        url VARCHAR(2048) NULL,
        ranking VARCHAR(20) NULL,
        impact_factor DECIMAL(7,3) NULL,
        open_access TINYINT(1) NOT NULL DEFAULT 0,
        location VARCHAR(255) NULL,
        country VARCHAR(100) NULL,
        event_start DATE NULL,
        event_end DATE NULL,
        abstract_deadline DATE NULL,
        submission_deadline DATE NULL,
        notification_date DATE NULL,
        camera_ready_date DATE NULL,
        topics TEXT NULL,
        notes TEXT NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (type),
        INDEX idx_sub (submission_deadline)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('publication_venues create: ' . $e->getMessage());
}

$PV_TYPES = [
    'journal'       => ['Jornal',        '📰', 'primary'],
    'conference'    => ['Conferência',   '🎤', 'success'],
    'special_issue' => ['Special Issue', '📚', 'info'],
    'workshop'      => ['Workshop',      '🛠️', 'warning'],
];

function pv_date($v) {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function pv_str($v, $max) {
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pv_action'])) {
    $act = $_POST['pv_action'];
    $msg = '';
    try {
        if ($act === 'save') {
            $type = array_key_exists($_POST['type'] ?? '', $PV_TYPES) ? $_POST['type'] : 'conference';
            $name = pv_str($_POST['name'] ?? '', 500);
            if (!$name) throw new Exception('O nome é obrigatório.');
            $url = pv_str($_POST['url'] ?? '', 2048);
            if ($url && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
            $if = trim($_POST['impact_factor'] ?? '');
            $data = [
                ':type' => $type, ':name' => $name,
                ':acronym' => pv_str($_POST['acronym'] ?? '', 50),
                ':publisher' => pv_str($_POST['publisher'] ?? '', 200),
                ':url' => $url,
                ':ranking' => pv_str($_POST['ranking'] ?? '', 20),
                ':if' => is_numeric(str_replace(',', '.', $if)) ? (float)str_replace(',', '.', $if) : null,
                ':oa' => !empty($_POST['open_access']) ? 1 : 0,
                ':location' => pv_str($_POST['location'] ?? '', 255),
                ':country' => pv_str($_POST['country'] ?? '', 100),
                ':es' => pv_date($_POST['event_start'] ?? ''),
                ':ee' => pv_date($_POST['event_end'] ?? ''),
                ':ad' => pv_date($_POST['abstract_deadline'] ?? ''),
                ':sd' => pv_date($_POST['submission_deadline'] ?? ''),
                ':nd' => pv_date($_POST['notification_date'] ?? ''),
                ':cr' => pv_date($_POST['camera_ready_date'] ?? ''),
                ':topics' => pv_str($_POST['topics'] ?? '', 5000),
                ':notes' => pv_str($_POST['notes'] ?? '', 10000),
            ];
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $data[':id'] = $id;
                $pvdb->prepare("UPDATE publication_venues SET type=:type, name=:name, acronym=:acronym,
                    publisher=:publisher, url=:url, ranking=:ranking, impact_factor=:if, open_access=:oa,
                    location=:location, country=:country, event_start=:es, event_end=:ee,
                    abstract_deadline=:ad, submission_deadline=:sd, notification_date=:nd,
                    camera_ready_date=:cr, topics=:topics, notes=:notes WHERE id=:id")->execute($data);
                $msg = 'updated';
            } else {
                $data[':by'] = $_SESSION['username'] ?? 'unknown';
                $pvdb->prepare("INSERT INTO publication_venues (type, name, acronym, publisher, url, ranking,
                    impact_factor, open_access, location, country, event_start, event_end, abstract_deadline,
                    submission_deadline, notification_date, camera_ready_date, topics, notes, created_by)
                    VALUES (:type,:name,:acronym,:publisher,:url,:ranking,:if,:oa,:location,:country,:es,:ee,
                    :ad,:sd,:nd,:cr,:topics,:notes,:by)")->execute($data);
                $msg = 'created';
            }
        } elseif ($act === 'delete') {
            $pvdb->prepare("DELETE FROM publication_venues WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'deleted';
        }
    } catch (Exception $e) {
        $msg = 'err:' . $e->getMessage();
    }
    // PRG — mantém filtros da página
    $back = '?tab=publication_venues&msg=' . urlencode($msg);
    if (!empty($_POST['return_type'])) $back .= '&type=' . urlencode($_POST['return_type']);
    header('Location: ' . $back);
    exit;
}

// ---------- Dados ----------
$venues = [];
try {
    $venues = $pvdb->query("SELECT * FROM publication_venues ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    error_log('publication_venues list: ' . $e->getMessage());
}

$pvToday = date('Y-m-d');
$counts = ['all' => count($venues)] + array_fill_keys(array_keys($PV_TYPES), 0);
$upcoming = 0;
foreach ($venues as &$v) {
    $counts[$v['type']] = ($counts[$v['type']] ?? 0) + 1;
    // próxima deadline relevante (abstract ou submissão que ainda não passou)
    $next = null; $nextLabel = '';
    foreach (['abstract_deadline' => 'Abstract', 'submission_deadline' => 'Submissão'] as $f => $lbl) {
        if ($v[$f] && $v[$f] >= $pvToday && ($next === null || $v[$f] < $next)) { $next = $v[$f]; $nextLabel = $lbl; }
    }
    $v['_next'] = $next;
    $v['_next_label'] = $nextLabel;
    $v['_days'] = $next ? (int)round((strtotime($next) - strtotime($pvToday)) / 86400) : null;
    // "passado" = tinha deadline(s) e todas já passaram (sem datas = contínuo/desconhecido)
    $v["_past"] = !$next && ($v["abstract_deadline"] || $v["submission_deadline"]);
    if ($v['_days'] !== null && $v['_days'] <= 30) $upcoming++;
}
unset($v);

$msgGet = $_GET['msg'] ?? '';
$initType = array_key_exists($_GET['type'] ?? '', $PV_TYPES) ? $_GET['type'] : 'all';

function pv_fmt($d) {
    if (!$d) return '';
    $m = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    $t = strtotime($d);
    return date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
function pv_range($a, $b) {
    if (!$a) return '';
    if (!$b || $b === $a) return pv_fmt($a);
    $m = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    $ta = strtotime($a); $tb = strtotime($b);
    if (date('Y-m', $ta) === date('Y-m', $tb))
        return date('j', $ta) . '–' . date('j', $tb) . ' ' . $m[(int)date('n', $tb) - 1] . ' ' . date('Y', $tb);
    return pv_fmt($a) . ' – ' . pv_fmt($b);
}
function pv_rank_score($r) {
    $r = strtoupper(trim((string)$r));
    $map = ['A*' => 0, 'Q1' => 1, 'A' => 1, 'Q2' => 2, 'B' => 2, 'Q3' => 3, 'C' => 3, 'Q4' => 4];
    return $map[$r] ?? 9;
}
$pvh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.pv-wrap { max-width: 1400px; }
.pv-sub { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
.pv-sub a { text-decoration:none; padding:5px 12px; border-radius:999px; background:#eef1f5; color:#333; font-size:.9em; }
.pv-sub a.active { background:#0d6efd; color:#fff; }
.pv-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
.pv-toolbar .form-control, .pv-toolbar .form-select { width:auto; }
.pv-toolbar .pv-search { min-width:220px; flex:1 1 220px; max-width:360px; }
.pv-types .btn { font-size:.85em; padding:3px 10px; border-radius:999px; }
.pv-table { font-size:.92em; }
.pv-table td { vertical-align: middle; }
.pv-name { font-weight:600; }
.pv-name a { text-decoration:none; }
.pv-meta { color:#6c757d; font-size:.85em; }
.pv-dl { white-space:nowrap; }
.pv-days { display:inline-block; min-width:68px; text-align:center; border-radius:999px; padding:2px 8px; font-size:.8em; font-weight:600; }
.pv-days.urgent { background:#f8d7da; color:#842029; }
.pv-days.soon   { background:#fff3cd; color:#664d03; }
.pv-days.ok     { background:#d1e7dd; color:#0f5132; }
.pv-days.past   { background:#e9ecef; color:#6c757d; }
.pv-loc a { text-decoration:none; }
.pv-row-past { opacity:.6; }
.pv-rank { font-weight:700; }
.pv-topics { max-width:280px; }
.pv-empty { padding:40px; text-align:center; color:#6c757d; }
.pv-sortable { cursor:pointer; user-select:none; white-space:nowrap; }
.pv-sortable:hover { text-decoration:underline; }
.pv-sortable.sorted::after { content:' ▲'; font-size:.7em; }
.pv-sortable.sorted.desc::after { content:' ▼'; }
@media (max-width: 768px) {
  .pv-col-hide-sm { display:none; }
  .pv-toolbar .pv-search { max-width:none; }
}
</style>

<div class="pv-wrap">
  <div class="pv-sub">
    <a href="?tab=research_ideas">💡 Research Ideas</a>
    <a href="?tab=publication_venues" class="active">📅 Onde Publicar</a>
  </div>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div>
      <h4 class="mb-0">Onde Publicar</h4>
      <div class="pv-meta">Jornais, conferências, special issues e workshops, com as deadlines.
        <?php if ($upcoming): ?><strong class="text-danger ms-1"><?= $upcoming ?> deadline<?= $upcoming > 1 ? 's' : '' ?> nos próximos 30 dias</strong><?php endif; ?>
      </div>
    </div>
    <button class="btn btn-primary" onclick="pvOpen()"><i class="bi bi-plus-lg"></i> Adicionar</button>
  </div>

  <?php if ($msgGet): ?>
    <?php if (str_starts_with($msgGet, 'err:')): ?>
      <div class="alert alert-danger py-2"><?= $pvh(substr($msgGet, 4)) ?></div>
    <?php else: ?>
      <div class="alert alert-success py-2 pv-flash"><?= ['created' => 'Oportunidade adicionada.', 'updated' => 'Oportunidade atualizada.', 'deleted' => 'Oportunidade apagada.'][$msgGet] ?? '' ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="pv-toolbar">
    <div class="d-flex flex-wrap gap-1 pv-types" role="group">
      <button type="button" class="btn btn-outline-secondary" data-type="all">Todos <span class="badge bg-secondary"><?= $counts['all'] ?></span></button>
      <?php foreach ($PV_TYPES as $k => [$lbl, $ic, $col]): ?>
        <button type="button" class="btn btn-outline-secondary" data-type="<?= $k ?>"><?= $ic ?> <?= $lbl ?> <span class="badge bg-<?= $col ?>"><?= $counts[$k] ?? 0 ?></span></button>
      <?php endforeach; ?>
    </div>
    <input type="search" class="form-control form-control-sm pv-search" id="pvSearch" placeholder="Pesquisar nome, tópico, local…">
    <select class="form-select form-select-sm" id="pvSort" title="Ordenar">
      <option value="deadline">Ordenar: próxima deadline</option>
      <option value="name">Ordenar: nome</option>
      <option value="ranking">Ordenar: ranking</option>
      <option value="if">Ordenar: impact factor</option>
      <option value="event">Ordenar: data do evento</option>
      <option value="location">Ordenar: local</option>
      <option value="recent">Ordenar: adicionados recentemente</option>
    </select>
    <div class="form-check form-switch ms-1">
      <input class="form-check-input" type="checkbox" id="pvHidePast" checked>
      <label class="form-check-label small" for="pvHidePast">Esconder deadlines passadas</label>
    </div>
  </div>

  <?php if (!$venues): ?>
    <div class="pv-empty border rounded">
      Ainda não há oportunidades registadas.<br>
      <button class="btn btn-outline-primary btn-sm mt-2" onclick="pvOpen()">Adicionar a primeira</button>
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover pv-table" id="pvTable">
      <thead class="table-light">
        <tr>
          <th class="pv-sortable" data-sort="name">Nome</th>
          <th class="pv-sortable" data-sort="ranking">Ranking</th>
          <th class="pv-sortable" data-sort="deadline">Próxima deadline</th>
          <th class="pv-col-hide-sm">Notificação</th>
          <th class="pv-sortable" data-sort="event">Evento</th>
          <th class="pv-sortable" data-sort="location">Local</th>
          <th class="pv-col-hide-sm">Tópicos</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($venues as $v):
          [$tLbl, $tIc, $tCol] = $PV_TYPES[$v['type']] ?? ['?', '', 'secondary'];
          $d = $v['_days'];
          $cls = $d === null ? 'past' : ($d <= 7 ? 'urgent' : ($d <= 30 ? 'soon' : 'ok'));
          $hasPhysical = in_array($v['type'], ['conference', 'workshop']);
          $locFull = trim(implode(', ', array_filter([$v['location'], $v['country']])));
          $json = $v; unset($json["_next"], $json["_next_label"], $json["_days"], $json["_past"]);
      ?>
        <tr class="<?= $v["_past"] ? "pv-row-past" : "" ?>"
            data-type="<?= $pvh($v['type']) ?>"
            data-past="<?= $v["_past"] ? 1 : 0 ?>"
            data-name="<?= $pvh(mb_strtolower(($v['acronym'] ? $v['acronym'] . ' ' : '') . $v['name'])) ?>"
            data-rank="<?= pv_rank_score($v['ranking']) ?>"
            data-if="<?= $v['impact_factor'] !== null ? (float)$v['impact_factor'] : -1 ?>"
            data-deadline="<?= $pvh($v['_next'] ?? '') ?>"
            data-event="<?= $pvh($v['event_start'] ?? '') ?>"
            data-location="<?= $pvh(mb_strtolower($locFull)) ?>"
            data-created="<?= $pvh($v['created_at']) ?>"
            data-search="<?= $pvh(mb_strtolower(implode(' ', [$v['name'], $v['acronym'], $v['publisher'], $v['topics'], $v['location'], $v['country'], $v['ranking'], $v['notes']]))) ?>">
          <td>
            <div class="pv-name">
              <span class="badge bg-<?= $tCol ?> me-1" title="<?= $tLbl ?>"><?= $tIc ?> <?= $tLbl ?></span>
              <?php if ($v['url']): ?><a href="<?= $pvh($v['url']) ?>" target="_blank" rel="noopener"><?php endif; ?>
              <?= $v['acronym'] ? '<span class="text-primary">' . $pvh($v['acronym']) . '</span> — ' : '' ?><?= $pvh($v['name']) ?>
              <?php if ($v['url']): ?> <i class="bi bi-box-arrow-up-right small"></i></a><?php endif; ?>
              <?php if ($v['open_access']): ?><span class="badge bg-light text-success border ms-1" title="Open Access">OA</span><?php endif; ?>
            </div>
            <?php if ($v['publisher'] || $v['notes']): ?>
              <div class="pv-meta"><?= $pvh($v['publisher']) ?><?= $v['publisher'] && $v['notes'] ? ' · ' : '' ?><?= $pvh(mb_strimwidth((string)$v['notes'], 0, 120, '…')) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($v['ranking']): ?><span class="pv-rank"><?= $pvh($v['ranking']) ?></span><?php endif; ?>
            <?php if ($v['impact_factor'] !== null): ?><div class="pv-meta">IF <?= rtrim(rtrim(number_format((float)$v['impact_factor'], 3, '.', ''), '0'), '.') ?></div><?php endif; ?>
          </td>
          <td class="pv-dl">
            <?php if ($v['_next']): ?>
              <div><?= pv_fmt($v['_next']) ?> <span class="pv-meta">(<?= $v['_next_label'] ?>)</span></div>
              <span class="pv-days <?= $cls ?>"><?= $d === 0 ? 'hoje' : ($d === 1 ? 'amanhã' : $d . ' dias') ?></span>
              <?php if ($v['_next_label'] === 'Abstract' && $v['submission_deadline']): ?>
                <div class="pv-meta">Paper: <?= pv_fmt($v['submission_deadline']) ?></div>
              <?php endif; ?>
            <?php elseif ($v['submission_deadline']): ?>
              <div class="pv-meta"><?= pv_fmt($v['submission_deadline']) ?></div>
              <span class="pv-days past">passou</span>
            <?php elseif ($v['type'] === 'journal'): ?>
              <span class="pv-meta">submissão contínua</span>
            <?php else: ?>
              <span class="pv-meta">—</span>
            <?php endif; ?>
          </td>
          <td class="pv-col-hide-sm pv-dl">
            <?= $v['notification_date'] ? pv_fmt($v['notification_date']) : '' ?>
            <?php if ($v['camera_ready_date']): ?><div class="pv-meta">Camera-ready: <?= pv_fmt($v['camera_ready_date']) ?></div><?php endif; ?>
          </td>
          <td class="pv-dl"><?= $pvh(pv_range($v['event_start'], $v['event_end'])) ?></td>
          <td class="pv-loc">
            <?php if ($locFull): ?>
              <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($locFull) ?>" target="_blank" rel="noopener" title="Ver no mapa">📍 <?= $pvh($locFull) ?></a>
            <?php elseif ($hasPhysical): ?><span class="pv-meta">—</span><?php endif; ?>
          </td>
          <td class="pv-col-hide-sm pv-topics"><span class="pv-meta"><?= $pvh(mb_strimwidth((string)$v['topics'], 0, 140, '…')) ?></span></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-secondary" title="Editar" onclick='pvOpen(<?= $pvh(json_encode($json, JSON_UNESCAPED_UNICODE)) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline" onsubmit="return confirm('Apagar esta oportunidade?')">
              <input type="hidden" name="pv_action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <input type="hidden" name="return_type" class="pv-return-type">
              <button class="btn btn-sm btn-outline-danger" title="Apagar"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="pv-empty d-none" id="pvNone">Nenhuma oportunidade corresponde aos filtros.</div>
  </div>
  <?php endif; ?>
</div>

<!-- Modal adicionar/editar -->
<div class="modal fade" id="pvModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" class="modal-content" id="pvForm">
      <input type="hidden" name="pv_action" value="save">
      <input type="hidden" name="id" id="pv_id">
      <input type="hidden" name="return_type" class="pv-return-type">
      <div class="modal-header">
        <h5 class="modal-title" id="pvModalTitle">Nova oportunidade</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-sm-4">
            <label class="form-label small mb-0">Tipo</label>
            <select class="form-select" name="type" id="pv_type">
              <?php foreach ($PV_TYPES as $k => [$lbl, $ic]): ?><option value="<?= $k ?>"><?= $ic ?> <?= $lbl ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label small mb-0">Sigla</label>
            <input class="form-control" name="acronym" id="pv_acronym" maxlength="50" placeholder="ex: ICRA">
          </div>
          <div class="col-sm-5">
            <label class="form-label small mb-0">Publisher</label>
            <input class="form-control" name="publisher" id="pv_publisher" maxlength="200" list="pvPublishers" placeholder="IEEE, Springer, Elsevier…">
            <datalist id="pvPublishers"><option>IEEE</option><option>Springer</option><option>Elsevier</option><option>MDPI</option><option>ACM</option><option>Wiley</option><option>Frontiers</option><option>IFAC</option></datalist>
          </div>
          <div class="col-12">
            <label class="form-label small mb-0">Nome *</label>
            <input class="form-control" name="name" id="pv_name" required maxlength="500">
          </div>
          <div class="col-sm-8">
            <label class="form-label small mb-0">URL / Call for papers</label>
            <input class="form-control" name="url" id="pv_url" maxlength="2048" placeholder="https://…">
          </div>
          <div class="col-sm-2">
            <label class="form-label small mb-0">Ranking</label>
            <input class="form-control" name="ranking" id="pv_ranking" maxlength="20" list="pvRanks" placeholder="Q1 / A">
            <datalist id="pvRanks"><option>Q1</option><option>Q2</option><option>Q3</option><option>Q4</option><option>A*</option><option>A</option><option>B</option><option>C</option></datalist>
          </div>
          <div class="col-sm-2">
            <label class="form-label small mb-0">Impact factor</label>
            <input class="form-control" name="impact_factor" id="pv_impact_factor" inputmode="decimal">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="open_access" value="1" id="pv_open_access">
              <label class="form-check-label small" for="pv_open_access">Open Access</label>
            </div>
          </div>

          <div class="col-12 mt-3"><h6 class="mb-0">Deadlines</h6></div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-0">Abstract</label>
            <input type="date" class="form-control" name="abstract_deadline" id="pv_abstract_deadline">
          </div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-0">Submissão</label>
            <input type="date" class="form-control" name="submission_deadline" id="pv_submission_deadline">
          </div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-0">Notificação</label>
            <input type="date" class="form-control" name="notification_date" id="pv_notification_date">
          </div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-0">Camera-ready</label>
            <input type="date" class="form-control" name="camera_ready_date" id="pv_camera_ready_date">
          </div>

          <div class="col-12 mt-3 pv-event-fields"><h6 class="mb-0">Evento</h6></div>
          <div class="col-sm-5 pv-event-fields">
            <label class="form-label small mb-0">Local (cidade)</label>
            <input class="form-control" name="location" id="pv_location" maxlength="255" placeholder="ex: Atlanta, GA">
          </div>
          <div class="col-sm-3 pv-event-fields">
            <label class="form-label small mb-0">País</label>
            <input class="form-control" name="country" id="pv_country" maxlength="100">
          </div>
          <div class="col-6 col-sm-2 pv-event-fields">
            <label class="form-label small mb-0">Início</label>
            <input type="date" class="form-control" name="event_start" id="pv_event_start">
          </div>
          <div class="col-6 col-sm-2 pv-event-fields">
            <label class="form-label small mb-0">Fim</label>
            <input type="date" class="form-control" name="event_end" id="pv_event_end">
          </div>

          <div class="col-12 mt-3">
            <label class="form-label small mb-0">Tópicos</label>
            <input class="form-control" name="topics" id="pv_topics" placeholder="robotics, agriculture, computer vision…">
          </div>
          <div class="col-12">
            <label class="form-label small mb-0">Notas</label>
            <textarea class="form-control" name="notes" id="pv_notes" rows="3" placeholder="ex: special issue com o colega X, APC 1500€…"></textarea>
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

<script>
(function () {
  const FIELDS = ['id','type','acronym','publisher','name','url','ranking','impact_factor','abstract_deadline',
    'submission_deadline','notification_date','camera_ready_date','location','country','event_start','event_end','topics','notes'];
  const LS = 'pv_prefs';
  let prefs = {};
  try { prefs = JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) {}
  let state = {
    type: <?= json_encode($initType) ?> !== 'all' ? <?= json_encode($initType) ?> : (prefs.type || 'all'),
    sort: prefs.sort || 'deadline',
    desc: !!prefs.desc,
    hidePast: prefs.hidePast !== undefined ? prefs.hidePast : true,
    q: ''
  };
  const save = () => { try { localStorage.setItem(LS, JSON.stringify({type: state.type, sort: state.sort, desc: state.desc, hidePast: state.hidePast})); } catch (e) {} };

  const tbody = document.querySelector('#pvTable tbody');
  const rows = tbody ? Array.from(tbody.rows) : [];
  const sortSel = document.getElementById('pvSort');
  const hideChk = document.getElementById('pvHidePast');
  const search = document.getElementById('pvSearch');

  function cmp(a, b) {
    const s = state.sort, A = a.dataset, B = b.dataset;
    const str = (x, y) => x.localeCompare(y, 'pt');
    const emptyLast = (x, y) => (x === '') - (y === '') || str(x, y);
    let r = 0;
    switch (s) {
      case 'name': r = str(A.name, B.name); break;
      case 'ranking': r = (+A.rank - +B.rank) || (+B.if - +A.if); break;
      case 'if': r = +B.if - +A.if; break;
      case 'deadline': r = emptyLast(A.deadline, B.deadline); break;
      case 'event': r = emptyLast(A.event, B.event); break;
      case 'location': r = emptyLast(A.location, B.location); break;
      case 'recent': r = str(B.created, A.created); break;
    }
    if (state.desc) r = -r;
    return r || str(A.name, B.name);
  }

  function apply() {
    document.querySelectorAll('.pv-types .btn').forEach(b => {
      const on = b.dataset.type === state.type;
      b.classList.toggle('active', on);
      b.classList.toggle('btn-secondary', on);
      b.classList.toggle('btn-outline-secondary', !on);
    });
    document.querySelectorAll('.pv-return-type').forEach(i => i.value = state.type === 'all' ? '' : state.type);
    if (sortSel) sortSel.value = state.sort;
    if (hideChk) hideChk.checked = state.hidePast;
    document.querySelectorAll('.pv-sortable').forEach(th => {
      th.classList.toggle('sorted', th.dataset.sort === state.sort);
      th.classList.toggle('desc', th.dataset.sort === state.sort && state.desc);
    });
    if (!tbody) return;
    const q = state.q.trim().toLowerCase();
    let shown = 0;
    rows.sort(cmp).forEach(tr => {
      const ok = (state.type === 'all' || tr.dataset.type === state.type)
        && (!state.hidePast || tr.dataset.past === "0")
        && (!q || tr.dataset.search.includes(q));
      tr.style.display = ok ? '' : 'none';
      if (ok) shown++;
      tbody.appendChild(tr);
    });
    document.getElementById('pvNone')?.classList.toggle('d-none', shown > 0);
  }

  document.querySelectorAll('.pv-types .btn').forEach(b => b.addEventListener('click', () => { state.type = b.dataset.type; save(); apply(); }));
  sortSel?.addEventListener('change', () => { state.sort = sortSel.value; state.desc = false; save(); apply(); });
  hideChk?.addEventListener('change', () => { state.hidePast = hideChk.checked; save(); apply(); });
  search?.addEventListener('input', () => { state.q = search.value; apply(); });
  document.querySelectorAll('.pv-sortable').forEach(th => th.addEventListener('click', () => {
    if (state.sort === th.dataset.sort) state.desc = !state.desc; else { state.sort = th.dataset.sort; state.desc = false; }
    save(); apply();
  }));

  // Modal
  const typeSel = document.getElementById('pv_type');
  function toggleEventFields() {
    const show = typeSel.value === 'conference' || typeSel.value === 'workshop';
    document.querySelectorAll('.pv-event-fields').forEach(el => el.style.display = show ? '' : 'none');
  }
  typeSel.addEventListener('change', toggleEventFields);

  window.pvOpen = function (v) {
    v = v || {};
    FIELDS.forEach(f => { const el = document.getElementById('pv_' + f); if (el) el.value = v[f] ?? ''; });
    if (!v.id) typeSel.value = state.type !== 'all' ? state.type : 'conference';
    document.getElementById('pv_open_access').checked = v.open_access == 1;
    document.getElementById('pvModalTitle').textContent = v.id ? 'Editar oportunidade' : 'Nova oportunidade';
    toggleEventFields();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('pvModal')).show();
    setTimeout(() => document.getElementById('pv_name').focus(), 300);
  };

  // tira ?msg= do URL para não repetir o aviso num refresh
  if (location.search.includes('msg=')) {
    const u = new URL(location.href); u.searchParams.delete('msg'); history.replaceState(null, '', u);
    setTimeout(() => document.querySelector('.pv-flash')?.remove(), 4000);
  }
  apply();
})();
</script>
