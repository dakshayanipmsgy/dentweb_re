(function () {
  const ALLOWED_BUTTON_STYLES = ['rounded', 'pill', 'outline', 'sharp', 'solid-heavy'];
  const ALLOWED_CARD_STYLES = ['soft', 'strong', 'border', 'flat'];
  const ALLOWED_FONT_SIZES = ['small', 'normal', 'large'];
  const ALLOWED_FONT_WEIGHTS = ['normal', 'medium', 'semibold', 'bold'];
  const DEFAULT_HERO_IMAGE = 'images/hero/hero.png';
  const DEFAULT_THEME = {
    primary_color: '#0f766e',
    secondary_color: '#f59e0b',
    accent_color: '#0ea5e9',
    button_style: 'rounded',
    card_style: 'soft',
  };
  const DEFAULT_GLOBAL_STYLE = {
    tagline_color: '',
    tagline_font_size: 'normal',
    tagline_font_weight: 'semibold',
    callout_color: '',
    callout_font_size: 'normal',
    callout_font_weight: 'semibold',
    subheader_bg_color: '',
  };

  const dataScript = document.getElementById('site-settings-json');
  let payload = {};

  if (dataScript && dataScript.textContent) {
    try {
      payload = JSON.parse(dataScript.textContent);
    } catch (error) {
      console.warn('Unable to parse embedded site settings', error);
      payload = {};
    }
  }

  const asString = (value) => (typeof value === 'string' ? value : '');
  const normalizeHex = (value, fallback) => {
    const raw = asString(value).trim();
    if (!raw) return fallback;
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(withHash) ? withHash.toUpperCase() : fallback;
  };

  const normalizeTheme = (theme = {}) => {
    const primary = normalizeHex(theme.primary_color ?? theme.primaryColor, DEFAULT_THEME.primary_color);
    const secondary = normalizeHex(theme.secondary_color ?? theme.secondaryColor, DEFAULT_THEME.secondary_color);
    const accent = normalizeHex(theme.accent_color ?? theme.accentColor, DEFAULT_THEME.accent_color);
    const buttonStyle = ALLOWED_BUTTON_STYLES.includes(theme.button_style) ? theme.button_style : DEFAULT_THEME.button_style;
    const cardStyle = ALLOWED_CARD_STYLES.includes(theme.card_style) ? theme.card_style : DEFAULT_THEME.card_style;

    return {
      ...theme,
      primary_color: primary,
      secondary_color: secondary,
      accent_color: accent,
      button_style: buttonStyle,
      card_style: cardStyle,
    };
  };

  const normalizeHero = (hero = {}) => ({
    ...hero,
    kicker: asString(hero.kicker),
    title: asString(hero.title),
    subtitle: asString(hero.subtitle),
    primary_image: asString(hero.primary_image ?? hero.primaryImage),
    primary_caption: asString(hero.primary_caption ?? hero.primaryCaption),
    primary_button_text: asString(hero.primary_button_text ?? hero.primaryButtonText),
    primary_button_link: asString(hero.primary_button_link ?? hero.primaryButtonLink),
    secondary_button_text: asString(hero.secondary_button_text ?? hero.secondaryButtonText),
    secondary_button_link: asString(hero.secondary_button_link ?? hero.secondaryButtonLink),
    announcement_badge: asString(hero.announcement_badge ?? hero.announcementBadge),
    announcement_text: asString(hero.announcement_text ?? hero.announcementText),
  });

  const normalizeSections = (sections = {}) => ({
    ...sections,
    what_our_customers_say_title: asString(sections.what_our_customers_say_title ?? sections.testimonialTitle),
    what_our_customers_say_subtitle: asString(sections.what_our_customers_say_subtitle ?? sections.testimonialSubtitle),
    seasonal_offer_title: asString(sections.seasonal_offer_title ?? sections.seasonalOfferTitle),
    seasonal_offer_text: asString(sections.seasonal_offer_text ?? sections.seasonalOfferText),
    cta_strip_title: asString(sections.cta_strip_title ?? sections.ctaStripTitle),
    cta_strip_text: asString(sections.cta_strip_text ?? sections.ctaStripText),
    cta_strip_cta_text: asString(sections.cta_strip_cta_text ?? sections.ctaStripCtaText),
    cta_strip_cta_link: asString(sections.cta_strip_cta_link ?? sections.ctaStripCtaLink),
  });

  const normalizeGlobal = (global = {}) => ({
    ...DEFAULT_GLOBAL_STYLE,
    site_tagline: asString(global.site_tagline),
    header_callout: asString(global.header_callout),
    tagline_color: normalizeHex(global.tagline_color, DEFAULT_GLOBAL_STYLE.tagline_color),
    tagline_font_size: ALLOWED_FONT_SIZES.includes(global.tagline_font_size) ? global.tagline_font_size : DEFAULT_GLOBAL_STYLE.tagline_font_size,
    tagline_font_weight: ALLOWED_FONT_WEIGHTS.includes(global.tagline_font_weight) ? global.tagline_font_weight : DEFAULT_GLOBAL_STYLE.tagline_font_weight,
    callout_color: normalizeHex(global.callout_color, DEFAULT_GLOBAL_STYLE.callout_color),
    callout_font_size: ALLOWED_FONT_SIZES.includes(global.callout_font_size) ? global.callout_font_size : DEFAULT_GLOBAL_STYLE.callout_font_size,
    callout_font_weight: ALLOWED_FONT_WEIGHTS.includes(global.callout_font_weight) ? global.callout_font_weight : DEFAULT_GLOBAL_STYLE.callout_font_weight,
    subheader_bg_color: normalizeHex(global.subheader_bg_color, DEFAULT_GLOBAL_STYLE.subheader_bg_color),
  });
  const normalizePayload = (raw = {}) => {
    const normalizedHero = normalizeHero(raw.hero || {});
    const normalizedTheme = normalizeTheme(raw.theme || {});
    const normalizedSections = normalizeSections((raw.sections && typeof raw.sections === 'object') ? raw.sections : {});
    const normalizedOffers = Array.isArray(raw.offers) ? raw.offers : (Array.isArray(raw.seasonal_offers) ? raw.seasonal_offers : []);
    const normalizedTestimonials = Array.isArray(raw.testimonials) ? raw.testimonials : [];

    return {
      theme: normalizedTheme,
      hero: {
        ...normalizedHero,
        primary_image: normalizedHero.primary_image || DEFAULT_HERO_IMAGE,
      },
      sections: normalizedSections,
      offers: normalizedOffers,
      seasonal_offers: normalizedOffers,
      testimonials: normalizedTestimonials,
      global: normalizeGlobal(raw.global || {}),
    };
  };

  const embeddedContent = normalizePayload(payload);

  const isEqual = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  const fetchLatestContent = async () => {
    try {
      const response = await fetch('/api/public/site-content/', { cache: 'no-store' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const json = await response.json();
      return normalizePayload(json || {});
    } catch (error) {
      console.warn('Falling back to embedded site settings', error);
      return embeddedContent;
    }
  };

  const promise = fetchLatestContent();
  window.DakshayaniSiteContent = promise;

  // Paint immediately using embedded content to avoid blank states.
  document.dispatchEvent(new CustomEvent('dakshayani:site-content-ready', { detail: embeddedContent }));

  promise
    .then((ready) => {
      if (!isEqual(ready, embeddedContent)) {
        document.dispatchEvent(new CustomEvent('dakshayani:site-content-ready', { detail: ready }));
      }
    })
    .catch(() => {
      document.dispatchEvent(new CustomEvent('dakshayani:site-content-error'));
    });
})();
