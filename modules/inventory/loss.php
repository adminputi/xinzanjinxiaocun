<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('loss_manage');
$pdo = getDB();
run_migrations();

// ===== AJAX：取商品当前库存与默认成本单价，供明细行自动带出 =====
// 成本优先取该仓库的移动加权平均成本，没有则回退采购价（与实时库存页口径一致）
if (($_GET['ajax'] ?? '') === 'stock') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $pid = intval($_GET['product_id'] ?? 0);
    $wid = intval($_GET['warehouse_id'] ?? 0);
    $stock = 0; $cost = 0;
    if ($pid > 0 && $wid > 0) {
        $st = $pdo->prepare("SELECT COALESCE(quantity,0) as qty, COALESCE(avg_cost,0) as avg_cost FROM inventory WHERE product_id=? AND warehouse_id=?");
        $st->execute([$pid, $wid]);
        if ($row = $st->fetch()) { $stock = floatval($row['qty']); $cost = floatval($row['avg_cost']); }
        if ($cost <= 0) {
            $st2 = $pdo->prepare("SELECT COALESCE(purchase_price,0) FROM products WHERE id=?");
            $st2->execute([$pid]);
            $cost = floatval($st2->fetchColumn());
        }
    }
    echo json_encode(['ok' => true, 'stock' => $stock, 'cost' => $cost], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== POST：确认 / 撤回（逻辑抽到 includes/functions.php，两个入口共用）=====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['confirm', 'withdraw'], true)) {
    csrf_verify();
    $getAction = $_POST['action'];
    $getId = intval($_POST['id'] ?? 0);
    if ($getId > 0) {
        $res = $getAction === 'confirm'
            ? confirm_loss_order($pdo, $getId)
            : withdraw_loss_order($pdo, $getId);
        // 这里紧接着就 redirect，$error 会被丢掉，失败原因必须走 flash
        if (!$res['ok']) flash_set($res['msg'], 'danger');
        else flash_set($res['msg'], 'success');
    }
    redirect('loss.php');
}

// ===== POST：保存 / 更新明细 =====
// 每行两种填法：
//   diff   增减量（有符号，正=加、负=减）
//   target 调整为 N 个（确认时才按当时库存算差值，这里只存目标值和预估值用于显示）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['save', 'update'], true)) {
    csrf_verify();
    $action     = $_POST['action'];
    $editLId    = intval($_POST['id'] ?? 0);
    $warehouseId= intval($_POST['warehouse_id'] ?? 0);
    $orderDate  = $_POST['order_date'] ?? date('Y-m-d');
    $remark     = trim($_POST['remark'] ?? '');
    $pids       = $_POST['product_id'] ?? [];
    $modes      = $_POST['mode'] ?? [];
    $qtys       = $_POST['quantity'] ?? [];
    $prices     = $_POST['price'] ?? [];
    $reasons    = $_POST['reason'] ?? [];

    if ($warehouseId <= 0) {
        $error = '请选择仓库';
    } else {
        $pdo->beginTransaction();
        try {
            $itemData = [];
            $stockStmt = $pdo->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE product_id=? AND warehouse_id=?");
            foreach ($pids as $i => $pidRaw) {
                $pid = intval($pidRaw);
                if ($pid <= 0) continue;
                $mode  = (($modes[$i] ?? 'diff') === 'target') ? 'target' : 'diff';
                $val   = floatval($qtys[$i] ?? 0);
                $price = floatval($prices[$i] ?? 0);
                if ($mode === 'target') {
                    if ($val < 0) { throw new Exception('调整后的数量不能为负数'); }
                    $stockStmt->execute([$pid, $warehouseId]);
                    $cur = floatval($stockStmt->fetchColumn());
                    $targetQty = $val;
                    $qty = $val - $cur; // 预估值，仅用于列表显示；确认时按当时库存重算
                } else {
                    if (abs($val) < 0.000001) continue; // 增减量为 0 的行没有意义
                    $targetQty = null;
                    $qty = $val;
                }
                $itemData[] = [
                    'pid' => $pid, 'qty' => $qty, 'price' => $price,
                    'amount' => abs($qty) * $price,
                    'target' => $targetQty,
                    'reason' => trim($reasons[$i] ?? ''),
                ];
            }
            if (empty($itemData)) {
                throw new Exception('请至少添加一个有效的调整行');
            }

            // 单据类型按明细符号自动判断，不再手工选：一行可以加、另一行可以减
            $hasPlus = false; $hasMinus = false;
            foreach ($itemData as $it) {
                if ($it['qty'] > 0.000001) $hasPlus = true;
                if ($it['qty'] < -0.000001) $hasMinus = true;
            }
            $type   = ($hasPlus && $hasMinus) ? 'mixed' : ($hasPlus ? 'overflow' : 'loss');
            $prefix = $type === 'loss' ? 'BS' : ($type === 'overflow' ? 'BY' : 'TZ');

            if ($action === 'update' && $editLId > 0) {
                $st = $pdo->prepare("SELECT * FROM loss_orders WHERE id=?");
                $st->execute([$editLId]);
                $old = $st->fetch();
                if (!$old || $old['status'] !== 'draft') {
                    throw new Exception('该单据不存在或已确认，不能编辑');
                }
                $pdo->prepare("UPDATE loss_orders SET warehouse_id=?,type=?,order_date=?,remark=?,created_at=? WHERE id=?")
                    ->execute([$warehouseId, $type, $orderDate, $remark, date('Y-m-d H:i:s'), $editLId]);
                $pdo->prepare("DELETE FROM loss_items WHERE loss_id=?")->execute([$editLId]);
                $insStmt = $pdo->prepare("INSERT INTO loss_items (loss_id,product_id,quantity,price,amount,target_qty,reason) VALUES (?,?,?,?,?,?,?)");
                foreach ($itemData as $it) {
                    $insStmt->execute([$editLId, $it['pid'], $it['qty'], $it['price'], $it['amount'], $it['target'], $it['reason']]);
                }
                add_log(get_user_id(), 'update', 'loss_order', "编辑库存调整: {$old['bill_no']}");
                $pdo->commit();
                flash_set('库存调整单已更新', 'success');
                redirect('loss.php');
            }

            $billNo = generate_bill_no($prefix);
            $pdo->prepare("INSERT INTO loss_orders (bill_no,warehouse_id,type,order_date,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$billNo, $warehouseId, $type, $orderDate, $remark, get_user_id(), date('Y-m-d H:i:s')]);
            $lossId = $pdo->lastInsertId();
            $insStmt = $pdo->prepare("INSERT INTO loss_items (loss_id,product_id,quantity,price,amount,target_qty,reason) VALUES (?,?,?,?,?,?,?)");
            foreach ($itemData as $it) {
                $insStmt->execute([$lossId, $it['pid'], $it['qty'], $it['price'], $it['amount'], $it['target'], $it['reason']]);
            }
            add_log(get_user_id(), 'create', 'loss_order', "新增库存调整: $billNo");
            $pdo->commit();
            flash_set("库存调整单 {$billNo} 已保存，确认后生效", 'success');
            redirect('loss.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Loss save error: ' . $e->getMessage());
            $error = '保存失败：' . $e->getMessage();
        }
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page - 1) * $perPage;
$total = $pdo->query("SELECT COUNT(*) FROM loss_orders")->fetchColumn();
$pages = ceil($total / $perPage);
// has_plus：单据里是否有「加库存」的行。有的话不允许撤回（成本无法精确还原）
$list = $pdo->query("SELECT l.*, w.name as warehouse_name,
        EXISTS(SELECT 1 FROM loss_items li WHERE li.loss_id=l.id AND COALESCE(li.actual_diff, li.quantity) > 0) as has_plus
    FROM loss_orders l LEFT JOIN warehouses w ON l.warehouse_id=w.id
    ORDER BY l.id DESC LIMIT $offset,$perPage")->fetchAll();

$warehouses = get_options('warehouses', 'id', 'name', 'status=1');
$products = $pdo->query("SELECT id,sku,name,purchase_price FROM products WHERE status=1")->fetchAll();

// 编辑时加载数据
$editData = null; $editItems = [];
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

// 从实时库存页「调整」按钮跳过来：?add=1&product_id=X&warehouse_id=Y
$autoProductId   = intval($_GET['product_id'] ?? 0);
$autoWarehouseId = intval($_GET['warehouse_id'] ?? 0);
$autoOpen = (isset($_GET['add']) && $autoProductId > 0) ? 1 : 0;

$reasonOptions = ['盘点差异', '损耗', '被盗', '录入错误', '客户退回', '其他'];
$typeLabels = ['loss' => '报损（减）', 'overflow' => '报溢（加）', 'mixed' => '混合（有加有减）'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-sliders"></i> 库存调整</h1>
    <button class="btn btn-primary" onclick="openModal('lossModal');resetLossForm()"><i class="fa-solid fa-plus"></i> 新增库存调整单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php flash_show(); ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>仓库</th><th>类型</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><span class="badge badge-<?=$item['type']=='loss'?'danger':($item['type']=='overflow'?'success':'info')?>"><?=$typeLabels[$item['type']] ?? $item['type']?></span></td>
    <td><?=$item['order_date']?:$item['created_at']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':($item['status']=='cancelled'?'gray':'warning')?>"><?=$item['status']=='confirmed'?'已确认':($item['status']=='cancelled'?'已取消':'草稿')?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['remark']??'')?>"><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,15))?></td>
    <td>
        <?php if ($item['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认后库存将变更，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success" title="确认"><i class="fa-solid fa-check"></i></button></form>
        <a href="?edit=<?=$item['id']?>" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($item['status'] === 'confirmed'): ?>
        <?php if (intval($item['has_plus']) === 1): ?>
        <span class="btn btn-sm btn-outline" style="opacity:.5;cursor:not-allowed;" title="含盘盈（加库存），撤回后成本无法精确还原，请另开一张反向调整单"><i class="fa-solid fa-undo"></i></span>
        <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-warning" title="撤回"><i class="fa-solid fa-undo"></i></button></form>
        <?php endif; ?>
        <?php endif; ?>
        <a href="loss_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-sliders"></i><p>暂无库存调整记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="lossModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="lossModalTitle">新增库存调整单</h3><button class="modal-close" onclick="closeModal('lossModal')">&times;</button></div>
<form method="post" id="lossForm"><?= csrf_field() ?><input type="hidden" name="action" id="lossAction" value="save"><input type="hidden" name="id" id="lossEditId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" id="lossWarehouseId" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">日期</label><input type="date" name="order_date" id="lossOrderDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>
    <div class="alert alert-info" style="margin:8px 0;">每行可单独选择「增减」或「调整为」：增减填有符号数量（如 -3 表示减少 3 个），调整为填最终应有的数量（如填 5，系统按当时库存自动算差值）。<strong>盘盈请填成本单价</strong>，否则库存价值会算不准。</div>
    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">调整明细</label><button type="button" class="btn btn-sm btn-outline" onclick="addLRow()"><i class="fa-solid fa-plus"></i> 添加</button></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:90px">当前库存</th><th style="width:110px">方式</th><th style="width:100px">数量</th><th style="width:90px">调整后</th><th style="width:100px">成本单价</th><th style="width:150px">原因</th><th style="width:50px"></th></tr></thead>
        <tbody id="lossItems"><tr id="lossTpl">
            <td><select name="product_id[]" class="form-control lp" onchange="onLpChange(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['purchase_price']?>"><?=htmlspecialchars($p['name'].' ['.$p['sku'].']')?></option><?php endforeach; ?></select></td>
            <td><input type="text" class="form-control lstock" value="-" readonly style="text-align:center;"></td>
            <td><select name="mode[]" class="form-control lmode" onchange="calcLRow(this)"><option value="diff">增减</option><option value="target">调整为</option></select></td>
            <td><input type="number" step="0.01" name="quantity[]" class="form-control lqty" value="0" onchange="calcLRow(this)" oninput="calcLRow(this)" required></td>
            <td><input type="text" class="form-control lafter" value="-" readonly style="text-align:center;"></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control lprice" value="0" required></td>
            <td><select name="reason[]" class="form-control lreason"><option value="">选择原因</option><?php foreach($reasonOptions as $ro): ?><option value="<?=$ro?>"><?=$ro?></option><?php endforeach; ?></select></td>
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
    document.getElementById('lossModalTitle').textContent = '编辑库存调整单';
    document.getElementById('lossAction').value = 'update';
    document.getElementById('lossEditId').value = '<?=$editData['id']?>';
    document.getElementById('lossWarehouseId').value = '<?=$editData['warehouse_id']?>';
    document.getElementById('lossOrderDate').value = '<?=$editData['order_date']?>';
    document.getElementById('lossRemark').value = '<?=js_escape($editData['remark']??'')?>';
    document.getElementById('lossSubmitBtn').textContent = '更新';
    document.getElementById('lossTpl').style.display = 'none';
    <?php foreach ($editItems as $ei): ?>
    (function(){
        var tpl = document.getElementById('lossTpl');
        var row = tpl.cloneNode(true);
        row.removeAttribute('id');
        row.style.display = '';
        row.querySelector('.lp').value = '<?=$ei['product_id']?>';
        row.querySelector('.lmode').value = '<?= $ei['target_qty'] !== null ? 'target' : 'diff' ?>';
        row.querySelector('.lqty').value = '<?= $ei['target_qty'] !== null ? floatval($ei['target_qty']) : floatval($ei['quantity']) ?>';
        row.querySelector('.lprice').value = '<?=floatval($ei['price'])?>';
        var rs = row.querySelector('.lreason');
        var rv = '<?=js_escape($ei['reason']??'')?>';
        var matched = false;
        for (var i = 0; i < rs.options.length; i++) { if (rs.options[i].value === rv) { rs.selectedIndex = i; matched = true; break; } }
        if (!matched && rv !== '') { var op = document.createElement('option'); op.value = rv; op.textContent = rv; rs.appendChild(op); rs.value = rv; }
        document.getElementById('lossItems').appendChild(row);
        refreshLRow(row);
    })();
    <?php endforeach; ?>
    openModal('lossModal');
});
</script>
<?php endif; ?>

<?php if ($autoOpen): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    resetLossForm();
    document.getElementById('lossWarehouseId').value = '<?=$autoWarehouseId?>';
    var row = addLRow();
    if (row) { row.querySelector('.lp').value = '<?=$autoProductId?>'; refreshLRow(row); }
    openModal('lossModal');
});
</script>
<?php endif; ?>

<script>
function lWarehouseId(){ var el = document.getElementById('lossWarehouseId'); return el ? el.value : ''; }

// 取某行的当前库存 + 默认成本（平均成本，取不到用采购价）
function refreshLRow(row){
    if (!row) return;
    var sel = row.querySelector('.lp');
    var pid = sel ? sel.value : '';
    var wid = lWarehouseId();
    var stockEl = row.querySelector('.lstock');
    if (!pid || !wid) { stockEl.value = '-'; calcLRow(row); return; }
    fetch('loss.php?ajax=stock&product_id=' + encodeURIComponent(pid) + '&warehouse_id=' + encodeURIComponent(wid), {
        headers: { 'X-Requested-With': 'fetch' }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && d.ok) {
            stockEl.value = d.stock;
            var priceEl = row.querySelector('.lprice');
            // 只在单价还是空/0 时自动带，用户手改过就不覆盖
            if (!parseFloat(priceEl.value)) priceEl.value = d.cost;
        }
        calcLRow(row);
    })
    .catch(function(){ calcLRow(row); });
}
function onLpChange(sel){
    var row = sel.closest('tr');
    var priceEl = row.querySelector('.lprice');
    // 换商品时单价跟着重置为该商品默认成本，避免沿用上一个商品的价格
    var p = sel.options[sel.selectedIndex].getAttribute('data-price') || 0;
    priceEl.value = p;
    refreshLRow(row);
}
function calcLRow(el){
    var row = el.closest ? el.closest('tr') : el;
    if (!row) return;
    var stock = parseFloat(row.querySelector('.lstock').value);
    if (isNaN(stock)) stock = 0;
    var qty = parseFloat(row.querySelector('.lqty').value);
    if (isNaN(qty)) qty = 0;
    var mode = row.querySelector('.lmode').value;
    var after = (mode === 'target') ? qty : (stock + qty);
    row.querySelector('.lafter').value = isNaN(after) ? '-' : Math.round(after * 100) / 100;
}
function refreshAllLRows(){
    document.querySelectorAll('#lossItems tr').forEach(function(r){
        if (r.id === 'lossTpl') return;
        refreshLRow(r);
    });
}
function addLRow(){
    var tpl = document.getElementById('lossTpl');
    var row = tpl.cloneNode(true);
    row.removeAttribute('id');
    row.style.display = '';
    row.querySelector('.lp').selectedIndex = 0;
    row.querySelector('.lmode').value = 'diff';
    row.querySelector('.lqty').value = 0;
    row.querySelector('.lprice').value = 0;
    row.querySelector('.lstock').value = '-';
    row.querySelector('.lafter').value = '-';
    if (row.querySelector('.lreason')) row.querySelector('.lreason').selectedIndex = 0;
    document.getElementById('lossItems').appendChild(row);
    return row;
}
function resetLossForm(){
    document.getElementById('lossModalTitle').textContent = '新增库存调整单';
    document.getElementById('lossAction').value = 'save';
    document.getElementById('lossEditId').value = '0';
    document.getElementById('lossWarehouseId').selectedIndex = 0;
    document.getElementById('lossOrderDate').value = '<?=date('Y-m-d')?>';
    document.getElementById('lossRemark').value = '';
    document.getElementById('lossSubmitBtn').textContent = '保存';
    var items = document.getElementById('lossItems');
    items.querySelectorAll('tr:not(#lossTpl)').forEach(function(r){ r.remove(); });
    var tpl = document.getElementById('lossTpl');
    tpl.style.display = '';
    tpl.querySelector('.lp').selectedIndex = 0;
    tpl.querySelector('.lmode').value = 'diff';
    tpl.querySelector('.lqty').value = 0;
    tpl.querySelector('.lprice').value = 0;
    tpl.querySelector('.lstock').value = '-';
    tpl.querySelector('.lafter').value = '-';
    if (tpl.querySelector('.lreason')) tpl.querySelector('.lreason').selectedIndex = 0;
}
// 换仓库要整表重取：库存是按仓库算的
document.getElementById('lossWarehouseId').addEventListener('change', refreshAllLRows);
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
