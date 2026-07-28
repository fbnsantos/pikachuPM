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

// ── Admin check ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

// ── Definição de campos ────────────────────────────────────────────────────
$TEXT_FIELDS = ['comment_desemp','comment_atitude','comment_colaborador'];
$ABCD_STD    = ['A'=>'Excecional','B'=>'Muito Bom','C'=>'Bom','D'=>'A Desenvolver'];

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
            'comment_desemp'      => 'Comentário Desempenho',
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
            'comment_atitude' => 'Comentário Atitude',
        ],
    ],
    'colaborador' => [
        'title'  => 'Colaborador',
        'scale'  => [],
        'fields' => [
            'comment_colaborador' => 'Comentário do Colaborador',
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
}

$can_view       = $is_admin || ($campaign && $campaign['is_public'] && $my_role);
$is_evaluator   = $my_role && $my_role['can_evaluate'];
$is_evaluatee   = $my_role && $my_role['can_be_evaluated'];

// flat field list
$all_fields = [];
foreach ($SECTIONS as $sk => $sec) {
    foreach ($sec['fields'] as $fk => $fl) {
        $all_fields[$fk] = ['label'=>$fl,'section'=>$sk,'is_text'=>in_array($fk,$TEXT_FIELDS),'scale'=>$sec['scale'],'sec_title'=>$sec['title']];
    }
}

// Total non-text columns
$total_cols = 0;
foreach ($SECTIONS as $sec) $total_cols += count($sec['fields']);
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

/* Comment cell button */
.pe-cmt-btn{border:1px dashed #adb5bd;background:#f8f9fa;border-radius:4px;font-size:10px;padding:2px 5px;cursor:pointer;color:#6c757d;white-space:nowrap}
.pe-cmt-btn.has-val{border-color:#ffc107;background:#fff3cd;color:#664d03}
.pe-cmt-btn:hover{border-color:#0d6efd;color:#0d6efd}

/* Comment modal */
.pe-cmt-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:flex;align-items:center;justify-content:center}
.pe-cmt-box{background:#fff;border-radius:12px;padding:20px;width:min(480px,90vw);box-shadow:0 8px 32px rgba(0,0,0,.25)}
.pe-cmt-box textarea{width:100%;border:1px solid #dee2e6;border-radius:6px;padding:8px;font-size:13px;resize:vertical;min-height:100px;margin-bottom:10px}

/* Save status */
.pe-status{font-size:11px;color:#6c757d;transition:all .2s}
.pe-status.saving{color:#0d6efd}.pe-status.saved{color:#198754}.pe-status.err{color:#dc3545}

/* Results */
.pe-dist-bar{display:flex;height:14px;border-radius:3px;overflow:hidden;gap:1px;min-width:80px}
.pe-dist-bar span{display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff}
.pe-dist-bar .dA{background:#198754}.pe-dist-bar .dB{background:#0d6efd}
.pe-dist-bar .dC{background:#fd7e14}.pe-dist-bar .dD{background:#dc3545}
</style>

<div class="pe-wrap">

<!-- ── Header ── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <h5 class="mb-0 fw-bold">👥 Avaliação entre Pares</h5>
    <?php if ($campaign): ?>
      <span class="text-muted" style="font-size:13px"><?= htmlspecialchars($campaign['title'].' '.($campaign['year_label']??'')) ?></span>
      <?= $campaign['is_public'] ? '<span class="pe-badge-public">🔓 Pública</span>' : '<span class="pe-badge-draft">🔒 Rascunho</span>' ?>
    <?php endif; ?>
    <span class="pe-status" id="pe-status"></span>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($is_admin): ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="peAdminToggle()">⚙ Gerir</button>
      <?php if ($campaign): ?>
      <button class="btn btn-sm <?= $campaign['is_public'] ? 'btn-warning' : 'btn-success' ?>"
        onclick="peTogglePublic(<?= $campaign['id'] ?>,<?= $campaign['is_public'] ? 0 : 1 ?>)">
        <?= $campaign['is_public'] ? '🔒 Tornar Rascunho' : '🔓 Tornar Pública' ?>
      </button>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($can_view): ?>
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

<?php elseif (!$can_view): ?>
  <div class="alert alert-secondary"><i class="bi bi-lock-fill"></i> A avaliação ainda não foi tornada pública. Aguarda a autorização do administrador.</div>

<?php else: ?>

<?php if ($is_evaluator || $is_admin): ?>
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
    if ($ee['user_id'] == $cur_uid && !$is_admin) continue;
    $eid = $ee['user_id'];
  ?>
  <tr>
    <td class="pe-td-name"><?= htmlspecialchars($ee['username']) ?></td>
    <?php foreach ($SECTIONS as $sk=>$sec): foreach ($sec['fields'] as $fk=>$fl):
      $resp  = $my_responses[$eid][$fk] ?? null;
      $val   = $resp['field_value'] ?? '';
      $skip  = (int)($resp['is_skip'] ?? 0);
      $is_txt = in_array($fk,$TEXT_FIELDS);
    ?>
    <td>
    <?php if ($is_txt): ?>
      <button class="pe-cmt-btn <?= $val ? 'has-val' : '' ?>"
        id="cmt-<?= $eid ?>-<?= $fk ?>"
        onclick="peOpenComment(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',<?= json_encode($fl) ?>)"
        title="<?= $val ? htmlspecialchars(mb_strimwidth($val,0,60,'…')) : 'Sem comentário' ?>">
        <?= $val ? '✏️ editar' : '+ comentário' ?>
      </button>
    <?php else:
      $selval = $skip ? '__skip__' : $val;
      $cls    = $skip ? 'val-skip' : ($val ? 'val-'.$val : '');
    ?>
      <select class="pe-sel <?= $cls ?>"
        id="sel-<?= $eid ?>-<?= $fk ?>"
        onchange="peSelChange(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',this)">
        <option value="">—</option>
        <?php foreach ($sec['scale'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $selval==$v?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
        <option value="__skip__" <?= $selval=='__skip__'?'selected':'' ?>>N/A</option>
      </select>
    <?php endif; ?>
    </td>
    <?php endforeach; endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; // is_evaluator ?>

<!-- ── Resultados públicos para o avaliado ── -->
<?php if ($is_evaluatee && $campaign['is_public'] && !$is_admin): ?>
<div class="mt-4">
  <h6 class="fw-bold">📊 Os meus resultados</h6>
  <p class="text-muted" style="font-size:12px">Distribuição das avaliações recebidas por parâmetro. A identidade dos avaliadores é anónima.</p>
  <?php
  $s = $pdo->prepare("SELECT field_key,field_value,is_skip FROM peer_eval_responses WHERE campaign_id=? AND evaluatee_id=? AND is_skip=0 AND (field_value IS NOT NULL AND field_value!='')");
  $s->execute([$cid,$cur_uid]);
  $by_field = [];
  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $by_field[$r['field_key']][] = $r['field_value'];
  ?>
  <div class="table-responsive">
  <table class="table table-sm table-bordered" style="font-size:12px">
    <thead class="table-dark"><tr><th>Secção</th><th>Parâmetro</th><th>Distribuição</th><th>Mais frequente</th><th>Nº avaliações</th></tr></thead>
    <tbody>
    <?php
    $last_sec = '';
    foreach ($all_fields as $fk=>$fi):
      if ($fi['is_text']) continue;
      $vals = $by_field[$fk] ?? [];
      if (empty($vals)) continue;
      $freq = array_count_values($vals);
      $total = count($vals);
      arsort($freq);
      $moda = array_key_first($freq);
      $bc = ['A'=>'bg-success','B'=>'bg-primary','C'=>'bg-warning text-dark','D'=>'bg-danger'];
    ?>
    <tr>
      <td class="text-muted" style="font-size:11px"><?= $last_sec!==$fi['sec_title'] ? ($last_sec=$fi['sec_title']) && htmlspecialchars($fi['sec_title']) : '' ?></td>
      <td><?= htmlspecialchars($fi['label']) ?></td>
      <td>
        <div class="pe-dist-bar">
          <?php foreach (['A','B','C','D'] as $v): $n=$freq[$v]??0; if(!$n) continue; $pct=round($n/$total*100); ?>
          <span class="d<?= $v ?>" style="flex:<?= $n ?>" title="<?= $v ?>: <?= $n ?>"><?= $n ?></span>
          <?php endforeach; ?>
        </div>
      </td>
      <td><span class="badge <?= $bc[$moda] ?? 'bg-secondary' ?>"><?= $moda ?></span></td>
      <td class="text-center"><?= $total ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <!-- Comentários recebidos -->
  <?php
  $s = $pdo->prepare("SELECT field_key,field_value FROM peer_eval_responses WHERE campaign_id=? AND evaluatee_id=? AND is_skip=0 AND field_value IS NOT NULL AND field_value!='' AND field_key LIKE 'comment_%'");
  $s->execute([$cid,$cur_uid]);
  $comments = $s->fetchAll(PDO::FETCH_ASSOC);
  if ($comments):
  ?>
  <h6 class="fw-bold mt-3">💬 Comentários recebidos</h6>
  <?php foreach ($comments as $c):
    $label = $all_fields[$c['field_key']]['label'] ?? $c['field_key'];
  ?>
  <div class="mb-2 p-2 border rounded" style="font-size:12px;background:#f8f9fa">
    <div class="fw-semibold text-muted mb-1" style="font-size:11px"><?= htmlspecialchars($label) ?></div>
    <?= nl2br(htmlspecialchars($c['field_value'])) ?>
  </div>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php endif; // can_view ?>
</div>

<!-- ── Modal comentário ── -->
<div id="pe-cmt-overlay" class="pe-cmt-overlay" style="display:none" onclick="if(event.target===this)peCloseComment()">
  <div class="pe-cmt-box">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong id="pe-cmt-title" style="font-size:13px"></strong>
      <button class="btn-close" onclick="peCloseComment()"></button>
    </div>
    <textarea id="pe-cmt-text" placeholder="Escreve o comentário…"></textarea>
    <div class="d-flex gap-2 justify-content-end">
      <button class="btn btn-sm btn-secondary" onclick="peCloseComment()">Cancelar</button>
      <button class="btn btn-sm btn-primary" onclick="peSaveComment()">Guardar</button>
    </div>
  </div>
</div>

<script>
const PE_URL = '<?= htmlspecialchars(dirname($_SERVER['PHP_SELF'])) ?>/peer_eval_ajax.php';
let _peTimer = null;
let _cmtCtx  = null; // {cid, eid, fkey}

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

// ── Comment modal ──
function peOpenComment(cid, eid, fkey, label) {
    _cmtCtx = {cid, eid, fkey};
    document.getElementById('pe-cmt-title').textContent = label;
    // Load existing value from textarea/button
    const btn = document.getElementById('cmt-'+eid+'-'+fkey);
    document.getElementById('pe-cmt-text').value = btn?.dataset.val || '';
    document.getElementById('pe-cmt-overlay').style.display = 'flex';
    setTimeout(() => document.getElementById('pe-cmt-text').focus(), 50);
}
function peCloseComment() {
    document.getElementById('pe-cmt-overlay').style.display = 'none';
    _cmtCtx = null;
}
function peSaveComment() {
    if (!_cmtCtx) return;
    const {cid,eid,fkey} = _cmtCtx;
    const val = document.getElementById('pe-cmt-text').value;
    peStatus('saving');
    pePost({pe_action:'save_response',campaign_id:cid,evaluatee_id:eid,field_key:fkey,field_value:val,is_skip:0}, d => {
        peStatus(d.ok ? 'saved' : 'err', d.ok ? '' : d.msg);
        if (d.ok) {
            const btn = document.getElementById('cmt-'+eid+'-'+fkey);
            if (btn) {
                btn.dataset.val = val;
                btn.textContent = val ? '✏️ editar' : '+ comentário';
                btn.className   = 'pe-cmt-btn' + (val ? ' has-val' : '');
                btn.title       = val || 'Sem comentário';
            }
            peCloseComment();
        }
    });
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
    const msg = val ? 'Tornar pública? Os participantes poderão ver os resultados.' : 'Voltar a rascunho?';
    if (!confirm(msg)) return;
    pePost({pe_action:'toggle_public',campaign_id:cid,is_public:val}, d => d.ok ? location.reload() : alert(d.msg));
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

// Load comment values into button dataset
<?php if ($is_evaluator || $is_admin): foreach ($evaluatees as $ee): if ($ee['user_id']==$cur_uid && !$is_admin) continue; $eid=$ee['user_id']; foreach ($TEXT_FIELDS as $fk): $val=$my_responses[$eid][$fk]['field_value']??''; if (!$val) continue; ?>
document.getElementById('cmt-<?= $eid ?>-<?= $fk ?>')?.setAttribute('data-val', <?= json_encode($val) ?>);
<?php endforeach; endforeach; endif; ?>

function pePost(data, cb) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    fetch(PE_URL, {method:'POST', body:fd})
        .then(r => r.json()).then(d => cb && cb(d))
        .catch(e => { console.error('pe error',e); peStatus('err','Erro de rede'); });
}
</script>
