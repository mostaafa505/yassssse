<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('audit');
$from = parse_date($_GET['from'] ?? '') ?: ''; $to = parse_date($_GET['to'] ?? '') ?: ''; $action = get_str('action'); $entity = get_str('entity'); $q = get_str('q');
$where = '1=1'; $params = [];
if ($from) { $where .= ' AND created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to)   { $where .= ' AND created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($action !== '') { $where .= ' AND action = ?'; $params[] = $action; }
if ($entity !== '') { $where .= ' AND entity = ?'; $params[] = $entity; }
if ($q !== '') { $where .= ' AND (details LIKE ? OR user_name LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
$perPage = 50; $page = page_num();
$total = (int)db_val("SELECT COUNT(*) FROM audit_logs WHERE $where", $params);
$rows = db_all("SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$actions = db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'); $entities = db_all('SELECT DISTINCT entity FROM audit_logs ORDER BY entity');
$actLabels = ['create' => 'إنشاء', 'update' => 'تعديل', 'delete' => 'حذف', 'cancel' => 'إلغاء', 'login' => 'دخول', 'logout' => 'خروج'];
page_start('سجل العمليات', 'audit');
?>
<div class="filters"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-2"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-2"><label class="form-label">العملية</label><select class="form-select" name="action"><option value="">— الكل —</option><?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>"<?= $action === $a['action'] ? ' selected' : '' ?>><?= e($actLabels[$a['action']] ?? $a['action']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">الكيان</label><select class="form-select" name="entity"><option value="">— الكل —</option><?php foreach ($entities as $a): ?><option<?= $entity === $a['entity'] ? ' selected' : '' ?>><?= e($a['entity']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>"></div>
  <div class="col-md-1"><button class="btn btn-brand w-100"><i class="bi bi-search"></i></button></div>
</form></div>
<div class="cardx"><div class="head"><i class="bi bi-clock-history"></i> سجل العمليات <span class="text-muted small fw-normal">(<?= $total ?>)</span></div>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>الكيان</th><th>التفاصيل</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="small text-nowrap"><?= e($r['created_at']) ?></td><td><?= e((string)$r['user_name']) ?></td><td><?= badge($actLabels[$r['action']] ?? $r['action'], in_array($r['action'], ['delete', 'cancel'], true) ? 'danger' : 'info') ?></td><td class="small"><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td><td class="small"><?= e((string)$r['details']) ?></td><td class="small text-muted" dir="ltr"><?= e((string)$r['ip_address']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد سجلات</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($total, $perPage, $page) ?>
<?php page_end();
