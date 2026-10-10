<?php
require_once __DIR__ . '/config.php';

try {
    // 1. Create exhibitions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exhibitions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL COMMENT 'اسم المعرض أو الفعالية',
            `location` VARCHAR(200) DEFAULT NULL COMMENT 'المكان أو المدينة أو الجناح',
            `start_date` DATE NOT NULL COMMENT 'تاريخ بداية المعرض',
            `end_date` DATE NOT NULL COMMENT 'تاريخ نهاية المعرض',
            `status` ENUM('upcoming','active','completed') NOT NULL DEFAULT 'active' COMMENT 'حالة المعرض',
            `budget` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الميزانية التقديرية المخصصة',
            `notes` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX (`start_date`),
            INDEX (`end_date`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Create exhibition_expenses table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exhibition_expenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `exhibition_id` INT NOT NULL,
            `category` ENUM(
                'booth_rent',
                'staff_wages',
                'hospitality_food',
                'packaging_supplies',
                'shipping_transport',
                'setup_decoration',
                'marketing_ads',
                'utilities_permits',
                'other'
            ) NOT NULL DEFAULT 'other',
            `title` VARCHAR(255) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `expense_date` DATE NOT NULL,
            `payment_method` ENUM('cash','card','transfer') NOT NULL DEFAULT 'cash',
            `receipt_ref` VARCHAR(100) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`exhibition_id`) REFERENCES `exhibitions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX (`exhibition_id`),
            INDEX (`category`),
            INDEX (`expense_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Add exhibition_id to sales if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM sales LIKE 'exhibition_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE `sales`
            ADD COLUMN `exhibition_id` INT DEFAULT NULL AFTER `invoice_number`,
            ADD CONSTRAINT `fk_sales_exhibition` FOREIGN KEY (`exhibition_id`) REFERENCES `exhibitions`(`id`) ON DELETE SET NULL
        ");
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
