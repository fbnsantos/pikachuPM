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
$cur_uid = (int)($_SESSION['user_id'] ?? 0);

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

$users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$prototypes = [];
try {
    $prototypes = $pdo->query("SELECT id, short_name, title, estado FROM prototypes ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$EQ_URL     = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/equipment_ajax.php';
$UPLOAD_URL = rtrim(dirname($_SERVER['PHP_SELF']),'/').'/uploads/equipment/';
?>

<style>
/* ── Layout split ─────────────────────────────────────────────────────────── */
#eq-layout{display:flex;gap:0;overflow:hidden;border:1px solid #dee2e6;border-radius:12px;background:#fff}

/* Left panel */
.eq-left{width:300px;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid #dee2e6;overflow:hidden}
.eq-left-head{padding:12px;border-bottom:1px solid #dee2e6;flex-shrink:0;display:flex;flex-direction:column;gap:8px}
.eq-left-head-row{display:flex;align-items:center;gap:8px}
.eq-left-head h6{margin:0;font-weight:700;font-size:13px;flex:1}
#eq-list{flex:1;overflow-y:auto}

/* List items */
.eq-list-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
.eq-list-item:hover{background:#f8f9fa}
.eq-list-item.active{background:#e8f0fe;border-right:3px solid #0d6efd}
.eq-li-icon{font-size:22px;flex-shrink:0;line-height:1}
.eq-li-body{flex:1;min-width:0}
.eq-li-name{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.eq-li-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:2px}
.eq-li-end{display:flex;flex-direction:column;align-items:center;gap:3px;flex-shrink:0}
.eq-list-empty{padding:40px 16px;text-align:center;color:#6c757d;font-size:13px}

/* Right panel */
.eq-right{flex:1;overflow-y:auto;min-width:0}
.eq-right-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#6c757d;gap:8px}
.eq-right-empty-icon{font-size:52px;opacity:.4}
.eq-right-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #dee2e6;position:sticky;top:0;background:#fff;z-index:5}
.eq-right-head h5{margin:0;font-size:15px;font-weight:700;flex:1}
.eq-right-body{padding:20px}
.eq-right-sect{margin-bottom:22px}
.eq-right-sect h6{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:10px;padding-bottom:5px;border-bottom:1px solid #f0f0f0}

/* Status & class badges */
.eq-status{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600}
.eq-status-funcional{background:#d1e7dd;color:#0a3622}
.eq-status-parcial{background:#fff3cd;color:#664d03}
.eq-status-inativo{background:#f8d7da;color:#58151c}
.eq-status-manutencao{background:#cff4fc;color:#055160}
.eq-class{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600}
.eq-class-equipamento{background:#e0cffc;color:#3d0a91}
.eq-class-demonstrador{background:#d2f4ea;color:#0a3622}
.eq-class-infraestrutura{background:#fde8d8;color:#842029}

/* Status dots for list */
.eq-sdot{display:inline-block;width:8px;height:8px;border-radius:50%}
.eq-sdot-funcional{background:#20c997}.eq-sdot-parcial{background:#ffc107}
.eq-sdot-inativo{background:#dc3545}.eq-sdot-manutencao{background:#0dcaf0}

/* Priority dots */
.prio-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px}
.prio-critica{background:#dc3545}.prio-alta{background:#fd7e14}
.prio-media{background:#ffc107}.prio-baixa{background:#20c997}

/* Issue list */
.eq-issue-item{background:#f8f9fa;border-radius:8px;padding:10px 12px;margin-bottom:8px;border-left:3px solid #dee2e6}
.eq-issue-item.prio-critica-border{border-color:#dc3545}
.eq-issue-item.prio-alta-border{border-color:#fd7e14}
.eq-issue-item.prio-media-border{border-color:#ffc107}
.eq-issue-item.prio-baixa-border{border-color:#20c997}
.eq-issue-item.status-resolvido{opacity:.5}

/* Attachments */
.eq-attach-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-bottom:12px}
.eq-attach-item{position:relative;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#f8f9fa;cursor:pointer}
.eq-attach-item img{width:100%;height:100%;object-fit:cover}
.eq-attach-item .eq-attach-del{position:absolute;top:3px;right:3px;background:rgba(220,53,69,.85);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1}
.eq-attach-doc{flex-direction:column;gap:4px;font-size:10px;text-align:center;padding:4px}
.eq-attach-doc-icon{font-size:24px}
.eq-attach-doc-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;padding:0 4px;font-size:9px;color:#495057}

/* Upload drop zone */
.eq-dropzone{border:2px dashed #ced4da;border-radius:8px;padding:16px;text-align:center;font-size:12px;color:#6c757d;cursor:pointer;transition:border-color .15s}
.eq-dropzone:hover,.eq-dropzone.drag{border-color:#0d6efd;background:#f0f7ff;color:#0d6efd}

/* Prototype refs in detail */
.eq-ref-item{display:flex;align-items:center;gap:8px;margin-bottom:6px;background:#f0f7ff;border-radius:6px;padding:6px 10px;font-size:12px;border:1px solid #b8d9f7}
.eq-ref-item a{color:#0d6efd;text-decoration:none;font-weight:600}
.eq-ref-item a:hover{text-decoration:underline}
.eq-ref-code{font-weight:700;color:#0d6efd;font-size:11px;white-space:nowrap}

/* Form modal */
.eq-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;display:flex;align-items:center;justify-content:center}
.eq-modal{background:#fff;border-radius:12px;width:min(660px,96vw);max-height:90vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)}
.eq-modal-head{padding:16px 20px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1}
.eq-modal-body{padding:20px}

/* Prototype picker */
.eq-refpicker{display:grid;grid-template-columns:1fr 1fr;gap:12px;border:1px solid #dee2e6;border-radius:8px;padding:12px;background:#f8f9fa;margin-top:6px}
.eq-refpicker-col-head{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:8px}
.eq-proto-search{margin-bottom:6px}
.eq-proto-list{max-height:170px;overflow-y:auto;display:flex;flex-direction:column;gap:3px}
.eq-proto-item{display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;background:#fff;border:1px solid #dee2e6;cursor:grab;font-size:11px;user-select:none;transition:background .1s,border-color .1s}
.eq-proto-item:hover{border-color:#0d6efd;background:#f0f7ff}
.eq-proto-item.in-refs{background:#e8f4fd;border-color:#7fb8ef;opacity:.6;cursor:default}
.eq-proto-item.dragging{opacity:.35}
.eq-proto-code{font-weight:700;color:#0d6efd;white-space:nowrap;flex-shrink:0}
.eq-proto-title-text{color:#495057;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.eq-refs-drop{min-height:130px;border:2px dashed #ced4da;border-radius:6px;padding:8px;display:flex;flex-direction:column;gap:4px;background:#fff;transition:border-color .15s,background .15s}
.eq-refs-drop.drag-over{border-color:#0d6efd;background:#f0f7ff}
.eq-refs-drop-hint{font-size:11px;text-align:center;color:#adb5bd;padding:20px 0;flex:1;display:flex;align-items:center;justify-content:center}
.eq-ref-chip{display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:6px;background:#e8f4fd;border:1px solid #b8d9f7;font-size:11px}
.eq-ref-chip-code{font-weight:700;color:#0d6efd;flex-shrink:0}
.eq-ref-chip-title{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#495057}
</style>

<!-- ── Header ── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h5 class="mb-0 fw-bold">🔧 Equipamentos &amp; Demonstradores</h5>
  <button class="btn btn-primary btn-sm" onclick="eqOpenForm()">+ Novo</button>
</div>

<!-- ── Layout split ── -->
<div id="eq-layout">

  <!-- ── Left panel ── -->
  <div class="eq-left">
    <div class="eq-left-head">
      <div class="eq-left-head-row">
        <h6>Lista</h6>
        <button class="btn btn-outline-secondary btn-sm py-0 ms-auto" onclick="eqLoad()" title="Actualizar"><i class="bi bi-arrow-clockwise"></i></button>
      </div>
      <input type="text" class="form-control form-control-sm" id="eq-search" placeholder="Pesquisar…" oninput="_eqFilter.q=this.value;eqRenderList()">
      <div class="d-flex flex-wrap gap-1" id="eq-class-filter">
        <button class="btn btn-sm btn-outline-secondary active" data-class="">Todos</button>
        <button class="btn btn-sm btn-outline-secondary" data-class="equipamento">🔧</button>
        <button class="btn btn-sm btn-outline-secondary" data-class="demonstrador">🎯</button>
        <button class="btn btn-sm btn-outline-secondary" data-class="infraestrutura">🏗️</button>
      </div>
      <div class="d-flex flex-wrap gap-1" id="eq-status-filter">
        <button class="btn btn-sm btn-outline-secondary active" data-status="">Qualquer estado</button>
        <button class="btn btn-sm btn-outline-secondary" data-status="funcional">✅</button>
        <button class="btn btn-sm btn-outline-secondary" data-status="parcial">⚠️</button>
        <button class="btn btn-sm btn-outline-secondary" data-status="manutencao">🔄</button>
        <button class="btn btn-sm btn-outline-secondary" data-status="inativo">❌</button>
      </div>
    </div>
    <div id="eq-list">
      <div class="eq-list-empty">A carregar…</div>
    </div>
  </div>

  <!-- ── Right panel ── -->
  <div class="eq-right" id="eq-right">
    <div class="eq-right-empty">
      <div class="eq-right-empty-icon">🔧</div>
      <p class="mb-0" style="font-size:14px">Seleciona um item à esquerda para ver o detalhe</p>
    </div>
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

      <!-- ── Referências a protótipos ── -->
      <div class="col-12">
        <label class="form-label fw-semibold">Referências a Protótipos</label>
        <div class="eq-refpicker">
          <!-- Esquerda: lista de protótipos disponíveis -->
          <div>
            <div class="eq-refpicker-col-head">Protótipos disponíveis</div>
            <input type="text" class="form-control form-control-sm eq-proto-search" id="eq-proto-search"
                   placeholder="Pesquisar…" oninput="eqFilterProtos()">
            <div id="eq-proto-list" class="eq-proto-list">
              <?php if (empty($prototypes)): ?>
              <div class="text-muted" style="font-size:11px;padding:8px">Sem protótipos disponíveis.</div>
              <?php endif; ?>
            </div>
          </div>
          <!-- Direita: selecionados -->
          <div>
            <div class="eq-refpicker-col-head">Selecionados</div>
            <div id="eq-refs-list" class="eq-refs-drop"
                 ondragover="eqRefDragOver(event)"
                 ondragleave="eqRefDragLeave(event)"
                 ondrop="eqRefDrop(event)">
              <span class="eq-refs-drop-hint">Arrasta ou clica para selecionar</span>
            </div>
          </div>
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
const EQ_PROTOS = <?= json_encode(array_map(fn($p) => [
    'id'         => (int)$p['id'],
    'short_name' => $p['short_name'],
    'title'      => $p['title'],
    'estado'     => $p['estado'] ?? '',
], $prototypes)) ?>;

const CLASS_ICON   = {equipamento:'🔧', demonstrador:'🎯', infraestrutura:'🏗️'};
const CLASS_LABEL  = {equipamento:'Equipamento', demonstrador:'Demonstrador', infraestrutura:'Infraestrutura'};
const STATUS_LABEL = {funcional:'✅ Funcional', parcial:'⚠️ Parcial', inativo:'❌ Inativo', manutencao:'🔄 Manutenção'};
const PRIO_LABEL   = {baixa:'Baixa', media:'Média', alta:'Alta', critica:'Crítica'};
const STAT_LABEL   = {aberto:'Aberto', em_progresso:'Em progresso', resolvido:'Resolvido'};

let _eqItems      = [];
let _eqCurrentId  = null;
let _eqCurrentItem = null;
let _eqRefs       = [];
let _eqFilter     = {class:'', status:'', q:''};

// ── Layout height ────────────────────────────────────────────────────────────
function eqSetHeight() {
    const layout = document.getElementById('eq-layout');
    if (!layout) return;
    const top = layout.getBoundingClientRect().top;
    const h = window.innerHeight - top - 24;
    layout.style.height = Math.max(400, h) + 'px';
}
window.addEventListener('resize', eqSetHeight);
// wait for tab to finish rendering
setTimeout(eqSetHeight, 100);

// ── Filters ──────────────────────────────────────────────────────────────────
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

// ── Load items ───────────────────────────────────────────────────────────────
function eqLoad() {
    const p = new URLSearchParams({eq_action:'list', class:_eqFilter.class, status:_eqFilter.status});
    fetch(EQ_URL+'?'+p).then(r=>r.json()).then(d => {
        if (!d.ok) return;
        _eqItems = d.items;
        eqRenderList();
        if (_eqCurrentId) {
            if (_eqItems.some(it => it.id === _eqCurrentId)) {
                // re-fetch detail to refresh counts
                fetch(EQ_URL+'?eq_action=get&id='+_eqCurrentId).then(r=>r.json()).then(dd => {
                    if (dd.ok) { _eqCurrentItem = dd.item; eqRenderDetail(dd.item); }
                });
            } else {
                _eqCurrentId = null; _eqCurrentItem = null;
                eqShowEmpty();
            }
        }
    });
}

// ── Render list ──────────────────────────────────────────────────────────────
function eqRenderList() {
    const list = document.getElementById('eq-list');
    const q = (_eqFilter.q || '').toLowerCase();
    const filtered = q ? _eqItems.filter(it =>
        it.name.toLowerCase().includes(q) ||
        (it.location||'').toLowerCase().includes(q) ||
        (it.responsible||'').toLowerCase().includes(q)
    ) : _eqItems;

    if (!filtered.length) {
        list.innerHTML = `<div class="eq-list-empty">📦<br>${q?'Sem resultados para "'+eHtml(q)+'"':'Nenhum item. Clica em <strong>+ Novo</strong>.'}</div>`;
        return;
    }
    list.innerHTML = filtered.map(eqListItemHtml).join('');
    if (_eqCurrentId) {
        list.querySelector('[data-id="'+_eqCurrentId+'"]')?.classList.add('active');
    }
    list.querySelectorAll('.eq-list-item').forEach(el => {
        el.addEventListener('click', () => eqSelectItem(parseInt(el.dataset.id)));
    });
}

function eqListItemHtml(it) {
    const icon  = CLASS_ICON[it.class] || '📦';
    const issues = it.open_issues > 0
        ? `<span class="badge bg-danger" style="font-size:9px" title="${it.open_issues} problema(s) aberto(s)">${it.open_issues}</span>` : '';
    const attach = it.attach_count > 0
        ? `<span style="font-size:9px;color:#6c757d">📎${it.attach_count}</span>` : '';
    return `<div class="eq-list-item" data-id="${it.id}">
      <span class="eq-li-icon">${icon}</span>
      <div class="eq-li-body">
        <div class="eq-li-name">${eHtml(it.name)}</div>
        <div class="eq-li-meta">
          <span class="eq-class eq-class-${it.class}" style="font-size:10px;padding:1px 5px">${CLASS_LABEL[it.class]}</span>
          ${it.location ? `<span style="font-size:10px;color:#6c757d">📍${eHtml(it.location)}</span>` : ''}
        </div>
      </div>
      <div class="eq-li-end">
        <span class="eq-sdot eq-sdot-${it.status}" title="${STATUS_LABEL[it.status]}"></span>
        ${issues}${attach}
      </div>
    </div>`;
}

// ── Select & show detail ─────────────────────────────────────────────────────
function eqSelectItem(id) {
    _eqCurrentId = id;
    document.querySelectorAll('.eq-list-item').forEach(el =>
        el.classList.toggle('active', parseInt(el.dataset.id) === id)
    );
    document.getElementById('eq-right').innerHTML = '<div class="eq-right-empty"><div class="eq-right-empty-icon" style="font-size:32px;animation:none">⏳</div><p style="font-size:13px">A carregar…</p></div>';
    fetch(EQ_URL+'?eq_action=get&id='+id).then(r=>r.json()).then(d => {
        if (!d.ok) return;
        _eqCurrentItem = d.item;
        eqRenderDetail(d.item);
    });
}

function eqShowEmpty() {
    document.getElementById('eq-right').innerHTML =
        '<div class="eq-right-empty"><div class="eq-right-empty-icon">🔧</div><p style="font-size:14px">Seleciona um item à esquerda</p></div>';
}

function eqRenderDetail(it) {
    const right = document.getElementById('eq-right');

    // Prototype refs
    const refHtml = (it.prototype_refs?.length)
        ? it.prototype_refs.map(r => {
            const code  = r.short_name || '';
            const title = r.title || r.title || '—';
            const href  = r.id ? `index.php?tab=prototypes%2Fprototypesv2` : (r.url||null);
            return `<div class="eq-ref-item">
              🔗 ${code ? `<span class="eq-ref-code">${eHtml(code)}</span>` : ''}
              ${href ? `<a href="${eHtml(href)}" target="_blank">${eHtml(title)}</a>` : eHtml(title)}
            </div>`;
          }).join('')
        : '<p class="text-muted mb-0" style="font-size:12px">Sem referências.</p>';

    // Issues
    const issueHtml = `<div id="eq-issues-list">
        ${it.issues.length
            ? it.issues.map(renderIssue).join('')
            : '<p class="text-muted mb-2" style="font-size:12px">Sem problemas registados.</p>'}
      </div>
      <div class="d-flex gap-2 mt-2">
        <select class="form-select form-select-sm" id="new-issue-prio" style="max-width:115px">
          <option value="baixa">Baixa</option>
          <option value="media" selected>Média</option>
          <option value="alta">Alta</option>
          <option value="critica">Crítica</option>
        </select>
        <input type="text" class="form-control form-control-sm" id="new-issue-desc" placeholder="Descreve o problema…">
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

    right.innerHTML = `
      <div class="eq-right-head">
        <span style="font-size:24px">${CLASS_ICON[it.class]||'📦'}</span>
        <h5>${eHtml(it.name)}</h5>
        <button class="btn btn-sm btn-outline-primary" onclick="eqEditCurrent()">✏️ Editar</button>
      </div>
      <div class="eq-right-body">

        <div class="eq-right-sect">
          <h6>Informação geral</h6>
          <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="eq-class eq-class-${it.class}">${CLASS_LABEL[it.class]}</span>
            <span class="eq-status eq-status-${it.status}">${STATUS_LABEL[it.status]}</span>
          </div>
          ${it.description ? `<p style="font-size:13px;white-space:pre-wrap;margin-bottom:8px">${eHtml(it.description)}</p>` : ''}
          <div class="d-flex flex-wrap gap-3" style="font-size:13px">
            ${it.location    ? `<span><span class="text-muted">📍</span> ${eHtml(it.location)}</span>` : ''}
            ${it.responsible ? `<span><span class="text-muted">👤</span> ${eHtml(it.responsible)}</span>` : ''}
            ${it.quantity > 1 ? `<span><span class="text-muted">📦</span> Quantidade: ${it.quantity}</span>` : ''}
          </div>
        </div>

        <div class="eq-right-sect">
          <h6>🔗 Referências a Protótipos</h6>
          ${refHtml}
        </div>

        <div class="eq-right-sect">
          <h6>⚠️ Problemas e Necessidades</h6>
          ${issueHtml}
        </div>

        <div class="eq-right-sect">
          <h6>📸 Fotografias</h6>
          <div class="eq-attach-grid" id="eq-photos">${photoHtml||''}</div>
          ${!photoHtml ? '<p class="text-muted mb-2" style="font-size:12px">Sem fotografias.</p>' : ''}
          <div class="eq-dropzone" onclick="document.getElementById('eq-photo-input').click()"
               ondragover="eqDrag(event,true)" ondragleave="eqDrag(event,false)" ondrop="eqDrop(event,${it.id},'photo')">
            📸 Clica ou arrasta fotos
            <input type="file" id="eq-photo-input" style="display:none" accept="image/*" multiple
                   onchange="eqUploadFiles(this.files,${it.id},'photo')">
          </div>
        </div>

        <div class="eq-right-sect">
          <h6>📎 Documentos</h6>
          <div class="eq-attach-grid" id="eq-docs">${docHtml||''}</div>
          ${!docHtml ? '<p class="text-muted mb-2" style="font-size:12px">Sem documentos.</p>' : ''}
          <div class="eq-dropzone" onclick="document.getElementById('eq-doc-input').click()"
               ondragover="eqDrag(event,true)" ondragleave="eqDrag(event,false)" ondrop="eqDrop(event,${it.id},'document')">
            📄 Clica ou arrasta documentos
            <input type="file" id="eq-doc-input" style="display:none" multiple
                   onchange="eqUploadFiles(this.files,${it.id},'document')">
          </div>
        </div>

      </div>`;
}

function eqEditCurrent() {
    if (_eqCurrentItem) eqOpenForm(_eqCurrentItem);
}

function renderIssue(iss) {
    return `<div class="eq-issue-item prio-${iss.priority}-border ${iss.status==='resolvido'?'status-resolvido':''}" id="issue-${iss.id}">
      <div class="d-flex align-items-start gap-2">
        <span class="prio-dot prio-${iss.priority}" title="${PRIO_LABEL[iss.priority]}" style="margin-top:4px;flex-shrink:0"></span>
        <div style="flex:1;font-size:12px">${eHtml(iss.description)}</div>
        <div class="d-flex gap-1 flex-shrink-0">
          <select class="form-select form-select-sm py-0" style="font-size:10px;width:auto"
                  onchange="eqUpdateIssueStatus(${iss.id},this.value)">
            <option value="aberto" ${iss.status==='aberto'?'selected':''}>Aberto</option>
            <option value="em_progresso" ${iss.status==='em_progresso'?'selected':''}>Em progresso</option>
            <option value="resolvido" ${iss.status==='resolvido'?'selected':''}>Resolvido</option>
          </select>
          <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:10px"
                  onclick="eqDelIssue(${iss.id})">✕</button>
        </div>
      </div>
    </div>`;
}

// ── Issues ───────────────────────────────────────────────────────────────────
function eqAddIssue(eid) {
    const desc = document.getElementById('new-issue-desc').value.trim();
    const prio = document.getElementById('new-issue-prio').value;
    if (!desc) return;
    eqPost({eq_action:'add_issue', equipment_id:eid, description:desc, priority:prio}, d => {
        if (!d.ok) return alert(d.msg);
        document.getElementById('new-issue-desc').value = '';
        eqReloadDetail(eid);
    });
}
function eqUpdateIssueStatus(id, status) {
    eqPost({eq_action:'update_issue', id, status, description:'_keep', priority:'_keep'}, ()=>{});
    document.getElementById('issue-'+id)?.classList.toggle('status-resolvido', status==='resolvido');
}
function eqDelIssue(id) {
    if (!confirm('Remover este problema?')) return;
    eqPost({eq_action:'delete_issue', id}, d => {
        if (d.ok) document.getElementById('issue-'+id)?.remove();
    });
}

// ── Attachments ──────────────────────────────────────────────────────────────
function eqDrag(e, on) { e.preventDefault(); e.currentTarget.classList.toggle('drag', on); }
function eqDrop(e, eid, type) {
    e.preventDefault(); e.currentTarget.classList.remove('drag');
    eqUploadFiles(e.dataTransfer.files, eid, type);
}
function eqUploadFiles(files, eid, type) {
    for (const file of files) {
        const fd = new FormData();
        fd.append('eq_action','upload'); fd.append('equipment_id',eid);
        fd.append('attach_type',type); fd.append('file',file);
        fetch(EQ_URL,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
            if (!d.ok) return alert('Erro: '+d.msg);
            eqReloadDetail(eid);
        });
    }
}
function eqDelAttach(id) {
    if (!confirm('Remover este anexo?')) return;
    eqPost({eq_action:'delete_attach',id}, d => {
        if (d.ok && _eqCurrentId) eqReloadDetail(_eqCurrentId);
    });
}

function eqReloadDetail(id) {
    fetch(EQ_URL+'?eq_action=get&id='+id).then(r=>r.json()).then(d => {
        if (d.ok) { _eqCurrentItem = d.item; eqRenderDetail(d.item); eqLoad(); }
    });
}

// ── Form ─────────────────────────────────────────────────────────────────────
function eqOpenForm(item) {
    _eqRefs = item ? (item.prototype_refs || []) : [];
    document.getElementById('eq-form-id').value    = item ? item.id : '';
    document.getElementById('eq-f-name').value     = item ? item.name : '';
    document.getElementById('eq-f-class').value    = item ? item.class : 'equipamento';
    document.getElementById('eq-f-status').value   = item ? item.status : 'funcional';
    document.getElementById('eq-f-qty').value      = item ? item.quantity : 1;
    document.getElementById('eq-f-location').value = item ? (item.location||'') : '';
    document.getElementById('eq-f-desc').value     = item ? (item.description||'') : '';
    document.getElementById('eq-f-resp-uid').value = item ? (item.responsible_uid||'') : '';
    document.getElementById('eq-f-resp').value     = item ? (item.responsible||'') : '';
    document.getElementById('eq-modal-title').textContent = item ? 'Editar: '+item.name : 'Novo item';
    document.getElementById('eq-delete-btn').style.display = item ? '' : 'none';
    eqRespUidChange();
    document.getElementById('eq-proto-search').value = '';
    eqRenderProtoList();
    eqRenderRefs();
    document.getElementById('eq-modal-overlay').style.display = 'flex';
}
function eqCloseForm() {
    document.getElementById('eq-modal-overlay').style.display = 'none';
}
function eqRespUidChange() {
    const uid = document.getElementById('eq-f-resp-uid').value;
    document.getElementById('eq-f-resp-custom-wrap').style.display = uid ? 'none' : '';
    if (uid) {
        const sel = document.getElementById('eq-f-resp-uid');
        document.getElementById('eq-f-resp').value = sel.options[sel.selectedIndex].text;
    }
}
function eqSaveForm() {
    const name = document.getElementById('eq-f-name').value.trim();
    if (!name) { document.getElementById('eq-f-name').focus(); return; }
    const data = {
        eq_action:'save',
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
        if (d.id) { _eqCurrentId = d.id; }
        eqLoad();
    });
}
function eqDeleteCurrent() {
    const id = document.getElementById('eq-form-id').value;
    if (!id || !confirm('Eliminar este item e todos os seus anexos e problemas? Esta acção não pode ser desfeita.')) return;
    eqPost({eq_action:'delete',id}, d => {
        if (!d.ok) return alert(d.msg);
        _eqCurrentId = null; _eqCurrentItem = null;
        eqCloseForm(); eqShowEmpty(); eqLoad();
    });
}

// ── Prototype picker ─────────────────────────────────────────────────────────
function eqRenderProtoList() {
    const q    = (document.getElementById('eq-proto-search')?.value || '').toLowerCase();
    const list = document.getElementById('eq-proto-list');
    if (!list) return;
    list.innerHTML = '';
    const refIds = new Set(_eqRefs.filter(r=>r.id).map(r=>r.id));
    const visible = EQ_PROTOS.filter(p =>
        !q || p.short_name.toLowerCase().includes(q) || p.title.toLowerCase().includes(q)
    );
    if (!visible.length) {
        list.innerHTML = '<div style="font-size:11px;color:#adb5bd;padding:6px">Sem resultados.</div>';
        return;
    }
    visible.forEach(p => {
        const inRefs = refIds.has(p.id);
        const el = document.createElement('div');
        el.className = 'eq-proto-item' + (inRefs ? ' in-refs' : '');
        el.draggable = !inRefs;
        el.title = p.title + (p.estado ? ' ['+p.estado+']' : '');
        el.innerHTML = `<span class="eq-proto-code">${eHtml(p.short_name)}</span><span class="eq-proto-title-text">${eHtml(p.title)}</span>`;
        el.addEventListener('dragstart', ev => {
            el.classList.add('dragging');
            ev.dataTransfer.setData('eq-proto', JSON.stringify({id:p.id, short_name:p.short_name, title:p.title}));
        });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));
        el.addEventListener('click', () => eqProtoToggle(p.id, p.short_name, p.title));
        list.appendChild(el);
    });
}

function eqFilterProtos() { eqRenderProtoList(); }

function eqProtoToggle(id, short_name, title) {
    const idx = _eqRefs.findIndex(r => r.id === id);
    if (idx >= 0) {
        _eqRefs.splice(idx, 1);
    } else {
        _eqRefs.push({id, short_name, title});
    }
    eqRenderRefs();
    eqRenderProtoList();
}

function eqRemoveRef(idx) {
    _eqRefs.splice(idx, 1);
    eqRenderRefs();
    eqRenderProtoList();
}

function eqRenderRefs() {
    const el = document.getElementById('eq-refs-list');
    if (!el) return;
    if (!_eqRefs.length) {
        el.innerHTML = '<span class="eq-refs-drop-hint">Arrasta ou clica para selecionar</span>';
        return;
    }
    el.innerHTML = _eqRefs.map((r, i) => `
      <div class="eq-ref-chip">
        <span class="eq-ref-chip-code">${eHtml(r.short_name||r.title||'—')}</span>
        <span class="eq-ref-chip-title">${eHtml(r.title||'')}</span>
        <button class="btn btn-link p-0 ms-auto text-danger" style="font-size:11px;line-height:1" onclick="eqRemoveRef(${i})">✕</button>
      </div>`).join('');
}

function eqRefDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}
function eqRefDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}
function eqRefDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    const raw = e.dataTransfer.getData('eq-proto');
    if (!raw) return;
    try {
        const p = JSON.parse(raw);
        if (p.id && !_eqRefs.some(r => r.id === p.id)) {
            _eqRefs.push({id:p.id, short_name:p.short_name, title:p.title});
            eqRenderRefs();
            eqRenderProtoList();
        }
    } catch(e) {}
}

// ── Utils ─────────────────────────────────────────────────────────────────────
function eqPost(data, cb) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v??'');
    fetch(EQ_URL, {method:'POST',body:fd}).then(r=>r.json()).then(d=>cb&&cb(d)).catch(e=>console.error('eq',e));
}
function eHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
eqLoad();
</script>
