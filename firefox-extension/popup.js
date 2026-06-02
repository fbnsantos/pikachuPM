// ── Constants ──────────────────────────────────────────────
const STATE_LABELS = {
  'aberta':      'Aberta',
  'em execução': 'Em Execução',
  'suspensa':    'Suspensa',
  'completada':  'Concluída',  // API usa 'completada', não 'concluída'
};

const STATE_CLASSES = {
  'aberta':      'aberta',
  'em execução': 'em-execucao',
  'suspensa':    'suspensa',
  'completada':  'concluida',
};

const CAL_COLORS = {
  green: '#22c55e', grey: '#6b7280', gray: '#6b7280',
  blue: '#3b82f6',  orange: '#f59e0b', purple: '#a855f7', red: '#ef4444',
};

const TIPO_LABELS = {
  ferias: 'Férias', aulas: 'Aulas', demo: 'Demo',
  campo: 'Campo',   tribe: 'Tribe', outro: 'Outro',
};

const isSidePanel = document.body.dataset.mode === 'sidepanel' || document.body.dataset.mode === 'sidebar';

// ── State ───────────────────────────────────────────────────
let todos = [];
let currentFilter = 'all';
let searchQuery   = '';
let editingId     = null;
let apiUrl        = '';
let token         = '';
let compactMode   = false;
const detailsCache = new Map();

// ── DOM refs ────────────────────────────────────────────────
const elNotConfigured = document.getElementById('not-configured');
const elMainContent   = document.getElementById('main-content');
const elLoading       = document.getElementById('loading');
const elErrorMsg      = document.getElementById('error-msg');
const elErrorText     = document.getElementById('error-text');
const elTodosList     = document.getElementById('todos-list');
const elEmptyState    = document.getElementById('empty-state');
const elFab           = document.getElementById('btn-add-todo');
const elModalOverlay  = document.getElementById('modal-overlay');
const elModalTitle    = document.getElementById('modal-title');
const elToast         = document.getElementById('toast');

// ── Config ──────────────────────────────────────────────────
async function loadConfig() {
  return new Promise((resolve) => {
    chrome.storage.sync.get(['apiUrl', 'token', 'compactMode', 'mqttSound'], (result) => {
      apiUrl      = (result.apiUrl || '').replace(/\/$/, '');
      token       = result.token || '';
      compactMode = !!result.compactMode;
      _mqttSound  = result.mqttSound !== false; // ativo por omissão
      resolve({ apiUrl, token, compactMode });
    });
  });
}

// ── Utilities ────────────────────────────────────────────────
function showToast(msg, type = '', duration) {
  elToast.textContent = msg;
  elToast.className = 'toast show' + (type ? ' ' + type : '');
  const ms = duration ?? (msg.length > 50 ? 4500 : 2500);
  setTimeout(() => { elToast.className = 'toast'; }, ms);
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatDeadline(dateStr) {
  if (!dateStr) return null;
  const date  = new Date(dateStr + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.round((date - today) / 86400000);
  const fmt  = date.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
  let cls = '';
  if (diff < 0) cls = 'overdue';
  else if (diff <= 2) cls = 'soon';
  return { label: fmt, cls, diff };
}

function formatCalDate(dateStr) {
  const d     = new Date(dateStr + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff  = Math.round((d - today) / 86400000);
  if (diff === 0) return { label: 'Hoje', isToday: true };
  if (diff === 1) return { label: 'Amanhã', isToday: false };
  if (diff <= 6) return {
    label: d.toLocaleDateString('pt-PT', { weekday: 'short' }),
    isToday: false,
  };
  return {
    label: d.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' }),
    isToday: false,
  };
}

// ── API ──────────────────────────────────────────────────────
async function apiFetch(path, options = {}) {
  const headers = { 'Authorization': 'Bearer ' + token, ...options.headers };
  if (options.body) headers['Content-Type'] = 'application/json';
  const res  = await fetch(apiUrl + path, { ...options, headers });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { error: text }; }
  if (!res.ok) throw new Error(data.error || data.message || `Erro ${res.status}`);
  return data;
}

// ── Todos ────────────────────────────────────────────────────
async function fetchTodos() {
  elLoading.style.display = 'flex';
  elErrorMsg.style.display = 'none';
  elTodosList.innerHTML = '';
  elEmptyState.style.display = 'none';
  try {
    const data = await apiFetch('/api/todos.php');
    todos = Array.isArray(data) ? data : (data.todos || []);
    renderTodos();
  } catch (err) {
    elLoading.style.display = 'none';
    elErrorText.textContent = err.message;
    elErrorMsg.style.display = 'flex';
  }
}

function getFilteredTodos() {
  let list = todos;
  if (currentFilter !== 'all') list = list.filter(t => t.estado === currentFilter);
  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    list = list.filter(t =>
      (t.titulo || '').toLowerCase().includes(q) ||
      (t.descritivo || '').toLowerCase().includes(q)
    );
  }
  return list;
}

function renderTodos() {
  elLoading.style.display = 'none';
  const filtered = getFilteredTodos();
  elTodosList.innerHTML = '';
  if (filtered.length === 0) { elEmptyState.style.display = 'flex'; return; }
  elEmptyState.style.display = 'none';
  filtered.forEach(todo => elTodosList.appendChild(buildTodoCard(todo)));
}

// ── Markdown (minimal) ──────────────────────────────────────
function renderMarkdown(text) {
  if (!text) return '';
  let html = escapeHtml(text);

  html = html.replace(/^### (.+)$/gm, '<h3 class="md-h">$1</h3>');
  html = html.replace(/^## (.+)$/gm,  '<h2 class="md-h">$1</h2>');
  html = html.replace(/^# (.+)$/gm,   '<h1 class="md-h">$1</h1>');

  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*(.+?)\*/g,     '<em>$1</em>');
  html = html.replace(/__(.+?)__/g,     '<strong>$1</strong>');
  html = html.replace(/_(.+?)_/g,       '<em>$1</em>');

  html = html.replace(/`([^`]+)`/g, '<code class="md-code">$1</code>');

  html = html.replace(/^[ \t]*[-*] (.+)$/gm, '<li>$1</li>');
  html = html.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul class="md-ul">$1</ul>');

  html = html
    .split(/\n{2,}/)
    .map(block => {
      block = block.trim();
      if (!block) return '';
      if (/^<(h[123]|ul|li)/.test(block)) return block;
      return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
    })
    .join('');

  return html;
}

// ── Task details (description + checklist) ───────────────────
async function fetchTaskDetails(todoId) {
  if (detailsCache.has(todoId)) return detailsCache.get(todoId);
  const data = await apiFetch(`/api/task_details.php?id=${todoId}`);
  detailsCache.set(todoId, data);
  return data;
}

function renderTaskDetails(container, data) {
  const { task, checklist } = data;
  const hasDesc      = task.descritivo && task.descritivo.trim();
  const hasChecklist = checklist && checklist.length > 0;

  if (!hasDesc && !hasChecklist) {
    container.innerHTML = '<span class="td-empty">Sem descrição nem checklist.</span>';
    return;
  }

  let html = '';
  if (hasDesc) {
    html += `<div class="td-desc">${renderMarkdown(task.descritivo)}</div>`;
  }
  if (hasChecklist) {
    const done  = checklist.filter(i => i.checked).length;
    const total = checklist.length;
    const pct   = Math.round((done / total) * 100);
    html += `
      <div class="td-checklist">
        <div class="td-cl-header">
          <span class="td-cl-label">Checklist</span>
          <span class="td-cl-progress">${done}/${total}</span>
          <div class="td-cl-bar"><div class="td-cl-fill" style="width:${pct}%"></div></div>
        </div>`;
    checklist.forEach(item => {
      const cls = item.checked ? 'td-cl-item checked' : 'td-cl-item';
      html += `
        <label class="${cls}" data-id="${item.id}">
          <input type="checkbox" ${item.checked ? 'checked' : ''}>
          <span>${escapeHtml(item.text)}</span>
        </label>`;
    });
    html += '</div>';
  }

  container.innerHTML = html;

  container.querySelectorAll('.td-cl-item input').forEach(chk => {
    chk.addEventListener('change', async (e) => {
      e.stopPropagation();
      const label  = chk.closest('.td-cl-item');
      const itemId = parseInt(label.dataset.id);
      const checked = chk.checked;

      label.classList.toggle('checked', checked);
      const cached = detailsCache.get(parseInt(container.closest('[data-todo-id]')?.dataset.todoId));
      if (cached) {
        const ci = cached.checklist.find(i => i.id === itemId);
        if (ci) ci.checked = checked;
        const cl    = cached.checklist;
        const done  = cl.filter(i => i.checked).length;
        const total = cl.length;
        const pct   = Math.round((done / total) * 100);
        const bar  = container.querySelector('.td-cl-fill');
        const prog = container.querySelector('.td-cl-progress');
        if (bar)  bar.style.width    = pct + '%';
        if (prog) prog.textContent   = `${done}/${total}`;
      }

      try {
        await apiFetch('/api/task_details.php', {
          method: 'PUT',
          body: JSON.stringify({ checklist_id: itemId, checked }),
        });
      } catch (err) {
        chk.checked = !checked;
        label.classList.toggle('checked', !checked);
        showToast(err.message, 'error');
      }
    });
  });
}

function buildTodoCard(todo) {
  const stateKey = STATE_CLASSES[todo.estado] || 'aberta';
  const card = document.createElement('div');
  card.className = `todo-card state-${stateKey}`;
  card.dataset.id = todo.id;

  const deadline = formatDeadline(todo.data_limite);
  const deadlineHtml = deadline
    ? `<span class="deadline ${deadline.cls}">📅 ${deadline.label}</span>` : '';

  const stateButtons = Object.entries(STATE_LABELS).map(([val, lbl]) =>
    `<button class="btn-state ${todo.estado === val ? 'active' : ''}" data-state="${val}">${lbl}</button>`
  ).join('');

  card.dataset.todoId = todo.id;
  card.innerHTML = `
    <div class="todo-header">
      <span class="todo-title">${escapeHtml(todo.titulo || '(sem título)')}</span>
      <span class="badge badge-${stateKey}">${STATE_LABELS[todo.estado] || todo.estado}</span>
    </div>
    <div class="todo-meta">
      ${deadlineHtml}
      ${todo.autor_nome ? `<span title="Criado por">✏️ ${escapeHtml(todo.autor_nome)}</span>` : ''}
      ${todo.responsavel_nome ? `<span title="Responsável">👤 ${escapeHtml(todo.responsavel_nome)}</span>` : ''}
    </div>
    <div class="todo-details" id="details-${todo.id}"></div>
    <div class="todo-actions">
      ${stateButtons}
      <button class="btn-details" title="Ver descrição e checklist">📋</button>
      <button class="btn-open" title="Abrir no pikachuPM">↗ Abrir</button>
      <button class="btn-delete" title="Eliminar">🗑</button>
    </div>`;

  card.addEventListener('click', (e) => {
    if (e.target.closest('button') || e.target.closest('input')) return;
    card.classList.remove('expanded');
  });

  card.querySelectorAll('.btn-state').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const newState = btn.dataset.state;
      if (newState === todo.estado) return;
      await updateTodoState(todo, newState, card);
    });
  });

  card.querySelector('.btn-details').addEventListener('click', async (e) => {
    e.stopPropagation();
    const detEl = card.querySelector('.todo-details');
    const isOpen = card.classList.contains('expanded');
    if (isOpen) { card.classList.remove('expanded'); return; }
    card.classList.add('expanded');
    if (!detEl.dataset.loaded) {
      detEl.innerHTML = '<span class="td-loading">↻</span>';
      try {
        const data = await fetchTaskDetails(todo.id);
        detEl.dataset.loaded = '1';
        renderTaskDetails(detEl, data);
      } catch (err) {
        detEl.innerHTML = `<span class="td-error">${escapeHtml(err.message)}</span>`;
      }
    }
  });

  card.querySelector('.btn-open').addEventListener('click', (e) => {
    e.stopPropagation();
    chrome.tabs.create({ url: `${apiUrl}/index.php?tab=todos` });
  });

  card.querySelector('.btn-delete').addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!confirm(`Eliminar "${todo.titulo}"?`)) return;
    await deleteTodo(todo.id, card);
  });

  return card;
}

async function updateTodoState(todo, newState, cardEl) {
  try {
    await apiFetch('/api/todos.php', {
      method: 'PUT',
      body: JSON.stringify({ id: todo.id, estado: newState }),
    });
    todo.estado = newState;
    const newCard = buildTodoCard(todo);
    newCard.classList.add('expanded');
    cardEl.replaceWith(newCard);
    showToast('Estado atualizado', 'success');
    if (currentFilter !== 'all' && currentFilter !== newState) {
      newCard.remove();
      if (!elTodosList.querySelector('.todo-card')) elEmptyState.style.display = 'flex';
    }
  } catch (err) { showToast(err.message, 'error'); }
}

async function deleteTodo(id, cardEl) {
  try {
    await apiFetch(`/api/todos.php?id=${id}`, { method: 'DELETE' });
    todos = todos.filter(t => t.id != id);
    cardEl.remove();
    showToast('Todo eliminado', 'success');
    if (!elTodosList.querySelector('.todo-card')) elEmptyState.style.display = 'flex';
  } catch (err) { showToast(err.message, 'error'); }
}

// ── Modal ─────────────────────────────────────────────────────
function openModal(todo = null) {
  editingId = todo ? todo.id : null;
  elModalTitle.textContent = todo ? 'Editar Todo' : 'Novo Todo';
  document.getElementById('todo-titulo').value      = todo ? (todo.titulo || '') : '';
  document.getElementById('todo-descritivo').value  = todo ? (todo.descritivo || '') : '';
  document.getElementById('todo-estado').value      = todo ? (todo.estado || 'aberta') : 'aberta';
  const defaultDate = new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10);
  document.getElementById('todo-data-limite').value = todo ? (todo.data_limite || '') : defaultDate;
  elModalOverlay.style.display = 'flex';
  document.getElementById('todo-titulo').focus();
}

function closeModal() {
  elModalOverlay.style.display = 'none';
  editingId = null;
}

async function saveTodo() {
  const titulo = document.getElementById('todo-titulo').value.trim();
  if (!titulo) { showToast('O título é obrigatório', 'error'); return; }

  const payload = {
    titulo,
    descritivo: document.getElementById('todo-descritivo').value.trim(),
    estado:     document.getElementById('todo-estado').value,
    data_limite: document.getElementById('todo-data-limite').value || null,
  };
  const saveBtn = document.getElementById('btn-modal-save');
  saveBtn.disabled = true;
  saveBtn.textContent = 'A guardar...';
  try {
    if (editingId) {
      payload.id = editingId;
      await apiFetch('/api/todos.php', { method: 'PUT', body: JSON.stringify(payload) });
      showToast('Todo atualizado', 'success');
    } else {
      await apiFetch('/api/todos.php', { method: 'POST', body: JSON.stringify(payload) });
      showToast('Todo criado', 'success');
    }
    closeModal();
    await fetchTodos();
  } catch (err) {
    showToast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Guardar';
  }
}

// ── Calendar (side panel only) ────────────────────────────────
async function fetchCalendar() {
  if (!isSidePanel) return;
  const elList  = document.getElementById('calendar-list');
  const elEmpty = document.getElementById('cal-empty');
  const elError = document.getElementById('cal-error');
  const elSpin  = document.getElementById('cal-loading');
  if (!elList) return;

  elSpin.classList.remove('hidden');
  elList.innerHTML = '';
  elEmpty.style.display = 'none';
  elError.style.display = 'none';

  try {
    const events = await apiFetch('/api/calendar.php?days=30');
    elSpin.classList.add('hidden');

    if (!events.length) { elEmpty.style.display = 'block'; return; }

    events.forEach(ev => elList.appendChild(buildCalEvent(ev)));
  } catch (err) {
    elSpin.classList.add('hidden');
    elError.style.display = 'block';
    elError.textContent = `Erro: ${err.message}`;
  }
}

function buildCalEvent(ev) {
  const row   = document.createElement('div');
  const color = CAL_COLORS[ev.cor] || CAL_COLORS[ev.tipo] || '#6b7280';
  const { label, isToday } = formatCalDate(ev.data);
  const tipo  = TIPO_LABELS[ev.tipo] || ev.tipo || '';
  const hora  = ev.hora ? ev.hora.slice(0, 5) + ' · ' : '';

  row.className = 'cal-event' + (isToday ? ' cal-today' : '');
  row.innerHTML = `
    <span class="cal-dot" style="background:${color}"></span>
    <span class="cal-date">${escapeHtml(label)}</span>
    <span class="cal-desc">${hora}${escapeHtml(ev.descricao || tipo)}</span>
    <span class="cal-tipo">${escapeHtml(tipo)}</span>`;
  return row;
}

// ── Sprints (side panel only) ─────────────────────────────────
const SPRINT_STATE_LABEL = {
  'aberta':      'Aberta',
  'em execução': 'Em curso',
  'suspensa':    'Pausada',
  'concluída':   'Concluída',
};
const SPRINT_STATE_CLASS = {
  'aberta':      'state-aberta',
  'em execução': 'state-em-execucao',
  'suspensa':    'state-suspensa',
  'concluída':   'state-concluida',
};

async function fetchSprints() {
  if (!isSidePanel) return;
  const elList  = document.getElementById('sprints-list');
  const elEmpty = document.getElementById('sprints-empty');
  const elError = document.getElementById('sprints-error');
  if (!elList) return;

  elList.innerHTML = '<div class="pk-loading">↻</div>';
  elEmpty.style.display = 'none';
  elError.style.display = 'none';

  try {
    const data = await apiFetch('/api/sprints.php');
    elList.innerHTML = '';
    if (!data.length) { elEmpty.style.display = 'block'; return; }
    data.forEach(s => elList.appendChild(buildSprintRow(s)));
  } catch (err) {
    elList.innerHTML = '';
    elError.style.display = 'block';
    elError.textContent = `Erro: ${err.message}`;
  }
}

function buildSprintRow(s) {
  const row = document.createElement('div');
  row.className = 'pk-row';

  const stateClass = SPRINT_STATE_CLASS[s.estado] || '';
  const stateLabel = SPRINT_STATE_LABEL[s.estado] || s.estado;

  let dateStr = '';
  if (s.data_inicio || s.data_fim) {
    const fmt = d => d ? new Date(d + 'T00:00:00').toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' }) : '?';
    dateStr = `${fmt(s.data_inicio)} – ${fmt(s.data_fim)}`;
  }

  row.innerHTML = `
    <div class="pk-row-main">
      <span class="pk-title">${escapeHtml(s.nome)}</span>
      <span class="badge badge-${stateClass}">${stateLabel}</span>
    </div>
    ${dateStr ? `<div class="pk-row-meta">📅 ${dateStr}</div>` : ''}`;

  row.addEventListener('click', () => {
    chrome.tabs.create({ url: `${apiUrl}/index.php?tab=sprints&sprint_id=${s.id}` });
  });
  return row;
}

// ── Deliverables (side panel only) ────────────────────────────
const DELIV_STATE_LABEL = { 'pending': 'Pendente', 'in-progress': 'Em curso', 'completed': 'Concluído' };
const DELIV_STATE_CLASS = { 'pending': 'state-aberta', 'in-progress': 'state-em-execucao', 'completed': 'state-concluida' };

async function fetchDeliverables() {
  if (!isSidePanel) return;
  const elList  = document.getElementById('deliverables-list');
  const elEmpty = document.getElementById('deliverables-empty');
  const elError = document.getElementById('deliverables-error');
  if (!elList) return;

  elList.innerHTML = '<div class="pk-loading">↻</div>';
  elEmpty.style.display = 'none';
  elError.style.display = 'none';

  try {
    const data = await apiFetch('/api/deliverables.php');
    elList.innerHTML = '';
    if (!data.length) { elEmpty.style.display = 'block'; return; }
    data.forEach(d => elList.appendChild(buildDeliverableRow(d)));
  } catch (err) {
    elList.innerHTML = '';
    elError.style.display = 'block';
    elError.textContent = `Erro: ${err.message}`;
  }
}

function buildDeliverableRow(d) {
  const row = document.createElement('div');
  row.className = 'pk-row';

  const stateClass = DELIV_STATE_CLASS[d.status] || '';
  const stateLabel = DELIV_STATE_LABEL[d.status] || d.status;

  let dateStr = '';
  if (d.due_date) {
    const date  = new Date(d.due_date + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const diff  = Math.round((date - today) / 86400000);
    const fmt   = date.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
    const cls   = diff < 0 ? 'overdue' : diff <= 3 ? 'soon' : '';
    dateStr = `<span class="deadline ${cls}">📅 ${fmt}</span>`;
  }

  const proj = d.short_name ? escapeHtml(d.short_name) : escapeHtml(d.project_title || '');

  row.innerHTML = `
    <div class="pk-row-main">
      <span class="pk-title">${escapeHtml(d.title)}</span>
      <span class="badge badge-${stateClass}">${stateLabel}</span>
    </div>
    <div class="pk-row-meta">
      <span class="pk-project">📁 ${proj}</span>
      ${dateStr}
    </div>`;

  row.addEventListener('click', () => {
    chrome.tabs.create({ url: `${apiUrl}/index.php?tab=projectos&project_id=${d.project_id}` });
  });
  return row;
}

// ── Clock (side panel only) ───────────────────────────────────
function startClock() {
  if (!isSidePanel) return;
  const el = document.getElementById('header-clock');
  if (!el) return;
  const tick = () => {
    el.textContent = new Date().toLocaleTimeString('pt-PT', {
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
  };
  tick();
  setInterval(tick, 1000);
}

// ── Pomodoro (side panel only) ────────────────────────────────
const POM = {
  focus:      25 * 60,
  shortBreak:  5 * 60,
  longBreak:  15 * 60,
};
const POM_LABEL = {
  focus:      '🍅 Foco',
  shortBreak: '☕ Pausa',
  longBreak:  '🛋 Pausa Longa',
};
const POM_COLOR = {
  focus:      '#ef4444',
  shortBreak: '#22c55e',
  longBreak:  '#3b82f6',
};

let pomPhase    = 'focus';
let pomLeft     = POM.focus;
let pomTotal    = POM.focus;
let pomRunning  = false;
let pomInterval = null;
let pomCount    = 0;   // sessões de foco concluídas no ciclo atual

function pomFmt(s) {
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

function pomRender() {
  const elTimer  = document.getElementById('pom-timer');
  const elPhase  = document.getElementById('pom-phase');
  const elToggle = document.getElementById('pom-toggle');
  const elBar    = document.getElementById('pom-bar');
  const elDots   = document.getElementById('pom-dots');
  if (!elTimer) return;

  elTimer.textContent  = pomFmt(pomLeft);
  elPhase.textContent  = POM_LABEL[pomPhase];
  elToggle.textContent = pomRunning ? '⏸' : '▶';
  elToggle.classList.toggle('active', pomRunning);

  const pct = ((pomTotal - pomLeft) / pomTotal) * 100;
  elBar.style.width      = pct + '%';
  elBar.style.background = POM_COLOR[pomPhase];

  // 4 pontos: ● concluído, ○ por fazer (ciclo de 4)
  const done = pomCount % 4;
  elDots.textContent = '●'.repeat(done) + '○'.repeat(4 - done);
  elDots.style.color = POM_COLOR[pomPhase];
}

function pomPlayDone() {
  try {
    if (!_audioCtx) _audioCtx = new AudioContext();
    if (_audioCtx.state === 'suspended') _audioCtx.resume();
    const ctx = _audioCtx;
    // 3 notas ascendentes: Dó5 → Mi5 → Sol5
    [523, 659, 784].forEach((freq, i) => {
      const t    = ctx.currentTime + i * 0.35;
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, t);
      gain.gain.setValueAtTime(0.45, t);
      gain.gain.exponentialRampToValueAtTime(0.001, t + 0.35);
      osc.start(t); osc.stop(t + 0.35);
    });
  } catch { /* AudioContext indisponível */ }
}

function pomNotify(label) {
  try {
    chrome.notifications.create({
      type: 'basic',
      iconUrl: 'icons/icon48.png',
      title: 'pikachuPM Pomodoro',
      message: label,
    });
  } catch { /* notificações indisponíveis */ }
}

function pomAdvance() {
  pomPlayDone();
  if (pomPhase === 'focus') {
    pomCount++;
    pomPhase = (pomCount % 4 === 0) ? 'longBreak' : 'shortBreak';
    pomNotify(pomCount % 4 === 0
      ? `🍅 × ${pomCount} — Pausa longa! Descansa 15 minutos.`
      : '🍅 Foco terminado! Pausa de 5 minutos.');
  } else {
    pomPhase = 'focus';
    pomNotify('☕ Pausa terminada! Hora de focar.');
  }
  pomLeft    = POM[pomPhase];
  pomTotal   = pomLeft;
  pomRunning = false;
  clearInterval(pomInterval);
  pomRender();
}

function pomTick() {
  if (pomLeft <= 1) { pomAdvance(); return; }
  pomLeft--;
  pomRender();
}

function pomToggle() {
  if (pomRunning) {
    clearInterval(pomInterval);
    pomRunning = false;
  } else {
    pomRunning  = true;
    pomInterval = setInterval(pomTick, 1000);
  }
  pomRender();
}

function pomReset() {
  clearInterval(pomInterval);
  pomRunning = false;
  pomLeft    = POM[pomPhase];
  pomTotal   = pomLeft;
  pomRender();
}

function initPomodoro() {
  if (!isSidePanel) return;
  pomRender();
  document.getElementById('pom-toggle')?.addEventListener('click', pomToggle);
  document.getElementById('pom-reset')?.addEventListener('click', pomReset);
}

// ── Leads (side panel only) ──────────────────────────────────
async function fetchLeads() {
  if (!isSidePanel) return;
  const elList  = document.getElementById('leads-list');
  const elEmpty = document.getElementById('leads-empty');
  const elError = document.getElementById('leads-error');
  if (!elList) return;

  elList.innerHTML = '<div class="pk-loading">↻</div>';
  elEmpty.style.display = 'none';
  elError.style.display = 'none';

  try {
    const data = await apiFetch('/api/leads.php');
    elList.innerHTML = '';
    if (!data.length) { elEmpty.style.display = 'block'; return; }
    data.forEach(l => elList.appendChild(buildLeadRow(l)));
  } catch (err) {
    elList.innerHTML = '';
    elError.style.display = 'block';
    elError.textContent = `Erro: ${err.message}`;
  }
}

function buildLeadRow(l) {
  const row = document.createElement('div');
  row.className = 'pk-row';

  // Relevância: pontos preenchidos de 1 a 10 (mostrar só até 5 para não ocupar espaço)
  const rel = Math.min(Math.max(parseInt(l.relevancia) || 0, 0), 10);
  const relDots = '●'.repeat(Math.round(rel / 2)) + '○'.repeat(5 - Math.round(rel / 2));

  let dateStr = '';
  if (l.data_fim) {
    const date  = new Date(l.data_fim + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const diff  = Math.round((date - today) / 86400000);
    const fmt   = date.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
    const cls   = diff < 0 ? 'overdue' : diff <= 3 ? 'soon' : '';
    dateStr = `<span class="deadline ${cls}">📅 ${fmt}</span>`;
  }

  const role = l.is_responsible == 1
    ? '<span class="pk-role">responsável</span>'
    : '<span class="pk-role pk-role-member">membro</span>';

  row.innerHTML = `
    <div class="pk-row-main">
      <span class="pk-title">${escapeHtml(l.titulo)}</span>
      <span class="pk-relevance" title="Relevância ${rel}/10">${relDots}</span>
    </div>
    <div class="pk-row-meta">
      ${role}
      ${dateStr}
    </div>`;

  row.addEventListener('click', () => {
    chrome.tabs.create({ url: `${apiUrl}/index.php?tab=leads&lead_id=${l.id}` });
  });
  return row;
}

// ── Personal checklist (local, never sent to server) ─────────
const PERSONAL_KEY = 'pikachu_personal_v1';

function personalLoad() {
  try { return JSON.parse(localStorage.getItem(PERSONAL_KEY) || '[]'); } catch { return []; }
}
function personalSave(items) {
  try { localStorage.setItem(PERSONAL_KEY, JSON.stringify(items)); } catch {}
}

function personalRender() {
  const items   = personalLoad();
  const listEl  = document.getElementById('personal-list');
  const emptyEl = document.getElementById('personal-empty');
  const clearEl = document.getElementById('personal-clear');
  if (!listEl) return;

  if (clearEl) clearEl.style.display = items.some(i => i.done) ? '' : 'none';
  if (emptyEl) emptyEl.style.display = items.length === 0 ? '' : 'none';

  listEl.innerHTML = items.map(item => `
    <div class="p-item${item.done ? ' done' : ''}" data-id="${item.id}">
      <label class="p-check">
        <input type="checkbox"${item.done ? ' checked' : ''} data-id="${escapeHtml(item.id)}">
        <span class="p-text">${escapeHtml(item.text)}</span>
      </label>
      <button class="p-del" data-id="${escapeHtml(item.id)}" title="Apagar">✕</button>
    </div>`).join('');

  listEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => {
      const arr  = personalLoad();
      const item = arr.find(i => i.id === cb.dataset.id);
      if (item) { item.done = cb.checked; personalSave(arr); personalRender(); }
    });
  });
  listEl.querySelectorAll('.p-del').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      personalSave(personalLoad().filter(i => i.id !== btn.dataset.id));
      personalRender();
    });
  });
}

function personalAdd(text) {
  text = (text || '').trim();
  if (!text) return;
  const items = personalLoad();
  items.unshift({ id: Date.now() + '_' + Math.random().toString(36).slice(2, 6), text, done: false });
  personalSave(items);
  personalRender();
}

function initPersonal() {
  const input    = document.getElementById('personal-input');
  const addBtn   = document.getElementById('personal-add-btn');
  const clearBtn = document.getElementById('personal-clear');
  addBtn?.addEventListener('click', () => { if (input) { personalAdd(input.value); input.value = ''; input.focus(); } });
  input?.addEventListener('keydown', e => { if (e.key === 'Enter') { personalAdd(input.value); input.value = ''; } });
  clearBtn?.addEventListener('click', () => { personalSave(personalLoad().filter(i => !i.done)); personalRender(); });
  personalRender();
}

// Helper: show/hide todos vs personal panel
function showPersonalView(show) {
  const searchBar    = document.getElementById('search-bar');
  const personalEl   = document.getElementById('personal-panel');
  if (searchBar)  searchBar.style.display  = show ? 'none' : '';
  elTodosList.style.display    = show ? 'none' : '';
  elLoading.style.display      = show ? 'none' : '';
  elErrorMsg.style.display     = show ? 'none' : '';
  elEmptyState.style.display   = show ? 'none' : '';
  elFab.style.display          = show ? 'none' : 'flex';
  if (personalEl) personalEl.style.display = show ? 'flex' : 'none';
  if (show) personalRender();
}

// ── Compact mode (side panel only) ────────────────────────────
function applyCompact(active) {
  document.body.classList.toggle('compact', active);
  const btn = document.getElementById('btn-compact');
  if (btn) btn.classList.toggle('active', active);
}

// ── MQTT (side panel only) ─────────────────────────────────────
const MAX_MQTT_MSGS = 30;

// Ping sonoro ao receber mensagem MQTT
let _audioCtx  = null;
let _mqttSound = true; // carregado do storage em init()

function playPing() {
  if (!_mqttSound) return;
  try {
    if (!_audioCtx) _audioCtx = new AudioContext();
    if (_audioCtx.state === 'suspended') _audioCtx.resume();

    const ctx  = _audioCtx;
    const t    = ctx.currentTime;
    const osc  = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.connect(gain);
    gain.connect(ctx.destination);

    // Ping curto em Lá5 (880 Hz), decaimento em 350 ms
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, t);
    osc.frequency.exponentialRampToValueAtTime(660, t + 0.35);
    gain.gain.setValueAtTime(0.35, t);
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.35);

    osc.start(t);
    osc.stop(t + 0.35);
  } catch { /* AudioContext indisponível */ }
}

const STATE_MQTT_LABEL = {
  connected:    'Ligado',
  connecting:   'A ligar…',
  error:        'Erro de ligação',
  disconnected: 'Desligado',
};

function setMqttDot(state, detail = '') {
  // Tab dot
  const dot = document.getElementById('mqtt-dot');
  if (dot) dot.className = 'mqtt-dot ' + (state || '');

  // Status bar inside panel
  const bar  = document.getElementById('mqtt-status-bar');
  const text = document.getElementById('mqtt-status-text');
  if (!bar || !text) return;
  bar.className = 'mqtt-status-bar ' + (state || '');
  const label = STATE_MQTT_LABEL[state] || 'Desligado';
  text.textContent = detail ? `${label} — ${detail}` : label;
}

function addMqttMessage(topic, payload, direction = 'incoming') {
  const feed  = document.getElementById('mqtt-feed');
  const empty = document.getElementById('mqtt-empty');
  if (!feed) return;

  empty.style.display = 'none';

  const now  = new Date();
  const time = now.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

  const row = document.createElement('div');
  row.className = `mqtt-msg ${direction}`;
  row.innerHTML = `
    <span class="mqtt-msg-topic" title="${escapeHtml(topic)}">${escapeHtml(topic)}</span>
    <span class="mqtt-msg-payload" title="${escapeHtml(payload)}">${escapeHtml(payload)}</span>
    <span class="mqtt-msg-time">${time}</span>`;
  feed.prepend(row);

  // Keep max messages
  while (feed.children.length > MAX_MQTT_MSGS) feed.lastChild.remove();
}

async function loadMqttHistory() {
  if (!isSidePanel) return;
  const { mqttMessages = [] } = await chrome.storage.local.get(['mqttMessages']);
  const feed  = document.getElementById('mqtt-feed');
  const empty = document.getElementById('mqtt-empty');
  if (!feed) return;

  feed.innerHTML = '';
  if (!mqttMessages.length) { empty.style.display = 'block'; return; }
  empty.style.display = 'none';

  mqttMessages.slice(0, MAX_MQTT_MSGS).forEach(m => {
    const time = new Date(m.ts).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const row  = document.createElement('div');
    row.className = 'mqtt-msg incoming';
    row.innerHTML = `
      <span class="mqtt-msg-topic" title="${escapeHtml(m.topic)}">${escapeHtml(m.topic)}</span>
      <span class="mqtt-msg-payload" title="${escapeHtml(m.payload)}">${escapeHtml(m.payload)}</span>
      <span class="mqtt-msg-time">${time}</span>`;
    feed.appendChild(row);
  });
}

async function initMqttPubTopic() {
  const { mqttPubTopic } = await chrome.storage.sync.get(['mqttPubTopic']);
  const el = document.getElementById('mqtt-pub-topic-input');
  if (el && mqttPubTopic) el.value = mqttPubTopic;
}

// ── Init ──────────────────────────────────────────────────────
async function init() {
  const config = await loadConfig();

  if (!config.apiUrl || !config.token) {
    elNotConfigured.style.display = 'flex';
    elMainContent.style.display   = 'none';
    elFab.style.display           = 'none';
    return;
  }

  elNotConfigured.style.display = 'none';
  elMainContent.style.display   = 'flex';
  elFab.style.display           = 'flex';

  initPersonal();

  if (isSidePanel) {
    applyCompact(config.compactMode);
    startClock();
    initPomodoro();
    await Promise.all([
      fetchTodos(), fetchCalendar(),
      fetchSprints(), fetchDeliverables(), fetchLeads(),
      loadMqttHistory(), initMqttPubTopic(),
    ]);
  } else {
    await fetchTodos();
  }
}

// ── Event listeners ───────────────────────────────────────────
document.getElementById('btn-settings').addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
});

document.getElementById('btn-go-settings').addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
});

document.getElementById('btn-refresh').addEventListener('click', () => {
  fetchTodos();
  fetchCalendar();
  fetchSprints();
  fetchDeliverables();
  fetchLeads();
});

document.getElementById('btn-retry').addEventListener('click', fetchTodos);

// Abrir painel lateral Firefox (no popup)
const btnSidebar = document.getElementById('btn-sidebar');
if (btnSidebar) {
  btnSidebar.addEventListener('click', async () => {
    await browser.sidebarAction.toggle();
    window.close();
  });
}

// Fechar painel lateral Firefox (no sidebar)
const btnSidebarClose = document.getElementById('btn-sidebar-close');
if (btnSidebarClose) {
  btnSidebarClose.addEventListener('click', () => {
    browser.sidebarAction.close();
  });
}

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentFilter = tab.dataset.state;
    if (currentFilter === 'personal') {
      showPersonalView(true);
    } else {
      showPersonalView(false);
      renderTodos();
    }
  });
});

document.getElementById('search-input').addEventListener('input', (e) => {
  searchQuery = e.target.value;
  renderTodos();
});

document.getElementById('btn-add-todo').addEventListener('click', () => openModal());
document.getElementById('btn-modal-close').addEventListener('click', closeModal);
document.getElementById('btn-modal-cancel').addEventListener('click', closeModal);
document.getElementById('btn-modal-save').addEventListener('click', saveTodo);

elModalOverlay.addEventListener('click', (e) => { if (e.target === elModalOverlay) closeModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

// ── Bottom tabs (Calendar | MQTT) ─────────────────────────────
document.querySelectorAll('.btab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.btab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const target = btn.dataset.panel;
    document.querySelectorAll('.bottom-content').forEach(el => {
      el.style.display = el.id === `panel-${target}` ? 'flex' : 'none';
    });
  });
});

// ── MQTT publish ──────────────────────────────────────────────
const mqttPubBtn = document.getElementById('mqtt-pub-btn');
if (mqttPubBtn) {
  mqttPubBtn.addEventListener('click', async () => {
    const topicEl   = document.getElementById('mqtt-pub-topic-input');
    const payloadEl = document.getElementById('mqtt-pub-msg-input');
    const topic     = topicEl.value.trim();
    const payload   = payloadEl.value.trim();
    if (!topic || !payload) { showToast('Preenche o tópico e a mensagem.', 'error'); return; }

    mqttPubBtn.disabled = true;
    try {
      const res = await chrome.runtime.sendMessage({ type: 'mqtt-publish', topic, payload });
      if (res && res.ok === false) {
        showToast(res.error || 'Não foi possível enviar — broker desligado?', 'error');
      } else {
        addMqttMessage(topic, payload, 'outgoing');
        payloadEl.value = '';
        showToast('Mensagem enviada', 'success');
      }
    } catch (err) {
      showToast('Erro ao enviar: ' + err.message, 'error');
    } finally {
      mqttPubBtn.disabled = false;
    }
  });

  // Send on Enter in message field
  document.getElementById('mqtt-pub-msg-input')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') mqttPubBtn.click();
  });
}

// ── MQTT reconnect button ─────────────────────────────────────
const mqttReconnectBtn = document.getElementById('mqtt-reconnect-btn');
if (mqttReconnectBtn) {
  mqttReconnectBtn.addEventListener('click', async () => {
    const { mqttEnabled } = await chrome.storage.sync.get(['mqttEnabled']);
    if (!mqttEnabled) { showToast('MQTT não está ativado nas definições.', 'error'); return; }
    setMqttDot('connecting', '');
    mqttReconnectBtn.disabled = true;
    try {
      await chrome.runtime.sendMessage({ type: 'mqtt-reconnect' });
    } catch (err) {
      showToast('Erro ao religar: ' + err.message, 'error');
    } finally {
      setTimeout(() => { mqttReconnectBtn.disabled = false; }, 3000);
    }
  });
}

// ── MQTT runtime messages ─────────────────────────────────────
if (isSidePanel) {
  chrome.runtime.onMessage.addListener((msg) => {
    if (msg.type === 'mqtt-message') {
      addMqttMessage(msg.topic, msg.payload, 'incoming');
      playPing();
    }
    if (msg.type === 'mqtt-status') {
      setMqttDot(msg.state, msg.error || msg.brokerUrl || '');
    }
  });

  // Ask current connection status on load
  chrome.runtime.sendMessage({ type: 'mqtt-status-request' }).then(res => {
    if (res) setMqttDot(res.state || (res.connected ? 'connected' : 'disconnected'));
  }).catch(() => {});
}

init();
