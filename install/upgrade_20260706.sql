-- 进销存管理系统 - 数据库升级脚本 2026-07-06
-- 为采购订单明细增加行备注字段
-- 为销售订单明细增加行备注字段

SET @col_exists1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND COLUMN_NAME='remark');
SET @sql1 = IF(@col_exists1=0, 'ALTER TABLE purchase_order_items ADD COLUMN remark VARCHAR(500) DEFAULT NULL COMMENT ''行备注'' AFTER received_qty', 'SELECT ''purchase_order_items.remark列已存在''');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @col_exists2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_order_items' AND COLUMN_NAME='remark');
SET @sql2 = IF(@col_exists2=0, 'ALTER TABLE sales_order_items ADD COLUMN remark VARCHAR(500) DEFAULT NULL COMMENT ''行备注'' AFTER shipped_qty', 'SELECT ''sales_order_items.remark列已存在''');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- 销售出库明细增加行备注
SET @col_exists3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_outstock_items' AND COLUMN_NAME='remark');
SET @sql3 = IF(@col_exists3=0, 'ALTER TABLE sales_outstock_items ADD COLUMN remark VARCHAR(500) DEFAULT NULL COMMENT ''行备注'' AFTER amount', 'SELECT ''sales_outstock_items.remark列已存在''');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- 销售订单增加取消原因
SET @col_exists4 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_orders' AND COLUMN_NAME='cancel_reason');
SET @sql4 = IF(@col_exists4=0, 'ALTER TABLE sales_orders ADD COLUMN cancel_reason VARCHAR(500) DEFAULT NULL COMMENT ''取消原因'' AFTER remark', 'SELECT ''sales_orders.cancel_reason列已存在''');
PREPARE stmt4 FROM @sql4; EXECUTE stmt4; DEALLOCATE PREPARE stmt4;

-- 销售出库单增加撤销原因
SET @col_exists5 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_outstocks' AND COLUMN_NAME='cancel_reason');
SET @sql5 = IF(@col_exists5=0, 'ALTER TABLE sales_outstocks ADD COLUMN cancel_reason VARCHAR(500) DEFAULT NULL COMMENT ''撤销原因'' AFTER remark', 'SELECT ''sales_outstocks.cancel_reason列已存在''');
PREPARE stmt5 FROM @sql5; EXECUTE stmt5; DEALLOCATE PREPARE stmt5;

-- 销售退货单增加撤销原因
SET @col_exists6 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_returns' AND COLUMN_NAME='cancel_reason');
SET @sql6 = IF(@col_exists6=0, 'ALTER TABLE sales_returns ADD COLUMN cancel_reason VARCHAR(255) DEFAULT '''' COMMENT ''撤销原因'' AFTER remark', 'SELECT ''sales_returns.cancel_reason列已存在''');
PREPARE stmt6 FROM @sql6; EXECUTE stmt6; DEALLOCATE PREPARE stmt6;

-- 采购退货单增加撤销原因
SET @col_exists7 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_returns' AND COLUMN_NAME='cancel_reason');
SET @sql7 = IF(@col_exists7=0, 'ALTER TABLE purchase_returns ADD COLUMN cancel_reason VARCHAR(255) DEFAULT '''' COMMENT ''撤销原因'' AFTER remark', 'SELECT ''purchase_returns.cancel_reason列已存在''');
PREPARE stmt7 FROM @sql7; EXECUTE stmt7; DEALLOCATE PREPARE stmt7;
