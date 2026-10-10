<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'manage_treasury');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$action = $_REQUEST['action'] ?? '';

function treasuryFail(bool $isAjax, string $message, string $redirectTo): void {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    redirect($redirectTo);
}

if (in_array($action, ['add_funding', 'add_expense'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    treasuryFail($isAjax, 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.', 'treasury/index.php?error=1');
}

if ($action === 'add_funding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');

    if ($title === '' || $amount <= 0) {
        treasuryFail($isAjax, 'يرجى كتابة بيان الحركة وتحديد مبلغ صحيح', 'treasury/index.php?error=1');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO treasury_transactions (type, title, amount, payment_method, transaction_date, created_by)
            VALUES ('funding', ?, ?, 'cash', ?, ?)
        ");
        $stmt->execute([$title, $amount, $transactionDate, $_SESSION['user_id'] ?? null]);
        $newId = $pdo->lastInsertId();

        logAudit($pdo, 'add_treasury_funding', 'treasury_transaction', $newId, null, [
            'title' => $title, 'amount' => $amount
        ]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $newId]);
            exit;
        }
        redirect('treasury/index.php?added=1');
    } catch (Exception $e) {
        treasuryFail($isAjax, $e->getMessage(), 'treasury/index.php?error=1');
    }
}

if ($action === 'add_expense' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? 'other';
    $title = trim($_POST['title'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'transfer'], true) ? $_POST['payment_method'] : 'cash';
    $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');

    if ($title === '' || $amount <= 0) {
        treasuryFail($isAjax, 'يرجى كتابة بيان المصروف وتحديد مبلغ صحيح', 'treasury/index.php?error=1');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO treasury_transactions (type, title, amount, payment_method, category, transaction_date, created_by)
            VALUES ('expense', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $amount, $paymentMethod, $category, $transactionDate, $_SESSION['user_id'] ?? null]);
        $newId = $pdo->lastInsertId();

        logAudit($pdo, 'add_treasury_expense', 'treasury_transaction', $newId, null, [
            'category' => $category, 'title' => $title, 'amount' => $amount
        ]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $newId]);
            exit;
        }
        redirect('treasury/index.php?added=1');
    } catch (Exception $e) {
        treasuryFail($isAjax, $e->getMessage(), 'treasury/index.php?error=1');
    }
}

if ($action === 'delete') {
    if (!verifyCsrf($_REQUEST['csrf_token'] ?? null)) {
        treasuryFail($isAjax, 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.', 'treasury/index.php');
    }
    $transactionId = intval($_REQUEST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM treasury_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            // الحركات المرتبطة بمصدر (مصروف معرض أو فاتورة مشتريات) لازم تتحذف من
            // مصدرها هي، مش من هنا مباشرة، عشان السجلين مايفترقوش عن بعض.
            if (!empty($transaction['reference_type'])) {
                treasuryFail($isAjax, 'هذه الحركة مرتبطة بمصدر آخر (مصروف معرض أو فاتورة مشتريات) — احذفها من صفحة المصدر نفسها.', 'treasury/index.php?error=1');
            }
            $pdo->prepare("DELETE FROM treasury_transactions WHERE id = ?")->execute([$transactionId]);
            logAudit($pdo, 'delete_treasury_transaction', 'treasury_transaction', $transactionId, $transaction, null);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        redirect('treasury/index.php?deleted=1');
    } catch (Exception $e) {
        treasuryFail($isAjax, $e->getMessage(), 'treasury/index.php');
    }
}

redirect('treasury/');
