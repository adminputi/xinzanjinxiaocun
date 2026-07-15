<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('sales_reconcile');
$pdo = getDB();
$isAdminRec = (get_user_role() === 'admin');
$uid = get_user_id();

$customerId = intval($_GET['customer_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// 销售对账：以销售订单为核心，计算每个客户的订单总额、已收金额、应收余额
// 出库和退货作为辅助数据单独查询，避免 LEFT JOIN 产生笛卡尔积
// 非admin用户只看自己创建的单据
$userIdJoin = $isAdminRec ? '' : "AND so.user_id = $uid";
$sql = "SELECT c.id, c.name as customer_name, c.phone, c.initial_balance,
    COALESCE(SUM(so.total_amount),0) as order_total,
    COALESCE(SUM(so.received_amount),0) as received_total,
    COUNT(DISTINCT so.id) as order_count
FROM customers c
LEFT JOIN sales_orders so ON c.id=so.customer_id AND so.order_date BETWEEN ? AND ? AND so.status NOT IN('draft','cancelled') $userIdJoin
WHERE c.status=1 ";
$params = [$dateFrom,$dateTo];
if ($customerId) { $sql .= "AND c.id=?"; $params[] = $customerId; }
$sql .= " GROUP BY c.id ORDER BY (COALESCE(SUM(so.total_amount),0)-COALESCE(SUM(so.received_amount),0)) DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$summaries = $stmt->fetchAll();

// 出库和退货数据单独查询（避免 LEFT JOIN 笛卡尔积）
$outstockData = []; $returnData = [];
if ($summaries) {
    $ids = array_column($summaries, 'id');
    $idsStr = implode(',', array_map('intval', $ids));
    if ($idsStr) {
        $outstockStmt = $pdo->prepare("SELECT customer_id, COALESCE(SUM(total_amount),0) as outstock_total FROM sales_outstocks WHERE customer_id IN ($idsStr) AND outstock_date BETWEEN ? AND ? AND status='confirmed'" . ($isAdminRec ? '' : " AND user_id = $uid") . " GROUP BY customer_id");
        $outstockStmt->execute([$dateFrom, $dateTo]);
        foreach ($outstockStmt as $row) { $outstockData[$row['customer_id']] = $row['outstock_total']; }

        $returnStmt = $pdo->prepare("SELECT customer_id, COALESCE(SUM(total_amount),0) as return_total FROM sales_returns WHERE customer_id IN ($idsStr) AND return_date BETWEEN ? AND ? AND status='confirmed'" . ($isAdminRec ? '' : " AND user_id = $uid") . " GROUP BY customer_id");
        $returnStmt->execute([$dateFrom, $dateTo]);
        foreach ($returnStmt as $row) { $returnData[$row['customer_id']] = $row['return_total']; }
    }
}

$customers = get_options('customers','id','name','status=1');
$totalOrder = array_sum(array_column($summaries,'order_total'));
$totalReceived = array_sum(array_column($summaries,'received_total'));
$totalInitial = array_sum(array_column($summaries,'initial_balance'));
$totalOutstock = array_sum($outstockData);
$totalReturn = array_sum($returnData);
?>


<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> 客户对账</h1>
</div>

<form class="filter-bar" method="get">
    <select name="customer_id" class="form-control" style="min-width:180px;">
        <option value="0">全部客户</option>
        <?php foreach($customers as $k=>$v): ?><option value="<?=$k?>" <?=$customerId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:140px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:140px;">
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> 查询</button>
</form>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div><div class="stat-content"><div class="stat-label">订单总额</div><div class="stat-value">¥<?=format_money($totalOrder)?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div><div class="stat-content"><div class="stat-label">已收款</div><div class="stat-value">¥<?=format_money($totalReceived)?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i class="fa-solid fa-truck-fast"></i></div><div class="stat-content"><div class="stat-label">出库总额</div><div class="stat-value">¥<?=format_money($totalOutstock)?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-rotate-left"></i></div><div class="stat-content"><div class="stat-label">退货总额</div><div class="stat-value">¥<?=format_money($totalReturn)?></div></div></div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>客户</th><th>电话</th><th>订单金额</th><th>已收金额</th><th>应收余额</th><th>出库总额</th><th>退货总额</th><th>操作</th></tr></thead>
<tbody>
<?php if ($summaries): foreach ($summaries as $row):
    $balance = $row['order_total'] + floatval($row['initial_balance'] ?? 0) - $row['received_total'];
    $outstockTotal = $outstockData[$row['id']] ?? 0;
    $returnTotal = $returnData[$row['id']] ?? 0;
    $hasData = $row['order_total']>0 || $outstockTotal>0 || $returnTotal>0 || floatval($row['initial_balance']??0)>0;
    if (!$hasData) continue;
?>
<tr>
    <td><strong><?=htmlspecialchars($row['customer_name'])?></strong></td>
    <td><?=htmlspecialchars($row['phone']?:'-')?></td>
    <td>¥<?=format_money($row['order_total'])?></td>
    <td style="color:var(--success)">¥<?=format_money($row['received_total'])?></td>
    <td style="color:<?=$balance>0?'var(--danger)':'var(--success)'?>"><strong>¥<?=format_money($balance)?></strong></td>
    <td>¥<?=format_money($outstockTotal)?></td>
    <td style="color:var(--danger)">¥<?=format_money($returnTotal)?></td>
    <td><a href="../finance/aging.php?customer_id=<?=$row['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i> 明细</a></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无对账数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
