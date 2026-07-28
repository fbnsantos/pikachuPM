<?php
// tabs/peer_evaluation.php — Avaliação entre Pares

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('<div class="alert alert-danger">Erro de conexão BD</div>');
}

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);
$cur_user = $_SESSION['username'] ?? '';

// ── Tabelas ────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS peer_eval_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL DEFAULT 'Avaliação entre Pares',
        year_label VARCHAR(20) DEFAULT NULL,
        is_public TINYINT(1) DEFAULT 0,
        results_published TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS peer_eval_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        can_evaluate TINYINT(1) DEFAULT 1,
        can_be_evaluated TINYINT(1) DEFAULT 1,
        added_by INT,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cp (campaign_id, user_id),
        INDEX idx_cid (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS peer_eval_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        evaluator_id INT NOT NULL,
        evaluatee_id INT NOT NULL,
        field_key VARCHAR(100) NOT NULL,
        field_value TEXT DEFAULT NULL,
        is_skip TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_resp (campaign_id, evaluator_id, evaluatee_id, field_key),
        INDEX idx_cid (campaign_id),
        INDEX idx_evaluatee (campaign_id, evaluatee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Adiciona coluna em instalações existentes (falha silenciosamente se já existir)
try { $pdo->exec("ALTER TABLE peer_eval_campaigns ADD COLUMN results_published TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}

// ── Admin check ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

// ── Definição de campos ────────────────────────────────────────────────────
$ABCD_STD = ['A'=>'Excecional','B'=>'Muito Bom','C'=>'Bom','D'=>'A Desenvolver'];

$SECTIONS = [
    'comp_esp' => [
        'title'  => 'Competências Específicas',
        'scale'  => $ABCD_STD,
        'fields' => [
            'comp_metodologia' => 'Metodologia e Técnicas de Investigação Científica',
            'comp_escrita'     => 'Escrita e Comunicação Científica',
        ],
    ],
    'comp_comp' => [
        'title'  => 'Competências Comportamentais',
        'scale'  => $ABCD_STD,
        'fields' => [
            'comp_comunicacao' => 'Comunicação interpessoal',
            'comp_resultados'  => 'Orientação para resultados',
            'comp_analitico'   => 'Pensamento Analítico',
            'comp_criativo'    => 'Pensamento Criativo',
            'comp_equipa'      => 'Trabalho em equipa',
        ],
    ],
    'desempenho' => [
        'title'  => 'Qualidade e Quantidade de Trabalho',
        'scale'  => [
            'A'=>'Desempenho superior na maioria das atividades',
            'B'=>'Desempenho superior em algumas atividades',
            'C'=>'Desempenho correspondente ao expectável',
            'D'=>'Desempenho insuficiente ao expectável',
        ],
        'fields' => [
            'desemp_qual_global'  => 'Desempenho Qualitativo (Global)',
            'desemp_qual_a'       => 'Complexidade do trabalho',
            'desemp_qual_b'       => 'Rigor / Qualidade técnica',
            'desemp_qual_c'       => 'Cumprimento de prazos',
            'desemp_qual_d'       => 'Contributos inovadores',
            'desemp_quant_global' => 'Desempenho Quantitativo (Global)',
            'desemp_quant_a'      => 'Múltiplas tarefas ou projetos',
            'desemp_quant_b'      => 'Afetação de tempo suplementar',
        ],
    ],
    'atitude' => [
        'title'  => 'Atitude',
        'scale'  => ['A'=>'Excecional','B'=>'Muito Bom','C'=>'Bom','D'=>'A Desenvolver'],
        'fields' => [
            'atitude_global'  => 'Atitude (Global)',
            'atitude_disp'    => 'Disponibilidade',
            'atitude_aut'     => 'Autonomia',
            'atitude_coop'    => 'Cooperação',
            'atitude_resp'    => 'Responsabilidade',
        ],
    ],
];

// ── Dados ──────────────────────────────────────────────────────────────────
$campaign      = $pdo->query("SELECT * FROM peer_eval_campaigns WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$all_campaigns = $pdo->query("SELECT * FROM peer_eval_campaigns ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$participants = $evaluatees = $evaluators = [];
$my_role = null;
$my_responses = [];

if ($campaign) {
    $cid = $campaign['id'];
    $stmt = $pdo->prepare("SELECT * FROM peer_eval_participants WHERE campaign_id=? ORDER BY username");
    $stmt->execute([$cid]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($participants as $p) {
        if ($p['can_be_evaluated']) $evaluatees[] = $p;
        if ($p['can_evaluate'])     $evaluators[]  = $p;
        if ($p['user_id'] == $cur_uid) $my_role = $p;
    }

    if ($my_role && $my_role['can_evaluate']) {
        $s = $pdo->prepare("SELECT evaluatee_id,field_key,field_value,is_skip FROM peer_eval_responses WHERE campaign_id=? AND evaluator_id=?");
        $s->execute([$cid,$cur_uid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $my_responses[$r['evaluatee_id']][$r['field_key']] = $r;
        }
    }

    // Para admin: progresso por avaliador (quantas pessoas distintas já avaliaram)
    $evaluator_progress = [];
    if ($is_admin) {
        $s = $pdo->prepare("SELECT evaluator_id, COUNT(DISTINCT evaluatee_id) as cnt FROM peer_eval_responses WHERE campaign_id=? GROUP BY evaluator_id");
        $s->execute([$cid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $evaluator_progress[(int)$r['evaluator_id']] = (int)$r['cnt'];
        }
    }
}

$is_evaluator     = $my_role && $my_role['can_evaluate'];
$is_evaluatee     = $my_role && $my_role['can_be_evaluated'];
$can_eval         = $is_admin || ($campaign && $campaign['is_public'] && $is_evaluator);
$can_see_results  = $campaign && $campaign['results_published'] && $is_evaluatee;

// Calcular valor médio A+/A/B+/B/C+/C/D+/D a partir de respostas A/B/C/D
function peCalcMean(array $vals): ?string {
    $map = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
    $sum = 0; $n = 0;
    foreach ($vals as $v) { if (isset($map[$v])) { $sum += $map[$v]; $n++; } }
    if (!$n) return null;
    $avg = $sum / $n;
    if ($avg >= 3.75) return 'A+';
    if ($avg >= 3.25) return 'A';
    if ($avg >= 2.75) return 'B+';
    if ($avg >= 2.25) return 'B';
    if ($avg >= 1.75) return 'C+';
    if ($avg >= 1.25) return 'C';
    if ($avg >= 0.75) return 'D+';
    return 'D';
}

// flat field list
$all_fields = [];
foreach ($SECTIONS as $sk => $sec) {
    foreach ($sec['fields'] as $fk => $fl) {
        $all_fields[$fk] = ['label'=>$fl,'section'=>$sk,'scale'=>$sec['scale'],'sec_title'=>$sec['title']];
    }
}
?>

<style>
.pe-wrap{padding:0 0 40px}
.pe-badge-public{background:#198754;color:#fff;font-size:11px;padding:3px 10px;border-radius:20px}
.pe-badge-draft{background:#6c757d;color:#fff;font-size:11px;padding:3px 10px;border-radius:20px}

/* Info panel */
.pe-info-panel{background:#f0f7ff;border:1px solid #b6d4fe;border-radius:10px;padding:14px 18px;margin-bottom:16px}
.pe-info-panel .pe-scale-group{margin-bottom:10px}
.pe-info-panel .pe-scale-group:last-child{margin-bottom:0}
.pe-info-panel .pe-scale-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#495057;margin-bottom:4px}
.pe-scale-items{display:flex;flex-wrap:wrap;gap:6px}
.pe-scale-item{display:inline-flex;align-items:center;gap:5px;font-size:11px;background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:3px 8px}
.pe-scale-badge{display:inline-block;width:20px;height:20px;border-radius:50%;font-size:10px;font-weight:700;text-align:center;line-height:20px;color:#fff;flex-shrink:0}
.pe-scale-badge.A{background:#198754}.pe-scale-badge.B{background:#0d6efd}
.pe-scale-badge.C{background:#fd7e14}.pe-scale-badge.D{background:#dc3545}

/* Admin panel */
.pe-admin-panel{background:#f8f9fa;border:1px solid #dee2e6;border-radius:10px;padding:18px;margin-bottom:20px}
.pe-pt{border-collapse:collapse;width:100%;font-size:13px}
.pe-pt th{background:#e9ecef;padding:7px 10px;text-align:left}
.pe-pt td{padding:7px 10px;border-bottom:1px solid #dee2e6;vertical-align:middle}
.pe-pt tr:last-child td{border-bottom:none}

/* Grid */
.pe-grid-wrap{overflow-x:auto;border:1px solid #dee2e6;border-radius:8px}
.pe-grid{border-collapse:collapse;font-size:12px;width:100%}
.pe-grid th{background:#343a40;color:#fff;padding:6px 5px;text-align:center;white-space:nowrap;position:sticky;top:0;z-index:2}
.pe-grid th.pe-th-name{text-align:left;min-width:140px;background:#212529;position:sticky;left:0;z-index:3}
.pe-grid th.pe-th-sec{background:#495057;font-size:10px;font-weight:700;letter-spacing:.5px}
.pe-grid th.pe-th-field{font-size:10px;font-weight:500;line-height:1.2;white-space:normal;max-width:75px;padding:5px 3px;cursor:help}
.pe-grid td{padding:3px;border:1px solid #e9ecef;vertical-align:middle;text-align:center}
.pe-grid td.pe-td-name{text-align:left;font-weight:600;padding:6px 10px;background:#f8f9fa;position:sticky;left:0;z-index:1;white-space:nowrap;min-width:140px;border-right:2px solid #dee2e6}
.pe-grid tr:nth-child(even) td{background:#fafafa}
.pe-grid tr:nth-child(even) td.pe-td-name{background:#f0f0f0}
.pe-grid tr:hover td{background:#fffbeb !important}

/* Cell select */
.pe-sel{width:62px;font-size:11px;padding:2px 1px;border:1px solid #ced4da;border-radius:4px;background:#fff;text-align:center;cursor:pointer}
.pe-sel:focus{border-color:#86b7fe;outline:none;box-shadow:0 0 0 2px rgba(13,110,253,.15)}
.pe-sel.val-A{background:#d1e7dd;border-color:#a3cfbb;color:#0a3622}
.pe-sel.val-B{background:#cfe2ff;border-color:#9ec5fe;color:#031633}
.pe-sel.val-C{background:#fff3cd;border-color:#ffc107;color:#664d03}
.pe-sel.val-D{background:#f8d7da;border-color:#f1aeb5;color:#58151c}
.pe-sel.val-skip{background:#e9ecef;border-color:#adb5bd;color:#6c757d;font-style:italic}

/* Save status */
.pe-status{font-size:11px;color:#6c757d;transition:all .2s}
.pe-status.saving{color:#0d6efd}.pe-status.saved{color:#198754}.pe-status.err{color:#dc3545}

/* Results */
.pe-mean{display:inline-block;padding:3px 10px;border-radius:6px;font-size:13px;font-weight:700;letter-spacing:.5px}
/* Distribution popover */
.pe-pop{position:fixed;z-index:9999;background:#fff;border:1px solid #dee2e6;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.18);padding:12px 16px;min-width:160px;display:none}
.pe-pop-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:8px}
.pe-pop-row{display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:12px}
.pe-pop-row:last-child{margin-bottom:0}
.pe-pop-lbl{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.pe-pop-bar-wrap{flex:1;background:#f0f0f0;border-radius:3px;height:10px;overflow:hidden}
.pe-pop-bar{height:10px;border-radius:3px;transition:width .2s}
.pe-pop-cnt{font-weight:600;min-width:18px;text-align:right}
td.pe-clickable{cursor:pointer}
td.pe-clickable:hover .pe-mean{opacity:.8}
.pe-mean-Ap,.pe-mean-A{background:#d1e7dd;color:#0a3622}
.pe-mean-Bp,.pe-mean-B{background:#cfe2ff;color:#031633}
.pe-mean-Cp,.pe-mean-C{background:#fff3cd;color:#664d03}
.pe-mean-Dp,.pe-mean-D{background:#f8d7da;color:#58151c}
.pe-mean-Ap::after{content:'+';font-size:9px;vertical-align:super}
.pe-mean-Bp::after{content:'+';font-size:9px;vertical-align:super}
.pe-mean-Cp::after{content:'+';font-size:9px;vertical-align:super}
.pe-mean-Dp::after{content:'+';font-size:9px;vertical-align:super}
</style>

<div class="pe-wrap">

<!-- ── Header ── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <h5 class="mb-0 fw-bold">👥 Avaliação entre Pares</h5>
    <?php if ($campaign): ?>
      <span class="text-muted" style="font-size:13px"><?= htmlspecialchars($campaign['title'].' '.($campaign['year_label']??'')) ?></span>
      <?= $campaign['is_public'] ? '<span class="pe-badge-public">🔓 Aberta</span>' : '<span class="pe-badge-draft">🔒 Rascunho</span>' ?>
      <?= !empty($campaign['results_published']) ? '<span class="pe-badge-public" style="background:#0d6efd">📊 Resultados publicados</span>' : '' ?>
    <?php endif; ?>
    <span class="pe-status" id="pe-status"></span>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($is_admin): ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="peAdminToggle()">⚙ Gerir</button>
      <?php if ($campaign): ?>
      <button class="btn btn-sm <?= $campaign['is_public'] ? 'btn-warning' : 'btn-success' ?>"
        onclick="peTogglePublic(<?= $campaign['id'] ?>,<?= $campaign['is_public'] ? 0 : 1 ?>)">
        <?= $campaign['is_public'] ? '🔒 Fechar avaliação' : '🔓 Abrir avaliação' ?>
      </button>
      <button class="btn btn-sm <?= !empty($campaign['results_published']) ? 'btn-secondary' : 'btn-primary' ?>"
        onclick="peToggleResults(<?= $campaign['id'] ?>,<?= !empty($campaign['results_published']) ? 0 : 1 ?>)">
        <?= !empty($campaign['results_published']) ? '📊 Retirar resultados' : '📊 Publicar resultados' ?>
      </button>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($can_eval || $can_see_results): ?>
    <button class="btn btn-sm btn-outline-info" onclick="peInfoToggle()">ⓘ Escalas</button>
    <?php endif; ?>
  </div>
</div>

<!-- ── Painel de escalas (ⓘ) ── -->
<div id="pe-info-panel" class="pe-info-panel" style="display:none">
  <?php
  $shown_scales = [];
  foreach ($SECTIONS as $sk => $sec):
    if (empty($sec['scale'])) continue;
    $scale_key = md5(serialize($sec['scale']));
    if (in_array($scale_key, $shown_scales)) continue;
    $shown_scales[] = $scale_key;
  ?>
  <div class="pe-scale-group">
    <div class="pe-scale-title"><?= htmlspecialchars($sec['title']) ?></div>
    <div class="pe-scale-items">
      <?php foreach ($sec['scale'] as $v=>$desc): ?>
      <span class="pe-scale-item">
        <span class="pe-scale-badge <?= $v ?>"><?= $v ?></span>
        <?= htmlspecialchars($desc) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="pe-scale-group">
    <div class="pe-scale-title">Opção especial</div>
    <div class="pe-scale-items">
      <span class="pe-scale-item"><span style="font-size:10px;background:#6c757d;color:#fff;border-radius:3px;padding:1px 5px">N/A</span> Não pretende avaliar este parâmetro</span>
    </div>
  </div>
</div>

<!-- ── Admin panel ── -->
<?php if ($is_admin): ?>
<div id="pe-admin-panel" class="pe-admin-panel" style="display:none">
  <div class="row g-3">
    <div class="col-md-5">
      <h6 class="fw-bold mb-2">📋 Campanha</h6>
      <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" id="pe-new-title" value="Avaliação entre Pares" placeholder="Título">
        <input type="text" class="form-control" id="pe-new-year" value="<?= date('Y') ?>" placeholder="Ano" style="max-width:75px">
        <button class="btn btn-primary" onclick="peCreateCampaign()">Nova</button>
      </div>
      <?php if ($all_campaigns): ?>
      <ul class="list-group list-group-flush" style="font-size:12px">
        <?php foreach ($all_campaigns as $c): ?>
        <li class="list-group-item py-1 px-2 d-flex justify-content-between">
          <span><?= htmlspecialchars($c['title'].' '.($c['year_label']??'')) ?>
            <?= $c['is_active'] ? ' <span class="badge bg-success" style="font-size:9px">Ativa</span>' : '' ?>
          </span>
          <small class="text-muted"><?= date('d/m/Y',strtotime($c['created_at'])) ?></small>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php if ($campaign): ?>
    <div class="col-md-7">
      <h6 class="fw-bold mb-2">👤 Participantes</h6>
      <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
        <select id="pe-add-user" class="form-select form-select-sm" style="max-width:200px">
          <option value="">Utilizador…</option>
        </select>
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" id="pe-chk-be" checked>
          <label class="form-check-label" for="pe-chk-be" style="font-size:12px">Avaliado</label>
        </div>
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" id="pe-chk-ev" checked>
          <label class="form-check-label" for="pe-chk-ev" style="font-size:12px">Avaliador</label>
        </div>
        <button class="btn btn-sm btn-outline-primary" onclick="peAddParticipant(<?= $campaign['id'] ?>)">+ Adicionar</button>
      </div>
      <table class="pe-pt">
        <thead><tr><th>Utilizador</th><th>Avaliado</th><th>Avaliador</th><th></th></tr></thead>
        <tbody id="pe-parts">
          <?php foreach ($participants as $p): ?>
          <tr id="pe-p-<?= $p['id'] ?>">
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td><?= $p['can_be_evaluated'] ? '✅' : '—' ?></td>
            <td><?= $p['can_evaluate']     ? '✅' : '—' ?></td>
            <td><button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:11px"
              onclick="peRemoveParticipant(<?= $campaign['id'] ?>,<?= $p['id'] ?>)">✕</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$campaign): ?>
  <div class="alert alert-info">Nenhuma campanha ativa. <?= $is_admin ? 'Cria uma campanha acima.' : 'Aguarda que um administrador crie uma campanha.' ?></div>

<?php elseif (!$is_admin && !$can_eval && !$can_see_results): ?>
  <div class="alert alert-secondary"><i class="bi bi-lock-fill"></i> A avaliação ainda não está disponível. Aguarda a autorização do administrador.</div>

<?php else: ?>

<?php if ($can_eval): ?>
<?php if (!$is_admin): ?>
<p class="text-muted mb-2" style="font-size:12px">A avaliar como: <strong><?= htmlspecialchars($cur_user) ?></strong> &nbsp;·&nbsp; Clica em ⓘ Escalas para ver o significado de cada letra.</p>
<?php endif; ?>

<!-- ── Grid principal ── -->
<div class="pe-grid-wrap">
<table class="pe-grid">
  <thead>
    <tr>
      <th class="pe-th-name" rowspan="2">Avaliado</th>
      <?php foreach ($SECTIONS as $sk=>$sec): ?>
      <th class="pe-th-sec" colspan="<?= count($sec['fields']) ?>"><?= htmlspecialchars($sec['title']) ?></th>
      <?php endforeach; ?>
    </tr>
    <tr>
      <?php foreach ($SECTIONS as $sk=>$sec): foreach ($sec['fields'] as $fk=>$fl): ?>
      <th class="pe-th-field" title="<?= htmlspecialchars($fl) ?>"><?= htmlspecialchars(mb_strimwidth($fl,0,22,'…')) ?></th>
      <?php endforeach; endforeach; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($evaluatees as $ee):
    if ($ee['user_id'] == $cur_uid) continue;
    $eid = $ee['user_id'];
  ?>
  <tr>
    <td class="pe-td-name"><?= htmlspecialchars($ee['username']) ?></td>
    <?php foreach ($SECTIONS as $sk=>$sec): foreach ($sec['fields'] as $fk=>$fl):
      $resp   = $my_responses[$eid][$fk] ?? null;
      $val    = $resp['field_value'] ?? '';
      $skip   = (int)($resp['is_skip'] ?? 0);
      $selval = $skip ? '__skip__' : $val;
      $cls    = $skip ? 'val-skip' : ($val ? 'val-'.$val : '');
    ?>
    <td>
      <select class="pe-sel <?= $cls ?>"
        id="sel-<?= $eid ?>-<?= $fk ?>"
        onchange="peSelChange(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',this)">
        <option value="">—</option>
        <?php foreach ($sec['scale'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $selval==$v?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
        <option value="__skip__" <?= $selval=='__skip__'?'selected':'' ?>>N/A</option>
      </select>
    </td>
    <?php endforeach; endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; // can_eval ?>

<!-- ── Resultados publicados (avaliado) ── -->
<?php if ($can_see_results && !$is_admin): ?>
<?php
$s = $pdo->prepare("SELECT field_key,field_value FROM peer_eval_responses WHERE campaign_id=? AND evaluatee_id=? AND is_skip=0 AND field_value IS NOT NULL AND field_value!=''");
$s->execute([$cid,$cur_uid]);
$by_field = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $by_field[$r['field_key']][] = $r['field_value'];
?>
<div class="mt-4">
  <h6 class="fw-bold">📊 Os meus resultados</h6>
  <p class="text-muted" style="font-size:12px">Valor médio das avaliações recebidas por parâmetro. A identidade dos avaliadores é anónima.</p>
  <div class="table-responsive">
  <table class="table table-sm table-bordered" style="font-size:12px">
    <thead class="table-dark"><tr><th>Secção</th><th>Parâmetro</th><th style="text-align:center">Valor médio</th><th style="text-align:center">Nº avaliações</th></tr></thead>
    <tbody>
    <?php
    $last_sec = '';
    foreach ($all_fields as $fk=>$fi):
      $vals  = $by_field[$fk] ?? [];
      if (empty($vals)) continue;
      $mean  = peCalcMean($vals);
      $cls   = 'pe-mean pe-mean-'.str_replace('+','p',$mean);
      $label = str_replace('+','⁺', $mean);
    ?>
    <tr>
      <td class="text-muted" style="font-size:11px;white-space:nowrap"><?php if ($last_sec !== $fi['sec_title']) { $last_sec = $fi['sec_title']; echo htmlspecialchars($fi['sec_title']); } ?></td>
      <td><?= htmlspecialchars($fi['label']) ?></td>
      <td style="text-align:center"><span class="<?= $cls ?>"><?= $label ?></span></td>
      <td style="text-align:center"><?= count($vals) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Admin: progresso + resultados ── -->
<?php if ($is_admin && $campaign): ?>

<!-- Progresso por avaliador -->
<?php if ($evaluators): ?>
<div class="mt-4">
  <h6 class="fw-bold">📋 Progresso das avaliações</h6>
  <div class="table-responsive">
  <table class="table table-sm table-bordered" style="font-size:12px">
    <thead class="table-secondary">
      <tr><th>Avaliador</th><th style="text-align:center">Pessoas avaliadas</th><th style="text-align:center">Total a avaliar</th><th style="min-width:120px">Progresso</th></tr>
    </thead>
    <tbody>
    <?php foreach ($evaluators as $ev):
      $ev_uid     = $ev['user_id'];
      $total_ev   = count(array_filter($evaluatees, fn($e) => $e['user_id'] != $ev_uid));
      $done       = $evaluator_progress[$ev_uid] ?? 0;
      $pct        = $total_ev > 0 ? round($done / $total_ev * 100) : 0;
      $bar_cls    = $pct === 0 ? 'bg-secondary' : ($pct >= 100 ? 'bg-success' : 'bg-warning text-dark');
    ?>
    <tr>
      <td><?= htmlspecialchars($ev['username']) ?></td>
      <td style="text-align:center"><?= $done ?></td>
      <td style="text-align:center"><?= $total_ev ?></td>
      <td>
        <div class="progress" style="height:16px;border-radius:4px">
          <div class="progress-bar <?= $bar_cls ?>" style="width:<?= max($pct,0) ?>%;font-size:10px;line-height:16px">
            <?= $pct > 0 ? $pct.'%' : '' ?>
          </div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Resultados agregados -->
<?php
$s = $pdo->prepare("SELECT evaluatee_id,field_key,field_value FROM peer_eval_responses WHERE campaign_id=? AND is_skip=0 AND field_value IS NOT NULL AND field_value!=''");
$s->execute([$cid]);
$admin_results = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $admin_results[$r['evaluatee_id']][$r['field_key']][] = $r['field_value'];
?>
<div class="mt-3">
  <h6 class="fw-bold">📊 Resultados agregados</h6>
  <p class="text-muted" style="font-size:12px">Visível só ao administrador. Clica em «Publicar resultados» para que cada avaliado veja os seus valores.</p>
  <?php if (!$admin_results): ?>
  <p class="text-muted" style="font-size:12px"><em>Ainda não existem respostas submetidas.</em></p>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm table-bordered" style="font-size:12px">
    <thead>
      <tr class="table-dark">
        <th style="position:sticky;left:0;background:#212529;min-width:130px">Avaliado</th>
        <?php foreach ($all_fields as $fk=>$fi): ?>
        <th style="text-align:center;font-size:10px;white-space:normal;max-width:70px;font-weight:500" title="<?= htmlspecialchars($fi['label']) ?>"><?= htmlspecialchars(mb_strimwidth($fi['label'],0,20,'…')) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($evaluatees as $ee):
      $eid = $ee['user_id'];
    ?>
    <tr>
      <td class="fw-semibold" style="white-space:nowrap;position:sticky;left:0;background:#f8f9fa"><?= htmlspecialchars($ee['username']) ?></td>
      <?php foreach ($all_fields as $fk=>$fi):
        $vals  = $admin_results[$eid][$fk] ?? [];
        $mean  = $vals ? peCalcMean($vals) : null;
        $cls   = $mean ? 'pe-mean pe-mean-'.str_replace('+','p',$mean) : '';
        $lbl   = $mean ? str_replace('+','⁺',$mean) : '';
        $freq  = $vals ? array_count_values($vals) : [];
        $total = count($vals);
        $dist  = $total ? htmlspecialchars(json_encode(['freq'=>$freq,'total'=>$total,'label'=>$fi['label']]),ENT_QUOTES) : '';
      ?>
      <td style="text-align:center;padding:2px" <?= $dist ? "class=\"pe-clickable\" data-dist=\"$dist\"" : '' ?>>
        <?= $mean ? "<span class=\"$cls\" style=\"font-size:11px;padding:1px 6px\">$lbl</span>" : '<span class="text-muted" style="font-size:10px">—</span>' ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php endif; // main gate ?>
</div>

<!-- ── Popover de distribuição ── -->
<div id="pe-pop" class="pe-pop">
  <div class="pe-pop-title" id="pe-pop-title"></div>
  <div id="pe-pop-body"></div>
</div>

<script>
const PE_URL = '<?= htmlspecialchars(dirname($_SERVER['PHP_SELF'])) ?>/peer_eval_ajax.php';
let _peTimer = null;

function peInfoToggle() {
    const p = document.getElementById('pe-info-panel');
    if (p) p.style.display = p.style.display === 'none' ? '' : 'none';
}
function peAdminToggle() {
    const p = document.getElementById('pe-admin-panel');
    if (p) p.style.display = p.style.display === 'none' ? '' : 'none';
}

// ── Select change (ABCD + N/A) ──
function peSelChange(cid, eid, fkey, sel) {
    const val  = sel.value;
    const skip = val === '__skip__' ? 1 : 0;
    const fval = skip ? '' : val;
    // Update style
    sel.className = 'pe-sel' + (val ? ' val-' + val : '');
    peStatus('saving');
    clearTimeout(_peTimer);
    _peTimer = setTimeout(() => {
        pePost({pe_action:'save_response',campaign_id:cid,evaluatee_id:eid,field_key:fkey,field_value:fval,is_skip:skip}, d => {
            peStatus(d.ok ? 'saved' : 'err', d.ok ? '' : d.msg);
        });
    }, 500);
}

function peStatus(state, msg) {
    const el = document.getElementById('pe-status');
    if (!el) return;
    const map = {saving:'A guardar…',saved:'✓ Guardado',err:'✗ '+(msg||'Erro')};
    el.textContent = map[state] || '';
    el.className   = 'pe-status '+(state||'');
    if (state==='saved') setTimeout(()=>{ el.textContent=''; el.className='pe-status'; },3000);
}

// ── Admin ──
function peCreateCampaign() {
    const title = document.getElementById('pe-new-title').value.trim();
    const year  = document.getElementById('pe-new-year').value.trim();
    if (!title) return alert('Título obrigatório');
    if (!confirm('Criar nova campanha? A campanha atual ficará arquivada.')) return;
    pePost({pe_action:'create_campaign',title,year}, d => d.ok ? location.reload() : alert(d.msg));
}
function peTogglePublic(cid, val) {
    const msg = val ? 'Abrir avaliação? Os avaliadores poderão preencher as avaliações.' : 'Fechar avaliação? Os avaliadores deixarão de poder preencher.';
    if (!confirm(msg)) return;
    pePost({pe_action:'toggle_public',campaign_id:cid,is_public:val}, d => d.ok ? location.reload() : alert(d.msg));
}
function peToggleResults(cid, val) {
    const msg = val ? 'Publicar resultados? Os avaliados verão os valores médios das suas avaliações.' : 'Retirar resultados? Os avaliados deixarão de ver os resultados.';
    if (!confirm(msg)) return;
    pePost({pe_action:'toggle_results',campaign_id:cid,results_published:val}, d => d.ok ? location.reload() : alert(d.msg));
}
function peAddParticipant(cid) {
    const sel   = document.getElementById('pe-add-user');
    const uid   = sel.value;
    const uname = sel.options[sel.selectedIndex]?.text;
    if (!uid) return alert('Seleciona um utilizador');
    const can_be = document.getElementById('pe-chk-be').checked ? 1 : 0;
    const can_ev = document.getElementById('pe-chk-ev').checked ? 1 : 0;
    if (!can_be && !can_ev) return alert('Seleciona pelo menos um papel');
    pePost({pe_action:'add_participant',campaign_id:cid,user_id:uid,username:uname,can_evaluate:can_ev,can_be_evaluated:can_be},
        d => d.ok ? location.reload() : alert(d.msg));
}
function peRemoveParticipant(cid, pid) {
    if (!confirm('Remover participante?')) return;
    pePost({pe_action:'remove_participant',campaign_id:cid,participant_id:pid}, d => {
        if (d.ok) document.getElementById('pe-p-'+pid)?.remove();
        else alert(d.msg);
    });
}

// Load users for admin dropdown
(function(){
    const sel = document.getElementById('pe-add-user');
    if (!sel) return;
    pePost({pe_action:'get_users'}, d => {
        if (!d.ok) return;
        d.users.forEach(u => {
            const o = document.createElement('option');
            o.value = u.user_id; o.textContent = u.username;
            sel.appendChild(o);
        });
    });
})();

// ── Popover de distribuição ──
const PE_COLORS = {A:'#198754',B:'#0d6efd',C:'#fd7e14',D:'#dc3545'};
const PE_POP    = document.getElementById('pe-pop');

document.addEventListener('click', e => {
    const td = e.target.closest('td.pe-clickable');
    if (!td) { PE_POP && (PE_POP.style.display='none'); return; }
    const raw = td.dataset.dist;
    if (!raw) return;
    const d = JSON.parse(raw);
    document.getElementById('pe-pop-title').textContent = d.label;
    const body = document.getElementById('pe-pop-body');
    body.innerHTML = '';
    const maxN = Math.max(...['A','B','C','D'].map(v => d.freq[v]||0), 1);
    ['A','B','C','D'].forEach(v => {
        const n = d.freq[v] || 0;
        const pct = Math.round(n / maxN * 100);
        const row = document.createElement('div');
        row.className = 'pe-pop-row';
        row.innerHTML = `
            <span class="pe-pop-lbl" style="background:${PE_COLORS[v]}">${v}</span>
            <div class="pe-pop-bar-wrap">
              <div class="pe-pop-bar" style="width:${pct}%;background:${PE_COLORS[v]}"></div>
            </div>
            <span class="pe-pop-cnt">${n}</span>
            <span class="text-muted" style="font-size:10px">${d.total>0?Math.round(n/d.total*100)+'%':''}</span>`;
        body.appendChild(row);
    });
    // Position near click
    const vw = window.innerWidth, vh = window.innerHeight;
    PE_POP.style.display = 'block';
    const pw = PE_POP.offsetWidth, ph = PE_POP.offsetHeight;
    let left = e.clientX + 12, top = e.clientY + 12;
    if (left + pw > vw - 8) left = e.clientX - pw - 8;
    if (top  + ph > vh - 8) top  = e.clientY - ph - 8;
    PE_POP.style.left = left + 'px';
    PE_POP.style.top  = top  + 'px';
    e.stopPropagation();
});

function pePost(data, cb) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    fetch(PE_URL, {method:'POST', body:fd})
        .then(r => r.json()).then(d => cb && cb(d))
        .catch(e => { console.error('pe error',e); peStatus('err','Erro de rede'); });
}
</script>
