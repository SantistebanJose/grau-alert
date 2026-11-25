self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });
self.addEventListener('push', event => {
  let data = { title: 'Grau-Alert', body: 'Tienes un nuevo aviso escolar.', url: 'familia.php' };
  try { data = { ...data, ...event.data.json() }; } catch {}
  const icon = new URL('public/assets/img/mgs.jpg', self.registration.scope).href;
  const url = new URL(data.url || 'familia.php', self.registration.scope).href;
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon,
    badge: icon,
    tag: data.tag || 'grau-alert-aviso',
    renotify: true,
    silent: false,
    vibrate: [180, 100, 180],
    data: { url },
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data?.url || new URL('familia.php', self.registration.scope).href;
  event.waitUntil(self.clients.openWindow(url));
});
