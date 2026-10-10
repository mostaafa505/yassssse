<?php
$pageTitle = 'تعديل بيانات المعرض / Edit Exhibition';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_exhibitions');

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM exhibitions WHERE id = ?");
$stmt->execute([$id]);
$exhibition = $stmt->fetch();

if (!$exhibition) {
    die('<div class="p-5 text-center"><h3>المعرض غير موجود</h3><a href="' . BASE_URL . '/exhibitions/">العودة</a></div>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $errors[] = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $budget = floatval($_POST['budget'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active', 'upcoming', 'completed']) ? $_POST['status'] : 'active';
    $notes = trim($_POST['notes'] ?? '');

    if ($title === '') {
        $errors[] = 'يرجى إدخال اسم المعرض أو الفعالية.';
    }
    if ($startDate === '' || $endDate === '') {
        $errors[] = 'يرجى تحديد تاريخ البداية وتاريخ النهاية.';
    } elseif ($startDate > $endDate) {
        $errors[] = 'تاريخ نهاية المعرض لا يمكن أن يكون قبل تاريخ البداية.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE exhibitions 
                SET title = ?, location = ?, start_date = ?, end_date = ?, status = ?, budget = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $title,
                $location,
                $startDate,
                $endDate,
                $status,
                $budget,
                $notes,
                $id
            ]);

            logAudit($pdo, 'update_exhibition', 'exhibition', $id, $exhibition, [
                'title' => $title,
                'location' => $location,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'budget' => $budget
            ]);

            redirect("exhibitions/view.php?id=$id&updated=1");
        } catch (Exception $e) {
            $errors[] = 'حدث خطأ أثناء التحديث: ' . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">تعديل بيانات المعرض</h1>
        <p class="text-subtle mb-0"><?= e($exhibition['title']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/exhibitions/view.php?id=<?= $id ?>" class="btn btn-outline-secondary px-3 py-2 fw-bold">
            <i class="bi bi-arrow-right me-1"></i> العودة للمشاهدة
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="panel">
            <div class="panel-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">اسم المعرض أو الفعالية <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-lg" required value="<?= e($_POST['title'] ?? $exhibition['title']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">المقر / الجناح / المدينة</label>
                            <input type="text" name="location" class="form-control" value="<?= e($_POST['location'] ?? $exhibition['location']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">حالة المعرض</label>
                            <select name="status" class="form-select">
                                <?php $curStatus = $_POST['status'] ?? $exhibition['status']; ?>
                                <option value="active" <?= $curStatus === 'active' ? 'selected' : '' ?>>🟢 نشط وجاري الآن</option>
                                <option value="upcoming" <?= $curStatus === 'upcoming' ? 'selected' : '' ?>>قادم (مستقبلي)</option>
                                <option value="completed" <?= $curStatus === 'completed' ? 'selected' : '' ?>>⚪ منتهي</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاريخ البداية <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="form-control" required value="<?= e($_POST['start_date'] ?? $exhibition['start_date']) ?>" onchange="calcDuration()">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاريخ النهاية <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="form-control" required value="<?= e($_POST['end_date'] ?? $exhibition['end_date']) ?>" onchange="calcDuration()">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">مدة المعرض</label>
                            <div class="input-group">
                                <input type="text" id="duration_display" class="form-control bg-light text-center fw-bold" readonly>
                                <span class="input-group-text"><i class="bi bi-calendar-range"></i></span>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">الميزانية المرصودة (درهم)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" name="budget" class="form-control" value="<?= e($_POST['budget'] ?? $exhibition['budget']) ?>">
                                <span class="input-group-text">درهم AED</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات وأهداف المعرض</label>
                            <textarea name="notes" class="form-control" rows="3"><?= e($_POST['notes'] ?? $exhibition['notes']) ?></textarea>
                        </div>

                        <div class="col-12 mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                            <a href="<?= BASE_URL ?>/exhibitions/view.php?id=<?= $id ?>" class="btn btn-light px-4">إلغاء</a>
                            <button type="submit" class="btn btn-primary px-5 fw-bold">
                                <i class="bi bi-check-lg me-1"></i> حفظ التعديلات
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function calcDuration() {
    const s = document.getElementById('start_date').value;
    const e = document.getElementById('end_date').value;
    if (s && e) {
        const d1 = new Date(s);
        const d2 = new Date(e);
        const diffTime = d2 - d1;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        if (diffDays > 0) {
            document.getElementById('duration_display').value = diffDays + ' يوم / أيام';
        } else {
            document.getElementById('duration_display').value = 'خطأ في التاريخ';
        }
    }
}
document.addEventListener('DOMContentLoaded', calcDuration);
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
