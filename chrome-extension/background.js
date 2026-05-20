// ── Offscreen document (MQTT persistent connection) ─────────────────────────

async function ensureOffscreen() {
  try {
    if (await chrome.offscreen.hasDocument()) return;
  } catch { /* Chrome < 116 — fall through to createDocument */ }
  try {
    await chrome.offscreen.createDocument({
      url: chrome.runtime.getURL('offscreen.html'),
      reasons: ['BLOBS'],
      justification: 'Maintain MQTT WebSocket connection for real-time notifications',
    });
  } catch (e) {
    if (!e.message?.includes('already')) throw e;
  }
}

async function startMqttIfEnabled() {
  const cfg = await chrome.storage.sync.get(
    ['mqttEnabled', 'mqttBrokerUrl', 'mqttUsername', 'mqttPassword', 'mqttTopics']
  );
  if (!cfg.mqttEnabled || !cfg.mqttBrokerUrl) return;
  await ensureOffscreen();

  const connectMsg = {
    type: 'mqtt-connect',
    config: {
      brokerUrl: cfg.mqttBrokerUrl,
      username:  cfg.mqttUsername || '',
      password:  cfg.mqttPassword || '',
      topics:    cfg.mqttTopics   || [],
    },
  };

  // Retry a few times — the offscreen listener may not be registered yet
  for (let attempt = 0; attempt < 5; attempt++) {
    try {
      await chrome.runtime.sendMessage(connectMsg);
      return; // success
    } catch {
      await new Promise(r => setTimeout(r, 300));
    }
  }
}

// ── Lifecycle ────────────────────────────────────────────────────────────────

chrome.runtime.onInstalled.addListener(async () => {
  await restorePanelBehavior();
  await startMqttIfEnabled();
});

chrome.runtime.onStartup.addListener(async () => {
  await restorePanelBehavior();
  await startMqttIfEnabled();
});

async function restorePanelBehavior() {
  const { sidebarMode } = await chrome.storage.sync.get(['sidebarMode']);
  await chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: !!sidebarMode });
}

// ── MQTT message handler ─────────────────────────────────────────────────────

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.type === 'mqtt-message') {
    handleIncomingMessage(msg);
    sendResponse({ ok: true });
    return true;
  }

  if (msg.type === 'mqtt-status') {
    // offscreen.js already sends to all listeners — nothing extra needed here
    sendResponse({ ok: true });
    return true;
  }

  // Relay publish requests from popup/sidepanel → offscreen
  if (msg.type === 'mqtt-publish') {
    ensureOffscreen()
      .then(() => chrome.runtime.sendMessage(msg))
      .then(res => sendResponse(res ?? { ok: true }))
      .catch(err => sendResponse({ ok: false, error: err.message }));
    return true;
  }

  // Relay connect/disconnect from options page
  if (msg.type === 'mqtt-connect' || msg.type === 'mqtt-disconnect') {
    ensureOffscreen()
      .then(() => chrome.runtime.sendMessage(msg))
      .then(() => sendResponse({ ok: true }))
      .catch(err => sendResponse({ ok: false, error: err.message }));
    return true;
  }

  // mqtt-reconnect: background reads storage (offscreen can't) then sends mqtt-connect
  if (msg.type === 'mqtt-reconnect') {
    chrome.storage.sync.get(
      ['mqttEnabled', 'mqttBrokerUrl', 'mqttUsername', 'mqttPassword', 'mqttTopics'],
      async cfg => {
        if (!cfg.mqttEnabled || !cfg.mqttBrokerUrl) {
          sendResponse({ ok: false, error: 'MQTT não está ativado nas definições.' });
          return;
        }
        try {
          await ensureOffscreen();
          await chrome.runtime.sendMessage({
            type: 'mqtt-connect',
            config: {
              brokerUrl: cfg.mqttBrokerUrl,
              username:  cfg.mqttUsername || '',
              password:  cfg.mqttPassword || '',
              topics:    cfg.mqttTopics   || [],
            },
          });
          sendResponse({ ok: true });
        } catch (err) {
          sendResponse({ ok: false, error: err.message });
        }
      }
    );
    return true;
  }

  // Relay status request: offscreen responds directly; background just relays
  if (msg.type === 'mqtt-status-request') {
    ensureOffscreen()
      .then(() => chrome.runtime.sendMessage(msg))
      .then(res => sendResponse(res ?? { connected: false, state: 'disconnected' }))
      .catch(() => sendResponse({ connected: false, state: 'disconnected' }));
    return true;
  }
});

async function handleIncomingMessage(msg) {
  // 1. Persist in local storage (last 50 messages)
  const { mqttMessages = [] } = await chrome.storage.local.get(['mqttMessages']);
  mqttMessages.unshift({ topic: msg.topic, payload: msg.payload, ts: msg.ts });
  await chrome.storage.local.set({ mqttMessages: mqttMessages.slice(0, 50) });

  // 2. Chrome notification
  chrome.notifications.create({
    type: 'basic',
    iconUrl: 'icons/icon48.png',
    title: `📡 ${msg.topic}`,
    message: msg.payload.length > 120 ? msg.payload.slice(0, 117) + '…' : msg.payload,
    silent: false,
  });

  // offscreen.js already delivers the message to all open extension pages via
  // chrome.runtime.sendMessage, so no extra forwarding is needed here.
}
