<?php
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
include_once __DIR__ . '/../config.php';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cur_uid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

// ── Tables ──────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS rh_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(255) NOT NULL,
    type ENUM('contratados','bolseiros') NOT NULL,
    months_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    UNIQUE KEY uq_plan_type (plan_name, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    rh_code VARCHAR(50) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    tipo_ligacao VARCHAR(150),
    sort_order INT DEFAULT 0,
    INDEX idx_camp (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES rh_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_person_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    project_code VARCHAR(100) NOT NULL DEFAULT '',
    project_name VARCHAR(255) NOT NULL DEFAULT '',
    pm_orc DECIMAL(8,2),
    pm_exe DECIMAL(8,2),
    sort_order INT DEFAULT 0,
    INDEX idx_per (person_id),
    FOREIGN KEY (person_id) REFERENCES rh_persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_monthly_alloc (
    person_project_id INT NOT NULL,
    year SMALLINT NOT NULL,
    month TINYINT NOT NULL,
    percentage DECIMAL(6,2),
    PRIMARY KEY (person_project_id, year, month),
    FOREIGN KEY (person_project_id) REFERENCES rh_person_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── AJAX / JSON handlers ─────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || ($action === 'get_campaign_data')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($action && $is_json) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {

        // ── Import full campaign from SheetJS-parsed JSON ─────────────────
        case 'import_rh': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $body = json_decode(file_get_contents('php://input'), true);
            $plan_name = trim($body['plan_name'] ?? '');
            $type      = $body['type'] ?? '';
            $months    = $body['months'] ?? [];
            $persons   = $body['persons'] ?? [];
            if (!$plan_name || !in_array($type, ['contratados','bolseiros'], true)) {
                echo json_encode(['error'=>'plan_name e type são obrigatórios']); exit;
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM rh_campaigns WHERE plan_name=? AND type=?")
                    ->execute([$plan_name, $type]);
                $pdo->prepare("INSERT INTO rh_campaigns (plan_name,type,months_json,created_by) VALUES (?,?,?,?)")
                    ->execute([$plan_name, $type, json_encode($months), $cur_uid]);
                $cid = (int)$pdo->lastInsertId();
                $sortP = 0;
                foreach ($persons as $p) {
                    $pdo->prepare("INSERT INTO rh_persons (campaign_id,rh_code,full_name,tipo_ligacao,sort_order) VALUES (?,?,?,?,?)")
                        ->execute([$cid, $p['rh_code'], $p['full_name'], $p['tipo_ligacao'] ?? null, $sortP++]);
                    $pid = (int)$pdo->lastInsertId();
                    $sortPP = 0;
                    foreach ($p['projects'] as $pp) {
                        $pdo->prepare("INSERT INTO rh_person_projects (person_id,project_code,project_name,pm_orc,pm_exe,sort_order) VALUES (?,?,?,?,?,?)")
                            ->execute([$pid, $pp['code'], $pp['name'], $pp['pm_orc'] ?? null, $pp['pm_exe'] ?? null, $sortPP++]);
                        $ppid = (int)$pdo->lastInsertId();
                        foreach ($pp['allocations'] as $ym => $pct) {
                            if ($pct === null || $pct === '') continue;
                            [$y, $m] = explode('-', $ym);
                            $pdo->prepare("INSERT INTO rh_monthly_alloc (person_project_id,year,month,percentage) VALUES (?,?,?,?)")
                                ->execute([$ppid, (int)$y, (int)$m, (float)$pct]);
                        }
                    }
                }
                $pdo->commit();
                echo json_encode(['ok'=>true,'campaign_id'=>$cid]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error'=>$e->getMessage()]);
            }
            exit;
        }

        // ── Fetch full campaign data (for grid render + export) ───────────
        case 'get_campaign_data': {
            $cid = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
            if (!$cid) { echo json_encode(['error'=>'campaign_id requerido']); exit; }
            $camp = $pdo->prepare("SELECT id,plan_name,type,months_json FROM rh_campaigns WHERE id=?");
            $camp->execute([$cid]);
            $campaign = $camp->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) { echo json_encode(['error'=>'Não encontrado']); exit; }
            $campaign['months'] = json_decode($campaign['months_json'], true);
            unset($campaign['months_json']);

            $ps = $pdo->prepare("SELECT * FROM rh_persons WHERE campaign_id=? ORDER BY sort_order,id");
            $ps->execute([$cid]);
            $persons = $ps->fetchAll(PDO::FETCH_ASSOC);
            foreach ($persons as &$person) {
                $pps = $pdo->prepare("SELECT * FROM rh_person_projects WHERE person_id=? ORDER BY sort_order,id");
                $pps->execute([$person['id']]);
                $person['projects'] = $pps->fetchAll(PDO::FETCH_ASSOC);
                foreach ($person['projects'] as &$pp) {
                    $al = $pdo->prepare("SELECT year,month,percentage FROM rh_monthly_alloc WHERE person_project_id=?");
                    $al->execute([$pp['id']]);
                    $pp['allocations'] = [];
                    foreach ($al->fetchAll(PDO::FETCH_ASSOC) as $a) {
                        $pp['allocations'][sprintf('%04d-%02d', $a['year'], $a['month'])] = (float)$a['percentage'];
                    }
                }
                unset($pp);
            }
            unset($person);
            $campaign['persons'] = $persons;
            echo json_encode($campaign);
            exit;
        }

        // ── Save one allocation cell ──────────────────────────────────────
        case 'save_alloc': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid  = (int)($b['pp_id'] ?? 0);
            $year  = (int)($b['year']  ?? 0);
            $month = (int)($b['month'] ?? 0);
            $pct   = ($b['pct'] === '' || $b['pct'] === null) ? null : (float)$b['pct'];
            if (!$ppid || !$year || !$month) { echo json_encode(['error'=>'Dados incompletos']); exit; }
            if ($pct === null) {
                $pdo->prepare("DELETE FROM rh_monthly_alloc WHERE person_project_id=? AND year=? AND month=?")
                    ->execute([$ppid, $year, $month]);
            } else {
                $pdo->prepare("INSERT INTO rh_monthly_alloc (person_project_id,year,month,percentage) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE percentage=VALUES(percentage)")
                    ->execute([$ppid, $year, $month, $pct]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save PM field ─────────────────────────────────────────────────
        case 'save_pm': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid  = (int)($b['pp_id'] ?? 0);
            $field = $b['field'] ?? '';
            $val   = ($b['value'] === '' || $b['value'] === null) ? null : (float)$b['value'];
            if (!$ppid || !in_array($field, ['pm_orc','pm_exe'], true)) { echo json_encode(['error'=>'Inválido']); exit; }
            $pdo->prepare("UPDATE rh_person_projects SET $field=? WHERE id=?")->execute([$val, $ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save person name/tipo ─────────────────────────────────────────
        case 'save_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid  = (int)($b['person_id'] ?? 0);
            $name = trim($b['full_name'] ?? '');
            $tipo = trim($b['tipo_ligacao'] ?? '');
            $code = trim($b['rh_code'] ?? '');
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $pdo->prepare("UPDATE rh_persons SET full_name=?, tipo_ligacao=?, rh_code=? WHERE id=?")
                ->execute([$name, $tipo ?: null, $code, $pid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save project label ────────────────────────────────────────────
        case 'save_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid = (int)($b['pp_id'] ?? 0);
            $code = trim($b['project_code'] ?? '');
            $name = trim($b['project_name'] ?? '');
            if (!$ppid) { echo json_encode(['error'=>'pp_id requerido']); exit; }
            $pdo->prepare("UPDATE rh_person_projects SET project_code=?, project_name=? WHERE id=?")
                ->execute([$code, $name, $ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Add person ────────────────────────────────────────────────────
        case 'add_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $cid   = (int)($b['campaign_id'] ?? 0);
            $name  = trim($b['full_name'] ?? '');
            $code  = trim($b['rh_code'] ?? '');
            $tipo  = trim($b['tipo_ligacao'] ?? '');
            if (!$cid || !$name) { echo json_encode(['error'=>'Dados incompletos']); exit; }
            $maxS = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM rh_persons WHERE campaign_id=?");
            $maxS->execute([$cid]);
            $sort = (int)$maxS->fetchColumn();
            $pdo->prepare("INSERT INTO rh_persons (campaign_id,rh_code,full_name,tipo_ligacao,sort_order) VALUES (?,?,?,?,?)")
                ->execute([$cid, $code, $name, $tipo ?: null, $sort]);
            $pid = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true,'person_id'=>$pid]);
            exit;
        }

        // ── Add project row to person ─────────────────────────────────────
        case 'add_person_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid  = (int)($b['person_id'] ?? 0);
            $code = trim($b['project_code'] ?? '');
            $name = trim($b['project_name'] ?? '');
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM rh_person_projects WHERE person_id=$pid")->fetchColumn();
            $pdo->prepare("INSERT INTO rh_person_projects (person_id,project_code,project_name,sort_order) VALUES (?,?,?,?)")
                ->execute([$pid, $code, $name, $sort]);
            echo json_encode(['ok'=>true,'pp_id'=>(int)$pdo->lastInsertId()]);
            exit;
        }

        // ── Delete project row ─────────────────────────────────────────────
        case 'delete_person_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid = (int)($b['pp_id'] ?? 0);
            if (!$ppid) { echo json_encode(['error'=>'pp_id requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_person_projects WHERE id=?")->execute([$ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Delete person (and all their project rows) ─────────────────────
        case 'delete_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid = (int)($b['person_id'] ?? 0);
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_persons WHERE id=?")->execute([$pid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Delete plan ───────────────────────────────────────────────────
        case 'delete_plan': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $plan = $b['plan_name'] ?? '';
            if (!$plan) { echo json_encode(['error'=>'plan_name requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_campaigns WHERE plan_name=?")->execute([$plan]);
            echo json_encode(['ok'=>true]);
            exit;
        }
    }

    echo json_encode(['error'=>'Ação desconhecida']);
    exit;
}

// ── Page data ────────────────────────────────────────────────────────────────
$plans = $pdo->query(
    "SELECT plan_name, MIN(created_at) as created_at FROM rh_campaigns GROUP BY plan_name ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$campaigns = $pdo->query(
    "SELECT id, plan_name, type FROM rh_campaigns ORDER BY plan_name, type"
)->fetchAll(PDO::FETCH_ASSOC);

$plan_map = [];
foreach ($campaigns as $c) {
    $plan_map[$c['plan_name']][$c['type']] = (int)$c['id'];
}
?>
<style>
.rh-wrap { padding: 0 0 60px; }
.rh-topbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.rh-topbar h5 { margin:0; font-size:15px; font-weight:700; }
.rh-plan-sel { min-width:200px; max-width:320px; font-size:13px; }

/* Tabs */
.rh-tabs { display:flex; gap:0; border-bottom:2px solid #dee2e6; margin-bottom:0; }
.rh-tab { background:none; border:none; padding:8px 20px; font-size:13px; font-weight:500; color:#6c757d; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; }
.rh-tab.active { color:#0d6efd; border-bottom-color:#0d6efd; }
.rh-tab:hover:not(.active) { color:#495057; background:#f8f9fa; border-radius:6px 6px 0 0; }

/* Grid outer */
.rh-grid-outer { position:relative; overflow-x:auto; border:1px solid #dee2e6; border-radius:0 8px 8px 8px; background:#fff; }

/* Table */
.rh-table { border-collapse:separate; border-spacing:0; font-size:12px; white-space:nowrap; min-width:100%; }
.rh-table th, .rh-table td { border-right:1px solid #e9ecef; border-bottom:1px solid #e9ecef; padding:0; }
.rh-table th { position:sticky; top:0; z-index:3; background:#343a40; color:#fff; font-weight:600; text-align:center; padding:4px 6px; }
.rh-table th.rh-sticky { z-index:5; }
.rh-table td.rh-sticky { position:sticky; z-index:2; background:#fff; }

/* Sticky column widths */
.rh-col-proj  { width:200px; min-width:200px; left:0; text-align:left !important; }
.rh-col-tipo  { width:170px; min-width:170px; left:200px; font-size:11px; color:#6c757d; }
.rh-col-pmorc { width:68px;  min-width:68px;  left:370px; text-align:center !important; }
.rh-col-pmexe { width:68px;  min-width:68px;  left:438px; text-align:center !important; }
.rh-sticky-border { border-right:2px solid #adb5bd !important; }

/* Header year/month rows */
.rh-year-th { background:#1a2433 !important; font-size:11px; letter-spacing:.5px; }
.rh-month-th { background:#343a40 !important; font-size:11px; padding:3px 2px !important; min-width:42px; width:42px; }

/* Person header row */
.rh-person-hdr td { background:#e9ecef; font-weight:600; font-size:12px; padding:5px 10px; cursor:pointer; user-select:none; border-bottom:1px solid #ced4da; }
.rh-person-hdr td:hover { background:#dee2e6; }
.rh-person-hdr .rh-ph-name { font-size:13px; }
.rh-person-hdr .rh-ph-meta { font-size:11px; font-weight:400; color:#6c757d; margin-left:8px; }
.rh-person-hdr .rh-ph-tipo { font-size:11px; font-weight:400; color:#0d6efd; margin-left:6px; }

/* Project rows */
.rh-proj-row td { padding:2px 4px; vertical-align:middle; }
.rh-proj-row:hover td { background:#f8f9fa; }
.rh-proj-row td.rh-sticky { background:#fff; }
.rh-proj-row:hover td.rh-sticky { background:#f8f9fa; }
.rh-proj-label { padding:2px 6px !important; }
.rh-proj-code { font-size:10px; color:#0d6efd; font-weight:600; }
.rh-proj-name { font-size:11px; color:#212529; }

/* SUM row */
.rh-sum-row td { background:#f1f3f5; font-size:11px; font-weight:600; padding:2px 4px; border-top:1px solid #ced4da !important; border-bottom:2px solid #adb5bd !important; }
.rh-sum-row td.rh-sticky { background:#f1f3f5; }

/* Allocation cells */
.rh-cell { width:42px; min-width:42px; text-align:center; padding:1px 2px !important; cursor:default; }
.rh-cell.rh-editable { cursor:pointer; }
.rh-cell.rh-editable:hover { outline:2px solid #0d6efd; outline-offset:-2px; }
.rh-cell-empty  { color:#adb5bd; }
.rh-cell-low    { background:#fff9e6; }
.rh-cell-full   { background:#d1e7dd; font-weight:700; color:#0f5132; }
.rh-cell-over   { background:#f8d7da; color:#721c24; font-weight:700; }
.rh-sum-empty   { color:#adb5bd; }
.rh-sum-ok      { background:#d1e7dd; color:#0f5132; }
.rh-sum-partial { background:#fff3cd; color:#664d03; }
.rh-sum-over    { background:#f8d7da; color:#721c24; }

/* PM cells */
.rh-pm-cell { text-align:center; font-size:11px; color:#495057; }
.rh-pm-cell.rh-editable { cursor:pointer; }
.rh-pm-cell.rh-editable:hover { background:#e8f4fd; outline:2px solid #0d6efd; outline-offset:-2px; }

/* Floating edit input */
#rh-edit-input {
    position:fixed; z-index:9999; display:none;
    width:52px; padding:2px 4px; font-size:12px; font-weight:600;
    text-align:center; border:2px solid #0d6efd; border-radius:4px;
    background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.2);
}

/* Empty state */
.rh-empty { padding:40px; text-align:center; color:#adb5bd; font-size:14px; }

/* Collapsed rows */
.rh-person-hidden { display:none; }

/* Admin action buttons on row */
.rh-row-actions { display:none; gap:4px; }
.rh-proj-row:hover .rh-row-actions { display:flex; }
.rh-btn-del { background:none; border:none; color:#dc3545; cursor:pointer; font-size:13px; padding:0 2px; line-height:1; }

/* Add person/project forms */
.rh-add-bar { display:flex; align-items:center; gap:8px; padding:6px 10px; background:#f8f9fa; border-top:1px dashed #dee2e6; }
.rh-add-bar input { font-size:12px; padding:3px 8px; border:1px solid #ced4da; border-radius:4px; }
.rh-add-bar .btn { font-size:12px; padding:3px 10px; }

/* Legend */
.rh-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11px; color:#6c757d; padding:6px 10px; background:#f8f9fa; border-top:1px solid #dee2e6; border-radius:0 0 8px 8px; }
.rh-legend-item { display:flex; align-items:center; gap:4px; }
.rh-legend-swatch { width:14px; height:14px; border-radius:3px; border:1px solid #dee2e6; }
</style>

<div class="rh-wrap">

<!-- Top bar -->
<div class="rh-topbar">
  <h5>📋 Imputação RH</h5>

  <select class="form-select form-select-sm rh-plan-sel" id="rh-plan-sel" onchange="rhSelectPlan(this.value)">
    <option value="">— Seleciona um plano —</option>
    <?php foreach ($plans as $pl): ?>
    <option value="<?= htmlspecialchars($pl['plan_name']) ?>">
      <?= htmlspecialchars($pl['plan_name']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <?php if ($is_admin): ?>
  <button class="btn btn-sm btn-outline-success" onclick="document.getElementById('rh-file-input').click()">
    <i class="bi bi-upload"></i> Importar XLSX
  </button>
  <input type="file" id="rh-file-input" accept=".xlsx" style="display:none" onchange="rhImportFile(this)">

  <button class="btn btn-sm btn-outline-primary" onclick="rhExportXlsx()" id="rh-export-btn" disabled>
    <i class="bi bi-download"></i> Exportar XLSX
  </button>

  <button class="btn btn-sm btn-outline-secondary" onclick="rhNewPlanModal()" title="Criar plano manualmente">
    <i class="bi bi-plus-circle"></i> Novo plano
  </button>

  <button class="btn btn-sm btn-outline-danger" onclick="rhDeletePlan()" id="rh-delete-btn" disabled>
    <i class="bi bi-trash3"></i> Eliminar plano
  </button>
  <?php else: ?>
  <button class="btn btn-sm btn-outline-primary" onclick="rhExportXlsx()" id="rh-export-btn" disabled>
    <i class="bi bi-download"></i> Exportar XLSX
  </button>
  <?php endif; ?>

  <span id="rh-status" class="text-muted" style="font-size:12px"></span>
</div>

<!-- Tabs -->
<div class="rh-tabs">
  <button class="rh-tab active" data-type="contratados" onclick="rhSwitchTab('contratados')">
    👤 Contratados
  </button>
  <button class="rh-tab" data-type="bolseiros" onclick="rhSwitchTab('bolseiros')">
    🎓 Bolseiros
  </button>
</div>

<!-- Grid containers -->
<div id="rh-grid-contratados" class="rh-grid-outer">
  <div class="rh-empty">Seleciona um plano para visualizar os dados</div>
</div>
<div id="rh-grid-bolseiros" class="rh-grid-outer" style="display:none">
  <div class="rh-empty">Seleciona um plano para visualizar os dados</div>
</div>

<!-- Floating edit input -->
<input id="rh-edit-input" type="number" min="0" max="999" step="1"
       onkeydown="rhInputKey(event)" onblur="rhInputBlur()">

</div><!-- .rh-wrap -->

<!-- New Plan Modal -->
<div class="modal fade" id="rh-new-plan-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Novo Plano Manual</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold">Nome do plano</label>
          <input type="text" class="form-control" id="rh-new-plan-name" placeholder="ex: CRIIS 2026-2029">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Período inicial</label>
          <input type="month" class="form-control" id="rh-new-plan-start" value="2026-01">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Período final</label>
          <input type="month" class="form-control" id="rh-new-plan-end" value="2029-12">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Tipo a criar</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="rh-np-cont" checked>
            <label class="form-check-label" for="rh-np-cont">Contratados</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="rh-np-bols" checked>
            <label class="form-check-label" for="rh-np-bols">Bolseiros</label></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary btn-sm" onclick="rhCreateEmptyPlan()">Criar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
// ── State ────────────────────────────────────────────────────────────────────
const RH_PLAN_MAP   = <?= json_encode($plan_map, JSON_UNESCAPED_UNICODE) ?>;
const RH_IS_ADMIN   = <?= $is_admin ? 'true' : 'false' ?>;
let rhCurrentPlan   = null;
let rhCurrentType   = 'contratados';
let rhCampaignData  = { contratados: null, bolseiros: null };
let rhEditTarget    = null; // { el, ppid, year, month, field }

// ── PT month labels ──────────────────────────────────────────────────────────
const PT_MONTHS_SHORT = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
const PT_MONTHS_ABBR  = { 'jan':'01','fev':'02','mar':'03','abr':'04','mai':'05','jun':'06',
                            'jul':'07','ago':'08','set':'09','out':'10','nov':'11','dez':'12' };

function rhYMLabel(ym) {
    const [y, m] = ym.split('-');
    return PT_MONTHS_SHORT[parseInt(m)-1] + ' ' + y.slice(2);
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function rhSwitchTab(type) {
    rhCurrentType = type;
    document.querySelectorAll('.rh-tab').forEach(t => t.classList.toggle('active', t.dataset.type === type));
    document.getElementById('rh-grid-contratados').style.display = type === 'contratados' ? '' : 'none';
    document.getElementById('rh-grid-bolseiros').style.display   = type === 'bolseiros'   ? '' : 'none';
}

// ── Plan selection ────────────────────────────────────────────────────────────
function rhSelectPlan(planName) {
    rhCurrentPlan = planName || null;
    const expBtn = document.getElementById('rh-export-btn');
    const delBtn = document.getElementById('rh-delete-btn');
    if (expBtn) expBtn.disabled = !planName;
    if (delBtn) delBtn.disabled = !planName;

    if (!planName) {
        ['contratados','bolseiros'].forEach(t => {
            rhCampaignData[t] = null;
            document.getElementById('rh-grid-'+t).innerHTML =
                '<div class="rh-empty">Seleciona um plano para visualizar os dados</div>';
        });
        return;
    }

    const planCamps = RH_PLAN_MAP[planName] || {};
    ['contratados','bolseiros'].forEach(type => {
        const cid = planCamps[type];
        const el  = document.getElementById('rh-grid-'+type);
        if (!cid) {
            rhCampaignData[type] = null;
            el.innerHTML = '<div class="rh-empty">Sem dados de ' + type + ' neste plano</div>';
            return;
        }
        el.innerHTML = '<div class="rh-empty"><div class="spinner-border spinner-border-sm text-secondary me-2"></div>A carregar…</div>';
        fetch('?tab=rh_imputacao&action=get_campaign_data&campaign_id='+cid, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            rhCampaignData[type] = data;
            rhRenderGrid(data, el, type);
        })
        .catch(e => { el.innerHTML = '<div class="rh-empty text-danger">Erro: ' + e.message + '</div>'; });
    });
}

// ── Grid rendering ────────────────────────────────────────────────────────────
function rhRenderGrid(data, container, type) {
    if (!data || !data.months || !data.persons) {
        container.innerHTML = '<div class="rh-empty">Sem dados</div>';
        return;
    }
    const months = data.months; // ["2026-01", ...]
    const totalCols = 4 + months.length;

    // Group months by year
    const yearGroups = [];
    months.forEach(ym => {
        const y = ym.split('-')[0];
        if (!yearGroups.length || yearGroups[yearGroups.length-1].year !== y)
            yearGroups.push({year: y, count: 0});
        yearGroups[yearGroups.length-1].count++;
    });

    let html = '<table class="rh-table" id="rh-tbl-'+type+'">';

    // ── Header row 1: year groups
    html += '<thead><tr>';
    html += '<th class="rh-sticky rh-col-proj rh-year-th" rowspan="2">Projeto</th>';
    html += '<th class="rh-sticky rh-col-tipo rh-year-th" rowspan="2">Tipo Ligação</th>';
    html += '<th class="rh-sticky rh-col-pmorc rh-year-th" rowspan="2">PM<br>ORC</th>';
    html += '<th class="rh-sticky rh-col-pmexe rh-sticky-border rh-year-th" rowspan="2">PM<br>EXE</th>';
    yearGroups.forEach(yg => {
        html += '<th class="rh-year-th" colspan="'+yg.count+'" style="font-size:13px;padding:5px 0">'+yg.year+'</th>';
    });
    html += '</tr>';

    // ── Header row 2: month names
    html += '<tr>';
    months.forEach(ym => {
        html += '<th class="rh-month-th">'+rhYMLabel(ym)+'</th>';
    });
    html += '</tr></thead><tbody>';

    // ── Person groups
    data.persons.forEach(person => {
        const pid = person.id;
        const projects = person.projects || [];

        // Person header row
        const pmExeTotal = projects.reduce((s,pp) => s + (parseFloat(pp.pm_exe)||0), 0);
        html += '<tr class="rh-person-hdr" onclick="rhTogglePerson('+pid+')">';
        html += '<td colspan="'+totalCols+'">';
        html += '<span style="margin-right:6px;font-size:10px;color:#6c757d" id="rh-arrow-'+pid+'">▼</span>';
        html += '<span class="rh-ph-name">'+rhEsc(person.full_name)+'</span>';
        html += '<span class="rh-ph-tipo">'+rhEsc(person.tipo_ligacao||'')+'</span>';
        html += '<span class="rh-ph-meta">'+projects.length+' projeto'+(projects.length!==1?'s':'');
        if (pmExeTotal) html += ' · PM EXE: '+pmExeTotal.toFixed(1)+' meses';
        html += '</span>';
        html += '<span class="rh-ph-meta" style="margin-left:6px;color:#6c757d;font-size:10px">('+rhEsc(person.rh_code)+')</span>';
        if (RH_IS_ADMIN) {
            html += '<button class="btn btn-xs btn-outline-primary ms-3" style="font-size:10px;padding:0 6px" onclick="event.stopPropagation();rhAddProjectRow('+pid+',\''+type+'\')">+ projeto</button>';
            html += '<button class="btn btn-xs btn-outline-danger ms-1" style="font-size:10px;padding:0 6px" onclick="event.stopPropagation();rhDeletePerson('+pid+',\''+type+'\')">🗑</button>';
        }
        html += '</td></tr>';

        // Project rows
        projects.forEach(pp => {
            const ppid = pp.id;
            html += '<tr class="rh-proj-row" data-pid="'+pid+'" data-ppid="'+ppid+'">';

            // Project label
            html += '<td class="rh-sticky rh-col-proj rh-proj-label">';
            html += '<div class="rh-proj-code">'+rhEsc(pp.project_code)+'</div>';
            html += '<div class="rh-proj-name">'+rhEsc(pp.project_name)+'</div>';
            if (RH_IS_ADMIN) {
                html += '<div class="rh-row-actions">'
                      + '<button class="rh-btn-del" onclick="rhDeletePP('+ppid+',\''+type+'\')" title="Remover projeto">✕</button>'
                      + '</div>';
            }
            html += '</td>';

            // Tipo
            html += '<td class="rh-sticky rh-col-tipo" style="font-size:11px;color:#6c757d;padding-left:6px">'+rhEsc(person.tipo_ligacao||'')+'</td>';

            // PM ORC
            const pmOrcVal = pp.pm_orc != null ? parseFloat(pp.pm_orc) : null;
            html += '<td class="rh-sticky rh-col-pmorc rh-pm-cell'+(RH_IS_ADMIN?' rh-editable':'')+'" '
                  + 'data-ppid="'+ppid+'" data-field="pm_orc">'
                  + (pmOrcVal != null ? pmOrcVal : '<span style="color:#ced4da">—</span>')+'</td>';

            // PM EXE
            const pmExeVal = pp.pm_exe != null ? parseFloat(pp.pm_exe) : null;
            html += '<td class="rh-sticky rh-col-pmexe rh-sticky-border rh-pm-cell'+(RH_IS_ADMIN?' rh-editable':'')+'" '
                  + 'data-ppid="'+ppid+'" data-field="pm_exe">'
                  + (pmExeVal != null ? pmExeVal : '<span style="color:#ced4da">—</span>')+'</td>';

            // Monthly cells
            months.forEach(ym => {
                const pct = pp.allocations ? pp.allocations[ym] : undefined;
                html += rhCellHtml(ppid, ym, pct);
            });

            html += '</tr>';
        });

        // SUM row
        html += '<tr class="rh-sum-row" data-pid="'+pid+'">';
        html += '<td class="rh-sticky rh-col-proj" style="padding-left:8px;color:#495057;font-size:11px">Σ total</td>';
        html += '<td class="rh-sticky rh-col-tipo"></td>';
        html += '<td class="rh-sticky rh-col-pmorc"></td>';

        const pmExeTotalFmt = pmExeTotal > 0 ? pmExeTotal.toFixed(1) : '';
        html += '<td class="rh-sticky rh-col-pmexe rh-sticky-border rh-sum-pm" data-pid="'+pid+'" style="text-align:center;font-size:11px;color:#495057">'+pmExeTotalFmt+'</td>';

        months.forEach(ym => {
            const [y, m] = ym.split('-').map(Number);
            const sum = projects.reduce((s, pp) => {
                const v = pp.allocations && pp.allocations[ym];
                return s + (v != null ? parseFloat(v) : 0);
            }, 0);
            html += rhSumCellHtml(pid, ym, sum);
        });
        html += '</tr>';
    });

    html += '</tbody></table>';

    // Legend
    html += '<div class="rh-legend">'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#d1e7dd"></span>100%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#fff9e6"></span>1–99%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#f8d7da"></span>&gt;100%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#fff3cd"></span>soma &lt;100%</span>'
          + '</div>';

    if (RH_IS_ADMIN) {
        html += '<div class="rh-add-bar">'
              + '<button class="btn btn-sm btn-outline-success" onclick="rhAddPersonRow(\''+data.id+'\',\''+type+'\')">+ Adicionar pessoa</button>'
              + '</div>';
    }

    container.innerHTML = html;
}

function rhCellHtml(ppid, ym, pct) {
    const [y, m] = ym.split('-');
    const editable = RH_IS_ADMIN ? ' rh-editable' : '';
    let cls = 'rh-cell'+editable;
    let label = '';
    if (pct != null && pct !== '') {
        const n = parseFloat(pct);
        label = n === 0 ? '<span class="rh-cell-empty">0</span>' : String(n);
        if (n === 0) cls += ' rh-cell-empty';
        else if (n < 100) cls += ' rh-cell-low';
        else if (n === 100) cls += ' rh-cell-full';
        else cls += ' rh-cell-over';
    }
    return '<td class="'+cls+'" data-ppid="'+ppid+'" data-y="'+y+'" data-m="'+m+'">'+label+'</td>';
}

function rhSumCellHtml(pid, ym, sum) {
    let cls = 'rh-cell';
    let label = sum > 0 ? Math.round(sum*100)/100 : '';
    if (sum === 0) cls += ' rh-sum-empty';
    else if (sum < 100) cls += ' rh-sum-partial';
    else if (sum === 100) cls += ' rh-sum-ok';
    else cls += ' rh-sum-over';
    return '<td class="'+cls+'" data-sum-pid="'+pid+'" data-sum-ym="'+ym+'">'+label+'</td>';
}

// ── Toggle person group ───────────────────────────────────────────────────────
function rhTogglePerson(pid) {
    const rows = document.querySelectorAll('[data-pid="'+pid+'"]');
    const arrow = document.getElementById('rh-arrow-'+pid);
    const collapsed = arrow && arrow.textContent === '▶';
    rows.forEach(r => {
        if (collapsed) r.classList.remove('rh-person-hidden');
        else r.classList.add('rh-person-hidden');
    });
    if (arrow) arrow.textContent = collapsed ? '▼' : '▶';
}

// ── Inline cell editing ───────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    const cell = e.target.closest('.rh-cell.rh-editable');
    if (cell) { rhOpenCellEdit(cell); return; }
    const pm = e.target.closest('.rh-pm-cell.rh-editable');
    if (pm) { rhOpenPmEdit(pm); return; }
    // click outside — commit
    if (!e.target.closest('#rh-edit-input')) rhCommitEdit();
});

function rhOpenCellEdit(cell) {
    rhCommitEdit();
    const rect = cell.getBoundingClientRect();
    const inp  = document.getElementById('rh-edit-input');
    const ppid = parseInt(cell.dataset.ppid);
    const year = parseInt(cell.dataset.y);
    const month= parseInt(cell.dataset.m);
    const cur  = cell.textContent.trim().replace(/[^0-9.]/g,'') || '';

    rhEditTarget = { el: cell, ppid, year, month, field: null, orig: cur };
    inp.value = cur;
    inp.style.left = (rect.left + window.scrollX) + 'px';
    inp.style.top  = (rect.top  + window.scrollY) + 'px';
    inp.style.width= rect.width + 'px';
    inp.style.height= rect.height + 'px';
    inp.style.display = 'block';
    inp.focus(); inp.select();
}

function rhOpenPmEdit(cell) {
    rhCommitEdit();
    const rect = cell.getBoundingClientRect();
    const inp  = document.getElementById('rh-edit-input');
    const ppid = parseInt(cell.dataset.ppid);
    const field= cell.dataset.field;
    const cur  = cell.textContent.trim().replace(/[^0-9.]/g,'') || '';

    rhEditTarget = { el: cell, ppid, year: null, month: null, field, orig: cur };
    inp.value = cur;
    inp.style.left = (rect.left + window.scrollX) + 'px';
    inp.style.top  = (rect.top  + window.scrollY) + 'px';
    inp.style.width= rect.width + 'px';
    inp.style.height= rect.height + 'px';
    inp.style.display = 'block';
    inp.focus(); inp.select();
}

function rhInputKey(e) {
    if (e.key === 'Enter')  { rhCommitEdit(); }
    if (e.key === 'Escape') { rhCancelEdit(); }
    if (e.key === 'Tab') {
        e.preventDefault();
        const t = rhEditTarget;
        rhCommitEdit();
        if (t && t.el) {
            const next = t.el.nextElementSibling;
            if (next && next.classList.contains('rh-editable')) rhOpenCellEdit(next);
        }
    }
}

function rhInputBlur() {
    // Small delay so click events fire first
    setTimeout(rhCommitEdit, 100);
}

function rhCancelEdit() {
    document.getElementById('rh-edit-input').style.display = 'none';
    rhEditTarget = null;
}

function rhCommitEdit() {
    if (!rhEditTarget) return;
    const inp = document.getElementById('rh-edit-input');
    const val = inp.value.trim();
    inp.style.display = 'none';
    const t = rhEditTarget;
    rhEditTarget = null;

    if (val === t.orig) return; // No change

    if (t.field) {
        // PM field
        const numVal = val === '' ? null : parseFloat(val);
        t.el.textContent = numVal != null ? numVal : '';
        fetch('?tab=rh_imputacao&action=save_pm', {
            method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ pp_id: t.ppid, field: t.field, value: val === '' ? null : parseFloat(val) })
        }).catch(console.error);
    } else {
        // Allocation cell
        const numVal = val === '' ? null : parseFloat(val);
        const ym = t.year + '-' + String(t.month).padStart(2,'0');

        // Update cell visuals
        rhUpdateCellVisual(t.el, numVal);

        // Update sum row
        const pid = t.el.closest('tr').dataset.pid;
        if (pid) rhRecomputeSum(parseInt(pid), ym);

        // Update in-memory data
        if (rhCampaignData[rhCurrentType]) {
            for (const person of rhCampaignData[rhCurrentType].persons) {
                for (const pp of person.projects) {
                    if (pp.id === t.ppid) {
                        if (numVal === null) delete pp.allocations[ym];
                        else pp.allocations[ym] = numVal;
                        break;
                    }
                }
            }
        }

        fetch('?tab=rh_imputacao&action=save_alloc', {
            method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ pp_id: t.ppid, year: t.year, month: t.month, pct: numVal })
        }).catch(console.error);
    }
}

function rhUpdateCellVisual(cell, pct) {
    cell.className = cell.className.replace(/rh-cell-\w+/g,'').trim();
    if (pct === null || pct === undefined) {
        cell.innerHTML = '';
    } else {
        const n = parseFloat(pct);
        if (n === 0) { cell.innerHTML = '<span class="rh-cell-empty">0</span>'; cell.classList.add('rh-cell-empty'); }
        else if (n < 100)  { cell.textContent = n; cell.classList.add('rh-cell-low'); }
        else if (n === 100){ cell.textContent = n; cell.classList.add('rh-cell-full'); }
        else               { cell.textContent = n; cell.classList.add('rh-cell-over'); }
    }
}

function rhRecomputeSum(pid, ym) {
    // Collect all project cells for this person+month
    const cells = document.querySelectorAll('.rh-proj-row[data-pid="'+pid+'"] .rh-cell[data-y="'+ym.split('-')[0]+'"][data-m="'+ym.split('-')[1]+'"]');
    let sum = 0;
    cells.forEach(c => { const v = parseFloat(c.textContent); if (!isNaN(v)) sum += v; });
    const sumCell = document.querySelector('.rh-sum-row[data-pid="'+pid+'"] [data-sum-ym="'+ym+'"]');
    if (sumCell) {
        sumCell.className = sumCell.className.replace(/rh-sum-\w+|rh-cell-\w+/g,'').trim() + ' rh-cell';
        if (sum === 0)        { sumCell.textContent=''; sumCell.classList.add('rh-sum-empty'); }
        else if (sum < 100)   { sumCell.textContent=Math.round(sum*100)/100; sumCell.classList.add('rh-sum-partial'); }
        else if (sum === 100) { sumCell.textContent=100; sumCell.classList.add('rh-sum-ok'); }
        else                  { sumCell.textContent=Math.round(sum*100)/100; sumCell.classList.add('rh-sum-over'); }
    }
}

// ── Admin: add / delete rows ──────────────────────────────────────────────────
function rhAddPersonRow(campaignId, type) {
    const name = prompt('Nome da pessoa:');
    if (!name) return;
    const code = prompt('Código RH (ex: R12345):', '') || '';
    const tipo = prompt('Tipo de ligação:', '') || '';
    rhAjax('add_person', { campaign_id: parseInt(campaignId), full_name: name, rh_code: code, tipo_ligacao: tipo })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhAddProjectRow(personId, type) {
    const code = prompt('Código do projeto (ex: PG07206 ou STEP_IRIS):', '') || '';
    const name = prompt('Nome curto do projeto:', code) || code;
    rhAjax('add_person_project', { person_id: personId, project_code: code, project_name: name })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhDeletePP(ppid, type) {
    if (!confirm('Remover este projeto e todas as alocações mensais?')) return;
    rhAjax('delete_person_project', { pp_id: ppid })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhDeletePerson(pid, type) {
    if (!confirm('Remover esta pessoa e todos os seus projetos/alocações?')) return;
    rhAjax('delete_person', { person_id: pid })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhDeletePlan() {
    if (!rhCurrentPlan) return;
    if (!confirm('Eliminar o plano "'+rhCurrentPlan+'" e todos os dados associados?')) return;
    rhAjax('delete_plan', { plan_name: rhCurrentPlan }).then(() => location.reload());
}

// ── Import XLSX ───────────────────────────────────────────────────────────────
function rhImportFile(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    const planName = prompt('Nome do plano para esta importação:', file.name.replace(/\.xlsx$/i,'').trim());
    if (!planName) return;

    rhSetStatus('A ler ficheiro…');
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
            const sheetTypeMap = {
                'Imputacao_RH_contratados': 'contratados',
                'Imputacao_RH_bolseiros':   'bolseiros'
            };
            const promises = [];
            wb.SheetNames.forEach(sname => {
                const type = sheetTypeMap[sname];
                if (!type) return;
                const ws = wb.Sheets[sname];
                const rows = XLSX.utils.sheet_to_json(ws, { header:1, defval:null, raw:true });
                const { months, persons } = rhParseSheet(rows);
                rhSetStatus('A importar '+type+' ('+persons.length+' pessoas)…');
                promises.push(
                    rhAjax('import_rh', { plan_name: planName, type, months, persons })
                );
            });
            Promise.all(promises).then(() => {
                rhSetStatus('Importação concluída!');
                location.reload();
            }).catch(err => { rhSetStatus('Erro: '+err.message); });
        } catch(ex) {
            rhSetStatus('Erro ao ler XLSX: '+ex.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

function rhParseSheet(rows) {
    if (!rows.length) return { months:[], persons:[] };

    // Parse header row → month keys
    const header = rows[0];
    const months = [];
    for (let i = 5; i < header.length; i++) {
        const h = header[i] ? String(header[i]).trim().toLowerCase() : '';
        const match = h.match(/^([a-z]+)\/(\d{2})$/);
        if (match && PT_MONTHS_ABBR[match[1]]) {
            months.push((2000 + parseInt(match[2])) + '-' + PT_MONTHS_ABBR[match[1]]);
        }
    }

    const persons = [];
    let curPerson = null;
    let curName   = null;

    for (let ri = 1; ri < rows.length; ri++) {
        const row = rows[ri];
        const colA = (row[0] ?? '').toString().trim();
        const colB = (row[1] ?? '').toString().trim();
        const colC = (row[2] ?? '').toString().trim();
        const colD = (row[3] ?? '').toString().trim();

        if (!colA) continue;
        // Skip SUM rows
        if (colC === 'SUM' || colD === 'SUM') continue;

        // Parse person
        let rh_code, full_name;
        if (colA.includes('|')) {
            const sep = colA.split(/\s*\|\s*/);
            rh_code   = sep[0].trim();
            full_name = sep.slice(1).join('|').trim();
        } else { rh_code = full_name = colA; }

        if (full_name !== curName) {
            curName = full_name;
            curPerson = { rh_code, full_name, tipo_ligacao: colB || null, projects: [] };
            persons.push(curPerson);
        } else if (colB && !curPerson.tipo_ligacao) {
            curPerson.tipo_ligacao = colB;
        }

        // Parse project
        let proj_code, proj_name;
        if (colC && colC.includes('|')) {
            const parts = colC.split(/\s*\|\s*/);
            proj_code = parts[0].trim(); proj_name = parts.slice(1).join('|').trim();
        } else { proj_code = proj_name = colC || ''; }

        // PM values
        const pm_orc = (row[3] !== null && row[3] !== undefined && String(row[3]).trim() !== 'SUM' && String(row[3]).trim() !== '')
                        ? parseFloat(row[3]) || null : null;
        const pm_exe = (row[4] !== null && row[4] !== undefined && String(row[4]).trim() !== '')
                        ? parseFloat(row[4]) || null : null;

        // Allocations
        const allocations = {};
        months.forEach((ym, i) => {
            const v = row[5 + i];
            if (v !== null && v !== undefined && v !== '') {
                const n = parseFloat(v);
                if (!isNaN(n)) allocations[ym] = n;
            }
        });

        curPerson.projects.push({ code: proj_code, name: proj_name, pm_orc, pm_exe, allocations });
    }

    return { months, persons };
}

// ── Export XLSX ───────────────────────────────────────────────────────────────
async function rhExportXlsx() {
    if (!rhCurrentPlan) return;
    rhSetStatus('A gerar XLSX…');

    const planCamps = RH_PLAN_MAP[rhCurrentPlan] || {};
    const wb = XLSX.utils.book_new();

    const sheetDefs = [
        { type:'contratados', sheetName:'Imputacao_RH_contratados' },
        { type:'bolseiros',   sheetName:'Imputacao_RH_bolseiros'   }
    ];

    for (const sd of sheetDefs) {
        const cid = planCamps[sd.type];
        if (!cid) continue;
        let data = rhCampaignData[sd.type];
        if (!data) {
            const r = await fetch('?tab=rh_imputacao&action=get_campaign_data&campaign_id='+cid, {
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });
            data = await r.json();
        }
        if (!data || !data.persons) continue;

        const months = data.months;
        const PT_ABBRs = { '01':'jan','02':'fev','03':'mar','04':'abr','05':'mai','06':'jun',
                           '07':'jul','08':'ago','09':'set','10':'out','11':'nov','12':'dez' };

        // Build rows array
        const aoa = [];

        // Header row
        const hdr = ['iD RH | Nome Profissional','Tipo Ligação','id PRP | Nome Curto','PM    ORC','PM EXE'];
        months.forEach(ym => { const [y,m] = ym.split('-'); hdr.push(PT_ABBRs[m]+'/'+y.slice(2)); });
        aoa.push(hdr);

        data.persons.forEach(person => {
            const personLabel = (person.rh_code ? person.rh_code + '  |  ' : '') + person.full_name;
            let sumPmExe = 0;
            const sumAllocs = {};
            months.forEach(ym => sumAllocs[ym] = 0);

            person.projects.forEach(pp => {
                const row = [personLabel, person.tipo_ligacao||'', (pp.project_code ? pp.project_code+'  |  ' : '')+pp.project_name, pp.pm_orc, pp.pm_exe];
                months.forEach(ym => { row.push(pp.allocations[ym] ?? null); sumAllocs[ym] += parseFloat(pp.allocations[ym]||0); });
                aoa.push(row);
                sumPmExe += parseFloat(pp.pm_exe||0);
            });

            // SUM row
            const sumRow = [personLabel, null, 'SUM', null, sumPmExe > 0 ? sumPmExe : null];
            months.forEach(ym => sumRow.push(sumAllocs[ym] || null));
            aoa.push(sumRow);
        });

        const ws = XLSX.utils.aoa_to_sheet(aoa);

        // Column widths
        const wscols = [{ wch:35 },{ wch:25 },{ wch:28 },{ wch:10 },{ wch:10 }];
        months.forEach(() => wscols.push({ wch:7 }));
        ws['!cols'] = wscols;

        XLSX.utils.book_append_sheet(wb, ws, sd.sheetName);
    }

    XLSX.writeFile(wb, rhCurrentPlan + '.xlsx');
    rhSetStatus('Exportado: '+rhCurrentPlan+'.xlsx');
}

// ── New empty plan ────────────────────────────────────────────────────────────
function rhNewPlanModal() {
    new bootstrap.Modal(document.getElementById('rh-new-plan-modal')).show();
}

async function rhCreateEmptyPlan() {
    const name  = document.getElementById('rh-new-plan-name').value.trim();
    const start = document.getElementById('rh-new-plan-start').value; // "2026-01"
    const end   = document.getElementById('rh-new-plan-end').value;
    if (!name || !start || !end) { alert('Preenche todos os campos'); return; }

    // Generate month array
    const months = [];
    let [sy, sm] = start.split('-').map(Number);
    const [ey, em] = end.split('-').map(Number);
    while (sy < ey || (sy === ey && sm <= em)) {
        months.push(sy + '-' + String(sm).padStart(2,'0'));
        sm++; if (sm > 12) { sm = 1; sy++; }
    }

    const types = [];
    if (document.getElementById('rh-np-cont').checked) types.push('contratados');
    if (document.getElementById('rh-np-bols').checked) types.push('bolseiros');
    if (!types.length) { alert('Seleciona pelo menos um tipo'); return; }

    for (const type of types) {
        await rhAjax('import_rh', { plan_name: name, type, months, persons: [] });
    }
    bootstrap.Modal.getInstance(document.getElementById('rh-new-plan-modal')).hide();
    location.reload();
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function rhAjax(action, body) {
    return fetch('?tab=rh_imputacao&action='+action, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify(body)
    }).then(r => r.json()).then(d => {
        if (d.error) throw new Error(d.error);
        return d;
    });
}

function rhSetStatus(msg) {
    const el = document.getElementById('rh-status');
    if (el) { el.textContent = msg; setTimeout(() => { if (el.textContent===msg) el.textContent=''; }, 4000); }
}

function rhEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-select first plan if exists
(function() {
    const sel = document.getElementById('rh-plan-sel');
    if (sel && sel.options.length > 1) {
        sel.selectedIndex = 1;
        rhSelectPlan(sel.value);
    }
})();
</script>
