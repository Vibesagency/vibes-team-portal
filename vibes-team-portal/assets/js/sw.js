// Minimal service worker: satisfies the installability requirement for
// "Add to Home Screen" on Android/Chrome. Deliberately does NOT cache any
// pages or API responses — this app's data (tasks, invoices, quotations…)
// must always come from the network, never a stale cache.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => { /* no-op: let the browser handle every request normally */ });
