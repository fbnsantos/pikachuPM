const elApiUrl = document.getElementById('api-url');
const elApiToken = document.getElementById('api-token');
const elStatus = document.getElementById('status');
const elSaveBtn = document.getElementById('btn-save');
const elToggleToken = document.getElementById('toggle-token');

// Load saved config
chrome.storage.sync.get(['apiUrl', 'token'], (result) => {
  if (result.apiUrl) elApiUrl.value = result.apiUrl;
  if (result.token) elApiToken.value = result.token;
});

// Toggle token visibility
elToggleToken.addEventListener('click', () => {
  const isPassword = elApiToken.type === 'password';
  elApiToken.type = isPassword ? 'text' : 'password';
  elToggleToken.textContent = isPassword ? '🙈' : '👁';
});

// Save settings
document.getElementById('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const apiUrl = elApiUrl.value.trim().replace(/\/$/, '');
  const token = elApiToken.value.trim();

  if (!apiUrl) {
    showStatus('O URL da API é obrigatório.', 'error');
    elApiUrl.focus();
    return;
  }

  if (!token) {
    showStatus('O token de API é obrigatório.', 'error');
    elApiToken.focus();
    return;
  }

  elSaveBtn.disabled = true;
  elSaveBtn.textContent = 'A verificar...';

  // Test connection
  try {
    const res = await fetch(apiUrl + '/api/todos.php', {
      headers: { 'Authorization': 'Bearer ' + token },
    });
    if (res.status === 401 || res.status === 403) {
      throw new Error('Token inválido ou sem permissão.');
    }
    if (!res.ok && res.status !== 200) {
      const text = await res.text();
      throw new Error(`Erro ${res.status}: ${text.slice(0, 80)}`);
    }
  } catch (err) {
    if (err.name === 'TypeError') {
      // Network error — save anyway, might be a local URL
      chrome.storage.sync.set({ apiUrl, token }, () => {
        showStatus('Definições guardadas (não foi possível verificar a ligação).', 'success');
      });
      elSaveBtn.disabled = false;
      elSaveBtn.textContent = 'Guardar definições';
      return;
    }
    showStatus(err.message, 'error');
    elSaveBtn.disabled = false;
    elSaveBtn.textContent = 'Guardar definições';
    return;
  }

  chrome.storage.sync.set({ apiUrl, token }, () => {
    showStatus('✓ Definições guardadas com sucesso!', 'success');
    elSaveBtn.disabled = false;
    elSaveBtn.textContent = 'Guardar definições';
  });
});

function showStatus(msg, type) {
  elStatus.textContent = msg;
  elStatus.className = 'status ' + type;
  if (type === 'success') {
    setTimeout(() => { elStatus.textContent = ''; elStatus.className = 'status'; }, 4000);
  }
}
