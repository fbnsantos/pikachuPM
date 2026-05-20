// ── Constants ──────────────────────────────────────────────
const STATE_LABELS = {
  'aberta':      'Aberta',
  'em execução': 'Em Execução',
  'suspensa':    'Suspensa',
  'concluída':   'Concluída',
};

const STATE_CLASSES = {
  'aberta':      'aberta',
  'em execução': 'em-execucao',
  'suspensa':    'suspensa',
  'concluída':   'concluida',
};

const CAL_COLORS = {
  green: '#22c55e', grey: '#6b7280', gray: '#6b7280',
  blue: '#3b82f6',  orange: '#f59e0b', purple: '#a855f7', red: '#ef4444',
};

const TIPO_LABELS = {
  ferias: 'Férias', aulas: 'Aulas', demo: 'Demo',
  campo: 'Campo',   tribe: 'Tribe', outro: 'Outro',
};

const isSidePanel = document.body.dataset.mode === 'sidepanel';

// ── State ───────────────────────────────────────────────────
let todos = [];
let currentFilter = 'all';
let searchQuery   = '';
let editingId     = null;
let apiUrl        = '';
let token         = '';
let compactMode   = false;

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
    chrome.storage.sync.get(['apiUrl', 'token', 'compactMode'], (result) => {
      apiUrl      = (result.apiUrl || '').replace(/\/$/, '');
      token       = result.token || '';
      compactMode = !!result.compactMode;
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

  card.innerHTML = `
    <div class="todo-header">
      <span class="todo-title">${escapeHtml(todo.titulo || '(sem título)')}</span>
      <span class="badge badge-${stateKey}">${STATE_LABELS[todo.estado] || todo.estado}</span>
    </div>
    <div class="todo-meta">
      ${deadlineHtml}
      ${todo.responsavel_nome ? `<span>👤 ${escapeHtml(todo.responsavel_nome)}</span>` : ''}
    </div>
    <div class="todo-actions">
      ${stateButtons}
      <button class="btn-open" title="Abrir no pikachuPM">↗ Abrir</button>
      <button class="btn-delete" title="Eliminar">🗑</button>
    </div>`;

  card.addEventListener('click', (e) => {
    if (e.target.closest('button')) return;
    card.classList.toggle('expanded');
  });

  card.querySelectorAll('.btn-state').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const newState = btn.dataset.state;
      if (newState === todo.estado) return;
      await updateTodoState(todo, newState, card);
    });
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
  document.getElementById('todo-data-limite').value = todo ? (todo.data_limite || '') : '';
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

// ── Compact mode (side panel only) ────────────────────────────
function applyCompact(active) {
  document.body.classList.toggle('compact', active);
  const btn = document.getElementById('btn-compact');
  if (btn) btn.classList.toggle('active', active);
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

  if (isSidePanel) applyCompact(config.compactMode);

  await Promise.all([fetchTodos(), fetchCalendar()]);
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
});

document.getElementById('btn-retry').addEventListener('click', fetchTodos);

// Compact toggle (side panel only)
const btnCompact = document.getElementById('btn-compact');
if (btnCompact) {
  btnCompact.addEventListener('click', async () => {
    compactMode = !compactMode;
    applyCompact(compactMode);
    await chrome.storage.sync.set({ compactMode });
    if (compactMode) {
      showToast('Modo compacto ativo — arrasta o bordo esquerdo do painel para o tornar mais estreito', '');
    }
  });
}

// Pin: popup → side panel persistente
const btnPin = document.getElementById('btn-pin');
if (btnPin) {
  btnPin.addEventListener('click', async () => {
    await chrome.storage.sync.set({ sidebarMode: true });
    await chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true });
    const win = await chrome.windows.getCurrent();
    await chrome.sidePanel.open({ windowId: win.id });
    window.close();
  });
}

// Unpin: side panel → popup normal
const btnUnpin = document.getElementById('btn-unpin');
if (btnUnpin) {
  btnUnpin.addEventListener('click', async () => {
    await chrome.storage.sync.set({ sidebarMode: false });
    await chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: false });
    window.close();
  });
}

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentFilter = tab.dataset.state;
    renderTodos();
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

init();
