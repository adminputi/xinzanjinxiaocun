<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('report_sales');
$pdo = getDB();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$categoryId = intval($_GET['category_id'] ?? 0);
$employeeId = intval($_GET['employee_id'] ?? 0);
$warehouseId = intval($_GET['warehouse_id'] ?? 0);

// 注意：sales_outstocks.employee_id 存的是操作人（当前登录用户），不是业务员。
// 业务员名称存在 salesperson_name 文本字段。需要通过 users 表的 real_name 字段匹配。
$employeeName = '';
if ($employeeId) {
    $stmt = $pdo->prepare("SELECT real_name FROM users WHERE id=?");
    $stmt->execute([$employeeId]);
    $employeeName = $stmt->fetchColumn();
}

$where = "WHERE so.status='confirmed' AND so.outstock_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($categoryId) { $where .= " AND p.category_id=?"; $params[]=$categoryId; }
if ($employeeName !== '' && $employeeName !== false) { $where .= " AND so.salesperson_name=?"; $params[]=$employeeName; }
if ($warehouseId && $warehouseId>0) { $where .= " AND so.warehouse_id=?"; $params[]=$warehouseId; }

// 汇总查询和趋势查询的WHERE（不包含 p.xxx 的条件）
$aggWhere = "WHERE so.status='confirmed' AND so.outstock_date BETWEEN ? AND ?";
$aggParams = [$dateFrom, $dateTo];
if ($employeeName !== '' && $employeeName !== false) { $aggWhere .= " AND so.salesperson_name=?"; $aggParams[]=$employeeName; }
if ($warehouseId && $warehouseId>0) { $aggWhere .= " AND so.warehouse_id=?"; $aggParams[]=$warehouseId; }
if ($categoryId) {
    // 分类筛选需要JOIN到items和products
    $aggWhere = "WHERE so.status='confirmed' AND so.outstock_date BETWEEN ? AND ? AND so.id IN (SELECT oi.outstock_id FROM sales_outstock_items oi JOIN products p ON oi.product_id=p.id WHERE p.category_id=?)";
    $aggParams = [$dateFrom, $dateTo, $categoryId];
    if ($employeeName !== '' && $employeeName !== false) { $aggWhere .= " AND so.salesperson_name=?"; $aggParams[]=$employeeName; }
    if ($warehouseId && $warehouseId>0) { $aggWhere .= " AND so.warehouse_id=?"; $aggParams[]=$warehouseId; }
}

// 商品销售排行
$stmt = $pdo->prepare("SELECT p.id, p.name, p.sku, SUM(oi.quantity) as total_qty, SUM(oi.amount) as total_amount FROM sales_outstock_items oi JOIN sales_outstocks so ON oi.outstock_id=so.id JOIN products p ON oi.product_id=p.id $where GROUP BY oi.product_id ORDER BY total_amount DESC LIMIT 30");
$stmt->execute($params); $products = $stmt->fetchAll();

// 汇总
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_outstocks so $aggWhere");
$stmt->execute($aggParams); $totalAmount = $stmt->fetchColumn();

// 按天趋势
$stmt = $pdo->prepare("SELECT DATE(outstock_date) as dt, COALESCE(SUM(total_amount),0) as amt FROM sales_outstocks so $aggWhere GROUP BY DATE(outstock_date) ORDER BY dt");
$stmt->execute($aggParams); $trends = $stmt->fetchAll();

$categories = get_options('product_categories','id','name','status=1');
$employees = get_options('users','id','real_name','status=1');
$warehouses = get_options('warehouses','id','name','status=1');
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-chart-bar"></i> 销售排行分析</h1>
    <button class="btn btn-outline" onclick="exportReport()"><i class="fa-solid fa-download"></i> 导出</button>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:130px;">
    <select name="category_id" class="form-control" style="min-width:120px;"><option value="0">全部分类</option><?php foreach($categories as $k=>$v): ?><option value="<?=$k?>" <?=$categoryId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <select name="warehouse_id" class="form-control" style="min-width:120px;"><option value="0">全部仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>" <?=$warehouseId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <select name="employee_id" class="form-control" style="min-width:120px;"><option value="0">全部业务员</option><?php foreach($employees as $k=>$v): ?><option value="<?=$k?>" <?=$employeeId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">销售总额：¥<?=format_money($totalAmount)?></h3></div>
    <div class="card-body" style="height:280px;"><canvas id="trendChart"></canvas></div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>排名</th><th>商品名称</th><th>SKU</th><th>销售数量</th><th>销售金额</th><th>占比</th></tr></thead>
<tbody>
<?php if ($products): foreach ($products as $i => $p): ?>
<tr>
    <td><span class="badge badge-<?=$i<3?'primary':'gray'?>"><?=$i+1?></span></td>
    <td><strong><?=htmlspecialchars($p['name'])?></strong></td>
    <td><?=$p['sku']?></td>
    <td><?=$p['total_qty']?></td>
    <td><strong>¥<?=format_money($p['total_amount'])?></strong></td>
    <td><?=$totalAmount>0 ? round($p['total_amount']/$totalAmount*100,1).'%' : '0%'?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-chart-bar"></i><p>暂无数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<script>
new Chart(document.getElementById('trendChart'),{
    type:'bar',
    data:{
        labels:[<?php foreach($trends as $t) echo "'".substr($t['dt'],5)."'," ?>],
        datasets:[{label:'销售额',data:[<?php foreach($trends as $t) echo $t['amt']."," ?>],backgroundColor:'rgba(67,97,238,0.7)',borderRadius:4}]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'¥'+v}}}}});
function exportReport(){var rows=document.querySelectorAll('table tbody tr');var d=[],h=['排名','商品名称','SKU','销售数量','销售金额'];rows.forEach(function(r,i){var c=r.querySelectorAll('td');if(c.length>=5)d.push([i+1,c[1].textContent.trim(),c[2].textContent.trim(),c[3].textContent.trim(),c[4].textContent.trim()]);});exportCsv(h,d,'sales_rank_<?=date('Ymd')?>.csv');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
