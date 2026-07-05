(() => {
  if (window.__dakshayaniPwaLoaded) return;
  window.__dakshayaniPwaLoaded = true;

  const manifestLink = document.querySelector('link[rel="manifest"]');
  const base = manifestLink?.href || document.baseURI;
  // Register beside the manifest so subdirectory deployments use /subdir/service-worker.js.
  const swUrl = new URL('service-worker.js', base).toString();
  const swScope = new URL('./', swUrl).toString();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(swUrl, { scope: swScope }).catch((error) => console.warn('PWA registration skipped:', error));
    }, { once: true });
  }

  let deferredPrompt;
  const dismissedKey = 'dakshayani-install-dismissed-v2';
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const show = () => {
    if (!deferredPrompt || isStandalone || localStorage.getItem(dismissedKey) === '1' || document.querySelector('.pwa-install-banner')) return;
    const banner = document.createElement('div');
    banner.className = 'pwa-install-banner';
    banner.setAttribute('role', 'status');
    banner.innerHTML = '<span>Install Dakshayani app for quicker access.</span><button type="button" class="pwa-install-action">Install</button><button type="button" class="pwa-install-close" aria-label="Dismiss install prompt">×</button>';
    document.body.appendChild(banner);
    banner.querySelector('.pwa-install-close').addEventListener('click', () => { localStorage.setItem(dismissedKey, '1'); banner.remove(); });
    banner.querySelector('.pwa-install-action').addEventListener('click', async () => { banner.remove(); deferredPrompt.prompt(); await deferredPrompt.userChoice.catch(() => null); deferredPrompt = null; });
  };
  window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); deferredPrompt = event; setTimeout(show, 1600); });
})();
