(() => {
  if (window.__dakshayaniPwaLoaded) return;
  window.__dakshayaniPwaLoaded = true;

  const manifestLink = document.querySelector('link[rel="manifest"]');
  const base = manifestLink?.href || document.baseURI;
  const swUrl = new URL('service-worker.js', base).toString();
  const swScope = new URL('./', swUrl).toString();
  const installHelpUrl = new URL('app-install-help.php', base).toString();

  const storage = {
    get(key) { try { return window.localStorage.getItem(key); } catch (error) { return null; } },
    set(key, value) { try { window.localStorage.setItem(key, value); } catch (error) { /* Storage can be unavailable in private modes. */ } },
  };
  const session = {
    get(key) { try { return window.sessionStorage.getItem(key); } catch (error) { return null; } },
    set(key, value) { try { window.sessionStorage.setItem(key, value); } catch (error) { /* Storage can be unavailable in private modes. */ } },
  };

  const getDisplayMode = () => {
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches === true;
    const fullscreen = window.matchMedia?.('(display-mode: fullscreen)').matches === true;
    const minimalUi = window.matchMedia?.('(display-mode: minimal-ui)').matches === true;
    const iosStandalone = window.navigator.standalone === true;
    return {
      browser: !(standalone || fullscreen || minimalUi || iosStandalone),
      standalone: standalone || fullscreen || minimalUi || iosStandalone,
      iosStandalone,
    };
  };

  // Display-mode checks are used only for user-interface hints. Security remains enforced by server-side PHP authentication and role checks.
  window.dakshayaniPwa = Object.freeze({ getDisplayMode });

  const createNotice = (className, message, actions, role = 'status') => {
    if (document.querySelector(`.${className}`)) return null;
    const notice = document.createElement('div');
    notice.className = className;
    notice.setAttribute('role', role);
    notice.innerHTML = `<span>${message}</span>${actions}`;
    document.body.appendChild(notice);
    return notice;
  };

  const showUpdateNotice = (registration) => {
    if (!registration?.waiting || session.get('dakshayani-update-dismissed') === '1') return;
    const notice = createNotice('pwa-update-banner', 'App update available. Refresh to update.', '<button type="button" class="pwa-update-refresh">Refresh</button><button type="button" class="pwa-update-dismiss" aria-label="Dismiss update notice">×</button>');
    if (!notice) return;
    notice.querySelector('.pwa-update-dismiss').addEventListener('click', () => { session.set('dakshayani-update-dismissed', '1'); notice.remove(); });
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

  const welcomeKey = 'dakshayani-standalone-welcome-dismissed-v1';
  const showStandaloneWelcome = () => {
    if (!getDisplayMode().standalone || storage.get(welcomeKey) === '1') return;
    const welcome = createNotice('pwa-welcome-note', 'Welcome to the Dakshayani app. Sign in as Admin, Customer, or Employee to access your secure workspace.', '<button type="button" class="pwa-welcome-close" aria-label="Dismiss welcome note">Not now</button>');
    if (!welcome) return;
    welcome.querySelector('.pwa-welcome-close').addEventListener('click', () => { storage.set(welcomeKey, '1'); welcome.remove(); });
  };

  let deferredPrompt;
  const dismissedKey = 'dakshayani-install-dismissed-v3';
  const showInstallPrompt = () => {
    if (!deferredPrompt || getDisplayMode().standalone || storage.get(dismissedKey) === '1' || document.querySelector('.pwa-install-banner')) return;
    const banner = createNotice('pwa-install-banner', 'Install Dakshayani app for quicker access to your secure workspace.', '<button type="button" class="pwa-install-action">Install</button><button type="button" class="pwa-install-close">Not now</button><a class="pwa-install-help" href="' + installHelpUrl + '">Help</a>');
    if (!banner) return;
    banner.querySelector('.pwa-install-close').addEventListener('click', () => { storage.set(dismissedKey, '1'); banner.remove(); });
    banner.querySelector('.pwa-install-action').addEventListener('click', async () => {
      banner.remove();
      try { deferredPrompt.prompt(); await deferredPrompt.userChoice; } catch (error) { console.warn('PWA install prompt unavailable:', error); }
      deferredPrompt = null;
    });
  };

  window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); deferredPrompt = event; setTimeout(showInstallPrompt, 1600); });
  window.addEventListener('appinstalled', () => { storage.set(dismissedKey, '1'); deferredPrompt = null; document.querySelector('.pwa-install-banner')?.remove(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showStandaloneWelcome, { once: true }); else showStandaloneWelcome();
})();
