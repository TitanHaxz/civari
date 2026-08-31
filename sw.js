/* NEARBY Service Worker — ağ öncelikli (online'da her zaman güncel, offline'da yedek) */
const CACHE = 'nearby-v23';

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(['./', 'index.html']).catch(() => {})));
});

self.addEventListener('activate', e => {
  self.clients.claim();
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;                 // POST (API) -> her zaman ağ
  e.respondWith(
    fetch(req).then(res => {
      if (req.mode === 'navigate') {                // sadece sayfa gezintisini offline için sakla
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(req, copy));
      }
      return res;
    }).catch(() => caches.match(req).then(m => m || caches.match('./')))
  );
});
