<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'manage_treasury');

$action = $_REQUEST['action'] ?? '';

if ($action === 'delete') {
    if (!verifyCsrf($_REQUEST['csrf_token'] ?? null)) {
        redirect('purchases/index.php?error=1');
    }
    $invoiceId = intval($_REQUEST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if ($invoice) {
            $pdo->beginTransaction();
            // purchase_invoice_items بتتحذف تلقائيًا (ON DELETE CASCADE)
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id = ?")->execute([$invoiceId]);
            $pdo->prepare("DELETE FROM treasury_transactions WHERE reference_type = 'purchase_invoice' AND reference_id = ?")->execute([$invoiceId]);
            logAudit($pdo, 'delete_purchase_invoice', 'purchase_invoice', $invoiceId, $invoice, null);
            $pdo->commit();
        }

        redirect('purchases/index.php?deleted=1');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirect('purchases/index.php?error=1');
    }
}

redirect('purchases/');
