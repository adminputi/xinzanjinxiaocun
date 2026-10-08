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

        // ========== 客户开发日期 ==========
        'customers_developed_at_v2' =>
            "ALTER TABLE customers
             ADD COLUMN developed_at DATE DEFAULT NULL COMMENT '开发日期'",

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

        // ========== CRM 跟进附件原始文件名 ==========
        // 上传文件会被重命名为随机串，需额外保存原始文件名用于展示和下载
        'customer_followups_attachment_name' =>
            "ALTER TABLE customer_followups
             ADD COLUMN attachment_name VARCHAR(255) DEFAULT '' COMMENT '附件原始文件名'",

        // ========== 收款单状态（支持作废，不作物理删除）==========
        'receipts_status' =>
            "ALTER TABLE receipts
             ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed=有效 cancelled=已作废'",
        'receipts_cancel_reason' =>
            "ALTER TABLE receipts
             ADD COLUMN cancel_reason VARCHAR(500) DEFAULT NULL COMMENT '作废原因'",
        'payments_status' =>
            "ALTER TABLE payments
             ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed=有效 cancelled=已作废'",
        'payments_cancel_reason' =>
            "ALTER TABLE payments
             ADD COLUMN cancel_reason VARCHAR(500) DEFAULT NULL COMMENT '作废原因'",

        // ========== 收款核销明细（一笔收款可核销多个订单，支持预收款与退款红冲）==========
        'receipt_allocations_table' =>
            "CREATE TABLE IF NOT EXISTS `receipt_allocations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `receipt_id` INT NOT NULL COMMENT '收款单ID',
                `customer_id` INT NOT NULL DEFAULT 0 COMMENT '客户ID',
                `order_id` INT NOT NULL DEFAULT 0 COMMENT '销售订单ID，0=未核销预收款',
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '核销金额，负数表示退款红冲',
                `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
                `user_id` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_receipt` (`receipt_id`),
                INDEX `idx_order` (`order_id`),
                INDEX `idx_customer` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'payment_allocations_table' =>
            "CREATE TABLE IF NOT EXISTS `payment_allocations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `payment_id` INT NOT NULL COMMENT '付款单ID',
                `supplier_id` INT NOT NULL DEFAULT 0 COMMENT '供应商ID',
                `order_id` INT NOT NULL DEFAULT 0 COMMENT '采购订单ID，0=未核销预付款',
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '核销金额，负数表示退款红冲',
                `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
                `user_id` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_payment` (`payment_id`),
                INDEX `idx_order` (`order_id`),
                INDEX `idx_supplier` (`supplier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ========== 历史收付款数据的核销迁移（执行一次即记录）==========
        // 历史收款单按其 order_id 生成等额核销记录；无关联订单的生成「未核销预收款」
        'receipt_allocations_backfill' =>
            "INSERT INTO receipt_allocations (receipt_id, customer_id, order_id, amount, remark, user_id, created_at)
             SELECT r.id, r.customer_id, COALESCE(r.order_id,0), r.amount, '历史数据迁移', r.user_id, r.created_at
             FROM receipts r
             WHERE NOT EXISTS (SELECT 1 FROM receipt_allocations a WHERE a.receipt_id = r.id)",
        'payment_allocations_backfill' =>
            "INSERT INTO payment_allocations (payment_id, supplier_id, order_id, amount, remark, user_id, created_at)
             SELECT p.id, p.supplier_id, COALESCE(p.order_id,0), p.amount, '历史数据迁移', p.user_id, p.created_at
             FROM payments p
             WHERE NOT EXISTS (SELECT 1 FROM payment_allocations a WHERE a.payment_id = p.id)",

        // ========== 库存金额层：移动加权平均成本 ==========
        'inventory_avg_cost' =>
            "ALTER TABLE inventory
             ADD COLUMN avg_cost DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '移动加权平均成本'",

        // ========== 打印模板类型放宽（老库为 ENUM，写入 quote/product_catalog 会 1265 截断）==========
        'print_templates_type_varchar' =>
            "ALTER TABLE print_templates MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'sales_outstock'",

        // ========== 日志类字段放宽（老库为 ENUM，恢复旧数据时新值会 1265 截断）==========
        'operation_logs_action_varchar' =>
            "ALTER TABLE operation_logs MODIFY COLUMN `action` VARCHAR(50) NOT NULL DEFAULT ''",
        'customer_transfer_logs_action_varchar' =>
            "ALTER TABLE customer_transfer_logs MODIFY COLUMN `action` VARCHAR(50) NOT NULL DEFAULT ''",
        'inventory_logs_type_varchar' =>
            "ALTER TABLE inventory_logs MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'in'",

        // ========== 跟进记录支持修改：记录最后修改人/修改时间 ==========
        'customer_followups_updated_at' =>
            "ALTER TABLE customer_followups
             ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL COMMENT '最后修改时间'",
        'customer_followups_updated_by' =>
            "ALTER TABLE customer_followups
             ADD COLUMN IF NOT EXISTS updated_by INT DEFAULT NULL COMMENT '最后修改人ID'",

        // ========== 销售合同模块 ==========
        // 合同不自建明细：商品明细即所关联报价单的明细，打印时作为「附件」渲染
        'sales_contracts_table' =>
            "CREATE TABLE IF NOT EXISTS `sales_contracts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `contract_no` VARCHAR(100) NOT NULL UNIQUE COMMENT '合同编号 HT+日期+序号',
                `customer_id` INT NOT NULL DEFAULT 0 COMMENT '甲方（客户）',
                `quote_id` INT NOT NULL COMMENT '来源报价单，明细即附件',
                `template_id` INT DEFAULT NULL COMMENT '使用的合同模板 print_templates.id',
                `total_amount` DECIMAL(12,2) DEFAULT 0 COMMENT '合同总金额',
                `payment_type` VARCHAR(30) NOT NULL DEFAULT 'full' COMMENT 'full=全款发货 deposit=定金+余款',
                `deposit_amount` DECIMAL(12,2) DEFAULT 0 COMMENT '定金金额',
                `balance_amount` DECIMAL(12,2) DEFAULT 0 COMMENT '尾款金额（总额-定金）',
                `prep_days` INT NOT NULL DEFAULT 7 COMMENT '备货周期（工作日）',
                `payment_terms` TEXT COMMENT '付款条款（按付款方式生成，可手工改）',
                `purchase_desc` VARCHAR(500) DEFAULT '' COMMENT '采购描述（金额自动带出，可改）',
                `delivery_place` VARCHAR(300) DEFAULT '' COMMENT '收货地点',
                `receiver_name` VARCHAR(50) DEFAULT '' COMMENT '甲方授权接货经办人',
                `receiver_phone` VARCHAR(30) DEFAULT '' COMMENT '接货人电话',
                `warranty` VARCHAR(200) DEFAULT '' COMMENT '质保条款',
                `tax_note` VARCHAR(200) DEFAULT '' COMMENT '价格说明，如不含税不含运费',
                `bank_name` VARCHAR(200) DEFAULT '' COMMENT '乙方开户行',
                `bank_account` VARCHAR(100) DEFAULT '' COMMENT '乙方账号号码',
                `hotline` VARCHAR(50) DEFAULT '' COMMENT '技术支持热线',
                `terms` TEXT COMMENT '其他约定',
                `status` ENUM('draft','confirmed','executing','completed','terminated') NOT NULL DEFAULT 'draft',
                `sign_date` DATE DEFAULT NULL COMMENT '签约日期',
                `effective_date` DATE DEFAULT NULL COMMENT '生效日期',
                `expiry_date` DATE DEFAULT NULL COMMENT '到期日期',
                `attachment` VARCHAR(500) DEFAULT NULL COMMENT '盖章扫描件',
                `employee_id` INT DEFAULT 0 COMMENT '业务员',
                `remark` TEXT,
                `user_id` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_customer` (`customer_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_quote` (`quote_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='销售合同'",
        'contract_payment_types_table' =>
            "CREATE TABLE IF NOT EXISTS `contract_payment_types` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL COMMENT '付款方式名称',
                `code` VARCHAR(30) NOT NULL UNIQUE COMMENT '方式代码 full/deposit',
                `template` TEXT COMMENT '条款模板，支持 {total_amount} {deposit_amount} 等变量',
                `need_deposit` TINYINT NOT NULL DEFAULT 0 COMMENT '是否需填定金金额',
                `need_days` TINYINT NOT NULL DEFAULT 0 COMMENT '是否需填备货周期',
                `sort` INT NOT NULL DEFAULT 0,
                `status` TINYINT NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同付款方式'",
        // 用 INSERT IGNORE：新装库时 schema.sql 已预置，此处靠 code 唯一键自动跳过，不会重复
        'contract_payment_types_seed' =>
            "INSERT IGNORE INTO contract_payment_types (name, code, template, need_deposit, need_days, sort, status) VALUES
             ('全款发货', 'full', '甲方应支付合同全款 ¥{total_amount}（大写：{total_amount_cn}）。全款到达乙方指定账户后 {prep_days} 个工作日内备货完毕并安排发货。', 0, 1, 1, 1),
             ('定金+余款', 'deposit', '合同签订后甲方支付定金 ¥{deposit_amount}（大写：{deposit_amount_cn}），乙方收款后合同生效并安排备货；发货前甲方支付合同余款 ¥{balance_amount}（大写：{balance_amount_cn}），乙方收到后安排发货。', 1, 0, 2, 1)",
        'sales_quotes_contract_id' =>
            "ALTER TABLE sales_quotes
             ADD COLUMN IF NOT EXISTS contract_id INT DEFAULT NULL COMMENT '转出的合同ID'",
        // 报价转合同后需独立状态，否则只能复用 quoted（其标签为「已转订单」，含义不符）
        'sales_quotes_status_contracted' =>
            "ALTER TABLE sales_quotes MODIFY COLUMN `status` ENUM('draft','quoted','contracted','withdrawn') DEFAULT 'draft'",
        'sales_orders_contract_id' =>
            "ALTER TABLE sales_orders
             ADD COLUMN IF NOT EXISTS contract_id INT DEFAULT NULL COMMENT '来源合同ID'",
        // 合同双方信息：顶部甲乙信息 + 尾部签章区文本。
        // 四个块都是「默认自动联动生成、允许在合同编辑页改写」的自由文本，支持换行
        'sales_contracts_party_blocks' =>
            "ALTER TABLE sales_contracts
             ADD COLUMN IF NOT EXISTS party_a_info TEXT COMMENT '顶部甲方信息（默认联动客户，可改）',
             ADD COLUMN IF NOT EXISTS party_b_info TEXT COMMENT '顶部乙方信息（默认联动公司，可改）',
             ADD COLUMN IF NOT EXISTS party_a_sign TEXT COMMENT '甲方签章区文本（地址/联系人等合并，可换行）',
             ADD COLUMN IF NOT EXISTS party_b_sign TEXT COMMENT '乙方签章区文本（账号/开户行等合并，可换行）'",
        // 模板升级：签章区由「多个独立字段」改为「两个可自定义文本块」。
        // 用 REPLACE 精确替换旧片段——模板被手工改过、片段匹配不上时不会误伤，
        // 且 REPLACE 幂等（已含新占位符的模板再跑也不会变）
        'sales_contract_tpl_sign_a' =>
            "UPDATE print_templates SET content = REPLACE(content,
                '地址：{customer_address}<br>联系人：{customer_contact}<br>联系电话：{customer_phone}<br>签约日期：{sign_date}',
                '{party_a_sign}')
             WHERE type='sales_contract'",
        'sales_contract_tpl_sign_b' =>
            "UPDATE print_templates SET content = REPLACE(content,
                '账号号码：{bank_account}<br>开户行：{bank_name}<br>联系电话：{company_phone}<br>签约日期：{sign_date}',
                '{party_b_sign}')
             WHERE type='sales_contract'",
        // 签章标题已移进文本块的默认值里，模板里那句前缀要去掉，否则打印出来是两行标题。
        // 必须排在上面两条之后——先有了 {party_a_sign} 才匹配得上；幂等，重复执行无副作用
        'sales_contract_tpl_sign_title_a' =>
            "UPDATE print_templates SET content = REPLACE(content,
                '甲方（签章）：<br><br>{party_a_sign}', '{party_a_sign}')
             WHERE type='sales_contract'",
        'sales_contract_tpl_sign_title_b' =>
            "UPDATE print_templates SET content = REPLACE(content,
                '乙方（签章）：<br><br>{party_b_sign}', '{party_b_sign}')
             WHERE type='sales_contract'",
        // 存量合同数据升级：这四个块的默认值格式变了——顶部标签「单位名称：」改成
        // 「甲方：/乙方：」，签章块首行补上「甲方（签章）：」。
        // 只按开头特征命中明显是自动生成的内容，手工改过的不动（不匹配即跳过）；
        // 用 SUBSTRING 而非 REPLACE，避免正文里恰好也含「单位名称：」时被连带替换
        'sales_contracts_party_label_a' =>
            "UPDATE sales_contracts
             SET party_a_info = CONCAT('甲方：', SUBSTRING(party_a_info, CHAR_LENGTH('单位名称：') + 1))
             WHERE party_a_info LIKE '单位名称：%'",
        'sales_contracts_party_label_b' =>
            "UPDATE sales_contracts
             SET party_b_info = CONCAT('乙方：', SUBSTRING(party_b_info, CHAR_LENGTH('单位名称：') + 1))
             WHERE party_b_info LIKE '单位名称：%'",
        'sales_contracts_party_sign_title_a' =>
            "UPDATE sales_contracts SET party_a_sign = CONCAT('甲方（签章）：\n', party_a_sign)
             WHERE party_a_sign LIKE '地址：%'",
        'sales_contracts_party_sign_title_b' =>
            "UPDATE sales_contracts SET party_b_sign = CONCAT('乙方（签章）：\n', party_b_sign)
             WHERE party_b_sign LIKE '账号号码：%'",
        // 存量修复：早期「删了销售订单但合同没跟着回退」的合同会一直卡在执行中/已完成，
        // 既改不了也转不了单。这里按「已无任何有效订单」把它们退回已生效，并同步把
        // 来源报价单恢复成可编辑。
        // 必须用闭包而不是一条 UPDATE：报价单回退只在合同原本是 executing/completed 时
        // 才该做，一条 SQL 把合同状态改掉之后就区分不出它原来是什么状态了，会把
        // 「本来就只是已生效、从未转过订单」的合同对应的报价单也误改成 draft
        'sales_contracts_rollback_orphan' => function ($pdo) {
            $st = $pdo->query(
                "SELECT c.id, c.contract_no, c.quote_id
                 FROM sales_contracts c
                 WHERE c.status IN ('executing','completed')
                   AND NOT EXISTS (SELECT 1 FROM sales_orders o
                                   WHERE o.contract_id = c.id AND o.status <> 'cancelled')"
            );
            $updCt = $pdo->prepare("UPDATE sales_contracts SET status='confirmed' WHERE id=?");
            $updQt = $pdo->prepare("UPDATE sales_quotes SET status='draft' WHERE id=? AND status='contracted'");
            // 迁移也可能在无人登录的场合跑，取不到用户就不写业务日志，避免外键报错
            $uid = function_exists('get_user_id') ? intval(get_user_id()) : 0;
            foreach ($st->fetchAll() as $r) {
                $updCt->execute([$r['id']]);
                if (!empty($r['quote_id'])) $updQt->execute([$r['quote_id']]);
                if ($uid > 0) {
                    add_log($uid, 'update', 'sales_contract',
                        "存量修复：合同 {$r['contract_no']} 已无有效订单，状态回退为已生效（可再次编辑/转单），来源报价单恢复可编辑");
                }
            }
        },
    ];
    
    // 执行未完成的迁移
    $count = 0;
    foreach ($migrations as $key => $sql) {
        if (isset($executed[$key])) continue;
        
        try {
            // 迁移值可以是 SQL 字符串，也可以是闭包——有些数据修复一条 SQL 表达不了
            if ($sql instanceof Closure) {
                $sql($pdo);
            } else {
                // MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS，用 try-catch 兜底
                $pdo->exec($sql);
            }
            $pdo->prepare("INSERT INTO _migrations (migration_key) VALUES (?)")->execute([$key]);
            $count++;
        } catch (Exception $e) {
            // 闭包迁移用不了「去掉 IF NOT EXISTS 重试」那套兜底，失败就记日志、下次再试
            if ($sql instanceof Closure) {
                error_log("Migration [$key] failed: " . $e->getMessage());
                continue;
            }
            // 列/表已存在时忽略错误（兼容不支持 IF NOT EXISTS 的 MySQL 版本）
            $isDuplicate = (stripos($e->getMessage(), 'Duplicate') !== false 
                || stripos($e->getMessage(), 'already exists') !== false);

            // 官方 MySQL 不支持 "ADD COLUMN IF NOT EXISTS"（MariaDB 才支持），
            // 报语法错误时去掉 IF NOT EXISTS 再重试一次，保证字段一定能加上
            if (!$isDuplicate
                && stripos($e->getMessage(), 'syntax') !== false
                && stripos($sql, 'ADD COLUMN') !== false
                && stripos($sql, 'IF NOT EXISTS') !== false) {
                try {
                    $pdo->exec(str_ireplace('IF NOT EXISTS', '', $sql));
                    $isDuplicate = true;
                } catch (Exception $e2) {
                    if (stripos($e2->getMessage(), 'Duplicate') !== false
                        || stripos($e2->getMessage(), 'already exists') !== false) {
                        $isDuplicate = true;
                    } else {
                        error_log("Migration [$key] retry failed: " . $e2->getMessage());
                    }
                }
            }

            if ($isDuplicate) {
                // 表/列已存在视为迁移完成，标记已执行
                try {
                    $pdo->prepare("INSERT IGNORE INTO _migrations (migration_key) VALUES (?)")->execute([$key]);
                } catch (Exception $ignored) {}
            } else {
                // 其他错误：记录日志，不标记已执行（下次会重试）
                error_log("Migration [$key] failed: " . $e->getMessage());
            }
        }
    }
    
    if ($count > 0) {
        error_log("Migrations executed: $count new schema changes applied.");
    }
}
