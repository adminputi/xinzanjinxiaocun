<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('report_purchase');
$pdo = getDB();
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$supplierId = intval($_GET['supplier_id'] ?? 0);

$where = "WHERE pi.status='confirmed' AND pi.instock_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($supplierId) { $where .= " AND pi.supplier_id=?"; $params[]=$supplierId; }

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_instocks pi $where"); $stmt->execute($params); $totalAmount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT DATE(instock_date) as dt, COALESCE(SUM(total_amount),0) as amt FROM purchase_instocks pi $where GROUP BY DATE(instock_date) ORDER BY dt"); $stmt->execute($params); $trends = $stmt->fetchAll();

// 按供应商汇总
$stmt = $pdo->prepare("SELECT s.name, COALESCE(SUM(pi.total_amount),0) as amt FROM purchase_instocks pi JOIN suppliers s ON pi.supplier_id=s.id $where GROUP BY pi.supplier_id ORDER BY amt DESC"); $stmt->execute($params); $suppliers = $stmt->fetchAll();

$supplierOptions = get_options('suppliers','id','name','status=1');
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-chart-pie"></i> 采购统计</h1>
    <button class="btn btn-outline" onclick="exportReport()"><i class="fa-solid fa-download"></i> 导出</button>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:130px;">
    <select name="supplier_id" class="form-control" style="min-width:150px;"><option value="0">全部供应商</option><?php foreach($supplierOptions as $k=>$v): ?><option value="<?=$k?>" <?=$supplierId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-cart-shopping"></i></div><div class="stat-content"><div class="stat-label">采购入库总额</div><div class="stat-value">¥<?=format_money($totalAmount)?></div></div></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
    <div class="card"><div class="card-header">采购趋势</div><div class="card-body" style="height:300px;"><canvas id="pChart"></canvas></div></div>
    <div class="card"><div class="card-header">供应商采购占比</div><div class="card-body" style="height:300px;padding:10px;">
        <?php if ($suppliers): ?>
        <?php foreach ($suppliers as $s): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-100)">
            <span style="font-size:13px;"><?=htmlspecialchars(mb_substr($s['name'],0,12))?></span>
            <span style="font-weight:600;">¥<?=format_money($s['amt'])?></span>
        </div>
        <?php endforeach; else: ?>
        <div class="empty-state" style="padding:30px;"><i class="fa-solid fa-chart-pie"></i><p>暂无数据</p></div>
        <?php endif; ?>
    </div></div>
</div>

<script>
new Chart(document.getElementById('pChart'),{type:'line',data:{labels:[<?php foreach($trends as $t) echo "'".substr($t['dt'],5)."'," ?>],datasets:[{label:'采购额',data:[<?php foreach($trends as $t) echo $t['amt']."," ?>],borderColor:'#10b981',backgroundColor:'rgba(16,185,129,0.1)',fill:true,tension:0.4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'¥'+v}}}}});
function exportReport(){var d=[];document.querySelectorAll('.card-body > div').forEach(function(e){var spans=e.querySelectorAll('span');if(spans.length>=2)d.push([spans[0].textContent.trim(),spans[1].textContent.trim()]);});exportCsv(['供应商','采购金额'],d,'purchase_stats.csv');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
