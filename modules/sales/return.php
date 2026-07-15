<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_return');
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
        $stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id=?");
        $stmt->execute([$getId]);
        $record = $stmt->fetch();
        if ($record && $record['status'] === 'draft') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE sales_returns SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $getId]);
                $stmt2 = $pdo->prepare("SELECT * FROM sales_return_items WHERE return_id=?");
                $stmt2->execute([$getId]);
                $items = $stmt2->fetchAll();
                foreach ($items as $it) {
                    update_inventory($it['product_id'], $record['warehouse_id'], $it['quantity'], 'in', $record['bill_no'], 'sales_return', get_user_id());
                }
                add_log(get_user_id(), 'confirm', 'sales_return', "确认退货: {$record['bill_no']}");
                $pdo->commit();
            } catch (Exception $e) { $pdo->rollBack(); error_log('Sales return confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
        }
    }
    if (!isset($error)) redirect('return.php');
}

// POST操作：撤回（需要输入原因）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    csrf_verify();
    $wId = intval($_POST['id'] ?? 0);
    $cancelReason = trim($_POST['cancel_reason'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id=?");
    $stmt->execute([$wId]);
    $record = $stmt->fetch();
    if ($record && $record['status'] === 'confirmed') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE sales_returns SET status='draft', cancel_reason=? WHERE id=?")->execute([$cancelReason, $wId]);
            $stmt2 = $pdo->prepare("SELECT * FROM sales_return_items WHERE return_id=?");
            $stmt2->execute([$wId]);
            $items = $stmt2->fetchAll();
            foreach ($items as $it) {
                update_inventory($it['product_id'], $record['warehouse_id'], -$it['quantity'], 'out', $record['bill_no'], 'sales_return_cancel', get_user_id(), '撤回退货');
            }
            add_log(get_user_id(), 'withdraw', 'sales_return', "撤回退货: {$record['bill_no']}，原因：{$cancelReason}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Sales return withdraw error: ' . $e->getMessage()); $error = '撤回失败，请查看系统日志'; }
    }
    if (!isset($error)) redirect('return.php');
}

$isAdminRet = (get_user_role() === 'admin');
$retWhere = ''; $retParams = [];
if (!$isAdminRet) {
    $retWhere = "WHERE sr.user_id = ?";
    $retParams[] = get_user_id();
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_returns sr $retWhere");
$stmt->execute($retParams);
$total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);
$stmt = $pdo->prepare("SELECT sr.*, c.name as customer_name, w.name as warehouse_name FROM sales_returns sr LEFT JOIN customers c ON sr.customer_id=c.id LEFT JOIN warehouses w ON sr.warehouse_id=w.id $retWhere ORDER BY sr.id DESC LIMIT $offset,$perPage");
$stmt->execute($retParams);
$list = $stmt->fetchAll();

$customers = get_options('customers','id','name','status=1');
$warehouses = get_options('warehouses','id','name','status=1');
$products = $pdo->query("SELECT id,sku,name,sale_price FROM products WHERE status=1")->fetchAll();

// POST保存/更新/撤回退货单
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save' || $action === 'update') {
        $editId = intval($_POST['id'] ?? 0);
        $customerId = intval($_POST['customer_id']??0);
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
                if ($pid && $qty>0) { $amt=$qty*$price; $totalAmount+=$amt; $itemData[]=['pid'=>intval($pid),'qty'=>$qty,'price'=>$price,'amt'=>$amt]; }
            }
            if (empty($itemData)) { $error='至少添加一个商品'; $pdo->rollBack(); }
            else {
                if ($action === 'update' && $editId > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id=?");
                    $stmt->execute([$editId]);
                    $old = $stmt->fetch();
                    if ($old && $old['status'] === 'draft') {
                        $pdo->prepare("UPDATE sales_returns SET customer_id=?,warehouse_id=?,total_amount=?,return_date=?,remark=?,created_at=? WHERE id=?")->execute([$customerId,$warehouseId,$totalAmount,$returnDate,$remark,date('Y-m-d H:i:s'),$editId]);
                        $pdo->prepare("DELETE FROM sales_return_items WHERE return_id=?")->execute([$editId]);
                        $insStmt = $pdo->prepare("INSERT INTO sales_return_items (return_id,product_id,quantity,price,amount) VALUES (?,?,?,?,?)");
                        foreach ($itemData as $it) {
                            $insStmt->execute([$editId,$it['pid'],$it['qty'],$it['price'],$it['amt']]);
                        }
                        add_log(get_user_id(), 'update', 'sales_return', "编辑退货: {$old['bill_no']}");
                    }
                } else {
                    $billNo = generate_bill_no('XT');
                    $pdo->prepare("INSERT INTO sales_returns (bill_no,customer_id,warehouse_id,total_amount,return_date,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$billNo,$customerId,$warehouseId,$totalAmount,$returnDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                    $retId = $pdo->lastInsertId();
                    $insStmt = $pdo->prepare("INSERT INTO sales_return_items (return_id,product_id,quantity,price,amount) VALUES (?,?,?,?,?)");
                    foreach ($itemData as $it) {
                        $insStmt->execute([$retId,$it['pid'],$it['qty'],$it['price'],$it['amt']]);
                    }
                    add_log(get_user_id(), 'create', 'sales_return', "销售退货: $billNo");
                }
                $pdo->commit();
                redirect('return.php');
            }
        } catch (Exception $e) { $pdo->rollBack(); error_log('Sales return save error: ' . $e->getMessage()); $error = '保存失败，请查看系统日志'; }
    }
}

// 编辑时加载数据
$editData = null;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT sr.* FROM sales_returns sr WHERE sr.id=? AND sr.status='draft'");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
    if ($editData) {
        $stmt2 = $pdo->prepare("SELECT * FROM sales_return_items WHERE return_id=?");
        $stmt2->execute([$editId]);
        $editItems = $stmt2->fetchAll();
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-arrow-rotate-left"></i> 销售退货</h1>
    <button class="btn btn-primary" onclick="openModal('retModal');resetSReturnForm()"><i class="fa-solid fa-plus"></i> 新增退货单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>仓库</th><th>金额</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['customer_name']?:'-')?></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><strong>¥<?=format_money($item['total_amount'])?></strong></td>
    <td><?=$item['created_at']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':($item['status']=='cancelled'?'gray':'warning')?>"><?=$item['status']=='confirmed'?'已确认':($item['status']=='cancelled'?'已取消':'草稿')?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['remark']??'')?>"><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,15))?></td>
    <td>
        <?php if ($item['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认退货后库存将增加，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success" title="确认退货"><i class="fa-solid fa-check"></i></button></form>
        <a href="?edit=<?=$item['id']?>" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($item['status'] === 'confirmed'): ?>
        <a href="javascript:void(0)" class="btn btn-sm btn-warning" onclick="showReturnWithdraw(<?=$item['id']?>,'<?=$item['bill_no']?>')" title="撤回"><i class="fa-solid fa-undo"></i></a>
        <?php endif; ?>
        <a href="return_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-arrow-rotate-left"></i><p>暂无退货记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="retModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="sretModalTitle">新增销售退货</h3><button class="modal-close" onclick="closeModal('retModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" id="sretAction" value="save"><input type="hidden" name="id" id="sretEditId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户 <span class="required">*</span></label><select name="customer_id" id="sretCustomerId" class="form-control" required><option value="">选择客户</option><?php foreach($customers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" id="sretWarehouseId" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">退货日期</label><input type="date" name="return_date" id="sretReturnDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><button type="button" class="btn btn-sm btn-outline" onclick="addSRow()"><i class="fa-solid fa-plus"></i> 添加</button></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:100px">数量</th><th style="width:100px">单价</th><th style="width:120px">金额</th><th style="width:50px"></th></tr></thead>
        <tbody id="sReturnItems"><tr id="sretTpl">
            <td><select name="product_id[]" class="form-control" onchange="updateSRp(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['sale_price']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="0.01" name="quantity[]" class="form-control srqty" value="1" onchange="calcSRow(this)" required></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control srprice" value="0" onchange="calcSRow(this)" required></td>
            <td><input type="text" class="form-control sramt" value="0" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcSRTotal()">×</button></td>
        </tr></tbody>
        <tfoot><tr><td colspan="3" class="text-right"><strong>合计：</strong></td><td><strong id="sretTotal">¥0.00</strong></td><td></td></tr></tfoot>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" id="sretRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('retModal')">取消</button><button type="submit" class="btn btn-primary" id="sretSubmitBtn">保存退货</button></div>
</form></div></div>

<?php if ($editData): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('sretModalTitle').textContent = '编辑销售退货';
    document.getElementById('sretAction').value = 'update';
    document.getElementById('sretEditId').value = '<?=$editData['id']?>';
    document.getElementById('sretCustomerId').value = '<?=$editData['customer_id']?>';
    document.getElementById('sretWarehouseId').value = '<?=$editData['warehouse_id']?>';
    document.getElementById('sretReturnDate').value = '<?=$editData['return_date']?>';
    document.getElementById('sretRemark').value = '<?=js_escape($editData['remark']??'')?>';
    document.getElementById('sretSubmitBtn').textContent = '更新退货';
    document.getElementById('sretTpl').style.display = 'none';
    <?php foreach ($editItems as $ei): ?>
    var tpl = document.getElementById('sretTpl');
    var row = tpl.cloneNode(true);
    row.removeAttribute('id');
    row.style.display = '';
    row.querySelector('select').value = '<?=$ei['product_id']?>';
    row.querySelector('.srqty').value = '<?=$ei['quantity']?>';
    row.querySelector('.srprice').value = '<?=$ei['price']?>';
    row.querySelector('.sramt').value = '<?=$ei['amount']?>';
    document.getElementById('sReturnItems').appendChild(row);
    <?php endforeach; ?>
    if (document.querySelectorAll('#sReturnItems tr:not([style*="display: none"])').length === 0) {
        document.getElementById('sretTpl').style.display = '';
    }
    calcSRTotal();
    openModal('retModal');
});
</script>
<?php endif; ?>

<script>
function resetSReturnForm(){
    document.getElementById('sretModalTitle').textContent = '新增销售退货';
    document.getElementById('sretAction').value = 'save';
    document.getElementById('sretEditId').value = '0';
    document.getElementById('sretCustomerId').selectedIndex = 0;
    document.getElementById('sretWarehouseId').selectedIndex = 0;
    document.getElementById('sretReturnDate').value = '<?=date('Y-m-d')?>';
    document.getElementById('sretRemark').value = '';
    document.getElementById('sretSubmitBtn').textContent = '保存退货';
    var items = document.getElementById('sReturnItems');
    var rows = items.querySelectorAll('tr:not(#sretTpl)');
    rows.forEach(function(r){ r.remove(); });
    document.getElementById('sretTpl').style.display = '';
    document.getElementById('sretTpl').querySelector('select').selectedIndex = 0;
    document.getElementById('sretTpl').querySelector('.srqty').value = 1;
    document.getElementById('sretTpl').querySelector('.srprice').value = 0;
    document.getElementById('sretTpl').querySelector('.sramt').value = 0;
    document.getElementById('sretTotal').textContent = '¥0.00';
}
function addSRow(){var tpl=document.getElementById('sretTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelector('.srqty').value=1;row.querySelector('.srprice').value=0;row.querySelector('.sramt').value=0;document.getElementById('sReturnItems').appendChild(row);}
function calcSRow(el){var row=el.closest('tr');var q=parseFloat(row.querySelector('.srqty').value)||0;var p=parseFloat(row.querySelector('.srprice').value)||0;row.querySelector('.sramt').value=(q*p).toFixed(2);calcSRTotal();}
function calcSRTotal(){var t=0;document.querySelectorAll('#sReturnItems .sramt').forEach(function(a){t+=parseFloat(a.value)||0;});document.getElementById('sretTotal').textContent='¥'+t.toFixed(2);}
function updateSRp(sel){var row=sel.closest('tr');var p=sel.options[sel.selectedIndex].getAttribute('data-price')||0;row.querySelector('.srprice').value=p;calcSRow(row.querySelector('.srprice'));}
function showReturnWithdraw(id, billNo) {
    document.getElementById('srWithdrawId').value = id;
    document.getElementById('srWithdrawBillNo').textContent = billNo;
    document.getElementById('srWithdrawReason').value = '';
    openModal('srWithdrawModal');
}
</script>

<!-- 销售退货撤回原因弹窗 -->
<div class="modal-overlay" id="srWithdrawModal">
    <div class="modal modal-sm">
        <div class="modal-header"><h3 class="modal-title">撤回退货</h3><button class="modal-close" onclick="closeModal('srWithdrawModal')">&times;</button></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="id" id="srWithdrawId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">退货单号</label>
                    <span id="srWithdrawBillNo" style="font-weight:bold;"></span>
                </div>
                <div class="form-group">
                    <label class="form-label">撤回原因 <span class="required">*</span></label>
                    <textarea name="cancel_reason" id="srWithdrawReason" class="form-control" rows="3" required placeholder="请输入撤回原因"></textarea>
                </div>
                <p style="color:var(--warning);font-size:13px;">⚠️ 撤回后库存将减少，单据状态变为草稿。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('srWithdrawModal')">取消</button>
                <button type="submit" class="btn btn-warning">确认撤回</button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
