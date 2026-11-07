/* Progressive enhancement. Viewing, navigation and forms work without this file. */
(function () {
  'use strict';
  function updateSection() {
    var hash = window.location.hash;
    document.querySelectorAll('.portal-quick-nav a, .customer-app-nav a').forEach(function (link) {
      var url = new URL(link.href, window.location.href);
      var samePage = url.pathname === window.location.pathname;
      var active = samePage && (hash ? url.hash === hash : !url.hash);
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', url.hash ? 'location' : 'page');
      else link.removeAttribute('aria-current');
    });
  }
  updateSection();
  window.addEventListener('hashchange', updateSection);
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.bank-copy');
    if (!button) return;
    var value = button.getAttribute('data-copy-value');
    if (!value) return;
    var feedback = document.getElementById('workspace-feedback');
    function announce(text) { if (feedback) feedback.textContent = text; }
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
      announce('Copy is unavailable in this browser. Select and copy the bank details shown above.');
      return;
    }
    navigator.clipboard.writeText(value).then(function () {
      button.textContent = 'Copied';
      announce('Bank detail copied.');
      window.setTimeout(function () { button.textContent = 'Copy'; }, 1800);
    }).catch(function () {
      announce('Copy was blocked. Select and copy the bank details shown above.');
    });
  });
}());
