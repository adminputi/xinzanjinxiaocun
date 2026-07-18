<?php
/**
 * 数据库迁移系统 - 集中管理所有表结构变更
 * 
 * 用法：在模块文件中 require_once 此文件，
 * 调用 run_migrations() 自动执行待处理的迁移。
 * 
 * 每次新增迁移只需在 $migrations 数组中添加新记录即可。
 * 已执行的迁移记录在 operation_logs 的 migration 模块中缓存。
 */

function run_migrations() {
    $pdo = getDB();
    
    // 确保迁移记录表存在
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `_migrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `migration_key` VARCHAR(100) NOT NULL UNIQUE,
            `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('Migration table creation failed: ' . $e->getMessage());
        return;
    }
    
    // 获取已执行的迁移
    $executed = $pdo->query("SELECT migration_key FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);
    $executed = array_flip($executed);
    
    // 定义所有迁移（按顺序执行）
    $migrations = [
        // ========== 销售出库单扩展字段 ==========
        'sales_outstocks_pay_status' => 
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS pay_status ENUM('paid_full','paid_deposit','unpaid') NOT NULL DEFAULT 'unpaid' COMMENT '收款状态'",
        'sales_outstocks_receiver_name' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS receiver_name VARCHAR(50) DEFAULT '' COMMENT '接货人员'",
        'sales_outstocks_receiver_phone' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS receiver_phone VARCHAR(30) DEFAULT '' COMMENT '接货人电话'",
        'sales_outstocks_salesperson_name' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS salesperson_name VARCHAR(50) DEFAULT '' COMMENT '业务员名称'",
        'sales_outstocks_salesperson_phone' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS salesperson_phone VARCHAR(30) DEFAULT '' COMMENT '业务员电话'",
        'sales_outstocks_pay_remark' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS pay_remark TEXT COMMENT '收款备注'",
        'sales_outstocks_pay_updated_at' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS pay_updated_at DATETIME DEFAULT NULL COMMENT '收款状态变更时间'",
        'sales_outstocks_cancel_reason' =>
            "ALTER TABLE sales_outstocks 
             ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) DEFAULT NULL COMMENT '撤销原因'",
             
        // ========== 销售出库收款日志表 ==========
        'sales_outstock_paylogs_table' =>
            "CREATE TABLE IF NOT EXISTS `sales_outstock_paylogs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `outstock_id` INT NOT NULL,
                `from_status` VARCHAR(20) DEFAULT '',
                `to_status` VARCHAR(20) DEFAULT '',
                `remark` TEXT,
                `user_name` VARCHAR(50) DEFAULT '',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_outstock` (`outstock_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
        // ========== 退货单撤销原因 ==========
        'sales_returns_cancel_reason' =>
            "ALTER TABLE sales_returns 
             ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) DEFAULT '' COMMENT '撤销原因'",
        'purchase_returns_cancel_reason' =>
            "ALTER TABLE purchase_returns 
             ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) DEFAULT '' COMMENT '撤销原因'",
             
        // ========== 销售订单收款字段 ==========
        'sales_orders_received_amount' =>
            "ALTER TABLE sales_orders 
             ADD COLUMN IF NOT EXISTS received_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '已收金额'",
        'sales_orders_pay_status' =>
            "ALTER TABLE sales_orders 
             ADD COLUMN IF NOT EXISTS pay_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT '收款状态'",
        'sales_orders_cancel_reason' =>
            "ALTER TABLE sales_orders 
             ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) DEFAULT NULL COMMENT '取消原因'",
             
        // ========== 采购订单付款字段 ==========
        'purchase_orders_paid_amount' =>
            "ALTER TABLE purchase_orders 
             ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '已付金额'",
        'purchase_orders_pay_status' =>
            "ALTER TABLE purchase_orders 
             ADD COLUMN IF NOT EXISTS pay_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT '付款状态'",
             
        // ========== 收付款关联订单 ==========
        'receipts_order_id' =>
            "ALTER TABLE receipts 
             ADD COLUMN IF NOT EXISTS order_id INT DEFAULT 0 COMMENT '关联订单ID'",
        'payments_order_id' =>
            "ALTER TABLE payments 
             ADD COLUMN IF NOT EXISTS order_id INT DEFAULT 0 COMMENT '关联订单ID'",
             
        // ========== 订单明细行备注 ==========
        'purchase_order_items_remark' =>
            "ALTER TABLE purchase_order_items
             ADD COLUMN IF NOT EXISTS remark VARCHAR(500) DEFAULT NULL COMMENT '行备注'",
        'sales_order_items_remark' =>
            "ALTER TABLE sales_order_items
             ADD COLUMN IF NOT EXISTS remark VARCHAR(500) DEFAULT NULL COMMENT '行备注'",
        'sales_outstock_items_remark' =>
            "ALTER TABLE sales_outstock_items
             ADD COLUMN IF NOT EXISTS remark VARCHAR(500) DEFAULT NULL COMMENT '行备注'",

        // ========== 商品描述（用于产品项目方案单/报价单打印） ==========
        'products_description' =>
            "ALTER TABLE products
             ADD COLUMN IF NOT EXISTS description TEXT COMMENT '商品描述（用于产品项目方案单打印）'",

        // ========== 销售报价单 ==========
        'sales_quotes_table' =>
            "CREATE TABLE IF NOT EXISTS `sales_quotes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `bill_no` VARCHAR(100) NOT NULL UNIQUE,
                `customer_id` INT DEFAULT 0,
                `total_amount` DECIMAL(12,2) DEFAULT 0,
                `status` ENUM('draft','quoted','withdrawn') DEFAULT 'draft',
                `order_id` INT DEFAULT NULL COMMENT '关联的销售订单ID',
                `quote_date` DATE DEFAULT NULL,
                `employee_id` INT DEFAULT 0 COMMENT '业务员',
                `remark` TEXT,
                `user_id` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_customer` (`customer_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'sales_quote_items_table' =>
            "CREATE TABLE IF NOT EXISTS `sales_quote_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `quote_id` INT NOT NULL,
                `product_id` INT NOT NULL,
                `quantity` DECIMAL(12,2) DEFAULT 0,
                `price` DECIMAL(12,2) DEFAULT 0,
                `amount` DECIMAL(12,2) DEFAULT 0,
                `remark` VARCHAR(500) DEFAULT NULL COMMENT '行备注',
                INDEX `idx_quote` (`quote_id`),
                INDEX `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    
    // 执行未完成的迁移
    $count = 0;
    foreach ($migrations as $key => $sql) {
        if (isset($executed[$key])) continue;
        
        try {
            // MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS，用 try-catch 兜底
            $pdo->exec($sql);
            $pdo->prepare("INSERT INTO _migrations (migration_key) VALUES (?)")->execute([$key]);
            $count++;
        } catch (Exception $e) {
            // 列/表已存在时忽略错误（兼容不支持 IF NOT EXISTS 的 MySQL 版本）
            if (stripos($e->getMessage(), 'Duplicate') === false 
                && stripos($e->getMessage(), 'already exists') === false) {
                error_log("Migration [$key] failed: " . $e->getMessage());
            }
            // 标记为已执行（表/列已存在视为迁移完成）
            try {
                $pdo->prepare("INSERT IGNORE INTO _migrations (migration_key) VALUES (?)")->execute([$key]);
            } catch (Exception $ignored) {
                // 迁移记录插入失败不影响业务，静默跳过
            }
        }
    }
    
    if ($count > 0) {
        error_log("Migrations executed: $count new schema changes applied.");
    }
}
