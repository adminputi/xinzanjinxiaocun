-- ============================================
-- 进销存系统 CRM 模块升级 SQL
-- 使用方法：在 phpMyAdmin 中选择对应数据库后导入此文件
-- 兼容：MySQL 5.7+
-- ============================================

-- -------------------------------------------
-- 辅助存储过程：安全添加字段（列不存在时才添加）
-- -------------------------------------------
DROP PROCEDURE IF EXISTS `proc_add_column`;
DELIMITER ;;
CREATE PROCEDURE `proc_add_column`(
    IN tbl_name VARCHAR(64),
    IN col_name VARCHAR(64),
    IN col_def  TEXT
)
BEGIN
    SET @cnt = 0;
    SELECT COUNT(*) INTO @cnt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = tbl_name
      AND COLUMN_NAME = col_name;
    IF @cnt = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl_name, '` ADD COLUMN `', col_name, '` ', col_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END;;
DELIMITER ;

-- -------------------------------------------
-- 辅助存储过程：安全添加索引（索引不存在时才添加）
-- -------------------------------------------
DROP PROCEDURE IF EXISTS `proc_add_index`;
DELIMITER ;;
CREATE PROCEDURE `proc_add_index`(
    IN tbl_name  VARCHAR(64),
    IN idx_name  VARCHAR(64),
    IN idx_columns TEXT
)
BEGIN
    SET @cnt = 0;
    SELECT COUNT(*) INTO @cnt
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = tbl_name
      AND INDEX_NAME = idx_name;
    IF @cnt = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl_name, '` ADD INDEX `', idx_name, '` (', idx_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END;;
DELIMITER ;

-- ============================================
-- 第一步：扩展 customers 表 —— CRM 字段
-- ============================================
CALL proc_add_column('customers', 'wechat',            "VARCHAR(50)  DEFAULT ''     COMMENT '微信号'               AFTER `address`");
CALL proc_add_column('customers', 'company',           "VARCHAR(200) DEFAULT ''     COMMENT '公司名称'              AFTER `wechat`");
CALL proc_add_column('customers', 'source_id',         "INT           DEFAULT NULL  COMMENT '客户来源ID'             AFTER `company`");
CALL proc_add_column('customers', 'owner_id',          "INT           DEFAULT NULL  COMMENT '归属业务经理(users.id)'  AFTER `source_id`");
CALL proc_add_column('customers', 'in_pool',           "TINYINT       DEFAULT 0     COMMENT '是否在公海'             AFTER `owner_id`");
CALL proc_add_column('customers', 'pooled_at',         "DATETIME      DEFAULT NULL  COMMENT '归入公海时间'           AFTER `in_pool`");
CALL proc_add_column('customers', 'last_followed_at',  "DATETIME      DEFAULT NULL  COMMENT '最后跟进时间'           AFTER `pooled_at`");
CALL proc_add_column('customers', 'intention',         "ENUM('高','中','低') DEFAULT NULL COMMENT '意向程度'       AFTER `last_followed_at`");
CALL proc_add_column('customers', 'intended_product',  "VARCHAR(200)  DEFAULT ''    COMMENT '意向产品'               AFTER `intention`");
CALL proc_add_column('customers', 'level',             "TINYINT       DEFAULT 0     COMMENT '客户等级'               AFTER `intended_product`");
CALL proc_add_column('customers', 'created_by',        "INT           DEFAULT NULL  COMMENT '创建人(users.id)'       AFTER `level`");

CALL proc_add_index('customers', 'idx_owner',  '`owner_id`');
CALL proc_add_index('customers', 'idx_pool',   '`in_pool`');
CALL proc_add_index('customers', 'idx_follow', '`last_followed_at`');
CALL proc_add_index('customers', 'idx_phone',  '`phone`');

-- ============================================
-- 第二步：新建 CRM 核心表
-- ============================================

-- 客户来源表
CREATE TABLE IF NOT EXISTS `customer_sources` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(100) NOT NULL,
    `sort_order` INT DEFAULT 0,
    `status`     TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `customer_sources` (`name`, `sort_order`) VALUES
('推流', 1), ('分配', 2), ('淘宝', 3), ('抖音', 4),
('微信', 5), ('转介绍', 6), ('展会', 7), ('其他', 8);

-- 跟进记录表
CREATE TABLE IF NOT EXISTS `customer_followups` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id`    INT NOT NULL,
    `user_id`        INT NOT NULL COMMENT '跟进人(users.id)',
    `follow_type`    ENUM('电话','微信','面谈','拜访','短信','邮件','其他') DEFAULT '电话',
    `content`        TEXT NOT NULL COMMENT '跟进内容',
    `attachment`     VARCHAR(500) DEFAULT '' COMMENT '附件路径',
    `next_follow_at` DATETIME DEFAULT NULL COMMENT '计划下次跟进时间',
    `result`         ENUM('待跟进','有意向','已成交','无意向') DEFAULT '待跟进',
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_user`     (`user_id`),
    INDEX `idx_next`     (`next_follow_at`),
    INDEX `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 客户转移记录表
CREATE TABLE IF NOT EXISTS `customer_transfer_logs` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id`  INT NOT NULL,
    `from_user_id` INT DEFAULT NULL COMMENT '原归属(NULL=公海)',
    `to_user_id`   INT DEFAULT NULL COMMENT '新归属(NULL=公海)',
    `action`       ENUM('to_pool','claim','assign','transfer') NOT NULL,
    `operator_id`  INT NOT NULL COMMENT '操作人(users.id)',
    `remark`       VARCHAR(500) DEFAULT '',
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 第三步：系统设置
-- ============================================
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('crm_pool_days',       '30', '公海自动回收天数：多久不跟进自动归入公海（0=关闭）'),
('crm_claim_hours',     '72', '认领后未跟进强制回收小时数：认领后几小时内不写跟进强制放回公海（0=关闭）'),
('crm_pool_last_check', '',   '公海回收最后一次检查时间');

-- ============================================
-- 第四步：更新角色权限
-- 逐条追加，避免多层嵌套 + CAST 在 MySQL 5.7 中的兼容问题
-- ============================================

-- 管理员（id=1）：逐条追加 9 个 CRM 权限
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_customer_view')  WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_customer_view"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_customer_edit')  WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_customer_edit"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_pool_claim')     WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_pool_claim"',     '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_pool_manage')    WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_pool_manage"',    '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_followup_view')  WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_followup_view"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_followup_add')   WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_followup_add"',   '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_source_manage')  WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_source_manage"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_report')         WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_report"',         '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_setting')        WHERE `id` = 1 AND JSON_CONTAINS(`permissions`, '"crm_setting"',        '$') = 0;

-- 业务经理/sales（id=4）：先移除 sales_outstock 再追加 6 个 CRM 权限
-- 注意：如果 sales 角色 id 不是 4，请修改下面的 id 值
UPDATE `roles`
SET `permissions` = JSON_REMOVE(
    `permissions`,
    JSON_UNQUOTE(JSON_SEARCH(`permissions`, 'one', 'sales_outstock'))
)
WHERE `id` = 4
  AND JSON_SEARCH(`permissions`, 'one', 'sales_outstock') IS NOT NULL;

UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_customer_view')  WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_customer_view"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_customer_edit')  WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_customer_edit"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_pool_claim')     WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_pool_claim"',     '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_followup_view')  WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_followup_view"',  '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_followup_add')   WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_followup_add"',   '$') = 0;
UPDATE `roles` SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'crm_report')         WHERE `id` = 4 AND JSON_CONTAINS(`permissions`, '"crm_report"',         '$') = 0;

-- ============================================
-- 清理辅助存储过程
-- ============================================
DROP PROCEDURE IF EXISTS `proc_add_column`;
DROP PROCEDURE IF EXISTS `proc_add_index`;

-- ============================================
-- 完毕！如果上方有 error 提示 "Column already exists"
-- 说明部分字段已添加过，可安全忽略。
-- ============================================
