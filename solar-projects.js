(function () {
  const caseList = document.querySelector('[data-case-list]');
  const caseFilters = document.querySelectorAll('[data-case-filter]');
  const testimonialList = document.querySelector('[data-testimonial-list]');
  const segmentLabels = {
    residential: 'Residential',
    commercial: 'Commercial',
    industrial: 'Industrial',
    agriculture: 'Agriculture',
  };

  function renderEmpty(container, message, className = 'site-search-empty') {
    if (!container) return;
    container.innerHTML = '';
    const paragraph = document.createElement('p');
    paragraph.className = className;
    paragraph.textContent = message;
    container.appendChild(paragraph);
  }

  function renderCaseStudies(items) {
    if (!caseList) return;
    if (!Array.isArray(items) || !items.length) {
      renderEmpty(caseList, 'No case studies available right now.');
      return;
    }

    caseList.innerHTML = '';
    const fragment = document.createDocumentFragment();

    items.forEach((study) => {
      const card = document.createElement('article');
      card.className = 'case-card';

      const media = document.createElement('div');
      media.className = 'case-media';
      if (study.image && study.image.src) {
        const img = document.createElement('img');
        img.src = study.image.src;
        img.alt = study.image.alt || study.title || 'Dakshayani solar project';
        img.loading = 'lazy';
        media.appendChild(img);
      }
      card.appendChild(media);

      const body = document.createElement('div');
      body.className = 'case-body';

      const segment = document.createElement('span');
      segment.className = 'case-segment';
      const segmentKey = typeof study.segment === 'string' ? study.segment.toLowerCase() : '';
      segment.textContent = segmentLabels[segmentKey] || segmentLabels.residential;
      body.appendChild(segment);

      const title = document.createElement('h3');
      title.textContent = study.title || 'Case study';
      body.appendChild(title);

      if (study.location) {
        const location = document.createElement('p');
        location.className = 'case-location';
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-location-dot';
        icon.setAttribute('aria-hidden', 'true');
        location.appendChild(icon);
        location.appendChild(document.createTextNode(` ${study.location}`));
        body.appendChild(location);
      }

      const summary = document.createElement('p');
      summary.textContent = study.summary || '';
      body.appendChild(summary);

      const metrics = document.createElement('dl');
      metrics.className = 'case-metrics';
      const metricsData = [
        { label: 'Capacity', value: study.capacityKw, suffix: 'kW' },
        { label: 'Annual generation', value: study.annualGenerationKwh, suffix: 'kWh' },
        { label: 'CO₂ offset', value: study.co2OffsetTonnes, suffix: 't' },
        { label: 'Payback', value: study.paybackYears, suffix: 'years' },
      ];
      metricsData.forEach(({ label, value, suffix }) => {
        const wrapper = document.createElement('div');
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        const numeric = typeof value === 'number' && !Number.isNaN(value) ? value : 0;
        dd.textContent = `${numeric} ${suffix}`.trim();
        wrapper.appendChild(dt);
        wrapper.appendChild(dd);
        metrics.appendChild(wrapper);
      });
      body.appendChild(metrics);

      if (Array.isArray(study.highlights) && study.highlights.length) {
        const highlights = document.createElement('ul');
        highlights.className = 'case-highlights';
        study.highlights.forEach((point) => {
          if (typeof point !== 'string' || point.trim() === '') return;
          const item = document.createElement('li');
          const icon = document.createElement('i');
          icon.className = 'fa-solid fa-check';
          icon.setAttribute('aria-hidden', 'true');
          item.appendChild(icon);
          item.appendChild(document.createTextNode(point));
          highlights.appendChild(item);
        });
        body.appendChild(highlights);
      }

      card.appendChild(body);
      fragment.appendChild(card);
    });

    caseList.appendChild(fragment);
  }

  function fetchCaseStudies(segment = '') {
    if (!caseList) return;
    renderEmpty(caseList, 'Loading case studies…', 'site-search-loading');
    const params = new URLSearchParams();
    if (segment) {
      params.append('segment', segment);
    }
    const url = params.toString() ? `/api/public/case-studies?${params.toString()}` : '/api/public/case-studies';
    fetch(url)
      .then((response) => {
        if (!response.ok) throw new Error('Failed to load case studies');
        return response.json();
      })
      .then((data) => {
        renderCaseStudies(data.caseStudies || []);
      })
      .catch(() => {
        renderEmpty(caseList, 'Unable to load case studies right now.');
      });
  }

  if (caseFilters.length) {
    caseFilters.forEach((button) => {
      button.addEventListener('click', () => {
        caseFilters.forEach((btn) => btn.classList.remove('is-active'));
        button.classList.add('is-active');
        fetchCaseStudies(button.dataset.caseFilter || '');
      });
    });
  }

  if (caseList) {
    fetchCaseStudies();
  }

  function renderTestimonials(items) {
    if (!testimonialList) return;
    if (!Array.isArray(items) || !items.length) {
      renderEmpty(testimonialList, 'Testimonials will appear here once published.');
      return;
    }

    testimonialList.innerHTML = '';
    const fragment = document.createDocumentFragment();

    items.forEach((item) => {
      const card = document.createElement('div');
      card.className = 'testimonial-card text-left';

      const quote = document.createElement('div');
      quote.className = 'quote-text';
      quote.textContent = item.quote || '';
      card.appendChild(quote);

      const author = document.createElement('div');
      author.className = 'author';

      if (item.image) {
        const avatar = document.createElement('img');
        avatar.src = item.image;
        avatar.alt = item.name || 'Customer photo';
        avatar.loading = 'lazy';
        avatar.className = 'author-avatar';
        author.appendChild(avatar);
      }

      const info = document.createElement('div');
      info.className = 'author-info';
      const name = document.createElement('p');
      name.className = 'author-name';
      const locationSuffix = item.location ? ` (${item.location})` : '';
      name.textContent = `${item.name || 'Customer'}${locationSuffix}`;
      info.appendChild(name);
      if (item.role) {
        const role = document.createElement('p');
        role.className = 'author-title';
        role.textContent = item.role;
        info.appendChild(role);
      }
      author.appendChild(info);
      card.appendChild(author);
      fragment.appendChild(card);
    });

    testimonialList.appendChild(fragment);
  }

  function loadTestimonials() {
    if (!testimonialList) return;
    renderEmpty(testimonialList, 'Loading testimonials…', 'site-search-loading');
    fetch('/api/public/testimonials')
      .then((response) => {
        if (!response.ok) throw new Error('Failed to load testimonials');
        return response.json();
      })
      .then((data) => {
        renderTestimonials(data.testimonials || []);
      })
      .catch(() => {
        renderEmpty(testimonialList, 'Unable to load testimonials right now.');
      });
  }

  loadTestimonials();
})();
