// Aldan Guru Service Worker v1.0
const CACHE = 'aldan-guru-v1';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(clients.claim());
});

// Push notification handler
self.addEventListener('push', function(e) {
  var data = e.data ? e.data.json() : {};
  var title = data.title || 'Aldan Guru';
  var options = {
    body: data.body || 'Namaskar! Aaj ka task complete karo.',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    vibrate: [200, 100, 200],
    data: { url: data.url || 'https://guru.aldancare.com' },
    actions: [
      { action: 'open', title: 'App Kholo' }
    ]
  };
  e.waitUntil(self.registration.showNotification(title, options));
});

// Notification click
self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : 'https://guru.aldancare.com';
  e.waitUntil(clients.openWindow(url));
});
