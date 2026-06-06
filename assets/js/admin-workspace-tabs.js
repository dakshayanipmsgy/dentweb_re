(() => {
  'use strict';

  const rootSelector = '[data-workspace-root]';
  const sameWorkspace = (link) => {
    const current = new URL(window.location.href);
    const target = new URL(link.href, current);
    return target.origin === current.origin && target.pathname === current.pathname;
  };

  const setLoading = (root, loading) => {
    root.classList.toggle('is-loading', loading);
    root.setAttribute('aria-busy', loading ? 'true' : 'false');
  };

  const loadWorkspace = async (url, push = true) => {
    const root = document.querySelector(rootSelector);
    if (!root) return window.location.assign(url);
    setLoading(root, true);
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'workspace-tabs' } });
      if (!response.ok) throw new Error(`Request failed: ${response.status}`);
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = parsed.querySelector(rootSelector);
      if (!next) throw new Error('Workspace panel was not found');
      root.replaceWith(next);
      document.title = parsed.title || document.title;
      if (push) history.pushState({ workspaceTab: true }, '', url);
      next.scrollIntoView({ block: 'start', behavior: 'smooth' });
    } catch (error) {
      window.location.assign(url);
    }
  };

  document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-workspace-tabs] a[data-workspace-tab]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!sameWorkspace(link) || link.target || link.hasAttribute('download')) return;
    const tabs = link.closest('[data-workspace-tabs]');
    const mode = link.dataset.workspaceMode || tabs.dataset.workspaceTabs;
    if (mode === 'fetch') {
      event.preventDefault();
      loadWorkspace(link.href);
      return;
    }
    sessionStorage.setItem(`workspace-scroll:${location.pathname}`, String(window.scrollY));
  });

  window.addEventListener('popstate', () => {
    const tabs = document.querySelector('[data-workspace-tabs="fetch"]');
    if (!tabs) return;
    const target = Array.from(tabs.querySelectorAll('a[data-workspace-tab]')).find((link) => link.href === window.location.href);
    if (target?.dataset.workspaceMode === 'reload') return window.location.reload();
    loadWorkspace(window.location.href, false);
  });

  const saved = sessionStorage.getItem(`workspace-scroll:${location.pathname}`);
  if (saved !== null) {
    sessionStorage.removeItem(`workspace-scroll:${location.pathname}`);
    requestAnimationFrame(() => window.scrollTo({ top: Number(saved) || 0, behavior: 'instant' }));
  }
})();
