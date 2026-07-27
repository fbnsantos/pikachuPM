const CACHE = 'pikachu-pwa-v35';
const SHELL = [
  './index.html',
  './app.css',
  './app.js',
  './img/icon-192x192.png',
  './img/icon-512x512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // API calls: always network, no cache
  if (url.pathname.includes('/api/')) {
    e.respondWith(fetch(e.request).catch(() => new Response('{"error":"offline"}', { headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  // index.html: network-first (garante HTML sempre fresco, evita HTTP cache do browser)
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
