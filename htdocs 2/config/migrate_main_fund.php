<?php
require_once __DIR__ . '/config.php';

try {
    // 1. جدول حركات الصندوق الرئيسي (سجل واحد لكل حركة دخول/خروج كاش أو بطاقة أو تحويل)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `treasury_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `type` ENUM('funding','expense','purchase_goods','purchase_service') NOT NULL DEFAULT 'expense' COMMENT 'funding = تغذية الصندوق (دخول) — الباقي حركات خروج',
            `title` VARCHAR(255) NOT NULL COMMENT 'بيان الحركة',
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` ENUM('cash','card','transfer') NOT NULL DEFAULT 'cash',
            `category` VARCHAR(50) DEFAULT NULL COMMENT 'تصنيف المصروف (لو type = expense)',
            `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'exhibition_expense / purchase_invoice / NULL لو يدوي',
            `reference_id` INT DEFAULT NULL,
            `transaction_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX (`transaction_date`),
            INDEX (`type`),
            INDEX (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. جدول فواتير المشتريات (بضاعة أو مواد خدمية)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_invoices` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_type` ENUM('goods','service') NOT NULL DEFAULT 'goods' COMMENT 'بضاعة/مخزون أو مواد وخدمات تشغيلية',
            `supplier_name` VARCHAR(200) DEFAULT NULL,
            `invoice_ref` VARCHAR(100) DEFAULT NULL COMMENT 'رقم فاتورة المورد (اختياري)',
            `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` ENUM('cash','card','transfer') NOT NULL DEFAULT 'cash',
            `purchase_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX (`invoice_type`),
            INDEX (`purchase_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. بنود فاتورة المشتريات
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `purchase_invoice_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `purchase_invoice_id` INT NOT NULL,
            `description` VARCHAR(255) NOT NULL COMMENT 'اسم الصنف أو الخدمة',
            `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices`(`id`) ON DELETE CASCADE,
            INDEX (`purchase_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Backfill idempotent: ضم مصاريف المعارض المسجلة فعلاً قبل هذا التحديث
    //    لسجل حركات الصندوق، عشان الرصيد يعكس الكاش الحقيقي اللي خرج بالفعل.
    //    الشرط NOT EXISTS بيمنع أي تكرار لو السكريبت اتشغل أكتر من مرة.
    $pdo->exec("
        INSERT INTO `treasury_transactions`
            (`type`, `title`, `amount`, `payment_method`, `category`, `reference_type`, `reference_id`, `transaction_date`, `notes`, `created_by`, `created_at`)
        SELECT
            'expense',
            CONCAT('مصروف معرض: ', ee.title),
            ee.amount,
            ee.payment_method,
            ee.category,
            'exhibition_expense',
            ee.id,
            ee.expense_date,
            ee.notes,
            ee.created_by,
            ee.created_at
        FROM `exhibition_expenses` ee
        WHERE NOT EXISTS (
            SELECT 1 FROM `treasury_transactions` t
            WHERE t.reference_type = 'exhibition_expense' AND t.reference_id = ee.id
        )
    ");

    echo "Main fund migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
