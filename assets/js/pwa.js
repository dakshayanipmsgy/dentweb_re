(() => {
  if (window.__dakshayaniPwaLoaded) return;
  window.__dakshayaniPwaLoaded = true;

  const manifestLink = document.querySelector('link[rel="manifest"]');
  const base = manifestLink?.href || document.baseURI;
  const swUrl = new URL('service-worker.js', base).toString();
  const swScope = new URL('./', swUrl).toString();
  const installHelpUrl = new URL('employee-app.php', base).toString();

  const storage = {
    get(key) { try { return window.localStorage.getItem(key); } catch (error) { return null; } },
    set(key, value) { try { window.localStorage.setItem(key, value); } catch (error) { /* Storage may be unavailable. */ } },
  };
  const session = {
    get(key) { try { return window.sessionStorage.getItem(key); } catch (error) { return null; } },
    set(key, value) { try { window.sessionStorage.setItem(key, value); } catch (error) { /* Storage may be unavailable. */ } },
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

  const isIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent || '') ||
    (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

  let deferredPrompt = null;

  const getInstallState = () => ({
    ...getDisplayMode(),
    canPrompt: Boolean(deferredPrompt),
    isIos: isIos(),
  });

  const notifyInstallState = () => {
    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
      const state = getInstallState();
      if (state.standalone) {
        button.textContent = 'App installed';
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      } else if (state.canPrompt) {
        button.textContent = button.dataset.installLabel || 'Install app';
        button.disabled = false;
        button.removeAttribute('aria-disabled');
      } else {
        button.textContent = button.dataset.fallbackLabel || 'View install steps';
        button.disabled = false;
        button.removeAttribute('aria-disabled');
      }
    });
    document.dispatchEvent(new CustomEvent('dakshayani:pwa-state', { detail: getInstallState() }));
  };

  const promptInstall = async (fallbackSelector = '') => {
    const state = getInstallState();
    if (state.standalone) return { outcome: 'installed' };

    if (!deferredPrompt) {
      if (fallbackSelector) document.querySelector(fallbackSelector)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return { outcome: 'unavailable' };
    }

    const prompt = deferredPrompt;
    deferredPrompt = null;
    try {
      await prompt.prompt();
      const choice = await prompt.userChoice;
      notifyInstallState();
      return choice || { outcome: 'dismissed' };
    } catch (error) {
      console.warn('PWA install prompt unavailable:', error);
      notifyInstallState();
      return { outcome: 'error' };
    }
  };

  window.dakshayaniPwa = Object.freeze({ getDisplayMode, getInstallState, promptInstall });

  document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-pwa-install]');
    if (!button) return;
    event.preventDefault();
    promptInstall(button.dataset.pwaInstallFallback || '');
  });

  const createNotice = (className, message, actions, role = 'status') => {
    if (document.querySelector(`.${className}`)) return null;
    const notice = document.createElement('div');
    const text = document.createElement('span');
    const actionWrap = document.createElement('span');
    notice.className = className;
    notice.setAttribute('role', role);
    notice.setAttribute('aria-live', role === 'alert' ? 'assertive' : 'polite');
    text.textContent = message;
    actionWrap.className = 'pwa-notice-actions';
    actionWrap.innerHTML = actions;
    notice.append(text, actionWrap);
    document.body.appendChild(notice);
    return notice;
  };

  const showUpdateNotice = (registration) => {
    if (!registration?.waiting || session.get('dakshayani-update-dismissed') === '1') return;
    const notice = createNotice('pwa-update-banner', 'App update available. Refresh to update.', '<button type="button" class="pwa-update-refresh">Refresh</button><button type="button" class="pwa-update-dismiss" aria-label="Dismiss update notice">×</button>');
    if (!notice) return;
    notice.querySelector('.pwa-update-dismiss').addEventListener('click', () => {
      session.set('dakshayani-update-dismissed', '1');
      notice.remove();
    });
    notice.querySelector('.pwa-update-refresh').addEventListener('click', () => {
      let refreshed = false;
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!refreshed) {
          refreshed = true;
          window.location.reload();
        }
      });
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

  const welcomeKey = 'dakshayani-standalone-welcome-dismissed-v2';
  const showStandaloneWelcome = () => {
    if (!getDisplayMode().standalone || storage.get(welcomeKey) === '1') return;
    const welcome = createNotice('pwa-welcome-note', 'Welcome to Dakshayani Work. Your dashboard and assigned tasks are ready.', '<button type="button" class="pwa-welcome-close" aria-label="Dismiss welcome note">Close</button>');
    if (!welcome) return;
    welcome.querySelector('.pwa-welcome-close').addEventListener('click', () => {
      storage.set(welcomeKey, '1');
      welcome.remove();
    });
  };

  const dismissedKey = 'dakshayani-install-dismissed-v4';
  const showInstallPrompt = () => {
    if (!deferredPrompt || getDisplayMode().standalone || storage.get(dismissedKey) === '1' || document.querySelector('.pwa-install-banner')) return;
    const banner = createNotice('pwa-install-banner', 'Install Dakshayani Work for quicker access to assigned tasks.', '<button type="button" class="pwa-install-action">Install</button><button type="button" class="pwa-install-close">Not now</button><a class="pwa-install-help" href="' + installHelpUrl + '">Install help</a>');
    if (!banner) return;
    banner.querySelector('.pwa-install-close').addEventListener('click', () => {
      storage.set(dismissedKey, '1');
      banner.remove();
    });
    banner.querySelector('.pwa-install-action').addEventListener('click', async () => {
      banner.remove();
      await promptInstall();
    });
  };

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    notifyInstallState();
    setTimeout(showInstallPrompt, 1200);
  });

  window.addEventListener('appinstalled', () => {
    storage.set(dismissedKey, '1');
    deferredPrompt = null;
    document.querySelector('.pwa-install-banner')?.remove();
    notifyInstallState();
  });

  const initialise = () => {
    showStandaloneWelcome();
    notifyInstallState();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
  else initialise();
})();
