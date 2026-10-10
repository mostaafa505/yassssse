<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'manage_exhibitions');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$action = $_REQUEST['action'] ?? '';
$exhibitionId = intval($_REQUEST['exhibition_id'] ?? 0);

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.']);
        exit;
    }
    redirect("exhibitions/view.php?id=$exhibitionId&error=1#expenses");
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? 'other';
    $title = trim($_POST['title'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'transfer']) ? $_POST['payment_method'] : 'cash';
    $receiptRef = trim($_POST['receipt_ref'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($exhibitionId <= 0 || $title === '' || $amount <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'يرجى ملء كافة الحقول الأساسية وتحديد مبلغ صحيح']);
            exit;
        }
        redirect("exhibitions/view.php?id=$exhibitionId&error=1#expenses");
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO exhibition_expenses (exhibition_id, category, title, amount, expense_date, payment_method, receipt_ref, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $exhibitionId,
            $category,
            $title,
            $amount,
            $expenseDate,
            $paymentMethod,
            $receiptRef,
            $notes,
            $_SESSION['user_id'] ?? null
        ]);
        $expId = $pdo->lastInsertId();

        try {
            $stmtTreasury = $pdo->prepare("
                INSERT INTO treasury_transactions (type, title, amount, payment_method, category, reference_type, reference_id, transaction_date, notes, created_by)
                VALUES ('expense', ?, ?, ?, ?, 'exhibition_expense', ?, ?, ?, ?)
            ");
            $stmtTreasury->execute([
                'مصروف معرض: ' . $title,
                $amount,
                $paymentMethod,
                $category,
                $expId,
                $expenseDate,
                $notes,
                $_SESSION['user_id'] ?? null
            ]);
        } catch (Throwable $t) { /* جدول الصندوق لسه مش متعمل الميجريشن بتاعه، تجاهل بصمت */ }

        logAudit($pdo, 'add_exhibition_expense', 'exhibition_expense', $expId, null, [
            'exhibition_id' => $exhibitionId,
            'category' => $category,
            'title' => $title,
            'amount' => $amount
        ]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $expId]);
            exit;
        }
        redirect("exhibitions/index.php?id=$exhibitionId&exp_added=1");
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        redirect("exhibitions/index.php?id=$exhibitionId&error=" . urlencode($e->getMessage()));
    }
}

if ($action === 'delete') {
    if (!verifyCsrf($_REQUEST['csrf_token'] ?? null)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.']);
            exit;
        }
        redirect("exhibitions/index.php?id=$exhibitionId");
    }
    $expenseId = intval($_REQUEST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM exhibition_expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch();

        if ($expense) {
            $exhibitionId = (int)$expense['exhibition_id'];
            $pdo->prepare("DELETE FROM exhibition_expenses WHERE id = ?")->execute([$expenseId]);
            try {
                $pdo->prepare("DELETE FROM treasury_transactions WHERE reference_type = 'exhibition_expense' AND reference_id = ?")->execute([$expenseId]);
            } catch (Throwable $t) { /* جدول الصندوق لسه مش متعمل الميجريشن بتاعه، تجاهل بصمت */ }
            logAudit($pdo, 'delete_exhibition_expense', 'exhibition_expense', $expenseId, $expense, null);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        redirect("exhibitions/index.php?id=$exhibitionId&exp_deleted=1");
    } catch (Exception $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        redirect("exhibitions/index.php?id=$exhibitionId");
    }
}

redirect('exhibitions/');
