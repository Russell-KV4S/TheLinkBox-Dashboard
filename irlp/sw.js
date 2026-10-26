// Service worker for the dashboard's alerts. It only shows notifications and brings the page back
// when one is tapped; it deliberately caches nothing, so the dashboard is always loaded fresh.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) if ('focus' in list[i]) return list[i].focus();
		return self.clients.openWindow('./');
	}));
});
