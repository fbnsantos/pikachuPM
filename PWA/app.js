'use strict';

// ══════════════════════════════════════════════════════
// CONFIG
// ══════════════════════════════════════════════════════
const CFG_KEY = 'pikachu_pwa_cfg_v1';

const DEFAULT_CFG = {
  apiUrl:      '',
  token:       '',
  mqttEnabled: false,
  mqttBroker:  '',
  mqttUser:    '',
  mqttPass:    '',
  mqttTopics:  [],
  pomFocus:    25,
  pomShort:    5,
  pomLong:     15,
};

let cfg = { ...DEFAULT_CFG };

function loadCfg() {
  try {
    const s = localStorage.getItem(CFG_KEY);
    if (s) cfg = { ...DEFAULT_CFG, ...JSON.parse(s) };
  } catch(e) {}
}

function saveCfg() {
  try { localStorage.setItem(CFG_KEY, JSON.stringify(cfg)); } catch(e) {}
}

function isConfigured() {
  return !!(cfg.apiUrl && cfg.token);
}

// ══════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════
let allTodos      = [];
let filterState   = 'all';
let searchQuery   = '';
let editingTodoId = null;
let mqttClient    = null;
let mqttMessages  = [];
const MAX_MQTT_MSGS = 50;

let pendingTodoAttachments  = [];
let pendingStoryAttachments = [];

// ══════════════════════════════════════════════════════
// API
// ══════════════════════════════════════════════════════
function apiUrl(path) {
  const base = cfg.apiUrl.endsWith('/') ? cfg.apiUrl : cfg.apiUrl + '/';
  return base + 'api/' + path;
}

async function apiFetch(path, options = {}) {
  const headers = {
    'Authorization': `Bearer ${cfg.token}`,
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };
  const resp = await fetch(apiUrl(path), { ...options, headers });
  if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
  return resp.json();
}

async function fetchTodos() {
  const params = filterState !== 'all' ? `?estado=${encodeURIComponent(filterState)}` : '';
  const data = await apiFetch(`todos.php${params}`);
  return data.todos || [];
}

async function saveTodo(todo) {
  if (todo.id) {
    return apiFetch(`todos.php?id=${todo.id}`, { method: 'PUT', body: JSON.stringify(todo) });
  } else {
    return apiFetch('todos.php', { method: 'POST', body: JSON.stringify(todo) });
  }
}

async function deleteTodo(id) {
  return apiFetch(`todos.php?id=${id}`, { method: 'DELETE' });
}

async function fetchSprints() {
  const data = await apiFetch('sprints.php');
  return Array.isArray(data) ? data : (data.sprints || []);
}

async function fetchDeliverables() {
  const data = await apiFetch('deliverables.php');
  return Array.isArray(data) ? data : (data.deliverables || []);
}

async function fetchLeads() {
  const data = await apiFetch('leads.php');
  return Array.isArray(data) ? data : (data.leads || []);
}

async function fetchCalendar() {
  const data = await apiFetch('calendar.php?days=30');
  return Array.isArray(data) ? data : (data.events || []);
}

// ══════════════════════════════════════════════════════
// RENDER: TODOS
// ══════════════════════════════════════════════════════
// CALENDAR HELPERS
// ══════════════════════════════════════════════════════
const CAL_COLORS = {
  green: '#22c55e', grey: '#6b7280', gray: '#6b7280',
  blue: '#3b82f6', orange: '#f59e0b', purple: '#a855f7', red: '#ef4444',
};
const TIPO_LABELS = {
  ferias: 'Férias', aulas: 'Aulas', demo: 'Demo',
  campo: 'Campo', tribe: 'Tribe', outro: 'Outro',
};

function formatCalDate(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((d - today) / 86400000);
  if (diff === 0) return { label: 'Hoje', isToday: true };
  if (diff === 1) return { label: 'Amanhã', isToday: false };
  if (diff <= 6) return { label: d.toLocaleDateString('pt-PT', { weekday: 'short' }), isToday: false };
  return { label: d.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' }), isToday: false };
}

function buildCalEvent(ev) {
  const color = CAL_COLORS[ev.cor] || CAL_COLORS[ev.tipo] || '#6b7280';
  const { label, isToday } = formatCalDate(ev.data);
  const tipo = TIPO_LABELS[ev.tipo] || ev.tipo || '';
  const hora = ev.hora ? ev.hora.slice(0, 5) + ' · ' : '';
  return `<div class="cal-event${isToday ? ' cal-today' : ''}">
    <span class="cal-dot" style="background:${color}"></span>
    <span class="cal-date">${escHtml(label)}</span>
    <span class="cal-desc">${escHtml(hora + (ev.descricao || tipo))}</span>
    <span class="cal-tipo">${escHtml(tipo)}</span>
  </div>`;
}

async function loadCalendar() {
  const listEl  = document.getElementById('calendar-list');
  const emptyEl = document.getElementById('cal-empty');
  const errEl   = document.getElementById('cal-error');
  const spinEl  = document.getElementById('cal-loading');
  if (!listEl) return;
  if (spinEl) spinEl.style.display = '';
  listEl.innerHTML = '';
  if (emptyEl) emptyEl.style.display = 'none';
  if (errEl)   errEl.style.display   = 'none';
  try {
    const events = await fetchCalendar();
    if (spinEl) spinEl.style.display = 'none';
    if (!events.length) { if (emptyEl) emptyEl.style.display = ''; return; }
    listEl.innerHTML = events.map(buildCalEvent).join('');
  } catch(e) {
    if (spinEl) spinEl.style.display = 'none';
    if (errEl)  { errEl.textContent = 'Erro: ' + e.message; errEl.style.display = ''; }
  }
}

// ══════════════════════════════════════════════════════
const ESTADO_LABEL = {
  'aberta':      'Aberta',
  'em execução': 'A fazer',
  'suspensa':    'Pausada',
  'completada':  'Concluída',
};
const ESTADO_CLASS = {
  'aberta':      'badge-aberta',
  'em execução': 'badge-execucao',
  'suspensa':    'badge-suspensa',
  'completada':  'badge-completada',
};

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s);
  if (isNaN(d)) return s;
  const now = new Date();
  const diff = Math.ceil((d - now) / 86400000);
  const str = d.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
  if (diff < 0)  return `⚠ ${str}`;
  if (diff === 0) return `Hoje`;
  if (diff === 1) return `Amanhã`;
  return str;
}

function escHtml(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function buildTodoCard(todo) {
  const done   = todo.estado === 'completada';
  const estado = ESTADO_LABEL[todo.estado] || todo.estado;
  const cls    = ESTADO_CLASS[todo.estado] || '';
  const date   = todo.data_limite ? fmtDate(todo.data_limite) : '';
  const autor  = todo.autor_nome ? `✏️ ${escHtml(todo.autor_nome)}` : '';
  const resp   = todo.responsavel_nome ? `👤 ${escHtml(todo.responsavel_nome)}` : '';

  return `<div class="todo-card ${done ? 'done' : ''}" data-id="${todo.id}">
    <div class="todo-header">
      <span class="todo-title">${escHtml(todo.titulo)}</span>
      <span class="badge ${cls}">${estado}</span>
    </div>
    <div class="todo-meta">
      ${date    ? `<span>📅 ${date}</span>` : ''}
      ${autor   ? `<span>${autor}</span>` : ''}
      ${resp    ? `<span>${resp}</span>` : ''}
    </div>
    <div class="todo-actions">
      <button class="todo-action-btn edit-btn" data-id="${todo.id}">✏️ Editar</button>
      <button class="todo-action-btn done-btn" data-id="${todo.id}" data-done="${done}">
        ${done ? '↩ Reabrir' : '✔ Concluir'}
      </button>
    </div>
  </div>`;
}

function applyFilter(todos) {
  let list = todos;
  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    list = list.filter(t =>
      (t.titulo || '').toLowerCase().includes(q) ||
      (t.descritivo || '').toLowerCase().includes(q)
    );
  }
  return list;
}

function renderTodos(todos) {
  const loadEl   = document.getElementById('loading');
  const errEl    = document.getElementById('error-msg');
  const emptyEl  = document.getElementById('empty-state');
  const listEl   = document.getElementById('todos-list');
  const fabEl    = document.getElementById('btn-add-todo');

  loadEl.style.display  = 'none';
  errEl.style.display   = 'none';

  const filtered = applyFilter(todos);

  if (filtered.length === 0) {
    emptyEl.style.display = '';
    listEl.innerHTML = '';
  } else {
    emptyEl.style.display = 'none';
    listEl.innerHTML = filtered.map(buildTodoCard).join('');
  }

  if (fabEl) fabEl.style.display = '';
}

// ══════════════════════════════════════════════════════
// LOAD TODOS
// ══════════════════════════════════════════════════════
async function loadTodos() {
  const loadEl  = document.getElementById('loading');
  const errEl   = document.getElementById('error-msg');
  const emptyEl = document.getElementById('empty-state');
  const listEl  = document.getElementById('todos-list');
  const fabEl   = document.getElementById('btn-add-todo');

  loadEl.style.display  = '';
  errEl.style.display   = 'none';
  emptyEl.style.display = 'none';
  listEl.innerHTML      = '';
  if (fabEl) fabEl.style.display = 'none';

  try {
    allTodos = await fetchTodos();
    renderTodos(allTodos);
  } catch(e) {
    loadEl.style.display = 'none';
    errEl.style.display  = '';
    document.getElementById('error-text').textContent = 'Erro ao carregar todos: ' + e.message;
  }
}

// ══════════════════════════════════════════════════════
// RENDER: BOTTOM PANELS
// ══════════════════════════════════════════════════════
function buildPkRow(item, isLead = false) {
  const relevDots = item.relevancia
    ? '★'.repeat(Math.min(5, item.relevancia)) + '☆'.repeat(Math.max(0, 5 - item.relevancia))
    : '';
  const roleClass = item.is_responsible ? '' : 'pk-role-member';
  const roleLabel = item.is_responsible ? 'Responsável' : 'Membro';
  // data_fim (sprints/leads) ou due_date (deliverables)
  const dateEnd   = item.data_fim ? fmtDate(item.data_fim) : (item.due_date ? fmtDate(item.due_date) : '');
  // título: varios campos possíveis consoante a API
  const titulo    = item.titulo || item.nome || item.title || '';
  // projeto: varios campos possíveis
  const projeto   = item.projeto_nome || item.project_name || item.project_title || item.short_name || '';

  return `<div class="pk-row">
    <div class="pk-row-main">
      <span class="pk-title">${escHtml(titulo)}</span>
      ${relevDots ? `<span class="pk-relevance">${relevDots}</span>` : ''}
      ${isLead ? `<span class="pk-role ${roleClass}">${roleLabel}</span>` : ''}
    </div>
    <div class="pk-row-meta">
      ${projeto ? `<span class="pk-project">${escHtml(projeto)}</span>` : ''}
      ${dateEnd ? `<span>📅 ${dateEnd}</span>` : ''}
    </div>
  </div>`;
}

async function loadSprints() {
  const listEl  = document.getElementById('sprints-list');
  const emptyEl = document.getElementById('sprints-empty');
  const errEl   = document.getElementById('sprints-error');
  listEl.innerHTML = '<div class="pk-empty">A carregar…</div>';
  try {
    const items = await fetchSprints();
    listEl.innerHTML = items.map(s => buildPkRow(s)).join('');
    emptyEl.style.display = items.length === 0 ? '' : 'none';
    errEl.style.display   = 'none';
  } catch(e) {
    listEl.innerHTML = '';
    errEl.textContent = 'Erro: ' + e.message;
    errEl.style.display = '';
  }
}

async function loadDeliverables() {
  const listEl  = document.getElementById('deliverables-list');
  const emptyEl = document.getElementById('deliverables-empty');
  const errEl   = document.getElementById('deliverables-error');
  listEl.innerHTML = '<div class="pk-empty">A carregar…</div>';
  try {
    const items = await fetchDeliverables();
    listEl.innerHTML = items.map(s => buildPkRow(s)).join('');
    emptyEl.style.display = items.length === 0 ? '' : 'none';
    errEl.style.display   = 'none';
  } catch(e) {
    listEl.innerHTML = '';
    errEl.textContent = 'Erro: ' + e.message;
    errEl.style.display = '';
  }
}

async function loadLeads() {
  const listEl  = document.getElementById('leads-list');
  const emptyEl = document.getElementById('leads-empty');
  const errEl   = document.getElementById('leads-error');
  listEl.innerHTML = '<div class="pk-empty">A carregar…</div>';
  try {
    const items = await fetchLeads();
    listEl.innerHTML = items.map(s => buildPkRow(s, true)).join('');
    emptyEl.style.display = items.length === 0 ? '' : 'none';
    errEl.style.display   = 'none';
  } catch(e) {
    listEl.innerHTML = '';
    errEl.textContent = 'Erro: ' + e.message;
    errEl.style.display = '';
  }
}

// ══════════════════════════════════════════════════════
// BOTTOM PANEL
// ══════════════════════════════════════════════════════
let activePanel     = 'calendar';
let panelOpen       = true;
let panelExpandedH  = 220;  // updated by drag and localStorage

function switchPanel(panel) {
  const samePanel = panel === activePanel;
  const panelEl   = document.getElementById('bottom-panel');

  if (samePanel) {
    // Toggle open/closed
    panelOpen = !panelOpen;
    panelEl.classList.toggle('collapsed', !panelOpen);
    panelEl.style.height = panelOpen ? panelExpandedH + 'px' : '44px';
    return;
  }

  panelOpen   = true;
  activePanel = panel;
  panelEl.classList.remove('collapsed');
  panelEl.style.height = panelExpandedH + 'px';

  // Tabs
  document.querySelectorAll('.btab').forEach(b => b.classList.remove('active'));
  document.querySelector(`.btab[data-panel="${panel}"]`)?.classList.add('active');

  // Content panels
  document.querySelectorAll('.bottom-content').forEach(c => {
    c.style.display = 'none';
    c.classList.remove('active');
  });
  const target = document.getElementById('panel-' + panel);
  if (target) { target.style.display = ''; target.classList.add('active'); }

  // Load data on first switch (lazy)
  if (panel === 'calendar'     && !calendar_loaded)     { calendar_loaded     = true; loadCalendar(); }
  if (panel === 'sprints'      && !sprints_loaded)      { sprints_loaded      = true; loadSprints(); }
  if (panel === 'deliverables' && !deliverables_loaded)  { deliverables_loaded = true; loadDeliverables(); }
  if (panel === 'leads'        && !leads_loaded)         { leads_loaded        = true; loadLeads(); }
}

let calendar_loaded = false, sprints_loaded = false, deliverables_loaded = false, leads_loaded = false;

// ══════════════════════════════════════════════════════
// MODAL: Create / Edit Todo
// ══════════════════════════════════════════════════════
function openModal(todo = null) {
  editingTodoId = todo ? todo.id : null;
  document.getElementById('modal-title').textContent = todo ? 'Editar Todo' : 'Novo Todo';
  document.getElementById('todo-titulo').value = todo?.titulo || '';
  document.getElementById('todo-descritivo').value = todo?.descritivo || '';
  document.getElementById('todo-estado').value = todo?.estado || 'aberta';
  document.getElementById('todo-data-limite').value = todo?.data_limite?.split('T')[0] || '';
  pendingTodoAttachments = [];
  const th = document.getElementById('todo-attach-thumbs');
  if (th) th.innerHTML = '';
  const ci = document.getElementById('todo-file-camera');
  const gi = document.getElementById('todo-file-gallery');
  if (ci) ci.value = '';
  if (gi) gi.value = '';
  document.getElementById('modal-overlay').style.display = '';
  setTimeout(() => document.getElementById('todo-titulo').focus(), 100);
}

function closeModal() {
  document.getElementById('modal-overlay').style.display = 'none';
  editingTodoId = null;
}

async function saveModal() {
  const titulo = document.getElementById('todo-titulo').value.trim();
  if (!titulo) { showToast('O título é obrigatório', 'error'); return; }

  const todo = {
    id:          editingTodoId,
    titulo,
    descritivo:  document.getElementById('todo-descritivo').value.trim(),
    estado:      document.getElementById('todo-estado').value,
    data_limite: document.getElementById('todo-data-limite').value || null,
  };

  const btn = document.getElementById('btn-modal-save');
  const wasEditing = !!editingTodoId;
  btn.disabled = true;
  try {
    const result = await saveTodo(todo);
    const savedId = editingTodoId || result?.todo?.id;
    const filesToUpload = pendingTodoAttachments.filter(Boolean);
    pendingTodoAttachments = [];
    closeModal();
    showToast(wasEditing ? 'Todo atualizado!' : 'Todo criado!', 'success');
    if (filesToUpload.length && savedId) await uploadAttachments(filesToUpload, savedId, 'todo');
    await loadTodos();
  } catch(e) {
    showToast('Erro ao guardar: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

// ══════════════════════════════════════════════════════
// SETTINGS SCREEN
// ══════════════════════════════════════════════════════
function openSettings() {
  document.getElementById('cfg-api-url').value        = cfg.apiUrl || '';
  document.getElementById('cfg-token').value          = cfg.token  || '';
  document.getElementById('cfg-mqtt-enabled').checked = cfg.mqttEnabled || false;
  document.getElementById('cfg-mqtt-broker').value    = cfg.mqttBroker || '';
  document.getElementById('cfg-mqtt-user').value      = cfg.mqttUser  || '';
  document.getElementById('cfg-mqtt-pass').value      = cfg.mqttPass  || '';
  document.getElementById('cfg-mqtt-topics').value    = (cfg.mqttTopics || []).join('\n');
  document.getElementById('cfg-pom-focus').value      = cfg.pomFocus || 25;
  document.getElementById('cfg-pom-short').value      = cfg.pomShort || 5;
  document.getElementById('cfg-pom-long').value       = cfg.pomLong  || 15;
  updateMqttFields();
  document.getElementById('settings-screen').style.display = '';
}

function closeSettings() {
  document.getElementById('settings-screen').style.display = 'none';
}

function saveSettings() {
  cfg.apiUrl      = document.getElementById('cfg-api-url').value.trim().replace(/\/$/, '') + '/';
  cfg.token       = document.getElementById('cfg-token').value.trim();
  cfg.mqttEnabled = document.getElementById('cfg-mqtt-enabled').checked;
  cfg.mqttBroker  = document.getElementById('cfg-mqtt-broker').value.trim();
  cfg.mqttUser    = document.getElementById('cfg-mqtt-user').value.trim();
  cfg.mqttPass    = document.getElementById('cfg-mqtt-pass').value;
  cfg.mqttTopics  = document.getElementById('cfg-mqtt-topics').value.split('\n').map(s => s.trim()).filter(Boolean);
  cfg.pomFocus    = parseInt(document.getElementById('cfg-pom-focus').value) || 25;
  cfg.pomShort    = parseInt(document.getElementById('cfg-pom-short').value) || 5;
  cfg.pomLong     = parseInt(document.getElementById('cfg-pom-long').value)  || 15;
  saveCfg();
  closeSettings();
  showToast('Definições guardadas!', 'success');

  // Re-init MQTT
  if (cfg.mqttEnabled && cfg.mqttBroker) mqttConnect();
  else if (mqttClient) { try { mqttClient.end(true); } catch(e) {} mqttClient = null; mqttSetState('disconnected'); }

  // Show/hide main content
  if (isConfigured()) {
    document.getElementById('setup-screen').style.display = 'none';
    document.getElementById('main-content').style.display = '';
    loadTodos();
  }
}

function updateMqttFields() {
  const enabled = document.getElementById('cfg-mqtt-enabled').checked;
  document.getElementById('mqtt-fields').classList.toggle('hidden', !enabled);
}

// ══════════════════════════════════════════════════════
// SETUP SCREEN
// ══════════════════════════════════════════════════════
function saveSetup() {
  const apiUrl = document.getElementById('setup-api-url').value.trim();
  const token  = document.getElementById('setup-token').value.trim();
  if (!apiUrl || !token) { showToast('Preenche o URL e o token', 'error'); return; }
  cfg.apiUrl = apiUrl.endsWith('/') ? apiUrl : apiUrl + '/';
  cfg.token  = token;
  saveCfg();
  document.getElementById('setup-screen').style.display  = 'none';
  document.getElementById('main-content').style.display  = '';
  loadTodos();
  loadCalendar(); calendar_loaded = true;
}

// ══════════════════════════════════════════════════════
// TOAST
// ══════════════════════════════════════════════════════
let toastTimer;
function showToast(msg, type = '') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className   = `toast ${type} show`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = 'toast'; }, 2800);
}

// ══════════════════════════════════════════════════════
// CLOCK
// ══════════════════════════════════════════════════════
function startClock() {
  const el = document.getElementById('header-clock');
  function tick() {
    const now = new Date();
    el.textContent = now.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }
  tick();
  setInterval(tick, 1000);
}

// ══════════════════════════════════════════════════════
// POMODORO
// ══════════════════════════════════════════════════════
let pomPhase    = 'focus';
let pomLeft     = 0;
let pomTotal    = 0;
let pomRunning  = false;
let pomInterval = null;
let pomCount    = 0;
let _audioCtx   = null;

const POM_LABEL = { focus: '🍅 Foco', shortBreak: '☕ Pausa', longBreak: '🛋 Pausa Longa' };

function pomDurations() {
  return {
    focus:      (cfg.pomFocus || 25) * 60,
    shortBreak: (cfg.pomShort || 5)  * 60,
    longBreak:  (cfg.pomLong  || 15) * 60,
  };
}

function pomFmt(s) {
  const m = Math.floor(s / 60), sec = s % 60;
  return `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
}

function pomRender() {
  const dur = pomDurations();
  pomTotal = dur[pomPhase] || dur.focus;

  document.getElementById('pom-phase').textContent = POM_LABEL[pomPhase];
  document.getElementById('pom-timer').textContent = pomFmt(pomLeft);
  document.getElementById('pom-toggle').textContent = pomRunning ? '⏸' : '▶';
  document.getElementById('pom-toggle').classList.toggle('active', pomRunning);

  const dots = ['○','○','○','○'];
  for (let i = 0; i < Math.min(pomCount % 4, 4); i++) dots[i] = '●';
  document.getElementById('pom-dots').textContent = dots.join('');

  const pct = pomTotal > 0 ? ((pomTotal - pomLeft) / pomTotal) * 100 : 0;
  document.getElementById('pom-bar').style.width = pct + '%';
}

function pomPlayDone() {
  try {
    if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    [523, 659, 784].forEach((freq, i) => {
      const osc  = _audioCtx.createOscillator();
      const gain = _audioCtx.createGain();
      osc.connect(gain); gain.connect(_audioCtx.destination);
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.25, _audioCtx.currentTime + i * 0.35);
      gain.gain.exponentialRampToValueAtTime(0.001, _audioCtx.currentTime + i * 0.35 + 0.6);
      osc.start(_audioCtx.currentTime + i * 0.35);
      osc.stop(_audioCtx.currentTime + i * 0.35 + 0.7);
    });
  } catch(e) {}
}

function pomNotify(msg) {
  if ('Notification' in window && Notification.permission === 'granted') {
    new Notification('pikachuPM Pomodoro', { body: msg, icon: 'img/icon-192x192.png' });
  }
}

function pomAdvance() {
  const dur = pomDurations();
  if (pomPhase === 'focus') {
    pomCount++;
    if (pomCount % 4 === 0) {
      pomPhase = 'longBreak';
      pomLeft  = dur.longBreak;
      pomNotify('Pausa longa! 🛋 15 minutos de descanso.');
    } else {
      pomPhase = 'shortBreak';
      pomLeft  = dur.shortBreak;
      pomNotify('Pausa curta! ☕ 5 minutos.');
    }
  } else {
    pomPhase = 'focus';
    pomLeft  = dur.focus;
    pomNotify('Tempo de foco! 🍅 Concentra-te.');
  }
  pomPlayDone();
  pomRunning = false;
  clearInterval(pomInterval);
  pomInterval = null;
  pomRender();
}

function pomTick() {
  if (pomLeft <= 0) { pomAdvance(); return; }
  pomLeft--;
  pomRender();
}

function pomToggle() {
  if (!_audioCtx) {
    try { _audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
  }
  if (pomRunning) {
    pomRunning = false;
    clearInterval(pomInterval);
    pomInterval = null;
  } else {
    pomRunning = true;
    pomInterval = setInterval(pomTick, 1000);
  }
  pomRender();
}

function pomReset() {
  const dur = pomDurations();
  pomRunning = false;
  clearInterval(pomInterval);
  pomInterval = null;
  pomLeft  = dur[pomPhase] || dur.focus;
  pomRender();
}

function initPomodoro() {
  const dur = pomDurations();
  pomLeft   = dur.focus;
  pomRender();

  document.getElementById('pom-toggle').addEventListener('click', () => {
    pomToggle();
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }
  });
  document.getElementById('pom-reset').addEventListener('click', pomReset);
}

// ══════════════════════════════════════════════════════
// MQTT
// ══════════════════════════════════════════════════════
function mqttSetState(state) {
  const dot     = document.getElementById('mqtt-dot');
  const bar     = document.getElementById('mqtt-status-bar');
  const iconEl  = document.getElementById('mqtt-status-icon');
  const textEl  = document.getElementById('mqtt-status-text');
  const labels  = { connected: 'Conectado', connecting: 'A ligar…', error: 'Erro', disconnected: 'Desligado' };
  if (dot) {
    dot.className = 'mqtt-dot' + (state !== 'disconnected' ? ' ' + state : '');
  }
  if (bar)    bar.className = `mqtt-status-bar ${state}`;
  if (iconEl) iconEl.textContent = '⬤';
  if (textEl) textEl.textContent = labels[state] || state;
}

function mqttAddMsg(topic, payload, dir = 'incoming') {
  const feedEl  = document.getElementById('mqtt-feed');
  const emptyEl = document.getElementById('mqtt-empty');
  if (!feedEl) return;

  mqttMessages.unshift({ topic, payload, dir, time: new Date() });
  if (mqttMessages.length > MAX_MQTT_MSGS) mqttMessages.pop();

  if (emptyEl) emptyEl.style.display = 'none';

  const t    = new Date();
  const time = t.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const div  = document.createElement('div');
  div.className = `mqtt-msg ${dir}`;
  div.innerHTML = `
    <span class="mqtt-msg-topic">${escHtml(topic)}</span>
    <span class="mqtt-msg-payload">${escHtml(String(payload))}</span>
    <span class="mqtt-msg-time">${time}</span>`;
  feedEl.prepend(div);

  // Trim DOM
  while (feedEl.children.length > MAX_MQTT_MSGS) feedEl.lastChild.remove();
}

function mqttConnect() {
  if (mqttClient) { try { mqttClient.end(true); } catch(e) {} mqttClient = null; }
  if (!cfg.mqttEnabled || !cfg.mqttBroker) { mqttSetState('disconnected'); return; }

  mqttSetState('connecting');
  try {
    const opts = {
      clientId:        'pk_pwa_' + Math.random().toString(16).slice(2),
      keepalive:       60,
      reconnectPeriod: 5000,
    };
    if (cfg.mqttUser) opts.username = cfg.mqttUser;
    if (cfg.mqttPass) opts.password = cfg.mqttPass;

    mqttClient = mqtt.connect(cfg.mqttBroker, opts);

    mqttClient.on('connect', () => {
      mqttSetState('connected');
      const topics = cfg.mqttTopics && cfg.mqttTopics.length > 0 ? cfg.mqttTopics : ['#'];
      topics.forEach(t => mqttClient.subscribe(t, { qos: 0 }));
    });

    mqttClient.on('message', (topic, payload) => {
      mqttAddMsg(topic, payload.toString());
    });

    mqttClient.on('error',     () => mqttSetState('error'));
    mqttClient.on('close',     () => mqttSetState('disconnected'));
    mqttClient.on('offline',   () => mqttSetState('disconnected'));
    mqttClient.on('reconnect', () => mqttSetState('connecting'));
  } catch(e) {
    mqttSetState('error');
  }
}

function mqttPublish() {
  const topicEl = document.getElementById('mqtt-pub-topic-input');
  const msgEl   = document.getElementById('mqtt-pub-msg-input');
  const topic   = topicEl.value.trim();
  const msg     = msgEl.value.trim();
  if (!topic || !msg || !mqttClient) return;
  mqttClient.publish(topic, msg, { qos: 0 }, err => {
    if (!err) {
      mqttAddMsg(topic, msg, 'outgoing');
      msgEl.value = '';
    }
  });
}

// ══════════════════════════════════════════════════════
// PERSONAL CHECKLIST — offline-first com sync ao servidor
// Cada item: { id, serverId?, text, done, _dirty?, _delete? }
//   id       = "local_X" enquanto não sincronizado, depois = serverId (string)
//   _dirty   = precisa de ser enviado (create ou update)
//   _delete  = apagado localmente, aguarda DELETE no servidor
// ══════════════════════════════════════════════════════
const PERSONAL_KEY = 'pikachu_personal_v2';

function pLoad() {
  try { return JSON.parse(localStorage.getItem(PERSONAL_KEY) || '[]'); } catch { return []; }
}
function pSave(items) {
  try { localStorage.setItem(PERSONAL_KEY, JSON.stringify(items)); } catch {}
}

// ── Render ────────────────────────────────────────────
function personalRender() {
  const items   = pLoad().filter(i => !i._delete);
  const listEl  = document.getElementById('personal-list');
  const emptyEl = document.getElementById('personal-empty');
  const clearEl = document.getElementById('personal-clear');
  const syncEl  = document.getElementById('personal-sync-status');
  if (!listEl) return;

  if (clearEl) clearEl.style.display = items.some(i => i.done) ? '' : 'none';
  if (emptyEl) emptyEl.style.display = items.length === 0 ? '' : 'none';

  const hasDirty = pLoad().some(i => i._dirty || i._delete);
  if (syncEl) {
    syncEl.textContent = hasDirty ? (navigator.onLine ? '↑ A sincronizar…' : '⚠ Offline — guardado localmente') : '';
    syncEl.className   = 'personal-sync-status' + (hasDirty && !navigator.onLine ? ' offline' : '');
  }

  listEl.innerHTML = items.map(item => `
    <div class="p-item${item.done ? ' done' : ''}${item._dirty ? ' p-dirty' : ''}" data-id="${escHtml(item.id)}">
      <label class="p-check">
        <input type="checkbox"${item.done ? ' checked' : ''} data-id="${escHtml(item.id)}">
        <span class="p-text">${escHtml(item.text)}</span>
      </label>
      <button class="p-speak" data-text="${escHtml(item.text)}" title="Ouvir nota">🔊</button>
      <button class="p-del" data-id="${escHtml(item.id)}" title="Apagar">✕</button>
    </div>`).join('');

  listEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => personalToggle(cb.dataset.id, cb.checked));
  });
  listEl.querySelectorAll('.p-speak').forEach(btn => {
    btn.addEventListener('click', e => { e.stopPropagation(); speakText(btn.dataset.text, btn); });
  });
  listEl.querySelectorAll('.p-del').forEach(btn => {
    btn.addEventListener('click', e => { e.stopPropagation(); personalDelete(btn.dataset.id); });
  });
}

// ── API helpers ───────────────────────────────────────
function pApiUrl() { return apiUrl('personal_notes.php'); }

async function pApiFetch(method, params = '', body = null) {
  const url  = pApiUrl() + (params ? '?' + params : '');
  const opts = {
    method,
    headers: { 'Authorization': `Bearer ${cfg.token}`, 'Content-Type': 'application/json' },
  };
  if (body) opts.body = JSON.stringify(body);
  const resp = await fetch(url, opts);
  if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
  return resp.json();
}

// ── Add ───────────────────────────────────────────────
async function personalAdd(text) {
  text = (text || '').trim();
  if (!text) return;
  const localId = 'local_' + Date.now() + '_' + Math.random().toString(36).slice(2, 5);
  const items = pLoad();
  items.unshift({ id: localId, text, done: false, _dirty: true });
  pSave(items);
  personalRender();

  if (navigator.onLine) {
    try {
      const res  = await pApiFetch('POST', '', { text });
      const arr  = pLoad();
      const item = arr.find(i => i.id === localId);
      if (item) { item.id = String(res.id); item.serverId = res.id; delete item._dirty; }
      pSave(arr);
      personalRender();
    } catch { /* fica dirty, sync depois */ }
  }
}

// ── Toggle done ───────────────────────────────────────
async function personalToggle(id, done) {
  const arr  = pLoad();
  const item = arr.find(i => i.id === id);
  if (!item) return;
  item.done   = done;
  item._dirty = true;
  pSave(arr);
  personalRender();

  if (navigator.onLine && item.serverId) {
    try {
      await pApiFetch('PUT', `id=${item.serverId}`, { done });
      const arr2  = pLoad();
      const item2 = arr2.find(i => i.id === id);
      if (item2) delete item2._dirty;
      pSave(arr2);
      personalRender();
    } catch { /* fica dirty */ }
  }
}

// ── Delete ────────────────────────────────────────────
async function personalDelete(id) {
  const arr  = pLoad();
  const item = arr.find(i => i.id === id);
  if (!item) return;

  if (!item.serverId) {
    // Nunca chegou ao servidor — apagar apenas localmente
    pSave(arr.filter(i => i.id !== id));
    personalRender();
    return;
  }

  item._delete = true;
  pSave(arr);
  personalRender();

  if (navigator.onLine) {
    try {
      await pApiFetch('DELETE', `id=${item.serverId}`);
      pSave(pLoad().filter(i => i.id !== id));
      personalRender();
    } catch { /* fica marcado, sync depois */ }
  }
}

// ── Clear done ────────────────────────────────────────
function personalClearDone() {
  const done = pLoad().filter(i => i.done && !i._delete);
  done.forEach(i => personalDelete(i.id));
}

// ── Sync — envia todos os itens dirty ao servidor ─────
async function personalSync() {
  if (!navigator.onLine || !isConfigured()) return;
  const arr = pLoad();
  let changed = false;

  for (const item of arr) {
    try {
      // Criar no servidor (item local ainda sem serverId)
      if (item._dirty && !item.serverId && !item._delete) {
        const res  = await pApiFetch('POST', '', { text: item.text });
        item.id       = String(res.id);
        item.serverId = res.id;
        delete item._dirty;
        changed = true;
      }
      // Actualizar no servidor
      else if (item._dirty && item.serverId && !item._delete) {
        await pApiFetch('PUT', `id=${item.serverId}`, { done: item.done, text: item.text });
        delete item._dirty;
        changed = true;
      }
      // Apagar no servidor
      else if (item._delete && item.serverId) {
        await pApiFetch('DELETE', `id=${item.serverId}`);
        item._synced_delete = true;
        changed = true;
      }
    } catch { /* tentar novamente na próxima sync */ }
  }

  if (changed) {
    pSave(arr.filter(i => !i._synced_delete));
    personalRender();
  }
}

// ── Fetch from server → merge com localStorage ────────
async function personalFetchFromServer() {
  if (!navigator.onLine || !isConfigured()) return;
  const syncEl = document.getElementById('personal-sync-status');
  try {
    if (syncEl) { syncEl.textContent = '↓ A sincronizar…'; syncEl.className = 'personal-sync-status'; }
    const res    = await pApiFetch('GET');
    const server = res.notes || [];
    const local  = pLoad();

    // Manter itens dirty/delete locais; substituir o resto pelos do servidor
    const dirtyIds  = new Set(local.filter(i => i._dirty || i._delete).map(i => i.serverId).filter(Boolean));
    const localOnly = local.filter(i => !i.serverId); // ainda sem ID de servidor

    const merged = [
      ...localOnly,
      ...server
        .filter(s => !dirtyIds.has(s.id))
        .map(s => ({ id: String(s.id), serverId: s.id, text: s.text, done: s.done })),
      ...local.filter(i => i.serverId && (i._dirty || i._delete)),
    ];

    pSave(merged);
    personalRender();
  } catch(e) {
    if (syncEl) { syncEl.textContent = '⚠ Erro sync: ' + e.message; syncEl.className = 'personal-sync-status offline'; }
  }
}

// ══════════════════════════════════════════════════════
// VOZ — SpeechRecognition + SpeechSynthesis
// ══════════════════════════════════════════════════════
let _recognition = null;

function startVoiceInput() {
  const SR     = window.SpeechRecognition || window.webkitSpeechRecognition;
  const micBtn = document.getElementById('personal-mic-btn');
  const input  = document.getElementById('personal-input');

  if (!SR) {
    showToast('Ditado por voz não suportado neste browser', 'error');
    return;
  }

  // A gravar → parar
  if (_recognition) {
    _recognition.stop();
    return;
  }

  _recognition = new SR();
  _recognition.lang            = navigator.language || 'pt-PT';
  _recognition.continuous      = false;
  _recognition.interimResults  = true;
  _recognition.maxAlternatives = 1;

  if (micBtn) micBtn.classList.add('listening');
  if (input)  input.placeholder = '🎤 A ouvir…';

  _recognition.onresult = e => {
    const t = Array.from(e.results).map(r => r[0].transcript).join('');
    if (input) input.value = t;
  };

  _recognition.onend = () => {
    _recognition = null;
    if (micBtn) micBtn.classList.remove('listening');
    if (input)  input.placeholder = 'Nova nota pessoal…';
    // Se ficou texto, guardar automaticamente
    if (input?.value.trim()) {
      personalAdd(input.value);
      input.value = '';
    }
  };

  _recognition.onerror = e => {
    _recognition = null;
    if (micBtn) micBtn.classList.remove('listening');
    if (input)  input.placeholder = 'Nova nota pessoal…';
    if (e.error !== 'no-speech' && e.error !== 'aborted') {
      showToast('Microfone: ' + e.error, 'error');
    }
  };

  try { _recognition.start(); }
  catch(e) {
    _recognition = null;
    if (micBtn) micBtn.classList.remove('listening');
    if (input)  input.placeholder = 'Nova nota pessoal…';
    showToast('Erro ao aceder ao microfone', 'error');
  }
}

function speakText(text, btn = null) {
  if (!window.speechSynthesis) { showToast('Síntese de voz não suportada', 'error'); return; }

  // A falar → parar
  if (window.speechSynthesis.speaking) {
    window.speechSynthesis.cancel();
    document.querySelectorAll('.p-speak.speaking').forEach(b => b.classList.remove('speaking'));
    return;
  }

  const utt  = new SpeechSynthesisUtterance(text);
  utt.lang   = navigator.language || 'pt-PT';
  utt.rate   = 1.0;
  utt.pitch  = 1.0;
  utt.volume = 1.0;

  if (btn) btn.classList.add('speaking');
  utt.onend   = () => { if (btn) btn.classList.remove('speaking'); };
  utt.onerror = () => { if (btn) btn.classList.remove('speaking'); };

  window.speechSynthesis.speak(utt);
}

// ── Sync manual ───────────────────────────────────────
async function personalSyncManual() {
  const btn    = document.getElementById('personal-sync-btn');
  const syncEl = document.getElementById('personal-sync-status');
  if (btn) btn.classList.add('spin');
  if (syncEl) { syncEl.textContent = '↓ A sincronizar…'; syncEl.className = 'personal-sync-status'; }
  try {
    await personalSync();
    await personalFetchFromServer();
    // Só mostra sucesso se não houve erro reportado pelo fetch
    if (syncEl && !syncEl.textContent.startsWith('⚠')) {
      syncEl.textContent = '✓ Sincronizado';
      setTimeout(() => { if (syncEl.textContent === '✓ Sincronizado') syncEl.textContent = ''; }, 2500);
    }
  } finally {
    if (btn) btn.classList.remove('spin');
  }
}

// ── Init ──────────────────────────────────────────────
function initPersonal() {
  const input    = document.getElementById('personal-input');
  const addBtn   = document.getElementById('personal-add-btn');
  const clearBtn = document.getElementById('personal-clear');
  const syncBtn  = document.getElementById('personal-sync-btn');

  addBtn?.addEventListener('click', () => {
    if (input) { personalAdd(input.value); input.value = ''; input.focus(); }
  });
  input?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { personalAdd(input.value); input.value = ''; }
  });
  clearBtn?.addEventListener('click', personalClearDone);
  syncBtn?.addEventListener('click', personalSyncManual);
  document.getElementById('personal-mic-btn')?.addEventListener('click', startVoiceInput);

  // Migrar dados da v1 (localStorage antigo)
  try {
    const old = localStorage.getItem('pikachu_personal_v1');
    if (old && !localStorage.getItem(PERSONAL_KEY)) {
      const oldItems = JSON.parse(old).map(i => ({
        id: 'local_' + (i.id || Date.now()),
        text: i.text, done: i.done || false, _dirty: true,
      }));
      pSave(oldItems);
      localStorage.removeItem('pikachu_personal_v1');
    }
  } catch {}

  personalRender();

  // Fetch inicial do servidor (em background)
  personalFetchFromServer().then(() => personalSync());

  // Sync quando voltar online
  window.addEventListener('online', () => {
    personalSync().then(personalFetchFromServer);
    showToast('Online — a sincronizar notas…', '');
  });
}

function showPersonalView(show) {
  const searchBar  = document.getElementById('search-bar');
  const personalEl = document.getElementById('personal-panel');
  const todosList  = document.getElementById('todos-list');
  const loadEl     = document.getElementById('loading');
  const errEl      = document.getElementById('error-msg');
  const emptyEl    = document.getElementById('empty-state');
  const fabEl      = document.getElementById('btn-add-todo');
  if (searchBar)  searchBar.style.display  = show ? 'none' : '';
  if (todosList)  todosList.style.display  = show ? 'none' : '';
  if (loadEl)     loadEl.style.display     = show ? 'none' : '';
  if (errEl)      errEl.style.display      = show ? 'none' : '';
  if (emptyEl)    emptyEl.style.display    = show ? 'none' : '';
  if (fabEl)      fabEl.style.display      = show ? 'none' : '';
  if (personalEl) personalEl.style.display = show ? '' : 'none';
  if (show) personalRender();
}

// ══════════════════════════════════════════════════════
// PULL-TO-REFRESH
// ══════════════════════════════════════════════════════
function setupPullToRefresh() {
  const listEl = document.getElementById('todos-list');
  let startY = 0, pulling = false;

  listEl.addEventListener('touchstart', e => {
    if (listEl.scrollTop === 0) startY = e.touches[0].clientY;
  }, { passive: true });

  listEl.addEventListener('touchend', e => {
    if (startY === 0) return;
    const dy = e.changedTouches[0].clientY - startY;
    if (dy > 60) { loadTodos(); showToast('A atualizar…'); }
    startY = 0;
  }, { passive: true });
}

// ══════════════════════════════════════════════════════
// EVENT LISTENERS
// ══════════════════════════════════════════════════════
function attachEvents() {
  // Filter tabs
  document.querySelectorAll('.tab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      filterState = btn.dataset.state;
      if (filterState === 'personal') {
        showPersonalView(true);
      } else {
        showPersonalView(false);
        loadTodos();
      }
    });
  });

  // Search
  let searchTimer;
  document.getElementById('search-input').addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      searchQuery = e.target.value.trim();
      renderTodos(allTodos);
    }, 250);
  });

  // Refresh
  document.getElementById('btn-refresh').addEventListener('click', () => {
    loadTodos();
    calendar_loaded = sprints_loaded = deliverables_loaded = leads_loaded = false;
    if (activePanel === 'calendar')     { calendar_loaded     = true; loadCalendar(); }
    if (activePanel === 'sprints')      { sprints_loaded      = true; loadSprints(); }
    if (activePanel === 'deliverables') { deliverables_loaded = true; loadDeliverables(); }
    if (activePanel === 'leads')        { leads_loaded        = true; loadLeads(); }
  });

  // Update app
  document.getElementById('btn-update-app').addEventListener('click', checkForUpdate);

  // Install
  document.getElementById('btn-install').addEventListener('click', triggerInstall);

  // Settings
  document.getElementById('btn-settings').addEventListener('click', openSettings);
  document.getElementById('btn-settings-close').addEventListener('click', closeSettings);
  document.getElementById('btn-cfg-save').addEventListener('click', saveSettings);
  document.getElementById('btn-cfg-logout').addEventListener('click', () => {
    if (confirm('Terminar sessão e apagar token?')) {
      cfg = { ...DEFAULT_CFG };
      saveCfg();
      closeSettings();
      document.getElementById('main-content').style.display = 'none';
      document.getElementById('setup-screen').style.display = '';
    }
  });

  // Toggle visibility buttons
  document.getElementById('setup-token-vis').addEventListener('click', () => {
    const i = document.getElementById('setup-token');
    i.type = i.type === 'password' ? 'text' : 'password';
  });
  document.getElementById('cfg-token-vis').addEventListener('click', () => {
    const i = document.getElementById('cfg-token');
    i.type = i.type === 'password' ? 'text' : 'password';
  });

  // MQTT toggle in settings
  document.getElementById('cfg-mqtt-enabled').addEventListener('change', updateMqttFields);

  // Setup save
  document.getElementById('btn-setup-save').addEventListener('click', saveSetup);
  document.getElementById('setup-api-url').addEventListener('keydown', e => { if (e.key === 'Enter') saveSetup(); });
  document.getElementById('setup-token').addEventListener('keydown', e => { if (e.key === 'Enter') saveSetup(); });

  // FAB → picker
  document.getElementById('btn-add-todo').addEventListener('click', openPicker);

  // Picker
  document.getElementById('pick-task').addEventListener('click', () => { closePicker(); openModal(); });
  document.getElementById('pick-bug').addEventListener('click', () => { closePicker(); openStoryForm('Bug'); });
  document.getElementById('pick-feature').addEventListener('click', () => { closePicker(); openStoryForm('Feature'); });
  document.getElementById('btn-picker-cancel').addEventListener('click', closePicker);
  document.getElementById('picker-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('picker-overlay')) closePicker();
  });

  // Story form
  document.getElementById('btn-story-back').addEventListener('click', () => { closeStoryForm(); openPicker(); });
  document.getElementById('btn-story-close').addEventListener('click', closeStoryForm);
  document.getElementById('btn-story-cancel').addEventListener('click', closeStoryForm);
  document.getElementById('btn-story-save').addEventListener('click', saveStoryForm);

  // Retry
  document.getElementById('btn-retry').addEventListener('click', loadTodos);

  // Todos list: event delegation
  document.getElementById('todos-list').addEventListener('click', e => {
    const editBtn = e.target.closest('.edit-btn');
    const doneBtn = e.target.closest('.done-btn');
    const card    = e.target.closest('.todo-card');

    if (editBtn) {
      const todo = allTodos.find(t => String(t.id) === editBtn.dataset.id);
      if (todo) openModal(todo);
      return;
    }

    if (doneBtn) {
      const todo = allTodos.find(t => String(t.id) === doneBtn.dataset.id);
      if (!todo) return;
      const done = doneBtn.dataset.done === 'true';
      saveTodo({ ...todo, estado: done ? 'aberta' : 'completada' })
        .then(() => { showToast(done ? 'Todo reaberto' : 'Todo concluído!', 'success'); loadTodos(); })
        .catch(e => showToast('Erro: ' + e.message, 'error'));
      return;
    }
  });

  // Modal
  document.getElementById('btn-modal-close').addEventListener('click', closeModal);
  document.getElementById('btn-modal-cancel').addEventListener('click', closeModal);
  document.getElementById('btn-modal-save').addEventListener('click', saveModal);
  document.getElementById('modal-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('modal-overlay')) closeModal();
  });

  // Bottom tabs
  document.querySelectorAll('.btab').forEach(btn => {
    btn.addEventListener('click', () => switchPanel(btn.dataset.panel));
  });

  // MQTT reconnect & publish
  document.getElementById('mqtt-reconnect-btn').addEventListener('click', () => {
    if (cfg.mqttEnabled && cfg.mqttBroker) mqttConnect();
  });
  document.getElementById('mqtt-pub-btn').addEventListener('click', mqttPublish);
  document.getElementById('mqtt-pub-msg-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') mqttPublish();
  });

  setupAttachEvents();

  document.getElementById('btn-bar')?.addEventListener('click', publishBarAlert);
}

// ══════════════════════════════════════════════════════
// PANEL RESIZE (drag handle)
// ══════════════════════════════════════════════════════
function initPanelResize() {
  const handle  = document.getElementById('panel-resize-handle');
  const panelEl = document.getElementById('bottom-panel');
  if (!handle || !panelEl) return;

  // Restore saved height
  try {
    const saved = parseInt(localStorage.getItem('pk_panel_h') || '0');
    if (saved >= 60) { panelExpandedH = saved; panelEl.style.height = saved + 'px'; }
  } catch {}

  let startY = 0, startH = 0, active = false;

  function dragStart(y) {
    startY  = y;
    startH  = panelEl.offsetHeight;
    active  = true;
    panelEl.classList.add('resizing');
    // If collapsed, start expanding from the handle
    if (panelEl.classList.contains('collapsed')) {
      startH  = 44;
      panelEl.classList.remove('collapsed');
      panelOpen = true;
    }
  }

  function dragMove(y) {
    if (!active) return;
    const dy  = startY - y;                          // up = grow
    const max = Math.floor(window.innerHeight * 0.75);
    const h   = Math.min(Math.max(startH + dy, 44), max);
    panelEl.style.height = h + 'px';
  }

  function dragEnd() {
    if (!active) return;
    active = false;
    panelEl.classList.remove('resizing');
    const h = panelEl.offsetHeight;
    if (h <= 52) {
      panelEl.classList.add('collapsed');
      panelEl.style.height = '44px';
      panelOpen = false;
    } else {
      panelExpandedH = h;
      panelOpen = true;
      try { localStorage.setItem('pk_panel_h', h); } catch {}
    }
  }

  // Touch
  handle.addEventListener('touchstart', e => { e.preventDefault(); dragStart(e.touches[0].clientY); }, { passive: false });
  handle.addEventListener('touchmove',  e => { e.preventDefault(); dragMove(e.touches[0].clientY);  }, { passive: false });
  handle.addEventListener('touchend',   dragEnd, { passive: true });

  // Mouse (browser/desktop)
  handle.addEventListener('mousedown', e => {
    e.preventDefault();
    dragStart(e.clientY);
    const onMM = ev => dragMove(ev.clientY);
    const onMU = ()  => { dragEnd(); document.removeEventListener('mousemove', onMM); document.removeEventListener('mouseup', onMU); };
    document.addEventListener('mousemove', onMM);
    document.addEventListener('mouseup', onMU);
  });
}

// ══════════════════════════════════════════════════════
// PWA INSTALL PROMPT
// ══════════════════════════════════════════════════════
let _installPrompt = null;

window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  _installPrompt = e;
  const btn = document.getElementById('btn-install');
  if (btn) btn.style.display = '';
});

window.addEventListener('appinstalled', () => {
  _installPrompt = null;
  const btn = document.getElementById('btn-install');
  if (btn) btn.style.display = 'none';
  showToast('App instalada! 🎉', 'success');
});

async function triggerInstall() {
  if (!_installPrompt) {
    // Fallback: instructions if prompt not available
    showToast('Chrome ⋮ → "Adicionar ao ecrã principal"', '');
    return;
  }
  _installPrompt.prompt();
  const { outcome } = await _installPrompt.userChoice;
  if (outcome === 'accepted') {
    _installPrompt = null;
    const btn = document.getElementById('btn-install');
    if (btn) btn.style.display = 'none';
  }
}

// ══════════════════════════════════════════════════════
// SERVICE WORKER
// ══════════════════════════════════════════════════════
let _swReg = null;

function registerSW() {
  if (!('serviceWorker' in navigator)) return;

  navigator.serviceWorker.register('./sw.js', {
    updateViaCache: 'none',
  }).then(async reg => {
    _swReg = reg;
    reg.update();

    // Mostrar versão do cache instalado no logo
    try {
      const keys    = await caches.keys();
      const active  = keys.find(k => /pikachu-pwa-v\d+/.test(k)) || '';
      const ver     = active.match(/v(\d+)/)?.[1];
      const swVerEl = document.getElementById('sw-ver');
      if (swVerEl && ver) swVerEl.textContent = `(sw${ver}) `;
    } catch {}
  }).catch(e => console.warn('SW registration failed:', e));

  // Quando o novo SW assume o controlo, recarrega a página automaticamente
  let _reloading = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (_reloading) return;
    _reloading = true;
    window.location.reload();
  });
}

async function checkForUpdate() {
  const btn = document.getElementById('btn-update-app');
  if (btn) btn.classList.add('spin');
  showToast('A verificar actualização…', '');

  try {
    // Buscar sw.js do servidor sem qualquer cache
    const fresh   = await fetch('./sw.js', { cache: 'no-store' });
    const swText  = await fresh.text();
    const serverV = parseInt((swText.match(/pikachu-pwa-v(\d+)/) || ['','0'])[1], 10);

    // Versão instalada actualmente
    const cacheKeys = await caches.keys();
    const activeKey = cacheKeys.find(k => /pikachu-pwa-v\d+/.test(k)) || '';
    const localV    = parseInt((activeKey.match(/v(\d+)/) || ['','0'])[1], 10);

    if (serverV > 0 && serverV !== localV) {
      // Versões diferentes → limpar tudo e recarregar
      showToast(`Nova versão (v${serverV})! A limpar cache…`, 'success');
      await Promise.all(cacheKeys.map(k => caches.delete(k)));
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map(r => r.unregister()));
      setTimeout(() => window.location.reload(), 700);
    } else if (serverV === localV && localV > 0) {
      showToast(`Versão v${localV} — já é a mais recente ✓`, 'success');
    } else {
      // Fallback: deixar o SW normal tratar
      const reg = _swReg || await navigator.serviceWorker.getRegistration('./sw.js');
      if (reg) {
        let found = false;
        reg.addEventListener('updatefound', () => { found = true; }, { once: true });
        await reg.update();
        await new Promise(r => setTimeout(r, 3000));
        if (!found) showToast('Já tens a versão mais recente ✓', 'success');
      } else {
        showToast('A recarregar…', '');
        setTimeout(() => window.location.reload(), 500);
      }
    }
  } catch(e) {
    showToast('Erro: ' + e.message, 'error');
  } finally {
    if (btn) btn.classList.remove('spin');
  }
}

// ══════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════
function init() {
  loadCfg();
  registerSW();
  startClock();
  initPomodoro();
  initPersonal();
  initPanelResize();
  attachEvents();
  setupPullToRefresh();

  // Auto-detect API URL from current page location
  if (!cfg.apiUrl) {
    const loc = window.location;
    // PWA is at .../PWA/index.html, API is at .../api/
    cfg.apiUrl = loc.origin + loc.pathname.replace(/\/PWA\/.*$/, '/');
    // Don't save yet — wait for token
  }

  if (isConfigured()) {
    document.getElementById('setup-screen').style.display = 'none';
    document.getElementById('main-content').style.display = '';
    loadTodos();
    loadCalendar(); calendar_loaded = true;

    if (cfg.mqttEnabled && cfg.mqttBroker) {
      if (typeof mqtt !== 'undefined') mqttConnect();
      else {
        const t = setInterval(() => {
          if (typeof mqtt !== 'undefined') { clearInterval(t); mqttConnect(); }
        }, 200);
      }
    }
  } else {
    document.getElementById('setup-screen').style.display = '';
    // Pre-fill the auto-detected API URL
    document.getElementById('setup-api-url').value = cfg.apiUrl || '';
  }
}

document.addEventListener('DOMContentLoaded', init);

// ══════════════════════════════════════════════════════
// BAR ALERT — publica "bar" em /PK/alertabarulho
// ══════════════════════════════════════════════════════
function publishBarAlert() {
  const TOPIC  = '/PK/alertabarulho';
  const MSG    = 'bar';
  const BROKER = 'wss://vcriis01.inesctec.pt:9002';
  const btn    = document.getElementById('btn-bar');

  function doPublish(client) {
    client.publish(TOPIC, MSG, { qos: 0 }, err => {
      if (!err) {
        showToast('🔕 Alerta de barulho enviado!', 'success');
        mqttAddMsg(TOPIC, MSG, 'outgoing');
        if (btn) { btn.classList.add('sent'); setTimeout(() => btn.classList.remove('sent'), 1500); }
      } else {
        showToast('Erro ao enviar alerta', 'error');
      }
      if (btn) btn.disabled = false;
    });
  }

  if (btn) btn.disabled = true;

  if (mqttClient && mqttClient.connected) {
    doPublish(mqttClient);
    return;
  }

  // Ligação temporária directa ao vcriis01
  const opts = {
    clientId:        'pk_bar_' + Math.random().toString(16).slice(2),
    keepalive:       30,
    reconnectPeriod: 0,
    connectTimeout:  5000,
  };
  if (cfg.mqttUser) opts.username = cfg.mqttUser;
  if (cfg.mqttPass) opts.password = cfg.mqttPass;

  try {
    const tmp = mqtt.connect(BROKER, opts);
    const t   = setTimeout(() => {
      tmp.end(true);
      if (btn) btn.disabled = false;
      showToast('Tempo esgotado ao ligar ao broker', 'error');
    }, 6000);
    tmp.on('connect', () => { clearTimeout(t); doPublish(tmp); setTimeout(() => tmp.end(true), 1000); });
    tmp.on('error',   () => { clearTimeout(t); tmp.end(true); if (btn) btn.disabled = false; showToast('Erro a ligar ao broker MQTT', 'error'); });
  } catch(e) {
    if (btn) btn.disabled = false;
    showToast('Erro: ' + e.message, 'error');
  }
}

// ══════════════════════════════════════════════════════
// ATTACHMENTS — foto/imagem na criação de task ou story
// ══════════════════════════════════════════════════════
function handleAttachFiles(files, pendingArr, thumbsId) {
  const thumbsEl = document.getElementById(thumbsId);
  Array.from(files).forEach(file => {
    const idx = pendingArr.length;
    pendingArr.push(file);
    if (!thumbsEl) return;
    const url = URL.createObjectURL(file);
    const div = document.createElement('div');
    div.className = 'attach-thumb';
    div.innerHTML = `<img src="${url}" alt=""><button class="attach-remove" data-idx="${idx}" title="Remover">✕</button>`;
    thumbsEl.appendChild(div);
  });
}

async function uploadAttachments(files, refId, type) {
  const base = cfg.apiUrl.endsWith('/') ? cfg.apiUrl : cfg.apiUrl + '/';
  for (const file of files) {
    const fd = new FormData();
    fd.append('type',   type);
    fd.append('ref_id', String(refId));
    fd.append('file',   file);
    try {
      const resp = await fetch(base + 'api/upload.php', {
        method:  'POST',
        headers: { 'Authorization': `Bearer ${cfg.token}` },
        body:    fd,
      });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    } catch(e) {
      showToast('Erro ao enviar imagem: ' + e.message, 'error');
    }
  }
}

function setupAttachEvents() {
  document.getElementById('todo-camera-btn').addEventListener('click', () => document.getElementById('todo-file-camera').click());
  document.getElementById('todo-gallery-btn').addEventListener('click', () => document.getElementById('todo-file-gallery').click());
  document.getElementById('todo-file-camera').addEventListener('change', e => { handleAttachFiles(e.target.files, pendingTodoAttachments, 'todo-attach-thumbs'); e.target.value = ''; });
  document.getElementById('todo-file-gallery').addEventListener('change', e => { handleAttachFiles(e.target.files, pendingTodoAttachments, 'todo-attach-thumbs'); e.target.value = ''; });

  document.getElementById('story-camera-btn').addEventListener('click', () => document.getElementById('story-file-camera').click());
  document.getElementById('story-gallery-btn').addEventListener('click', () => document.getElementById('story-file-gallery').click());
  document.getElementById('story-file-camera').addEventListener('change', e => { handleAttachFiles(e.target.files, pendingStoryAttachments, 'story-attach-thumbs'); e.target.value = ''; });
  document.getElementById('story-file-gallery').addEventListener('change', e => { handleAttachFiles(e.target.files, pendingStoryAttachments, 'story-attach-thumbs'); e.target.value = ''; });

  document.getElementById('todo-attach-thumbs').addEventListener('click', e => {
    const btn = e.target.closest('.attach-remove');
    if (!btn) return;
    pendingTodoAttachments[parseInt(btn.dataset.idx)] = null;
    btn.closest('.attach-thumb').remove();
  });
  document.getElementById('story-attach-thumbs').addEventListener('click', e => {
    const btn = e.target.closest('.attach-remove');
    if (!btn) return;
    pendingStoryAttachments[parseInt(btn.dataset.idx)] = null;
    btn.closest('.attach-thumb').remove();
  });
}

// ══════════════════════════════════════════════════════
// PICKER — escolher tipo de item a adicionar
// ══════════════════════════════════════════════════════
function openPicker() {
  document.getElementById('picker-overlay').style.display = '';
}
function closePicker() {
  document.getElementById('picker-overlay').style.display = 'none';
}

// ══════════════════════════════════════════════════════
// STORY FORM — criar Bug / Feature num protótipo
// ══════════════════════════════════════════════════════
let storyType = 'Bug';

async function openStoryForm(type) {
  storyType = type;
  const icons = { Bug: '🐛', Feature: '✨' };
  document.getElementById('story-modal-title').textContent = `${icons[type] || ''} Novo ${type}`;
  document.getElementById('btn-story-save').textContent = `Criar ${type}`;
  document.getElementById('story-text').value = '';
  document.getElementById('story-priority').value = type === 'Bug' ? 'Must' : 'Should';
  pendingStoryAttachments = [];
  const sth = document.getElementById('story-attach-thumbs');
  if (sth) sth.innerHTML = '';
  const sci = document.getElementById('story-file-camera');
  const sgi = document.getElementById('story-file-gallery');
  if (sci) sci.value = '';
  if (sgi) sgi.value = '';

  const sel = document.getElementById('story-prototype');
  sel.innerHTML = '<option value="">A carregar…</option>';
  document.getElementById('story-overlay').style.display = '';
  setTimeout(() => document.getElementById('story-text').focus(), 150);

  try {
    const data = await apiFetch('stories.php');
    const protos = data.prototypes || [];
    sel.innerHTML = protos.length
      ? protos.map(p => `<option value="${p.id}">${p.short_name} — ${p.title}</option>`).join('')
      : '<option value="">Sem protótipos disponíveis</option>';
  } catch(e) {
    sel.innerHTML = '<option value="">Erro ao carregar protótipos</option>';
  }
}

function closeStoryForm() {
  document.getElementById('story-overlay').style.display = 'none';
}

async function saveStoryForm() {
  const prototype_id = parseInt(document.getElementById('story-prototype').value);
  const story_text   = document.getElementById('story-text').value.trim();
  const moscow_priority = document.getElementById('story-priority').value;

  if (!prototype_id) { showToast('Selecciona um protótipo', 'error'); return; }
  if (!story_text)   { showToast('A descrição é obrigatória', 'error'); return; }

  const btn = document.getElementById('btn-story-save');
  btn.disabled = true;
  try {
    const result = await apiFetch('stories.php', {
      method: 'POST',
      body: JSON.stringify({ prototype_id, story_text, story_type: storyType, moscow_priority }),
    });
    const savedId = result?.id;
    const filesToUpload = pendingStoryAttachments.filter(Boolean);
    pendingStoryAttachments = [];
    closeStoryForm();
    showToast(`${storyType} criado com sucesso!`, 'success');
    if (filesToUpload.length && savedId) await uploadAttachments(filesToUpload, savedId, 'story');
  } catch(e) {
    showToast('Erro ao criar: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}
