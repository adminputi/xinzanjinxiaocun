<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('loss_manage');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;

// POST操作：确认、撤回
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['confirm','withdraw'])) {
    csrf_verify();
    $getAction = $_POST['action'];
    $getId = intval($_POST['id'] ?? 0);
if ($getId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM loss_orders WHERE id=?");
    $stmt->execute([$getId]);
    $record = $stmt->fetch();
    if ($record && $record['status'] === 'draft' && $getAction === 'confirm') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE loss_orders SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $getId]);
            $stmt = $pdo->prepare("SELECT * FROM loss_items WHERE loss_id=?");
            $stmt->execute([$getId]);
            $items = $stmt->fetchAll();
            foreach ($items as $it) {
                $changeQty = $record['type']=='loss' ? -$it['quantity'] : $it['quantity'];
                update_inventory($it['product_id'], $record['warehouse_id'], $changeQty, 'loss', $record['bill_no'], 'loss', get_user_id(), $it['reason']??'');
            }
            add_log(get_user_id(), 'confirm', 'loss_order', "确认报损报溢: {$record['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Loss confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
    } elseif ($record && $record['status'] === 'confirmed' && $getAction === 'withdraw') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE loss_orders SET status='draft' WHERE id=?")->execute([$getId]);
            $stmt = $pdo->prepare("SELECT * FROM loss_items WHERE loss_id=?");
            $stmt->execute([$getId]);
            $items = $stmt->fetchAll();
            foreach ($items as $it) {
                $reverseQty = $record['type']=='loss' ? $it['quantity'] : -$it['quantity'];
                update_inventory($it['product_id'], $record['warehouse_id'], $reverseQty, 'loss', $record['bill_no'], 'loss', get_user_id(), '撤回报损报溢');
            }
            add_log(get_user_id(), 'withdraw', 'loss_order', "撤回报损报溢: {$record['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Loss withdraw error: ' . $e->getMessage()); $error = '撤回失败，请查看系统日志'; }
    }
    if (!isset($error)) redirect('loss.php');
}
}

$total = $pdo->query("SELECT COUNT(*) FROM loss_orders")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT l.*, w.name as warehouse_name FROM loss_orders l LEFT JOIN warehouses w ON l.warehouse_id=w.id ORDER BY l.id DESC LIMIT $offset,$perPage")->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');
$products = $pdo->query("SELECT id,sku,name,purchase_price FROM products WHERE status=1")->fetchAll();

// 编辑时加载数据
$editData = null;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM loss_orders WHERE id=? AND status='draft'");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
    if ($editData) {
        $stmt = $pdo->prepare("SELECT * FROM loss_items WHERE loss_id=?");
        $stmt->execute([$editId]);
        $editItems = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save' || $action === 'update') {
        csrf_verify();
        $editLId = intval($_POST['id'] ?? 0);
        $warehouseId = intval($_POST['warehouse_id']??0);
        $type = $_POST['type'] ?? 'loss';
        $orderDate = $_POST['order_date']??date('Y-m-d');
        $remark = $_POST['remark']??'';
        $pids = $_POST['product_id']??[];
        $qtys = $_POST['quantity']??[];
        $prices = $_POST['price']??[];
        $reasons = $_POST['reason']??[];

        $pdo->beginTransaction();
        try {
            $totalAmount = 0; $itemData = [];
            foreach ($pids as $i => $pid) {
                $qty = floatval($qtys[$i]??0); $price = floatval($prices[$i]??0);
                if ($pid && $qty>0) { $amt=$qty*$price; $totalAmount+=$amt; $itemData[]=['pid'=>intval($pid),'qty'=>$qty,'price'=>$price,'amt'=>$amt,'reason'=>$reasons[$i]??'']; }
            }
            if (empty($itemData)) { $error='至少添加一个商品'; $pdo->rollBack(); }
            else {
                if ($action === 'update' && $editLId > 0) {
                    $st = $pdo->prepare("SELECT * FROM loss_orders WHERE id=?");
                    $st->execute([$editLId]);
                    $old = $st->fetch();
                    if ($old && $old['status'] === 'draft') {
                        $pdo->prepare("UPDATE loss_orders SET warehouse_id=?,type=?,order_date=?,remark=?,created_at=? WHERE id=?")->execute([$warehouseId,$type,$orderDate,$remark,date('Y-m-d H:i:s'),$editLId]);
                        $pdo->prepare("DELETE FROM loss_items WHERE loss_id=?")->execute([$editLId]);
                        $insStmt = $pdo->prepare("INSERT INTO loss_items (loss_id,product_id,quantity,amount,reason) VALUES (?,?,?,?,?)");
                        foreach ($itemData as $it) {
                            $insStmt->execute([$editLId,$it['pid'],$it['qty'],$it['amt'],$it['reason']]);
                        }
                        add_log(get_user_id(), 'update', 'loss_order', "编辑报损报溢: {$old['bill_no']}");
                    }
                } else {
                    $prefix = $type=='loss'?'BS':'BY';
                    $billNo = generate_bill_no($prefix);
                    $pdo->prepare("INSERT INTO loss_orders (bill_no,warehouse_id,type,order_date,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?)")->execute([$billNo,$warehouseId,$type,$orderDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                    $lossId = $pdo->lastInsertId();
                    $insStmt = $pdo->prepare("INSERT INTO loss_items (loss_id,product_id,quantity,amount,reason) VALUES (?,?,?,?,?)");
                    foreach ($itemData as $it) {
                        $insStmt->execute([$lossId,$it['pid'],$it['qty'],$it['amt'],$it['reason']]);
                    }
                    add_log(get_user_id(), 'create', 'loss_order', "报损报溢: $billNo");
                }
                $pdo->commit();
                redirect('loss.php');
            }
        } catch (Exception $e) { $pdo->rollBack(); error_log('Loss save error: ' . $e->getMessage()); $error = '保存失败，请查看系统日志'; }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-triangle-exclamation"></i> 报损报溢</h1>
    <button class="btn btn-primary" onclick="openModal('lossModal');resetLossForm()"><i class="fa-solid fa-plus"></i> 新增报损/报溢单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>仓库</th><th>类型</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><span class="badge badge-<?=$item['type']=='loss'?'danger':'success'?>"><?=$item['type']=='loss'?'报损':'报溢'?></span></td>
    <td><?=$item['created_at']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':($item['status']=='cancelled'?'gray':'warning')?>"><?=$item['status']=='confirmed'?'已确认':($item['status']=='cancelled'?'已取消':'草稿')?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['remark']??'')?>"><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,15))?></td>
    <td>
        <?php if ($item['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认后库存将变更，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success" title="确认"><i class="fa-solid fa-check"></i></button></form>
        <a href="?edit=<?=$item['id']?>" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($item['status'] === 'confirmed'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-warning" title="撤回"><i class="fa-solid fa-undo"></i></button></form>
        <?php endif; ?>
        <a href="loss_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>暂无报损报溢记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="lossModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="lossModalTitle">新增报损/报溢单</h3><button class="modal-close" onclick="closeModal('lossModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" id="lossAction" value="save"><input type="hidden" name="id" id="lossEditId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" id="lossWarehouseId" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">类型 <span class="required">*</span></label><select name="type" id="lossType" class="form-control"><option value="loss">报损（减少库存）</option><option value="overflow">报溢（增加库存）</option></select></div>
        <div class="form-group"><label class="form-label">日期</label><input type="date" name="order_date" id="lossOrderDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><button type="button" class="btn btn-sm btn-outline" onclick="addLRow()"><i class="fa-solid fa-plus"></i> 添加</button></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:100px">数量</th><th style="width:100px">单价</th><th style="width:160px">原因</th><th style="width:50px"></th></tr></thead>
        <tbody id="lossItems"><tr id="lossTpl">
            <td><select name="product_id[]" class="form-control" onchange="updateLp(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['purchase_price']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="0.01" name="quantity[]" class="form-control" value="1" required></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control lprice" value="0" required></td>
            <td><input type="text" name="reason[]" class="form-control" placeholder="原因"></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">×</button></td>
        </tr></tbody>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" id="lossRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('lossModal')">取消</button><button type="submit" class="btn btn-primary" id="lossSubmitBtn">保存</button></div>
</form></div></div>

<?php if ($editData): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('lossModalTitle').textContent = '编辑报损/报溢单';
    document.getElementById('lossAction').value = 'update';
    document.getElementById('lossEditId').value = '<?=$editData['id']?>';
    document.getElementById('lossWarehouseId').value = '<?=$editData['warehouse_id']?>';
    document.getElementById('lossType').value = '<?=$editData['type']?>';
    document.getElementById('lossOrderDate').value = '<?=$editData['order_date']?>';
    document.getElementById('lossRemark').value = '<?=js_escape($editData['remark']??'')?>';
    document.getElementById('lossSubmitBtn').textContent = '更新';
    document.getElementById('lossTpl').style.display = 'none';
    <?php foreach ($editItems as $ei): ?>
    var tpl = document.getElementById('lossTpl');
    var row = tpl.cloneNode(true);
    row.removeAttribute('id');
    row.style.display = '';
    row.querySelector('select').value = '<?=$ei['product_id']?>';
    row.querySelectorAll('input[type=number]')[0].value = '<?=$ei['quantity']?>';
    row.querySelector('.lprice').value = '<?=$ei['amount']>0?round($ei['amount']/$ei['quantity'],2):0?>';
    row.querySelector('input[type=text]').value = '<?=js_escape($ei['reason']??'')?>';
    document.getElementById('lossItems').appendChild(row);
    <?php endforeach; ?>
    if (document.querySelectorAll('#lossItems tr:not([style*="display: none"])').length === 0) {
        document.getElementById('lossTpl').style.display = '';
    }
    openModal('lossModal');
});
</script>
<?php endif; ?>

<script>
function resetLossForm(){
    document.getElementById('lossModalTitle').textContent = '新增报损/报溢单';
    document.getElementById('lossAction').value = 'save';
    document.getElementById('lossEditId').value = '0';
    document.getElementById('lossWarehouseId').selectedIndex = 0;
    document.getElementById('lossType').value = 'loss';
    document.getElementById('lossOrderDate').value = '<?=date('Y-m-d')?>';
    document.getElementById('lossRemark').value = '';
    document.getElementById('lossSubmitBtn').textContent = '保存';
    var items = document.getElementById('lossItems');
    var rows = items.querySelectorAll('tr:not(#lossTpl)');
    rows.forEach(function(r){ r.remove(); });
    document.getElementById('lossTpl').style.display = '';
    document.getElementById('lossTpl').querySelector('select').selectedIndex = 0;
    document.getElementById('lossTpl').querySelectorAll('input[type=number]')[0].value = 1;
    document.getElementById('lossTpl').querySelector('.lprice').value = 0;
    document.getElementById('lossTpl').querySelector('input[type=text]').value = '';
}
function addLRow(){var tpl=document.getElementById('lossTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelectorAll('input[type=number]')[0].value=1;row.querySelector('.lprice').value=0;document.getElementById('lossItems').appendChild(row);}
function updateLp(sel){var row=sel.closest('tr');var p=sel.options[sel.selectedIndex].getAttribute('data-price')||0;row.querySelector('.lprice').value=p;}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
