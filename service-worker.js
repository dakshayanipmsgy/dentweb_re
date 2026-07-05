const CACHE_VERSION = 'dakshayani-pwa-v2';
const CACHE_NAME = CACHE_VERSION;

// Increment CACHE_VERSION after changing cached assets so deployed users receive
// fresh CSS/JS/manifest files. Activation removes all older Dakshayani caches.
const SW_BASE = new URL('./', self.location.href);
const SAFE_ASSETS = [
  'manifest.webmanifest',
  'offline.html',
  'style.css',
  'layout-styles.css',
  'script.js',
  'login.js',
  'assets/css/admin-unified.css',
  'assets/css/pwa-shell.css',
  'assets/js/pwa.js',
  'assets/icons/app-icon.svg',
  'assets/icons/app-icon-maskable.svg'
].map((path) => new URL(path, SW_BASE).toString());

const PRIVATE_ROUTE_PATTERNS = [
  /(?:^|\/)admin(?:-|\/|$)/i,
  /(?:^|\/)customer(?:-|\/|$)/i,
  /(?:^|\/)employee(?:-|\/|$)/i,
  /dashboard/i,
  /quotation|agreement|dispatch|challan|invoice|receipt/i,
  /complaint|task|lead|record|document/i,
  /download|storage\/|generated|handover|pdf/i,
  /customer[-_]?files|uploads/i,
  /api\//i
];
const SAFE_STATIC_EXTENSIONS = /\.(?:css|js|svg|webmanifest)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SAFE_ASSETS)).catch(() => undefined));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys
    .filter((key) => key.startsWith('dakshayani-pwa') && key !== CACHE_NAME)
    .map((key) => caches.delete(key)))));
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

function isPrivateRoute(url) {
  const route = url.pathname + url.search;
  return PRIVATE_ROUTE_PATTERNS.some((pattern) => pattern.test(route));
}

function noStoreFetch(request) {
  return fetch(request, { cache: 'no-store' });
}

function isSafeStaticAsset(request, url) {
  if (request.method !== 'GET' || url.origin !== self.location.origin) return false;
  if (isPrivateRoute(url)) return false;
  return SAFE_STATIC_EXTENSIONS.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Never cache POST responses, authenticated navigation/PHP pages, generated
  // documents, uploads, storage files, or API-like responses.
  if (request.method !== 'GET') return;

  if (request.mode === 'navigate') {
    event.respondWith(noStoreFetch(request).catch(() => caches.match(new URL('offline.html', SW_BASE).toString())));
    return;
  }

  if (url.pathname.endsWith('.php') || isPrivateRoute(url)) {
    event.respondWith(noStoreFetch(request));
    return;
  }

  if (!isSafeStaticAsset(request, url)) return;

  event.respondWith(caches.match(request).then((cached) => {
    const network = fetch(request, { cache: 'no-cache' }).then((response) => {
      if (response.ok && response.type === 'basic') {
        caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
      }
      return response;
    });
    return cached || network;
  }));
});
