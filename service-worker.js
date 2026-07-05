const CACHE_NAME = 'dakshayani-pwa-v1';
const SAFE_ASSETS = [
  'manifest.webmanifest', 'style.css', 'layout-styles.css', 'script.js', 'login.js',
  'assets/css/admin-unified.css', 'assets/css/pwa-shell.css', 'assets/js/pwa.js',
  'assets/icons/app-icon.svg', 'assets/icons/app-icon-maskable.svg',
  'images/favicon.ico'
];
const PRIVATE_PATTERNS = [/admin/i, /customer/i, /employee/i, /dashboard/i, /quotation/i, /agreement/i, /dispatch/i, /challan/i, /invoice/i, /receipt/i, /complaint/i, /task/i, /lead/i, /download/i, /storage\//i, /generated/i, /pdf/i, /record/i];
const STATIC_EXTENSIONS = /\.(?:css|js|png|jpg|jpeg|svg|gif|webp|ico|woff2?|ttf|webmanifest)$/i;
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SAFE_ASSETS)).catch(() => undefined));
  self.skipWaiting();
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
  self.clients.claim();
});
function isSafeStatic(url) { return url.origin === self.location.origin && STATIC_EXTENSIONS.test(url.pathname) && !PRIVATE_PATTERNS.some((pattern) => pattern.test(url.pathname + url.search)); }
self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (request.mode === 'navigate' || url.pathname.endsWith('.php') || PRIVATE_PATTERNS.some((pattern) => pattern.test(url.pathname + url.search))) return;
  if (!isSafeStatic(url)) return;
  event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
    if (response.ok && response.type === 'basic') caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
    return response;
  })));
});
