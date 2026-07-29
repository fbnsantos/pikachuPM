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

    CREATE TABLE IF NOT EXISTS skills_profiles (
        user_id INT PRIMARY KEY,
        team_role VARCHAR(200) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
        ['Systems',     'Embedded',                14],
        ['Systems',     'Sensors',                 15],
        ['Systems',     'Advanced Mechatronics',   16],
        ['Management',  'Proposals Writing',       20],
        ['Management',  'Project Management',      21],
        ['Management',  'Prototype Management',    22],
        ['Management',  'Communication & Demos',   23],
    ];
    $s = $pdo->prepare("INSERT INTO skills_competencies (category, name, sort_order) VALUES (?,?,?)");
    foreach ($seeds as $seed) $s->execute($seed);
}

// ── Dados ──────────────────────────────────────────────────────────────────
$competencies = $pdo->query("
    SELECT id, category, name FROM skills_competencies WHERE active=1 ORDER BY sort_order
")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("
    SELECT u.user_id, u.username, COALESCE(p.team_role,'') AS team_role
    FROM user_tokens u
    LEFT JOIN skills_profiles p ON p.user_id = u.user_id
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

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
$LEVEL_COLOR = ['L'=>'#0d6efd','C'=>'#6610f2','S'=>'#198754','A'=>'#fd7e14','I'=>'#e6a800'];
?>

<style>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.sk-wrap { padding-bottom: 40px }

/* ── Table scroll container ─────────────────────────────────────────────── */
.sk-table-wrap {
    overflow-x: auto;
    overflow-y: auto;
    max-height: calc(100vh - 200px);
    border: 1px solid #dee2e6;
    border-radius: 10px;
    position: relative;
}

/* ── Table base ─────────────────────────────────────────────────────────── */
.sk-table {
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12px;
    min-width: max-content;
    width: 100%;
}
.sk-table th, .sk-table td {
    border-right: 1px solid #dee2e6;
    border-bottom: 1px solid #dee2e6;
    padding: 0;
}
.sk-table th:first-child, .sk-table td:first-child { border-left: none; }
.sk-table tr:last-child td { border-bottom: none; }

/* ── Sticky header rows ─────────────────────────────────────────────────── */
.sk-table thead th {
    position: sticky;
    background: #fff;
    z-index: 10;
}
.sk-table thead tr:first-child th { top: 0; }
.sk-table thead tr:nth-child(2) th { top: 38px; } /* height of first header row */

/* ── Sticky first two columns ───────────────────────────────────────────── */
.sk-col-person, .sk-col-role {
    position: sticky;
    background: #fff;
    z-index: 11;
}
.sk-col-person { left: 0; min-width: 130px; max-width: 160px; z-index: 12; }
.sk-col-role   { left: 130px; min-width: 140px; max-width: 180px; z-index: 12; }

/* Ensure sticky header corners are above everything */
thead .sk-col-person, thead .sk-col-role { z-index: 30; }

/* Shadow to visually separate sticky columns */
.sk-col-role { box-shadow: 3px 0 6px -2px rgba(0,0,0,.08); }

/* ── Category headers ───────────────────────────────────────────────────── */
.sk-cat-header {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    padding: 6px 10px;
    height: 38px;
}
.sk-cat-Technical  { background: #eff3ff; color: #3451b2; border-bottom: 2px solid #aab8f0 !important; }
.sk-cat-Systems    { background: #ecfdf3; color: #186535; border-bottom: 2px solid #86efac !important; }
.sk-cat-Management { background: #fff7ed; color: #9a3412; border-bottom: 2px solid #fdba74 !important; }

/* ── Competency column headers (vertical text) ──────────────────────────── */
.sk-comp-header {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    height: 130px;
    vertical-align: bottom;
    text-align: left;
    padding: 8px 6px 6px;
    font-size: 11px;
    font-weight: 600;
    color: #495057;
    white-space: nowrap;
    background: #f8f9fa;
}

/* Category boundary */
.sk-cat-first { border-left: 2px solid #adb5bd !important; }

/* Person + role header */
.sk-header-left {
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #6c757d;
    background: #f8f9fa;
    vertical-align: bottom;
}

/* ── Body rows ──────────────────────────────────────────────────────────── */
.sk-table tbody tr:hover td { background: #f8faff !important; }
.sk-table tbody tr:hover .sk-col-person,
.sk-table tbody tr:hover .sk-col-role { background: #eef2ff !important; }

/* Name cell */
.sk-col-person.sk-body {
    padding: 7px 12px;
    font-weight: 600;
    font-size: 12px;
    white-space: nowrap;
}

/* Role cell */
.sk-col-role.sk-body {
    padding: 6px 10px;
    font-size: 11px;
    color: #555;
    white-space: nowrap;
}
.sk-editable-role { cursor: text; }
.sk-editable-role:hover { background: #fffbe6 !important; }

/* ── Competency cells ───────────────────────────────────────────────────── */
.sk-cell {
    text-align: center;
    padding: 5px 4px;
    min-width: 36px;
    vertical-align: middle;
}
.sk-cell.sk-editable { cursor: pointer; }
.sk-cell.sk-editable:hover { background: #f0f7ff !important; }

/* ── Level badges ───────────────────────────────────────────────────────── */
.sk-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    line-height: 1;
}
.sk-L { background: #0d6efd }
.sk-C { background: #6610f2 }
.sk-S { background: #198754 }
.sk-A { background: #fd7e14 }
.sk-I { background: #e6a800; color: #333 }
.sk-empty { color: #ced4da; font-size: 16px; line-height: 1 }

/* ── Level picker popover ───────────────────────────────────────────────── */
#sk-picker {
    position: fixed;
    z-index: 2000;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    padding: 8px 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.15);
    min-width: 220px;
}
.sk-pick-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px }
.sk-pick-btn {
    width: 30px; height: 30px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .1s, box-shadow .1s;
}
.sk-pick-btn:hover { transform: scale(1.15); box-shadow: 0 2px 8px rgba(0,0,0,.2) }
.sk-pick-btn.sk-pick-active { border-color: #333; box-shadow: 0 0 0 3px rgba(0,0,0,.15) }
.sk-pick-btn-L { background: #0d6efd }
.sk-pick-btn-C { background: #6610f2 }
.sk-pick-btn-S { background: #198754 }
.sk-pick-btn-A { background: #fd7e14 }
.sk-pick-btn-I { background: #e6a800; color: #333 }
.sk-pick-btn-clear { background: #e9ecef; color: #495057; font-size: 14px }
.sk-pick-labels { display: flex; gap: 6px; font-size: 9px; color: #6c757d; text-align: center; padding: 0 1px }
.sk-pick-labels span { width: 30px; overflow: hidden; white-space: nowrap }
</style>

<div class="sk-wrap">

<!-- ── Header & legenda ── -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h5 class="mb-0 fw-bold">📊 Competências da Equipa</h5>
  <div class="d-flex gap-2 flex-wrap align-items-center" style="font-size:12px">
    <?php foreach ($LEVEL_LABEL as $k => $lbl): ?>
    <span class="d-inline-flex align-items-center gap-1">
      <span class="sk-badge sk-<?= $k ?>"><?= $k ?></span>
      <span class="text-muted"><?= $lbl ?></span>
    </span>
    <?php endforeach; ?>
    <span class="text-muted ms-1">· Clica numa célula para definir o teu nível</span>
    <?php if ($is_admin): ?>
    <span class="badge bg-warning text-dark ms-1">Admin: podes editar todos</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Tabela ── -->
<div class="sk-table-wrap">
<table class="sk-table">
  <thead>
    <!-- Linha 1: nome fixo + categorias -->
    <tr>
      <th rowspan="2" class="sk-col-person sk-header-left" style="vertical-align:bottom">Pessoa</th>
      <th rowspan="2" class="sk-col-role sk-header-left" style="vertical-align:bottom">Equipa / Função</th>
      <?php foreach ($cats as $cat => $comps): ?>
      <th colspan="<?= count($comps) ?>" class="sk-cat-header sk-cat-<?= $cat ?>">
        <?= htmlspecialchars($cat) ?>
      </th>
      <?php endforeach; ?>
    </tr>
    <!-- Linha 2: nomes das competências (vertical) -->
    <tr>
      <?php foreach ($competencies as $c): ?>
      <th class="sk-comp-header <?= isset($catFirstId[$c['id']]) ? 'sk-cat-first' : '' ?>">
        <?= htmlspecialchars($c['name']) ?>
      </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $u):
        $can_edit = ($u['user_id'] == $cur_uid || $is_admin);
    ?>
    <tr>
      <td class="sk-col-person sk-body"><?= htmlspecialchars($u['username']) ?></td>
      <td class="sk-col-role sk-body <?= $can_edit ? 'sk-editable-role' : '' ?>"
          data-uid="<?= $u['user_id'] ?>"
          data-role="<?= htmlspecialchars($u['team_role']) ?>"
          title="<?= $can_edit ? 'Clica para editar função/equipa' : '' ?>">
        <?= $u['team_role']
            ? htmlspecialchars($u['team_role'])
            : '<span class="text-muted" style="font-size:10px">—</span>' ?>
      </td>
      <?php foreach ($competencies as $c):
          $level = $lvl_idx[$u['user_id']][$c['id']] ?? '';
      ?>
      <td class="sk-cell <?= $can_edit ? 'sk-editable' : '' ?> <?= isset($catFirstId[$c['id']]) ? 'sk-cat-first' : '' ?>"
          data-uid="<?= $u['user_id'] ?>"
          data-comp="<?= $c['id'] ?>"
          data-level="<?= htmlspecialchars($level) ?>"
          title="<?= htmlspecialchars($c['name']) ?><?= $level ? ' — '.htmlspecialchars($LEVEL_LABEL[$level]) : '' ?>">
        <?= $level
            ? '<span class="sk-badge sk-'.$level.'">'.$level.'</span>'
            : '<span class="sk-empty">·</span>' ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

</div>

<!-- ── Level picker ── -->
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

<script>
const SK_URL = '<?= htmlspecialchars($SK_URL) ?>';

// ── Level picker ─────────────────────────────────────────────────────────────
let skTarget = null;

document.addEventListener('click', e => {
    // Clique num botão do picker
    const pickBtn = e.target.closest('.sk-pick-btn');
    if (pickBtn) {
        e.stopPropagation();
        if (!skTarget) return;
        skSetLevel(skTarget.uid, skTarget.comp, pickBtn.dataset.level, skTarget.cell);
        skClosePicker();
        return;
    }
    // Clique numa célula editável
    const cell = e.target.closest('.sk-cell.sk-editable');
    if (cell) {
        e.stopPropagation();
        if (skTarget?.cell === cell) { skClosePicker(); return; }
        skOpenPicker(cell);
        return;
    }
    // Clique fora → fechar
    if (!e.target.closest('#sk-picker')) skClosePicker();
});

function skOpenPicker(cell) {
    skTarget = {
        uid:  parseInt(cell.dataset.uid),
        comp: parseInt(cell.dataset.comp),
        cell
    };
    // Destacar nível atual
    const cur = cell.dataset.level;
    document.querySelectorAll('.sk-pick-btn').forEach(b =>
        b.classList.toggle('sk-pick-active', b.dataset.level === cur)
    );
    const picker = document.getElementById('sk-picker');
    picker.style.display = 'block';

    const rect = cell.getBoundingClientRect();
    let top  = rect.bottom + 6;
    let left = rect.left;
    if (left + 230 > window.innerWidth - 8) left = window.innerWidth - 234;
    if (top + 80  > window.innerHeight)      top  = rect.top - 84;
    picker.style.top  = top  + 'px';
    picker.style.left = left + 'px';
}

function skClosePicker() {
    document.getElementById('sk-picker').style.display = 'none';
    skTarget = null;
}

function skSetLevel(uid, compId, level, cell) {
    const fd = new FormData();
    fd.append('sk_action',      'set_level');
    fd.append('user_id',        uid);
    fd.append('competency_id',  compId);
    fd.append('level',          level);
    fetch(SK_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (!d.ok) { alert(d.msg); return; }
        cell.dataset.level = level;
        cell.innerHTML = level
            ? `<span class="sk-badge sk-${level}">${level}</span>`
            : '<span class="sk-empty">·</span>';
    });
}

// ── Edição inline da função/equipa ───────────────────────────────────────────
document.querySelectorAll('.sk-editable-role').forEach(cell => {
    cell.addEventListener('click', () => {
        if (cell.querySelector('input')) return;
        const uid  = parseInt(cell.dataset.uid);
        const prev = cell.dataset.role || '';

        const input = document.createElement('input');
        input.type  = 'text';
        input.value = prev;
        input.className = 'form-control form-control-sm';
        input.style.cssText = 'min-width:120px;font-size:11px;padding:2px 6px;height:auto';
        input.placeholder = 'Ex: Engenheiro, Investigador…';
        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus(); input.select();

        const save = () => {
            const val = input.value.trim();
            const fd  = new FormData();
            fd.append('sk_action',  'set_role');
            fd.append('user_id',    uid);
            fd.append('team_role',  val);
            fetch(SK_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
                cell.dataset.role = val;
                cell.innerHTML = val
                    ? val
                    : '<span class="text-muted" style="font-size:10px">—</span>';
            });
        };

        input.addEventListener('blur', save);
        input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter')  { ev.preventDefault(); input.blur(); }
            if (ev.key === 'Escape') {
                cell.innerHTML = prev
                    ? prev
                    : '<span class="text-muted" style="font-size:10px">—</span>';
            }
        });
    });
});
</script>
