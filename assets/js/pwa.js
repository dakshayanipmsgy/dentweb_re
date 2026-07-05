(() => {
  if (window.__dakshayaniPwaLoaded) return;
  window.__dakshayaniPwaLoaded = true;

  const manifestLink = document.querySelector('link[rel="manifest"]');
  const base = manifestLink?.href || document.baseURI;
  const swUrl = new URL('service-worker.js', base).toString();
  const swScope = new URL('./', swUrl).toString();

  const createNotice = (className, message, actions) => {
    if (document.querySelector(`.${className}`)) return null;
    const notice = document.createElement('div');
    notice.className = className;
    notice.setAttribute('role', 'status');
    notice.innerHTML = `<span>${message}</span>${actions}`;
    document.body.appendChild(notice);
    return notice;
  };

  const showUpdateNotice = (registration) => {
    if (!registration?.waiting || sessionStorage.getItem('dakshayani-update-dismissed') === '1') return;
    const notice = createNotice('pwa-update-banner', 'App update available. Refresh to update.', '<button type="button" class="pwa-update-refresh">Refresh</button><button type="button" class="pwa-update-dismiss" aria-label="Dismiss update notice">×</button>');
    if (!notice) return;
    notice.querySelector('.pwa-update-dismiss').addEventListener('click', () => { sessionStorage.setItem('dakshayani-update-dismissed', '1'); notice.remove(); });
    notice.querySelector('.pwa-update-refresh').addEventListener('click', () => {
      let refreshed = false;
      navigator.serviceWorker.addEventListener('controllerchange', () => { if (!refreshed) { refreshed = true; window.location.reload(); } });
      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    });
  };

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(swUrl, { scope: swScope }).then((registration) => {
        showUpdateNotice(registration);
        registration.addEventListener('updatefound', () => {
          const worker = registration.installing;
          if (!worker) return;
          worker.addEventListener('statechange', () => {
            if (worker.state === 'installed' && navigator.serviceWorker.controller) showUpdateNotice(registration);
          });
        });
      }).catch((error) => console.warn('PWA registration skipped:', error));
    }, { once: true });
  }

  let deferredPrompt;
  const dismissedKey = 'dakshayani-install-dismissed-v2';
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const show = () => {
    if (!deferredPrompt || isStandalone || localStorage.getItem(dismissedKey) === '1' || document.querySelector('.pwa-install-banner')) return;
    const banner = createNotice('pwa-install-banner', 'Install Dakshayani app for quicker access to your secure workspace.', '<button type="button" class="pwa-install-action">Install</button><button type="button" class="pwa-install-close">Not now</button>');
    if (!banner) return;
    banner.querySelector('.pwa-install-close').addEventListener('click', () => { localStorage.setItem(dismissedKey, '1'); banner.remove(); });
    banner.querySelector('.pwa-install-action').addEventListener('click', async () => { banner.remove(); deferredPrompt.prompt(); await deferredPrompt.userChoice.catch(() => null); deferredPrompt = null; });
  };
  window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); deferredPrompt = event; setTimeout(show, 1600); });
})();
