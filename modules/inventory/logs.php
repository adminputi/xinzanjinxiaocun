<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('inventory_log');
$pdo = getDB();

$page = max(1, intval($_GET['page'] ?? 1));
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// 删除记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $delId = intval($_POST['id'] ?? 0);
    if ($delId > 0) {
        $pdo->prepare("DELETE FROM inventory_logs WHERE id=?")->execute([$delId]);
        add_log(get_user_id(), 'delete', 'inventory_log', "删除库存变动记录ID: $delId");
    }
    redirect("logs.php?page=$page&date_from=$dateFrom&date_to=$dateTo&type=$type&search=".urlencode($search));
}

$where = "WHERE l.created_at BETWEEN ? AND ?";
$params = [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'];
if ($type) { $where .= " AND l.type=?"; $params[]=$type; }
if ($search) { $where .= " AND (p.name LIKE ? OR l.bill_no LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_logs l JOIN products p ON l.product_id=p.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT l.*, p.name as product_name, p.sku, w.name as warehouse_name, u.real_name as user_name FROM inventory_logs l JOIN products p ON l.product_id=p.id LEFT JOIN warehouses w ON l.warehouse_id=w.id LEFT JOIN users u ON l.user_id=u.id $where ORDER BY l.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$typeLabels = ['in'=>'入库','out'=>'出库','transfer_in'=>'调拨入','transfer_out'=>'调拨出','check'=>'盘点调整','loss'=>'报损报溢'];
$typeBadges = ['in'=>'success','out'=>'danger','transfer_in'=>'info','transfer_out'=>'warning','check'=>'primary','loss'=>'orange'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clock-rotate-left"></i> 库存变动记录</h1>
    <button class="btn btn-outline" onclick="exportLogs()"><i class="fa-solid fa-download"></i> 导出</button>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:140px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:140px;">
    <select name="type" class="form-control" style="min-width:120px;"><option value="">全部类型</option><?php foreach($typeLabels as $k=>$v): ?><option value="<?=$k?>" <?=$type==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索商品/单据..." value="<?=htmlspecialchars($search)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>时间</th><th>商品</th><th>仓库</th><th>类型</th><th>变动数量</th><th>变动后库存</th><th>单据号</th><th>操作人</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?=$item['created_at']?></td>
    <td><strong><?=htmlspecialchars($item['product_name'])?></strong><br><small><?=$item['sku']?></small></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><span class="badge badge-<?=$typeBadges[$item['type']]??'gray'?>"><?=$typeLabels[$item['type']]??$item['type']?></span></td>
    <td style="color:<?=$item['change_quantity']>0?'var(--success)':'var(--danger)'?>;font-weight:bold;"><?=$item['change_quantity']>0?'+'.$item['change_quantity']:$item['change_quantity']?></td>
    <td><?=$item['current_quantity']?></td>
    <td><?=$item['bill_no']?></td>
    <td><?=htmlspecialchars($item['user_name']?:'-')?></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'','0','20'))?></td>
    <td>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除该库存变动记录？此操作不可恢复')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?=$item['id']?>">
            <button class="btn btn-sm btn-outline" title="删除"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>
        </form>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i><p>暂无变动记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&type=<?=$type?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<script>
function exportLogs(){var rows=document.querySelectorAll('table tbody tr');var d=[],h=['时间','商品','SKU','仓库','类型','变动数量','变动后库存','单据号','操作人'];rows.forEach(function(r){var c=r.querySelectorAll('td');if(c.length>=8)d.push([c[0].textContent.trim(),c[1].textContent.trim().split('\n')[0],c[1].querySelector('small')?.textContent||'',c[2].textContent.trim(),c[3].textContent.trim(),c[4].textContent.trim(),c[5].textContent.trim(),c[6].textContent.trim(),c[7].textContent.trim()]);});exportCsv(h,d,'inventory_logs_<?=date('Ymd')?>.csv');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
