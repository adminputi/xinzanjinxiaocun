-- ============================================
-- 进销存管理系统 数据库结构
-- ============================================

-- 角色表
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT '',
    `permissions` TEXT COMMENT 'JSON格式权限列表',
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `real_name` VARCHAR(50) DEFAULT '',
    `email` VARCHAR(100) DEFAULT '',
    `phone` VARCHAR(20) DEFAULT '',
    `role_id` INT DEFAULT 0,
    `status` TINYINT DEFAULT 1,
    `last_login` DATETIME DEFAULT NULL,
    `login_ip` VARCHAR(45) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 操作日志
CREATE TABLE IF NOT EXISTS `operation_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT 0,
    `action` VARCHAR(50) DEFAULT '',
    `module` VARCHAR(50) DEFAULT '',
    `content` TEXT,
    `ip_address` VARCHAR(45) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_module` (`module`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 商品分类
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `parent_id` INT DEFAULT 0,
    `sort_order` INT DEFAULT 0,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 单位
CREATE TABLE IF NOT EXISTS `units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `status` TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `units` (`name`) VALUES ('个'),('箱'),('件'),('套'),('千克'),('克'),('吨'),('米'),('升'),('包'),('瓶'),('桶');

-- 商品表
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(100) NOT NULL UNIQUE COMMENT 'SKU编码',
    `name` VARCHAR(200) NOT NULL,
    `category_id` INT DEFAULT 0,
    `unit_id` INT DEFAULT 0,
    `spec` VARCHAR(100) DEFAULT '' COMMENT '规格',
    `barcode` VARCHAR(100) DEFAULT '' COMMENT '条码',
    `purchase_price` DECIMAL(12,2) DEFAULT 0 COMMENT '采购价',
    `sale_price` DECIMAL(12,2) DEFAULT 0 COMMENT '销售价',
    `min_stock` DECIMAL(12,2) DEFAULT 0 COMMENT '最低库存预警',
    `max_stock` DECIMAL(12,2) DEFAULT 0 COMMENT '最高库存',
    `image` VARCHAR(255) DEFAULT '',
    `remark` TEXT,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_id`),
    INDEX `idx_sku` (`sku`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 商品图片表
CREATE TABLE IF NOT EXISTS `product_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 仓库表
CREATE TABLE IF NOT EXISTS `warehouses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) DEFAULT '',
    `address` VARCHAR(255) DEFAULT '',
    `manager` VARCHAR(50) DEFAULT '',
    `phone` VARCHAR(20) DEFAULT '',
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `warehouses` (`name`, `code`) VALUES ('主仓库', 'MAIN');

-- 供应商表
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) DEFAULT '' COMMENT '供应商编码',
    `name` VARCHAR(200) NOT NULL,
    `contact` VARCHAR(50) DEFAULT '',
    `phone` VARCHAR(30) DEFAULT '',
    `email` VARCHAR(100) DEFAULT '',
    `address` VARCHAR(255) DEFAULT '',
    `bank_name` VARCHAR(100) DEFAULT '',
    `bank_account` VARCHAR(50) DEFAULT '',
    `tax_no` VARCHAR(50) DEFAULT '',
    `remark` TEXT,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 客户表
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) DEFAULT '' COMMENT '客户编码',
    `name` VARCHAR(200) NOT NULL,
    `type` ENUM('individual','company') DEFAULT 'company',
    `contact` VARCHAR(50) DEFAULT '',
    `phone` VARCHAR(30) DEFAULT '',
    `email` VARCHAR(100) DEFAULT '',
    `address` VARCHAR(255) DEFAULT '',
    `wechat` VARCHAR(50) DEFAULT '' COMMENT '微信号',
    `company` VARCHAR(200) DEFAULT '' COMMENT '公司名称',
    `level` TINYINT DEFAULT 0 COMMENT '客户等级',
    `source_id` INT DEFAULT NULL COMMENT '客户来源ID',
    `owner_id` INT DEFAULT NULL COMMENT '归属业务经理(users.id)',
    `in_pool` TINYINT DEFAULT 0 COMMENT '是否在公海',
    `pooled_at` DATETIME DEFAULT NULL COMMENT '归入公海时间',
    `last_followed_at` DATETIME DEFAULT NULL COMMENT '最后跟进时间',
    `intention` ENUM('高','中','低') DEFAULT NULL COMMENT '意向程度',
    `intended_product` VARCHAR(200) DEFAULT '' COMMENT '意向产品',
    `created_by` INT DEFAULT NULL COMMENT '创建人(users.id)',
    `initial_balance` DECIMAL(12,2) DEFAULT 0 COMMENT '期初应收',
    `remark` TEXT,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_owner` (`owner_id`),
    INDEX `idx_pool` (`in_pool`),
    INDEX `idx_follow` (`last_followed_at`),
    INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 库存表
CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_product_warehouse` (`product_id`, `warehouse_id`),
    INDEX `idx_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 库存变动记录
CREATE TABLE IF NOT EXISTS `inventory_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `change_quantity` DECIMAL(12,2) DEFAULT 0 COMMENT '变动数量(正=入库,负=出库)',
    `current_quantity` DECIMAL(12,2) DEFAULT 0 COMMENT '变动后数量',
    `type` ENUM('in','out','transfer_in','transfer_out','check','loss') DEFAULT 'in',
    `bill_no` VARCHAR(100) DEFAULT '' COMMENT '单据编号',
    `bill_type` VARCHAR(50) DEFAULT '' COMMENT '单据类型',
    `user_id` INT DEFAULT 0,
    `remark` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_product` (`product_id`),
    INDEX `idx_warehouse` (`warehouse_id`),
    INDEX `idx_bill` (`bill_no`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购订单
CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE COMMENT '单据编号',
    `supplier_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `paid_amount` DECIMAL(12,2) DEFAULT 0 COMMENT '已付金额',
    `status` ENUM('draft','confirmed','received','partial','completed','cancelled') DEFAULT 'draft',
    `order_date` DATE DEFAULT NULL,
    `expected_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0 COMMENT '采购员',
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购订单明细
CREATE TABLE IF NOT EXISTS `purchase_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `received_qty` DECIMAL(12,2) DEFAULT 0 COMMENT '已入库数量',
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '行备注',
    INDEX `idx_order` (`order_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购入库单
CREATE TABLE IF NOT EXISTS `purchase_instocks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `order_id` INT DEFAULT 0 COMMENT '关联采购订单',
    `supplier_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `instock_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_date` (`instock_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购入库明细
CREATE TABLE IF NOT EXISTS `purchase_instock_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `instock_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    INDEX `idx_instock` (`instock_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购退货单
CREATE TABLE IF NOT EXISTS `purchase_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `supplier_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `return_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `cancel_reason` VARCHAR(255) DEFAULT '' COMMENT '撤销原因',
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_date` (`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 采购退货明细
CREATE TABLE IF NOT EXISTS `purchase_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    INDEX `idx_return` (`return_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售订单
CREATE TABLE IF NOT EXISTS `sales_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `customer_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `received_amount` DECIMAL(12,2) DEFAULT 0 COMMENT '已收金额',
    `status` ENUM('draft','confirmed','shipped','partial','completed','cancelled') DEFAULT 'draft',
    `order_date` DATE DEFAULT NULL,
    `delivery_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0 COMMENT '业务员',
    `remark` TEXT,
    `cancel_reason` VARCHAR(500) DEFAULT NULL COMMENT '取消原因',
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售订单明细
CREATE TABLE IF NOT EXISTS `sales_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `shipped_qty` DECIMAL(12,2) DEFAULT 0 COMMENT '已出库数量',
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '行备注',
    INDEX `idx_order` (`order_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售出库单
CREATE TABLE IF NOT EXISTS `sales_outstocks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `order_id` INT DEFAULT 0 COMMENT '关联销售订单',
    `customer_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `pay_status` ENUM('paid_full','paid_deposit','unpaid') DEFAULT 'unpaid' COMMENT '收款状态',
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `outstock_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `receiver_name` VARCHAR(50) DEFAULT '' COMMENT '接货人员',
    `receiver_phone` VARCHAR(30) DEFAULT '' COMMENT '接货人电话',
    `salesperson_name` VARCHAR(50) DEFAULT '' COMMENT '业务员名称',
    `salesperson_phone` VARCHAR(30) DEFAULT '' COMMENT '业务员电话',
    `remark` TEXT,
    `cancel_reason` VARCHAR(500) DEFAULT NULL COMMENT '撤销原因',
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_date` (`outstock_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售出库明细
CREATE TABLE IF NOT EXISTS `sales_outstock_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `outstock_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '行备注',
    INDEX `idx_outstock` (`outstock_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售退货单
CREATE TABLE IF NOT EXISTS `sales_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `customer_id` INT DEFAULT 0,
    `warehouse_id` INT DEFAULT 0,
    `total_amount` DECIMAL(12,2) DEFAULT 0,
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `return_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `cancel_reason` VARCHAR(255) DEFAULT '' COMMENT '撤销原因',
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_date` (`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 销售退货明细
CREATE TABLE IF NOT EXISTS `sales_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `price` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    INDEX `idx_return` (`return_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 调拨单
CREATE TABLE IF NOT EXISTS `transfers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `from_warehouse_id` INT DEFAULT 0,
    `to_warehouse_id` INT DEFAULT 0,
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `transfer_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_from` (`from_warehouse_id`),
    INDEX `idx_to` (`to_warehouse_id`),
    INDEX `idx_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 调拨明细
CREATE TABLE IF NOT EXISTS `transfer_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transfer_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    INDEX `idx_transfer` (`transfer_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 盘点单
CREATE TABLE IF NOT EXISTS `check_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `warehouse_id` INT DEFAULT 0,
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `check_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_warehouse` (`warehouse_id`),
    INDEX `idx_date` (`check_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 盘点明细
CREATE TABLE IF NOT EXISTS `check_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `check_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `book_qty` DECIMAL(12,2) DEFAULT 0 COMMENT '账面数量',
    `actual_qty` DECIMAL(12,2) DEFAULT 0 COMMENT '实盘数量',
    `diff_qty` DECIMAL(12,2) DEFAULT 0 COMMENT '差异',
    `remark` VARCHAR(255) DEFAULT '',
    INDEX `idx_check` (`check_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 报损报溢单
CREATE TABLE IF NOT EXISTS `loss_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `warehouse_id` INT DEFAULT 0,
    `type` ENUM('loss','overflow') DEFAULT 'loss' COMMENT '报损/报溢',
    `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    `order_date` DATE DEFAULT NULL,
    `employee_id` INT DEFAULT 0,
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_warehouse` (`warehouse_id`),
    INDEX `idx_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 报损报溢明细
CREATE TABLE IF NOT EXISTS `loss_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `loss_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0 COMMENT '金额',
    `reason` VARCHAR(255) DEFAULT '',
    INDEX `idx_loss` (`loss_id`),
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 收款记录
CREATE TABLE IF NOT EXISTS `receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `customer_id` INT DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `pay_method` ENUM('cash','bank','wechat','alipay','other') DEFAULT 'bank',
    `receipt_date` DATE DEFAULT NULL,
    `related_bill_no` VARCHAR(100) DEFAULT '' COMMENT '关联单据',
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_date` (`receipt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 付款记录
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bill_no` VARCHAR(100) NOT NULL UNIQUE,
    `supplier_id` INT DEFAULT 0,
    `amount` DECIMAL(12,2) DEFAULT 0,
    `pay_method` ENUM('cash','bank','wechat','alipay','other') DEFAULT 'bank',
    `payment_date` DATE DEFAULT NULL,
    `related_bill_no` VARCHAR(100) DEFAULT '' COMMENT '关联单据',
    `remark` TEXT,
    `user_id` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== CRM 模块 ==========

-- 客户来源
CREATE TABLE IF NOT EXISTS `customer_sources` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `sort_order` INT DEFAULT 0,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `customer_sources` (`name`, `sort_order`) VALUES
('推流', 1), ('分配', 2), ('淘宝', 3), ('抖音', 4), ('微信', 5), ('转介绍', 6), ('展会', 7), ('其他', 8);

-- 跟进记录
CREATE TABLE IF NOT EXISTS `customer_followups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `user_id` INT NOT NULL COMMENT '跟进人',
    `follow_type` ENUM('电话','微信','面谈','拜访','短信','邮件','其他') DEFAULT '电话',
    `content` TEXT NOT NULL COMMENT '跟进内容',
    `attachment` VARCHAR(500) DEFAULT '' COMMENT '附件路径',
    `next_follow_at` DATETIME DEFAULT NULL COMMENT '计划下次跟进时间',
    `result` ENUM('待跟进','有意向','已成交','无意向') DEFAULT '待跟进',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_next` (`next_follow_at`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 客户转移记录
CREATE TABLE IF NOT EXISTS `customer_transfer_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `from_user_id` INT DEFAULT NULL COMMENT '原归属(NULL=公海)',
    `to_user_id` INT DEFAULT NULL COMMENT '新归属(NULL=公海)',
    `action` ENUM('to_pool','claim','assign','transfer') NOT NULL,
    `operator_id` INT NOT NULL COMMENT '操作人',
    `remark` VARCHAR(500) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 打印模板
CREATE TABLE IF NOT EXISTS `print_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('sales_order','sales_outstock','purchase_order','purchase_instock') DEFAULT 'sales_order',
    `content` TEXT COMMENT 'HTML模板内容',
    `is_default` TINYINT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统设置
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `description` VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('captcha_enabled', '0', '登录验证码开关：1开启/0关闭'),
('crm_pool_days', '30', '公海自动回收天数：多久不跟进自动归入公海（0=关闭）'),
('crm_claim_hours', '72', '认领后未跟进强制回收小时数：认领后几小时内不写跟进强制放回公海（0=关闭）'),
('crm_pool_last_check', '', '公海回收最后一次检查时间');

-- 插入默认角色
INSERT IGNORE INTO `roles` (`id`, `name`, `description`, `permissions`) VALUES 
(1, 'admin', '超级管理员', '["dashboard","master_data","product_view","product_category","warehouse_view","customer_view","supplier_view","purchase","purchase_order","purchase_instock","purchase_return","purchase_reconcile","sales","sales_order","sales_outstock","sales_return","sales_reconcile","print_template","inventory","inventory_view","inventory_log","transfer_manage","check_manage","loss_manage","finance","finance_arpay","finance_receive","finance_payment","finance_aging","report","report_sales","report_purchase","report_inventory","report_performance","report_io","system","system_users","system_roles","system_logs","system_settings","system_init","crm_customer_view","crm_customer_edit","crm_pool_claim","crm_pool_manage","crm_followup_view","crm_followup_add","crm_source_manage","crm_report","crm_setting"]'),
(2, 'warehouse', '仓库人员', '["dashboard","inventory","inventory_view","inventory_log","transfer_manage","check_manage","loss_manage","product_view"]'),
(3, 'purchase', '采购人员', '["dashboard","purchase","purchase_order","purchase_instock","purchase_return","purchase_reconcile","product_view","supplier_view"]'),
(4, 'sales', '销售人员', '["dashboard","sales","sales_order","sales_return","sales_reconcile","product_view","customer_view","report_sales","crm_customer_view","crm_customer_edit","crm_pool_claim","crm_followup_view","crm_followup_add","crm_report"]'),
(5, 'finance', '财务人员', '["dashboard","finance","finance_arpay","finance_receive","finance_payment","finance_aging","report","report_sales","report_purchase","report_inventory"]'),
(6, 'viewer', '只读用户', '["dashboard","product_view","inventory_view","report_sales","report_purchase","report_inventory"]');

-- 打印默认模板
INSERT IGNORE INTO `print_templates` (`name`, `type`, `content`, `is_default`) VALUES
('销售单模板A（含单价金额）', 'sales_order',
'<div style="font-family:SimSun;max-width:800px;margin:0 auto;padding:20px;">
<h2 style="text-align:center;margin-bottom:5px;">{company_name}</h2>
<h3 style="text-align:center;margin-top:0;margin-bottom:15px;">销售单</h3>
<div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;">
<div>单号：{bill_no}</div><div>日期：{bill_date}</div>
</div>
<div style="font-size:14px;margin-bottom:8px;">
<div>客户：{customer_name}　　电话：{customer_phone}</div>
<div>地址：{customer_address}</div>
</div>
<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead>
<tbody>{items}</tbody>
<tfoot><tr><td colspan="7" align="right">合计金额：</td><td>{total_amount}</td></tr></tfoot>
</table>
<div style="margin-top:40px;font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;">
<div>制单人：{user_name}</div><div>审核人：___________</div><div>客户签字：___________</div><div>业务签字：___________</div>
</div>
</div>', 1),
('销售单模板B（不含单价金额）', 'sales_order',
'<div style="font-family:SimSun;max-width:800px;margin:0 auto;padding:20px;">
<h2 style="text-align:center;margin-bottom:5px;">{company_name}</h2>
<h3 style="text-align:center;margin-top:0;margin-bottom:15px;">销售单（送货单）</h3>
<div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;">
<div>单号：{bill_no}</div><div>日期：{bill_date}</div>
</div>
<div style="font-size:14px;margin-bottom:8px;">
<div>客户：{customer_name}　　电话：{customer_phone}</div>
<div>地址：{customer_address}</div>
</div>
<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>备注</th></tr></thead>
<tbody>{items}</tbody>
</table>
<div style="margin-top:40px;font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;">
<div>制单人：{user_name}</div><div>审核人：___________</div><div>客户签字：___________</div><div>业务签字：___________</div>
</div>
</div>', 0);
INSERT INTO `print_templates` (`name`, `type`, `content`, `is_default`) VALUES
('默认销售出库单（含单价金额）', 'sales_outstock',
'<div style="font-family:SimSun;max-width:800px;margin:0 auto;padding:20px;">
<h2 style="text-align:center;margin-bottom:5px;">{company_name}</h2>
<h3 style="text-align:center;margin-top:0;margin-bottom:15px;">销售出库单</h3>
<div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;">
<div>单号：{bill_no}</div><div>日期：{bill_date}</div>
</div>
<div style="font-size:14px;margin-bottom:8px;">
<div>客户：{customer_name}　　电话：{customer_phone}</div>
<div>地址：{customer_address}　　仓库：{warehouse_name}</div>
</div>
<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead>
<tbody>{items}</tbody>
<tfoot><tr><td colspan="7" align="right">合计金额：</td><td>{total_amount}</td></tr></tfoot>
</table>
<div style="margin-top:40px;font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;">
<div>制单人：{user_name}</div><div>审核人：___________</div><div>客户签字：___________</div><div>业务签字：___________</div>
</div>
</div>', 1),
('默认销售出库单（不含单价金额）', 'sales_outstock',
'<div style="font-family:SimSun;max-width:800px;margin:0 auto;padding:20px;">
<h2 style="text-align:center;margin-bottom:5px;">{company_name}</h2>
<h3 style="text-align:center;margin-top:0;margin-bottom:15px;">销售出库单（送货单）</h3>
<div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;">
<div>单号：{bill_no}</div><div>日期：{bill_date}</div>
</div>
<div style="font-size:14px;margin-bottom:8px;">
<div>客户：{customer_name}　　电话：{customer_phone}</div>
<div>地址：{customer_address}　　仓库：{warehouse_name}</div>
</div>
<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>备注</th></tr></thead>
<tbody>{items}</tbody>
</table>
<div style="margin-top:40px;font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;">
<div>制单人：{user_name}</div><div>审核人：___________</div><div>客户签字：___________</div><div>业务签字：___________</div>
</div>
</div>', 0);

-- ========== 售后追踪模块 ==========

-- 追踪状态管理表
CREATE TABLE IF NOT EXISTS `tracking_statuses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '状态名称',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '启用状态 1启用 0禁用',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='追踪状态管理';

-- 追踪码主表
CREATE TABLE IF NOT EXISTS `tracking_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tracking_no` VARCHAR(100) NOT NULL UNIQUE COMMENT '追踪码',
    `order_id` INT NOT NULL DEFAULT 0 COMMENT '关联销售订单ID',
    `outstock_id` INT NOT NULL DEFAULT 0 COMMENT '关联出库单ID',
    `password` VARCHAR(100) NOT NULL DEFAULT '888888' COMMENT '查询密码',
    `products_type` ENUM('all','selected') NOT NULL DEFAULT 'all' COMMENT '追踪产品：全部/选中',
    `tracking_data` LONGTEXT COMMENT 'JSON，快照所有展示字段（系统读取+手动输入）',
    `status_id` INT NOT NULL DEFAULT 0 COMMENT '当前状态ID',
    `after_sales_employee_id` INT NOT NULL DEFAULT 0 COMMENT '售后人员ID',
    `qrcode_path` VARCHAR(255) DEFAULT '' COMMENT '二维码图片路径',
    `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
    `user_id` INT NOT NULL DEFAULT 0 COMMENT '制单人ID',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后追踪码';

-- 追踪产品明细表
CREATE TABLE IF NOT EXISTS `tracking_code_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tracking_id` INT NOT NULL COMMENT '关联追踪码ID',
    `order_item_id` INT NOT NULL DEFAULT 0 COMMENT '关联销售订单明细ID',
    `product_id` INT NOT NULL DEFAULT 0 COMMENT '商品ID',
    `product_name` VARCHAR(200) DEFAULT '' COMMENT '商品名称(快照)',
    `sku` VARCHAR(50) DEFAULT '' COMMENT 'SKU(快照)',
    `spec` VARCHAR(100) DEFAULT '' COMMENT '规格(快照)',
    `unit_name` VARCHAR(20) DEFAULT '' COMMENT '单位(快照)',
    `quantity` DECIMAL(12,2) DEFAULT 0 COMMENT '数量',
    `price` DECIMAL(12,2) DEFAULT 0 COMMENT '单价',
    `amount` DECIMAL(12,2) DEFAULT 0 COMMENT '金额',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='追踪产品明细';

-- 流程记录表（状态更新时间线）
CREATE TABLE IF NOT EXISTS `tracking_processes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tracking_id` INT NOT NULL COMMENT '关联追踪码ID',
    `status_id` INT NOT NULL DEFAULT 0 COMMENT '状态ID',
    `content` TEXT COMMENT '流程说明',
    `images` TEXT COMMENT 'JSON，上传图片路径数组',
    `is_newest` TINYINT NOT NULL DEFAULT 0 COMMENT '是否最新标记 1是 0否',
    `user_id` INT NOT NULL DEFAULT 0 COMMENT '操作人ID',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='追踪流程记录';

-- 售后信息表
CREATE TABLE IF NOT EXISTS `tracking_after_sales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tracking_id` INT NOT NULL COMMENT '关联追踪码ID',
    `content` TEXT COMMENT '售后信息',
    `images` TEXT COMMENT 'JSON，上传图片路径数组',
    `user_id` INT NOT NULL DEFAULT 0 COMMENT '操作人ID',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后信息记录';

-- 预置默认状态数据
INSERT INTO `tracking_statuses` (`name`, `sort_order`, `status`) VALUES
('已发货', 1, 1),
('运输中', 2, 1),
('已到场地', 3, 1),
('已交货', 4, 1),
('待调试', 5, 1),
('订单完成', 6, 1);
