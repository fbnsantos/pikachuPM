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
$ABCD_STD    = ['A'=>'A — Excecional','B'=>'B — Muito Bom','C'=>'C — Bom','D'=>'D — A Desenvolver'];

$SECTIONS = [
    'comp_esp' => [
        'title' => 'Competências Específicas',
        'scale' => $ABCD_STD,
        'fields' => [
            'comp_metodologia' => 'Metodologia e Técnicas de Investigação Científica',
            'comp_escrita'     => 'Escrita e Comunicação Científica',
        ],
    ],
    'comp_comp' => [
        'title' => 'Competências Comportamentais',
        'scale' => $ABCD_STD,
        'fields' => [
            'comp_comunicacao' => 'Comunicação interpessoal',
            'comp_resultados'  => 'Orientação para resultados',
            'comp_analitico'   => 'Pensamento Analítico',
            'comp_criativo'    => 'Pensamento Criativo',
            'comp_equipa'      => 'Trabalho em equipa',
        ],
    ],
    'desempenho' => [
        'title' => '2/3. Qualidade e Quantidade de Trabalho',
        'scale' => [
            'A'=>'A — Desempenho superior na maioria das atividades',
            'B'=>'B — Desempenho superior em algumas atividades',
            'C'=>'C — Desempenho correspondente ao expectável',
            'D'=>'D — Desempenho insuficiente ao expectável',
        ],
        'fields' => [
            'desemp_qual_global'  => '2.1 DESEMPENHO QUALITATIVO (GLOBAL)',
            'desemp_qual_a'       => 'a) Complexidade do trabalho realizado',
            'desemp_qual_b'       => 'b) Rigor / Qualidade técnica',
            'desemp_qual_c'       => 'c) Cumprimento de prazos / Eficiência',
            'desemp_qual_d'       => 'd) Contributos inovadores',
            'desemp_quant_global' => '2.2 DESEMPENHO QUANTITATIVO (GLOBAL)',
            'desemp_quant_a'      => 'a) Múltiplas tarefas ou projetos',
            'desemp_quant_b'      => 'b) Afetação de tempo suplementar',
            'comment_desemp'      => 'Comentário ponto 2',
        ],
    ],
    'atitude' => [
        'title' => '4. Atitude',
        'scale' => ['A'=>'A — Excecional','B'=>'B — Muito Bom','C'=>'C — Bom','D'=>'D — A Desenvolver'],
        'fields' => [
            'atitude_global'  => 'ATITUDE (GLOBAL)',
            'atitude_disp'    => 'a) Disponibilidade',
            'atitude_aut'     => 'b) Autonomia',
            'atitude_coop'    => 'c) Cooperação',
            'atitude_resp'    => 'd) Responsabilidade',
            'comment_atitude' => 'Comentário ponto 3',
        ],
    ],
    'colaborador' => [
        'title' => 'Comentário do Colaborador',
        'scale' => [],
        'fields' => [
            'comment_colaborador' => 'Comentário do Colaborador',
        ],
    ],
];


// ── Dados para render ──────────────────────────────────────────────────────
$campaign = $pdo->query("SELECT * FROM peer_eval_campaigns WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$all_campaigns = $pdo->query("SELECT * FROM peer_eval_campaigns ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$participants = $evaluatees = $evaluators = [];
$my_role = null;
$my_responses = [];

if ($campaign) {
    $cid = $campaign['id'];

    $participants = $pdo->prepare("SELECT * FROM peer_eval_participants WHERE campaign_id=? ORDER BY username");
    $participants->execute([$cid]);
    $participants = $participants->fetchAll(PDO::FETCH_ASSOC);

    foreach ($participants as $p) {
        if ($p['can_be_evaluated']) $evaluatees[] = $p;
        if ($p['can_evaluate'])     $evaluators[]  = $p;
        if ($p['user_id'] == $cur_uid) $my_role = $p;
    }

    // Load responses for current evaluator
    if ($my_role && $my_role['can_evaluate']) {
        $s = $pdo->prepare("SELECT evaluatee_id,field_key,field_value,is_skip FROM peer_eval_responses WHERE campaign_id=? AND evaluator_id=?");
        $s->execute([$cid,$cur_uid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $my_responses[$r['evaluatee_id']][$r['field_key']] = $r;
        }
    }
}

// Can view: admin always; others only when campaign is public AND they're a participant
$can_view = $is_admin || ($campaign && $campaign['is_public'] && $my_role);

// All field keys flat
$all_fields = [];
foreach ($SECTIONS as $sk => $sec) {
    foreach ($sec['fields'] as $fk => $fl) {
        $all_fields[$fk] = ['label'=>$fl,'section'=>$sk,'is_text'=>in_array($fk,$TEXT_FIELDS),'scale'=>$sec['scale']];
    }
}
?>

<style>
.pe-wrap { padding: 0 0 40px; }
.pe-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
.pe-title { font-size:1.3rem; font-weight:700; margin:0; }
.pe-badge-public { background:#198754; color:#fff; font-size:11px; padding:3px 10px; border-radius:20px; }
.pe-badge-draft  { background:#6c757d; color:#fff; font-size:11px; padding:3px 10px; border-radius:20px; }

/* Admin panel */
.pe-admin-panel { background:#f8f9fa; border:1px solid #dee2e6; border-radius:10px; padding:18px; margin-bottom:20px; }
.pe-admin-panel h6 { font-weight:700; margin-bottom:12px; }

/* Participants table */
.pe-participants-table { width:100%; border-collapse:collapse; font-size:13px; }
.pe-participants-table th { background:#e9ecef; padding:7px 10px; text-align:left; }
.pe-participants-table td { padding:7px 10px; border-bottom:1px solid #dee2e6; vertical-align:middle; }
.pe-participants-table tr:last-child td { border-bottom:none; }

/* Evaluation grid */
.pe-grid-wrap { overflow-x:auto; }
.pe-grid { border-collapse:collapse; width:100%; min-width:900px; font-size:12px; }
.pe-grid th { background:#343a40; color:#fff; padding:7px 6px; text-align:center; white-space:nowrap; position:sticky; top:0; z-index:2; }
.pe-grid th.pe-col-name { text-align:left; min-width:130px; background:#212529; }
.pe-grid th.pe-section-hdr { background:#495057; font-size:11px; font-weight:600; letter-spacing:.4px; }
.pe-grid td { padding:5px 4px; border:1px solid #dee2e6; vertical-align:middle; text-align:center; }
.pe-grid td.pe-col-name { text-align:left; font-weight:600; padding:5px 10px; background:#f8f9fa; position:sticky; left:0; z-index:1; white-space:nowrap; }
.pe-grid tr:hover td { background:#fff9db; }
.pe-grid tr:hover td.pe-col-name { background:#fff3cd; }

/* Cell controls */
.pe-cell { position:relative; min-width:80px; }
.pe-select { width:100%; font-size:11px; padding:3px 2px; border:1px solid #ced4da; border-radius:4px; background:#fff; }
.pe-skip-lbl { display:flex; align-items:center; justify-content:center; gap:3px; font-size:10px; color:#6c757d; margin-top:3px; cursor:pointer; }
.pe-skip-lbl input { cursor:pointer; }
.pe-cell.is-skip .pe-select { opacity:.35; pointer-events:none; }
.pe-cell.is-skip { background:#f0f0f0; }

/* Text area cells */
.pe-textarea { width:100%; font-size:11px; border:1px solid #ced4da; border-radius:4px; padding:4px; resize:vertical; min-height:52px; }

/* Section group header in grid */
.pe-grid th.pe-field-hdr { font-size:10px; max-width:70px; white-space:normal; text-align:center; line-height:1.2; padding:4px 3px; }

/* Save status */
.pe-save-status { font-size:11px; color:#6c757d; margin-left:8px; }
.pe-save-status.saving { color:#0d6efd; }
.pe-save-status.saved  { color:#198754; }
.pe-save-status.error  { color:#dc3545; }

/* Evaluatee accordion */
.pe-person-row { cursor:pointer; }
.pe-person-row td.pe-col-name::before { content:'▶ '; font-size:9px; color:#6c757d; }
.pe-person-row.open td.pe-col-name::before { content:'▼ '; }
.pe-detail-row { display:none; }
.pe-detail-row.open { display:table-row; }
.pe-detail-panel { padding:16px; background:#fffdf0; }

/* Legend */
.pe-legend { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; font-size:11px; }
.pe-legend-item { background:#f1f3f5; border:1px solid #dee2e6; border-radius:4px; padding:2px 8px; }
</style>

<div class="pe-wrap">

  <!-- ── Header ── -->
  <div class="pe-header">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <h5 class="pe-title">👥 Avaliação entre Pares</h5>
      <?php if ($campaign): ?>
        <span class="fw-semibold text-muted" style="font-size:13px"><?= htmlspecialchars($campaign['title']) ?> <?= htmlspecialchars($campaign['year_label'] ?? '') ?></span>
        <?php if ($campaign['is_public']): ?>
          <span class="pe-badge-public">🔓 Pública</span>
        <?php else: ?>
          <span class="pe-badge-draft">🔒 Rascunho</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($is_admin): ?>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" onclick="peAdminToggle()">⚙ Gerir Campanha</button>
      <?php if ($campaign): ?>
        <button class="btn btn-sm <?= $campaign['is_public'] ? 'btn-warning' : 'btn-success' ?>" onclick="peTogglePublic(<?= $campaign['id'] ?>,<?= $campaign['is_public'] ? 0 : 1 ?>)">
          <?= $campaign['is_public'] ? '🔒 Tornar Rascunho' : '🔓 Tornar Pública' ?>
        </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($is_admin): ?>
  <!-- ── Painel Admin ── -->
  <div class="pe-admin-panel" id="pe-admin-panel" style="display:none">
    <div class="row g-3">
      <!-- Nova campanha -->
      <div class="col-md-5">
        <h6>📋 Nova Campanha</h6>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control" id="pe-new-title" placeholder="Título (ex: Avaliação Anual)" value="Avaliação entre Pares">
          <input type="text" class="form-control" id="pe-new-year" placeholder="Ano" value="<?= date('Y') ?>" style="max-width:80px">
          <button class="btn btn-primary" onclick="peCreateCampaign()">Criar</button>
        </div>
        <?php if (count($all_campaigns) > 0): ?>
        <small class="text-muted">Campanhas anteriores:</small>
        <ul class="list-group list-group-flush mt-1" style="font-size:12px">
          <?php foreach ($all_campaigns as $c): ?>
          <li class="list-group-item py-1 px-2 d-flex justify-content-between align-items-center">
            <span><?= htmlspecialchars($c['title'].' '.($c['year_label']??'')) ?> <?= $c['is_active'] ? '<span class="badge bg-success">Ativa</span>' : '' ?></span>
            <small class="text-muted"><?= date('d/m/Y',strtotime($c['created_at'])) ?></small>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

      <?php if ($campaign): ?>
      <!-- Participantes -->
      <div class="col-md-7">
        <h6>👤 Participantes — <?= htmlspecialchars($campaign['title']) ?></h6>
        <div class="d-flex gap-2 mb-2 flex-wrap">
          <select id="pe-add-user" class="form-select form-select-sm" style="max-width:200px">
            <option value="">Utilizador...</option>
          </select>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" id="pe-chk-evaluatee" checked>
            <label class="form-check-label" for="pe-chk-evaluatee" style="font-size:12px">Avaliado</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" id="pe-chk-evaluator" checked>
            <label class="form-check-label" for="pe-chk-evaluator" style="font-size:12px">Avaliador</label>
          </div>
          <button class="btn btn-sm btn-outline-primary" onclick="peAddParticipant(<?= $campaign['id'] ?>)">+ Adicionar</button>
        </div>

        <table class="pe-participants-table">
          <thead><tr><th>Utilizador</th><th>Avaliado</th><th>Avaliador</th><th></th></tr></thead>
          <tbody id="pe-participants-tbody">
            <?php foreach ($participants as $p): ?>
            <tr id="pe-part-<?= $p['id'] ?>">
              <td><?= htmlspecialchars($p['username']) ?></td>
              <td><?= $p['can_be_evaluated'] ? '✅' : '—' ?></td>
              <td><?= $p['can_evaluate'] ? '✅' : '—' ?></td>
              <td><button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:1px 6px" onclick="peRemoveParticipant(<?= $campaign['id'] ?>,<?= $p['id'] ?>)">✕</button></td>
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
    <div class="alert alert-info">Nenhuma campanha ativa. <?= $is_admin ? 'Cria uma nova campanha acima.' : 'Aguarda que um administrador crie uma campanha.' ?></div>

  <?php elseif (!$can_view): ?>
    <div class="alert alert-secondary"><i class="bi bi-lock-fill"></i> A avaliação ainda não foi tornada pública. Aguarda a autorização do administrador.</div>

  <?php else: ?>
  <!-- ── Grid de avaliação ── -->
  <?php
  // Determine what to show
  $is_evaluator   = $my_role && $my_role['can_evaluate'];
  $is_evaluatee   = $my_role && $my_role['can_be_evaluated'];
  $show_all       = $is_admin;   // admin sees all
  $show_own_only  = !$is_admin && $is_evaluatee && !$is_evaluator; // only see own results
  ?>

  <?php if ($is_evaluator || $is_admin): ?>
  <div class="mb-2 d-flex align-items-center gap-2 flex-wrap">
    <strong style="font-size:13px">📝 Formulário de Avaliação</strong>
    <?php if ($is_evaluator && !$is_admin): ?>
      <span class="text-muted" style="font-size:12px">A avaliar como: <strong><?= htmlspecialchars($cur_user) ?></strong></span>
    <?php elseif ($is_admin): ?>
      <span class="text-muted" style="font-size:12px">Vista administrador (todas as avaliações)</span>
    <?php endif; ?>
    <span class="pe-save-status" id="pe-save-status"></span>
  </div>

  <!-- Legenda -->
  <div class="pe-legend">
    <?php foreach (['funcoes','atitude'] as $sk): ?>
      <?php foreach ($SECTIONS[$sk]['scale'] as $v=>$l): ?>
      <span class="pe-legend-item"><strong><?= $v ?></strong>: <?= htmlspecialchars($l) ?></span>
      <?php endforeach; ?>
      <span class="pe-legend-item" style="background:#e8f4fd">|</span>
    <?php endforeach; ?>
  </div>

  <div class="pe-grid-wrap">
  <table class="pe-grid" id="pe-grid">
    <thead>
      <!-- Linha 1: secções -->
      <tr>
        <th class="pe-col-name" rowspan="2">Avaliado</th>
        <?php foreach ($SECTIONS as $sk=>$sec): ?>
          <th class="pe-section-hdr" colspan="<?= count($sec['fields']) ?>"><?= htmlspecialchars($sec['title']) ?></th>
        <?php endforeach; ?>
      </tr>
      <!-- Linha 2: campos -->
      <tr>
        <?php foreach ($SECTIONS as $sk=>$sec): ?>
          <?php foreach ($sec['fields'] as $fk=>$fl): ?>
          <th class="pe-field-hdr" title="<?= htmlspecialchars($fl) ?>"><?= htmlspecialchars(mb_strimwidth($fl,0,30,'…')) ?></th>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php
    // For admin: show one row per evaluatee+evaluator pair? No — show evaluatees as rows
    // The evaluator (logged-in) fills the row for each evaluatee
    // Admin sees... the matrix per evaluator? For now admin sees MY own form (self as evaluator)
    // We can extend later with evaluator selector

    $show_evaluatees = $evaluatees;
    // Exclude self from evaluatees if current user is evaluator (can't self-evaluate) — optional
    // Actually, allow self-evaluation if in the list

    foreach ($show_evaluatees as $evaluatee):
        if ($evaluatee['user_id'] == $cur_uid && !$is_admin) continue; // Skip self if not admin
        $eid = $evaluatee['user_id'];
        $row_id = "pe-row-$eid";
        $det_id = "pe-det-$eid";
    ?>
    <tr class="pe-person-row" id="<?= $row_id ?>" onclick="peToggleDetail(<?= $eid ?>)">
      <td class="pe-col-name"><?= htmlspecialchars($evaluatee['username']) ?></td>
      <?php foreach ($SECTIONS as $sk=>$sec): ?>
        <?php foreach ($sec['fields'] as $fk=>$fl):
          $resp = $my_responses[$eid][$fk] ?? null;
          $val  = $resp['field_value'] ?? '';
          $skip = $resp['is_skip'] ?? 0;
          $is_txt = in_array($fk,$TEXT_FIELDS);
        ?>
        <td class="pe-cell <?= $skip ? 'is-skip' : '' ?>" id="cell-<?= $eid ?>-<?= $fk ?>">
          <?php if ($is_txt): ?>
            <span style="font-size:10px;color:#6c757d"><?= $val ? '✏️' : '—' ?></span>
          <?php elseif (!empty($sec['scale'])): ?>
            <span class="badge <?= $val=='A'?'bg-success':($val=='B'?'bg-primary':($val=='C'?'bg-warning text-dark':($val=='D'?'bg-danger':'bg-light text-dark'))) ?>" style="font-size:11px"><?= $skip ? 'N/A' : ($val ?: '—') ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tr>
    <!-- Detail row for this evaluatee -->
    <tr class="pe-detail-row" id="<?= $det_id ?>">
      <td colspan="<?= 1 + array_sum(array_map(fn($s)=>count($s['fields']),$SECTIONS)) ?>">
        <div class="pe-detail-panel">
          <h6 class="mb-3">✏️ Avaliação de <strong><?= htmlspecialchars($evaluatee['username']) ?></strong></h6>
          <?php foreach ($SECTIONS as $sk=>$sec): ?>
          <div class="mb-4">
            <div class="fw-bold mb-2" style="font-size:13px;border-bottom:1px solid #dee2e6;padding-bottom:4px"><?= htmlspecialchars($sec['title']) ?></div>
            <?php if (!empty($sec['scale'])): ?>
            <div class="mb-2 pe-legend" style="font-size:11px">
              <?php foreach ($sec['scale'] as $v=>$l): ?>
              <span class="pe-legend-item"><strong><?= $v ?></strong>: <?= htmlspecialchars($l) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="row g-2">
            <?php foreach ($sec['fields'] as $fk=>$fl):
              $resp = $my_responses[$eid][$fk] ?? null;
              $val  = $resp['field_value'] ?? '';
              $skip = (int)($resp['is_skip'] ?? 0);
              $is_txt = in_array($fk,$TEXT_FIELDS);
            ?>
            <div class="col-md-6 col-lg-4">
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px"><?= htmlspecialchars($fl) ?></label>
              <?php if ($is_txt): ?>
                <textarea class="pe-textarea"
                  onchange="peSave(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',this.value,0)"
                  placeholder="Comentário…"><?= htmlspecialchars($val) ?></textarea>
              <?php else: ?>
                <div class="pe-cell <?= $skip?'is-skip':'' ?>" id="dc-<?= $eid ?>-<?= $fk ?>">
                  <select class="pe-select"
                    onchange="peSave(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',this.value,<?= $skip ?>)"
                    <?= $skip ? 'disabled' : '' ?>>
                    <option value="">— Selecionar —</option>
                    <?php foreach ($sec['scale'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $val==$v?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="pe-skip-lbl">
                    <input type="checkbox" <?= $skip?'checked':'' ?>
                      onchange="peSkip(<?= $campaign['id'] ?>,<?= $eid ?>,'<?= $fk ?>',this)"> Não avalia
                  </label>
                </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; /* is_evaluator || is_admin */ ?>

  <?php if ($is_evaluatee && $campaign['is_public'] && !$is_admin): ?>
  <!-- Resultados para o próprio avaliado -->
  <div class="mt-4">
    <h6>📊 Os meus resultados</h6>
    <?php
    $s = $pdo->prepare("SELECT field_key, field_value, is_skip FROM peer_eval_responses WHERE campaign_id=? AND evaluatee_id=? AND is_skip=0 AND field_value!=''");
    $s->execute([$cid,$cur_uid]);
    $my_evals = $s->fetchAll(PDO::FETCH_ASSOC);
    $by_field = [];
    foreach ($my_evals as $r) $by_field[$r['field_key']][] = $r['field_value'];

    $abcd_ord = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
    ?>
    <div class="table-responsive">
    <table class="table table-sm table-bordered" style="font-size:12px">
      <thead><tr><th>Parâmetro</th><th>Avaliações</th><th>Moda</th></tr></thead>
      <tbody>
      <?php foreach ($all_fields as $fk=>$fi):
        if ($fi['is_text']) continue;
        $vals = $by_field[$fk] ?? [];
        if (empty($vals)) continue;
        $freq = array_count_values($vals);
        arsort($freq);
        $moda = array_key_first($freq);
        $tags = '';
        foreach ($vals as $v) $tags .= '<span class="badge '.($v=='A'?'bg-success':($v=='B'?'bg-primary':($v=='C'?'bg-warning text-dark':'bg-danger'))).'">'.$v.'</span> ';
      ?>
      <tr>
        <td><?= htmlspecialchars($fi['label']) ?></td>
        <td><?= $tags ?></td>
        <td><span class="badge <?= $moda=='A'?'bg-success':($moda=='B'?'bg-primary':($moda=='C'?'bg-warning text-dark':'bg-danger')) ?>"><?= $moda ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; /* can_view */ ?>
</div>

<script>
const PE_URL = '<?= htmlspecialchars(dirname($_SERVER['PHP_SELF'])) ?>/peer_eval_ajax.php';

// ── Admin ──
function peAdminToggle() {
    const p = document.getElementById('pe-admin-panel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
}

function peCreateCampaign() {
    const title = document.getElementById('pe-new-title').value.trim();
    const year  = document.getElementById('pe-new-year').value.trim();
    if (!title) return alert('Título obrigatório');
    pePost({pe_action:'create_campaign',title,year}, () => location.reload());
}

function peTogglePublic(cid, val) {
    if (!confirm(val ? 'Tornar avaliação pública para todos os participantes?' : 'Voltar a rascunho?')) return;
    pePost({pe_action:'toggle_public',campaign_id:cid,is_public:val}, () => location.reload());
}

async function peAddParticipant(cid) {
    const sel   = document.getElementById('pe-add-user');
    const uid   = sel.value;
    const uname = sel.options[sel.selectedIndex]?.text;
    if (!uid) return alert('Seleciona um utilizador');
    const can_ev  = document.getElementById('pe-chk-evaluator').checked  ? 1 : 0;
    const can_be  = document.getElementById('pe-chk-evaluatee').checked  ? 1 : 0;
    if (!can_ev && !can_be) return alert('Seleciona pelo menos um papel');
    pePost({pe_action:'add_participant',campaign_id:cid,user_id:uid,username:uname,can_evaluate:can_ev,can_be_evaluated:can_be}, () => location.reload());
}

function peRemoveParticipant(cid, pid) {
    if (!confirm('Remover participante?')) return;
    pePost({pe_action:'remove_participant',campaign_id:cid,participant_id:pid}, () => {
        const row = document.getElementById('pe-part-'+pid);
        if (row) row.remove();
    });
}

// Load user list for admin select
(function() {
    const sel = document.getElementById('pe-add-user');
    if (!sel) return;
    pePost({pe_action:'get_users'}, data => {
        if (!data.ok) return;
        data.users.forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.user_id;
            opt.textContent = u.username;
            sel.appendChild(opt);
        });
    });
})();

// ── Grid ──
function peToggleDetail(eid) {
    const row  = document.getElementById('pe-row-'+eid);
    const det  = document.getElementById('pe-det-'+eid);
    const open = det.classList.contains('open');
    // Close all
    document.querySelectorAll('.pe-detail-row.open').forEach(r=>r.classList.remove('open'));
    document.querySelectorAll('.pe-person-row.open').forEach(r=>r.classList.remove('open'));
    if (!open) { det.classList.add('open'); row.classList.add('open'); }
}

let _saveTimer = null;
function peSave(cid, eid, fkey, val, skip) {
    const st = document.getElementById('pe-save-status');
    if (st) { st.textContent='A guardar…'; st.className='pe-save-status saving'; }
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(() => {
        pePost({pe_action:'save_response',campaign_id:cid,evaluatee_id:eid,field_key:fkey,field_value:val,is_skip:skip}, data => {
            if (st) {
                st.textContent = data.ok ? '✓ Guardado' : '✗ '+data.msg;
                st.className   = 'pe-save-status '+(data.ok?'saved':'error');
                setTimeout(()=>{ if(st) { st.textContent=''; st.className='pe-save-status'; } }, 3000);
            }
            if (data.ok) peUpdateSummaryCell(eid, fkey, val, skip);
        });
    }, 600);
}

function peSkip(cid, eid, fkey, chk) {
    const cell = document.getElementById('dc-'+eid+'-'+fkey);
    const sel  = cell?.querySelector('.pe-select');
    const skip = chk.checked ? 1 : 0;
    if (skip) { cell?.classList.add('is-skip'); if(sel){sel.disabled=true;} }
    else       { cell?.classList.remove('is-skip'); if(sel){sel.disabled=false;} }
    peSave(cid, eid, fkey, sel?.value || '', skip);
}

function peUpdateSummaryCell(eid, fkey, val, skip) {
    const cell = document.getElementById('cell-'+eid+'-'+fkey);
    if (!cell) return;
    const badges = {A:'bg-success',B:'bg-primary',C:'bg-warning text-dark',D:'bg-danger'};
    if (skip) {
        cell.innerHTML='<span class="badge bg-light text-dark" style="font-size:11px">N/A</span>';
    } else if (val && badges[val]) {
        cell.innerHTML=`<span class="badge ${badges[val]}" style="font-size:11px">${val}</span>`;
    } else {
        cell.innerHTML='<span style="color:#999">—</span>';
    }
}

// ── Generic POST ──
function pePost(data, cb) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k,v);
    fetch(PE_URL, {method:'POST',body:fd})
        .then(r=>r.json()).then(d=> cb && cb(d))
        .catch(e=>console.error('pe error',e));
}
</script>
