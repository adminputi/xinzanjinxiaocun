<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('report_io');
$pdo = getDB();
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$warehouseId = intval($_GET['warehouse_id'] ?? 0);

$whFilter = $warehouseId>0 ? "AND warehouse_id=$warehouseId" : "";

// 出入库汇总 - 按类型
$stmt = $pdo->prepare("SELECT type, COUNT(*) as cnt, SUM(ABS(change_quantity)) as total_qty FROM inventory_logs WHERE created_at BETWEEN ? AND ? $whFilter GROUP BY type");
$stmt->execute([$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);
$typeSummary = $stmt->fetchAll();

// 按仓库汇总
$stmt = $pdo->prepare("SELECT w.name, COUNT(l.id) as cnt, SUM(CASE WHEN l.change_quantity>0 THEN l.change_quantity ELSE 0 END) as in_qty, SUM(CASE WHEN l.change_quantity<0 THEN ABS(l.change_quantity) ELSE 0 END) as out_qty FROM inventory_logs l JOIN warehouses w ON l.warehouse_id=w.id WHERE l.created_at BETWEEN ? AND ? GROUP BY l.warehouse_id");
$stmt->execute([$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);
$whSummary = $stmt->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');
$typeLabels = ['in'=>'采购入库','out'=>'销售出库','transfer_in'=>'调拨入库','transfer_out'=>'调拨出库','check'=>'盘点调整','loss'=>'报损报溢'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-right-left"></i> 仓库出入库汇总</h1>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:130px;">
    <select name="warehouse_id" class="form-control" style="min-width:140px;"><option value="0">全部仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>" <?=$warehouseId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div class="card"><div class="card-header">按业务类型汇总</div><div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>类型</th><th>笔数</th><th>数量合计</th></tr></thead>
        <tbody><?php if($typeSummary): foreach($typeSummary as $t): ?><tr>
            <td><strong><?=$typeLabels[$t['type']]??$t['type']?></strong></td>
            <td><?=$t['cnt']?></td><td><?=$t['total_qty']?></td>
        </tr><?php endforeach; else: ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?></tbody>
    </table></div></div></div>

    <div class="card"><div class="card-header">按仓库汇总</div><div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>仓库</th><th>入库数量</th><th>出库数量</th><th>操作次数</th></tr></thead>
        <tbody><?php if($whSummary): foreach($whSummary as $w): ?><tr>
            <td><strong><?=htmlspecialchars($w['name'])?></strong></td>
            <td style="color:var(--success)">+<?=$w['in_qty']?></td>
            <td style="color:var(--danger)">-<?=$w['out_qty']?></td>
            <td><?=$w['cnt']?></td>
        </tr><?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr><?php endif; ?></tbody>
    </table></div></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
