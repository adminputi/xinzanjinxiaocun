<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('check_manage');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM check_orders")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT c.*, w.name as warehouse_name FROM check_orders c LEFT JOIN warehouses w ON c.warehouse_id=w.id ORDER BY c.id DESC LIMIT $offset,$perPage")->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create') {
    csrf_verify();
    $warehouseId = intval($_POST['warehouse_id']??0);
    $checkDate = $_POST['check_date']??date('Y-m-d');
    $remark = $_POST['remark']??'';

    $pdo->beginTransaction();
    try {
        $billNo = generate_bill_no('PD');
        $pdo->prepare("INSERT INTO check_orders (bill_no,warehouse_id,status,check_date,remark,user_id,created_at) VALUES (?,?,'draft',?,?,?,?)")->execute([$billNo,$warehouseId,$checkDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
        $checkId = $pdo->lastInsertId();

        // 加载该仓库所有商品库存作为账面数
        $st = $pdo->prepare("SELECT i.product_id, i.quantity FROM inventory i JOIN products p ON i.product_id=p.id WHERE i.warehouse_id=? AND p.status=1");
        $st->execute([$warehouseId]);
        $stockItems = $st->fetchAll();
        $insStmt = $pdo->prepare("INSERT INTO check_items (check_id,product_id,book_qty,actual_qty,diff_qty) VALUES (?,?,?,0,0)");
        foreach ($stockItems as $si) { $insStmt->execute([$checkId,$si['product_id'],$si['quantity']]); }
        add_log(get_user_id(), 'create', 'check_order', "创建盘点单: $billNo");
        $pdo->commit();
        redirect("check_edit.php?id=$checkId");
    } catch (Exception $e) { $pdo->rollBack(); error_log('Check create error: ' . $e->getMessage()); $error = '创建失败，请查看系统日志'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'confirm') {
    csrf_verify();
    $checkId = intval($_POST['id']??0);
    $st = $pdo->prepare("SELECT * FROM check_orders WHERE id=?");
    $st->execute([$checkId]);
    $checkOrder = $st->fetch();

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM check_items WHERE check_id=?");
        $st->execute([$checkId]);
        $items = $st->fetchAll();
        foreach ($items as $item) {
            if ($item['diff_qty'] != 0) {
                update_inventory($item['product_id'], $checkOrder['warehouse_id'], $item['diff_qty'], 'check', $checkOrder['bill_no'], 'check', get_user_id(), '盘点调整');
            }
        }
        $pdo->prepare("UPDATE check_orders SET status='confirmed' WHERE id=?")->execute([$checkId]);
        add_log(get_user_id(), 'confirm', 'check_order', "确认盘点: {$checkOrder['bill_no']}");
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); error_log('Check confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
    redirect('check.php');
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clipboard-check"></i> 盘点管理</h1>
    <button class="btn btn-primary" onclick="openModal('checkModal')"><i class="fa-solid fa-plus"></i> 新建盘点单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>仓库</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><?=$item['check_date']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':'warning'?>"><?=$item['status']=='confirmed'?'已完成':'待盘点'?></span></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'','0','20'))?></td>
    <td>
        <?php if($item['status']=='draft'): ?>
        <a href="check_edit.php?id=<?=$item['id']?>" class="btn btn-sm btn-primary">盘点</a>
        <?php endif; ?>
        <a href="check_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-clipboard-check"></i><p>暂无盘点记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="checkModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">新建盘点单</h3><button class="modal-close" onclick="closeModal('checkModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="modal-body">
    <div class="form-group"><label class="form-label">盘点仓库 <span class="required">*</span></label><select name="warehouse_id" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">盘点日期</label><input type="date" name="check_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('checkModal')">取消</button><button type="submit" class="btn btn-primary">创建盘点单</button></div>
</form></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
