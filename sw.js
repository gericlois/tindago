// Service worker for the TindaGo PWA (one registration, whole-site
// scope). Deliberately conservative:
// only same-origin, versionless static assets (CSS/JS/images/fonts) get
// cached. Every .php page is left to the network untouched, since this app
// shows real-time wallet balances, credit lines, and order/payment status
// that must never be served stale from a cache.
const CACHE_NAME = 'tindago-static-v1';
const PRECACHE_ASSETS = [
  './assets/css/theme.css',
  './assets/css/style.css',
  './assets/js/main.js',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(PRECACHE_ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (key) { return key !== CACHE_NAME; })
            .map(function (key) { return caches.delete(key); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') {
    return;
  }

  var url = new URL(event.request.url);
  var isSameOrigin = url.origin === self.location.origin;
  var isStaticAsset = isSameOrigin && /\.(css|js|png|jpe?g|svg|webp|woff2?)$/.test(url.pathname);

  if (!isStaticAsset) {
    return; // every dynamic page (all .php requests) bypasses the SW entirely
  }

  event.respondWith(
    caches.match(event.request).then(function (cached) {
      if (cached) {
        return cached;
      }
      return fetch(event.request).then(function (response) {
        var responseClone = response.clone();
        caches.open(CACHE_NAME).then(function (cache) {
          cache.put(event.request, responseClone);
        });
        return response;
      });
    })
  );
});
