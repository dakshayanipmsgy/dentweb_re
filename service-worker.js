const CACHE_NAME = 'dakshayani-pwa-static-v2';

// Resolve precache URLs relative to the service worker file so cPanel subdirectory
// installs (example.com/portal/service-worker.js) cache /portal/assets/... safely.
const SW_BASE = new URL('./', self.location.href);
const SAFE_ASSETS = [
  'manifest.webmanifest',
  'style.css',
  'layout-styles.css',
  'script.js',
  'login.js',
  'assets/css/admin-unified.css',
  'assets/css/pwa-shell.css',
  'assets/js/pwa.js',
  'assets/icons/app-icon.svg',
  'assets/icons/app-icon-maskable.svg',
  'images/favicon.ico'
].map((path) => new URL(path, SW_BASE).toString());

// Authenticated PHP pages and generated/customer documents can contain private
// business or customer data, so the service worker never stores navigation/PHP
// responses or routes matching these portal/document keywords.
const PRIVATE_ROUTE_PATTERNS = [
  /(?:^|\/)admin(?:-|\/|$)/i,
  /(?:^|\/)customer(?:-|\/|$)/i,
  /(?:^|\/)employee(?:-|\/|$)/i,
  /dashboard/i,
  /quotation|agreement|dispatch|challan|invoice|receipt/i,
  /complaint|task|lead|record|document/i,
  /download|storage\/|generated|handover|pdf/i,
  /customer[-_]?files|uploads/i
];
const SAFE_STATIC_EXTENSIONS = /\.(?:css|js|svg|ico|woff2?|ttf|webmanifest)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SAFE_ASSETS)).catch(() => undefined));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
  self.clients.claim();
});

function isPrivateRoute(url) {
  const route = url.pathname + url.search;
  return PRIVATE_ROUTE_PATTERNS.some((pattern) => pattern.test(route));
}

function isSafeStaticAsset(request, url) {
  if (request.method !== 'GET' || url.origin !== self.location.origin) return false;
  if (isPrivateRoute(url)) return false;
  return SAFE_STATIC_EXTENSIONS.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Never cache POST responses, navigations, PHP pages, or document/private data.
  if (request.method !== 'GET' || request.mode === 'navigate' || url.pathname.endsWith('.php') || isPrivateRoute(url)) {
    return;
  }
  if (!isSafeStaticAsset(request, url)) return;

  event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
    if (response.ok && response.type === 'basic') {
      caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
    }
    return response;
  })));
});
