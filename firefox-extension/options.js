const elApiUrl       = document.getElementById('api-url');
const elApiToken     = document.getElementById('api-token');
const elStatus       = document.getElementById('status');
const elSaveBtn      = document.getElementById('btn-save');
const elMqttEnabled  = document.getElementById('mqtt-enabled');
const elMqttFields   = document.getElementById('mqtt-fields');
const elMqttBroker   = document.getElementById('mqtt-broker');
const elMqttUser     = document.getElementById('mqtt-user');
const elMqttPass     = document.getElementById('mqtt-pass');
const elMqttTopics   = document.getElementById('mqtt-topics');
const elMqttPubTopic = document.getElementById('mqtt-pub-topic');
const elMqttSound    = document.getElementById('mqtt-sound');

// ── Load saved config ────────────────────────────────────────────────────────
browser.storage.sync.get(
  ['apiUrl', 'token', 'mqttEnabled', 'mqttBrokerUrl', 'mqttUsername', 'mqttPassword', 'mqttTopics', 'mqttPubTopic', 'mqttSound'],
  result => {
    if (result.apiUrl)       { elApiUrl.value = result.apiUrl; checkPermission(result.apiUrl); }
    if (result.token)          elApiToken.value = result.token;

    elMqttEnabled.checked = !!result.mqttEnabled;
    toggleMqttFields(!!result.mqttEnabled);
    if (result.mqttBrokerUrl) elMqttBroker.value   = result.mqttBrokerUrl;
    if (result.mqttUsername)  elMqttUser.value      = result.mqttUsername;
    if (result.mqttPassword)  elMqttPass.value      = result.mqttPassword;
    if (result.mqttTopics)    elMqttTopics.value    = result.mqttTopics.join('\n');
    if (result.mqttPubTopic)  elMqttPubTopic.value  = result.mqttPubTopic;
    elMqttSound.checked = result.mqttSound !== false; // ativo por omissão
  }
);

// ── MQTT toggle ───────────────────────────────────────────────────────────────
elMqttEnabled.addEventListener('change', () => toggleMqttFields(elMqttEnabled.checked));

function toggleMqttFields(show) {
  elMqttFields.classList.toggle('hidden', !show);
}

// ── Permission check ─────────────────────────────────────────────────────────
elApiUrl.addEventListener('blur', () => {
  if (elApiUrl.value.trim()) checkPermission(elApiUrl.value.trim());
});

function checkPermission(url) {
  try {
    const origin = new URL(url.trim().replace(/\/$/, '')).origin;
    browser.permissions.contains({ origins: [`${origin}/*`] }, granted => {
      if (granted) showStatus(`✓ Acesso autorizado a ${origin}`, 'success');
      else         showStatus(`Sem acesso a ${origin} — guarda para autorizar.`, '');
    });
  } catch { /* URL ainda inválido */ }
}

// ── Visibility toggles ────────────────────────────────────────────────────────
document.getElementById('toggle-token').addEventListener('click', () => {
  const t = elApiToken;
  t.type = t.type === 'password' ? 'text' : 'password';
  document.getElementById('toggle-token').textContent = t.type === 'password' ? '👁' : '🙈';
});

document.getElementById('toggle-mqtt-pass').addEventListener('click', () => {
  const t = elMqttPass;
  t.type = t.type === 'password' ? 'text' : 'password';
  document.getElementById('toggle-mqtt-pass').textContent = t.type === 'password' ? '👁' : '🙈';
});

// ws://host:port → http://host:port  (Chrome permissions use http/https patterns)
function wsOriginToHttp(wsUrl) {
  const url = wsUrl.trim();
  if (!url.match(/^wss?:\/\//i)) {
    throw new Error('O URL do broker deve começar com ws:// ou wss://');
  }
  const normalized = url.replace(/^wss:/i, 'https:').replace(/^ws:/i, 'http:');
  const origin = new URL(normalized).origin;
  if (!origin || origin === 'null') {
    throw new Error('URL do broker inválido — verifica o host e a porta (ex: ws://localhost:9001)');
  }
  return origin;
}

// ── Save ─────────────────────────────────────────────────────────────────────
document.getElementById('settings-form').addEventListener('submit', async e => {
  e.preventDefault();

  const apiUrl = elApiUrl.value.trim().replace(/\/$/, '');
  const token  = elApiToken.value.trim();

  if (!apiUrl) { showStatus('O URL da API é obrigatório.', 'error'); return; }
  if (!token)  { showStatus('O token de API é obrigatório.', 'error'); return; }

  let apiOrigin;
  try { apiOrigin = new URL(apiUrl).origin; }
  catch { showStatus('URL inválido. Exemplo: https://servidor.com/PK', 'error'); return; }

  const mqttEnabled   = elMqttEnabled.checked;
  const mqttBrokerUrl = elMqttBroker.value.trim();
  const mqttUsername  = elMqttUser.value.trim();
  const mqttPassword  = elMqttPass.value;
  const mqttTopics    = elMqttTopics.value.split('\n').map(t => t.trim()).filter(Boolean);
  const mqttPubTopic  = elMqttPubTopic.value.trim();

  // Collect all origins that need permission
  const originsToRequest = [`${apiOrigin}/*`];

  if (mqttEnabled) {
    if (!mqttBrokerUrl) {
      showStatus('Com MQTT activo, o URL do broker é obrigatório.', 'error');
      return;
    }
    try {
      const mqttOrigin = wsOriginToHttp(mqttBrokerUrl);
      if (mqttOrigin !== apiOrigin) originsToRequest.push(`${mqttOrigin}/*`);
    } catch (err) {
      showStatus(err.message, 'error');
      return;
    }
  }

  elSaveBtn.disabled = true;
  elSaveBtn.textContent = 'A pedir acesso...';

  // Request all needed host permissions in one dialog
  browser.permissions.request({ origins: originsToRequest }, async granted => {
    if (!granted) {
      showStatus(`Acesso negado. A extensão precisa de acesso a: ${originsToRequest.join(', ')}`, 'error');
      elSaveBtn.disabled = false;
      elSaveBtn.textContent = 'Guardar definições';
      return;
    }

    elSaveBtn.textContent = 'A guardar...';

    const mqttSound = elMqttSound.checked;
    await browser.storage.sync.set({
      apiUrl, token,
      mqttEnabled, mqttBrokerUrl, mqttUsername, mqttPassword, mqttTopics, mqttPubTopic, mqttSound,
    });

    // Reconnect MQTT with new config
    if (!mqttEnabled || !mqttBrokerUrl) {
      try { await browser.runtime.sendMessage({ type: 'mqtt-disconnect' }); } catch {}
      showStatus('✓ Definições guardadas. MQTT desativado.', 'success');
      elSaveBtn.disabled = false;
      elSaveBtn.textContent = 'Guardar definições';
      return;
    }

    // Send connect and wait for mqtt-status feedback (up to 8 seconds)
    showStatus('✓ Definições guardadas. A ligar ao broker…', 'success');
    elSaveBtn.disabled = false;
    elSaveBtn.textContent = 'Guardar definições';

    awaitMqttStatus(8000);

    try {
      await browser.runtime.sendMessage({
        type: 'mqtt-connect',
        config: { brokerUrl: mqttBrokerUrl, username: mqttUsername, password: mqttPassword, topics: mqttTopics },
      });
    } catch {
      showStatus('✓ Definições guardadas (reinicia a extensão para aplicar MQTT).', '');
    }
  });
});

// Fica à escuta do próximo mqtt-status e actualiza o UI, com timeout
function awaitMqttStatus(timeoutMs = 8000) {
  let settled = false;

  const timer = setTimeout(() => {
    if (settled) return;
    settled = true;
    showStatus('⚠ Sem resposta do broker — verifica o URL e a porta.', 'error');
  }, timeoutMs);

  const listener = (msg) => {
    if (msg.type !== 'mqtt-status' || settled) return;
    settled = true;
    clearTimeout(timer);
    browser.runtime.onMessage.removeListener(listener);

    if (msg.state === 'connected') {
      showStatus(`✓ Ligado ao broker: ${msg.brokerUrl || ''}`, 'success');
    } else if (msg.state === 'error') {
      showStatus(`✗ Falha ao ligar: ${msg.error || 'erro desconhecido'}`, 'error');
    } else if (msg.state === 'connecting') {
      // still connecting — wait for next status
      settled = false;
      browser.runtime.onMessage.addListener(listener);
    }
  };

  browser.runtime.onMessage.addListener(listener);
}

function showStatus(msg, type) {
  elStatus.textContent = msg;
  elStatus.className = 'status ' + type;
  if (type === 'success') {
    setTimeout(() => { elStatus.textContent = ''; elStatus.className = 'status'; }, 6000);
  }
}

