-- ============================================
-- 售后追踪模块 数据库升级
-- ============================================

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
