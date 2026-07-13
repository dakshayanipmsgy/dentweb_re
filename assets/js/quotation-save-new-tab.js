(function () {
  const forms = document.querySelectorAll('form[data-quotation-save-form]');
  if (!forms.length || typeof window.fetch !== 'function') return;

  const closePlaceholder = (placeholder) => {
    try {
      if (placeholder && !placeholder.closed) placeholder.close();
    } catch (error) {
      // Ignore browser restrictions around closing windows we could not open.
    }
  };

  forms.forEach((form) => {
    let saving = false;
    form.addEventListener('submit', async (event) => {
      if (saving) {
        event.preventDefault();
        return;
      }
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

      event.preventDefault();
      saving = true;
      const submitter = event.submitter || form.querySelector('[type="submit"]');
      const placeholder = window.open('', '_blank');
      if (placeholder) {
        try {
          placeholder.document.title = 'Saving quotation...';
          placeholder.document.body.innerHTML = '<p>Saving quotation. The quotation view will open here after the save succeeds.</p>';
        } catch (error) {
          // Some browsers restrict access immediately; navigation below can still work.
        }
      }
      if (submitter) submitter.disabled = true;

      try {
        const actionUrl = form.getAttribute('action') || window.location.href;
        const response = await fetch(actionUrl, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-Quotation-Save': '1'
          }
        });
        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json') ? await response.json() : null;
        if (response.ok && payload && payload.ok && payload.view_url) {
          if (placeholder && !placeholder.closed) {
            placeholder.location.href = payload.view_url;
          } else {
            window.open(payload.view_url, '_blank');
          }
          return;
        }
        closePlaceholder(placeholder);
        if (response.redirected && response.url) {
          window.location.href = response.url;
          return;
        }
        window.location.reload();
      } catch (error) {
        closePlaceholder(placeholder);
        window.location.reload();
      } finally {
        saving = false;
        if (submitter) submitter.disabled = false;
      }
    });
  });
})();
