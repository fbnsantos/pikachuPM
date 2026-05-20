// Runs in the hidden offscreen document — persists while Chrome is open.
// Maintains the MQTT WebSocket connection and forwards messages to the runtime.

let client = null;
let currentConfig = null;
let currentState  = 'disconnected'; // 'connecting' | 'connected' | 'error' | 'disconnected'

// ── Connect ────────────────────────────────────────────────────────────────
function connect(cfg) {
  if (client) { client.end(true); client = null; }
  currentConfig = cfg;

  const opts = {
    clientId: 'pikachuPM_ext_' + Math.random().toString(16).slice(2, 10),
    clean: true,
    reconnectPeriod: 5000,
    connectTimeout: 10000,
    keepalive: 30,
  };
  if (cfg.username) opts.username = cfg.username;
  if (cfg.password) opts.password = cfg.password;

  setState('connecting', cfg.brokerUrl);

  try {
    client = mqtt.connect(cfg.brokerUrl, opts);
  } catch (err) {
    setState('error', err.message || 'Erro ao iniciar ligação WebSocket');
    return;
  }

  client.on('connect', () => {
    setState('connected', cfg.brokerUrl);
    (cfg.topics || []).forEach(t => {
      if (t.trim()) client.subscribe(t.trim(), { qos: 1 });
    });
  });

  client.on('reconnect', () => {
    setState('connecting', cfg.brokerUrl);
  });

  client.on('message', (topic, payload) => {
    const msg = {
      type: 'mqtt-message',
      topic,
      payload: payload.toString(),
      ts: Date.now(),
    };
    send(msg);
  });

  client.on('error', err => {
    // WebSocket errors often have an empty message — provide a fallback
    const detail = err.message || 'Sem resposta do broker (verifica URL e porta)';
    setState('error', detail);
  });

  client.on('close', () => {
    // mqtt.js fires 'close' after every 'error'; keep the error message visible
    // until the next reconnect attempt (which fires 'reconnect' → 'connecting')
    if (currentState !== 'error' && currentState !== 'connecting') {
      setState('disconnected');
    }
  });
}

function setState(state, detail = '') {
  currentState = state;
  const msg = { type: 'mqtt-status', state };
  if (state === 'connected')   msg.brokerUrl = detail;
  if (state === 'connecting')  msg.brokerUrl = detail;
  if (state === 'error')       msg.error     = detail;
  send(msg);
}

function disconnect() {
  if (client) { client.end(true); client = null; }
  currentConfig = null;
  setState('disconnected');
}

function publish(topic, payload, qos = 1) {
  if (!client || !client.connected) {
    return false; // not connected
  }
  client.publish(topic, payload, { qos });
  return true;
}

function send(msg) {
  try { chrome.runtime.sendMessage(msg); } catch { /* receiver not ready */ }
}

// ── Message listener ────────────────────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  switch (msg.type) {
    case 'mqtt-connect':
      // Config always comes from background.js (offscreen has no chrome.storage)
      connect(msg.config);
      sendResponse({ ok: true });
      break;
    case 'mqtt-disconnect':
      disconnect();
      sendResponse({ ok: true });
      break;
    case 'mqtt-publish': {
      const sent = publish(msg.topic, msg.payload, msg.qos);
      sendResponse(sent
        ? { ok: true }
        : { ok: false, error: 'Não ligado ao broker MQTT.' }
      );
      break;
    }
    case 'mqtt-status-request':
      sendResponse({
        connected: !!(client && client.connected),
        state: currentState,
      });
      break;
  }
  return true;
});

// Auto-connect is triggered by background.js after document creation.
// Offscreen documents cannot access chrome.storage — only chrome.runtime.
