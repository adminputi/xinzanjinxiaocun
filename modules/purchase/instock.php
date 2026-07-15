<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_instock');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$where = ''; $params = [];
if ($search) { $where = "WHERE pi.bill_no LIKE ? OR s.name LIKE ?"; $params[] = "%$search%"; $params[] = "%$search%"; }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_instocks pi LEFT JOIN suppliers s ON pi.supplier_id=s.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT pi.*, s.name as supplier_name, w.name as warehouse_name, eu.real_name as employee_name, u.real_name as op_name, (SELECT GROUP_CONCAT(CONCAT(p.name,' x',i.quantity) SEPARATOR '; ') FROM purchase_instock_items i JOIN products p ON i.product_id=p.id WHERE i.instock_id=pi.id LIMIT 3) as item_summary, (SELECT COALESCE(SUM(ii.quantity),0) FROM purchase_instock_items ii WHERE ii.instock_id=pi.id) as total_quantity FROM purchase_instocks pi LEFT JOIN suppliers s ON pi.supplier_id=s.id LEFT JOIN warehouses w ON pi.warehouse_id=w.id LEFT JOIN users eu ON pi.employee_id=eu.id LEFT JOIN users u ON pi.user_id=u.id $where ORDER BY pi.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$suppliers = get_options('suppliers','id','name','status=1');
$warehouses = get_options('warehouses','id','name','status=1');
$employees = get_options('users','id','real_name','status=1');
$products = $pdo->query("SELECT id,sku,name,spec,purchase_price,(SELECT name FROM units WHERE id=unit_id) as unit_name FROM products WHERE status=1")->fetchAll();

// 从采购订单跳转过来的预填数据
$fromOrder = null; $fromItems = [];
if (isset($_GET['from_order']) && intval($_GET['from_order']) > 0) {
    $oid = intval($_GET['from_order']);
    // 检查是否已经入库过
    $existingInstock = $pdo->prepare("SELECT id FROM purchase_instocks WHERE order_id=? LIMIT 1");
    $existingInstock->execute([$oid]);
    $existingInstock = $existingInstock->fetch();
    if ($existingInstock) {
        $error = '该订单已入库，单号：'.$existingInstock['id'].'，不允许重复入库';
    } else {
        $stmt = $pdo->prepare("SELECT o.*, s.name as supplier_name FROM purchase_orders o LEFT JOIN suppliers s ON o.supplier_id=s.id WHERE o.id=?");
        $stmt->execute([$oid]);
        $fromOrder = $stmt->fetch();
        if ($fromOrder) {
            $stmt = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.purchase_price FROM purchase_order_items i JOIN products p ON i.product_id=p.id WHERE i.order_id=?");
            $stmt->execute([$oid]);
            $fromItems = $stmt->fetchAll();
        }
    }
}

// 处理新建入库
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    $supplierId = intval($_POST['supplier_id']??0);
    $warehouseId = intval($_POST['warehouse_id']??0);
    $instockDate = $_POST['instock_date']??date('Y-m-d');
    $employeeId = intval($_POST['employee_id']??0);
    $remark = $_POST['remark']??'';
    $pids = $_POST['product_id']??[];
    $qtys = $_POST['quantity']??[];
    $prices = $_POST['price']??[];

    $pdo->beginTransaction();
    try {
        $totalAmount = 0; $itemData = [];
        foreach ($pids as $i => $pid) {
            $qty = floatval($qtys[$i]??0); $price = floatval($prices[$i]??0);
            if ($pid && $qty>0) { $amt = $qty*$price; $totalAmount += $amt; $itemData[] = ['pid'=>intval($pid),'qty'=>$qty,'price'=>$price,'amt'=>$amt]; }
        }
        if (empty($itemData)) { $error='至少添加一个商品'; $pdo->rollBack(); }
        else {
            if ($id > 0) {
                $billNo = $_POST['bill_no'];
                $pdo->prepare("UPDATE purchase_instocks SET supplier_id=?,warehouse_id=?,total_amount=?,instock_date=?,employee_id=?,remark=? WHERE id=?")->execute([$supplierId,$warehouseId,$totalAmount,$instockDate,$employeeId,$remark,$id]);
                $pdo->prepare("DELETE FROM purchase_instock_items WHERE instock_id=?")->execute([$id]);
            } else {
                $billNo = generate_bill_no('RK');
                $pdo->prepare("INSERT INTO purchase_instocks (bill_no,order_id,supplier_id,warehouse_id,total_amount,instock_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$billNo,intval($_POST['order_id']??0),$supplierId,$warehouseId,$totalAmount,$instockDate,$employeeId,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                $id = $pdo->lastInsertId();
            }
            $insStmt = $pdo->prepare("INSERT INTO purchase_instock_items (instock_id,product_id,quantity,price,amount) VALUES (?,?,?,?,?)");
            foreach ($itemData as $it) {
                $insStmt->execute([$id,$it['pid'],$it['qty'],$it['price'],$it['amt']]);
                if ($id > 0) update_inventory($it['pid'], $warehouseId, $it['qty'], 'in', $billNo, 'purchase_instock', get_user_id());
            }
            // 确认入库更新库存
            $pdo->prepare("UPDATE purchase_instocks SET status='confirmed' WHERE id=?")->execute([$id]);
            add_log(get_user_id(), 'create', 'purchase_instock', "采购入库: $billNo");
            $pdo->commit();
            redirect('instock.php');
        }
    } catch (Exception $e) { $pdo->rollBack(); error_log('Instock save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
}


// 撤销入库（反审核）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'undo') {
    csrf_verify();
    $iid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM purchase_instocks WHERE id=?");
    $stmt->execute([$iid]);
    $instock = $stmt->fetch();
    if ($instock && $instock['status']=='confirmed') {
        if (!check_permission('purchase_instock')) die('无权限');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_instock_items WHERE instock_id=?");
            $stmt->execute([$iid]);
            $items = $stmt->fetchAll();
            foreach ($items as $item) {
                update_inventory($item['product_id'], $instock['warehouse_id'], -$item['quantity'], 'out', $instock['bill_no'], 'purchase_instock_undo', get_user_id(), '撤销入库扣除库存');
            }
            $pdo->prepare("UPDATE purchase_instocks SET status='draft' WHERE id=?")->execute([$iid]);
            add_log(get_user_id(), 'undo', 'purchase_instock', "撤销入库: {$instock['bill_no']}，库存已扣回");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Instock undo error: '.$e->getMessage()); $error = '撤销失败，请稍后重试'; }
    }
    redirect('instock.php');
}


// 确认入库
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'confirm') {
    csrf_verify();
    $iid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM purchase_instocks WHERE id=?");
    $stmt->execute([$iid]);
    $instock = $stmt->fetch();
    if ($instock && $instock['status']=='draft') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_instock_items WHERE instock_id=?");
            $stmt->execute([$iid]);
            $items = $stmt->fetchAll();
            foreach ($items as $item) {
                update_inventory($item['product_id'], $instock['warehouse_id'], $item['quantity'], 'in', $instock['bill_no'], 'purchase_instock', get_user_id());
            }
            $pdo->prepare("UPDATE purchase_instocks SET status='confirmed' WHERE id=?")->execute([$iid]);
            add_log(get_user_id(), 'confirm', 'purchase_instock', "确认入库: {$instock['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Instock confirm error: '.$e->getMessage()); $error = '确认失败，请稍后重试'; }
    }
    redirect('instock.php');
}

?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-boxes-packing"></i> 采购入库</h1>
    <button class="btn btn-primary" onclick="openInstockModal()"><i class="fa-solid fa-plus"></i> 新增入库单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/供应商..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>供应商</th><th>仓库</th><th>商品信息</th><th>数量</th><th>操作人</th><th>日期</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['supplier_name']?:'-')?></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['item_summary']??'')?>"><?=htmlspecialchars($item['item_summary']?:'-')?></td>
    <td><strong><?=number_format($item['total_quantity']??0,0)?></strong></td>
    <td><?=htmlspecialchars($item['op_name']?:'-')?></td>
    <td><?=$item['instock_date']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':'warning'?>"><?=$item['status']=='confirmed'?'已入库':'草稿'?></span></td>
    <td>
        <div class="table-actions">
            <?php if($item['status']=='draft'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success">确认入库</button></form>
            <?php endif; ?>
            <a href="instock_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a>
            <?php if ($item['status']=='confirmed' && check_permission('purchase_instock')): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('⚠️ 确定撤销此入库单吗？\n\n撤销后库存将自动扣回，单据状态变为草稿。\n\n单号：<?=$item['bill_no']?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?=$item['id']?>">
                <button class="btn btn-sm btn-outline" title="撤销入库（扣除库存）" style="color:var(--warning)"><i class="fa-solid fa-rotate-left"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-boxes-packing"></i><p>暂无入库记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<!-- 新增入库弹窗 -->
<div class="modal-overlay" id="instockModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title">新增采购入库</h3><button class="modal-close" onclick="closeModal('instockModal')">&times;</button></div>
<form method="post" style="display:flex;flex-direction:column;flex:1;min-height:0;"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="order_id" id="iform_order_id" value="<?=$fromOrder['id']??0?>">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">供应商 <span class="required">*</span></label><select name="supplier_id" class="form-control" required><option value="">选择供应商</option><?php foreach($suppliers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">入库日期</label><input type="date" name="instock_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><?php if (!$fromOrder): ?><button type="button" class="btn btn-sm btn-outline" onclick="addInstockRow()"><i class="fa-solid fa-plus"></i> 添加</button><?php endif; ?></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:100px">数量</th><th style="width:100px">单价</th><th style="width:120px">金额</th><?php if (!$fromOrder): ?><th style="width:50px"></th><?php endif; ?></tr></thead>
        <tbody id="instockItems"><?php if (!$fromOrder): ?><tr id="instockTpl">
            <td><select name="product_id[]" class="form-control product-sel" onchange="updateIp(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['purchase_price']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="1" name="quantity[]" class="form-control iqty" value="1" onchange="calcIRow(this)" required></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control iprice" value="0" onchange="calcIRow(this)" required></td>
            <td><input type="text" class="form-control iamt" value="0" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcITotal()">×</button></td>
        </tr><?php endif; ?></tbody>
        <tfoot><tr><td colspan="3" class="text-right"><strong>合计：</strong></td><td><strong id="instockTotal">¥0.00</strong></td><?php if (!$fromOrder): ?><td></td><?php endif; ?></tr></tfoot>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('instockModal')">取消</button><button type="submit" class="btn btn-primary">保存入库</button></div>
</form></div></div>

<script>
var productOptionsHtml='<?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['purchase_price']?>"><?=js_escape($p['name']).' ['.js_escape($p['sku']).']'?></option><?php endforeach; ?>';

function buildInstockRow(pid,qty,price,amt,canDelete){
    var tr=document.createElement('tr');
    var html='<td><select name="product_id[]" class="form-control product-sel" onchange="updateIp(this)" required><option value="">选择商品</option>'+productOptionsHtml+'</select></td>';
    html+='<td><input type="number" step="1" name="quantity[]" class="form-control iqty" value="'+qty+'" onchange="calcIRow(this)" required></td>';
    html+='<td><input type="number" step="0.01" name="price[]" class="form-control iprice" value="'+price+'" onchange="calcIRow(this)" required></td>';
    html+='<td><input type="text" class="form-control iamt" value="'+amt+'" readonly></td>';
    if(canDelete) html+='<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();calcITotal()">×</button></td>';
    tr.innerHTML=html;
    if(pid){ var sel=tr.querySelector('.product-sel'); for(var i=0;i<sel.options.length;i++){ if(sel.options[i].value==pid){sel.selectedIndex=i;break;} } }
    document.getElementById('instockItems').appendChild(tr);
}

function openInstockModal(){
    document.querySelector('#instockModal select[name="supplier_id"]').selectedIndex=0;
    document.querySelector('#instockModal select[name="warehouse_id"]').selectedIndex=0;
    document.getElementById('iform_order_id').value='0';
    var items=document.getElementById('instockItems');
    items.innerHTML='';
    <?php if ($fromOrder): ?>
    document.getElementById('iform_order_id').value='<?=$fromOrder['id']?>';
    var supSel=document.querySelector('#instockModal select[name="supplier_id"]');
    for(var i=0;i<supSel.options.length;i++){
        if(supSel.options[i].value=='<?=$fromOrder['supplier_id']?>'){supSel.selectedIndex=i;break;}
    }
    var whSel=document.querySelector('#instockModal select[name="warehouse_id"]');
    for(var i=0;i<whSel.options.length;i++){
        if(whSel.options[i].value=='<?=$fromOrder['warehouse_id']?>'){whSel.selectedIndex=i;break;}
    }
    <?php foreach($fromItems as $fi): ?>
    buildInstockRow('<?=$fi['product_id']?>','<?=$fi['quantity']?>','<?=$fi['purchase_price']?>','<?=$fi['amount']?>',false);
    <?php endforeach; ?>
    <?php endif; ?>
    calcITotal();
    openModal('instockModal');
}
function addInstockRow(){if(!document.getElementById('instockTpl'))return;var tpl=document.getElementById('instockTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelector('.iqty').value=1;row.querySelector('.iprice').value=0;row.querySelector('.iamt').value=0;document.getElementById('instockItems').appendChild(row);}
function calcIRow(el){var row=el.closest('tr');var q=parseFloat(row.querySelector('.iqty').value)||0;var p=parseFloat(row.querySelector('.iprice').value)||0;row.querySelector('.iamt').value=(q*p).toFixed(2);calcITotal();}
function calcITotal(){var t=0;document.querySelectorAll('#instockItems .iamt').forEach(function(a){t+=parseFloat(a.value)||0;});document.getElementById('instockTotal').textContent='¥'+t.toFixed(2);}
function updateIp(sel){var row=sel.closest('tr');var p=sel.options[sel.selectedIndex].getAttribute('data-price')||0;row.querySelector('.iprice').value=p;calcIRow(row.querySelector('.iprice'));}
<?php if ($fromOrder): ?>document.addEventListener('DOMContentLoaded',function(){openInstockModal();});<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
