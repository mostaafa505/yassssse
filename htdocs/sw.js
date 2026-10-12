/* Service Worker — بيخلّي النظام يتثبّت كتطبيق ويفتح بسرعة.
   مبنحفظش صفحات النظام (فيها بيانات مالية) — الصفحات دايماً من السيرفر، ولو مفيش نت بتظهر صفحة offline.html. */
const VERSION = 'mb-v1';
const STATIC = VERSION + '-static';
const PRECACHE = ['offline.html', 'assets/img/icon-192.png', 'assets/img/icon-512.png', 'assets/favicon.svg', 'assets/img/logo-mark.svg'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

const isStatic = (u) => /\.(css|js|woff2?|png|svg|ico|webp)$/.test(u.pathname) && !u.pathname.endsWith('sw.js');
const isCdn = (u) => u.hostname === 'cdn.jsdelivr.net';

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') { return; }
  const u = new URL(req.url);

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  if ((u.origin === location.origin && isStatic(u)) || isCdn(u)) {
    // ملفات ثابتة: من الكاش فوراً وتتحدّث في الخلفية
    e.respondWith(caches.open(STATIC).then((c) => c.match(req).then((hit) => {
      const net = fetch(req).then((res) => { if (res && (res.ok || res.type === 'opaque')) { c.put(req, res.clone()); } return res; }).catch(() => hit);
      return hit || net;
    })));
  }
});
