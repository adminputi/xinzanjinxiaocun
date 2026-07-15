<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('purchase_return');
$pdo = getDB();

// 执行数据库迁移
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;

// POST操作：确认退货
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    csrf_verify();
    $getId = intval($_POST['id'] ?? 0);
    if ($getId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM purchase_returns WHERE id=?");
        $stmt->execute([$getId]);
        $record = $stmt->fetch();
        if ($record && $record['status'] === 'draft') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE purchase_returns SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $getId]);
                $stmt = $pdo->prepare("SELECT * FROM purchase_return_items WHERE return_id=?");
                $stmt->execute([$getId]);
                $items = $stmt->fetchAll();
                foreach ($items as $it) {
                    update_inventory($it['product_id'], $record['warehouse_id'], -$it['quantity'], 'out', $record['bill_no'], 'purchase_return', get_user_id());
                }
                add_log(get_user_id(), 'confirm', 'purchase_return', "确认退货: {$record['bill_no']}");
                $pdo->commit();
            } catch (Exception $e) { $pdo->rollBack(); error_log('Purchase return confirm error: '.$e->getMessage()); $error = '确认失败，请稍后重试'; }
        }
    }
    if (!isset($error)) redirect('return.php');
}

// POST操作：撤回（需要输入原因）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    csrf_verify();
    $wId = intval($_POST['id'] ?? 0);
    $cancelReason = trim($_POST['cancel_reason'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM purchase_returns WHERE id=?");
    $stmt->execute([$wId]);
    $record = $stmt->fetch();
    if ($record && $record['status'] === 'confirmed') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchase_returns SET status='draft', cancel_reason=? WHERE id=?")->execute([$cancelReason, $wId]);
            $stmt = $pdo->prepare("SELECT * FROM purchase_return_items WHERE return_id=?");
            $stmt->execute([$wId]);
            $items = $stmt->fetchAll();
            foreach ($items as $it) {
                update_inventory($it['product_id'], $record['warehouse_id'], $it['quantity'], 'in', $record['bill_no'], 'purchase_return_cancel', get_user_id(), '撤回退货');
            }
            add_log(get_user_id(), 'withdraw', 'purchase_return', "撤回退货: {$record['bill_no']}，原因：{$cancelReason}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Purchase return withdraw error: '.$e->getMessage()); $error = '撤回失败，请稍后重试'; }
    }
    if (!isset($error)) redirect('return.php');
}

$total = $pdo->query("SELECT COUNT(*) FROM purchase_returns")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT pr.*, s.name as supplier_name, w.name as warehouse_name FROM purchase_returns pr LEFT JOIN suppliers s ON pr.supplier_id=s.id LEFT JOIN warehouses w ON pr.warehouse_id=w.id ORDER BY pr.id DESC LIMIT $offset,$perPage")->fetchAll();

$suppliers = get_options('suppliers','id','name','status=1');
$warehouses = get_options('warehouses','id','name','status=1');
$products = $pdo->query("SELECT id,sku,name,purchase_price FROM products WHERE status=1")->fetchAll();

// POST保存/更新退货单
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save' || $action === 'update') {
        csrf_verify();
        $editId = intval($_POST['id'] ?? 0);
        $supplierId = intval($_POST['supplier_id']??0);
        $warehouseId = intval($_POST['warehouse_id']??0);
        $returnDate = $_POST['return_date']??date('Y-m-d');
        $remark = $_POST['remark']??'';
        $pids = $_POST['product_id']??[];
        $qtys = $_POST['quantity']??[];
        $prices = $_POST['price']??[];

        $pdo->beginTransaction();
        try {
            $totalAmount = 0; $itemData = [];
            foreach ($pids as $i => $pid) {
                $qty = floatval($qtys[$i]??0); $price = floatval($prices[$i]??0);
                if ($pid && $qty>0) { $amt = $qty*$price; $totalAmount += $amt; $itemData[]=['pid'=>intval($pid),'qty'=>$qty,'price'=>$price,'amt'=>$amt]; }
            }
            if (empty($itemData)) { $error='至少添加一个商品'; $pdo->rollBack(); }
            else {
                if ($action === 'update' && $editId > 0) {
                    $st = $pdo->prepare("SELECT * FROM purchase_returns WHERE id=?");
                    $st->execute([$editId]);
                    $old = $st->fetch();
                    if ($old && $old['status'] === 'draft') {
                        $pdo->prepare("UPDATE purchase_returns SET supplier_id=?,warehouse_id=?,total_amount=?,return_date=?,remark=?,created_at=? WHERE id=?")->execute([$supplierId,$warehouseId,$totalAmount,$returnDate,$remark,date('Y-m-d H:i:s'),$editId]);
                        $pdo->prepare("DELETE FROM purchase_return_items WHERE return_id=?")->execute([$editId]);
                        $insStmt = $pdo->prepare("INSERT INTO purchase_return_items (return_id,product_id,quantity,price,amount) VALUES (?,?,?,?,?)");
                        foreach ($itemData as $it) {
                            $insStmt->execute([$editId,$it['pid'],$it['qty'],$it['price'],$it['amt']]);
                        }
                        add_log(get_user_id(), 'update', 'purchase_return', "编辑退货: {$old['bill_no']}");
                    }
                } else {
                    $billNo = generate_bill_no('CT');
                    $pdo->prepare("INSERT INTO purchase_returns (bill_no,supplier_id,warehouse_id,total_amount,return_date,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$billNo,$supplierId,$warehouseId,$totalAmount,$returnDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                    $retId = $pdo->lastInsertId();
                    $insStmt = $pdo->prepare("INSERT INTO purchase_return_items (return_id,product_id,quantity,price,amount) VALUES (?,?,?,?,?)");
                    foreach ($itemData as $it) {
                        $insStmt->execute([$retId,$it['pid'],$it['qty'],$it['price'],$it['amt']]);
                    }
                    add_log(get_user_id(), 'create', 'purchase_return', "采购退货: $billNo");
                }
                $pdo->commit();
                redirect('return.php');
            }
        } catch (Exception $e) { $pdo->rollBack(); error_log('Purchase return save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
    }
}


// 编辑时加载数据
$editData = null;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT pr.* FROM purchase_returns pr WHERE pr.id=? AND pr.status='draft'");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
    if ($editData) {
        $stmt = $pdo->prepare("SELECT * FROM purchase_return_items WHERE return_id=?");
        $stmt->execute([$editId]);
        $editItems = $stmt->fetchAll();
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-rotate-left"></i> 采购退货</h1>
    <button class="btn btn-primary" onclick="openModal('returnModal');resetReturnForm()"><i class="fa-solid fa-plus"></i> 新增退货单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>供应商</th><th>仓库</th><th>金额</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['supplier_name']?:'-')?></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><strong>¥<?=format_money($item['total_amount'])?></strong></td>
    <td><?=$item['created_at']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':($item['status']=='cancelled'?'gray':'warning')?>"><?=$item['status']=='confirmed'?'已确认':($item['status']=='cancelled'?'已取消':'草稿')?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['remark']??'')?>"><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,15))?></td>
    <td>
        <?php if ($item['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认退货后库存将减少，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success" title="确认退货"><i class="fa-solid fa-check"></i></button></form>
        <a href="?edit=<?=$item['id']?>" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($item['status'] === 'confirmed'): ?>
        <a href="javascript:void(0)" class="btn btn-sm btn-warning" onclick="showPReturnWithdraw(<?=$item['id']?>,'<?=$item['bill_no']?>')" title="撤回"><i class="fa-solid fa-undo"></i></a>
        <?php endif; ?>
        <a href="return_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-rotate-left"></i><p>暂无退货记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="returnModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="retModalTitle">新增采购退货</h3><button class="modal-close" onclick="closeModal('returnModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" id="retAction" value="save"><input type="hidden" name="id" id="retEditId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">供应商 <span class="required">*</span></label><select name="supplier_id" id="retSupplierId" class="form-control" required><option value="">选择供应商</option><?php foreach($suppliers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" id="retWarehouseId" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">退货日期</label><input type="date" name="return_date" id="retReturnDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><button type="button" class="btn btn-sm btn-outline" onclick="addRRow()"><i class="fa-solid fa-plus"></i> 添加</button></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:100px">数量</th><th style="width:100px">单价</th><th style="width:120px">金额</th><th style="width:50px"></th></tr></thead>
        <tbody id="returnItems"><tr id="retTpl">
            <td><select name="product_id[]" class="form-control psel" onchange="updateRp(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['purchase_price']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="0.01" name="quantity[]" class="form-control rqty" value="1" onchange="calcRRow(this)" required></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control rprice" value="0" onchange="calcRRow(this)" required></td>
            <td><input type="text" class="form-control ramt" value="0" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcRTotal()">×</button></td>
        </tr></tbody>
        <tfoot><tr><td colspan="3" class="text-right"><strong>合计：</strong></td><td><strong id="retTotal">¥0.00</strong></td><td></td></tr></tfoot>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" id="retRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('returnModal')">取消</button><button type="submit" class="btn btn-primary" id="retSubmitBtn">保存退货</button></div>
</form></div></div>

<?php if ($editData): ?>
<script>
// 编辑模式，自动打开弹窗并填充数据
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('retModalTitle').textContent = '编辑采购退货';
    document.getElementById('retAction').value = 'update';
    document.getElementById('retEditId').value = '<?=$editData['id']?>';
    document.getElementById('retSupplierId').value = '<?=$editData['supplier_id']?>';
    document.getElementById('retWarehouseId').value = '<?=$editData['warehouse_id']?>';
    document.getElementById('retReturnDate').value = '<?=$editData['return_date']?>';
    document.getElementById('retRemark').value = '<?=js_escape($editData['remark']??'')?>';
    document.getElementById('retSubmitBtn').textContent = '更新退货';
    // 清除模板行
    document.getElementById('retTpl').style.display = 'none';
    // 添加编辑项
    <?php foreach ($editItems as $ei): ?>
    var tpl = document.getElementById('retTpl');
    var row = tpl.cloneNode(true);
    row.removeAttribute('id');
    row.style.display = '';
    row.querySelector('select').value = '<?=$ei['product_id']?>';
    row.querySelector('.rqty').value = '<?=$ei['quantity']?>';
    row.querySelector('.rprice').value = '<?=$ei['price']?>';
    row.querySelector('.ramt').value = '<?=$ei['amount']?>';
    document.getElementById('returnItems').appendChild(row);
    <?php endforeach; ?>
    // 如果没有编辑项，显示模板行
    if (document.querySelectorAll('#returnItems tr:not([style*="display: none"])').length === 0) {
        document.getElementById('retTpl').style.display = '';
    }
    calcRTotal();
    openModal('returnModal');
});
</script>
<?php endif; ?>

<script>
function resetReturnForm(){
    document.getElementById('retModalTitle').textContent = '新增采购退货';
    document.getElementById('retAction').value = 'save';
    document.getElementById('retEditId').value = '0';
    document.getElementById('retSupplierId').selectedIndex = 0;
    document.getElementById('retWarehouseId').selectedIndex = 0;
    document.getElementById('retReturnDate').value = '<?=date('Y-m-d')?>';
    document.getElementById('retRemark').value = '';
    document.getElementById('retSubmitBtn').textContent = '保存退货';
    // 清除除了模板行外的所有行
    var items = document.getElementById('returnItems');
    var rows = items.querySelectorAll('tr:not(#retTpl)');
    rows.forEach(function(r){ r.remove(); });
    document.getElementById('retTpl').style.display = '';
    document.getElementById('retTpl').querySelector('select').selectedIndex = 0;
    document.getElementById('retTpl').querySelector('.rqty').value = 1;
    document.getElementById('retTpl').querySelector('.rprice').value = 0;
    document.getElementById('retTpl').querySelector('.ramt').value = 0;
    document.getElementById('retTotal').textContent = '¥0.00';
}
function addRRow(){var tpl=document.getElementById('retTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelector('.rqty').value=1;row.querySelector('.rprice').value=0;row.querySelector('.ramt').value=0;document.getElementById('returnItems').appendChild(row);}
function calcRRow(el){var row=el.closest('tr');var q=parseFloat(row.querySelector('.rqty').value)||0;var p=parseFloat(row.querySelector('.rprice').value)||0;row.querySelector('.ramt').value=(q*p).toFixed(2);calcRTotal();}
function calcRTotal(){var t=0;document.querySelectorAll('#returnItems .ramt').forEach(function(a){t+=parseFloat(a.value)||0;});document.getElementById('retTotal').textContent='¥'+t.toFixed(2);}
function updateRp(sel){var row=sel.closest('tr');var p=sel.options[sel.selectedIndex].getAttribute('data-price')||0;row.querySelector('.rprice').value=p;calcRRow(row.querySelector('.rprice'));}
function showPReturnWithdraw(id, billNo) {
    document.getElementById('prWithdrawId').value = id;
    document.getElementById('prWithdrawBillNo').textContent = billNo;
    document.getElementById('prWithdrawReason').value = '';
    openModal('prWithdrawModal');
}
</script>

<!-- 采购退货撤回原因弹窗 -->
<div class="modal-overlay" id="prWithdrawModal">
    <div class="modal modal-sm">
        <div class="modal-header"><h3 class="modal-title">撤回退货</h3><button class="modal-close" onclick="closeModal('prWithdrawModal')">&times;</button></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="id" id="prWithdrawId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">退货单号</label>
                    <span id="prWithdrawBillNo" style="font-weight:bold;"></span>
                </div>
                <div class="form-group">
                    <label class="form-label">撤回原因 <span class="required">*</span></label>
                    <textarea name="cancel_reason" id="prWithdrawReason" class="form-control" rows="3" required placeholder="请输入撤回原因"></textarea>
                </div>
                <p style="color:var(--warning);font-size:13px;">⚠️ 撤回后库存将恢复，单据状态变为草稿。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('prWithdrawModal')">取消</button>
                <button type="submit" class="btn btn-warning">确认撤回</button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
