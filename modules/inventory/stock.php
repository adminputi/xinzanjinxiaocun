<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('inventory_view');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$warehouseId = intval($_GET['warehouse_id'] ?? 0);
$categoryId = intval($_GET['category_id'] ?? 0);
$lowStock = $_GET['low_stock'] ?? 0;

$where = "WHERE 1=1";
$params = [];
if ($search) { $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.spec LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
if ($categoryId) { $where .= " AND p.category_id=?"; $params[]=$categoryId; }
if ($warehouseId) { $where .= " AND i.warehouse_id=?"; $params[]=$warehouseId; }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;

$baseSql = "FROM products p LEFT JOIN product_categories c ON p.category_id=c.id ";
if ($warehouseId) {
    $baseSql .= "JOIN inventory i ON p.id=i.product_id AND i.warehouse_id=$warehouseId ";
} else {
    $baseSql .= "LEFT JOIN (SELECT product_id, SUM(quantity) as quantity FROM inventory GROUP BY product_id) i ON p.id=i.product_id ";
}

$countSql = "SELECT COUNT(*) $baseSql $where";
$stmt = $pdo->prepare($countSql); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

if ($lowStock) {
    $where .= " AND COALESCE(i.quantity,0) <= p.min_stock AND p.min_stock > 0";
}

$sql = "SELECT p.*, c.name as category_name, COALESCE(i.quantity,0) as stock_qty $baseSql $where ORDER BY COALESCE(i.quantity,999999) ASC LIMIT $offset,$perPage";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$list = $stmt->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');
$categories = get_options('product_categories','id','name','status=1');

// 库存总览统计
$totalValue = $pdo->query("SELECT COALESCE(SUM(i.quantity * p.purchase_price),0) FROM inventory i JOIN products p ON i.product_id=p.id WHERE p.status=1")->fetchColumn();
$lowCount = $pdo->query("SELECT COUNT(*) FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) as quantity FROM inventory GROUP BY product_id) i ON p.id=i.product_id WHERE p.min_stock>0 AND p.status=1 AND COALESCE(i.quantity,0)<=p.min_stock")->fetchColumn();
$totalTypes = $pdo->query("SELECT COUNT(DISTINCT product_id) FROM inventory WHERE quantity>0")->fetchColumn();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-boxes-stacked"></i> 实时库存</h1>
    <div class="page-actions">
        <button class="btn btn-outline" onclick="exportStock()"><i class="fa-solid fa-download"></i> 导出</button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-cubes"></i></div><div class="stat-content"><div class="stat-label">库存品种数</div><div class="stat-value"><?=$totalTypes?></div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i class="fa-solid fa-coins"></i></div><div class="stat-content"><div class="stat-label">库存总价值</div><div class="stat-value">¥<?=format_money($totalValue)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="stat-content"><div class="stat-label">低库存预警</div><div class="stat-value"><?=$lowCount?> 种</div></div></div>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索品名/SKU/规格..." value="<?=htmlspecialchars($search)?>"></div>
    <select name="warehouse_id" class="form-control" style="min-width:140px;"><option value="0">全部仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>" <?=$warehouseId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <select name="category_id" class="form-control" style="min-width:140px;"><option value="0">全部分类</option><?php foreach($categories as $k=>$v): ?><option value="<?=$k?>" <?=$categoryId==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <label style="display:flex;align-items:center;gap:4px;font-size:13px;"><input type="checkbox" name="low_stock" value="1" <?=$lowStock?'checked':''?>> 仅显示预警</label>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search||$warehouseId||$categoryId||$lowStock): ?><a href="stock.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table class="stock-table">
<thead><tr><th style="width:48px;">图片</th><th class="mob-hide">SKU</th><th>商品名称</th><th class="mob-hide">分类</th><th>规格</th><th class="col-stock">库存数量</th><th>采购价</th><th class="mob-hide">库存价值</th><th>最低库存</th><th>状态</th><th style="width:60px;">操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): $stock = floatval($item['stock_qty']); $stockVal = $stock * floatval($item['purchase_price']); ?>
<tr>
    <td>
        <?php if (!empty($item['image'])): ?>
        <img src="../../<?= htmlspecialchars($item['image']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" onclick="previewImage('../../<?= htmlspecialchars($item['image']) ?>')" title="点击放大">
        <?php else: ?>
        <span style="display:inline-block;width:40px;height:40px;background:var(--gray-100);border-radius:4px;text-align:center;line-height:40px;color:var(--gray-400);font-size:16px;"><i class="fa-solid fa-box"></i></span>
        <?php endif; ?>
    </td>
    <td class="mob-hide" data-label="SKU"><?=htmlspecialchars($item['sku'])?></td>
    <td data-label="商品名称"><strong><a href="javascript:void(0)" onclick="viewProductDetail(<?=$item['id']?>)" style="color:var(--primary);text-decoration:none;"><?=htmlspecialchars($item['name'])?></a></strong></td>
    <td class="mob-hide" data-label="分类"><?=htmlspecialchars($item['category_name']?:'-')?></td>
    <td data-label="规格"><?=htmlspecialchars($item['spec']?:'-')?></td>
    <td class="col-stock" data-label="库存">
        <?php if($item['min_stock']>0 && $stock<=$item['min_stock']): ?>
        <span class="badge badge-danger stock-num"><?=$stock?></span>
        <?php elseif($stock==0): ?>
        <span class="badge badge-warning stock-num"><?=$stock?></span>
        <?php else: ?>
        <strong class="stock-num"><?=$stock?></strong>
        <?php endif; ?>
    </td>
    <td data-label="单价">¥<?=format_money($item['purchase_price'])?></td>
    <td class="mob-hide" data-label="库存价值">¥<?=format_money($stockVal)?></td>
    <td data-label="最低库存"><?=$item['min_stock']?:'-'?></td>
    <td data-label="状态"><?= $item['min_stock']>0&&$stock<=$item['min_stock'] ? '<span class="badge badge-danger">库存不足</span>' : ($stock>0?'<span class="badge badge-success">正常</span>':'<span class="badge badge-warning">缺货</span>') ?></td>
    <td><button class="btn btn-sm btn-outline" onclick="viewProductDetail(<?=$item['id']?>)" title="查看详情"><i class="fa-solid fa-eye"></i></button></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-boxes-stacked"></i><p>暂无库存数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>&warehouse_id=<?=$warehouseId?>&category_id=<?=$categoryId?>&low_stock=<?=$lowStock?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<script>
function exportStock(){var rows=document.querySelectorAll('table tbody tr');var d=[],h=['SKU','商品名称','分类','规格','库存数量','采购价','库存价值','最低库存'];rows.forEach(function(r){var c=r.querySelectorAll('td');if(c.length>=9)d.push([c[1].textContent.trim(),c[2].textContent.trim(),c[3].textContent.trim(),c[4].textContent.trim(),c[5].textContent.trim(),c[6].textContent.trim(),c[7].textContent.trim(),c[8].textContent.trim()]);});exportCsv(h,d,'stock_<?=date('Ymd')?>.csv');}

function viewProductDetail(productId) {
    openModal('productDetailModal');
    document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);"><i class="fa-solid fa-spinner fa-spin"></i> 加载中...</div>';
    fetch('../product/detail.php?id=' + productId)
    .then(function(r){return r.json();})
    .then(function(resp){
        if (!resp.success) {
            document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger);">' + (resp.message || '加载失败') + '</div>';
            return;
        }
        var p = resp.product;
        var imgs = resp.images || [];
        var inv = resp.recent_inventory || [];
        var imgHtml = '';
        if (imgs.length > 0) {
            imgHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
            imgs.forEach(function(img){
                imgHtml += '<img src="../../' + img.image_url + '" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);cursor:pointer;" onclick="previewImage(\'../../' + img.image_url + '\')" title="点击放大">';
            });
            imgHtml += '</div>';
        } else if (p.image) {
            imgHtml = '<div style="margin-bottom:16px;"><img src="../../' + p.image + '" style="max-width:200px;max-height:200px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);cursor:pointer;" onclick="previewImage(\'../../' + p.image + '\')" title="点击放大"></div>';
        } else {
            imgHtml = '<div style="margin-bottom:16px;color:var(--gray-400);font-size:13px;">暂无商品图片</div>';
        }
        var html = imgHtml;
        html += '<table style="width:100%;border-collapse:collapse;"><tbody>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);width:90px;font-size:13px;">SKU编码</td><td style="padding:6px 12px;font-weight:500;">' + escHtml(p.sku) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">商品名称</td><td style="padding:6px 12px;font-weight:500;">' + escHtml(p.name) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">分类</td><td style="padding:6px 12px;">' + escHtml(p.category_name || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">规格</td><td style="padding:6px 12px;">' + escHtml(p.spec || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">采购价</td><td style="padding:6px 12px;color:var(--warning);font-weight:500;">&yen;' + Number(p.purchase_price).toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">销售价</td><td style="padding:6px 12px;color:var(--danger);font-weight:500;">&yen;' + Number(p.sale_price).toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">当前库存</td><td style="padding:6px 12px;font-weight:500;">';
        var stock = Number(p.stock_qty);
        if (p.min_stock > 0 && stock <= Number(p.min_stock)) { html += '<span class="badge badge-danger">' + stock + '</span>'; }
        else if (stock == 0) { html += '<span class="badge badge-warning">' + stock + '</span>'; }
        else { html += '<span class="badge badge-success">' + stock + '</span>'; }
        html += '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">库存金额</td><td style="padding:6px 12px;color:var(--primary);font-weight:500;">&yen;' + Number(p.stock_value || 0).toFixed(2) + '</td></tr>';
        if (p.remark) {
            html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">备注</td><td style="padding:6px 12px;word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap;max-width:400px;">' + escHtml(p.remark) + '</td></tr>';
        }
        html += '</tbody></table>';
        if (inv.length > 0) {
            html += '<hr style="margin:16px 0;border-color:var(--gray-200);"><h4 style="margin-bottom:8px;font-size:14px;">最近10条出入库记录</h4>';
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr><th style="padding:6px;border-bottom:1px solid var(--gray-200);text-align:left;">类型</th><th style="padding:6px;border-bottom:1px solid var(--gray-200);text-align:right;">数量</th><th style="padding:6px;border-bottom:1px solid var(--gray-200);text-align:left;">备注</th><th style="padding:6px;border-bottom:1px solid var(--gray-200);">时间</th></tr></thead><tbody>';
            inv.forEach(function(log){
                html += '<tr><td style="padding:4px 6px;">' + escHtml(log.type_name || log.type) + '</td><td style="padding:4px 6px;text-align:right;">' + Number(log.change_quantity).toFixed(0) + '</td><td style="padding:4px 6px;word-break:break-word;">' + escHtml(log.remark || '-') + '</td><td style="padding:4px 6px;white-space:nowrap;">' + (log.created_at || '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        document.getElementById('productDetailBody').innerHTML = html;
    })
    .catch(function(e){
        document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger);">网络错误，请重试</div>';
    });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function previewImage(url) {
    document.getElementById('imgPreviewSrc').src = url;
    openModal('imagePreviewModal');
}
</script>

<!-- 商品详情弹窗 -->
<div class="modal-overlay" id="productDetailModal">
    <div class="modal modal-md">
        <div class="modal-header">
            <h3 class="modal-title">商品详情</h3>
            <button class="modal-close" onclick="closeModal('productDetailModal')">&times;</button>
        </div>
        <div class="modal-body" id="productDetailBody">
            <div style="text-align:center;padding:40px;color:var(--gray-400);">加载中...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('productDetailModal')">关闭</button>
        </div>
    </div>
</div>

<!-- 图片预览弹窗 -->
<div class="modal-overlay" id="imagePreviewModal" onclick="closeModal('imagePreviewModal')">
    <div style="max-width:90vw;max-height:90vh;position:relative;top:50%;left:50%;transform:translate(-50%,-50%);">
        <img id="imgPreviewSrc" src="" style="max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

