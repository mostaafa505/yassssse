<?php
/**
 * migrate_offline_sync.php
 * يضيف عمود client_uid لجدول sales — معرف فريد يتولد في المتصفح وقت حفظ
 * الفاتورة أوفلاين، بيستخدم لمنع تكرار الفاتورة (وخصم المخزون مرتين) لو
 * المزامنة اتكررت (انقطع الاتصال بعد نجاح الحفظ، أو اشتغلت المزامنة اليدوية
 * والتلقائية في نفس اللحظة).
 */
require_once __DIR__ . '/config.php';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM sales LIKE 'client_uid'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE `sales`
            ADD COLUMN `client_uid` VARCHAR(64) DEFAULT NULL COMMENT 'معرف فريد من المتصفح لمنع تكرار فواتير الأوفلاين عند المزامنة' AFTER `invoice_number`,
            ADD UNIQUE KEY `client_uid` (`client_uid`)
        ");
        echo "Added client_uid column + unique index to sales table.\n";
    } else {
        echo "client_uid column already exists — nothing to do.\n";
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
