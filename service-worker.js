self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });
self.addEventListener('push', event => {
  let data = {title:'Grau-Alert', body:'Tienes un nuevo aviso escolar.', url:'/familia.php'};
  try { data = {...data, ...event.data.json()}; } catch {}
  event.waitUntil(self.registration.showNotification(data.title, {body:data.body, icon:'/public/assets/img/mgs.jpg', badge:'/public/assets/img/mgs.jpg', data:{url:data.url || '/familia.php'}}));
});
self.addEventListener('notificationclick', event => { event.notification.close(); event.waitUntil(clients.openWindow(event.notification.data?.url || '/familia.php')); });
