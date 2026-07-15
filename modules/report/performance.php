<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('report_performance');
$pdo = getDB();
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$employeeId = intval($_GET['employee_id'] ?? 0);
$productId = intval($_GET['product_id'] ?? 0);

// 显示单个业务员详情
$viewEmployeeId = intval($_GET['view_employee'] ?? 0);
// 显示单个业务员订单列表
$viewOrdersEmpId = intval($_GET['orders_employee'] ?? 0);
$orderPage = max(1, intval($_GET['opage'] ?? 1));

$empFilter = $employeeId>0 ? "AND so.employee_id=$employeeId" : "";
$prodFilter = $productId>0 ? "AND soi.product_id=$productId" : "";

// 主查询
$whereJoin = "AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled') $empFilter";

$params = [$dateFrom, $dateTo];
if ($prodFilter) {
    // 有产品筛选时，需要用子查询
    $stmt = $pdo->prepare("SELECT e.id, e.real_name as name, '' as department, e.phone, '' as position,
        COUNT(DISTINCT so.id) as order_count,
        COALESCE(SUM(so.total_amount),0) as total_amount,
        COALESCE(SUM(so.received_amount),0) as received_amount
    FROM users e
    LEFT JOIN sales_orders so ON e.id=so.employee_id AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled')
    WHERE e.status=1 $empFilter
    AND so.id IN (SELECT DISTINCT order_id FROM sales_order_items WHERE product_id=?)
    GROUP BY e.id ORDER BY total_amount DESC");
    $stmt->execute([$dateFrom, $dateTo, $productId]);
} else {
    $stmt = $pdo->prepare("SELECT e.id, e.real_name as name, '' as department, e.phone, '' as position,
        COUNT(DISTINCT so.id) as order_count,
        COALESCE(SUM(so.total_amount),0) as total_amount,
        COALESCE(SUM(so.received_amount),0) as received_amount
    FROM users e
    LEFT JOIN sales_orders so ON e.id=so.employee_id AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled')
    WHERE e.status=1
    GROUP BY e.id ORDER BY total_amount DESC");
    $stmt->execute([$dateFrom, $dateTo]);
}
$performances = $stmt->fetchAll();

$totalAmount = array_sum(array_column($performances,'total_amount'));

// 业务员详情
$empDetail = null; $empProducts = [];
if ($viewEmployeeId > 0) {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$viewEmployeeId]);
    $empDetail = $st->fetch();
    if ($empDetail) {
        $st = $pdo->prepare("SELECT p.name, p.sku, SUM(soi.quantity) as qty, SUM(soi.amount) as amt FROM sales_order_items soi JOIN sales_orders so ON soi.order_id=so.id JOIN products p ON soi.product_id=p.id WHERE so.employee_id=? AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled') GROUP BY soi.product_id ORDER BY amt DESC LIMIT 20");
        $st->execute([$viewEmployeeId, $dateFrom, $dateTo]);
        $empProducts = $st->fetchAll();
    }
}

// 业务员订单列表
$empOrders = []; $empOrderTotal = 0; $empOrderPages = 1;
if ($viewOrdersEmpId > 0) {
    $perOrderPage = ITEMS_PER_PAGE;
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE employee_id=? AND order_date BETWEEN ? AND ? AND status NOT IN('draft','cancelled')");
    $st->execute([$viewOrdersEmpId, $dateFrom, $dateTo]);
    $empOrderTotal = $st->fetchColumn();
    $empOrderPages = ceil($empOrderTotal/$perOrderPage);
    $orderOffset = ($orderPage-1)*$perOrderPage;
    $st = $pdo->prepare("SELECT so.*, c.name as customer_name FROM sales_orders so LEFT JOIN customers c ON so.customer_id=c.id WHERE so.employee_id=? AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled') ORDER BY so.id DESC LIMIT $orderOffset,$perOrderPage");
    $st->execute([$viewOrdersEmpId, $dateFrom, $dateTo]);
    $empOrders = $st->fetchAll();
}

// 下拉选项
$employees = get_options('users','id','real_name','status=1');
$products = get_options('products','id','name','status=1');
$statusLabels = ['draft'=>'草稿','confirmed'=>'已确认','shipped'=>'已发货','partial'=>'部分完成','completed'=>'已完成','cancelled'=>'已取消'];
$statusBadge = ['draft'=>'warning','confirmed'=>'info','shipped'=>'primary','partial'=>'warning','completed'=>'success','cancelled'=>'gray'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-medal"></i> 业务员业绩报表</h1>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:130px;">
    <select name="employee_id" class="form-control" style="min-width:140px;">
        <option value="0">全部业务员</option>
        <?php foreach($employees as $k=>$v): ?><option value="<?=$k?>" <?=$employeeId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <select name="product_id" class="form-control" style="min-width:140px;">
        <option value="0">全部产品</option>
        <?php foreach($products as $k=>$v): ?><option value="<?=$k?>" <?=$productId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-user-check"></i></div><div class="stat-content"><div class="stat-label">有业绩人数</div><div class="stat-value"><?=count(array_filter($performances,function($p){return $p['order_count']>0;}))?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i class="fa-solid fa-file-invoice"></i></div><div class="stat-content"><div class="stat-label">总订单数</div><div class="stat-value"><?=array_sum(array_column($performances,'order_count'))?></div></div></div>
    <div class="stat-card"><div class="stat-icon cyan"><i class="fa-solid fa-sack-dollar"></i></div><div class="stat-content"><div class="stat-label">销售总额</div><div class="stat-value">¥<?=format_money($totalAmount)?></div></div></div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>排名</th><th>业务员</th><th>部门</th><th>职位</th><th>订单数</th><th>销售金额</th><th>已收金额</th><th>回款率</th><th>业绩占比</th></tr></thead>
<tbody>
<?php if ($performances): foreach ($performances as $i => $p): ?>
<tr>
    <td><span class="badge badge-<?=$i<3?'primary':'gray'?>"><?=$i+1?></span></td>
    <td><a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>&view_employee=<?=$p['id']?>" style="font-weight:bold;color:var(--primary);text-decoration:none;"><?=htmlspecialchars($p['name'])?></a></td>
    <td><?=htmlspecialchars($p['department']?:'-')?></td>
    <td><?=htmlspecialchars($p['position']?:'-')?></td>
    <td><a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>&orders_employee=<?=$p['id']?>" class="badge badge-info" style="cursor:pointer;text-decoration:none;" title="点击查看订单列表"><?=$p['order_count']?></a></td>
    <td><strong>¥<?=format_money($p['total_amount'])?></strong></td>
    <td style="color:var(--success)">¥<?=format_money($p['received_amount'])?></td>
    <td><?=$p['total_amount']>0?round($p['received_amount']/$p['total_amount']*100,1).'%':'0%'?></td>
    <td><?=$totalAmount>0?round($p['total_amount']/$totalAmount*100,1).'%':'0%'?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-medal"></i><p>暂无业绩数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<!-- 业务员详情弹窗 -->
<?php if ($empDetail): ?>
<div class="modal-overlay show" id="empDetailModal">
<div class="modal modal-sm">
    <div class="modal-header">
        <h3 class="modal-title"><i class="fa-solid fa-user"></i> 业务员详情</h3>
        <a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>" class="modal-close">&times;</a>
    </div>
    <div class="modal-body">
        <div style="margin-bottom:16px;">
            <p><strong>姓名：</strong><?=htmlspecialchars($empDetail['real_name'])?></p>
            <p><strong>用户名：</strong><?=htmlspecialchars($empDetail['username'])?></p>
            <p><strong>电话：</strong><?=htmlspecialchars($empDetail['phone']?:'-')?></p>
            <p><strong>邮箱：</strong><?=htmlspecialchars($empDetail['email']?:'-')?></p>
        </div>
        <h4 style="font-size:13px;color:var(--gray-600);margin-bottom:8px;">销售产品排行</h4>
        <table style="width:100%;font-size:12px;">
            <thead><tr><th>产品</th><th>数量</th><th>金额</th></tr></thead>
            <tbody>
            <?php if($empProducts): foreach($empProducts as $ep): ?>
            <tr><td><?=htmlspecialchars($ep['name'])?></td><td><?=$ep['qty']?></td><td>¥<?=format_money($ep['amt'])?></td></tr>
            <?php endforeach; else: ?>
            <tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="modal-footer">
        <a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>" class="btn btn-primary">关闭</a>
    </div>
</div></div>
<?php endif; ?>

<!-- 业务员订单列表 -->
<?php if ($viewOrdersEmpId > 0): ?>
<div class="modal-overlay show" id="empOrdersModal">
<div class="modal modal-lg">
    <div class="modal-header">
        <h3 class="modal-title"><i class="fa-solid fa-list"></i> 订单列表 - <?=htmlspecialchars($empOrders[0]['customer_name']??'' ?: '业务员') ?> (<?=$empOrderTotal?>单)</h3>
        <a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>" class="modal-close">&times;</a>
    </div>
    <div class="modal-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>客户</th><th>日期</th><th>金额</th><th>已收</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($empOrders): foreach($empOrders as $eo): ?>
            <tr>
                <td><strong><?=$eo['bill_no']?></strong></td>
                <td><?=htmlspecialchars($eo['customer_name']?:'-')?></td>
                <td><?=$eo['order_date']?></td>
                <td>¥<?=format_money($eo['total_amount'])?></td>
                <td>¥<?=format_money($eo['received_amount'])?></td>
                <td><span class="badge badge-<?=$statusBadge[$eo['status']]?>"><?=$statusLabels[$eo['status']]?></span></td>
                <td><a href="../sales/order_view.php?id=<?=$eo['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7"><div class="empty-state"><p>暂无订单</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php if($empOrderPages>1): ?>
    <div style="padding:12px;text-align:center;">
        <?php for($pi=1;$pi<=$empOrderPages;$pi++): ?>
        <a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&orders_employee=<?=$viewOrdersEmpId?>&opage=<?=$pi?>" style="display:inline-block;padding:4px 10px;margin:0 2px;border-radius:4px;text-decoration:none;<?=$pi==$orderPage?'background:var(--primary);color:#fff;':'background:var(--gray-100);color:var(--gray-700);'?>"><?=$pi?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <div class="modal-footer">
        <a href="?date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&employee_id=<?=$employeeId?>&product_id=<?=$productId?>" class="btn btn-primary">关闭</a>
    </div>
</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
