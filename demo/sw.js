// Offline support: remember every file of the page after the first visit.
// Network first, so a new deploy is picked up when online; the cache answers when offline.
const CACHE = 'daraja-playground-v1';
const FILES = [
  './', 'index.html', 'favicon.svg', 'css/style.css',
  'js/app.js', 'js/engine.js', 'js/daraja.js', 'js/mock.js', 'js/shop.js', 'js/sha256.js', 'js/snippets.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== self.location.origin) return;
  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((cache) => cache.put(req, copy));
        return res;
      })
      .catch(() => caches.match(req).then((hit) => hit || caches.match('index.html'))),
  );
});
