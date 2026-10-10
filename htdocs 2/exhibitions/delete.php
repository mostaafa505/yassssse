<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin']);

$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM exhibitions WHERE id = ?");
        $stmt->execute([$id]);
        $ex = $stmt->fetch();

        if ($ex) {
            $pdo->prepare("DELETE FROM exhibitions WHERE id = ?")->execute([$id]);
            logAudit($pdo, 'delete_exhibition', 'exhibition', $id, $ex, null);
        }
    } catch (Exception $e) {
        // silent or redirect
    }
}

redirect('exhibitions/');
