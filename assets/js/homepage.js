/* Homepage controls: native photo scrolling, clear validation and contained mobile navigation. */
(() => {
  'use strict';

  const start = () => {
    const viewport = document.querySelector('[data-slides-viewport]');
    const previous = document.querySelector('[data-gallery-previous]');
    const next = document.querySelector('[data-gallery-next]');
    if (viewport && previous && next) {
      const updateGallery = () => {
        previous.disabled = viewport.scrollLeft <= 2;
        next.disabled = viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 2;
      };
      const moveGallery = (direction) => {
        const slide = viewport.querySelector('.slide');
        const distance = slide ? slide.getBoundingClientRect().width + 16 : viewport.clientWidth * 0.85;
        viewport.scrollBy({ left: direction * distance, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      };
      previous.addEventListener('click', () => moveGallery(-1));
      next.addEventListener('click', () => moveGallery(1));
      viewport.addEventListener('scroll', updateGallery, { passive: true });
      viewport.addEventListener('keydown', (event) => {
        if (event.target !== viewport) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          moveGallery(event.key === 'ArrowLeft' ? -1 : 1);
        }
      });
      viewport.querySelectorAll('img').forEach((image) => image.addEventListener('load', updateGallery, { once: true }));
      window.addEventListener('resize', updateGallery, { passive: true });
      updateGallery();
    }

    const form = document.getElementById('homepage-lead-form');
    if (form) {
      const fields = [...form.querySelectorAll('input:not([type="hidden"]),select')];
      const notice = document.getElementById('homepage-lead-form-alert');
      const clearField = (event) => {
        event.target.setCustomValidity('');
        event.target.removeAttribute('aria-invalid');
      };
      fields.forEach((field) => {
        field.addEventListener('input', clearField);
        field.addEventListener('change', clearField);
      });
      form.addEventListener('submit', (event) => {
        fields.forEach((field) => {
          field.setCustomValidity('');
          if (field.required && !field.value.trim()) {
            field.setCustomValidity(`Please enter ${field.labels?.[0]?.textContent.trim().toLowerCase() || 'this field'}.`);
          }
          if (field.id === 'lead-phone' && field.value.trim()) {
            const digits = field.value.replace(/\D/g, '');
            if (!/^[+\d][\d\s().-]+$/.test(field.value.trim()) || digits.length < 10 || digits.length > 15) {
              field.setCustomValidity('Enter a phone number with 10 to 15 digits, including the country code if needed.');
            }
          }
          field.toggleAttribute('aria-invalid', !field.validity.valid);
          if (!field.validity.valid) field.setAttribute('aria-invalid', 'true');
        });
        const invalid = fields.find((field) => !field.validity.valid);
        if (!invalid) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (notice) {
          notice.textContent = invalid.validationMessage;
          notice.classList.add('is-visible', 'is-error');
          notice.classList.remove('is-success');
        }
        invalid.focus();
        invalid.reportValidity();
      }, true);
    }

    const header = document.querySelector('header.site-header');
    const enhanceMenu = () => {
      const menu = header?.querySelector('#mobile-menu');
      const toggle = header?.querySelector('#mobile-menu-button');
      if (!menu || !toggle) return false;
      let wasOpen = false;
      const savedInert = new Map();
      const syncMenu = () => {
        const open = menu.classList.contains('show');
        const focusWasInside = menu.contains(document.activeElement);
        document.body.classList.toggle('home-menu-open', open);
        if (open) {
          menu.inert = false;
          menu.setAttribute('aria-hidden', 'false');
          if (!wasOpen) {
            menu.scrollTop = 0;
            document.querySelectorAll('main,footer.site-footer').forEach((element) => {
              savedInert.set(element, element.inert);
              element.inert = true;
            });
            menu.querySelector('[data-close-mobile]')?.focus({ preventScroll: true });
          }
        } else {
          savedInert.forEach((value, element) => { element.inert = value; });
          savedInert.clear();
          if (wasOpen && focusWasInside) {
            const target = matchMedia('(max-width:920px)').matches ? toggle : header.querySelector('a');
            target?.focus({ preventScroll: true });
          }
          menu.inert = true;
          menu.setAttribute('aria-hidden', 'true');
        }
        wasOpen = open;
      };
      new MutationObserver(syncMenu).observe(menu, { attributes: true, attributeFilter: ['class'] });
      document.addEventListener('keydown', (event) => {
        if (!menu.classList.contains('show')) return;
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopImmediatePropagation();
          toggle.click();
          return;
        }
        if (event.key !== 'Tab') return;
        const controls = [...menu.querySelectorAll('a[href],button:not([disabled]),[tabindex="0"]')].filter((element) => element.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if ((event.shiftKey && (document.activeElement === first || !menu.contains(document.activeElement))) || (!event.shiftKey && document.activeElement === last)) {
          event.preventDefault();
          (event.shiftKey ? last : first)?.focus();
        }
      }, true);
      window.addEventListener('resize', () => {
        if (matchMedia('(min-width:921px)').matches && menu.classList.contains('show')) toggle.click();
      }, { passive: true });
      syncMenu();
      return true;
    };
    if (header && !enhanceMenu()) {
      const observer = new MutationObserver(() => { if (enhanceMenu()) observer.disconnect(); });
      observer.observe(header, { childList: true, subtree: true });
    }

    const contact = document.getElementById('contact-form');
    if (contact && 'IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => {
        document.body.classList.toggle('home-contact-visible', entry.isIntersecting);
      }).observe(contact);
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
