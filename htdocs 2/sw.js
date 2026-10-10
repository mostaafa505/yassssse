/**
 * sw.js — Service Worker الشامل للـ POS Offline
 * يخزن كل الصفحات اللي زارها المستخدم ويخدمها بدون انترنت
 */

const CACHE_NAME   = 'pos-v3';
const ASSET_CACHE  = 'pos-assets-v3';

// توكن CSRF (F-05) — مبعوت من الصفحة عن طريق postMessage (راجع header.php)،
// لازم يتضاف كهيدر لأي POST بيعملها عامل الخدمة نفسه (خارج سياق fetch()
// العادي بتاع الصفحة، فمش بيستفيد من الـ monkey-patch في app.js)
let csrfToken = null;
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SET_CSRF_TOKEN') {
        csrfToken = event.data.token;
    }
});

// ─── ملفات Assets ثابتة تُحفظ فوراً عند التثبيت ──────────────────────────────
const STATIC_ASSETS = [
    '/store/assets/css/app.css',
    '/store/assets/js/app.js',
    '/store/assets/js/offline-db.js',
    '/store/assets/img/dahana.jpg',
    '/store/assets/img/adventure.jpg',
    '/store/manifest.json',
];

// ─── Install: خزّن الـ Assets الثابتة ─────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(ASSET_CACHE)
            .then(cache => Promise.allSettled(
                STATIC_ASSETS.map(url => cache.add(url).catch(() => {}))
            ))
            .then(() => self.skipWaiting())
    );
});

// ─── Activate: امسح الكاشات القديمة ──────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k !== CACHE_NAME && k !== ASSET_CACHE)
                    .map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// ─── Fetch: منطق الكاش الذكي ──────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // تجاهل طلبات خارجية (CDN fonts etc) في وضع أوفلاين — دعها للمتصفح
    if (url.origin !== location.origin) {
        event.respondWith(
            fetch(req).catch(() => caches.match(req))
        );
        return;
    }

    // ── POST requests: مرورها للسيرفر — لو فشلت معالجتها في JS ──────────────
    if (req.method === 'POST') {
        return; // الـ JS في الصفحة يتعامل مع POST أوفلاين
    }

    // ── API calls (JSON): Network أولاً ثم الكاش ─────────────────────────────
    if (url.pathname.includes('search_product.php') ||
        url.pathname.includes('sync_offline.php') ||
        url.pathname.includes('get_sale_json.php') ||
        url.pathname.includes('sync_offline_actions.php')) {
        event.respondWith(
            fetch(req)
                .then(res => {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(req, clone));
                    return res;
                })
                .catch(() => caches.match(req).then(cached => cached || new Response(
                    JSON.stringify({ success: false, offline: true, products: [], error: 'offline' }),
                    { headers: { 'Content-Type': 'application/json' } }
                )))
        );
        return;
    }

    // ── صفحات HTML: Network أولاً، لو فشل → الكاش ───────────────────────────
    // (هذا هو السر: نخزّن كل صفحة PHP بعد تحميلها بنجاح)
    event.respondWith(
        fetch(req)
            .then(res => {
                // خزّن فقط الردود الناجحة للصفحات
                if (res.status === 200 && req.method === 'GET') {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(req, clone));
                }
                return res;
            })
            .catch(() => {
                // فشل الشبكة — جرب الكاش
                return caches.match(req).then(cached => {
                    if (cached) return cached;
                    // لو مفيش كاش للصفحة المطلوبة، ارجع صفحة الـ POS المحفوظة
                    return caches.match('/store/pos/index.php') ||
                           caches.match('/store/pos/') ||
                           new Response('<html dir="rtl"><body style="font-family:Cairo,sans-serif;text-align:center;padding:50px"><h2>⚠️ لا يوجد اتصال بالانترنت</h2><p>افتح شاشة نقطة البيع للعمل في وضع عدم الاتصال</p><a href="/store/pos/" style="color:#2563eb">فتح نقطة البيع</a></body></html>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
                });
            })
    );
});

// ─── Background Sync: رفع الفواتير المعلقة تلقائياً ─────────────────────────
self.addEventListener('sync', event => {
    if (event.tag === 'sync-pending-sales') {
        event.waitUntil(syncPendingSales());
    }
    if (event.tag === 'sync-pending-actions') {
        event.waitUntil(syncPendingActions());
    }
});

async function syncPendingSales() {
    const db      = await openOfflineDB();
    const pending = await getAllPending(db, 'pending_sales');
    if (!pending.length) return;

    try {
        const res  = await fetch('/store/pos/sync_offline.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '' },
            body: JSON.stringify({ sales: pending })
        });
        const data = await res.json();

        if (data.success) {
            for (const id of data.synced_ids) await deletePending(db, 'pending_sales', id);
            for (const r of (data.failed_reasons || [])) await markPendingErrorSW(db, 'pending_sales', r.local_id, r.reason);
            const clients = await self.clients.matchAll({ includeUncontrolled: true });
            clients.forEach(c => c.postMessage({ type: 'SYNC_COMPLETE', synced: data.synced_ids.length, failed: data.failed || 0, failedReasons: data.failed_reasons || [] }));
        }
    } catch (err) {
        throw err; // يجعل البراوزر يعيد المحاولة
    }
}

// ─── Background Sync: رفع طلبات إلغاء/إرجاع الفواتير المعلقة ────────────────
async function syncPendingActions() {
    const db      = await openOfflineDB();
    const pending = await getAllPending(db, 'pending_actions');
    if (!pending.length) return;

    try {
        const res  = await fetch('/store/sales/sync_offline_actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '' },
            body: JSON.stringify({ actions: pending })
        });
        const data = await res.json();

        if (data.success) {
            for (const id of data.synced_ids) await deletePending(db, 'pending_actions', id);
            const clients = await self.clients.matchAll({ includeUncontrolled: true });
            clients.forEach(c => c.postMessage({ type: 'ACTIONS_SYNC_COMPLETE', synced: data.synced_ids.length, failed: data.failed || 0 }));
        }
    } catch (err) {
        throw err; // يجعل البراوزر يعيد المحاولة
    }
}

// ─── IndexedDB helpers ─────────────────────────────────────────────────────────
// النسخة لازم تفضل مطابقة لـ DB_VERSION في assets/js/offline-db.js بالظبط —
// أي object store جديد يتضاف هناك لازم يتضاف هنا كمان بنفس رقم النسخة، وإلا
// الـ Service Worker (اللي بيشتغل في خيط منفصل) هيفتح نسخة قديمة من القاعدة
// ومش هيلاقي الـ store الجديد وقت المزامنة في الخلفية.
function openOfflineDB() {
    return new Promise((res, rej) => {
        const r = indexedDB.open('pos_offline_db', 2);
        r.onsuccess = e => res(e.target.result);
        r.onerror   = e => rej(e.target.error);
        r.onupgradeneeded = e => {
            const db = e.target.result;
            ['pending_sales', 'pending_actions', 'products_cache'].forEach(name => {
                if (!db.objectStoreNames.contains(name)) {
                    db.createObjectStore(name, name === 'products_cache'
                        ? { keyPath: 'id' }
                        : { keyPath: 'local_id', autoIncrement: true });
                }
            });
        };
    });
}
function getAllPending(db, store) {
    return new Promise((res, rej) => {
        const r = db.transaction(store, 'readonly').objectStore(store).getAll();
        r.onsuccess = () => res(r.result || []);
        r.onerror   = e => rej(e.target.error);
    });
}
function deletePending(db, store, key) {
    return new Promise((res, rej) => {
        const r = db.transaction(store, 'readwrite').objectStore(store).delete(key);
        r.onsuccess = res;
        r.onerror   = e => rej(e.target.error);
    });
}
function markPendingErrorSW(db, storeName, key, reason) {
    return new Promise((res, rej) => {
        const tx    = db.transaction(storeName, 'readwrite');
        const store = tx.objectStore(storeName);
        const getReq = store.get(key);
        getReq.onsuccess = () => {
            const record = getReq.result;
            if (!record) { res(); return; }
            record.last_error = reason;
            record.last_attempt_at = new Date().toISOString();
            const putReq = store.put(record);
            putReq.onsuccess = () => res();
            putReq.onerror   = e => rej(e.target.error);
        };
        getReq.onerror = e => rej(e.target.error);
    });
}
