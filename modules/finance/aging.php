<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance_aging');
$pdo = getDB();

$customerId = intval($_GET['customer_id'] ?? 0);

$sql = "SELECT c.id, c.name as customer_name, c.phone, c.initial_balance,
    SUM(CASE WHEN so.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN so.total_amount - so.received_amount ELSE 0 END) as within30,
    SUM(CASE WHEN so.order_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND so.order_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN so.total_amount - so.received_amount ELSE 0 END) as within60,
    SUM(CASE WHEN so.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND so.order_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN so.total_amount - so.received_amount ELSE 0 END) as within90,
    SUM(CASE WHEN so.order_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN so.total_amount - so.received_amount ELSE 0 END) + c.initial_balance as over90,
    SUM(so.total_amount - so.received_amount) + c.initial_balance as total_balance
FROM customers c
LEFT JOIN sales_orders so ON c.id=so.customer_id AND so.status NOT IN('draft','cancelled')
WHERE c.status=1";
$params = [];
if ($customerId) { $sql .= " AND c.id=?"; $params[]=$customerId; }
$sql .= " GROUP BY c.id HAVING total_balance > 0 ORDER BY total_balance DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$agings = $stmt->fetchAll();

$totalAging = ['within30'=>0,'within60'=>0,'within90'=>0,'over90'=>0,'total'=>0];
foreach ($agings as $a) {
    $totalAging['within30'] += $a['within30'];
    $totalAging['within60'] += $a['within60'];
    $totalAging['within90'] += $a['within90'];
    $totalAging['over90'] += $a['over90'];
    $totalAging['total'] += $a['total_balance'];
}

$customers = get_options('customers','id','name','status=1');
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-hourglass-half"></i> 欠款账龄分析</h1>
</div>

<form class="filter-bar" method="get">
    <select name="customer_id" class="form-control" style="min-width:180px;"><option value="0">全部客户</option><?php foreach($customers as $k=>$v): ?><option value="<?=$k?>" <?=$customerId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-calendar-check"></i></div><div class="stat-content"><div class="stat-label">30天内</div><div class="stat-value">¥<?=format_money($totalAging['within30'])?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-calendar"></i></div><div class="stat-content"><div class="stat-label">30-60天</div><div class="stat-value">¥<?=format_money($totalAging['within60'])?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-calendar-xmark"></i></div><div class="stat-content"><div class="stat-label">60-90天</div><div class="stat-value">¥<?=format_money($totalAging['within90'])?></div></div></div>
    <div class="stat-card"><div class="stat-icon red" style="background:#fee2e2;"><i class="fa-solid fa-circle-exclamation"></i></div><div class="stat-content"><div class="stat-label">90天以上</div><div class="stat-value">¥<?=format_money($totalAging['over90'])?></div></div></div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>客户</th><th>电话</th><th>30天内</th><th>30-60天</th><th>60-90天</th><th>90天以上</th><th>应收合计</th></tr></thead>
<tbody>
<?php if ($agings): foreach ($agings as $a): ?>
<tr>
    <td><strong><?=htmlspecialchars($a['customer_name'])?></strong></td>
    <td><?=htmlspecialchars($a['phone']?:'-')?></td>
    <td>¥<?=format_money($a['within30'])?></td>
    <td>¥<?=format_money($a['within60'])?></td>
    <td>¥<?=format_money($a['within90'])?></td>
    <td style="color:<?=$a['over90']>0?'var(--danger)':''?>"><strong>¥<?=format_money($a['over90'])?></strong></td>
    <td style="font-weight:bold;">¥<?=format_money($a['total_balance'])?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-hourglass-half"></i><p>暂无欠款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
