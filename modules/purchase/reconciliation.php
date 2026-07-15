<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_reconcile');
$pdo = getDB();

$supplierId = intval($_GET['supplier_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// 采购对账：以采购订单为核心，计算每个供应商的订单总额、已付金额、应付余额
// 同时展示入库和退货作为辅助参考
$sql = "SELECT s.id, s.name as supplier_name, s.phone,
    COALESCE(SUM(po.total_amount),0) as order_total,
    COALESCE(SUM(po.paid_amount),0) as paid_total,
    COUNT(DISTINCT po.id) as order_count
FROM suppliers s
LEFT JOIN purchase_orders po ON s.id=po.supplier_id AND po.order_date BETWEEN ? AND ? AND po.status NOT IN('draft','cancelled')
WHERE s.status=1 ";
$params = [$dateFrom,$dateTo];
if ($supplierId) { $sql .= "AND s.id=?"; $params[] = $supplierId; }
$sql .= " GROUP BY s.id ORDER BY (COALESCE(SUM(po.total_amount),0)-COALESCE(SUM(po.paid_amount),0)) DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$summaries = $stmt->fetchAll();

// 入库和退款的辅助数据（单独查询，避免LEFT JOIN重复计算）
$instockData = []; $returnData = [];
if ($summaries) {
    $ids = array_column($summaries, 'id');
    $idsStr = implode(',', array_map('intval', $ids));
    if ($idsStr) {
        $instockStmt = $pdo->prepare("SELECT supplier_id, COALESCE(SUM(total_amount),0) as instock_total FROM purchase_instocks WHERE supplier_id IN ($idsStr) AND instock_date BETWEEN ? AND ? AND status='confirmed' GROUP BY supplier_id");
        $instockStmt->execute([$dateFrom, $dateTo]);
        foreach ($instockStmt as $row) { $instockData[$row['supplier_id']] = $row['instock_total']; }

        $returnStmt = $pdo->prepare("SELECT supplier_id, COALESCE(SUM(total_amount),0) as return_total FROM purchase_returns WHERE supplier_id IN ($idsStr) AND return_date BETWEEN ? AND ? AND status='confirmed' GROUP BY supplier_id");
        $returnStmt->execute([$dateFrom, $dateTo]);
        foreach ($returnStmt as $row) { $returnData[$row['supplier_id']] = $row['return_total']; }
    }
}

$suppliers = get_options('suppliers','id','name','status=1');
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-calculator"></i> 采购对账</h1>
</div>

<form class="filter-bar" method="get">
    <select name="supplier_id" class="form-control" style="min-width:180px;">
        <option value="0">全部供应商</option>
        <?php foreach($suppliers as $k=>$v): ?><option value="<?=$k?>" <?=$supplierId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:140px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:140px;">
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> 查询</button>
</form>

<?php
$totalOrder = array_sum(array_column($summaries,'order_total'));
$totalPaid = array_sum(array_column($summaries,'paid_total'));
$totalInstock = array_sum($instockData);
$totalReturn = array_sum($returnData);
?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-file-invoice"></i></div><div class="stat-content"><div class="stat-label">采购订单总额</div><div class="stat-value">¥<?=format_money($totalOrder)?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div><div class="stat-content"><div class="stat-label">已付款金额</div><div class="stat-value">¥<?=format_money($totalPaid)?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-balance-scale"></i></div><div class="stat-content"><div class="stat-label">应付余额</div><div class="stat-value">¥<?=format_money($totalOrder-$totalPaid)?></div></div></div>
    <div class="stat-card"><div class="stat-icon cyan"><i class="fa-solid fa-boxes-packing"></i></div><div class="stat-content"><div class="stat-label">入库总额</div><div class="stat-value">¥<?=format_money($totalInstock)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-rotate-left"></i></div><div class="stat-content"><div class="stat-label">退货总额</div><div class="stat-value">¥<?=format_money($totalReturn)?></div></div></div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>供应商</th><th>电话</th><th>订单笔数</th><th>订单金额</th><th>已付金额</th><th>应付余额</th><th>入库总额</th><th>退货总额</th><th>操作</th></tr></thead>
<tbody>
<?php if ($summaries): foreach ($summaries as $row):
    $returnTotal = $returnData[$row['id']] ?? 0;
    $balance = $row['order_total'] - $row['paid_total'] - $returnTotal;
    $instockTotal = $instockData[$row['id']] ?? 0;
    $hasData = $row['order_total']>0 || $instockTotal>0 || $returnTotal>0;
    if (!$hasData) continue;
?>
<tr>
    <td><strong><?=htmlspecialchars($row['supplier_name'])?></strong></td>
    <td><?=htmlspecialchars($row['phone']?:'-')?></td>
    <td><span class="badge badge-info"><?=$row['order_count']?></span></td>
    <td>¥<?=format_money($row['order_total'])?></td>
    <td style="color:var(--success)">¥<?=format_money($row['paid_total'])?></td>
    <td style="color:<?=$balance>0?'var(--danger)':'var(--success)'?>"><strong>¥<?=format_money($balance)?></strong></td>
    <td>¥<?=format_money($instockTotal)?></td>
    <td style="color:var(--danger)">¥<?=format_money($returnTotal)?></td>
    <td><a href="../product/supplier_detail.php?id=<?=$row['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i> 明细</a></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-calculator"></i><p>暂无对账数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
