(() => {
  const base = document.querySelector('link[rel="manifest"]')?.href || document.baseURI;
  const swUrl = new URL('service-worker.js', base).toString();
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register(swUrl).catch((error) => console.warn('PWA registration skipped:', error)));
  }
  let deferredPrompt;
  const dismissedKey = 'dakshayani-install-dismissed-v1';
  const dismissed = localStorage.getItem(dismissedKey) === '1';
  const show = () => {
    if (!deferredPrompt || dismissed || document.querySelector('.pwa-install-banner')) return;
    const banner = document.createElement('div');
    banner.className = 'pwa-install-banner';
    banner.innerHTML = '<span>Install Dakshayani app for quicker access.</span><button type="button" class="pwa-install-action">Install</button><button type="button" class="pwa-install-close" aria-label="Dismiss install prompt">×</button>';
    document.body.appendChild(banner);
    banner.querySelector('.pwa-install-close').addEventListener('click', () => { localStorage.setItem(dismissedKey, '1'); banner.remove(); });
    banner.querySelector('.pwa-install-action').addEventListener('click', async () => { banner.remove(); deferredPrompt.prompt(); await deferredPrompt.userChoice.catch(() => null); deferredPrompt = null; });
  };
  window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); deferredPrompt = event; setTimeout(show, 1200); });
})();
