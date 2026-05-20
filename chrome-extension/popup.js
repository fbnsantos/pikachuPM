const STATE_LABELS = {
  'aberta': 'Aberta',
  'em execução': 'Em Execução',
  'suspensa': 'Suspensa',
  'concluída': 'Concluída',
};

const STATE_CLASSES = {
  'aberta': 'aberta',
  'em execução': 'em-execucao',
  'suspensa': 'suspensa',
  'concluída': 'concluida',
};

const isSidePanel = document.body.dataset.mode === 'sidepanel';

let todos = [];
let currentFilter = 'all';
let searchQuery = '';
let editingId = null;
let apiUrl = '';
let token = '';

// DOM refs
const elNotConfigured = document.getElementById('not-configured');
const elMainContent = document.getElementById('main-content');
const elLoading = document.getElementById('loading');
const elErrorMsg = document.getElementById('error-msg');
const elErrorText = document.getElementById('error-text');
const elTodosList = document.getElementById('todos-list');
const elEmptyState = document.getElementById('empty-state');
const elFab = document.getElementById('btn-add-todo');
const elModalOverlay = document.getElementById('modal-overlay');
const elModalTitle = document.getElementById('modal-title');
const elToast = document.getElementById('toast');

async function loadConfig() {
  return new Promise((resolve) => {
    chrome.storage.sync.get(['apiUrl', 'token'], (result) => {
      apiUrl = (result.apiUrl || '').replace(/\/$/, '');
      token = result.token || '';
      resolve({ apiUrl, token });
    });
  });
}

function showToast(msg, type = '') {
  elToast.textContent = msg;
  elToast.className = 'toast show' + (type ? ' ' + type : '');
  setTimeout(() => { elToast.className = 'toast'; }, 2500);
}

function formatDeadline(dateStr) {
  if (!dateStr) return null;
  const date = new Date(dateStr + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.round((date - today) / (1000 * 60 * 60 * 24));
  const fmt = date.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
  let cls = '';
  if (diff < 0) cls = 'overdue';
  else if (diff <= 2) cls = 'soon';
  return { label: fmt, cls, diff };
}

async function apiFetch(path, options = {}) {
  const url = apiUrl + path;
  const headers = { 'Authorization': 'Bearer ' + token, ...options.headers };
  if (options.body) headers['Content-Type'] = 'application/json';
  const res = await fetch(url, { ...options, headers });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { error: text }; }
  if (!res.ok) throw new Error(data.error || data.message || `Erro ${res.status}`);
  return data;
}

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
  if (currentFilter !== 'all') {
    list = list.filter(t => t.estado === currentFilter);
  }
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
  if (filtered.length === 0) {
    elEmptyState.style.display = 'flex';
    return;
  }
  elEmptyState.style.display = 'none';
  filtered.forEach(todo => {
    elTodosList.appendChild(buildTodoCard(todo));
  });
}

function buildTodoCard(todo) {
  const stateKey = STATE_CLASSES[todo.estado] || 'aberta';
  const card = document.createElement('div');
  card.className = `todo-card state-${stateKey}`;
  card.dataset.id = todo.id;

  const deadline = formatDeadline(todo.data_limite);
  const deadlineHtml = deadline
    ? `<span class="deadline ${deadline.cls}">📅 ${deadline.label}</span>`
    : '';

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
      <button class="btn-open" data-id="${todo.id}" title="Abrir no pikachuPM">↗ Abrir</button>
      <button class="btn-delete" data-id="${todo.id}" title="Eliminar">🗑</button>
    </div>
  `;

  // Expand/collapse on click
  card.addEventListener('click', (e) => {
    if (e.target.closest('button')) return;
    card.classList.toggle('expanded');
  });

  // State change buttons
  card.querySelectorAll('.btn-state').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const newState = btn.dataset.state;
      if (newState === todo.estado) return;
      await updateTodoState(todo, newState, card);
    });
  });

  // Open in app
  card.querySelector('.btn-open').addEventListener('click', (e) => {
    e.stopPropagation();
    const base = apiUrl.replace('/api/todos.php', '');
    chrome.tabs.create({ url: `${apiUrl.replace('/api/todos.php', '')}#todo-${todo.id}` });
  });

  // Delete
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
    // Remove from list if filter no longer matches
    if (currentFilter !== 'all' && currentFilter !== newState) {
      newCard.remove();
      const remaining = elTodosList.querySelectorAll('.todo-card');
      if (remaining.length === 0) elEmptyState.style.display = 'flex';
    }
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function deleteTodo(id, cardEl) {
  try {
    await apiFetch(`/api/todos.php?id=${id}`, { method: 'DELETE' });
    todos = todos.filter(t => t.id != id);
    cardEl.remove();
    showToast('Todo eliminado', 'success');
    if (elTodosList.children.length === 0) elEmptyState.style.display = 'flex';
  } catch (err) {
    showToast(err.message, 'error');
  }
}

function openModal(todo = null) {
  editingId = todo ? todo.id : null;
  elModalTitle.textContent = todo ? 'Editar Todo' : 'Novo Todo';
  document.getElementById('todo-titulo').value = todo ? (todo.titulo || '') : '';
  document.getElementById('todo-descritivo').value = todo ? (todo.descritivo || '') : '';
  document.getElementById('todo-estado').value = todo ? (todo.estado || 'aberta') : 'aberta';
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
  if (!titulo) {
    showToast('O título é obrigatório', 'error');
    return;
  }
  const payload = {
    titulo,
    descritivo: document.getElementById('todo-descritivo').value.trim(),
    estado: document.getElementById('todo-estado').value,
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

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function init() {
  const config = await loadConfig();
  if (!config.apiUrl || !config.token) {
    elNotConfigured.style.display = 'flex';
    elMainContent.style.display = 'none';
    elFab.style.display = 'none';
    return;
  }
  elNotConfigured.style.display = 'none';
  elMainContent.style.display = 'flex';
  elFab.style.display = 'flex';
  await fetchTodos();
}

// Event listeners
document.getElementById('btn-settings').addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
});

document.getElementById('btn-go-settings').addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
});

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

document.getElementById('btn-refresh').addEventListener('click', fetchTodos);
document.getElementById('btn-retry').addEventListener('click', fetchTodos);

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

elModalOverlay.addEventListener('click', (e) => {
  if (e.target === elModalOverlay) closeModal();
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeModal();
});

init();
