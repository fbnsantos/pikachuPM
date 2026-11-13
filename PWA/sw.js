const CACHE = 'pikachu-pwa-v48';
const V = '?v=48';
const CHAT_CFG_CACHE = 'pk-chat-cfg'; // guarda o URL da API do chat para o handler de push
// Os URLs têm de coincidir com os do index.html (incluindo ?v=), para servir offline
const SHELL = [
  './index.html',
  './app.css' + V,
  './app.js' + V,
  '../assets/chat/pkchat.js' + V,
  '../assets/chat/pkchat.css' + V,
  './img/icon-192x192.png',
  './img/icon-512x512.png',
];

self.addEventListener('install', e => {
  // Cada ficheiro é guardado à parte: uma falha não impede a instalação do SW novo
  e.waitUntil(caches.open(CACHE).then(c => Promise.all(SHELL.map(u => c.add(u).catch(err => console.warn('SW cache falhou:', u, err))))));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE && k !== CHAT_CFG_CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
      .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
      .then(clients => clients.forEach(c => c.postMessage({ type: 'SW_UPDATED' })))
  );
});

function networkFirst(req) {
  return fetch(req, { cache: 'no-store' })
    .then(resp => {
      if (resp.ok) { const clone = resp.clone(); caches.open(CACHE).then(c => c.put(req, clone)); }
      return resp;
    })
    .catch(() => caches.match(req).then(r => r || caches.match('./index.html')));
}

self.addEventListener('fetch', e => {
  // Só GET pode ir para a cache (uploads do chat, etc. vão sempre à rede)
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  // API calls: always network, no cache
  if (url.pathname.includes('/api/') || url.pathname.includes('/tabs/')) {
    e.respondWith(fetch(e.request).catch(() => new Response('{"error":"offline"}', { headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  // HTML, JS e CSS: rede primeiro (evita código antigo preso na cache); cache só offline
  if (url.pathname.endsWith('/') || /\.(html|js|css)$/.test(url.pathname) || url.search.includes('_sw=')) {
    e.respondWith(networkFirst(e.request));
    return;
  }
  // Imagens/ícones: cache-first
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
      const clone = resp.clone();
      caches.open(CACHE).then(c => c.put(e.request, clone));
      return resp;
    }))
  );
});

// ══ Notificações push do chat ══════════════════════════════
// O servidor envia um push sem conteúdo; aqui perguntamos a api/chat.php?action=push_inbox
// (o URL da API é guardado pela app na cache CHAT_CFG_CACHE) o que há por ler.
const CHAT_ICONS = { sprint: '🏃', prototype: '🧪', lead: '🎯' };

async function chatCfg() {
  try {
    const r = await (await caches.open(CHAT_CFG_CACHE)).match('chat-cfg');
    return r ? await r.json() : null;
  } catch (e) { return null; }
}

async function onChatPush(e) {
  let data = null;
  try {
    const cfg = await chatCfg();
    const sub = await self.registration.pushManager.getSubscription();
    if (cfg && cfg.api && sub) {
      const r = await fetch(cfg.api + '?action=push_inbox', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: sub.endpoint }), cache: 'no-store',
      });
      if (r.ok) data = await r.json();
    }
  } catch (err) { /* sem rede → notificação genérica */ }

  // Número de mensagens por ler no ícone da app
  try {
    if (data && typeof data.unread_total === 'number') {
      if (data.unread_total > 0 && self.navigator.setAppBadge) await self.navigator.setAppBadge(data.unread_total);
      else if (self.navigator.clearAppBadge) await self.navigator.clearAppBadge();
    }
  } catch (err) {}

  // App aberta e visível → o próprio chat mostra/toca a mensagem
  const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  if (wins.some(c => c.visibilityState === 'visible' && c.focused) && !(data && data.test)) return;

  const base = { icon: 'img/icon-192x192.png', badge: 'img/icon-192x192.png', vibrate: [200, 100, 200], renotify: true, silent: false };
  if (data && data.test) {
    await self.registration.showNotification('🔔 pikachuPM', { ...base, body: 'As notificações do chat estão a funcionar.', tag: 'pkchat-test' });
  }
  if (data && data.channels && data.channels.length) {
    for (const c of data.channels.slice(0, 3)) {
      const who = c.last ? `${c.last.username}: ${c.last.body}` : 'Nova mensagem';
      await self.registration.showNotification(`${c.mentions ? '@ ' : ''}${CHAT_ICONS[c.type] || '💬'} ${c.title}`, {
        ...base,
        body: c.unread > 1 ? `${who}\n(${c.unread} mensagens por ler)` : who,
        tag: `pkchat-${c.type}:${c.id}`,
        data: { type: c.type, id: c.id },
      });
    }
  } else if (!data) {
    await self.registration.showNotification('💬 pikachuPM', { ...base, body: 'Tens mensagens novas no chat.', tag: 'pkchat-generic' });
  }
}

self.addEventListener('push', e => e.waitUntil(onChatPush(e)));

// Clicar numa notificação do chat → abre a app nesse canal
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const d = e.notification.data || {};
  const hash = d.type && d.id ? `#chat=${d.type}:${d.id}` : '#chat';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) {
        if ('focus' in c) {
          c.postMessage({ type: 'OPEN_CHAT', chatType: d.type || null, chatId: d.id || null });
          return c.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow('./index.html' + hash) : null;
    })
  );
});
