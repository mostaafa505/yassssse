/**
 * offline-db.js — قاعدة بيانات المتصفح المحلية للـ POS
 * تخزن: المنتجات (products_cache) + الفواتير المعلقة (pending_sales)
 * + طلبات إلغاء/إرجاع الفواتير المعلقة (pending_actions)
 */

const DB_NAME    = 'pos_offline_db';
const DB_VERSION = 2; // كان 1 — لازم يترفع كل ما نضيف object store جديد، وإلا مش هيتعمل عليه upgrade للأجهزة اللي فاتحة النسخة القديمة

let _db = null;

function openDB() {
    if (_db) return Promise.resolve(_db);
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = e => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('products_cache')) {
                db.createObjectStore('products_cache', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('pending_sales')) {
                const ps = db.createObjectStore('pending_sales', {
                    keyPath: 'local_id', autoIncrement: true
                });
                ps.createIndex('created_at', 'created_at');
            }
            if (!db.objectStoreNames.contains('pending_actions')) {
                const pa = db.createObjectStore('pending_actions', {
                    keyPath: 'local_id', autoIncrement: true
                });
                pa.createIndex('created_at', 'created_at');
            }
        };

        req.onsuccess = e => { _db = e.target.result; resolve(_db); };
        req.onerror   = e => reject(e.target.error);
    });
}

async function saveProductsToCache(products) {
    const db    = await openDB();
    const tx    = db.transaction('products_cache', 'readwrite');
    const store = tx.objectStore('products_cache');
    store.clear();
    for (const p of products) store.put(p);
    return new Promise((resolve, reject) => {
        tx.oncomplete = resolve;
        tx.onerror    = e => reject(e.target.error);
    });
}

async function getProductsFromCache() {
    const db    = await openDB();
    const tx    = db.transaction('products_cache', 'readonly');
    const store = tx.objectStore('products_cache');
    return new Promise((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror   = e => reject(e.target.error);
    });
}

function generateClientUid() {
    if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    // fallback لمتصفحات قديمة مالهاش crypto.randomUUID
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

async function addPendingSale(saleData) {
    const db    = await openDB();
    const tx    = db.transaction('pending_sales', 'readwrite');
    const store = tx.objectStore('pending_sales');
    // client_uid فريد لكل فاتورة أوفلاين — يمنع تسجيلها مرتين لو اتكررت المزامنة
    const record = { ...saleData, client_uid: saleData.client_uid || generateClientUid(), created_at: new Date().toISOString(), status: 'pending' };
    return new Promise((resolve, reject) => {
        const req = store.add(record);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
    });
}

async function getPendingSales() {
    const db    = await openDB();
    const tx    = db.transaction('pending_sales', 'readonly');
    const store = tx.objectStore('pending_sales');
    return new Promise((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror   = e => reject(e.target.error);
    });
}

async function removePendingSale(local_id) {
    const db    = await openDB();
    const tx    = db.transaction('pending_sales', 'readwrite');
    const store = tx.objectStore('pending_sales');
    return new Promise((resolve, reject) => {
        const req = store.delete(local_id);
        req.onsuccess = resolve;
        req.onerror   = e => reject(e.target.error);
    });
}

async function countPendingSales() {
    const db    = await openDB();
    const tx    = db.transaction('pending_sales', 'readonly');
    const store = tx.objectStore('pending_sales');
    return new Promise((resolve, reject) => {
        const req = store.count();
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
    });
}

// بتتسجل عليها سبب فشل آخر محاولة مزامنة عشان تفضل بادية للكاشير/الأدمن في
// شاشة الفواتير بدل ما تختفي أو تفضل عالقة من غير أي تفسير
async function markPendingSaleError(local_id, reason) {
    const db    = await openDB();
    const tx    = db.transaction('pending_sales', 'readwrite');
    const store = tx.objectStore('pending_sales');
    return new Promise((resolve, reject) => {
        const getReq = store.get(local_id);
        getReq.onsuccess = () => {
            const record = getReq.result;
            if (!record) { resolve(); return; }
            record.last_error = reason;
            record.last_attempt_at = new Date().toISOString();
            const putReq = store.put(record);
            putReq.onsuccess = () => resolve();
            putReq.onerror   = e => reject(e.target.error);
        };
        getReq.onerror = e => reject(e.target.error);
    });
}

// ─── طلبات إلغاء/إرجاع الفواتير أوفلاين ─────────────────────────────────────
async function addPendingAction(actionData) {
    const db    = await openDB();
    const tx    = db.transaction('pending_actions', 'readwrite');
    const store = tx.objectStore('pending_actions');
    const record = { ...actionData, client_uid: actionData.client_uid || generateClientUid(), created_at: new Date().toISOString() };
    return new Promise((resolve, reject) => {
        const req = store.add(record);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
    });
}

async function getPendingActions() {
    const db    = await openDB();
    const tx    = db.transaction('pending_actions', 'readonly');
    const store = tx.objectStore('pending_actions');
    return new Promise((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror   = e => reject(e.target.error);
    });
}

async function removePendingAction(local_id) {
    const db    = await openDB();
    const tx    = db.transaction('pending_actions', 'readwrite');
    const store = tx.objectStore('pending_actions');
    return new Promise((resolve, reject) => {
        const req = store.delete(local_id);
        req.onsuccess = resolve;
        req.onerror   = e => reject(e.target.error);
    });
}

async function countPendingActions() {
    const db    = await openDB();
    const tx    = db.transaction('pending_actions', 'readonly');
    const store = tx.objectStore('pending_actions');
    return new Promise((resolve, reject) => {
        const req = store.count();
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
    });
}
