<?php
$pageTitle = 'سجل العمليات والرقابة / Audit Log';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin'], 'view_audit_log');

$stmt = $pdo->query("
    SELECT a.*, u.name_ar as real_user_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.id DESC
    LIMIT 150
");
$logs = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">سجل العمليات والرقابة</h1>
        <p class="text-subtle mb-0">تتبع تلقائي لجميع التعديلات الحساسة والأسعار والمخزون والمستخدمين</p>
    </div>
</div>

<!-- Audit Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0">التاريخ والوقت</th>
                    <th class="text-caption text-subtle border-bottom-0">المستخدم</th>
                    <th class="text-caption text-subtle border-bottom-0">نوع الإجراء / Action</th>
                    <th class="text-caption text-subtle border-bottom-0" style="width: 35%;">تفاصيل العملية (الوصف)</th>
                    <th class="text-caption text-subtle border-bottom-0">الرقم المرجعي</th>
                    <th class="text-center text-caption text-subtle border-bottom-0">عرض</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-subtle"><i class="bi bi-clock-history fs-1 d-block mb-3 text-tertiary"></i>لا توجد سجلات مسجلة بعد.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-subtle num"><?= date('Y-m-d h:i:s A', strtotime($log['created_at'])) ?></td>
                            <td><strong class="text-dark"><?= e($log['real_user_name'] ?: ($log['user_name'] ?: 'System')) ?></strong></td>
                            <td>
                                <?php if ($log['action'] === 'create_sale'): ?>
                                    <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2);"><i class="bi bi-cart-check me-1"></i> إتمام بيع</span>
                                <?php elseif ($log['action'] === 'create'): ?>
                                    <span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2);"><i class="bi bi-plus-circle me-1"></i> إضافة</span>
                                <?php elseif ($log['action'] === 'update'): ?>
                                    <span class="badge" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2);"><i class="bi bi-pencil me-1"></i> تعديل</span>
                                <?php elseif ($log['action'] === 'delete'): ?>
                                    <span class="badge" style="background: var(--intent-danger-bg); color: var(--intent-danger); border: 1px solid rgba(239,68,68,0.2);"><i class="bi bi-trash me-1"></i> حذف</span>
                                <?php elseif ($log['action'] === 'receive_stock'): ?>
                                    <span class="badge" style="background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0;"><i class="bi bi-box-arrow-in-down me-1"></i> استلام مخزون</span>
                                <?php elseif ($log['action'] === 'stocktake'): ?>
                                    <span class="badge" style="background: var(--slate-800); color: white;"><i class="bi bi-clipboard-check me-1"></i> جرد</span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><?= e($log['action']) ?></span>
                                <?php endif; ?>
                            </td>
<?php
                            $desc = '';
                            $oldV = json_decode($log['old_value'] ?: '{}', true) ?: [];
                            $newV = json_decode($log['new_value'] ?: '{}', true) ?: [];
                            
                            if ($log['action'] === 'update' && $log['entity_type'] === 'product') {
                                if (($oldV['retail_price'] ?? '') != ($newV['retail_price'] ?? '')) {
                                    $desc = "تعديل سعر منتج من " . ($oldV['retail_price'] ?? 0) . " إلى " . ($newV['retail_price'] ?? 0) . " درهم";
                                } else {
                                    $desc = "تعديل بيانات المنتج";
                                }
                            } elseif ($log['action'] === 'create_sale') {
                                $desc = "إتمام فاتورة مبيعات (#" . ($newV['invoice_number'] ?? '') . ") بخصم " . ($newV['discount'] ?? 0) . " درهم";
                            } elseif ($log['action'] === 'cancel_sale') {
                                $inv = $newV['invoice_number'] ?? '#' . $log['entity_id'];
                                $refund = $newV['refund_amount'] ?? ($oldV['total'] ?? 0);
                                $qtyRet = $newV['total_qty_returned'] ?? ($newV['items_returned'] ?? 0);
                                $desc = "إلغاء ومرتجع فاتورة ($inv) بقيمة " . number_format($refund, 2) . " درهم واستعادة ($qtyRet) قطع للمخزون";
                            } elseif ($log['action'] === 'receive_stock') {
                                $desc = "إضافة / تعديل كميات مخزون المنتج";
                            } elseif ($log['action'] === 'toggle_offer') {
                                $desc = "تفعيل أو إيقاف عرض ترويجي";
                            } elseif ($log['entity_type'] === 'user') {
                                $desc = "تعديل صلاحيات أو بيانات مستخدم";
                            } else {
                                $desc = "تحديث في " . e($log['entity_type']);
                            }
                            ?>
                            <td><span class="text-dark fw-medium"><?= e($desc) ?></span></td>
                            <td><span class="badge num" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><?= e($log['entity_type']) ?> #<?= $log['entity_id'] ?></span></td>
                            <td class="text-center">
                                <!-- بيانات السجل بتتحط في data-log (متعدّاة على e() زي أي مخرج HTML عادي)
                                     مش جوه onclick='...(<?= '' ?>)' زي ما كانت قبل كده — لأن onclick كان
                                     بيتحد بعلامة اقتباس مفردة، وأي بيانات فيها علامة اقتباس مفردة حقيقية
                                     (سبب مرتجع، اسم عميل، أي نص حر) كانت بتقفل الـ attribute بدري وتكسر
                                     الصفحة كلها — ثغرة حقن HTML حقيقية اتكتشفت أثناء اختبار إصلاح F-15 -->
                                <button type="button" class="btn btn-sm btn-light px-3 js-audit-details" data-log='<?= e(json_encode($log, JSON_UNESCAPED_UNICODE)) ?>'>
                                    <i class="bi bi-eye me-1"></i> عرض التفاصيل
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
</div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="auditDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; box-shadow: var(--shadow-modal);">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h6 class="title-medium text-dark mb-0" id="auditModalTitle">تفاصيل العملية</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <label class="form-label text-caption mb-2">القيم القديمة (Before):</label>
                    <div class="num p-3 rounded-3 mb-0" id="auditOldVal" style="background: var(--surface-app); border: 1px solid var(--border-strong); max-height: 250px; overflow-y: auto; color: var(--text-secondary); font-size: 0.85rem;">—</div>
                </div>
                <div>
                    <label class="form-label text-caption mb-2">القيم الجديدة / المسجلة (After):</label>
                    <div class="num p-3 rounded-3 mb-0" id="auditNewVal" style="background: var(--intent-success-bg); border: 1px solid rgba(16,185,129,0.2); color: var(--intent-success); max-height: 250px; overflow-y: auto; font-size: 0.85rem;">—</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showAuditModal(log) {
    document.getElementById('auditModalTitle').innerText = `العملية: ${log.action} على ${log.entity_type} (#${log.entity_id})`;
    
    // سجل العمليات بيعرض نصوص حرة اتكتبت من مستخدمين (سبب مرتجع، ملاحظة
    // مصروف، اسم منتج...) — لازم كل حاجة تتعرض هنا تتعدّى على escapeHtml()
    // الأول قبل ما تتحط جوه innerHTML، وإلا أي حد يقدر يحط سكريبت في حقل
    // نصي عادي (زي سبب الإرجاع) وينفّذه في متصفح الأدمن أول ما يفتح تفاصيل
    // العملية دي — ثغرة XSS مخزّنة حقيقية اتكتشفت في تدقيق F-15.
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str === null || str === undefined ? '' : String(str);
        return div.innerHTML;
    }

    function formatValue(val) {
        if (!val || val === 'null') return '<span class="text-subtle">لا توجد تفاصيل</span>';
        try {
            const parsed = JSON.parse(val);
            if (typeof parsed === 'object' && parsed !== null) {
                let html = `<div class="table-responsive border-0">
<table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;"><tbody>`;
                for (const [k, v] of Object.entries(parsed)) {
                    let displayV;
                    if (Array.isArray(v)) {
                        displayV = v.map(item => escapeHtml(item)).join('<br>');
                    } else if (typeof v === 'object' && v !== null) {
                        displayV = escapeHtml(JSON.stringify(v));
                    } else {
                        displayV = v !== null ? escapeHtml(v) : '—';
                    }
                    html += `<tr><td class="text-subtle bg-light" style="width: 30%;">${escapeHtml(k)}</td><td class="fw-medium">${displayV}</td></tr>`;
                }
                html += `</tbody></table>
</div>`;
                return html;
            }
            return escapeHtml(val);
        } catch (e) {
            return escapeHtml(val);
        }
    }

    document.getElementById('auditOldVal').innerHTML = formatValue(log.old_value);
    document.getElementById('auditNewVal').innerHTML = formatValue(log.new_value);
    new bootstrap.Modal(document.getElementById('auditDetailModal')).show();
}

// كل زرار "عرض التفاصيل" بياخد بيانات السجل من data-log (متعدّاة على e()
// في PHP)، مش من inline onclick زي ما كان قبل كده — نفس السبب الموضح في
// تعليق الـ PHP فوق الزرار
document.querySelectorAll('.js-audit-details').forEach(btn => {
    btn.addEventListener('click', () => {
        try {
            const log = JSON.parse(btn.dataset.log);
            showAuditModal(log);
        } catch (e) {
            alert('تعذر عرض تفاصيل هذه العملية.');
        }
    });
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
