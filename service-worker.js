const CACHE_NAME='dakshayani-pwa-v5-work-static';
const BASE=new URL('./',self.location.href);
const SAFE_PATHS=['manifest.webmanifest','employee-manifest.webmanifest','offline.html','style.css','layout-styles.css','login.js','assets/css/admin-unified.css','assets/css/pwa-shell.css','assets/js/pwa.js','assets/js/employee-pwa.js','assets/icons/app-icon.svg','assets/icons/app-icon-maskable.svg','assets/icons/employee/icon-192.png','assets/icons/employee/icon-512.png','assets/icons/employee/icon-maskable-192.png','assets/icons/employee/icon-maskable-512.png','assets/icons/employee/apple-touch-icon.png'];
const SAFE_URLS=new Set(SAFE_PATHS.map(path=>new URL(path,BASE).href));
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE_NAME).then(cache=>Promise.all([...SAFE_URLS].map(url=>fetch(url,{cache:'no-cache'}).then(response=>{if(response.ok&&response.type==='basic')return cache.put(url,response);}).catch(()=>undefined))))));
self.addEventListener('activate',event=>event.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('dakshayani-pwa')&&key!==CACHE_NAME).map(key=>caches.delete(key)))),self.clients.claim()])));
self.addEventListener('message',event=>{if(event.data?.type==='SKIP_WAITING')self.skipWaiting();});
self.addEventListener('fetch',event=>{
  const request=event.request,url=new URL(request.url);
  if(request.method!=='GET')return;
  if(request.mode==='navigate'){event.respondWith(fetch(request,{cache:'no-store'}).catch(()=>caches.match(new URL('offline.html',BASE).href)));return;}
  if(url.origin!==self.location.origin)return;
  if(url.pathname.endsWith('.php')||url.pathname.includes('/api/')){event.respondWith(fetch(request,{cache:'no-store'}));return;}
  if(!SAFE_URLS.has(url.href))return;
  event.respondWith(caches.match(url.href).then(hit=>hit||fetch(request,{cache:'no-cache'}).then(response=>{if(response.ok&&response.type==='basic')caches.open(CACHE_NAME).then(cache=>cache.put(url.href,response.clone()));return response;})));
});
self.addEventListener('push',event=>{
  let data={};try{data=event.data?.json()||{};}catch(error){}
  const id=Number.isSafeInteger(Number(data.notification_id))&&Number(data.notification_id)>0?Number(data.notification_id):0;
  const unread=Math.max(0,Math.min(99,Number(data.unread_count)||0));
  const title=typeof data.title==='string'&&data.title.length<=80?data.title:'Dakshayani Work';
  const body=typeof data.message==='string'&&data.message.length<=160?data.message:'New work notification available. Sign in to view it.';
  const badge=self.registration.setAppBadge?(unread?self.registration.setAppBadge(unread):(self.registration.clearAppBadge?self.registration.clearAppBadge():self.registration.setAppBadge(0))):Promise.resolve();
  event.waitUntil(Promise.all([self.registration.showNotification(title,{body,icon:new URL('assets/icons/employee/icon-192.png',BASE).href,badge:new URL('assets/icons/employee/icon-192.png',BASE).href,tag:id?`work-notification-${id}`:'work-notification',renotify:false,data:{notificationId:id}}),badge]));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();const id=Number(event.notification.data?.notificationId);const target=new URL(id>0&&Number.isSafeInteger(id)?`notification-open.php?id=${id}`:'notifications.php',BASE).href;
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(windows=>{for(const client of windows){if(new URL(client.url).origin===self.location.origin){client.navigate(target);return client.focus();}}return self.clients.openWindow(target);}));
});
