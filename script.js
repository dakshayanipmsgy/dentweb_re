/**
 * Client-side enhancements for Dakshayani Enterprises
 * --------------------------------------------------
 * - Injects shared header/footer partials across static pages.
 * - Highlights the active navigation link based on the current URL.
 * - Adds accessible mobile navigation behaviour.
 * - Updates any element with the `data-current-year` attribute.
 */

const PARTIALS = {
  header: 'partials/header.php',
  footer: 'partials/footer.html',
};

const INLINE_PARTIALS = {
  header: `
    <header class="global-header" data-component="global-header">
      <div class="container header-inner">
        <a href="/index.php" class="brand" aria-label="Dakshayani Enterprises home">
          <img src="/images/logo/New dakshayani logo centered small.png" alt="Dakshayani Enterprises" class="brand-logo-em" />
          <span class="brand-text">Dakshayani Enterprises</span>
        </a>

        <nav class="nav-desktop" aria-label="Primary navigation">
          <a href="/index.php" class="nav-link">Home</a>
          <a href="/about.html" class="nav-link">About Us</a>
          <div class="nav-dropdown">
            <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
              Solutions
              <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="nav-dropdown-menu" role="menu">
              <a href="/solar-projects.html" class="nav-link" role="menuitem">Solar Projects</a>
              <a href="/govt-epc.html" class="nav-link" role="menuitem">Govt. EPC &amp; Infrastructure</a>
              <a href="/pm-surya-ghar.html" class="nav-link" role="menuitem">PM Surya Ghar Subsidy</a>
              <a href="/meera-gh2.html" class="nav-link" role="menuitem">Meera GH2 Initiative</a>
              <a href="/e-mobility.html" class="nav-link" role="menuitem">E-Mobility &amp; Charging</a>
            </div>
          </div>
          <div class="nav-dropdown">
            <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
              Knowledge Hub
              <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="nav-dropdown-menu" role="menu">
          <a href="/knowledge-hub.html" class="nav-link" role="menuitem">Knowledge Hub</a>
          <a href="/innovation-tech.html" class="nav-link" role="menuitem">Innovation &amp; Tech</a>
          <a href="/blog/index.php" class="nav-link" role="menuitem">Blog &amp; Insights</a>
          <a href="/calculator.html" class="nav-link" role="menuitem">Solar Calculator</a>
          <a href="/policies.html" class="nav-link" role="menuitem">Policies &amp; Compliance</a>
            </div>
          </div>
        </nav>

        <div class="nav-actions" role="group" aria-label="Header quick actions">
          <a href="/login.php" class="btn btn-secondary nav-login-link">Login Portal</a>
          <span class="nav-theme-badge" data-site-theme-label hidden></span>
        </div>

        <button
          type="button"
          class="menu-btn"
          aria-label="Open navigation menu"
          aria-controls="mobile-menu"
          aria-expanded="false"
          id="mobile-menu-button"
        >
          <i class="fas fa-bars" aria-hidden="true"></i>
          <span class="sr-only">Toggle navigation</span>
        </button>
      </div>

      <nav id="mobile-menu" class="nav-mobile" aria-label="Mobile navigation">
        <div class="nav-mobile-section" aria-label="Primary pages">
          <a href="/index.php">Home</a>
          <a href="/about.html">About Us</a>
          <a href="/login.php" class="btn btn-secondary">Login</a>
        </div>
        <div class="nav-mobile-divider" role="presentation"></div>
        <div class="nav-mobile-section" aria-label="Solutions">
          <p class="nav-mobile-label">Solutions</p>
          <a href="/solar-projects.html">Solar Projects</a>
          <a href="/govt-epc.html">Govt. EPC &amp; Infrastructure</a>
          <a href="/pm-surya-ghar.html">PM Surya Ghar Subsidy</a>
          <a href="/meera-gh2.html">Meera GH2 Initiative</a>
          <a href="/e-mobility.html">E-Mobility &amp; Charging</a>
        </div>
        <div class="nav-mobile-divider" role="presentation"></div>
        <div class="nav-mobile-section" aria-label="Knowledge Hub">
          <p class="nav-mobile-label">Knowledge Hub</p>
      <a href="/knowledge-hub.html">Knowledge Hub</a>
      <a href="/innovation-tech.html">Innovation &amp; Tech</a>
      <a href="/blog/index.php">Blog &amp; Insights</a>
      <a href="/calculator.html">Solar Calculator</a>
      <a href="/policies.html">Policies &amp; Compliance</a>
        </div>
        <div class="nav-mobile-divider" role="presentation"></div>
        <div class="nav-mobile-section" aria-label="Quick actions">
          <a href="/login.php" class="btn btn-secondary" data-close-mobile>Login Portal</a>
          <p class="nav-mobile-theme" data-site-theme-label hidden></p>
        </div>
      </nav>

    </header>

    <div class="site-search-overlay" data-site-search hidden>
      <div class="site-search-backdrop" data-close-search></div>
      <div class="site-search-dialog" role="dialog" aria-modal="true" aria-labelledby="site-search-title">
        <form class="site-search-form" data-site-search-form>
          <div class="site-search-header">
            <h2 id="site-search-title">Search Dakshayani Knowledge Hub</h2>
            <button type="button" class="site-search-close" data-close-search aria-label="Close search">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
          <div class="site-search-input">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" placeholder="Search blogs, FAQs, case studies…" aria-label="Search site content" required />
            <select name="segment" aria-label="Filter by segment">
              <option value="">All segments</option>
              <option value="residential">Residential</option>
              <option value="commercial">Commercial</option>
              <option value="agriculture">Agriculture</option>
            </select>
          </div>
          <div class="site-search-results" data-site-search-results>
            <p class="site-search-empty">Type to explore Dakshayani insights, project learnings, and FAQs.</p>
          </div>
        </form>
      </div>
    </div>
  `.trim(),
  footer: `
    <div class="container footer-content">
      <div>
        <div class="footer-brand">
          <img src="/images/logo/New dakshayani logo centered small.png" alt="Dakshayani Enterprises" class="brand-logo-em" />
          <span class="brand-text">Dakshayani Enterprises</span>
        </div>
        <p class="text-sm">
          Your trusted solar EPC partner in Ranchi, expanding clean energy access across Jharkhand,
          Chhattisgarh, Odisha, and Uttar Pradesh with Tier-1 technology and transparent service.
        </p>
        <div class="footer-social" aria-label="Social links">
          <a href="https://wa.me/917070278178" target="_blank" rel="noopener" aria-label="WhatsApp">
            <i class="fa-brands fa-whatsapp"></i>
          </a>
          <a href="mailto:connect@dakshayani.co.in" aria-label="Email">
            <i class="fa-solid fa-envelope"></i>
          </a>
          <a href="tel:+917070278178" aria-label="Call">
            <i class="fa-solid fa-phone"></i>
          </a>
        </div>
      </div>

      <div>
        <h4 class="font-bold text-lg">Solar &amp; Schemes</h4>
        <ul class="footer-links">
          <li><a href="/pm-surya-ghar.html">PM Surya Ghar Yojana</a></li>
          <li><a href="/financing.html">Financing &amp; Loans</a></li>
          <li><a href="/solar-projects.html#residential">Residential Solutions</a></li>
          <li><a href="/solar-projects.html#commercial">Commercial / Industrial</a></li>
      <li><a href="/calculator.html">Solar Savings Calculator</a></li>
    </ul>
  </div>

  <div>
    <h4 class="font-bold text-lg">Company</h4>
    <ul class="footer-links">
      <li><a href="/about.html">About Dakshayani Enterprises</a></li>
      <li><a href="/meera-gh2.html">Meera GH2 (Hydrogen)</a></li>
      <li><a href="/blog/index.php">Blog &amp; News</a></li>
      <li><a href="/policies.html#terms">T&amp;C / Warranty</a></li>
      <li><a href="/contact.html">Contact &amp; Support</a></li>
    </ul>
  </div>
</div>

<div class="container footer-bottom">
  <p>
    &copy; <span data-current-year></span> Dakshayani Enterprises. All rights reserved.
    Office: Maa Tara, Kilburn Colony, Hinoo, Ranchi, Jharkhand-834002.
  </p>
</div>

<div class="floating-support" data-floating-actions>
  <button
    type="button"
    class="floating-support__toggle"
    aria-haspopup="dialog"
    aria-expanded="false"
    aria-label="Open Dakshayani support options"
    data-floating-toggle
  >
    <img src="/images/Logopngsmallest.png" alt="Dakshayani Enterprises" />
  </button>

  <div
    class="quick-access-panel"
    role="dialog"
    aria-modal="false"
    aria-label="Dakshayani support menu"
    tabindex="-1"
    data-floating-menu
    hidden
  >
    <div class="quick-access-header">
      <h2>Connect with us</h2>
      <button type="button" class="quick-access-close" aria-label="Close support menu" data-floating-close>
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="quick-access-body">
      <div class="quick-access-group">
        <h3>Reach out instantly</h3>
        <div class="quick-access-actions two-column">
          <a href="tel:+917070278178" class="quick-access-link" data-close-floating>
            <i class="fa-solid fa-phone"></i>
            Call
          </a>
          <a href="https://wa.me/917070278178" class="quick-access-link" target="_blank" rel="noopener" data-close-floating>
            <i class="fa-brands fa-whatsapp"></i>
            WhatsApp
          </a>
          <a href="mailto:connect@dakshayani.co.in" class="quick-access-link" data-close-floating>
            <i class="fa-solid fa-envelope"></i>
            Mail
          </a>
          <a
            href="https://www.facebook.com/d.entranchi"
            class="quick-access-link"
            target="_blank"
            rel="noopener"
            data-close-floating
          >
            <i class="fa-brands fa-facebook-f"></i>
            Facebook
          </a>
          <a
            href="https://www.instagram.com/d.entranchi"
            class="quick-access-link"
            target="_blank"
            rel="noopener"
            data-close-floating
          >
            <i class="fa-brands fa-instagram"></i>
            Instagram
          </a>
        </div>
      </div>

      <div class="quick-access-group">
        <h3>Need detailed assistance?</h3>
        <a href="/contact.html" class="quick-access-link quick-access-link--primary" data-close-floating>
          <i class="fa-solid fa-comments"></i>
          Consult / Complaint / Connect
        </a>
      </div>

      <div class="quick-access-group">
        <h3>Language &amp; tools</h3>
        <div class="quick-access-language">
          <button type="button" class="quick-access-toggle" data-toggle-language data-close-floating>
            <i class="fa-solid fa-language"></i>
            English / हिंदी
          </button>
          <div id="google_translate_element" class="translate-widget" aria-hidden="true"></div>
        </div>
        <div class="quick-access-actions">
          <button
            type="button"
            class="quick-access-link"
            data-open-search
            data-close-floating
            aria-label="Open Dakshayani site search"
          >
            <i class="fa-solid fa-magnifying-glass"></i>
            Search our website
          </button>
        </div>
        <p class="quick-access-footnote">English ↔ हिंदी translation is powered by Google Translate.</p>
      </div>
    </div>
  </div>
</div>
  `.trim(),
};

const LANGUAGE_STORAGE_KEY = 'dakshayaniLanguagePreference';
const SITE_SEARCH_ENDPOINT = '/api/public/search';
let translateScriptRequested = false;
let translateReadyResolver = null;
const translateReady = new Promise((resolve) => {
  translateReadyResolver = resolve;
});

const FESTIVAL_THEMES = {
  default: {
    label: 'Standard décor',
    heroImage: '',
    primary: '',
    dark: '',
    overlay: '',
    banner: '',
  },
  diwali: {
    label: 'Diwali lights',
    heroImage: 'images/4.png',
    primary: '#f97316',
    dark: '#c2410c',
    overlay: 'rgba(17, 24, 39, 0.88)',
    banner: 'Happy Diwali! May your rooftops shine bright with clean solar energy and prosperity.',
    bannerBg: 'linear-gradient(120deg, rgba(249, 115, 22, 0.9), rgba(245, 158, 11, 0.78))',
    bannerColor: '#fff7ed',
  },
  holi: {
    label: 'Holi colours',
    heroImage: 'images/collage.jpg',
    primary: '#ec4899',
    dark: '#be123c',
    overlay: 'rgba(76, 29, 149, 0.72)',
    banner: 'Rang Barse! Celebrate Holi with vibrant savings and sustainable power from Dakshayani.',
    bannerBg: 'linear-gradient(120deg, rgba(236, 72, 153, 0.9), rgba(59, 130, 246, 0.75))',
    bannerColor: '#fdf2f8',
  },
  christmas: {
    label: 'Christmas glow',
    heroImage: 'images/team.jpg',
    primary: '#16a34a',
    dark: '#0f766e',
    overlay: 'rgba(15, 23, 42, 0.82)',
    banner: 'Season’s greetings! Spread warmth and clean energy this Christmas with Dakshayani Enterprises.',
    bannerBg: 'linear-gradient(120deg, rgba(20, 83, 45, 0.92), rgba(14, 116, 144, 0.8))',
    bannerColor: '#ecfdf5',
  },
};

const LEAD_FORM_ENDPOINT = '/api/leads/whatsapp';
const DEFAULT_HERO_IMAGE = 'images/hero/hero.png';
const DEFAULT_THEME = {
  primary_color: '#0f766e',
  secondary_color: '#f59e0b',
  accent_color: '#0ea5e9',
  button_style: 'rounded',
  card_style: 'soft',
};

/**
 * Resolve a partial path relative to the location of this script file.
 * This keeps partial loading working from nested directories such as `/pilot`.
 */
function resolvePartialUrl(relativePath) {
  const scriptEl = document.querySelector('script[src*="script.js"]');
  if (!scriptEl) return relativePath;
  const scriptUrl = new URL(scriptEl.getAttribute('src'), window.location.href);
  const baseUrl = new URL('./', scriptUrl);
  return new URL(relativePath, baseUrl).toString();
}

let siteContentReadyPromise = null;

function ensureSiteContentReady() {
  if (siteContentReadyPromise) {
    return siteContentReadyPromise;
  }

  const globalPromise = window.DakshayaniSiteContent;
  if (globalPromise && typeof globalPromise.then === 'function') {
    siteContentReadyPromise = globalPromise;
    return siteContentReadyPromise;
  }

  const existingScript = document.querySelector('script[src*="site-content.js"]');

  siteContentReadyPromise = new Promise((resolve, reject) => {
    const handleReady = () => {
      const promise = window.DakshayaniSiteContent;
      if (promise && typeof promise.then === 'function') {
        promise.then(resolve).catch(reject);
      } else {
        resolve(null);
      }
    };

    const handleError = () => {
      reject(new Error('Failed to load site-content.js'));
    };

    if (existingScript) {
      existingScript.addEventListener('load', handleReady, { once: true });
      existingScript.addEventListener('error', handleError, { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = resolvePartialUrl('site-content.js');
    script.defer = true;
    script.addEventListener('load', handleReady, { once: true });
    script.addEventListener('error', handleError, { once: true });
    document.head.appendChild(script);
  }).catch((error) => {
    console.warn(error);
    return null;
  });

  return siteContentReadyPromise;
}

let latestSiteContent = null;

function syncThemeBadges(detail) {
  const theme = detail && detail.theme ? detail.theme : null;
  const label = theme && typeof theme.seasonLabel === 'string' ? theme.seasonLabel.trim() : '';
  const announcement = theme && typeof theme.announcement === 'string' ? theme.announcement.trim() : '';

  if (label) {
    document.body.dataset.themeSeason = label;
  } else {
    delete document.body.dataset.themeSeason;
  }

  document.querySelectorAll('[data-site-theme-label]').forEach((node) => {
    if (!(node instanceof HTMLElement)) {
      return;
    }
    if (!label) {
      node.textContent = '';
      node.hidden = true;
      node.removeAttribute('title');
      delete node.dataset.themeAnnouncement;
      return;
    }

    node.textContent = label;
    node.hidden = false;
    if (announcement) {
      node.setAttribute('title', announcement);
      node.dataset.themeAnnouncement = announcement;
    } else {
      node.removeAttribute('title');
      delete node.dataset.themeAnnouncement;
    }
  });
}

document.addEventListener('dakshayani:site-content-ready', (event) => {
  latestSiteContent = event.detail || latestSiteContent;
  syncThemeBadges(latestSiteContent);
});

document.addEventListener('dakshayani:site-content-error', () => {
  latestSiteContent = null;
  syncThemeBadges(null);
});

/**
 * Determine the canonical page key from the current location.
 */
function getPageKey(pathname = window.location.pathname) {
  const cleaned = pathname.replace(/\/+$/, '');
  const fileName = cleaned.split('/').pop() || 'index.php';
  return fileName.replace(/\.(html|php)$/i, '') || 'index';
}

/**
 * Fetch a HTML partial and inject it into the provided host element.
 */
function getPartialKey(partialPath) {
  return Object.entries(PARTIALS).find(([, path]) => path === partialPath)?.[0];
}

async function injectPartial(selector, partialPath) {
  const host = document.querySelector(selector);
  if (!host) return;

  const url = resolvePartialUrl(partialPath);
  const partialKey = getPartialKey(partialPath);

  try {
    const response = await fetch(url, { cache: 'no-cache' });
    if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);

    host.innerHTML = await response.text();

    if (selector === 'header.site-header') {
      enhanceHeaderNavigation(host);
    }

    if (selector === 'footer.site-footer') {
      enhanceFooter(host);
    }
  } catch (error) {
    console.error(`Failed to load partial: ${url}`, error);
    if (partialKey && INLINE_PARTIALS[partialKey]) {
      host.innerHTML = INLINE_PARTIALS[partialKey];
      if (partialKey === 'header') {
        enhanceHeaderNavigation(host);
      }
      if (partialKey === 'footer') {
        enhanceFooter(host);
      }
    }
  }
}

/**
 * Highlight the active navigation entry and wire up the mobile menu.
 */
function enhanceHeaderNavigation(headerEl) {
  const overrideKey = document.body?.dataset?.activeNav;
  const pageKey = (overrideKey || getPageKey()).toLowerCase();
  const links = headerEl.querySelectorAll('a[href]');

  links.forEach((link) => {
    const href = link.getAttribute('href');
    if (!href || href.startsWith('http')) return;
    const targetKey = getPageKey(href.split('#')[0]).toLowerCase();
    if (targetKey && targetKey === pageKey) {
      link.classList.add('active');
      link.setAttribute('aria-current', 'page');

      const dropdown = link.closest('.nav-dropdown');
      if (dropdown) {
        dropdown.classList.add('is-active');
        const toggle = dropdown.querySelector('.nav-dropdown-toggle');
        if (toggle) {
          toggle.classList.add('active');
        }
      }
    }
  });

  const dropdowns = Array.from(headerEl.querySelectorAll('.nav-dropdown'));

  const closeDropdowns = (except) => {
    dropdowns.forEach((dropdown) => {
      if (dropdown === except) return;
      dropdown.classList.remove('is-open');
      const toggle = dropdown.querySelector('.nav-dropdown-toggle');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  };

  dropdowns.forEach((dropdown) => {
    const toggle = dropdown.querySelector('.nav-dropdown-toggle');
    const menu = dropdown.querySelector('.nav-dropdown-menu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const isOpen = dropdown.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(isOpen));
      if (isOpen) {
        closeDropdowns(dropdown);
      }
    });

    dropdown.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        dropdown.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });

    menu.querySelectorAll('a').forEach((item) => {
      item.addEventListener('click', () => {
        dropdown.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  });

  document.addEventListener('click', (event) => {
    if (!headerEl.contains(event.target)) {
      closeDropdowns();
    }
  });

  const menuButton = headerEl.querySelector('#mobile-menu-button');
  const mobileMenu = document.getElementById('mobile-menu');

  if (!menuButton || !mobileMenu) return;

  const closeMenu = () => {
    mobileMenu.classList.remove('show');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.classList.remove('is-active');
    menuButton.setAttribute('aria-label', 'Open navigation menu');
  };

  menuButton.addEventListener('click', () => {
    const isOpen = mobileMenu.classList.toggle('show');
    menuButton.setAttribute('aria-expanded', String(isOpen));
    menuButton.classList.toggle('is-active', isOpen);
    menuButton.setAttribute(
      'aria-label',
      isOpen ? 'Close navigation menu' : 'Open navigation menu',
    );
  });

  mobileMenu.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => closeMenu());
  });

  mobileMenu.querySelectorAll('[data-close-mobile]').forEach((control) => {
    if (control.tagName === 'A') {
      return;
    }
    control.addEventListener('click', () => closeMenu());
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  setupGlobalSearch(headerEl);
  setupLanguageToggle(headerEl);
  setupStickyHeader(headerEl);
  setupWhatsAppCTA(headerEl);
}

function loadGoogleTranslateScript() {
  if (translateScriptRequested) {
    return;
  }
  translateScriptRequested = true;
  const script = document.createElement('script');
  script.src = 'https://translate.google.com/translate_a/element.js?cb=initDakshayaniTranslate';
  script.defer = true;
  script.onerror = () => {
    console.warn('Unable to load Google Translate script.');
  };
  document.head.appendChild(script);
}

function applyStoredLanguagePreference() {
  const stored = window.localStorage?.getItem?.(LANGUAGE_STORAGE_KEY);
  if (!stored) {
    return;
  }
  setGoogleLanguage(stored);
}

function setGoogleLanguage(language, attempt = 0) {
  const normalised = language === 'hi' ? 'hi' : 'en';
  const combo = document.querySelector('.goog-te-combo');

  if (!combo) {
    if (attempt < 10) {
      window.setTimeout(() => setGoogleLanguage(normalised, attempt + 1), 200);
    }
  } else if (combo.value !== normalised) {
    combo.value = normalised;
    const changeEvent = new Event('change', { bubbles: true });
    combo.dispatchEvent(changeEvent);
  }

  document.documentElement.setAttribute('lang', normalised);
  try {
    window.localStorage?.setItem?.(LANGUAGE_STORAGE_KEY, normalised);
  } catch (error) {
    console.warn('Unable to persist language preference', error);
  }
}

function setupLanguageToggle(rootEl = document) {
  if (!rootEl || typeof rootEl.querySelectorAll !== 'function') {
    rootEl = document;
  }

  const toggleButtons = Array.from(rootEl.querySelectorAll('[data-toggle-language]'));
  const pendingButtons = toggleButtons.filter((button) => button.dataset.languageToggleInitialised !== 'true');
  if (!pendingButtons.length) {
    return;
  }

  loadGoogleTranslateScript();

  pendingButtons.forEach((button) => {
    button.dataset.languageToggleInitialised = 'true';
    button.addEventListener('click', () => {
      translateReady
        .then(() => {
          const current = document.documentElement.getAttribute('lang') === 'hi' ? 'hi' : 'en';
          const next = current === 'hi' ? 'en' : 'hi';
          setGoogleLanguage(next);
        })
        .catch((error) => console.warn('Translator not initialised', error));
    });
  });

  translateReady.then(() => {
    applyStoredLanguagePreference();
  });
}

function setupWhatsAppCTA(headerEl) {
  const whatsappTriggers = headerEl.querySelectorAll('[data-open-whatsapp]');
  whatsappTriggers.forEach((button) => {
    button.addEventListener('click', () => {
      const url = 'https://wa.me/917070278178?text=Hello%20Dakshayani%20team%2C%20I%20need%20support%20with%20my%20solar%20project.';
      const popup = window.open(url, '_blank');
      if (!popup) {
        window.location.href = url;
      }
    });
  });
}

function setupFloatingSupport(rootEl = document) {
  if (!rootEl || typeof rootEl.querySelector !== 'function') {
    rootEl = document;
  }

  const container = rootEl.querySelector('[data-floating-actions]') || document.querySelector('[data-floating-actions]');
  if (!container) {
    return;
  }

  if (container.dataset.initialised === 'true') {
    return;
  }

  container.dataset.initialised = 'true';

  const toggle = container.querySelector('[data-floating-toggle]');
  const panel = container.querySelector('[data-floating-menu]');
  if (!toggle || !panel) {
    return;
  }

  const closeButtons = container.querySelectorAll('[data-floating-close]');
  const autoClosers = container.querySelectorAll('[data-close-floating]');
  let lastFocusedElement = null;
  let hideTimer = null;

  const setHiddenState = (hidden) => {
    if (hidden) {
      panel.setAttribute('aria-hidden', 'true');
      hideTimer = window.setTimeout(() => {
        panel.hidden = true;
      }, 220);
    } else {
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
      panel.hidden = false;
      panel.setAttribute('aria-hidden', 'false');
    }
  };

  const closePanel = ({ restoreFocus = true } = {}) => {
    if (!container.classList.contains('is-open')) {
      return;
    }
    container.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    setHiddenState(true);
    if (restoreFocus && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
    }
    if (!restoreFocus) {
      lastFocusedElement = null;
    }
  };

  const openPanel = () => {
    if (container.classList.contains('is-open')) {
      return;
    }
    lastFocusedElement = document.activeElement;
    setHiddenState(false);
    container.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => {
      panel.focus();
    });
  };

  toggle.addEventListener('click', () => {
    if (container.classList.contains('is-open')) {
      closePanel();
    } else {
      openPanel();
    }
  });

  closeButtons.forEach((button) => {
    button.addEventListener('click', () => closePanel());
  });

  autoClosers.forEach((button) => {
    button.addEventListener('click', () => closePanel({ restoreFocus: false }));
  });

  document.addEventListener('click', (event) => {
    if (!container.contains(event.target)) {
      closePanel({ restoreFocus: false });
    }
  });

  container.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePanel();
    }
  });
}

function enhanceFooter(footerEl) {
  setupFloatingSupport(footerEl);
  setupLanguageToggle(footerEl);
  setupGlobalSearch(footerEl);
}

function setupStickyHeader(headerEl) {
  const root = headerEl.closest('.global-header') || headerEl;
  if (!root) {
    return;
  }
  const handleScroll = () => {
    if (window.scrollY > 24) {
      root.classList.add('is-sticky');
    } else {
      root.classList.remove('is-sticky');
    }
  };
  handleScroll();
  window.addEventListener('scroll', handleScroll, { passive: true });
}

function setupGlobalSearch(rootEl = document) {
  if (!rootEl || typeof rootEl.querySelectorAll !== 'function') {
    rootEl = document;
  }

  const overlay = document.querySelector('[data-site-search]');
  const form = overlay ? overlay.querySelector('[data-site-search-form]') : null;
  const openers = Array.from(rootEl.querySelectorAll('[data-open-search]'));
  const closers = overlay ? Array.from(overlay.querySelectorAll('[data-close-search]')) : [];
  const resultsHost = overlay ? overlay.querySelector('[data-site-search-results]') : null;
  if (!overlay || !form || !resultsHost) {
    return;
  }

  const input = form.querySelector('input[name="q"]');
  const segmentSelect = form.querySelector('select[name="segment"]');

  const closeSearch = () => {
    overlay.hidden = true;
    document.body.classList.remove('site-search-open');
    form.reset();
    resultsHost.innerHTML = '<p class="site-search-empty">Type to explore Dakshayani insights, project learnings, and FAQs.</p>';
  };

  const openSearch = () => {
    overlay.hidden = false;
    document.body.classList.add('site-search-open');
    setTimeout(() => {
      input?.focus();
    }, 60);
  };

  openers.forEach((button) => {
    if (button.dataset.siteSearchOpenerInitialised === 'true') {
      return;
    }
    button.dataset.siteSearchOpenerInitialised = 'true';
    button.addEventListener('click', () => openSearch());
  });

  closers.forEach((button) => {
    if (button.dataset.siteSearchCloserInitialised === 'true') {
      return;
    }
    button.dataset.siteSearchCloserInitialised = 'true';
    button.addEventListener('click', () => closeSearch());
  });

  const renderResults = (items = [], query = '') => {
    resultsHost.innerHTML = '';

    if (!items.length) {
      const empty = document.createElement('p');
      empty.className = 'site-search-empty';
      empty.textContent = query
        ? `No results for "${query}". Try a different keyword.`
        : 'No results found. Try a different keyword.';
      resultsHost.appendChild(empty);
      return;
    }

    const grouped = items.reduce((acc, item) => {
      const type = (item.group || item.type || 'Other').toString();
      const key = type.trim() || 'Other';
      if (!acc.has(key)) {
        acc.set(key, []);
      }
      acc.get(key).push(item);
      return acc;
    }, new Map());

    const quickNav = document.createElement('nav');
    quickNav.className = 'site-search-quick-nav';
    const quickNavList = document.createElement('ul');
    quickNavList.className = 'site-search-quick-nav__list';

    const container = document.createElement('div');
    container.className = 'site-search-groups';

    let index = 0;
    grouped.forEach((groupItems, key) => {
      const slug = `search-group-${key.toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'group'}-${index}`;
      const navItem = document.createElement('li');
      const navLink = document.createElement('a');
      navLink.href = `#${slug}`;
      navLink.textContent = `${key} (${groupItems.length})`;
      navLink.className = 'site-search-quick-nav__link';
      navLink.addEventListener('click', (event) => {
        event.preventDefault();
        const target = resultsHost.querySelector(`#${slug}`);
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
      navItem.appendChild(navLink);
      quickNavList.appendChild(navItem);

      const section = document.createElement('section');
      section.className = 'site-search-group';
      section.id = slug;
      const heading = document.createElement('h3');
      heading.textContent = key;
      heading.className = 'site-search-group__title';
      section.appendChild(heading);

      const list = document.createElement('ul');
      list.className = 'site-search-list';

      groupItems.forEach((item) => {
        const entry = document.createElement('li');
        entry.className = 'site-search-item';

        const link = document.createElement('a');
        link.className = 'site-search-link';
        link.href = item.url || '#';

        const type = document.createElement('span');
        type.className = 'site-search-type';
        type.textContent = item.type || key;
        link.appendChild(type);

        const title = document.createElement('span');
        title.className = 'site-search-title';
        title.textContent = item.title || 'Untitled result';
        link.appendChild(title);

        if (item.excerpt) {
          const excerpt = document.createElement('span');
          excerpt.className = 'site-search-excerpt';
          excerpt.textContent = item.excerpt;
          link.appendChild(excerpt);
        }

        if (item.tags && item.tags.length) {
          const tags = document.createElement('span');
          tags.className = 'site-search-tags';
          tags.textContent = item.tags.join(' • ');
          link.appendChild(tags);
        }

        entry.appendChild(link);
        list.appendChild(entry);
      });

      section.appendChild(list);
      container.appendChild(section);
      index += 1;
    });

    quickNav.appendChild(quickNavList);
    resultsHost.appendChild(quickNav);
    resultsHost.appendChild(container);
  };

  const setLoading = (state) => {
    if (state) {
      resultsHost.innerHTML = '<p class="site-search-loading">Looking up Dakshayani Knowledge Hub…</p>';
    }
  };

  if (!setupGlobalSearch.coreInitialised) {
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        closeSearch();
      }
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !overlay.hidden) {
        closeSearch();
      }
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const query = String(input?.value || '').trim();
      const segment = String(segmentSelect?.value || '').trim();
      if (!query) {
        renderResults([], query);
        return;
      }

      const params = new URLSearchParams({ q: query });
      if (segment) {
        params.append('segment', segment);
      }
      params.append('limit', '25');

      setLoading(true);

      fetch(`${SITE_SEARCH_ENDPOINT}?${params.toString()}`)
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Search failed with status ${response.status}`);
          }
          return response.json();
        })
        .then((data) => {
          renderResults(data.results || [], query);
        })
        .catch((error) => {
          console.error('Search request failed', error);
          resultsHost.innerHTML = '<p class="site-search-empty">Unable to load search results at the moment.</p>';
        });
    });

    setupGlobalSearch.coreInitialised = true;
  }
}

/**
 * Update the text content of any element tagged with `data-current-year`.
 */
function stampCurrentYear() {
  const year = String(new Date().getFullYear());
  document.querySelectorAll('[data-current-year]').forEach((node) => {
    node.textContent = year;
  });
}

function ensureFestivalBanner() {
  let banner = document.querySelector('[data-festival-banner]');
  if (!banner) {
    banner = document.createElement('div');
    banner.className = 'festival-banner';
    banner.setAttribute('data-festival-banner', '');
    banner.hidden = true;
    document.body?.insertBefore(banner, document.body.firstChild || null);
  }
  return banner;
}

function applyFestivalTheme(themeKey) {
  const theme = FESTIVAL_THEMES[themeKey] || FESTIVAL_THEMES.default;
  const root = document.documentElement;
  const banner = ensureFestivalBanner();

  const encodedHero = theme.heroImage ? `url('${encodeURI(theme.heroImage)}')` : '';

  if (theme.primary) root.style.setProperty('--primary-main', theme.primary); else root.style.removeProperty('--primary-main');
  if (theme.dark) root.style.setProperty('--primary-dark', theme.dark); else root.style.removeProperty('--primary-dark');
  if (theme.overlay) root.style.setProperty('--hero-overlay-color', theme.overlay); else root.style.removeProperty('--hero-overlay-color');
  if (theme.bannerBg) root.style.setProperty('--festival-banner-bg', theme.bannerBg); else root.style.removeProperty('--festival-banner-bg');
  if (theme.bannerColor) root.style.setProperty('--festival-banner-color', theme.bannerColor); else root.style.removeProperty('--festival-banner-color');

  if (encodedHero) {
    root.style.setProperty('--hero-image-url', encodedHero);
  } else {
    root.style.removeProperty('--hero-image-url');
  }

  if (theme.banner) {
    banner.textContent = theme.banner;
    banner.hidden = false;
  } else {
    banner.textContent = '';
    banner.hidden = true;
  }

  if (themeKey === 'default') {
    delete document.body.dataset.festivalTheme;
  } else {
    document.body.dataset.festivalTheme = themeKey;
  }
}
function applyThemeTokens(theme = {}) {
  const root = document.documentElement;
  const palette = { ...DEFAULT_THEME, ...(theme || {}) };
  const tokens = window.DakshayaniThemeTokens || {};
  const buttonTokens = tokens.buttons || {};
  const cardTokens = tokens.cards || {};
  const resolvedButtonStyle = buttonTokens[palette.button_style]
    ? palette.button_style
    : (buttonTokens.rounded ? 'rounded' : Object.keys(buttonTokens)[0]);
  const resolvedCardStyle = cardTokens[palette.card_style]
    ? palette.card_style
    : (cardTokens.soft ? 'soft' : Object.keys(cardTokens)[0]);
  const buttonToken = buttonTokens[resolvedButtonStyle] || buttonTokens.rounded || {};
  const cardToken = cardTokens[resolvedCardStyle] || cardTokens.soft || {};

  const buttonPrimaryText = buttonToken.primary_bg === 'transparent' ? palette.primary_color : '#ffffff';
  const buttonSecondaryText = buttonToken.secondary_bg === 'transparent' ? palette.secondary_color : '#ffffff';

  root.style.setProperty('--primary-main', palette.primary_color);
  root.style.setProperty('--secondary-main', palette.secondary_color);
  root.style.setProperty('--accent-blue', palette.accent_color);
  root.style.setProperty('--accent-strong', palette.accent_color);
  root.style.setProperty('--btn-radius', buttonToken.radius || '0.85rem');
  root.style.setProperty('--btn-primary-shadow', buttonToken.primary_shadow || '0 12px 24px rgba(51, 51, 51, 0.25)');
  root.style.setProperty('--btn-primary-hover-shadow', buttonToken.primary_hover_shadow || '0 16px 30px rgba(51, 51, 51, 0.35)');
  root.style.setProperty('--btn-secondary-shadow', buttonToken.secondary_shadow || '0 12px 26px rgba(0, 55, 74, 0.25)');
  root.style.setProperty('--btn-primary-border', buttonToken.primary_border || '1px solid transparent');
  root.style.setProperty('--btn-secondary-border', buttonToken.secondary_border || '1px solid transparent');
  root.style.setProperty('--btn-primary-bg', buttonToken.primary_bg || palette.primary_color);
  root.style.setProperty('--btn-primary-hover-bg', buttonToken.primary_hover_bg || palette.accent_color);
  root.style.setProperty('--btn-secondary-bg', buttonToken.secondary_bg || palette.secondary_color);
  root.style.setProperty('--btn-secondary-hover-bg', buttonToken.secondary_hover_bg || palette.secondary_color);
  root.style.setProperty('--btn-base-border', buttonToken.base_border || '1px solid transparent');
  root.style.setProperty('--btn-primary-text', buttonPrimaryText);
  root.style.setProperty('--btn-secondary-text', buttonSecondaryText);
  root.style.setProperty('--card-shadow', cardToken.shadow || '0 12px 32px rgba(15, 23, 42, 0.08)');
  root.style.setProperty('--card-border', cardToken.border || '1px solid rgba(148, 163, 184, 0.16)');
  root.style.setProperty('--card-surface', cardToken.surface || '#ffffff');
  root.style.setProperty('--radius-card', cardToken.radius || '1.25rem');
}

function toggleHidden(element, shouldHide) {
  if (!element) return;
  element.hidden = Boolean(shouldHide);
}

const FONT_SIZE_SCALE = {
  small: '0.75rem',
  normal: '0.8rem',
  large: '0.95rem',
};

const FONT_WEIGHT_SCALE = {
  normal: '400',
  medium: '500',
  semibold: '600',
  bold: '700',
};

function normalizeHexColor(value) {
  const raw = typeof value === 'string' ? value.trim() : '';
  if (!raw) return '';
  const withHash = raw.startsWith('#') ? raw : `#${raw}`;
  return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(withHash) ? withHash.toUpperCase() : '';
}

function resolveFontSize(key) {
  return FONT_SIZE_SCALE[key] || '';
}

function resolveFontWeight(key) {
  return FONT_WEIGHT_SCALE[key] || '';
}

function refreshSubheaderMarquee() {
  const marquee = document.querySelector('[data-subheader-marquee]');
  const marqueeInner = marquee?.querySelector('[data-marquee-inner]');
  const track = marquee?.querySelector('[data-marquee-track]');
  const cloneTrack = marquee?.querySelector('[data-marquee-track-clone]');
  const subheader = document.querySelector('.site-subheader');

  if (!marquee || !marqueeInner || !track || !cloneTrack) {
    return;
  }

  const visibleBadges = Array.from(track.querySelectorAll('.nav-theme-badge')).filter((badge) => !badge.hidden);
  const hasContent = visibleBadges.length > 0;

  if (subheader) {
    toggleHidden(subheader, !hasContent);
    subheader.setAttribute('aria-hidden', hasContent ? 'false' : 'true');
  }

  cloneTrack.innerHTML = '';
  marqueeInner.style.removeProperty('--marquee-track-width');
  marqueeInner.style.removeProperty('--marquee-duration');
  marquee.classList.remove('is-animating');

  if (!hasContent) {
    return;
  }

  cloneTrack.innerHTML = track.innerHTML;
  cloneTrack.setAttribute('aria-hidden', 'true');

  const trackWidth = track.getBoundingClientRect().width;
  if (!trackWidth) {
    return;
  }

  const durationSeconds = Math.min(30, Math.max(12, trackWidth / 40));
  marqueeInner.style.setProperty('--marquee-track-width', `${trackWidth}px`);
  marqueeInner.style.setProperty('--marquee-duration', `${durationSeconds}s`);
  marquee.classList.add('is-animating');
}

function applyGlobalContent(global = {}) {
  const taglines = document.querySelectorAll('[data-site-tagline]');
  const callouts = document.querySelectorAll('[data-header-callout]');
  const subheader = document.querySelector('.site-subheader');
  const taglineText = typeof global.site_tagline === 'string' ? global.site_tagline : '';
  const calloutText = typeof global.header_callout === 'string' ? global.header_callout : '';
  const taglineColor = normalizeHexColor(global.tagline_color);
  const calloutColor = normalizeHexColor(global.callout_color);
  const taglineFontSize = resolveFontSize(global.tagline_font_size);
  const calloutFontSize = resolveFontSize(global.callout_font_size);
  const taglineFontWeight = resolveFontWeight(global.tagline_font_weight);
  const calloutFontWeight = resolveFontWeight(global.callout_font_weight);
  const subheaderBg = normalizeHexColor(global.subheader_bg_color);

  taglines.forEach((node) => {
    node.textContent = taglineText;
    toggleHidden(node, !taglineText);
    node.style.color = taglineColor || '';
    node.style.fontSize = taglineFontSize || '';
    node.style.fontWeight = taglineFontWeight || '';
  });
  callouts.forEach((node) => {
    node.textContent = calloutText;
    toggleHidden(node, !calloutText);
    node.style.color = calloutColor || '';
    node.style.fontSize = calloutFontSize || '';
    node.style.fontWeight = calloutFontWeight || '';
  });

  if (subheader) {
    subheader.style.background = subheaderBg || '';
  }

  refreshSubheaderMarquee();
}

function renderHeroGallery(items) {
  const container = document.querySelector('[data-hero-gallery]');
  if (!container) return;

  container.innerHTML = '';

  if (!Array.isArray(items) || !items.length) {
    toggleHidden(container, true);
    return;
  }

  toggleHidden(container, false);

  const galleryItems = items.slice(0, 3);
  galleryItems.slice(0, 3).forEach((item) => {
    const figure = document.createElement('figure');
    const img = document.createElement('img');
    img.src = item.image;
    img.alt = item.caption;
    img.loading = 'lazy';

    const caption = document.createElement('figcaption');
    caption.textContent = item.caption;

    figure.appendChild(img);
    figure.appendChild(caption);
    container.appendChild(figure);
  });
}

function applyHeroSettings(hero = {}, theme = {}) {
  const heroSection = document.querySelector('[data-hero-section]');
  const kicker = document.querySelector('[data-hero-kicker]');
  const title = document.querySelector('[data-hero-title]');
  const subtitle = document.querySelector('[data-hero-subtitle]');
  const mainImage = document.querySelector('[data-hero-main-image]');
  const mainCaption = document.querySelector('[data-hero-main-caption]');
  const primaryBtn = document.querySelector('[data-hero-primary]');
  const primaryBtnText = document.querySelector('[data-hero-primary-text]');
  const secondaryBtn = document.querySelector('[data-hero-secondary]');
  const secondaryBtnText = document.querySelector('[data-hero-secondary-text]');
  const announcement = document.querySelector('[data-hero-announcement]');
  const announcementBadge = document.querySelector('[data-hero-announcement-badge]');
  const announcementText = document.querySelector('[data-hero-announcement-text]');
  const root = document.documentElement;
  const activeTheme = document.body?.dataset?.festivalTheme || 'default';
  const resolvedImage = (typeof hero.primary_image === 'string' ? hero.primary_image : hero.primaryImage) || '';
  const primaryImage = resolvedImage.trim() || DEFAULT_HERO_IMAGE;
  const primaryAlt = typeof hero.primaryAlt === 'string' ? hero.primaryAlt : '';

  if (kicker) {
    const kickerText = typeof hero.kicker === 'string' ? hero.kicker.trim() : '';
    kicker.textContent = kickerText;
    toggleHidden(kicker, !kickerText);
  }
  if (title) title.textContent = typeof hero.title === 'string' ? hero.title : '';
  if (subtitle) subtitle.textContent = typeof hero.subtitle === 'string' ? hero.subtitle : '';
  if (mainImage) {
    mainImage.src = primaryImage;
    mainImage.alt = primaryAlt || mainImage.alt || 'Dakshayani Enterprises';
  }
  if (mainCaption) mainCaption.textContent = typeof hero.primary_caption === 'string' ? hero.primary_caption : (typeof hero.primaryCaption === 'string' ? hero.primaryCaption : '');

  if (primaryBtn) {
    if (typeof hero.primary_button_link === 'string') {
      primaryBtn.href = hero.primary_button_link;
    }
    if (primaryBtnText) primaryBtnText.textContent = typeof hero.primary_button_text === 'string' ? hero.primary_button_text : '';
    toggleHidden(primaryBtn, !hero.primary_button_text);
  }

  if (secondaryBtn) {
    if (typeof hero.secondary_button_link === 'string') secondaryBtn.href = hero.secondary_button_link;
    if (secondaryBtnText) secondaryBtnText.textContent = typeof hero.secondary_button_text === 'string' ? hero.secondary_button_text : '';
    toggleHidden(secondaryBtn, !hero.secondary_button_text);
  }

  if (announcement) {
    const badge = typeof hero.announcement_badge === 'string' ? hero.announcement_badge : '';
    const text = typeof hero.announcement_text === 'string' ? hero.announcement_text : '';
    if (announcementBadge) {
      announcementBadge.textContent = badge;
      toggleHidden(announcementBadge, !badge);
    }
    if (announcementText) {
      announcementText.textContent = text;
      toggleHidden(announcementText, !text);
    }
    toggleHidden(announcement, !badge && !text);
  }

  if (primaryImage && activeTheme === 'default') {
    root.style.setProperty('--hero-image-url', `url('${primaryImage}')`);
  }

  renderHeroGallery(Array.isArray(hero.gallery) ? hero.gallery : []);

  if (heroSection) {
    heroSection.hidden = false;
  }
}

function renderCtaStrip(sections = {}) {
  const strip = document.querySelector('[data-cta-strip]');
  if (!strip) return;

  const title = document.querySelector('[data-cta-strip-title]');
  const text = document.querySelector('[data-cta-strip-text]');
  const button = document.querySelector('[data-cta-strip-button]');
  const buttonText = document.querySelector('[data-cta-strip-button-text]');

  const headingCopy = typeof sections.cta_strip_title === 'string' ? sections.cta_strip_title : '';
  const bodyCopy = typeof sections.cta_strip_text === 'string' ? sections.cta_strip_text : '';
  const ctaCopy = typeof sections.cta_strip_cta_text === 'string' ? sections.cta_strip_cta_text : '';
  const ctaLink = typeof sections.cta_strip_cta_link === 'string' ? sections.cta_strip_cta_link : '';

  const hasContent = headingCopy || bodyCopy || ctaCopy;
  toggleHidden(strip, !hasContent);
  if (!hasContent) return;

  if (title) {
    title.textContent = headingCopy;
    toggleHidden(title, !headingCopy);
  }
  if (text) {
    text.textContent = bodyCopy;
    toggleHidden(text, !bodyCopy);
  }
  if (button) {
    button.href = ctaLink || '#';
    toggleHidden(button, !ctaCopy);
  }
  if (buttonText) {
    buttonText.textContent = ctaCopy;
  }
}

function renderNewestInstalls(installs) {
  const section = document.querySelector('[data-installs-section]');
  const grid = document.querySelector('[data-installs-grid]');
  if (!grid) return;

  const items = Array.isArray(installs) && installs.length ? installs : [];
  grid.innerHTML = '';

  if (!items.length) {
    if (section) section.hidden = true;
    return;
  }

  if (section) section.hidden = false;

  items.slice(0, 4).forEach((install) => {
    const card = document.createElement('article');
    card.className = 'install-card';

    const media = document.createElement('div');
    media.className = 'install-media';
    const img = document.createElement('img');
    img.src = install.image;
    img.alt = install.title;
    img.loading = 'lazy';
    media.appendChild(img);

    const body = document.createElement('div');
    body.className = 'install-body';

    const meta = document.createElement('p');
    meta.className = 'install-meta';
    meta.textContent = `${install.capacity} • ${install.completedOn}`;

    const title = document.createElement('h3');
    title.textContent = install.title;

    const location = document.createElement('p');
    location.className = 'install-location';
    const locationIcon = document.createElement('i');
    locationIcon.className = 'fa-solid fa-location-dot';
    location.appendChild(locationIcon);
    location.append(` ${install.location}`);

    const summary = document.createElement('p');
    summary.className = 'install-summary';
    summary.textContent = install.summary;

    body.append(meta, title, location, summary);
    card.append(media, body);
    grid.appendChild(card);
  });
}
function renderOffers(sections = {}, offers = [], theme = {}) {
  const title = document.querySelector('[data-offers-title]');
  const subtitle = document.querySelector('[data-offers-subtitle]');
  const list = document.querySelector('[data-offers-list]');
  const empty = document.querySelector('[data-offers-empty]');

  if (title) title.textContent = sections.seasonal_offer_title || '';
  if (subtitle) subtitle.textContent = sections.seasonal_offer_text || '';
  if (!list) return;

  list.innerHTML = '';
  const items = Array.isArray(offers) ? offers : [];

  if (!items.length) {
    if (empty) empty.hidden = false;
    return;
  }
  if (empty) empty.hidden = true;

  items.forEach((offer) => {
    const card = document.createElement('div');
    card.className = 'offer-card';

    const header = document.createElement('div');
    header.className = 'offer-card-header';
    const heading = document.createElement('h3');
    heading.textContent = offer.label || '';
    header.appendChild(heading);

    const description = document.createElement('p');
    description.textContent = offer.description || '';

    card.append(header, description);

    if (offer.valid_till) {
      const validity = document.createElement('p');
      validity.className = 'text-xs text-gray-500';
      validity.textContent = `Valid till ${offer.valid_till}`;
      card.appendChild(validity);
    }

    if (offer.cta_text) {
      const cta = document.createElement('a');
      cta.href = offer.cta_link || '/contact';
      cta.className = 'btn btn-primary';
      cta.textContent = offer.cta_text;
      card.appendChild(cta);
    }

    list.appendChild(card);
  });
}

function renderTestimonials(sections = {}, testimonials = [], theme = {}) {
  const title = document.querySelector('[data-testimonial-title]');
  const subtitle = document.querySelector('[data-testimonial-subtitle]');
  const list = document.querySelector('[data-testimonial-list]');
  const empty = document.querySelector('[data-testimonial-empty]');

  if (title) title.textContent = sections.what_our_customers_say_title || '';
  if (subtitle) subtitle.textContent = sections.what_our_customers_say_subtitle || '';
  if (!list) return;

  list.innerHTML = '';
  const items = Array.isArray(testimonials) ? testimonials : [];

  if (!items.length) {
    if (empty) empty.hidden = false;
    return;
  }
  if (empty) empty.hidden = true;

  items.forEach((testimonial) => {
    const card = document.createElement('div');
    card.className = 'testimonial-card';

    const quote = document.createElement('p');
    quote.className = 'quote-text';
    quote.textContent = testimonial.message ? `"${testimonial.message}"` : '';

    const author = document.createElement('div');
    author.className = 'author';

    const info = document.createElement('div');
    info.className = 'author-info';

    const name = document.createElement('div');
    name.className = 'author-name';
    name.textContent = testimonial.name || '';
    if (testimonial.location) {
      const location = document.createElement('span');
      location.className = 'text-xs text-gray-500';
      location.textContent = `· ${testimonial.location}`;
      name.appendChild(location);
    }

    info.appendChild(name);

    if (testimonial.system_size || testimonial.type) {
      const titleEl = document.createElement('div');
      titleEl.className = 'author-title';
      titleEl.textContent = `${testimonial.system_size || ''} ${testimonial.type || ''}`.trim();
      info.appendChild(titleEl);
    }

    author.appendChild(info);
    card.append(quote, author);
    list.appendChild(card);
  });
}

function applySiteContent(detail = {}) {
  const theme = detail.theme || {};
  applyThemeTokens(theme);
  applyGlobalContent(detail.global || {});
  applyHeroSettings(detail.hero || {}, theme);
  renderOffers(detail.sections || {}, detail.offers || detail.seasonal_offers || [], theme);
  renderTestimonials(detail.sections || {}, detail.testimonials || [], theme);
  renderCtaStrip(detail.sections || {});
}

function setupLeadForm() {
  const form = document.getElementById('homepage-lead-form');
  if (!form) {
    return;
  }

  const alertEl = document.getElementById('homepage-lead-form-alert');
  const submitButton = form.querySelector('button[type="submit"]');

  const setAlert = (message, variant) => {
    if (!alertEl) {
      return;
    }

    alertEl.textContent = message || '';
    alertEl.classList.remove('is-success', 'is-error', 'is-visible');

    if (message) {
      alertEl.classList.add('is-visible');
      if (variant === 'success') {
        alertEl.classList.add('is-success');
      } else if (variant === 'error') {
        alertEl.classList.add('is-error');
      }
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.setAttribute('aria-busy', 'true');
    }

    const formData = new FormData(form);
    const payload = {
      name: String(formData.get('name') || '').trim(),
      phone: String(formData.get('phone') || '').trim(),
      city: String(formData.get('city') || '').trim(),
      projectType: String(formData.get('projectType') || '').trim(),
      leadSource: String(formData.get('leadSource') || 'Website Homepage').trim(),
    };

    if (!payload.name || !payload.phone || !payload.city || !payload.projectType) {
      setAlert('Please complete every field before submitting the form.', 'error');
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
      }
      return;
    }

    const messageLines = [
      'Hello Dakshayani Enterprises!',
      `Name: ${payload.name}`,
      `Phone: ${payload.phone}`,
      `City: ${payload.city}`,
      `Project Type: ${payload.projectType}`,
      `Source: ${payload.leadSource}`,
    ];
    const whatsappUrl = `https://wa.me/917070278178?text=${encodeURIComponent(messageLines.join('\n'))}`;

    const popup = window.open(whatsappUrl, '_blank');
    if (!popup) {
      window.location.href = whatsappUrl;
    }

    setAlert('Opening WhatsApp… please send us your message there!', 'success');
    form.reset();

    if (submitButton) {
      submitButton.disabled = false;
      submitButton.removeAttribute('aria-busy');
    }
  });
}

window.initDakshayaniTranslate = function initDakshayaniTranslate() {
  try {
    /* global google */
    if (window.google && google.translate) {
      new google.translate.TranslateElement(
        {
          pageLanguage: 'en',
          includedLanguages: 'en,hi',
          autoDisplay: false,
          layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
        },
        'google_translate_element'
      );
    }
  } catch (error) {
    console.warn('Google Translate initialisation failed', error);
  }
  if (typeof translateReadyResolver === 'function') {
    translateReadyResolver();
    translateReadyResolver = null;
  }
  applyStoredLanguagePreference();
};

// Wait for the document to be interactive before injecting content.
document.addEventListener('DOMContentLoaded', () => {
  const headerPromise = injectPartial('header.site-header', PARTIALS.header);
  const footerPromise = injectPartial('footer.site-footer', PARTIALS.footer);
  const contentPromise = ensureSiteContentReady();
  stampCurrentYear();
  setupLeadForm();
  refreshSubheaderMarquee();
  contentPromise.then((detail) => {
    if (detail) {
      latestSiteContent = detail;
      applySiteContent(detail);
      syncThemeBadges(detail);
    }
  });
  Promise.all([headerPromise, contentPromise]).then(([, detail]) => {
    if (detail) {
      applyGlobalContent(detail.global || {});
      syncThemeBadges(detail);
    }
  });
  Promise.resolve(footerPromise).catch(() => {});
  setupScrollAnimations();
});

function setupScrollAnimations() {
  const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px',
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('animate-fade-up');
        entry.target.style.opacity = ''; // Remove inline opacity so animation takes over
        observer.unobserve(entry.target);
      }
    });
  }, observerOptions);

  const selectors = [
    '.hero-title',
    '.hero-sub',
    '.hero-actions',
    '.hero-media',
    '.section h2',
    '.section p.sub',
    '.capability-card',
    '.trust-card',
    '.solution-card',
    '.journey-step',
    '.impact-stats .card',
    '.testimonial-card',
    '.contact-card',
    '.form-card',
  ];

  document.querySelectorAll(selectors.join(',')).forEach((el) => {
    el.style.opacity = '0';
    el.style.willChange = 'opacity, transform';
    observer.observe(el);
  });
}
