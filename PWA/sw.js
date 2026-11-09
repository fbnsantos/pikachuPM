const CACHE = 'pikachu-pwa-v40';
const SHELL = [
  './index.html',
  './app.css',
  './app.js',
  '../assets/chat/pkchat.js',
  '../assets/chat/pkchat.css',
  './img/icon-192x192.png',
  './img/icon-512x512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
      .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
      .then(clients => clients.forEach(c => c.postMessage({ type: 'SW_UPDATED' })))
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // Só GET pode ir para a cache (uploads do chat, etc. vão sempre à rede)
  if (e.request.method !== 'GET') return;
  // API calls: always network, no cache
  if (url.pathname.includes('/api/') || url.pathname.includes('/tabs/')) {
    e.respondWith(fetch(e.request).catch(() => new Response('{"error":"offline"}', { headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  // index.html: network-first
  if (url.pathname.endsWith('/') || url.pathname.endsWith('index.html') || url.search.includes('_sw=')) {
    e.respondWith(
      fetch(e.request, { cache: 'no-store' })
        .then(resp => { caches.open(CACHE).then(c => c.put(e.request, resp.clone())); return resp; })
        .catch(() => caches.match('./index.html'))
    );
    return;
  }
  // App shell: cache-first
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
      const clone = resp.clone();
      caches.open(CACHE).then(c => c.put(e.request, clone));
      return resp;
    }))
  );
});

// Clicar numa notificação do chat → foca/abre a app
self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) { if ('focus' in c) return c.focus(); }
      return self.clients.openWindow ? self.clients.openWindow('./index.html') : null;
    })
  );
});
