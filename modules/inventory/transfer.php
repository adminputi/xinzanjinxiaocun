<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('transfer_manage');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;

// POST操作：确认、撤回
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['confirm','withdraw'])) {
    csrf_verify();
    $getAction = $_POST['action'];
    $getId = intval($_POST['id'] ?? 0);
if ($getId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id=?");
    $stmt->execute([$getId]);
    $record = $stmt->fetch();
    if ($record && $record['status'] === 'draft' && $getAction === 'confirm') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE transfers SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $getId]);
            $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id=?");
            $stmt->execute([$getId]);
            $items = $stmt->fetchAll();
            foreach ($items as $it) {
                update_inventory($it['product_id'], $record['from_warehouse_id'], -$it['quantity'], 'transfer_out', $record['bill_no'], 'transfer', get_user_id(), "调拨至仓库ID:{$record['to_warehouse_id']}");
                update_inventory($it['product_id'], $record['to_warehouse_id'], $it['quantity'], 'transfer_in', $record['bill_no'], 'transfer', get_user_id(), "从仓库ID:{$record['from_warehouse_id']}调入");
            }
            add_log(get_user_id(), 'confirm', 'transfer', "确认调拨: {$record['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Transfer confirm error: '.$e->getMessage()); $error = '确认失败，请稍后重试'; }
    } elseif ($record && $record['status'] === 'confirmed' && $getAction === 'withdraw') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE transfers SET status='draft' WHERE id=?")->execute([$getId]);
            $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id=?");
            $stmt->execute([$getId]);
            $items = $stmt->fetchAll();
            foreach ($items as $it) {
                // 反向操作：从调入仓库减少，从调出仓库增加
                update_inventory($it['product_id'], $record['to_warehouse_id'], -$it['quantity'], 'transfer_out', $record['bill_no'], 'transfer', get_user_id(), "撤回调拨-从调入仓库扣回");
                update_inventory($it['product_id'], $record['from_warehouse_id'], $it['quantity'], 'transfer_in', $record['bill_no'], 'transfer', get_user_id(), "撤回调拨-恢复到调出仓库");
            }
            add_log(get_user_id(), 'withdraw', 'transfer', "撤回调拨: {$record['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Transfer withdraw error: '.$e->getMessage()); $error = '撤回失败，请稍后重试'; }
    }
    if (!isset($error)) redirect('transfer.php');
}
}

$total = $pdo->query("SELECT COUNT(*) FROM transfers")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT t.*, w1.name as from_name, w2.name as to_name FROM transfers t LEFT JOIN warehouses w1 ON t.from_warehouse_id=w1.id LEFT JOIN warehouses w2 ON t.to_warehouse_id=w2.id ORDER BY t.id DESC LIMIT $offset,$perPage")->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');
$products = $pdo->query("SELECT id,sku,name FROM products WHERE status=1")->fetchAll();

// 编辑时加载数据
$editData = null;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id=? AND status='draft'");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
    if ($editData) {
        $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id=?");
        $stmt->execute([$editId]);
        $editItems = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save' || $action === 'update') {
        csrf_verify();
        $editTId = intval($_POST['id'] ?? 0);
        $fromId = intval($_POST['from_warehouse_id']??0);
        $toId = intval($_POST['to_warehouse_id']??0);
        $transferDate = $_POST['transfer_date']??date('Y-m-d');
        $remark = $_POST['remark']??'';
        $pids = $_POST['product_id']??[];
        $qtys = $_POST['quantity']??[];

        if ($fromId == $toId) { $error = '调出仓库和调入仓库不能相同'; }
        else {
            $itemData = [];
            foreach ($pids as $i => $pid) {
                $qty = floatval($qtys[$i]??0);
                if ($pid && $qty>0) $itemData[]=['pid'=>intval($pid),'qty'=>$qty];
            }
            if (empty($itemData)) { $error = '至少添加一个商品'; }
            else {
                $pdo->beginTransaction();
                try {
                    if ($action === 'update' && $editTId > 0) {
                        $st = $pdo->prepare("SELECT * FROM transfers WHERE id=?");
                        $st->execute([$editTId]);
                        $old = $st->fetch();
                        if ($old && $old['status'] === 'draft') {
                            $pdo->prepare("UPDATE transfers SET from_warehouse_id=?,to_warehouse_id=?,transfer_date=?,remark=?,created_at=? WHERE id=?")->execute([$fromId,$toId,$transferDate,$remark,date('Y-m-d H:i:s'),$editTId]);
                            $pdo->prepare("DELETE FROM transfer_items WHERE transfer_id=?")->execute([$editTId]);
                            $insStmt = $pdo->prepare("INSERT INTO transfer_items (transfer_id,product_id,quantity) VALUES (?,?,?)");
                            foreach ($itemData as $it) { $insStmt->execute([$editTId,$it['pid'],$it['qty']]); }
                            add_log(get_user_id(), 'update', 'transfer', "编辑调拨单: {$old['bill_no']}");
                        }
                    } else {
                        $billNo = generate_bill_no('DB');
                        $pdo->prepare("INSERT INTO transfers (bill_no,from_warehouse_id,to_warehouse_id,transfer_date,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?)")->execute([$billNo,$fromId,$toId,$transferDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                        $tId = $pdo->lastInsertId();
                        $insStmt = $pdo->prepare("INSERT INTO transfer_items (transfer_id,product_id,quantity) VALUES (?,?,?)");
                        foreach ($itemData as $it) { $insStmt->execute([$tId,$it['pid'],$it['qty']]); }
                        add_log(get_user_id(), 'create', 'transfer', "调拨单: $billNo");
                    }
                    $pdo->commit();
                    redirect('transfer.php');
                } catch (Exception $e) { $pdo->rollBack(); error_log('Transfer save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
            }
        }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-right-left"></i> 调拨管理</h1>
    <button class="btn btn-primary" onclick="resetTfForm();openModal('transModal')"><i class="fa-solid fa-plus"></i> 新增调拨单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>调出仓库</th><th>调入仓库</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['from_name']?:'-')?></td>
    <td><?=htmlspecialchars($item['to_name']?:'-')?></td>
    <td><?=$item['created_at']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':'warning'?>"><?=$item['status']=='confirmed'?'已确认':'草稿'?></span></td>
    <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['remark']??'')?>"><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,12))?></td>
    <td>
        <?php if ($item['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认后库存将变更，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success" title="确认"><i class="fa-solid fa-check"></i></button></form>
        <a href="?edit=<?=$item['id']?>" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($item['status'] === 'confirmed'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-warning" title="撤回"><i class="fa-solid fa-undo"></i></button></form>
        <?php endif; ?>
        <a href="transfer_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-right-left"></i><p>暂无调拨记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="transModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="tfModalTitle">新增调拨单</h3><button class="modal-close" onclick="closeModal('transModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" id="tfAction" value="save"><input type="hidden" name="id" id="tfEditId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">调出仓库 <span class="required">*</span></label><select name="from_warehouse_id" id="tfFromWh" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">调入仓库 <span class="required">*</span></label><select name="to_warehouse_id" id="tfToWh" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">调拨日期</label><input type="date" name="transfer_date" id="tfDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><button type="button" class="btn btn-sm btn-outline" onclick="addTfRow()"><i class="fa-solid fa-plus"></i> 添加</button></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:140px">数量</th><th style="width:50px"></th></tr></thead>
        <tbody id="tfItems"><tr id="tfTpl">
            <td><select name="product_id[]" class="form-control" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="0.01" name="quantity[]" class="form-control" value="1" required></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">×</button></td>
        </tr></tbody>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" id="tfRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('transModal')">取消</button><button type="submit" class="btn btn-primary" id="tfSubmitBtn">保存调拨</button></div>
</form></div></div>

<?php if ($editData): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('tfModalTitle').textContent = '编辑调拨单';
    document.getElementById('tfAction').value = 'update';
    document.getElementById('tfEditId').value = '<?=$editData['id']?>';
    document.getElementById('tfFromWh').value = '<?=$editData['from_warehouse_id']?>';
    document.getElementById('tfToWh').value = '<?=$editData['to_warehouse_id']?>';
    document.getElementById('tfDate').value = '<?=$editData['transfer_date']?>';
    document.getElementById('tfRemark').value = '<?=js_escape($editData['remark']??'')?>';
    document.getElementById('tfSubmitBtn').textContent = '更新调拨';
    document.getElementById('tfTpl').style.display = 'none';
    <?php foreach ($editItems as $ei): ?>
    var tpl = document.getElementById('tfTpl');
    var row = tpl.cloneNode(true);
    row.removeAttribute('id');
    row.style.display = '';
    row.querySelector('select').value = '<?=$ei['product_id']?>';
    row.querySelector('input[type=number]').value = '<?=$ei['quantity']?>';
    document.getElementById('tfItems').appendChild(row);
    <?php endforeach; ?>
    openModal('transModal');
});
</script>
<?php endif; ?>

<script>
function resetTfForm(){
    document.getElementById('tfModalTitle').textContent = '新增调拨单';
    document.getElementById('tfAction').value = 'save';
    document.getElementById('tfEditId').value = '0';
    document.getElementById('tfFromWh').selectedIndex = 0;
    document.getElementById('tfToWh').selectedIndex = 0;
    document.getElementById('tfDate').value = '<?=date('Y-m-d')?>';
    document.getElementById('tfRemark').value = '';
    document.getElementById('tfSubmitBtn').textContent = '保存调拨';
    var items = document.getElementById('tfItems');
    var rows = items.querySelectorAll('tr:not(#tfTpl)');
    rows.forEach(function(r){ r.remove(); });
    document.getElementById('tfTpl').style.display = '';
    document.getElementById('tfTpl').querySelector('select').selectedIndex = 0;
    document.getElementById('tfTpl').querySelector('input[type=number]').value = 1;
}
function addTfRow(){var tpl=document.getElementById('tfTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelector('input').value=1;document.getElementById('tfItems').appendChild(row);}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
