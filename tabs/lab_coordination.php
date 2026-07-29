<?php
// tabs/lab_coordination.php — Coordenação do Laboratório
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

// ── Tabela ────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS skills_profiles (
        user_id INT PRIMARY KEY,
        team_role VARCHAR(200) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Processar save inline via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lc_action'])) {
    header('Content-Type: application/json');
    $target_uid = (int)($_POST['user_id'] ?? 0);
    if ($target_uid !== $cur_uid && !$is_admin) {
        echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit;
    }
    if ($_POST['lc_action'] === 'set_role') {
        $role = trim($_POST['team_role'] ?? '');
        $pdo->prepare("INSERT INTO skills_profiles (user_id, team_role) VALUES (?,?)
            ON DUPLICATE KEY UPDATE team_role=VALUES(team_role), updated_at=NOW()")
            ->execute([$target_uid, $role]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida']); exit;
}

// ── Dados ──────────────────────────────────────────────────────────────────
$members = $pdo->query("
    SELECT u.user_id, u.username,
           COALESCE(p.team_role, '') AS team_role
    FROM user_tokens u
    LEFT JOIN skills_profiles p ON p.user_id = u.user_id
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

// Competências de destaque por pessoa (top 3 por nível)
$top_skills = [];
try {
    $rows = $pdo->query("
        SELECT m.user_id, c.name, m.level
        FROM skills_matrix m
        JOIN skills_competencies c ON c.id = m.competency_id
        ORDER BY m.user_id, FIELD(m.level,'L','C','S','A','I')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!isset($top_skills[$r['user_id']])) $top_skills[$r['user_id']] = [];
        if (count($top_skills[$r['user_id']]) < 4) {
            $top_skills[$r['user_id']][] = ['name'=>$r['name'],'level'=>$r['level']];
        }
    }
} catch (Exception $e) {}

$LC_URL = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/tabs/lab_coordination.php';
$LEVEL_COLOR = ['L'=>'#0d6efd','C'=>'#6610f2','S'=>'#198754','A'=>'#fd7e14','I'=>'#e6a800'];
$LEVEL_LABEL = ['L'=>'Líder','C'=>'Co-líder','S'=>'Skilled','A'=>'Aprendiz','I'=>'Interessado'];
?>

<style>
.lc-wrap{padding-bottom:40px}

/* Grelha de cards */
.lc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}

/* Card de pessoa */
.lc-card{background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:10px;transition:box-shadow .15s}
.lc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1)}
.lc-card-head{display:flex;align-items:center;gap:10px}
.lc-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0}
.lc-name{font-weight:700;font-size:13px}
.lc-role{font-size:11px;color:#6c757d;min-height:16px}

/* Competências */
.lc-skills{display:flex;flex-wrap:wrap;gap:4px}
.lc-skill-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;border:1px solid}
.lc-skill-L{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
.lc-skill-C{background:#ede9fe;border-color:#c4b5fd;color:#6d28d9}
.lc-skill-S{background:#dcfce7;border-color:#86efac;color:#166534}
.lc-skill-A{background:#ffedd5;border-color:#fdba74;color:#9a3412}
.lc-skill-I{background:#fef9c3;border-color:#fde047;color:#713f12}

/* Role editable */
.lc-role-edit{cursor:text;border-radius:4px;padding:1px 4px;transition:background .1s}
.lc-role-edit:hover{background:#fffbe6}
.lc-role-edit[data-editable]:empty::before{content:attr(placeholder);color:#adb5bd}
</style>

<div class="lc-wrap">

<div class="d-flex align-items-center gap-3 mb-4">
  <h5 class="mb-0 fw-bold">🧪 Lab Coordination</h5>
  <span class="text-muted" style="font-size:12px"><?= count($members) ?> membros · Clica na função para editar a tua</span>
</div>

<div class="lc-grid">
  <?php foreach ($members as $m):
    $initials = strtoupper(substr($m['username'], 0, 1));
    $can_edit = ($m['user_id'] == $cur_uid || $is_admin);
    $skills   = $top_skills[$m['user_id']] ?? [];
  ?>
  <div class="lc-card">
    <div class="lc-card-head">
      <div class="lc-avatar"><?= $initials ?></div>
      <div style="flex:1;min-width:0">
        <div class="lc-name"><?= htmlspecialchars($m['username']) ?></div>
        <div class="lc-role <?= $can_edit ? 'lc-role-edit' : '' ?>"
             <?= $can_edit ? 'data-editable data-uid="'.$m['user_id'].'"' : '' ?>
             <?= $can_edit ? 'title="Clica para editar"' : '' ?>
             placeholder="— sem função definida —">
          <?= $m['team_role'] ? htmlspecialchars($m['team_role']) : '' ?>
        </div>
      </div>
    </div>
    <?php if ($skills): ?>
    <div class="lc-skills">
      <?php foreach ($skills as $sk): ?>
      <span class="lc-skill-chip lc-skill-<?= $sk['level'] ?>"
            title="<?= htmlspecialchars($LEVEL_LABEL[$sk['level']]) ?>">
        <?= $sk['level'] ?> <?= htmlspecialchars($sk['name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted mb-0" style="font-size:11px">Sem competências definidas na matriz.</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

</div>

<script>
const LC_URL = '<?= htmlspecialchars(rtrim(dirname($_SERVER['PHP_SELF']),'/').'/skills_ajax.php') ?>';

// Edição inline da função/equipa
document.querySelectorAll('.lc-role-edit[data-editable]').forEach(el => {
    el.addEventListener('click', () => {
        if (el.querySelector('input')) return;
        const uid  = parseInt(el.dataset.uid);
        const prev = el.textContent.trim();

        const input = document.createElement('input');
        input.type  = 'text';
        input.value = prev;
        input.className = 'form-control form-control-sm';
        input.style.cssText = 'font-size:11px;padding:2px 6px;height:auto;min-width:140px';
        input.placeholder = 'Ex: Investigador, Engenheiro…';
        el.textContent = '';
        el.appendChild(input);
        input.focus(); input.select();

        const save = () => {
            const val = input.value.trim();
            const fd  = new FormData();
            fd.append('sk_action',  'set_role');
            fd.append('user_id',    uid);
            fd.append('team_role',  val);
            fetch(LC_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
                el.textContent = val || '';
            });
        };
        input.addEventListener('blur', save);
        input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter')  { ev.preventDefault(); input.blur(); }
            if (ev.key === 'Escape') { el.textContent = prev; }
        });
    });
});
</script>
