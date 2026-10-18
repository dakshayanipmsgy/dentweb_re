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
      document.activeElement?.blur();
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
          form.querySelector('[data-save-error]')?.remove();
          const id=form.querySelector('[name="quote_id"]');
          if(id&&payload.quote_id)id.value=payload.quote_id;
          let notice=form.querySelector('[data-save-result]');
          if(!notice){notice=document.createElement('p');notice.dataset.saveResult='';notice.setAttribute('role','status');form.prepend(notice);}
          notice.replaceChildren(document.createTextNode('Quotation saved. '));
          const link=document.createElement('a');link.href=payload.view_url;link.textContent='Open saved quotation';link.target='_blank';link.rel='noopener';notice.append(link);
          const another=document.createElement('a');another.href=form.dataset.quotationSaveForm==='employee'?'employee-quotations.php':'admin-quotations.php';another.textContent='Create another quotation';another.style.marginLeft='1rem';notice.append(another);
          form.dispatchEvent(new CustomEvent('quotation-saved',{detail:payload}));
          if (placeholder && !placeholder.closed) {
            placeholder.location.href = payload.view_url;
          } else {
            window.open(payload.view_url, '_blank');
          }
          return;
        }
        closePlaceholder(placeholder);
        throw new Error(payload?.message || payload?.error || (response.redirected ? 'Your session may have expired. Sign in again in another tab, then retry. Your entries are retained.' : 'Unable to save. Your entries are retained; please retry.'));
      } catch (error) {
        closePlaceholder(placeholder);
        let notice=form.querySelector('[data-save-error]');
        if(!notice){notice=document.createElement('p');notice.dataset.saveError='';notice.setAttribute('role','alert');form.prepend(notice);}
        notice.textContent=error.message||'Connection failed. Your entries are retained; please retry.';
        notice.scrollIntoView({block:'center'});
      } finally {
        saving = false;
        if (submitter) submitter.disabled = false;
      }
    });
  });
})();
