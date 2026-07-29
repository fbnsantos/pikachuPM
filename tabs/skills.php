<?php
// tabs/skills.php — Matriz de Competências da Equipa
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('<div class="alert alert-danger">Erro de conexão BD</div>');
}

$cur_uid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id=?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

// ── Tabelas ────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS skills_competencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL,
        name VARCHAR(150) NOT NULL,
        sort_order INT DEFAULT 0,
        active TINYINT(1) DEFAULT 1,
        INDEX idx_cat_sort (category, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS skills_matrix (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        competency_id INT NOT NULL,
        level ENUM('L','C','S','A','I') NOT NULL,
        updated_by INT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_comp (user_id, competency_id),
        INDEX idx_comp (competency_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS skills_excluded (
        user_id INT PRIMARY KEY,
        excluded_by INT NOT NULL,
        excluded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Seed de competências ───────────────────────────────────────────────────
if (!(int)$pdo->query("SELECT COUNT(*) FROM skills_competencies")->fetchColumn()) {
    $seeds = [
        ['Technical',   'Electronics',              1],
        ['Technical',   'Software',                 2],
        ['Technical',   'Mechanical',               3],
        ['Technical',   'Agronomics',               4],
        ['Systems',     'Safety & Mission',        10],
        ['Systems',     'Navigation',              11],
        ['Systems',     'Perception & AI',         12],
        ['Systems',     'Manipulation',            13],
        ['Systems',     'Embedded & Sensors',      14],
        ['Systems',     'Advanced Mechatronics',   16],
        ['Management',  'Proposals Writing',       20],
        ['Management',  'Project Management',      21],
        ['Management',  'Prototype Management',    22],
        ['Management',  'Communication & Demos',   23],
        ['Management',  'Lab Management',          24],
    ];
    $s = $pdo->prepare("INSERT INTO skills_competencies (category, name, sort_order) VALUES (?,?,?)");
    foreach ($seeds as $seed) $s->execute($seed);
}

// ── Migração: adicionar Lab Management se não existir ─────────────────────
if (!$pdo->query("SELECT id FROM skills_competencies WHERE name='Lab Management'")->fetch()) {
    $pdo->prepare("INSERT INTO skills_competencies (category, name, sort_order) VALUES ('Management','Lab Management',24)")->execute();
}

// ── Migração: fundir Embedded + Sensors → Embedded & Sensors ──────────────
$emb = $pdo->query("SELECT id FROM skills_competencies WHERE name='Embedded'")->fetch(PDO::FETCH_ASSOC);
$sen = $pdo->query("SELECT id FROM skills_competencies WHERE name='Sensors'")->fetch(PDO::FETCH_ASSOC);
if ($emb && $sen) {
    $emb_id = (int)$emb['id'];
    $sen_id = (int)$sen['id'];
    $rank   = ['L'=>1,'C'=>2,'S'=>3,'A'=>4,'I'=>5];

    // Renomear Embedded → Embedded & Sensors
    $pdo->prepare("UPDATE skills_competencies SET name='Embedded & Sensors' WHERE id=?")->execute([$emb_id]);

    // Para cada utilizador com nível em Sensors: manter o melhor nível entre os dois
    $s = $pdo->prepare("SELECT user_id, level, updated_by FROM skills_matrix WHERE competency_id=?");
    $s->execute([$sen_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cur = $pdo->prepare("SELECT level FROM skills_matrix WHERE user_id=? AND competency_id=?");
        $cur->execute([$row['user_id'], $emb_id]);
        $existing = $cur->fetchColumn();
        $best = (!$existing || ($rank[$row['level']] < $rank[$existing])) ? $row['level'] : $existing;
        $pdo->prepare("INSERT INTO skills_matrix (user_id, competency_id, level, updated_by)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)")
            ->execute([$row['user_id'], $emb_id, $best, $row['updated_by']]);
    }
    // Apagar Sensors
    $pdo->prepare("DELETE FROM skills_matrix WHERE competency_id=?")->execute([$sen_id]);
    $pdo->prepare("DELETE FROM skills_competencies WHERE id=?")->execute([$sen_id]);
}

// ── Dados ──────────────────────────────────────────────────────────────────
$competencies = $pdo->query("
    SELECT id, category, name FROM skills_competencies WHERE active=1 ORDER BY sort_order
")->fetchAll(PDO::FETCH_ASSOC);

// Utilizadores activos na matriz
$users = $pdo->query("
    SELECT u.user_id, u.username
    FROM user_tokens u
    WHERE u.user_id NOT IN (SELECT user_id FROM skills_excluded)
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

// Todos os utilizadores para o painel de gestão (admin)
$all_users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$excluded_ids = array_fill_keys(
    $pdo->query("SELECT user_id FROM skills_excluded")->fetchAll(PDO::FETCH_COLUMN),
    true
);

$lvl_idx = [];
foreach ($pdo->query("SELECT user_id, competency_id, level FROM skills_matrix")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $lvl_idx[$l['user_id']][$l['competency_id']] = $l['level'];
}

// Agrupar competências por categoria e marcar a primeira de cada
$cats = [];
foreach ($competencies as $c) $cats[$c['category']][] = $c;
$catFirstId = [];
foreach ($cats as $cat => $comps) $catFirstId[$comps[0]['id']] = true;

$SK_URL = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/skills_ajax.php';
$LEVEL_LABEL = ['L'=>'Líder','C'=>'Co-líder','S'=>'Skilled','A'=>'Aprendiz','I'=>'Interessado'];
?>

<style>
.sk-wrap{padding-bottom:40px}

/* scroll container */
.sk-table-wrap{overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 210px);border:1px solid #dee2e6;border-radius:10px;position:relative}

/* table */
.sk-table{border-collapse:separate;border-spacing:0;font-size:12px;min-width:max-content;width:100%}
.sk-table th,.sk-table td{border-right:1px solid #dee2e6;border-bottom:1px solid #dee2e6;padding:0}
.sk-table th:first-child,.sk-table td:first-child{border-left:none}
.sk-table tr:last-child td{border-bottom:none}

/* sticky headers */
.sk-table thead th{position:sticky;background:#fff;z-index:10}
.sk-table thead tr:first-child th{top:0}
.sk-table thead tr:nth-child(2) th{top:38px}

/* sticky first column */
.sk-col-person{position:sticky;left:0;background:#fff;z-index:12;min-width:140px;max-width:170px}
thead .sk-col-person{z-index:30}
.sk-col-person{box-shadow:3px 0 6px -2px rgba(0,0,0,.08)}

/* category headers */
.sk-cat-header{text-align:center;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:6px 10px;height:38px}
.sk-cat-Technical {background:#eff3ff;color:#3451b2;border-bottom:2px solid #aab8f0!important}
.sk-cat-Systems   {background:#ecfdf3;color:#186535;border-bottom:2px solid #86efac!important}
.sk-cat-Management{background:#fff7ed;color:#9a3412;border-bottom:2px solid #fdba74!important}

/* competency headers (vertical) */
.sk-comp-header{writing-mode:vertical-rl;text-orientation:mixed;transform:rotate(180deg);height:130px;vertical-align:bottom;text-align:left;padding:8px 6px 6px;font-size:11px;font-weight:600;color:#495057;white-space:nowrap;background:#f8f9fa}

/* category boundary */
.sk-cat-first{border-left:2px solid #adb5bd!important}

/* person header */
.sk-header-left{padding:8px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;background:#f8f9fa;vertical-align:bottom}

/* body rows */
.sk-table tbody tr:hover td{background:#f8faff!important}
.sk-table tbody tr:hover .sk-col-person{background:#eef2ff!important}
.sk-col-person.sk-body{padding:7px 12px;font-weight:600;font-size:12px;white-space:nowrap}

/* cells */
.sk-cell{text-align:center;padding:5px 4px;min-width:38px;vertical-align:middle}
.sk-cell.sk-editable{cursor:pointer}
.sk-cell.sk-editable:hover{background:#f0f7ff!important}

/* level badges */
.sk-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-size:11px;font-weight:700;color:#fff;line-height:1}
.sk-L{background:#0d6efd}.sk-C{background:#6610f2}.sk-S{background:#198754}
.sk-A{background:#fd7e14}.sk-I{background:#e6a800;color:#333}
.sk-empty{color:#ced4da;font-size:16px;line-height:1}

/* picker */
#sk-picker{position:fixed;z-index:2000;background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:8px 10px;box-shadow:0 6px 24px rgba(0,0,0,.15);min-width:215px}
.sk-pick-row{display:flex;gap:6px;align-items:center;margin-bottom:6px}
.sk-pick-btn{width:30px;height:30px;border-radius:50%;border:2px solid transparent;cursor:pointer;font-size:12px;font-weight:700;color:#fff;display:inline-flex;align-items:center;justify-content:center;transition:transform .1s,box-shadow .1s}
.sk-pick-btn:hover{transform:scale(1.15);box-shadow:0 2px 8px rgba(0,0,0,.2)}
.sk-pick-btn.sk-pick-active{border-color:#333;box-shadow:0 0 0 3px rgba(0,0,0,.15)}
.sk-pick-btn-L{background:#0d6efd}.sk-pick-btn-C{background:#6610f2}.sk-pick-btn-S{background:#198754}
.sk-pick-btn-A{background:#fd7e14}.sk-pick-btn-I{background:#e6a800;color:#333}
.sk-pick-btn-clear{background:#e9ecef;color:#495057;font-size:14px}
.sk-pick-labels{display:flex;gap:6px;font-size:9px;color:#6c757d;text-align:center;padding:0 1px}
.sk-pick-labels span{width:30px;overflow:hidden;white-space:nowrap}

/* members modal */
.sk-members-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;display:flex;align-items:center;justify-content:center}
.sk-members-modal{background:#fff;border-radius:12px;width:min(440px,96vw);max-height:80vh;overflow-y:auto;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.3)}
</style>

<div class="sk-wrap">

<!-- Header -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h5 class="mb-0 fw-bold">📊 Competências da Equipa</h5>
  <div class="d-flex gap-2 flex-wrap align-items-center" style="font-size:12px">
    <?php foreach ($LEVEL_LABEL as $k => $lbl): ?>
    <span class="d-inline-flex align-items-center gap-1">
      <span class="sk-badge sk-<?= $k ?>"><?= $k ?></span>
      <span class="text-muted"><?= $lbl ?></span>
    </span>
    <?php endforeach; ?>
  </div>
  <?php if ($is_admin): ?>
  <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="document.getElementById('sk-members-overlay').style.display='flex'">
    👥 Gerir membros
  </button>
  <?php endif; ?>
</div>

<!-- Tabela -->
<div class="sk-table-wrap">
<table class="sk-table">
  <thead>
    <tr>
      <th rowspan="2" class="sk-col-person sk-header-left" style="vertical-align:bottom">Pessoa</th>
      <?php foreach ($cats as $cat => $comps): ?>
      <th colspan="<?= count($comps) ?>" class="sk-cat-header sk-cat-<?= $cat ?>"><?= htmlspecialchars($cat) ?></th>
      <?php endforeach; ?>
    </tr>
    <tr>
      <?php foreach ($competencies as $c): ?>
      <th class="sk-comp-header <?= isset($catFirstId[$c['id']]) ? 'sk-cat-first' : '' ?>"><?= htmlspecialchars($c['name']) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $u):
        $can_edit = ($u['user_id'] == $cur_uid || $is_admin);
    ?>
    <tr>
      <td class="sk-col-person sk-body"><?= htmlspecialchars($u['username']) ?></td>
      <?php foreach ($competencies as $c):
          $level = $lvl_idx[$u['user_id']][$c['id']] ?? '';
      ?>
      <td class="sk-cell <?= $can_edit ? 'sk-editable' : '' ?> <?= isset($catFirstId[$c['id']]) ? 'sk-cat-first' : '' ?>"
          data-uid="<?= $u['user_id'] ?>"
          data-comp="<?= $c['id'] ?>"
          data-level="<?= htmlspecialchars($level) ?>"
          title="<?= htmlspecialchars($c['name']) ?><?= $level ? ' — '.htmlspecialchars($LEVEL_LABEL[$level]) : '' ?>">
        <?= $level ? '<span class="sk-badge sk-'.$level.'">'.$level.'</span>' : '<span class="sk-empty">·</span>' ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
    <tr><td colspan="<?= count($competencies)+1 ?>" class="text-center text-muted py-4" style="font-size:13px">
      Nenhum membro activo. Clica em "Gerir membros" para adicionar.
    </td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

</div>

<!-- Level picker -->
<div id="sk-picker" style="display:none">
  <div class="sk-pick-row">
    <?php foreach ($LEVEL_LABEL as $k => $lbl): ?>
    <button class="sk-pick-btn sk-pick-btn-<?= $k ?>" data-level="<?= $k ?>" title="<?= $lbl ?>"><?= $k ?></button>
    <?php endforeach; ?>
    <button class="sk-pick-btn sk-pick-btn-clear" data-level="" title="Limpar">✕</button>
  </div>
  <div class="sk-pick-labels">
    <?php foreach ($LEVEL_LABEL as $k => $lbl): ?>
    <span><?= $lbl ?></span>
    <?php endforeach; ?>
    <span>Limpar</span>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- Modal de gestão de membros -->
<div id="sk-members-overlay" class="sk-members-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="sk-members-modal">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0 fw-bold">👥 Membros da Matriz</h6>
      <button class="btn-close" onclick="document.getElementById('sk-members-overlay').style.display='none'"></button>
    </div>
    <p class="text-muted mb-3" style="font-size:12px">
      Activa ou desactiva cada pessoa. Por defeito todos os utilizadores estão incluídos.
    </p>
    <?php foreach ($all_users as $u):
        $excluded = isset($excluded_ids[$u['user_id']]);
    ?>
    <div class="d-flex align-items-center gap-3 py-2 border-bottom">
      <div class="form-check form-switch mb-0 flex-grow-1">
        <input class="form-check-input" type="checkbox" id="sk-mem-<?= $u['user_id'] ?>"
               role="switch" <?= $excluded ? '' : 'checked' ?>
               onchange="skToggleMember(<?= $u['user_id'] ?>, this.checked)">
        <label class="form-check-label" for="sk-mem-<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></label>
      </div>
      <span class="badge <?= $excluded ? 'bg-secondary' : 'bg-success' ?>" style="min-width:60px;text-align:center">
        <?= $excluded ? 'Inactivo' : 'Activo' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
const SK_URL = '<?= htmlspecialchars($SK_URL) ?>';

// ── Level picker ─────────────────────────────────────────────────────────────
let skTarget = null;

document.addEventListener('click', e => {
    const pickBtn = e.target.closest('.sk-pick-btn');
    if (pickBtn) {
        e.stopPropagation();
        if (!skTarget) return;
        skSetLevel(skTarget.uid, skTarget.comp, pickBtn.dataset.level, skTarget.cell);
        skClosePicker();
        return;
    }
    const cell = e.target.closest('.sk-cell.sk-editable');
    if (cell) {
        e.stopPropagation();
        if (skTarget?.cell === cell) { skClosePicker(); return; }
        skOpenPicker(cell);
        return;
    }
    if (!e.target.closest('#sk-picker')) skClosePicker();
});

function skOpenPicker(cell) {
    skTarget = { uid: parseInt(cell.dataset.uid), comp: parseInt(cell.dataset.comp), cell };
    const cur = cell.dataset.level;
    document.querySelectorAll('.sk-pick-btn').forEach(b =>
        b.classList.toggle('sk-pick-active', b.dataset.level === cur)
    );
    const picker = document.getElementById('sk-picker');
    picker.style.display = 'block';
    const rect = cell.getBoundingClientRect();
    let top  = rect.bottom + 6;
    let left = rect.left;
    if (left + 220 > window.innerWidth - 8) left = window.innerWidth - 224;
    if (top  + 80  > window.innerHeight)    top  = rect.top - 84;
    picker.style.top  = top  + 'px';
    picker.style.left = left + 'px';
}

function skClosePicker() {
    document.getElementById('sk-picker').style.display = 'none';
    skTarget = null;
}

function skSetLevel(uid, compId, level, cell) {
    const fd = new FormData();
    fd.append('sk_action', 'set_level');
    fd.append('user_id', uid);
    fd.append('competency_id', compId);
    fd.append('level', level);
    fetch(SK_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (!d.ok) { alert(d.msg); return; }
        cell.dataset.level = level;
        cell.innerHTML = level
            ? `<span class="sk-badge sk-${level}">${level}</span>`
            : '<span class="sk-empty">·</span>';
    });
}

// ── Gestão de membros (admin) ─────────────────────────────────────────────────
function skToggleMember(uid, included) {
    const badge = document.querySelector(`#sk-mem-${uid}`)?.closest('.d-flex')?.querySelector('.badge');
    const fd = new FormData();
    fd.append('sk_action', 'toggle_member');
    fd.append('user_id', uid);
    fd.append('included', included ? '1' : '0');
    fetch(SK_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (!d.ok) { alert(d.msg); return; }
        if (badge) {
            badge.className = 'badge ' + (included ? 'bg-success' : 'bg-secondary');
            badge.textContent = included ? 'Activo' : 'Inactivo';
        }
        // Reload the matrix without closing the modal
        window.location.reload();
    });
}
</script>
