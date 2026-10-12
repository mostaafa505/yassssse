-- ============================================================================
-- نظام ماهر البحيري للاستيراد والتصدير — هيكل قاعدة البيانات
-- متوافق مع MySQL 5.7+ / MariaDB 10.3+  (InnoDB, utf8mb4)
-- ملاحظة: الملف بيتنفذ تلقائياً من صفحة التثبيت install/ ، أو تقدر تستورده
-- يدوياً من phpMyAdmin داخل قاعدة بيانات فاضية.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','accounts_manager','accountant','warehouse','sales','purchases','viewer') NOT NULL DEFAULT 'viewer',
  `permissions` text DEFAULT NULL COMMENT 'JSON: قائمة الوحدات المسموحة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_logins` int NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `recovery_hash` varchar(255) DEFAULT NULL COMMENT 'هاش رمز استرداد كلمة المرور',
  `recovery_set_at` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `skey` varchar(60) NOT NULL,
  `svalue` text DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sequences` (
  `name` varchar(30) NOT NULL,
  `last_value` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `entity` varchar(40) NOT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── المخازن والأصناف ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL COMMENT 'كود الصنف',
  `name` varchar(190) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'وحدة',
  `reorder_level` decimal(14,3) NOT NULL DEFAULT 0 COMMENT 'حد إعادة الطلب',
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_items_code` (`code`),
  KEY `idx_items_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كل حركة مخزون في سطر مستقل. الكمية بإشارة: موجب = وارد، سالب = منصرف.
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `move_date` date NOT NULL,
  `move_type` enum('opening','purchase','sale','receipt','issue','adjustment','transfer_in','transfer_out') NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_cost` decimal(14,4) DEFAULT NULL,
  `doc_no` varchar(60) DEFAULT NULL,
  `ref_type` varchar(20) DEFAULT NULL,
  `ref_id` int unsigned DEFAULT NULL,
  `reason` varchar(60) DEFAULT NULL COMMENT 'تلف / مرتجع / جرد ...',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sm_item_wh` (`item_id`,`warehouse_id`),
  KEY `idx_sm_date` (`move_date`),
  KEY `idx_sm_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_sm_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_sm_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── الموردين والعملاء ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `tax_no` varchar(40) DEFAULT NULL,
  `credit_days` int NOT NULL DEFAULT 0 COMMENT 'مدة السداد الافتراضية بالأيام',
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suppliers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `tax_no` varchar(40) DEFAULT NULL,
  `credit_days` int NOT NULL DEFAULT 0 COMMENT 'مدة التحصيل الافتراضية بالأيام',
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كشف الحساب: الرصيد = مجموع (invoice_amount) − مجموع (payment_amount)
-- invoice_amount = فواتير / رصيد افتتاحي / إشعار إضافة   (يزيد الرصيد)
-- payment_amount = تحصيل أو سداد / إشعار خصم              (ينقص الرصيد)
CREATE TABLE IF NOT EXISTS `customer_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `doc_type` enum('opening','invoice','payment','debit_note','credit_note') NOT NULL,
  `doc_no` varchar(60) DEFAULT NULL,
  `invoice_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `ref_type` varchar(20) DEFAULT NULL,
  `ref_id` int unsigned DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cl_party` (`customer_id`,`entry_date`),
  KEY `idx_cl_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_cl_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `doc_type` enum('opening','invoice','payment','debit_note','credit_note') NOT NULL,
  `doc_no` varchar(60) DEFAULT NULL,
  `invoice_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `ref_type` varchar(20) DEFAULT NULL,
  `ref_id` int unsigned DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sl_party` (`supplier_id`,`entry_date`),
  KEY `idx_sl_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_sl_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── الخزينة ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `treasury_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `acc_type` enum('cash','bank','wallet') NOT NULL DEFAULT 'cash',
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0,
  `notes` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ta_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `treasury_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `doc_no` varchar(60) NOT NULL,
  `description` varchar(300) NOT NULL,
  `category` varchar(40) NOT NULL,
  `receipt_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_method` varchar(30) NOT NULL DEFAULT 'نقدي',
  `responsible` varchar(120) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `ref_type` varchar(20) DEFAULT NULL,
  `ref_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tt_acc_date` (`account_id`,`txn_date`),
  KEY `idx_tt_cat` (`category`),
  KEY `idx_tt_ref` (`ref_type`,`ref_id`),
  CONSTRAINT `fk_tt_acc` FOREIGN KEY (`account_id`) REFERENCES `treasury_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── فواتير المشتريات ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_date` date NOT NULL,
  `doc_no` varchar(60) NOT NULL COMMENT 'رقم فاتورة المورد',
  `supplier_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0,
  `discount` decimal(14,2) NOT NULL DEFAULT 0,
  `transport` decimal(14,2) NOT NULL DEFAULT 0,
  `vat` decimal(14,2) NOT NULL DEFAULT 0,
  `other_fees` decimal(14,2) NOT NULL DEFAULT 0,
  `total` decimal(14,2) NOT NULL DEFAULT 0,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `receipt_note_no` varchar(60) DEFAULT NULL COMMENT 'رقم إذن الاستلام',
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_supplier_doc` (`supplier_id`,`doc_no`),
  KEY `idx_purchases_date` (`invoice_date`),
  CONSTRAINT `fk_pu_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_pu_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pi_purchase` (`purchase_id`),
  CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── فواتير المبيعات (للعملاء) ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_date` date NOT NULL,
  `doc_no` varchar(60) NOT NULL COMMENT 'رقم فاتورة البيع (تلقائي)',
  `customer_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0,
  `discount` decimal(14,2) NOT NULL DEFAULT 0,
  `transport` decimal(14,2) NOT NULL DEFAULT 0,
  `vat` decimal(14,2) NOT NULL DEFAULT 0,
  `other_fees` decimal(14,2) NOT NULL DEFAULT 0,
  `total` decimal(14,2) NOT NULL DEFAULT 0,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `issue_note_no` varchar(60) DEFAULT NULL COMMENT 'رقم إذن الصرف',
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_doc` (`doc_no`),
  KEY `idx_sales_date` (`invoice_date`),
  KEY `idx_sales_customer` (`customer_id`),
  CONSTRAINT `fk_sa_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sa_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_si_sale` (`sale_id`),
  CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── سندات القبض (من العملاء) والصرف (للموردين) ──────────────────────────────
CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `voucher_type` enum('receipt','payment') NOT NULL COMMENT 'receipt=قبض من عميل، payment=صرف لمورد',
  `voucher_date` date NOT NULL,
  `doc_no` varchar(60) NOT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `supplier_id` int unsigned DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `account_id` int unsigned NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voucher_doc` (`voucher_type`,`doc_no`),
  KEY `idx_vouchers_date` (`voucher_date`),
  CONSTRAINT `fk_vo_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_vo_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_vo_acc` FOREIGN KEY (`account_id`) REFERENCES `treasury_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- توزيع السند على الفواتير (invoice_id = فاتورة بيع أو شراء حسب نوع السند)
CREATE TABLE IF NOT EXISTS `voucher_allocations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int unsigned NOT NULL,
  `invoice_id` int unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_va_voucher` (`voucher_id`),
  KEY `idx_va_invoice` (`invoice_id`),
  CONSTRAINT `fk_va_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
