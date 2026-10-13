<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/migration.php';

$pdo = getDB();
// 确保新增字段（如库存平均成本、收款状态）已就绪，避免查询时字段不存在
run_migrations();

// 统计数据
$stats = [];

// ===== 看板可见性：每一块按权限码决定「查不查、显不显示」 =====
// 用权限码而不是角色名，自定义角色只要在「系统管理-角色权限」里勾了就能看到，不用改代码。
// admin 在 check_permission() 里恒为 true，看到的是全量。
$can = [
    'product'      => check_permission('product_view'),
    'inventory'    => check_permission('inventory_view'),
    'stock_value'  => check_permission('report_inventory'),  // 库存压了多少钱，属金额敏感
    'purchase'     => check_permission('purchase_order'),
    'purchase_in'  => check_permission('purchase_instock'),
    'sales'        => check_permission('sales_outstock'),
    'sales_order'  => check_permission('sales_order'),
    'finance'      => check_permission('finance_arpay'),
    'aging'        => check_permission('finance_aging'),
    'report_sales' => check_permission('report_sales'),
    'crm'          => check_permission('crm_customer_view'),
    'crm_report'   => check_permission('crm_report'),
];

// ===== 数据范围：勾了 view_all 才看全公司，否则只看「归属自己的客户」 =====
// 口径统一按 customers.owner_id（客户归属经理），与 CRM 各页面一致
$crmAll    = check_crm_all();
$salesAll  = check_sales_all();
$crmScope  = owner_scope_where($crmAll, 'c');    // CRM：customers 别名固定 c
$salesScope = owner_scope_where($salesAll, 'c'); // 销售单据：需自己 LEFT JOIN customers c

// 商品总数
$stats['products'] = 0;
if ($can['product']) {
    $stats['products'] = $pdo->query("SELECT COUNT(*) FROM products WHERE status=1")->fetchColumn();
}

// 库存总价值（优先用移动加权平均成本，未计算成本的回退采购价）
$stats['stock_value'] = 0;
if ($can['stock_value']) {
    $stmt = $pdo->query("SELECT COALESCE(SUM(i.quantity * CASE WHEN COALESCE(i.avg_cost,0)>0 THEN i.avg_cost ELSE p.purchase_price END),0) FROM inventory i JOIN products p ON i.product_id=p.id");
    $stats['stock_value'] = $stmt->fetchColumn() ?: 0;
}

// 低库存商品数
$stats['low_stock'] = 0;
if ($can['inventory']) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) as quantity FROM inventory GROUP BY product_id) i ON p.id=i.product_id WHERE p.min_stock>0 AND p.status=1 AND COALESCE(i.quantity,0)<=p.min_stock");
    $stats['low_stock'] = $stmt->fetchColumn() ?: 0;
}

// 本月采购金额（采购没有归属概念，只看权限不看范围）
$stats['purchase_month'] = 0;
if ($can['purchase']) {
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_instocks WHERE status='confirmed' AND MONTH(instock_date)=MONTH(CURDATE()) AND YEAR(instock_date)=YEAR(CURDATE())");
    $stats['purchase_month'] = $stmt->fetchColumn();
}

// 本月销售金额（按客户归属隔离：没勾 sales_view_all 的人只统计自己客户的单）
$stats['sales_month'] = 0;
if ($can['sales']) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM sales_outstocks o
        LEFT JOIN customers c ON c.id=o.customer_id
        WHERE o.status='confirmed' AND MONTH(o.outstock_date)=MONTH(CURDATE()) AND YEAR(o.outstock_date)=YEAR(CURDATE())" . $salesScope['where']);
    $stmt->execute($salesScope['params']);
    $stats['sales_month'] = $stmt->fetchColumn();
}

// 应收/应付统一口径：与「应收应付」「账龄分析」「客户对账」使用同一函数，四处不会再算出四个数
//   应收余额 = 有效订单总额 - 已收 + 客户期初 - 已确认退货
//   应付余额 = 有效采购订单总额 - 已付 - 已确认采购退货
// 应收按客户归属过滤（get_ar_totals 不传参 = 全公司，行为与原来完全一致）
$arTotals = ['balance'=>0, 'within30'=>0, 'within60'=>0, 'within90'=>0, 'over90'=>0];
$apTotals = ['balance'=>0];
if ($can['finance'] || $can['aging']) {
    $arTotals = get_ar_totals($salesAll ? [] : ['owner_user_id' => get_user_id()]);
}
if ($can['finance']) {
    $apTotals = get_ap_totals();
}
$stats['receivable'] = $arTotals['balance'];
$stats['payable']    = $apTotals['balance'];

// 待审批单据
$stats['pending_purchase'] = 0;
$stats['pending_sales']    = 0;
$stats['pending_purchase_instock'] = 0;
if ($can['purchase']) {
    $stats['pending_purchase'] = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='draft'")->fetchColumn();
}
if ($can['sales_order']) {
    $stats['pending_sales'] = $pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='draft'")->fetchColumn();
}
if ($can['purchase_in']) {
    $stats['pending_purchase_instock'] = $pdo->query("SELECT COUNT(*) FROM purchase_instocks WHERE status='draft'")->fetchColumn();
}

// 今日销售汇总
$today = date('Y-m-d');
$todaySales = ['amt'=>0, 'cnt'=>0];
if ($can['sales']) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) as amt, COUNT(*) as cnt FROM sales_outstocks o
        LEFT JOIN customers c ON c.id=o.customer_id
        WHERE o.status='confirmed' AND o.outstock_date=?" . $salesScope['where']);
    $stmt->execute(array_merge([$today], $salesScope['params']));
    $todaySales = $stmt->fetch();
}

// 今日采购汇总
$todayPurchase = ['amt'=>0, 'cnt'=>0];
if ($can['purchase']) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as amt, COUNT(*) as cnt FROM purchase_instocks WHERE status='confirmed' AND instock_date=?");
    $stmt->execute([$today]);
    $todayPurchase = $stmt->fetch();
}

// 应收账款到期提醒（30天内到期）
//   财务：看全部（内置财务角色已回填 sales_view_all）
//   非财务但有客户/销售权限（如销售）：只看自己客户的那几行，看不到别人的欠款
$showArDue  = $can['finance'] || $can['sales'] || $can['crm'];
$arDueScope = owner_scope_where($can['finance'] ? $salesAll : false, 'c');
$arDueList = [];
if ($showArDue) {
    $arDueStmt = $pdo->prepare("SELECT o.id, o.bill_no, c.name as customer_name, (o.total_amount - o.received_amount) as balance, o.delivery_date
        FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id
        WHERE o.status NOT IN('draft','cancelled')
        AND o.total_amount > o.received_amount" . $arDueScope['where'] . "
        ORDER BY o.delivery_date ASC LIMIT 10");
    $arDueStmt->execute($arDueScope['params']);
    $arDueList = $arDueStmt->fetchAll();
}

// 近30天销售趋势
$salesTrend = [];
if ($can['report_sales']) {
    $trendStmt = $pdo->prepare("SELECT DATE(o.outstock_date) as dt, COALESCE(SUM(o.total_amount),0) as amt FROM sales_outstocks o
        LEFT JOIN customers c ON c.id=o.customer_id
        WHERE o.status='confirmed' AND o.outstock_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $salesScope['where'] . "
        GROUP BY DATE(o.outstock_date) ORDER BY dt");
    $trendStmt->execute($salesScope['params']);
    $salesTrend = $trendStmt->fetchAll();
}

// 销售排行TOP10
$topProducts = [];
if ($can['report_sales']) {
    $topStmt = $pdo->prepare("SELECT p.name, SUM(oi.quantity) as qty, SUM(oi.amount) as amt FROM sales_outstock_items oi
        JOIN sales_outstocks o ON oi.outstock_id=o.id
        JOIN products p ON oi.product_id=p.id
        LEFT JOIN customers c ON c.id=o.customer_id
        WHERE o.status='confirmed' AND o.outstock_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $salesScope['where'] . "
        GROUP BY oi.product_id ORDER BY amt DESC LIMIT 10");
    $topStmt->execute($salesScope['params']);
    $topProducts = $topStmt->fetchAll();
}

// 库存预警列表
$warnProducts = [];
if ($can['inventory']) {
    $warnStmt = $pdo->query("SELECT p.id, p.name, p.sku, p.min_stock, p.purchase_price, COALESCE(i.quantity,0) as quantity FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) as quantity FROM inventory GROUP BY product_id) i ON p.id=i.product_id WHERE p.min_stock>0 AND p.status=1 AND COALESCE(i.quantity,0)<=p.min_stock ORDER BY (COALESCE(i.quantity,0)-p.min_stock) LIMIT 10");
    $warnProducts = $warnStmt->fetchAll();
}

// 应收账款-账龄分桶（与账龄分析页同源：期初应收归入 90 天以上）
$aging = [
    'within30' => $arTotals['within30'],
    'within60' => $arTotals['within60'],
    'within90' => $arTotals['within90'],
    'over90'   => $arTotals['over90'],
];

// ==================== CRM 统计数据（仅授权用户，且按客户归属隔离） ====================
$showCrm = $can['crm'];
$crmStats      = ['total'=>0, 'pool'=>0, 'owned'=>0, 'new_month'=>0, 'followups_month'=>0, 'followups_today'=>0, 'high_intent'=>0];
$sourceData    = [];
$intentionData = [];
$ownerRank     = [];
$ownerRankTotal = 0;
$todayFollowups = [];
$pendingTotal  = 0;
$pendingOverdue = 0;
$newCustTrend  = [];
$followTrend   = [];
if ($showCrm) {
// 公海客户数：公海是共享池、谁都能认领，不做归属过滤，否则业务员就看不到可认领的客户了
$crmStats['pool'] = $pdo->query("SELECT COUNT(*) FROM customers WHERE in_pool=1 AND status=1")->fetchColumn();

if ($crmAll) {
    $crmStats['total'] = $pdo->query("SELECT COUNT(*) FROM customers WHERE status=1")->fetchColumn();
    $crmStats['owned'] = $crmStats['total'] - $crmStats['pool'];
} else {
    // 只看自己：归属数直接查，「总数」= 我的归属 + 公海（即我在系统里能接触到的客户）
    $stOwn = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.status=1 AND c.in_pool=0" . $crmScope['where']);
    $stOwn->execute($crmScope['params']);
    $crmStats['owned'] = (int)$stOwn->fetchColumn();
    $crmStats['total'] = $crmStats['owned'] + $crmStats['pool'];
}

// 本月新增客户
$stNew = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.status=1 AND MONTH(c.created_at)=MONTH(CURDATE()) AND YEAR(c.created_at)=YEAR(CURDATE())" . $crmScope['where']);
$stNew->execute($crmScope['params']);
$crmStats['new_month'] = $stNew->fetchColumn();

// 本月跟进次数（跟进记录按客户的归属过滤）
$stFm = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f JOIN customers c ON c.id=f.customer_id WHERE MONTH(f.created_at)=MONTH(CURDATE()) AND YEAR(f.created_at)=YEAR(CURDATE())" . $crmScope['where']);
$stFm->execute($crmScope['params']);
$crmStats['followups_month'] = $stFm->fetchColumn();

// 今日跟进次数
$stFt = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f JOIN customers c ON c.id=f.customer_id WHERE DATE(f.created_at)=CURDATE()" . $crmScope['where']);
$stFt->execute($crmScope['params']);
$crmStats['followups_today'] = $stFt->fetchColumn();

// 高意向客户数
$stHi = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE c.status=1 AND c.in_pool=0 AND c.intention='高'" . $crmScope['where']);
$stHi->execute($crmScope['params']);
$crmStats['high_intent'] = $stHi->fetchColumn();

// 客户来源分布
$stSrc = $pdo->prepare("SELECT COALESCE(s.name,'未设置') as source_name, COUNT(*) as cnt FROM customers c LEFT JOIN customer_sources s ON c.source_id=s.id WHERE c.status=1" . $crmScope['where'] . " GROUP BY c.source_id ORDER BY cnt DESC");
$stSrc->execute($crmScope['params']);
$sourceData = $stSrc->fetchAll();

// 意向程度分布
$stInt = $pdo->prepare("SELECT COALESCE(c.intention,'未知') as intention, COUNT(*) as cnt FROM customers c WHERE c.status=1" . $crmScope['where'] . " GROUP BY c.intention");
$stInt->execute($crmScope['params']);
$intentionData = $stInt->fetchAll();

// 业务经理客户排名（同事之间的对比，只给有 crm_report 的管理层看；排名本身是全公司口径）
$ownerRankTotal = 0;
if ($can['crm_report']) {
    $ownerRank = $pdo->query("SELECT u.real_name, COUNT(*) as cnt FROM customers c JOIN users u ON c.owner_id=u.id WHERE c.status=1 AND c.in_pool=0 GROUP BY c.owner_id ORDER BY cnt DESC LIMIT 10")->fetchAll();
    // 占比分母 = 全公司归属客户总数。注意不能用 $crmStats['owned']：
    // 非管理员登录时那只是「他自己的客户数」，171/19 会算出 900% 这种占比
    $ownerRankTotal = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status=1 AND in_pool=0")->fetchColumn();
}

// 待跟进列表：今日计划 + 逾期未跟进（与跟进记录页「待跟进」Tab 同口径，用公共 SQL 片段）
// 排序 next_follow_at ASC → 逾期最久的排最前
$stToday = $pdo->prepare("SELECT c.name as customer_name, c.id as customer_id, u.real_name as owner_name, f.content, f.follow_type, f.next_follow_at,
    (SELECT MIN(f2.created_at) FROM customer_followups f2
      WHERE f2.customer_id = f.customer_id AND f2.created_at > f.next_follow_at) AS done_after_at
    FROM customer_followups f 
    JOIN customers c ON f.customer_id=c.id 
    JOIN users u ON f.user_id=u.id
    WHERE c.status=1" . sql_follow_pending() . $crmScope['where'] . "
    ORDER BY f.next_follow_at ASC LIMIT 10");
$stToday->execute(array_merge([follow_tomorrow_start()], $crmScope['params']));
$todayFollowups = $stToday->fetchAll();

// 待跟进总数（含逾期），单独统计，不受上面 LIMIT 10 影响
$stPendTotal = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f JOIN customers c ON f.customer_id=c.id WHERE c.status=1" . sql_follow_pending() . $crmScope['where']);
$stPendTotal->execute(array_merge([follow_tomorrow_start()], $crmScope['params']));
$pendingTotal = (int)$stPendTotal->fetchColumn();

$stPendOverdue = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f JOIN customers c ON f.customer_id=c.id WHERE c.status=1" . sql_follow_pending() . $crmScope['where'] . " AND f.next_follow_at <= ?");
$stPendOverdue->execute(array_merge([follow_tomorrow_start(), date('Y-m-d H:i:s')], $crmScope['params']));
$pendingOverdue = (int)$stPendOverdue->fetchColumn();

// 近30天新增客户趋势
$stNc = $pdo->prepare("SELECT DATE(c.created_at) as dt, COUNT(*) as cnt FROM customers c WHERE c.status=1 AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $crmScope['where'] . " GROUP BY DATE(c.created_at) ORDER BY dt");
$stNc->execute($crmScope['params']);
$newCustTrend = $stNc->fetchAll();

// 近30天跟进趋势
$stFt2 = $pdo->prepare("SELECT DATE(f.created_at) as dt, COUNT(*) as cnt FROM customer_followups f JOIN customers c ON c.id=f.customer_id WHERE f.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $crmScope['where'] . " GROUP BY DATE(f.created_at) ORDER BY dt");
$stFt2->execute($crmScope['params']);
$followTrend = $stFt2->fetchAll();
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-gauge-high"></i> 首页看板</h1>
    <div class="page-actions">
        <span style="color:var(--gray-500);font-size:13px;"><?= date('Y年m月d日 星期') ?><?= ['日','一','二','三','四','五','六'][date('w')] ?></span>
    </div>
</div>

<!-- 统计卡片（按权限显示：跟自己岗位无关的数字不再出现） -->
<div class="stats-grid">
    <?php if ($can['product'] || $can['inventory']): ?>
    <a href="modules/inventory/stock.php" class="stat-card stat-card-clickable">
        <div class="stat-icon blue"><i class="fa-solid fa-box"></i></div>
        <div class="stat-content">
            <div class="stat-label">商品总数</div>
            <div class="stat-value"><?= $stats['products'] ?></div>
            <?php if ($can['inventory']): ?>
            <div class="stat-sub">低库存预警 <b style="color:var(--danger)"><?= $stats['low_stock'] ?></b> 种</div>
            <?php endif; ?>
        </div>
    </a>
    <?php endif; ?>
    <?php if ($can['stock_value']): ?>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fa-solid fa-coins"></i></div>
        <div class="stat-content">
            <div class="stat-label">库存价值</div>
            <div class="stat-value">¥<?= format_money($stats['stock_value']) ?></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($can['purchase']): ?>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fa-solid fa-cart-shopping"></i></div>
        <div class="stat-content">
            <div class="stat-label">本月采购</div>
            <div class="stat-value">¥<?= format_money($stats['purchase_month']) ?></div>
            <div class="stat-sub">待审核 <?= $stats['pending_purchase'] ?> 单</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($can['sales']): ?>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fa-solid fa-sack-dollar"></i></div>
        <div class="stat-content">
            <div class="stat-label"><?= $salesAll ? '本月销售' : '本月销售（我的客户）' ?></div>
            <div class="stat-value">¥<?= format_money($stats['sales_month']) ?></div>
            <?php if ($can['sales_order']): ?>
            <div class="stat-sub">待审核 <?= $stats['pending_sales'] ?> 单</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($can['finance']): ?>
    <div class="stat-card">
        <div class="stat-icon cyan"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="stat-content">
            <div class="stat-label"><?= $salesAll ? '应收账款' : '应收账款（我的客户）' ?></div>
            <div class="stat-value">¥<?= format_money($stats['receivable']) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fa-solid fa-credit-card"></i></div>
        <div class="stat-content">
            <div class="stat-label">应付账款</div>
            <div class="stat-value">¥<?= format_money($stats['payable']) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 待办提醒工作台 -->
<div class="dashboard-grid-3">
    <!-- 待审批单据 -->
    <?php if ($can['purchase'] || $can['sales_order'] || $can['purchase_in']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-clipboard-check" style="color:var(--warning)"></i> 待审批单据</h3>
        </div>
        <div class="card-body" style="padding:10px 16px;">
            <?php $pendingCount = $stats['pending_purchase'] + $stats['pending_sales'] + $stats['pending_purchase_instock']; ?>
            <?php if ($pendingCount > 0): ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php if ($can['purchase'] && $stats['pending_purchase'] > 0): ?>
                <a href="modules/purchase/order.php" style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--warning-light,#fffbeb);border-radius:6px;border:1px solid #fde68a;">
                    <span><i class="fa-solid fa-cart-shopping" style="color:var(--warning)"></i> 采购订单待审核</span>
                    <span class="badge badge-warning"><?= $stats['pending_purchase'] ?> 单</span>
                </a>
                <?php endif; ?>
                <?php if ($can['sales_order'] && $stats['pending_sales'] > 0): ?>
                <a href="modules/sales/order.php" style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--primary-light,#eef0ff);border-radius:6px;border:1px solid #c7d2fe;">
                    <span><i class="fa-solid fa-bag-shopping" style="color:var(--primary)"></i> 销售订单待审核</span>
                    <span class="badge badge-primary"><?= $stats['pending_sales'] ?> 单</span>
                </a>
                <?php endif; ?>
                <?php if ($can['purchase_in'] && $stats['pending_purchase_instock'] > 0): ?>
                <a href="modules/purchase/instock.php" style="display:flex;justify-content:space-between;padding:8px 12px;background:#f0fdf4;border-radius:6px;border:1px solid #bbf7d0;">
                    <span><i class="fa-solid fa-boxes-packing" style="color:var(--success)"></i> 入库单待确认</span>
                    <span class="badge badge-success"><?= $stats['pending_purchase_instock'] ?> 单</span>
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:12px;color:var(--gray-400);"><i class="fa-solid fa-check-circle" style="color:var(--success);font-size:20px;"></i><p style="margin:4px 0 0;font-size:13px;">暂无待审批单据</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 今日经营概况：四个格子各自按权限显示，无关的格子直接不出现 -->
    <?php if ($can['sales'] || $can['purchase'] || $can['finance']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-calendar-day" style="color:var(--primary)"></i> 今日经营概况</h3>
        </div>
        <div class="card-body" style="padding:10px 16px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <?php if ($can['sales']): ?>
                <div style="text-align:center;padding:10px;background:var(--primary-light,#eef0ff);border-radius:8px;">
                    <div style="font-size:20px;font-weight:700;color:var(--primary);">¥<?= format_money($todaySales['amt']) ?></div>
                    <div style="font-size:11px;color:var(--gray-500);"><?= $salesAll ? '今日销售' : '今日销售（我的客户）' ?><?= "（{$todaySales['cnt']}单）" ?></div>
                </div>
                <?php endif; ?>
                <?php if ($can['purchase']): ?>
                <div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px;">
                    <div style="font-size:20px;font-weight:700;color:var(--success);">¥<?= format_money($todayPurchase['amt']) ?></div>
                    <div style="font-size:11px;color:var(--gray-500);">今日采购（<?= $todayPurchase['cnt'] ?>单）</div>
                </div>
                <?php endif; ?>
                <?php if ($can['finance']): ?>
                <div style="text-align:center;padding:10px;background:#fffbeb;border-radius:8px;">
                    <div style="font-size:20px;font-weight:700;color:var(--warning);">¥<?= format_money($stats['receivable']) ?></div>
                    <div style="font-size:11px;color:var(--gray-500);">当前应收款</div>
                </div>
                <div style="text-align:center;padding:10px;background:#fef2f2;border-radius:8px;">
                    <div style="font-size:20px;font-weight:700;color:var(--danger);">¥<?= format_money($stats['payable']) ?></div>
                    <div style="font-size:11px;color:var(--gray-500);">当前应付款</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 应收账款到期提醒：财务看全部；销售等角色只列自己客户的行 -->
    <?php if ($showArDue): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-clock" style="color:var(--danger)"></i> 应收款到期提醒<?= $arDueScope['where'] ? '（我的客户）' : '' ?></h3>
            <?php if ($can['aging']): ?><a href="modules/finance/aging.php" class="btn btn-sm btn-outline">全部</a><?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($arDueList): ?>
            <table>
                <thead><tr><th>客户</th><th>金额</th><th>交货日</th></tr></thead>
                <tbody>
                    <?php foreach ($arDueList as $ar): 
                        $overdue = $ar['delivery_date'] && $ar['delivery_date'] < $today;
                    ?>
                    <tr>
                        <td><a href="modules/sales/order_view.php?id=<?=$ar['id']?>"><?=htmlspecialchars(mb_substr($ar['customer_name']?:$ar['bill_no'],0,8))?></a></td>
                        <td><b style="color:<?=$overdue?'var(--danger)':'var(--gray-700)'?>">¥<?=format_money($ar['balance'])?></b></td>
                        <td><?=$ar['delivery_date']?:'-'?><?=$overdue?' <span class="badge badge-danger">逾期</span>':''?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-check-circle" style="color:var(--success)"></i><p style="font-size:13px;">无到期应收款</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 图表区（只显示有权限的那张；只剩一张时改单列，避免半边空白） -->
<?php if ($can['report_sales'] || $can['aging']): ?>
<div class="dashboard-charts"<?= ($can['report_sales'] && $can['aging']) ? '' : ' style="grid-template-columns:1fr;"' ?>>
    <?php if ($can['report_sales']): ?>
    <div class="card">
        <div class="card-header"><h3 class="card-title">近30天销售趋势<?= $salesAll ? '' : '（我的客户）' ?></h3></div>
        <div class="card-body" style="height:300px;">
            <canvas id="salesChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($can['aging']): ?>
    <div class="card">
        <div class="card-header"><h3 class="card-title">账龄分析</h3></div>
        <div class="card-body" style="height:300px;">
            <canvas id="agingChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 销售排行 & 库存预警（只显示有权限的那块） -->
<?php if ($can['report_sales'] || $can['inventory']): ?>
<div class="dashboard-grid-2"<?= ($can['report_sales'] && $can['inventory']) ? '' : ' style="grid-template-columns:1fr;"' ?>>
    <?php if ($can['report_sales']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-ranking-star"></i> 销售排行 TOP10 (近30天)<?= $salesAll ? '' : '（我的客户）' ?></h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($topProducts): ?>
            <table>
                <thead><tr><th>排名</th><th>商品名称</th><th>销量</th><th>金额</th></tr></thead>
                <tbody>
                    <?php foreach ($topProducts as $i => $item): ?>
                    <tr>
                        <td><span class="badge badge-<?= $i<3 ? 'primary' : 'gray' ?>"><?= $i+1 ?></span></td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= $item['qty'] ?></td>
                        <td>¥<?= format_money($item['amt']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-chart-line"></i><p>暂无销售数据</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can['inventory']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warning)"></i> 低库存预警</h3>
            <a href="modules/inventory/stock.php" class="btn btn-sm btn-outline">查看全部</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($warnProducts): ?>
            <table>
                <thead><tr><th>商品名称</th><th>SKU</th><th>当前库存</th><th>最低库存</th><th>状态</th></tr></thead>
                <tbody>
                    <?php foreach ($warnProducts as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= $item['sku'] ?></td>
                        <td><span class="badge badge-danger"><?= $item['quantity'] ?></span></td>
                        <td><?= $item['min_stock'] ?></td>
                        <td><span class="badge badge-danger">库存不足</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-check-circle" style="color:var(--success)"></i><p>库存充足，暂无预警</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ==================== CRM 客户看板（仅授权用户可见） ==================== -->
<?php if ($showCrm): ?>
<div style="margin-top:24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
        <i class="fa-solid fa-user-group" style="font-size:18px;color:var(--primary);"></i>
        <h2 style="font-size:16px;font-weight:700;margin:0;">CRM 客户看板<?= $crmAll ? '' : '（我的客户）' ?></h2>
        <span style="height:1px;flex:1;background:var(--gray-200);"></span>
        <?php if ($can['crm_report']): ?><a href="modules/crm/report.php" class="btn btn-sm btn-outline">完整报表 →</a><?php endif; ?>
    </div>

    <!-- CRM 统计卡片 -->
    <div class="stats-grid">
        <a href="modules/crm/customers.php" class="stat-card stat-card-clickable">
            <div class="stat-icon blue"><i class="fa-solid fa-address-book"></i></div>
            <div class="stat-content">
                <div class="stat-label">客户总数</div>
                <div class="stat-value"><?= $crmStats['total'] ?></div>
                <div class="stat-sub"><?= $crmAll ? '归属' : '我的归属' ?> <b><?= $crmStats['owned'] ?></b> 个，公海 <b style="color:var(--warning)"><?= $crmStats['pool'] ?></b> 个</div>
            </div>
        </a>
        <a href="modules/crm/pool.php" class="stat-card stat-card-clickable">
            <div class="stat-icon cyan"><i class="fa-solid fa-water"></i></div>
            <div class="stat-content">
                <div class="stat-label">公海客户</div>
                <div class="stat-value"><?= $crmStats['pool'] ?></div>
                <div class="stat-sub">占比 <?= $crmStats['total']>0 ? round($crmStats['pool']/$crmStats['total']*100,1) : 0 ?>%</div>
            </div>
        </a>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-user-plus"></i></div>
            <div class="stat-content">
                <div class="stat-label">本月新增客户</div>
                <div class="stat-value"><?= $crmStats['new_month'] ?></div>
            </div>
        </div>
        <a href="modules/crm/followups.php" class="stat-card stat-card-clickable">
            <div class="stat-icon purple"><i class="fa-solid fa-comments"></i></div>
            <div class="stat-content">
                <div class="stat-label">本月跟进</div>
                <div class="stat-value"><?= $crmStats['followups_month'] ?></div>
                <div class="stat-sub">今日 <b><?= $crmStats['followups_today'] ?></b> 次</div>
            </div>
        </a>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-star"></i></div>
            <div class="stat-content">
                <div class="stat-label">高意向客户</div>
                <div class="stat-value"><?= $crmStats['high_intent'] ?></div>
                <div class="stat-sub">占归属客户 <?= $crmStats['owned']>0 ? round($crmStats['high_intent']/$crmStats['owned']*100,1) : 0 ?>%</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-content">
                <div class="stat-label">待跟进</div>
                <div class="stat-value"><?= $pendingTotal ?></div>
                <div class="stat-sub"><?= $pendingOverdue > 0 ? "含逾期 {$pendingOverdue} 条" : '今日计划 + 逾期未跟进' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- CRM 图表区 -->
<div class="dashboard-charts">
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> 客户来源分布</h3></div>
        <div class="card-body" style="height:300px;">
            <canvas id="sourceChart"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-doughnut" style="color:var(--success)"></i> 意向程度分布</h3></div>
        <div class="card-body" style="height:300px;">
            <canvas id="intentionChart"></canvas>
        </div>
    </div>
</div>

<!-- CRM 趋势图 -->
<div class="dashboard-charts">
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-line" style="color:var(--primary)"></i> 近30天新增客户趋势</h3></div>
        <div class="card-body" style="height:280px;">
            <canvas id="newCustChart"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--warning)"></i> 近30天跟进趋势</h3></div>
        <div class="card-body" style="height:280px;">
            <canvas id="followChart"></canvas>
        </div>
    </div>
</div>

<!-- CRM 数据表格 -->
<div class="dashboard-grid-2"<?= $can['crm_report'] ? '' : ' style="grid-template-columns:1fr;"' ?>>
    <?php if ($can['crm_report']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-medal" style="color:var(--warning)"></i> 业务经理客户排名 TOP10</h3>
            <a href="modules/crm/customers.php" class="btn btn-sm btn-outline">全部客户</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($ownerRank): ?>
            <table>
                <thead><tr><th>排名</th><th>业务经理</th><th>客户数</th><th>占比</th></tr></thead>
                <tbody>
                    <?php foreach ($ownerRank as $i => $row): ?>
                    <tr>
                        <td><span class="badge badge-<?= $i<3 ? 'primary' : 'gray' ?>"><?= $i+1 ?></span></td>
                        <td><?= htmlspecialchars($row['real_name']) ?></td>
                        <td><?= $row['cnt'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;height:6px;background:var(--gray-100);border-radius:3px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $ownerRankTotal>0 ? round($row['cnt']/$ownerRankTotal*100,1) : 0 ?>%;background:var(--primary);border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px;color:var(--gray-500);white-space:nowrap;"><?= $ownerRankTotal>0 ? round($row['cnt']/$ownerRankTotal*100,1) : 0 ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-user-slash"></i><p>暂无已归属客户</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fa-solid fa-calendar-check" style="color:var(--primary)"></i> 待跟进客户（含逾期）<?= $crmAll ? '' : '（我的）' ?></h3>
            <a href="modules/crm/followups.php?tab=today_pending" class="btn btn-sm btn-outline">全部跟进</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($todayFollowups): ?>
            <table>
                <thead><tr><th>客户</th><th>负责人</th><th>方式</th><th>计划时间</th><th>内容</th></tr></thead>
                <tbody>
                    <?php foreach ($todayFollowups as $fu): ?>
                    <?php $fuSt = follow_plan_status($fu['next_follow_at'], null, $fu['done_after_at'] ?? null); ?>
                    <?php $fuOverdue = $fuSt['state'] === 'overdue'; ?>
                    <tr<?=$fuOverdue?' style="background:rgba(220,53,69,.07);"':''?>>
                        <td><a href="modules/crm/customer_detail.php?id=<?= $fu['customer_id'] ?>"><?= htmlspecialchars(mb_substr($fu['customer_name'],0,8)) ?></a></td>
                        <td><?= htmlspecialchars($fu['owner_name']) ?></td>
                        <td><span class="badge badge-info"><?= $fu['follow_type'] ?></span></td>
                        <td style="font-size:12px;white-space:nowrap;color:<?=$fuOverdue?'var(--danger);font-weight:600':'var(--gray-500)'?>;"><?=format_datetime_short($fu['next_follow_at'])?><?=$fuOverdue?($fuSt['days']>0?' 逾期'.$fuSt['days'].'天':' 已到点'):''?></td>
                        <td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($fu['content']) ?>"><?= htmlspecialchars(mb_substr($fu['content'],0,20)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-check-circle" style="color:var(--success)"></i><p>暂无待跟进客户</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 系统/开发信息：公开板块，登录即可见（PHP版本、数据库版本、上传路径、安装时间、作者QQ） -->
<?php require_once __DIR__ . '/includes/system_info.php'; ?>

<script>
<?php if ($can['report_sales']): ?>
// 销售趋势图
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: [<?php foreach($salesTrend as $t) echo "'".substr($t['dt'],5)."'," ?>],
        datasets: [{
            label: '销售额',
            data: [<?php foreach($salesTrend as $t) echo $t['amt']."," ?>],
            borderColor: '#4361ee',
            backgroundColor: 'rgba(67,97,238,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 2,
            pointHoverRadius: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '¥'+v } }
        }
    }
});
<?php endif; ?>

<?php if ($can['aging']): ?>
// 账龄图
new Chart(document.getElementById('agingChart'), {
    type: 'doughnut',
    data: {
        labels: ['30天内','30-60天','60-90天','90天以上'],
        datasets: [{
            data: [<?= $aging['within30'] ?>, <?= $aging['within60'] ?>, <?= $aging['within90'] ?>, <?= $aging['over90'] ?>],
            backgroundColor: ['#10b981','#f59e0b','#f97316','#ef4444']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } }
        }
    }
});
<?php endif; ?>

<?php if ($showCrm): ?>
// ==================== CRM 图表 ====================

// 客户来源分布
new Chart(document.getElementById('sourceChart'), {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_column($sourceData, 'source_name'), JSON_UNESCAPED_UNICODE); ?>,
        datasets: [{
            data: [<?php foreach($sourceData as $s) echo $s['cnt']."," ?>],
            backgroundColor: ['#4361ee','#3a86ff','#8338ec','#ff006e','#fb5607','#ffbe0b','#06d6a0','#118ab2','#ef476f','#f78c6b']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { boxWidth: 10, padding: 10, font: { size: 11 } } }
        }
    }
});

// 意向程度分布
new Chart(document.getElementById('intentionChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($intentionData, 'intention'), JSON_UNESCAPED_UNICODE); ?>,
        datasets: [{
            data: [<?php foreach($intentionData as $it) echo $it['cnt']."," ?>],
            backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#9ca3af']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 } } }
        }
    }
});

// 近30天新增客户趋势
new Chart(document.getElementById('newCustChart'), {
    type: 'bar',
    data: {
        labels: [<?php foreach($newCustTrend as $t) echo "'".substr($t['dt'],5)."'," ?>],
        datasets: [{
            label: '新增客户',
            data: [<?php foreach($newCustTrend as $t) echo $t['cnt']."," ?>],
            backgroundColor: 'rgba(67,97,238,0.6)',
            borderColor: '#4361ee',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// 近30天跟进趋势
new Chart(document.getElementById('followChart'), {
    type: 'line',
    data: {
        labels: [<?php foreach($followTrend as $t) echo "'".substr($t['dt'],5)."'," ?>],
        datasets: [{
            label: '跟进次数',
            data: [<?php foreach($followTrend as $t) echo $t['cnt']."," ?>],
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 3,
            pointBackgroundColor: '#f59e0b'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>