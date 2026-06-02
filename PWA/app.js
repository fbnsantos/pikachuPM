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
  return data.sprints || [];
}

async function fetchDeliverables() {
  const data = await apiFetch('deliverables.php');
  return data.deliverables || [];
}

async function fetchLeads() {
  const data = await apiFetch('leads.php');
  return data.leads || [];
}

// ══════════════════════════════════════════════════════
// RENDER: TODOS
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
  const dateEnd   = item.data_fim ? fmtDate(item.data_fim) : '';

  return `<div class="pk-row">
    <div class="pk-row-main">
      <span class="pk-title">${escHtml(item.titulo || item.nome || '')}</span>
      ${relevDots ? `<span class="pk-relevance">${relevDots}</span>` : ''}
      ${isLead ? `<span class="pk-role ${roleClass}">${roleLabel}</span>` : ''}
    </div>
    <div class="pk-row-meta">
      ${item.projeto_nome || item.project_name ? `<span class="pk-project">${escHtml(item.projeto_nome || item.project_name)}</span>` : ''}
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
let activePanel     = 'sprints';
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

  // Load data on first switch
  if (panel === 'sprints'      && !sprints_loaded)      { sprints_loaded      = true; loadSprints(); }
  if (panel === 'deliverables' && !deliverables_loaded)  { deliverables_loaded = true; loadDeliverables(); }
  if (panel === 'leads'        && !leads_loaded)         { leads_loaded        = true; loadLeads(); }
}

let sprints_loaded = false, deliverables_loaded = false, leads_loaded = false;

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
    await saveTodo(todo);
    closeModal();
    showToast(wasEditing ? 'Todo atualizado!' : 'Todo criado!', 'success');
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
  loadSprints(); sprints_loaded = true;
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
// PERSONAL CHECKLIST (localStorage — nunca sai do dispositivo)
// ══════════════════════════════════════════════════════
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
        <input type="checkbox"${item.done ? ' checked' : ''} data-id="${escHtml(item.id)}">
        <span class="p-text">${escHtml(item.text)}</span>
      </label>
      <button class="p-del" data-id="${escHtml(item.id)}" title="Apagar">✕</button>
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
    sprints_loaded = deliverables_loaded = leads_loaded = false;
    if (activePanel === 'sprints')      loadSprints();
    if (activePanel === 'deliverables') loadDeliverables();
    if (activePanel === 'leads')        loadLeads();
  });

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

  // FAB
  document.getElementById('btn-add-todo').addEventListener('click', () => openModal());

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
function registerSW() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js')
      .catch(e => console.warn('SW registration failed:', e));
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
    loadSprints(); sprints_loaded = true;

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
