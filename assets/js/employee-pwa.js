(() => {
  const root = document.querySelector('[data-employee-pwa]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let promptEvent = null;
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const status = (message) => { document.querySelectorAll('[data-app-status]').forEach((el) => { el.textContent = message; }); };
  const install = document.querySelector('[data-employee-install]');
  const renderInstall = () => {
    if (!install) return;
    install.hidden = standalone() || !promptEvent;
    status(standalone() ? 'Installed — running as Dakshayani Work.' : promptEvent ? 'Ready to install.' : 'Install prompt unavailable. Use the instructions below.');
  };
  addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); promptEvent = event; renderInstall(); });
  addEventListener('appinstalled', () => { promptEvent = null; localStorage.setItem('dakshayani-work-install-dismissed-v1', '1'); renderInstall(); });
  install?.addEventListener('click', async () => { if (!promptEvent) return; await promptEvent.prompt(); await promptEvent.userChoice; promptEvent = null; renderInstall(); });
  const network = () => document.querySelectorAll('[data-network-status]').forEach((el) => { el.textContent = navigator.onLine ? 'Online' : 'Offline — current work requires internet'; el.classList.toggle('is-offline', !navigator.onLine); });
  addEventListener('online', network); addEventListener('offline', network); network(); renderInstall();

  const api = async (action, method = 'GET', body) => {
    const response = await fetch(`api/push-subscriptions.php?action=${encodeURIComponent(action)}`, { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
    const json = await response.json(); if (!response.ok || !json.ok) throw new Error(json.error?.message || 'Push request failed.'); return json.data;
  };
  const devices = document.querySelector('[data-push-devices]');
  const paintDevices = (rows) => { if (!devices) return; devices.replaceChildren(...(rows.length ? rows.map((row) => { const li=document.createElement('li');li.textContent=`${row.label} — ${row.status} `;if(row.status==='active'){const b=document.createElement('button');b.type='button';b.textContent='Revoke';b.dataset.revoke=row.id;li.append(b);}return li; }) : [Object.assign(document.createElement('li'), { textContent: 'No registered browsers.' })])); };
  let config;
  const load = async () => { if (!root) return; try { config=await api('status');paintDevices(config.devices);document.querySelector('[data-push-state]').textContent=config.available?'Push is available. Permission is requested only when you press Enable.':'Push is not configured on this server.'; } catch(e){document.querySelector('[data-push-state]').textContent=e.message;} };
  document.querySelector('[data-enable-push]')?.addEventListener('click', async () => {
    const out=document.querySelector('[data-push-state]');
    try { if(!config?.available)throw new Error('Push is not configured.');if(!('Notification'in window)||!('PushManager'in window)||!('serviceWorker'in navigator))throw new Error('This browser does not support web push.');const permission=await Notification.requestPermission();if(permission!=='granted')throw new Error(permission==='denied'?'Notifications were denied. Change browser settings before trying again.':'Notification permission was not granted.');const registration=await navigator.serviceWorker.ready;let sub=await registration.pushManager.getSubscription();if(!sub)sub=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:config.vapid_public_key});await api('subscribe','POST',{subscription:sub.toJSON(),label:navigator.platform||'This browser'});out.textContent='Notifications enabled for this browser.';await load(); } catch(e){out.textContent=e.message;}
  });
  devices?.addEventListener('click', async(e)=>{const b=e.target.closest('[data-revoke]');if(!b)return;await api('revoke','POST',{id:b.dataset.revoke});await load();});
  document.querySelector('[data-revoke-all]')?.addEventListener('click',async()=>{await api('revoke-all','POST',{});await load();});
  load();
})();
