// pikachuPM — Firefox Extension Background Script (MV2, persistent)
// MQTT corre aqui directamente — não é necessário offscreen document.

// ── MQTT ─────────────────────────────────────────────────────────────────────
let mqttClient    = null;
let currentConfig = null;
let currentState  = 'disconnected';

function mqttConnect(cfg) {
  if (mqttClient) { try { mqttClient.end(true); } catch {} mqttClient = null; }
  currentConfig = cfg;

  const opts = {
    clientId:        'pikachuPM_ff_' + Math.random().toString(16).slice(2, 10),
    clean:           true,
    reconnectPeriod: 5000,
    connectTimeout:  10000,
    keepalive:       30,
  };
  if (cfg.username) opts.username = cfg.username;
  if (cfg.password) opts.password = cfg.password;

  setMqttState('connecting', cfg.brokerUrl);

  try {
    mqttClient = mqtt.connect(cfg.brokerUrl, opts);
  } catch (err) {
    setMqttState('error', err.message || 'Erro ao iniciar ligação WebSocket');
    return;
  }

  mqttClient.on('connect', () => {
    setMqttState('connected', cfg.brokerUrl);
    (cfg.topics || []).forEach(t => {
      if (t.trim()) mqttClient.subscribe(t.trim(), { qos: 1 });
    });
  });

  mqttClient.on('reconnect', () => setMqttState('connecting', cfg.brokerUrl));

  mqttClient.on('message', (topic, payload) => {
    handleIncomingMessage({ topic, payload: payload.toString(), ts: Date.now() });
  });

  mqttClient.on('error', err => {
    setMqttState('error', err.message || 'Sem resposta do broker (verifica URL e porta)');
  });

  mqttClient.on('close', () => {
    if (currentState !== 'error' && currentState !== 'connecting') {
      setMqttState('disconnected');
    }
  });
}

function mqttDisconnect() {
  if (mqttClient) { try { mqttClient.end(true); } catch {} mqttClient = null; }
  currentConfig = null;
  setMqttState('disconnected');
}

function setMqttState(state, detail = '') {
  currentState = state;
  const msg = { type: 'mqtt-status', state };
  if (state === 'connected')  msg.brokerUrl = detail;
  if (state === 'connecting') msg.brokerUrl = detail;
  if (state === 'error')      msg.error     = detail;
  try { browser.runtime.sendMessage(msg); } catch { /* sem listeners activos */ }
}

async function handleIncomingMessage(msg) {
  // Persistir últimas 50 mensagens
  const { mqttMessages = [] } = await browser.storage.local.get(['mqttMessages']);
  mqttMessages.unshift({ topic: msg.topic, payload: msg.payload, ts: msg.ts });
  await browser.storage.local.set({ mqttMessages: mqttMessages.slice(0, 50) });

  // Notificação
  browser.notifications.create({
    type:     'basic',
    iconUrl:  'icons/icon48.png',
    title:    `📡 ${msg.topic}`,
    message:  msg.payload.length > 120 ? msg.payload.slice(0, 117) + '…' : msg.payload,
  });

  // Reenviar para sidebar/popup abertos
  try {
    browser.runtime.sendMessage({
      type:    'mqtt-message',
      topic:   msg.topic,
      payload: msg.payload,
      ts:      msg.ts,
    });
  } catch { /* sem listeners */ }
}

// ── Arranque ──────────────────────────────────────────────────────────────────
async function startMqttIfEnabled() {
  const cfg = await browser.storage.sync.get(
    ['mqttEnabled', 'mqttBrokerUrl', 'mqttUsername', 'mqttPassword', 'mqttTopics']
  );
  if (!cfg.mqttEnabled || !cfg.mqttBrokerUrl) return;
  mqttConnect({
    brokerUrl: cfg.mqttBrokerUrl,
    username:  cfg.mqttUsername || '',
    password:  cfg.mqttPassword || '',
    topics:    cfg.mqttTopics   || [],
  });
}

browser.runtime.onInstalled.addListener(() => startMqttIfEnabled());
browser.runtime.onStartup.addListener(() => startMqttIfEnabled());

// ── Mensagens ─────────────────────────────────────────────────────────────────
browser.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.type === 'mqtt-connect') {
    mqttConnect(msg.config);
    sendResponse({ ok: true });
    return true;
  }

  if (msg.type === 'mqtt-disconnect') {
    mqttDisconnect();
    sendResponse({ ok: true });
    return true;
  }

  if (msg.type === 'mqtt-publish') {
    if (!mqttClient || !mqttClient.connected) {
      sendResponse({ ok: false, error: 'Não ligado ao broker MQTT.' });
    } else {
      mqttClient.publish(msg.topic, msg.payload, { qos: 1 });
      sendResponse({ ok: true });
    }
    return true;
  }

  if (msg.type === 'mqtt-reconnect') {
    browser.storage.sync.get(
      ['mqttEnabled', 'mqttBrokerUrl', 'mqttUsername', 'mqttPassword', 'mqttTopics'],
      cfg => {
        if (!cfg.mqttEnabled || !cfg.mqttBrokerUrl) {
          sendResponse({ ok: false, error: 'MQTT não está ativado nas definições.' });
          return;
        }
        mqttConnect({
          brokerUrl: cfg.mqttBrokerUrl,
          username:  cfg.mqttUsername || '',
          password:  cfg.mqttPassword || '',
          topics:    cfg.mqttTopics   || [],
        });
        sendResponse({ ok: true });
      }
    );
    return true;
  }

  if (msg.type === 'mqtt-status-request') {
    sendResponse({
      connected: !!(mqttClient && mqttClient.connected),
      state:     currentState,
    });
    return true;
  }
});
