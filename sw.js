// Chat Amn 1.0 - optional static-asset PWA cache, no external assets.
// API messages, authentication, media streams and user data are NEVER cached.
const CACHE_NAME = 'chat-amn-local-static-v1.0';
const STATIC_ASSETS = [
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/fonts/Vazirmatn-Variable.woff2',
  './assets/fonts/Estedad-Variable.woff2',
  './assets/fonts/Sahel-Variable.woff2',
  './assets/fonts/Shabnam.woff2', './assets/fonts/Samim.woff2',
  './assets/fonts/Parastoo.woff2', './assets/fonts/Gandom.woff2',
  './assets/fonts/Mikhak.woff2', './assets/fonts/NotoSansArabic.woff2',
  './assets/fonts/NotoNaskhArabic.woff2',
  './assets/img/icon.svg', './assets/img/icon-192.png', './assets/img/icon-512.png',
  './assets/img/ui-icons.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;
  const asset = STATIC_ASSETS.find(path => url.pathname.endsWith(path.replace('./', '')));
  if (!asset) return;
  const cacheKey = new URL(asset, self.registration.scope);
  event.respondWith(
    fetch(event.request).then(response => {
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(cacheKey, copy)).catch(() => {});
      }
      return response;
    }).catch(() => caches.match(cacheKey))
  );
});
