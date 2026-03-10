const STATIC_CACHE = 'platica-nubecita-static-v1';
const STATIC_ASSETS = [
  '/manifest.webmanifest',
  '/apple-touch-icon.png',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-512.png',
  '/css/app.css?v=20260309pwa2',
  '/js/auth.js?v=20260309pwa2',
  '/js/chat.js?v=20260309pwa2',
  '/js/pwa-install.js?v=20260309pwa2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== STATIC_CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

const isStaticAsset = (request) => {
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return false;
  }

  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/uploads/') || url.pathname.startsWith('/attachments/')) {
    return false;
  }

  return (
    url.pathname === '/manifest.webmanifest' ||
    url.pathname === '/apple-touch-icon.png' ||
    url.pathname.startsWith('/icons/') ||
    url.pathname.startsWith('/css/') ||
    url.pathname.startsWith('/js/')
  );
};

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request));
    return;
  }

  if (!isStaticAsset(request)) {
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (!response || response.status !== 200) {
          return response;
        }

        const copy = response.clone();
        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
        return response;
      });
    })
  );
});
