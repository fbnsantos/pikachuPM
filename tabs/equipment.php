<?php
// tabs/equipment.php — Gestor de Equipamentos e Demonstradores

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('<div class="alert alert-danger">Erro de conexão BD</div>');
}

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);

// ── Tabelas ────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS equipment_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class ENUM('equipamento','demonstrador','infraestrutura') NOT NULL DEFAULT 'equipamento',
        name VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        quantity INT DEFAULT 1,
        responsible VARCHAR(150) DEFAULT NULL,
        responsible_uid INT DEFAULT NULL,
        status ENUM('funcional','parcial','inativo','manutencao') NOT NULL DEFAULT 'funcional',
        prototype_refs JSON DEFAULT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_class (class),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS equipment_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipment_id INT NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
        status ENUM('aberto','em_progresso','resolvido') NOT NULL DEFAULT 'aberto',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_eq (equipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS equipment_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipment_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        is_photo TINYINT(1) DEFAULT 0,
        uploaded_by INT NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_eq (equipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Utilizadores para dropdown de responsável
$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$EQ_URL = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/equipment_ajax.php';
$UPLOAD_URL = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/uploads/equipment/';
?>

<style>
.eq-wrap{padding:0 0 60px}

/* Filter bar */
.eq-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
.eq-filter .btn-group .btn{font-size:12px;padding:4px 12px}

/* Status badge */
.eq-status{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600}
.eq-status-funcional{background:#d1e7dd;color:#0a3622}
.eq-status-parcial{background:#fff3cd;color:#664d03}
.eq-status-inativo{background:#f8d7da;color:#58151c}
.eq-status-manutencao{background:#cff4fc;color:#055160}

/* Class badge */
.eq-class{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600}
.eq-class-equipamento{background:#e0cffc;color:#3d0a91}
.eq-class-demonstrador{background:#d2f4ea;color:#0a3622}
.eq-class-infraestrutura{background:#fde8d8;color:#842029}

/* Cards */
.eq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.eq-card{background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:16px;cursor:pointer;transition:box-shadow .15s,transform .1s;display:flex;flex-direction:column;gap:8px}
.eq-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.12);transform:translateY(-2px)}
.eq-card-header{display:flex;align-items:flex-start;gap:10px}
.eq-icon{font-size:28px;line-height:1;flex-shrink:0}
.eq-card-title{font-weight:700;font-size:14px;line-height:1.3;flex:1}
.eq-card-badges{display:flex;gap:5px;flex-wrap:wrap}
.eq-card-meta{font-size:11px;color:#6c757d;display:flex;flex-direction:column;gap:3px}
.eq-card-meta span{display:flex;align-items:center;gap:5px}
.eq-card-footer{display:flex;gap:6px;margin-top:4px;padding-top:8px;border-top:1px solid #f0f0f0}
.eq-issue-badge{font-size:11px;border-radius:10px;padding:2px 8px;font-weight:600}
.eq-empty{text-align:center;padding:60px 20px;color:#6c757d}
.eq-empty-icon{font-size:56px;margin-bottom:12px}

/* Priority dots */
.prio-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px}
.prio-critica{background:#dc3545}.prio-alta{background:#fd7e14}
.prio-media{background:#ffc107}.prio-baixa{background:#20c997}

/* Drawer */
.eq-drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:800;display:none}
.eq-drawer{position:fixed;top:0;right:0;width:min(600px,100vw);height:100vh;background:#fff;z-index:801;display:flex;flex-direction:column;box-shadow:-4px 0 20px rgba(0,0,0,.15);transform:translateX(100%);transition:transform .25s ease}
.eq-drawer.open{transform:translateX(0)}
.eq-drawer-head{padding:16px 20px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;gap:10px;flex-shrink:0}
.eq-drawer-head h5{margin:0;font-size:16px;font-weight:700;flex:1}
.eq-drawer-body{flex:1;overflow-y:auto;padding:20px}
.eq-drawer-sect{margin-bottom:20px}
.eq-drawer-sect h6{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:10px;padding-bottom:5px;border-bottom:1px solid #f0f0f0}

/* Issue list */
.eq-issue-item{background:#f8f9fa;border-radius:8px;padding:10px 12px;margin-bottom:8px;border-left:3px solid #dee2e6}
.eq-issue-item.prio-critica-border{border-color:#dc3545}
.eq-issue-item.prio-alta-border{border-color:#fd7e14}
.eq-issue-item.prio-media-border{border-color:#ffc107}
.eq-issue-item.prio-baixa-border{border-color:#20c997}
.eq-issue-item.status-resolvido{opacity:.5}

/* Attachments */
.eq-attach-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px}
.eq-attach-item{position:relative;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#f8f9fa;cursor:pointer}
.eq-attach-item img{width:100%;height:100%;object-fit:cover}
.eq-attach-item .eq-attach-del{position:absolute;top:3px;right:3px;background:rgba(220,53,69,.85);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1}
.eq-attach-doc{flex-direction:column;gap:4px;font-size:10px;text-align:center;padding:4px}
.eq-attach-doc-icon{font-size:24px}
.eq-attach-doc-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;padding:0 4px;font-size:9px;color:#495057}

/* Proto refs */
.eq-ref-item{display:flex;align-items:center;gap:8px;margin-bottom:6px;background:#f8f9fa;border-radius:6px;padding:6px 10px;font-size:12px}
.eq-ref-item a{color:#0d6efd;text-decoration:none}
.eq-ref-item a:hover{text-decoration:underline}

/* Upload drop zone */
.eq-dropzone{border:2px dashed #ced4da;border-radius:8px;padding:20px;text-align:center;font-size:12px;color:#6c757d;cursor:pointer;transition:border-color .15s}
.eq-dropzone:hover,.eq-dropzone.drag{border-color:#0d6efd;background:#f0f7ff;color:#0d6efd}

/* Form modal */
.eq-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;display:flex;align-items:center;justify-content:center}
.eq-modal{background:#fff;border-radius:12px;width:min(580px,96vw);max-height:90vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)}
.eq-modal-head{padding:16px 20px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1}
.eq-modal-body{padding:20px}
</style>

<div class="eq-wrap">

<!-- Header -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h5 class="mb-0 fw-bold">🔧 Equipamentos &amp; Demonstradores</h5>
  <button class="btn btn-primary btn-sm" onclick="eqOpenForm()">+ Novo</button>
</div>

<!-- Filtros -->
<div class="eq-filter">
  <div class="btn-group" id="eq-class-filter" role="group">
    <button class="btn btn-outline-secondary active" data-class="">Todos</button>
    <button class="btn btn-outline-secondary" data-class="equipamento">🔧 Equipamento</button>
    <button class="btn btn-outline-secondary" data-class="demonstrador">🎯 Demonstrador</button>
    <button class="btn btn-outline-secondary" data-class="infraestrutura">🏗️ Infraestrutura</button>
  </div>
  <div class="btn-group" id="eq-status-filter" role="group">
    <button class="btn btn-outline-secondary active" data-status="">Qualquer estado</button>
    <button class="btn btn-outline-secondary" data-status="funcional">✅ Funcional</button>
    <button class="btn btn-outline-secondary" data-status="parcial">⚠️ Parcial</button>
    <button class="btn btn-outline-secondary" data-status="manutencao">🔄 Manutenção</button>
    <button class="btn btn-outline-secondary" data-status="inativo">❌ Inativo</button>
  </div>
  <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="eqLoad()" title="Actualizar"><i class="bi bi-arrow-clockwise"></i></button>
</div>

<!-- Grid de cards -->
<div id="eq-grid" class="eq-grid">
  <div class="eq-empty"><div class="eq-empty-icon">🔧</div><p>A carregar…</p></div>
</div>

</div>

<!-- ── Drawer de detalhe ── -->
<div class="eq-drawer-overlay" id="eq-overlay" onclick="eqCloseDrawer()"></div>
<div class="eq-drawer" id="eq-drawer">
  <div class="eq-drawer-head">
    <span id="eq-drawer-icon" style="font-size:24px"></span>
    <h5 id="eq-drawer-title">—</h5>
    <button class="btn btn-sm btn-outline-primary" id="eq-edit-btn" onclick="eqEditCurrent()">✏️ Editar</button>
    <button class="btn-close" onclick="eqCloseDrawer()"></button>
  </div>
  <div class="eq-drawer-body" id="eq-drawer-body">
    <p class="text-muted">A carregar…</p>
  </div>
</div>

<!-- ── Modal de formulário ── -->
<div class="eq-modal-overlay" id="eq-modal-overlay" style="display:none" onclick="if(event.target===this)eqCloseForm()">
<div class="eq-modal" id="eq-modal">
  <div class="eq-modal-head">
    <span id="eq-modal-title" class="fw-bold">Novo item</span>
    <button class="btn-close" onclick="eqCloseForm()"></button>
  </div>
  <div class="eq-modal-body">
    <input type="hidden" id="eq-form-id" value="">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Nome *</label>
        <input type="text" class="form-control" id="eq-f-name" placeholder="Nome do equipamento ou demonstrador">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Classe *</label>
        <select class="form-select" id="eq-f-class">
          <option value="equipamento">🔧 Equipamento</option>
          <option value="demonstrador">🎯 Demonstrador</option>
          <option value="infraestrutura">🏗️ Infraestrutura</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Estado</label>
        <select class="form-select" id="eq-f-status">
          <option value="funcional">✅ Funcional</option>
          <option value="parcial">⚠️ Parcialmente funcional</option>
          <option value="manutencao">🔄 Em manutenção</option>
          <option value="inativo">❌ Inativo</option>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold">Responsável</label>
        <select class="form-select" id="eq-f-resp-uid" onchange="eqRespUidChange()">
          <option value="">— Selecionar utilizador —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Quantidade</label>
        <input type="number" class="form-control" id="eq-f-qty" value="1" min="1">
      </div>
      <div class="col-12" id="eq-f-resp-custom-wrap" style="display:none">
        <label class="form-label">Responsável (outro)</label>
        <input type="text" class="form-control" id="eq-f-resp" placeholder="Nome do responsável externo">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Local</label>
        <input type="text" class="form-control" id="eq-f-location" placeholder="Ex: Lab 2.08, Armazém, etc.">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Descrição</label>
        <textarea class="form-control" id="eq-f-desc" rows="3" placeholder="Descrição do equipamento, características, etc."></textarea>
      </div>

      <!-- Referências a protótipos -->
      <div class="col-12" id="eq-refs-section">
        <label class="form-label fw-semibold">Referências a Protótipos</label>
        <div id="eq-refs-list"></div>
        <div class="d-flex gap-2 mt-2">
          <input type="text" class="form-control form-control-sm" id="eq-new-ref-title" placeholder="Nome do protótipo">
          <input type="text" class="form-control form-control-sm" id="eq-new-ref-url" placeholder="URL ou ID (opcional)">
          <button class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="eqAddRef()">+ Ref</button>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mt-4 pt-3 border-top">
      <button class="btn btn-primary" onclick="eqSaveForm()">Guardar</button>
      <button class="btn btn-outline-secondary" onclick="eqCloseForm()">Cancelar</button>
      <button class="btn btn-outline-danger ms-auto" id="eq-delete-btn" style="display:none" onclick="eqDeleteCurrent()">🗑 Eliminar</button>
    </div>
  </div>
</div>
</div>

<script>
const EQ_URL    = '<?= htmlspecialchars($EQ_URL) ?>';
const EQ_UPLOAD = '<?= htmlspecialchars($UPLOAD_URL) ?>';

const CLASS_ICON   = {equipamento:'🔧', demonstrador:'🎯', infraestrutura:'🏗️'};
const CLASS_LABEL  = {equipamento:'Equipamento', demonstrador:'Demonstrador', infraestrutura:'Infraestrutura'};
const STATUS_LABEL = {funcional:'✅ Funcional', parcial:'⚠️ Parcial', inativo:'❌ Inativo', manutencao:'🔄 Manutenção'};
const PRIO_LABEL   = {baixa:'Baixa', media:'Média', alta:'Alta', critica:'Crítica'};
const STAT_LABEL   = {aberto:'Aberto', em_progresso:'Em progresso', resolvido:'Resolvido'};

let _eqFilter     = {class:'', status:''};
let _eqCurrentId  = null;
let _eqCurrentItem = null;
let _eqRefs       = [];

// ── Filtros ──────────────────────────────────────────────────────────────
document.getElementById('eq-class-filter').addEventListener('click', e => {
    const btn = e.target.closest('button[data-class]');
    if (!btn) return;
    document.querySelectorAll('#eq-class-filter button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _eqFilter.class = btn.dataset.class;
    eqLoad();
});
document.getElementById('eq-status-filter').addEventListener('click', e => {
    const btn = e.target.closest('button[data-status]');
    if (!btn) return;
    document.querySelectorAll('#eq-status-filter button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _eqFilter.status = btn.dataset.status;
    eqLoad();
});

// ── Carregar cards ────────────────────────────────────────────────────────
function eqLoad() {
    const p = new URLSearchParams({eq_action:'list', class:_eqFilter.class, status:_eqFilter.status});
    fetch(EQ_URL+'?'+p).then(r=>r.json()).then(d=>{
        if (!d.ok) return;
        const grid = document.getElementById('eq-grid');
        if (!d.items.length) {
            grid.innerHTML = `<div class="eq-empty"><div class="eq-empty-icon">📦</div><p>Nenhum item encontrado. Clica em <strong>+ Novo</strong> para adicionar.</p></div>`;
            return;
        }
        grid.innerHTML = d.items.map(eqCardHtml).join('');
        grid.querySelectorAll('.eq-card').forEach(c => {
            c.addEventListener('click', () => eqOpenDrawer(parseInt(c.dataset.id)));
        });
    });
}

function eqCardHtml(it) {
    const icon    = CLASS_ICON[it.class] || '📦';
    const cls_lbl = `<span class="eq-class eq-class-${it.class}">${CLASS_LABEL[it.class]}</span>`;
    const st_lbl  = `<span class="eq-status eq-status-${it.status}">${STATUS_LABEL[it.status]}</span>`;
    const issues  = it.open_issues > 0
        ? `<span class="eq-issue-badge bg-danger text-white">${it.open_issues} problema${it.open_issues>1?'s':''}</span>` : '';
    const attach  = it.attach_count > 0
        ? `<span class="eq-issue-badge bg-light text-secondary">📎 ${it.attach_count}</span>` : '';
    const refs    = it.prototype_refs?.length
        ? `<span class="eq-issue-badge bg-light text-secondary">🔗 ${it.prototype_refs.length} proto${it.prototype_refs.length>1?'s':''}</span>` : '';
    return `<div class="eq-card" data-id="${it.id}">
      <div class="eq-card-header">
        <span class="eq-icon">${icon}</span>
        <div style="flex:1">
          <div class="eq-card-title">${eHtml(it.name)}</div>
          <div class="eq-card-badges mt-1">${cls_lbl} ${st_lbl}</div>
        </div>
      </div>
      <div class="eq-card-meta">
        ${it.location ? `<span>📍 ${eHtml(it.location)}</span>` : ''}
        ${it.responsible ? `<span>👤 ${eHtml(it.responsible)}</span>` : ''}
        ${it.quantity > 1 ? `<span>📦 Quantidade: ${it.quantity}</span>` : ''}
      </div>
      ${issues||attach||refs ? `<div class="eq-card-footer">${issues}${attach}${refs}</div>` : ''}
    </div>`;
}

// ── Drawer de detalhe ────────────────────────────────────────────────────
function eqOpenDrawer(id) {
    _eqCurrentId = id;
    document.getElementById('eq-overlay').style.display = 'block';
    const drawer = document.getElementById('eq-drawer');
    drawer.classList.add('open');
    document.getElementById('eq-drawer-body').innerHTML = '<p class="text-muted text-center py-4">A carregar…</p>';
    fetch(EQ_URL+'?eq_action=get&id='+id).then(r=>r.json()).then(d=>{
        if (!d.ok) return;
        _eqCurrentItem = d.item;
        renderDrawer(d.item);
    });
}
function eqCloseDrawer() {
    document.getElementById('eq-drawer').classList.remove('open');
    document.getElementById('eq-overlay').style.display = 'none';
    _eqCurrentId = null;
    _eqCurrentItem = null;
}
function eqEditCurrent() {
    if (_eqCurrentItem) eqOpenForm(_eqCurrentItem);
}

function renderDrawer(it) {
    document.getElementById('eq-drawer-icon').textContent = CLASS_ICON[it.class]||'📦';
    document.getElementById('eq-drawer-title').textContent = it.name;

    const prioColor = {baixa:'#20c997',media:'#ffc107',alta:'#fd7e14',critica:'#dc3545'};
    const statusBg  = {aberto:'#f8d7da',em_progresso:'#fff3cd',resolvido:'#d1e7dd'};
    const statusTxt = {aberto:'#58151c',em_progresso:'#664d03',resolvido:'#0a3622'};

    // Issues actions (add)
    const issueHtml = `
      <div id="eq-issues-list">
        ${it.issues.length ? it.issues.map(iss => renderIssue(iss)).join('') : '<p class="text-muted" style="font-size:12px">Sem problemas registados.</p>'}
      </div>
      <div class="d-flex gap-2 mt-2">
        <select class="form-select form-select-sm" id="new-issue-prio" style="max-width:120px">
          <option value="baixa">Baixa</option>
          <option value="media" selected>Média</option>
          <option value="alta">Alta</option>
          <option value="critica">Crítica</option>
        </select>
        <input type="text" class="form-control form-control-sm" id="new-issue-desc" placeholder="Descreve o problema ou necessidade…">
        <button class="btn btn-sm btn-outline-danger flex-shrink-0" onclick="eqAddIssue(${it.id})">+ Problema</button>
      </div>`;

    // Attachments
    const photoHtml = it.attachments.filter(a=>a.is_photo).map(a=>`
      <div class="eq-attach-item" title="${eHtml(a.original_name)}">
        <img src="${EQ_UPLOAD}${eHtml(a.filename)}" onclick="window.open('${EQ_UPLOAD}${eHtml(a.filename)}','_blank')" style="cursor:zoom-in">
        <button class="eq-attach-del" onclick="event.stopPropagation();eqDelAttach(${a.id})" title="Remover">✕</button>
      </div>`).join('');
    const docHtml = it.attachments.filter(a=>!a.is_photo).map(a=>`
      <div class="eq-attach-item eq-attach-doc" onclick="window.open('${EQ_UPLOAD}${eHtml(a.filename)}','_blank')">
        <span class="eq-attach-doc-icon">📄</span>
        <span class="eq-attach-doc-name" title="${eHtml(a.original_name)}">${eHtml(a.original_name)}</span>
        <button class="eq-attach-del" onclick="event.stopPropagation();eqDelAttach(${a.id})" title="Remover">✕</button>
      </div>`).join('');

    // Prototype refs
    const refHtml = it.prototype_refs?.length ? it.prototype_refs.map(r=>`
      <div class="eq-ref-item">
        🔗 ${r.url ? `<a href="${eHtml(r.url)}" target="_blank">${eHtml(r.title)}</a>` : eHtml(r.title)}
      </div>`).join('') : '<p class="text-muted" style="font-size:12px">Sem referências a protótipos.</p>';

    document.getElementById('eq-drawer-body').innerHTML = `
      <!-- Meta -->
      <div class="eq-drawer-sect">
        <h6>Informação geral</h6>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="eq-class eq-class-${it.class}">${CLASS_LABEL[it.class]}</span>
          <span class="eq-status eq-status-${it.status}">${STATUS_LABEL[it.status]}</span>
        </div>
        ${it.description ? `<p style="font-size:13px;white-space:pre-wrap">${eHtml(it.description)}</p>` : ''}
        <div class="row g-2" style="font-size:13px">
          ${it.location   ? `<div class="col-auto"><span class="text-muted">📍 Local:</span> ${eHtml(it.location)}</div>` : ''}
          ${it.responsible? `<div class="col-auto"><span class="text-muted">👤 Responsável:</span> ${eHtml(it.responsible)}</div>` : ''}
          ${it.quantity>1 ? `<div class="col-auto"><span class="text-muted">📦 Quantidade:</span> ${it.quantity}</div>` : ''}
        </div>
      </div>

      <!-- Refs -->
      ${it.class==='demonstrador' ? `<div class="eq-drawer-sect"><h6>🔗 Referências a Protótipos</h6>${refHtml}</div>` : ''}

      <!-- Problemas -->
      <div class="eq-drawer-sect"><h6>⚠️ Problemas e Necessidades</h6>${issueHtml}</div>

      <!-- Fotografias -->
      <div class="eq-drawer-sect">
        <h6>📸 Fotografias</h6>
        <div class="eq-attach-grid mb-2" id="eq-photos">${photoHtml || '<p class="text-muted" style="font-size:12px">Sem fotografias.</p>'}</div>
        <div class="eq-dropzone" id="eq-photo-drop" onclick="document.getElementById('eq-photo-input').click()" ondragover="eqDrag(event,true)" ondragleave="eqDrag(event,false)" ondrop="eqDrop(event,${it.id},'photo')">
          📸 Clica ou arrasta para adicionar fotos
          <input type="file" id="eq-photo-input" style="display:none" accept="image/*" multiple onchange="eqUploadFiles(this.files,${it.id},'photo')">
        </div>
      </div>

      <!-- Documentos -->
      <div class="eq-drawer-sect">
        <h6>📎 Documentos</h6>
        <div class="eq-attach-grid mb-2" id="eq-docs">${docHtml || '<p class="text-muted" style="font-size:12px">Sem documentos.</p>'}</div>
        <div class="eq-dropzone" id="eq-doc-drop" onclick="document.getElementById('eq-doc-input').click()" ondragover="eqDrag(event,true)" ondragleave="eqDrag(event,false)" ondrop="eqDrop(event,${it.id},'document')">
          📄 Clica ou arrasta para adicionar documentos
          <input type="file" id="eq-doc-input" style="display:none" multiple onchange="eqUploadFiles(this.files,${it.id},'document')">
        </div>
      </div>
    `;
}

function renderIssue(iss) {
    const prioColor = {baixa:'#20c997',media:'#ffc107',alta:'#fd7e14',critica:'#dc3545'};
    const statBadge = {aberto:'bg-danger',em_progresso:'bg-warning text-dark',resolvido:'bg-success'};
    return `<div class="eq-issue-item prio-${iss.priority}-border ${iss.status==='resolvido'?'status-resolvido':''}" id="issue-${iss.id}">
      <div class="d-flex align-items-start gap-2">
        <span class="prio-dot prio-${iss.priority}" title="Prioridade: ${PRIO_LABEL[iss.priority]}" style="margin-top:4px;flex-shrink:0"></span>
        <div style="flex:1;font-size:12px">${eHtml(iss.description)}</div>
        <div class="d-flex gap-1 flex-shrink-0">
          <select class="form-select form-select-sm py-0" style="font-size:10px;width:auto" onchange="eqUpdateIssueStatus(${iss.id},this.value)">
            <option value="aberto" ${iss.status==='aberto'?'selected':''}>Aberto</option>
            <option value="em_progresso" ${iss.status==='em_progresso'?'selected':''}>Em progresso</option>
            <option value="resolvido" ${iss.status==='resolvido'?'selected':''}>Resolvido</option>
          </select>
          <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:10px" onclick="eqDelIssue(${iss.id})">✕</button>
        </div>
      </div>
    </div>`;
}

// ── Issues ────────────────────────────────────────────────────────────────
function eqAddIssue(eid) {
    const desc = document.getElementById('new-issue-desc').value.trim();
    const prio = document.getElementById('new-issue-prio').value;
    if (!desc) return;
    eqPost({eq_action:'add_issue',equipment_id:eid,description:desc,priority:prio}, d=>{
        if (!d.ok) return alert(d.msg);
        document.getElementById('new-issue-desc').value = '';
        eqReloadDrawer(eid);
    });
}
function eqUpdateIssueStatus(id, status) {
    eqPost({eq_action:'update_issue',id,status,description:'_keep',priority:'_keep'}, ()=>{});
    // Visually mark resolved
    const el = document.getElementById('issue-'+id);
    if (el) el.classList.toggle('status-resolvido', status==='resolvido');
}
function eqDelIssue(id) {
    if (!confirm('Remover este problema?')) return;
    eqPost({eq_action:'delete_issue',id}, d=>{
        if (d.ok) document.getElementById('issue-'+id)?.remove();
    });
}

// ── Attachments ───────────────────────────────────────────────────────────
function eqDrag(e, on) {
    e.preventDefault();
    e.currentTarget.classList.toggle('drag', on);
}
function eqDrop(e, eid, type) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag');
    eqUploadFiles(e.dataTransfer.files, eid, type);
}
function eqUploadFiles(files, eid, type) {
    for (const file of files) {
        const fd = new FormData();
        fd.append('eq_action','upload');
        fd.append('equipment_id', eid);
        fd.append('attach_type', type);
        fd.append('file', file);
        fetch(EQ_URL, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if (!d.ok) return alert('Erro ao fazer upload: '+d.msg);
            eqReloadDrawer(eid);
        });
    }
}
function eqDelAttach(id) {
    if (!confirm('Remover este anexo?')) return;
    eqPost({eq_action:'delete_attach',id}, d=>{
        if (d.ok && _eqCurrentId) eqReloadDrawer(_eqCurrentId);
    });
}

function eqReloadDrawer(id) {
    fetch(EQ_URL+'?eq_action=get&id='+id).then(r=>r.json()).then(d=>{
        if (d.ok) { _eqCurrentItem = d.item; renderDrawer(d.item); eqLoad(); }
    });
}

// ── Formulário ────────────────────────────────────────────────────────────
function eqOpenForm(item) {
    _eqRefs = item ? (item.prototype_refs || []) : [];
    document.getElementById('eq-form-id').value     = item ? item.id : '';
    document.getElementById('eq-f-name').value      = item ? item.name : '';
    document.getElementById('eq-f-class').value     = item ? item.class : 'equipamento';
    document.getElementById('eq-f-status').value    = item ? item.status : 'funcional';
    document.getElementById('eq-f-qty').value       = item ? item.quantity : 1;
    document.getElementById('eq-f-location').value  = item ? (item.location||'') : '';
    document.getElementById('eq-f-desc').value      = item ? (item.description||'') : '';
    document.getElementById('eq-f-resp-uid').value  = item ? (item.responsible_uid||'') : '';
    document.getElementById('eq-f-resp').value      = item ? (item.responsible||'') : '';
    document.getElementById('eq-modal-title').textContent = item ? 'Editar: '+item.name : 'Novo item';
    document.getElementById('eq-delete-btn').style.display = item ? '' : 'none';
    eqRespUidChange();
    eqRenderRefs();
    document.getElementById('eq-modal-overlay').style.display = 'flex';
}
function eqCloseForm() {
    document.getElementById('eq-modal-overlay').style.display = 'none';
}
function eqRespUidChange() {
    const uid = document.getElementById('eq-f-resp-uid').value;
    const wrap = document.getElementById('eq-f-resp-custom-wrap');
    wrap.style.display = uid ? 'none' : '';
    if (uid) {
        const sel = document.getElementById('eq-f-resp-uid');
        document.getElementById('eq-f-resp').value = sel.options[sel.selectedIndex].text;
    }
}
function eqSaveForm() {
    const name = document.getElementById('eq-f-name').value.trim();
    if (!name) { document.getElementById('eq-f-name').focus(); return; }
    const data = {
        eq_action: 'save',
        id: document.getElementById('eq-form-id').value,
        name, class: document.getElementById('eq-f-class').value,
        status:   document.getElementById('eq-f-status').value,
        quantity: document.getElementById('eq-f-qty').value,
        location: document.getElementById('eq-f-location').value,
        description: document.getElementById('eq-f-desc').value,
        responsible_uid: document.getElementById('eq-f-resp-uid').value,
        responsible: document.getElementById('eq-f-resp').value,
        prototype_refs: JSON.stringify(_eqRefs),
    };
    eqPost(data, d => {
        if (!d.ok) return alert(d.msg);
        eqCloseForm();
        eqLoad();
        if (d.id && _eqCurrentId === d.id) eqReloadDrawer(d.id);
    });
}
function eqDeleteCurrent() {
    const id = document.getElementById('eq-form-id').value;
    if (!id || !confirm('Eliminar este item e todos os seus anexos e problemas? Esta acção não pode ser desfeita.')) return;
    eqPost({eq_action:'delete',id}, d=>{
        if (!d.ok) return alert(d.msg);
        eqCloseForm(); eqCloseDrawer(); eqLoad();
    });
}

// Refs
function eqRenderRefs() {
    const el = document.getElementById('eq-refs-list');
    if (!el) return;
    el.innerHTML = _eqRefs.map((r,i)=>`
      <div class="eq-ref-item">
        🔗 <span style="flex:1">${eHtml(r.title)}${r.url?` — <a href="${eHtml(r.url)}" target="_blank" style="font-size:11px">${eHtml(r.url)}</a>`:''}</span>
        <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:10px" onclick="_eqRefs.splice(${i},1);eqRenderRefs()">✕</button>
      </div>`).join('');
}
function eqAddRef() {
    const title = document.getElementById('eq-new-ref-title').value.trim();
    if (!title) return;
    _eqRefs.push({title, url: document.getElementById('eq-new-ref-url').value.trim()});
    document.getElementById('eq-new-ref-title').value = '';
    document.getElementById('eq-new-ref-url').value = '';
    eqRenderRefs();
}

// ── Utils ─────────────────────────────────────────────────────────────────
function eqPost(data, cb) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v??'');
    fetch(EQ_URL, {method:'POST', body:fd}).then(r=>r.json()).then(d=>cb&&cb(d)).catch(e=>console.error('eq error',e));
}
function eHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────
eqLoad();
</script>
