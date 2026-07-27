/* Krishna Reminder — service worker (PWA shell + web push) */

const CACHE = 'krishna-v1';

const SHELL = [
    '/',
    '/offline',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/assets/img/favicon.svg',
    '/assets/img/icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(SHELL).catch(() => undefined))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
        return;
    }

    // Never cache authenticated pages or API responses — reminders must be fresh.
    if (request.url.includes('/api/') || request.url.includes('/client/') || request.url.includes('/admin/')) {
        event.respondWith(fetch(request).catch(() => caches.match('/offline')));
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => cached || caches.match('/offline'));

            return cached || network;
        })
    );
});

/* ------------------------------------------------------------- Web push */

self.addEventListener('push', (event) => {
    let payload = { title: 'Krishna Reminder', body: '' };

    try {
        payload = event.data ? event.data.json() : payload;
    } catch (e) {
        payload.body = event.data ? event.data.text() : '';
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Krishna Reminder', {
            body: payload.body || '',
            icon: '/assets/img/icon-192.png',
            badge: '/assets/img/icon-192.png',
            tag: payload.tag || 'krishna-reminder',
            requireInteraction: true,
            data: { url: payload.url || '/client' },
            actions: [
                { action: 'done', title: '✅ Done' },
                { action: 'snooze', title: '⏰ Snooze' }
            ]
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = (event.notification.data && event.notification.data.url) || '/client';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        })
    );
});
