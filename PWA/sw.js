const CACHE = 'pikachu-pwa-v42';
const V = '?v=42';
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
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
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
