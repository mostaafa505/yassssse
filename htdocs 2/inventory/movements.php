<?php
$pageTitle = 'سجل حركات المخزون / Stock Movements';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_inventory');

$stmt = $pdo->query("
    SELECT m.*, p.name_ar, p.name_en, p.sku,
    pv.color_ar, pv.size,
    u.name_ar as user_name
    FROM inventory_movements m
    LEFT JOIN products p ON m.product_id = p.id
    LEFT JOIN product_variants pv ON m.variant_id = pv.id
    LEFT JOIN users u ON m.user_id = u.id
    ORDER BY m.id DESC
    LIMIT 100
");
$movements = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">سجل حركات المخزون <span class="text-muted fs-6">/ Inventory Log</span></h4>
        <p class="text-muted small mb-0">تتبع جميع عمليات الدخول والخروج والبيع والتحويل</p>
    </div>
    <a href="<?= BASE_URL ?>/inventory/" class="btn btn-outline-secondary rounded-pill px-3">
        <i class="bi bi-arrow-right me-1"></i> العودة للمخزون
    </a>
</div>

<div class="panel border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>التاريخ والوقت</th>
                    <th>المنتج</th>
                    <th>الخيار (اللون/المقاس)</th>
                    <th>نوع الحركة</th>
                    <th>الكمية</th>
                    <th>المسؤول</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">لا توجد حركات مسجلة حتى الآن.</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                        <tr>
                            <td><?= date('Y-m-d h:i A', strtotime($m['created_at'])) ?></td>
                            <td>
                                <strong><?= e($m['name_ar']) ?></strong>
                                <div class="small text-muted">SKU: <?= e($m['sku'] ?: '—') ?></div>
                            </td>
                            <td>
                                <?= ($m['color_ar'] || $m['size']) ? '<span class="badge bg-light text-dark border">' . e($m['color_ar']) . ' ' . e($m['size']) . '</span>' : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?php if ($m['movement_type'] === 'sale'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-cart-dash me-1"></i> مبيعات (خروج)</span>
                                <?php elseif ($m['movement_type'] === 'in'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-box-arrow-in-down me-1"></i> توريد (دخول)</span>
                                <?php elseif ($m['movement_type'] === 'adjustment'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-sliders me-1"></i> تسوية جرد</span>
                                <?php elseif ($m['movement_type'] === 'return'): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-arrow-return-left me-1"></i> مرتجع</span>
                                <?php elseif (in_array($m['movement_type'], ['transfer_in', 'transfer_out'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="bi bi-arrow-left-right me-1"></i> تحويل
                                        <?php if ($m['from_location'] && $m['to_location']): ?>
                                            (<?= $m['from_location'] === 'warehouse' ? 'المخزن' : 'المعرض' ?> ← <?= $m['to_location'] === 'warehouse' ? 'المخزن' : 'المعرض' ?>)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border"><?= e($m['movement_type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $isPositive = in_array($m['movement_type'], ['in', 'return']); ?>
                                <?php $isNeutral = in_array($m['movement_type'], ['transfer_in', 'transfer_out', 'adjustment']); ?>
                                <strong class="<?= $isNeutral ? 'text-primary' : ($isPositive ? 'text-success' : 'text-danger') ?>">
                                    <?php if ($isNeutral): ?><?= $m['quantity'] ?><?php else: ?><?= $isPositive ? '+' : '-' ?><?= $m['quantity'] ?><?php endif; ?>
                                </strong>
                            </td>
                            <td><?= e($m['user_name'] ?: 'System') ?></td>
                            <td class="small text-muted"><?= e($m['notes'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
