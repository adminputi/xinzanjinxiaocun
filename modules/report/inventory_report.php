<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('report_inventory');
$pdo = getDB();

$warehouseId = intval($_GET['warehouse_id'] ?? 0);
$categoryId = intval($_GET['category_id'] ?? 0);

// 库存明细（含分类、仓库维度）
$whFilter = $warehouseId>0 ? "AND i.warehouse_id=$warehouseId" : "";
$catFilter = $categoryId>0 ? "AND p.category_id=$categoryId" : "";
$whereClause = "WHERE p.status=1 $whFilter $catFilter AND i.quantity != 0";

// 按分类+仓库统计库存价值
$catsSql = "SELECT c.id as cat_id, c.name as cat_name, w.id as wh_id, w.name as wh_name,
    COUNT(DISTINCT i.product_id) as product_count,
    COALESCE(SUM(i.quantity),0) as total_qty,
    COALESCE(SUM(i.quantity * p.purchase_price),0) as total_value
FROM inventory i
JOIN products p ON i.product_id=p.id
LEFT JOIN product_categories c ON p.category_id=c.id
LEFT JOIN warehouses w ON i.warehouse_id=w.id
WHERE p.status=1 ".($warehouseId>0?"AND i.warehouse_id=$warehouseId":"")." ".($categoryId>0?"AND p.category_id=$categoryId":"")."
AND i.quantity != 0
GROUP BY c.id, w.id
ORDER BY total_value DESC";
$cats = $pdo->query($catsSql)->fetchAll();

// 按商品统计库存（最准确的库存汇总）
$productsSql = "SELECT p.id, p.name, p.sku, p.spec, p.purchase_price, p.sale_price, p.min_stock, p.max_stock,
    COALESCE(SUM(i.quantity),0) as total_qty,
    COALESCE(SUM(i.quantity * p.purchase_price),0) as total_value,
    GROUP_CONCAT(CONCAT(w.name,':',i.quantity) ORDER BY w.name SEPARATOR ' | ') as wh_detail
FROM products p
LEFT JOIN inventory i ON p.id=i.product_id
LEFT JOIN warehouses w ON i.warehouse_id=w.id
WHERE p.status=1 ".($warehouseId>0?"AND i.warehouse_id=$warehouseId":"")." ".($categoryId>0?"AND p.category_id=$categoryId":"")."
GROUP BY p.id
HAVING total_qty > 0
ORDER BY total_value DESC";
$products = $pdo->query($productsSql)->fetchAll();

// 低库存预警（准确的，考虑所有仓库）
$lowStockSql = "SELECT p.id, p.name, p.sku, p.spec, p.purchase_price, p.sale_price, p.min_stock,
    COALESCE(SUM(i.quantity),0) as total_qty,
    GROUP_CONCAT(CONCAT(w.name,':',i.quantity) ORDER BY w.name SEPARATOR ' | ') as wh_detail
FROM products p
LEFT JOIN inventory i ON p.id=i.product_id
LEFT JOIN warehouses w ON i.warehouse_id=w.id
WHERE p.status=1 AND p.min_stock > 0
GROUP BY p.id
HAVING COALESCE(SUM(i.quantity),0) <= p.min_stock AND COALESCE(SUM(i.quantity),0) > 0
ORDER BY (COALESCE(SUM(i.quantity),0) - p.min_stock) ASC";
$lowStocks = $pdo->query($lowStockSql)->fetchAll();

// 零库存/无库存品种（完全没有库存的产品）
$zeroStockSql = "SELECT p.id, p.name, p.sku, p.purchase_price, p.sale_price, p.min_stock
FROM products p
WHERE p.status=1 AND p.id NOT IN (SELECT DISTINCT product_id FROM inventory WHERE quantity>0)
ORDER BY p.name";
$zeroStocks = $pdo->query($zeroStockSql)->fetchAll();

// 总体统计
$totalTypes = count($products);
$totalValue = array_sum(array_column($products, 'total_value'));
$totalQty = array_sum(array_column($products, 'total_qty'));
$lowCount = count($lowStocks);
$zeroCount = count($zeroStocks);

// 下拉选项
$warehouses = get_options('warehouses','id','name','status=1');
$categories = get_options('product_categories','id','name','status=1');

// 按分类汇总
$catSummary = []; $catTypes = []; $catValues = []; $catQtys = [];
foreach ($cats as $c) {
    $cid = $c['cat_id'] ?: 0;
    if (!isset($catSummary[$cid])) { $catSummary[$cid] = ['name'=>$c['cat_name']?:'未分类','types'=>0,'qty'=>0,'value'=>0]; }
    $catSummary[$cid]['types'] += $c['product_count'];
    $catSummary[$cid]['qty'] += $c['total_qty'];
    $catSummary[$cid]['value'] += $c['total_value'];
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-chart-pie"></i> 库存余额报表</h1>
    <button class="btn btn-outline" onclick="exportReport()"><i class="fa-solid fa-download"></i> 导出</button>
</div>

<form class="filter-bar" method="get">
    <select name="warehouse_id" class="form-control" style="min-width:140px;"><option value="0">全部仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>" <?=$warehouseId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <select name="category_id" class="form-control" style="min-width:140px;"><option value="0">全部分类</option><?php foreach($categories as $k=>$v): ?><option value="<?=$k?>" <?=$categoryId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-cubes"></i></div><div class="stat-content"><div class="stat-label">库存品种</div><div class="stat-value"><?=$totalTypes?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i class="fa-solid fa-boxes-stacked"></i></div><div class="stat-content"><div class="stat-label">库存总量</div><div class="stat-value"><?=number_format($totalQty)?></div></div></div>
    <div class="stat-card"><div class="stat-icon cyan"><i class="fa-solid fa-coins"></i></div><div class="stat-content"><div class="stat-label">库存总价值</div><div class="stat-value">¥<?=format_money($totalValue)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="stat-content"><div class="stat-label">低库存预警</div><div class="stat-value"><?=$lowCount?></div></div></div>
    <div class="stat-card"><div class="stat-icon gray"><i class="fa-solid fa-ban"></i></div><div class="stat-content"><div class="stat-label">零库存品种</div><div class="stat-value"><?=$zeroCount?></div></div></div>
</div>

<!-- 按分类库存价值 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-pie"></i> 按分类库存价值</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>分类</th><th>品种数</th><th>库存总量</th><th>库存价值</th><th>价值占比</th></tr></thead>
        <tbody>
        <?php foreach($catSummary as $c): ?>
        <tr>
            <td><strong><?=htmlspecialchars($c['name'])?></strong></td>
            <td><?=$c['types']?></td>
            <td><?=number_format($c['qty'])?></td>
            <td>¥<?=format_money($c['value'])?></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:6px;background:var(--gray-100);border-radius:3px;overflow:hidden;">
                        <div style="height:100%;width:<?=$totalValue>0?round($c['value']/$totalValue*100,1):0?>%;background:var(--primary);border-radius:3px;"></div>
                    </div>
                    <span style="font-size:12px;white-space:nowrap;"><?=$totalValue>0?round($c['value']/$totalValue*100,1).'%':'0%'?></span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($catSummary)): ?><tr><td colspan="5" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<!-- 按仓库库存 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-warehouse"></i> 按仓库库存明细</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>仓库</th><th>分类</th><th>品种数</th><th>库存总量</th><th>库存价值</th></tr></thead>
        <tbody>
        <?php if($cats): foreach($cats as $c): ?>
        <tr>
            <td><?=htmlspecialchars($c['wh_name']?:'-')?></td>
            <td><?=htmlspecialchars($c['cat_name']?:'未分类')?></td>
            <td><?=$c['product_count']?></td>
            <td><?=number_format($c['total_qty'])?></td>
            <td>¥<?=format_money($c['total_value'])?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5" class="text-center text-muted">暂无数据</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<!-- 低库存预警清单 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title" style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> 低库存预警清单（库存 ≤ 最低库存）</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>商品</th><th>SKU</th><th>规格</th><th>各仓库存</th><th>合计</th><th>最低</th><th>差值</th><th>采购价</th></tr></thead>
        <tbody>
        <?php if($lowStocks): foreach($lowStocks as $l): $diff = $l['total_qty'] - $l['min_stock']; ?>
        <tr>
            <td><strong><?=htmlspecialchars($l['name'])?></strong></td>
            <td><?=$l['sku']?></td>
            <td><?=htmlspecialchars($l['spec']?:'-')?></td>
            <td style="font-size:12px;"><?=htmlspecialchars($l['wh_detail']?:'-')?></td>
            <td><span class="badge badge-danger"><?=$l['total_qty']?></span></td>
            <td><?=$l['min_stock']?></td>
            <td style="color:var(--danger);font-weight:bold;"><?=$diff?></td>
            <td>¥<?=format_money($l['purchase_price'])?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="8" class="text-center" style="color:var(--success)"><i class="fa-solid fa-check-circle"></i> 所有商品库存充足</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<!-- 零库存清单 -->
<?php if ($zeroCount > 0): ?>
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title" style="color:var(--gray-600);"><i class="fa-solid fa-ban"></i> 零库存品种（无库存商品）</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container">
        <table><thead><tr><th>商品</th><th>SKU</th><th>采购价</th><th>销售价</th><th>最低库存</th></tr></thead>
        <tbody>
        <?php foreach(array_slice($zeroStocks,0,30) as $z): ?>
        <tr>
            <td><strong><?=htmlspecialchars($z['name'])?></strong></td>
            <td><?=$z['sku']?></td>
            <td>¥<?=format_money($z['purchase_price'])?></td>
            <td>¥<?=format_money($z['sale_price'])?></td>
            <td><?=$z['min_stock']?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($zeroCount > 30): ?>
        <tr><td colspan="5" class="text-center text-muted">...还有 <?=$zeroCount-30?> 个零库存品种未列出</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>
<?php endif; ?>

<?php
// 准备JSON数据用于导出
$jsonData = json_encode(array_map(function($p) {
    return [$p['name'], $p['sku'], $p['wh_detail']??'', $p['total_qty'], '¥'.format_money($p['total_value'])];
}, $products), JSON_UNESCAPED_UNICODE);
?>
<script>
function exportReport(){
    exportCsv(['商品名称','SKU','仓库库存','总数量','库存价值'], <?=$jsonData?>, 'inventory_report_<?=date('Ymd')?>.csv');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
