<?php
$pageTitle = 'سجل الفواتير / Invoices';
require_once dirname(__DIR__) . '/includes/header.php';

requireAuth(['admin', 'cashier', 'accountant']);

$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$payMethod = $_GET['pay_method'] ?? '';

$sql = "
    SELECT s.*, u.name_ar as cashier_name_ar,
    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as items_count
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE 1=1
";

$params = [];

if ($_SESSION['user_role'] === 'cashier') {
    $sql .= " AND s.cashier_id = ?";
    $params[] = $_SESSION['user_id'];
}

if ($search !== '') {
    $sql .= " AND (s.invoice_number LIKE ? OR s.customer_name LIKE ? OR s.customer_phone LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(s.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $sql .= " AND DATE(s.created_at) <= ?";
    $params[] = $dateTo;
}

if ($payMethod !== '') {
    $sql .= " AND s.payment_method = ?";
    $params[] = $payMethod;
}

$sql .= " ORDER BY s.id DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">سجل الفواتير</h1>
        <p class="text-subtle mb-0">عرض وطباعة فواتير المبيعات السابقة</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/sales/export.php" class="btn btn-outline-success fw-bold">
            <i class="bi bi-file-earmark-excel me-1"></i> تصدير إكسل (Excel)
        </a>
        <?php if (in_array($_SESSION['user_role'], ['admin', 'cashier'])): ?>
        <a href="<?= BASE_URL ?>/pos/" class="btn btn-primary fw-bold">
            <i class="bi bi-calculator me-1"></i> نقطة البيع (POS)
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="panel mb-4">
    <div class="panel-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label text-caption">بحث</label>
                <input type="text" name="q" class="form-control" placeholder="رقم الفاتورة، العميل..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label text-caption">من تاريخ</label>
                <input type="date" name="date_from" class="form-control text-start" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label text-caption">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control text-start" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-caption">طريقة الدفع</label>
                <select name="pay_method" class="form-select">
                    <option value="">جميع الطرق</option>
                    <option value="cash" <?= $payMethod === 'cash' ? 'selected' : '' ?>>نقدي (كاش)</option>
                    <option value="card" <?= $payMethod === 'card' ? 'selected' : '' ?>>بطاقة</option>
                    <option value="transfer" <?= $payMethod === 'transfer' ? 'selected' : '' ?>>تحويل بنكي</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">تصفية</button>
                <?php if ($search || $dateFrom || $dateTo || $payMethod): ?>
                    <a href="<?= BASE_URL ?>/sales/" class="btn btn-light"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Invoices Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0">رقم الفاتورة</th>
                    <th class="text-caption text-subtle border-bottom-0">التاريخ والوقت</th>
                    <th class="text-caption text-subtle border-bottom-0">الكاشير</th>
                    <th class="text-caption text-subtle border-bottom-0">الأصناف</th>
                    <th class="text-caption text-subtle border-bottom-0">طريقة الدفع</th>
                    <th class="text-end text-caption text-subtle border-bottom-0">الإجمالي</th>
                    <th class="text-center text-caption text-subtle border-bottom-0">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-subtle">
                            <i class="bi bi-receipt fs-1 d-block mb-3 text-tertiary"></i>
                            لا توجد فواتير مطابقة للبحث.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sales as $s): ?>
                        <tr data-sale-id="<?= $s['id'] ?>">
                            <td><span dir="ltr" class="num fw-bold text-primary">#<?= e($s['invoice_number']) ?></span></td>
                            <td class="num text-subtle"><?= date('Y-m-d h:i A', strtotime($s['created_at'])) ?></td>
                            <td><?= e($s['cashier_name_ar'] ?: 'Admin') ?></td>
                            <td class="num"><span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><?= (int)$s['items_count'] ?> صنف</span></td>
                            <td>
                                <?php if ($s['payment_method'] === 'cash'): ?>
                                    <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2);"><i class="bi bi-cash me-1"></i> كاش</span>
                                <?php elseif ($s['payment_method'] === 'card'): ?>
                                    <span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2);"><i class="bi bi-credit-card me-1"></i> بطاقة</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #F8FAFC; color: #475569; border: 1px solid #CBD5E1;"><i class="bi bi-bank me-1"></i> تحويل</span>
                                <?php endif; ?>
                                
                                <?php if ($s['status'] === 'returned'): ?>
                                    <span class="badge mt-1 d-block" style="background: var(--intent-danger-bg); color: var(--intent-danger); border: 1px solid rgba(225,29,72,0.2);">ملغاة / مرتجع بالكامل</span>
                                <?php elseif ($s['status'] === 'partial_return'): ?>
                                    <span class="badge mt-1 d-block" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2);">مرتجع جزئي</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end num fw-bold text-dark fs-6">
                                <?php if ($s['status'] === 'returned'): ?>
                                    <del class="text-muted"><?= money($s['total']) ?></del>
                                <?php else: ?>
                                    <?= money($s['total']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/sales/invoice.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-sm btn-light mb-1">
                                    <i class="bi bi-printer me-1"></i> طباعة
                                </a>
                                <?php
                                    // صلاحية الإرجاع/الإلغاء: أدمن ومحاسب دايمًا، وأي حد تاني
                                    // (زي الكاشير) لو المدير اديه صلاحية "إلغاء فواتير البيع"
                                    // المخصصة من شاشة المستخدمين — قبل كده كان الشرط مثبت على
                                    // الدور بس، فالصلاحية المخصصة ماكانتش بتتفعّل خالص
                                    $canVoidThisSale = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('void_invoice');
                                ?>
                                <?php if ($s['status'] !== 'returned' && $canVoidThisSale): ?>
                                    <button onclick="handleReturnClick(<?= $s['id'] ?>)" class="btn btn-sm btn-outline-warning w-100 mb-1">
                                        <i class="bi bi-arrow-return-left me-1"></i> إرجاع
                                    </button>
                                <?php endif; ?>
                                <?php if ($s['status'] === 'completed' && $canVoidThisSale): ?>
                                    <button onclick="cancelSale(<?= $s['id'] ?>)" class="btn btn-sm btn-outline-danger w-100">
                                        <i class="bi bi-x-circle me-1"></i> إلغاء الفاتورة كاملة
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
</div>
    </div>
</div>

<!-- Offline Return Modal -->
<div class="modal fade" id="offlineReturnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold">إرجاع فاتورة (وضع أوفلاين)</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-wifi-off me-1"></i>
                    مفيش إنترنت دلوقتي — المرتجع ده هيتحفظ محليًا ويترفع تلقائيًا للسيرفر أول ما الاتصال يرجع.
                </div>
                <div id="offlineReturnItemsBox"></div>
                <div class="mt-3">
                    <label class="form-label text-caption fw-bold">سبب الإرجاع *</label>
                    <textarea id="offlineReturnReason" class="form-control" rows="2" placeholder="مثال: مقاس غير مناسب..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-warning fw-bold" onclick="submitOfflineReturn()">
                    <i class="bi bi-arrow-return-left me-1"></i> حفظ المرتجع محليًا
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Offline pending actions bar -->
<div id="pendingActionsBar" style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:9999; background:#0369a1; color:#fff; text-align:center; padding:8px 16px; font-weight:700; font-size:0.9rem; font-family:'Cairo',sans-serif; direction:rtl;">
    <i class="bi bi-arrow-repeat me-2"></i>
    <span id="pendingActionsBarText"></span>
    &nbsp;&nbsp;
    <button onclick="manualSyncActions()" id="syncActionsBtn" style="background:#fff; color:#0369a1; border:none; border-radius:6px; padding:2px 12px; font-weight:700; cursor:pointer; font-family:'Cairo',sans-serif;">
        رفع الآن
    </button>
</div>

<script src="<?= BASE_URL ?>/assets/js/offline-db.js"></script>
<script>
let offlineReturnContext = null; // {saleId, invoiceNumber, items}

// بعد ما نحفظ طلب إلغاء/إرجاع أوفلاين، نوضح فورًا جوه الصف نفسه إنه "قيد
// التنفيذ" — بدل ما الكاشير يحس إن حاجة معملتش لحد ما يشوف رسالة تختفي
function markRowPendingAction(saleId, label) {
    const row = document.querySelector(`tr[data-sale-id="${saleId}"]`);
    if (!row) return;
    const actionsCell = row.querySelector('td:last-child');
    if (actionsCell) {
        actionsCell.querySelectorAll('button').forEach(b => b.disabled = true);
    }
    const paymentCell = row.children[4];
    if (paymentCell && !paymentCell.querySelector('.pending-action-badge')) {
        const badge = document.createElement('span');
        badge.className = 'badge mt-1 d-block pending-action-badge';
        badge.style.cssText = 'background: var(--surface-app); border: 1px dashed #0369a1; color: #0369a1;';
        badge.innerHTML = `<i class="bi bi-clock-history me-1"></i> ${label}`;
        paymentCell.appendChild(badge);
    }
}

function cancelSale(id) {
    if (!confirm('هل أنت متأكد من إلغاء هذه الفاتورة؟ سيتم إرجاع المنتجات للمخزون.')) return;

    if (!navigator.onLine) {
        // ─── أوفلاين: احفظ طلب الإلغاء محليًا وهيترفع تلقائيًا بعدين ───────────
        addPendingAction({ type: 'cancel', sale_id: id, items: [], reason: null }).then(() => {
            markRowPendingAction(id, 'قيد الإلغاء — أوفلاين');
            updatePendingActionsBar();
            requestActionsSync();
        }).catch(err => alert('خطأ في حفظ الطلب محليًا: ' + err));
        return;
    }

    fetch('<?= BASE_URL ?>/sales/cancel.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'sale_id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
        else alert(data.message || 'حدث خطأ');
    })
    .catch(() => {
        // فشل الاتصال فجأة رغم إن navigator.onLine كان true — احفظ محليًا برضو
        addPendingAction({ type: 'cancel', sale_id: id, items: [], reason: null }).then(() => {
            markRowPendingAction(id, 'قيد الإلغاء — أوفلاين');
            updatePendingActionsBar();
        });
    });
}

function handleReturnClick(id) {
    if (navigator.onLine) {
        window.location.href = '<?= BASE_URL ?>/sales/return.php?id=' + id;
        return;
    }
    // ─── أوفلاين: هات بيانات الفاتورة من كاش الـ Service Worker (لو اتفتحت
    // قبل كده وهي أونلاين) وابني فورم الإرجاع من غير ما نعدي على السيرفر ────
    fetch('<?= BASE_URL ?>/sales/get_sale_json.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('مفيش بيانات محفوظة لهذه الفاتورة عشان تقدر ترجعها أوفلاين. افتح الفاتورة مرة وانت متصل بالإنترنت الأول.');
                return;
            }
            openOfflineReturnModal(data.sale, data.items);
        })
        .catch(() => alert('مفيش بيانات محفوظة لهذه الفاتورة عشان تقدر ترجعها أوفلاين.'));
}

function openOfflineReturnModal(sale, items) {
    offlineReturnContext = { saleId: sale.id, invoiceNumber: sale.invoice_number, items };
    const box = document.getElementById('offlineReturnItemsBox');
    box.innerHTML = '';

    const table = document.createElement('table');
    table.className = 'table table-bordered align-middle table-sm';
    table.innerHTML = `<thead class="table-light"><tr><th>الصنف</th><th>المباع</th><th>مرتجع سابقًا</th><th style="width:110px;">الكمية دلوقتي</th></tr></thead>`;
    const tbody = document.createElement('tbody');

    items.forEach(it => {
        const remaining = it.quantity - it.already_returned;
        const tr = document.createElement('tr');
        const label = it.product_name_ar + ((it.color_ar || it.size) ? ` [${it.color_ar || ''} ${it.size || ''}]` : '');
        tr.innerHTML = `
            <td><strong>${label}</strong></td>
            <td class="num">${it.quantity}</td>
            <td class="num">${it.already_returned}</td>
            <td>${remaining > 0
                ? `<input type="number" step="1" min="0" max="${remaining}" class="form-control form-control-sm offline-return-qty" data-sale-item-id="${it.sale_item_id}" placeholder="0">`
                : `<span class="badge status-inactive">اترجع بالكامل</span>`}</td>
        `;
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    box.appendChild(table);

    document.getElementById('offlineReturnReason').value = '';
    new bootstrap.Modal(document.getElementById('offlineReturnModal')).show();
}

function submitOfflineReturn() {
    const reason = document.getElementById('offlineReturnReason').value.trim();
    if (!reason) { alert('يرجى كتابة سبب الإرجاع.'); return; }

    const qtyInputs = document.querySelectorAll('.offline-return-qty');
    const items = [];
    qtyInputs.forEach(inp => {
        const qty = parseFloat(inp.value) || 0;
        if (qty > 0) items.push({ sale_item_id: parseInt(inp.dataset.saleItemId), qty });
    });

    if (items.length === 0) { alert('يرجى تحديد كمية صنف واحد على الأقل لإرجاعه.'); return; }

    addPendingAction({
        type: 'return',
        sale_id: offlineReturnContext.saleId,
        items,
        reason
    }).then(() => {
        bootstrap.Modal.getInstance(document.getElementById('offlineReturnModal')).hide();
        markRowPendingAction(offlineReturnContext.saleId, 'قيد الإرجاع — أوفلاين');
        updatePendingActionsBar();
        requestActionsSync();
    }).catch(err => alert('خطأ في حفظ المرتجع محليًا: ' + err));
}

function requestActionsSync() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(reg => {
            if ('sync' in reg) reg.sync.register('sync-pending-actions').catch(() => {});
        });
    }
}

async function manualSyncActions() {
    const pending = await getPendingActions();
    if (pending.length === 0) { alert('لا توجد طلبات معلقة.'); return; }

    const btn = document.getElementById('syncActionsBtn');
    btn.disabled = true;
    btn.textContent = 'جارٍ الرفع...';

    try {
        const res = await fetch('<?= BASE_URL ?>/sales/sync_offline_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ actions: pending })
        });
        const data = await res.json();
        if (data.success) {
            for (const id of data.synced_ids) await removePendingAction(id);
            alert(`تمت مزامنة ${data.synced_ids.length} طلب بنجاح.${data.failed > 0 ? ` (${data.failed} فشل)` : ''}`);
            location.reload();
        } else {
            alert('فشلت المزامنة. تأكد من الاتصال بالإنترنت.');
        }
    } catch (e) {
        alert('لا يوجد اتصال بالإنترنت حاليًا.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'رفع الآن';
        updatePendingActionsBar();
    }
}

async function updatePendingActionsBar() {
    const bar = document.getElementById('pendingActionsBar');
    const text = document.getElementById('pendingActionsBarText');
    const syncBtn = document.getElementById('syncActionsBtn');
    const count = await countPendingActions();

    if (count === 0) { bar.style.display = 'none'; return; }

    bar.style.display = 'block';
    if (!navigator.onLine) {
        text.textContent = `وضع بدون اتصال — ${count} طلب إلغاء/إرجاع معلق سيترفع عند عودة الإنترنت`;
        syncBtn.style.display = 'none';
    } else {
        text.textContent = `عاد الاتصال — ${count} طلب إلغاء/إرجاع معلق بانتظار الرفع`;
        syncBtn.style.display = 'inline-block';
        manualSyncActions();
    }
}

// خزّن بيانات كل فاتورة ظاهرة في الصفحة في كاش الـ Service Worker وإحنا أونلاين،
// عشان تبقى متاحة للإرجاع أوفلاين بعدين حتى لو النت قطع فجأة
function prefetchSalesForOffline() {
    if (!navigator.onLine) return;
    document.querySelectorAll('[data-sale-id]').forEach(el => {
        const id = el.dataset.saleId;
        fetch('<?= BASE_URL ?>/sales/get_sale_json.php?id=' + id).catch(() => {});
    });
}

/**
 * فواتير البيع اللي اتعملت أوفلاين ولسه ماوصلتش للسيرفر (مخزّنة في
 * IndexedDB بس) — كانت مش بتظهر في القائمة خالص لحد ما تتزامن، فالكاشير
 * يحس إن البيع "ضاع". دلوقتي بتتحط فوق القائمة فورًا بشارة واضحة "قيد
 * المزامنة" لحد ما ترفع فعليًا للسيرفر وتاخد رقم فاتورة حقيقي.
 */
async function renderPendingSalesRows() {
    const tbody = document.querySelector('.table tbody');
    if (!tbody) return;

    // امسح أي صفوف أوفلاين قديمة اتحطت قبل كده عشان نعيد رسمها من الأول
    tbody.querySelectorAll('tr[data-pending-sale]').forEach(tr => tr.remove());

    const pending = await getPendingSales();
    if (pending.length === 0) return;

    const posUser = JSON.parse(localStorage.getItem('pos_user') || '{}');
    const payLabels = { cash: ['كاش', 'bi-cash'], card: ['بطاقة', 'bi-credit-card'], transfer: ['تحويل', 'bi-bank'] };

    // لو الجدول فاضي (رسالة "لا توجد فواتير") امسحها الأول
    const emptyRow = tbody.querySelector('td[colspan]');
    if (emptyRow) emptyRow.closest('tr').remove();

    pending.slice().reverse().forEach(p => {
        const total = Math.max(0, p.items.reduce((s, i) => s + i.qty * i.unit_price, 0) - (p.discount || 0));
        const [payLabel, payIcon] = payLabels[p.payment_method] || ['—', 'bi-question'];
        const dateStr = new Date(p.created_at).toLocaleString('ar-AE', { hour12: true });

        const hasError = !!p.last_error;
        const statusBadge = hasError
            ? `<span class="badge mt-1 d-block" style="background: #fee2e2; color: #991b1b; border: 1px dashed #dc2626;" title="${p.last_error}"><i class="bi bi-exclamation-triangle me-1"></i> فشلت المزامنة: ${p.last_error}</span>`
            : `<span class="badge mt-1 d-block" style="background: #fef3c7; color: #92400e; border: 1px dashed #d97706;"><i class="bi bi-cloud-slash me-1"></i> قيد المزامنة — لسه ما وصلتش للسيرفر</span>`;

        const tr = document.createElement('tr');
        tr.dataset.pendingSale = '1';
        tr.style.background = hasError ? '#FEF2F2' : '#FFFBEB';
        tr.innerHTML = `
            <td><span dir="ltr" class="num fw-bold text-warning">#OFFLINE-${p.local_id}</span></td>
            <td class="num text-subtle">${dateStr}</td>
            <td>${posUser.name_ar || '—'}</td>
            <td class="num"><span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">${p.items.length} صنف</span></td>
            <td>
                <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><i class="bi ${payIcon} me-1"></i> ${payLabel}</span>
                ${statusBadge}
            </td>
            <td class="text-end num fw-bold text-dark fs-6">AED ${total.toFixed(2)}</td>
            <td class="text-center">
                <button onclick="discardPendingSale(${p.local_id})" class="btn btn-sm btn-outline-danger w-100">
                    <i class="bi bi-trash me-1"></i> إلغاء (لسه محليًا)
                </button>
            </td>
        `;
        tbody.insertBefore(tr, tbody.firstChild);
    });
}

function discardPendingSale(localId) {
    if (!confirm('هتمسح الفاتورة دي نهائيًا من غير ما ترفعها للسيرفر خالص. متأكد؟')) return;
    removePendingSale(localId).then(() => renderPendingSalesRows());
}

// نفس منطق المزامنة اليدوية بتاع شاشة الـ POS، بس هنا كـ fallback لو المستخدم
// قاعد في شاشة الفواتير وقت ما النت يرجع (مش لازم يفتح شاشة الـ POS الأول)
async function syncPendingSalesFromHere() {
    const pending = await getPendingSales();
    if (pending.length === 0) return;
    try {
        const res = await fetch('<?= BASE_URL ?>/pos/sync_offline.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ sales: pending })
        });
        const data = await res.json();
        if (data.success) {
            for (const id of data.synced_ids) await removePendingSale(id);
            for (const r of (data.failed_reasons || [])) await markPendingSaleError(r.local_id, r.reason);
            renderPendingSalesRows();
            if (data.synced_ids.length > 0) location.reload();
        }
    } catch (e) { /* لسه مفيش نت فعليًا، هيتحاول تاني بعدين */ }
}

window.addEventListener('online', () => {
    updatePendingActionsBar();
    renderPendingSalesRows();
    syncPendingSalesFromHere();
});
window.addEventListener('offline', updatePendingActionsBar);
document.addEventListener('DOMContentLoaded', () => {
    updatePendingActionsBar();
    prefetchSalesForOffline();
    renderPendingSalesRows();
    if (navigator.onLine) syncPendingSalesFromHere();
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
