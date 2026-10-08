<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_order');
$pdo = getDB();

$id = intval($_GET['id'] ?? 0);
$order = null;
$items = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
    $stmt->execute([$id]);
    if (!$stmt) die('数据库查询失败，请检查 purchase_orders 表是否存在');
    $order = $stmt->fetch();
    if (!$order) die('订单不存在');
    // 非admin用户只能编辑自己的记录
    if ($_SESSION['user_role'] !== 'admin' && ($order['user_id'] ?? 0) != get_user_id()) die('无权编辑此记录');
    $stmt = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.unit_id, u.name as unit_name FROM purchase_order_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.order_id=?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$suppliers = get_options('suppliers', 'id', 'name', 'status=1');
$warehouses = get_options('warehouses', 'id', 'name', 'status=1');
$employees = get_options('users', 'id', 'real_name', 'status=1');
// 产品列表转为JSON供前端弹窗搜索使用
$products = $pdo->query("SELECT id, sku, name, spec, image, purchase_price, (SELECT name FROM units WHERE id=unit_id) as unit_name FROM products WHERE status=1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save_order';
    $oid = intval($_POST['id'] ?? 0);
    $supplierId = intval($_POST['supplier_id']??0);
    $warehouseId = intval($_POST['warehouse_id']??0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDate = $_POST['expected_date'] ?? '';
    $employeeId = intval($_POST['employee_id']??0);
    $remark = $_POST['remark'] ?? '';
    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $itemRemarks = $_POST['item_remark'] ?? [];

    $pdo->beginTransaction();
    try {
        $totalAmount = 0;
        $itemsData = [];
        foreach ($pids as $i => $pid) {
            $qty = floatval($qtys[$i]??0);
            $price = floatval($prices[$i]??0);
            if ($pid && $qty > 0) {
                $amount = $qty * $price;
                $totalAmount += $amount;
                $itemsData[] = [
                    'pid' => intval($pid),
                    'qty' => $qty,
                    'price' => $price,
                    'amount' => $amount,
                    'remark' => $itemRemarks[$i] ?? '',
                ];
            }
        }
        if (empty($itemsData)) { $error = '请至少添加一个商品'; $pdo->rollBack(); }
        else {
            if ($oid > 0) {
                $billNo = $order['bill_no'];
                $pdo->prepare("UPDATE purchase_orders SET supplier_id=?,warehouse_id=?,total_amount=?,order_date=?,expected_date=?,employee_id=?,remark=? WHERE id=?")
                    ->execute([$supplierId,$warehouseId,$totalAmount,$orderDate,$expectedDate?:null,$employeeId,$remark,$oid]);
                $pdo->prepare("DELETE FROM purchase_order_items WHERE order_id=?")->execute([$oid]);
                add_log(get_user_id(), 'update', 'purchase_order', "编辑采购订单: $billNo");
            } else {
                $billNo = generate_bill_no('CG');
                $pdo->prepare("INSERT INTO purchase_orders (bill_no,supplier_id,warehouse_id,total_amount,order_date,expected_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$billNo,$supplierId,$warehouseId,$totalAmount,$orderDate,$expectedDate?:null,$employeeId,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                $oid = $pdo->lastInsertId();
                add_log(get_user_id(), 'create', 'purchase_order', "新建采购订单: $billNo");
            }

            $insStmt = $pdo->prepare("INSERT INTO purchase_order_items (order_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
            foreach ($itemsData as $it) { $insStmt->execute([$oid,$it['pid'],$it['qty'],$it['price'],$it['amount'],$it['remark']]); }

            $pdo->commit();
            redirect('order.php');
        }
    } catch (Exception $e) { $pdo->rollBack(); error_log('Purchase order save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
}
?>
<?php
// 产品数据转为JSON供JS使用
$productsJson = [];
foreach ($products as $p) {
    $productsJson[] = [
        'id' => $p['id'],
        'sku' => $p['sku'],
        'name' => $p['name'],
        'spec' => $p['spec'],
        // 图片路径在库里是 uploads/products/... ，本页位于 /modules/purchase/ 下，需补 ../../ 前缀
        'image_url' => $p['image'] ? '../../' . $p['image'] : '',
        'purchase_price' => floatval($p['purchase_price']),
        'unit_name' => $p['unit_name'],
    ];
}
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-<?= $id>0?'pen-to-square':'plus' ?>"></i> <?= $id>0?'编辑':'新增' ?>采购订单</h1>
    <a href="order.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card">
    <form method="post" id="orderForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_order">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">供应商 <span class="required">*</span></label>
                    <select name="supplier_id" class="form-control searchable" data-ajax="/api/search_partners.php?type=supplier" required>
                        <option value="">请选择供应商</option>
                        <?php foreach($suppliers as $sid=>$sname): ?><option value="<?=$sid?>" <?=$order&&$order['supplier_id']==$sid?'selected':''?>><?=$sname?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">仓库</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="0">请选择仓库</option>
                        <?php foreach($warehouses as $wid=>$wname): ?><option value="<?=$wid?>" <?=$order&&$order['warehouse_id']==$wid?'selected':''?>><?=$wname?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">采购员</label>
                    <select name="employee_id" class="form-control">
                        <option value="0">选择采购员</option>
                        <?php foreach($employees as $eid=>$ename): ?><option value="<?=$eid?>" <?=$order&&$order['employee_id']==$eid?'selected':''?>><?=$ename?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">订单日期</label>
                    <input type="date" name="order_date" class="form-control" value="<?= $order['order_date']??date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">预计到货</label>
                    <input type="date" name="expected_date" class="form-control" value="<?= $order['expected_date']??'' ?>">
                </div>
            </div>

            <!-- 商品明细 -->
            <div class="mt-3">
                <div class="flex-between mb-2">
                    <label class="form-label" style="margin:0;">商品明细</label>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openProductModal()"><i class="fa-solid fa-plus"></i> 添加商品</button>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>商品名称</th><th style="width:130px">数量</th><th style="width:110px">单价(¥)</th><th style="width:120px">金额(¥)</th><th style="width:140px">备注</th><th style="width:60px">操作</th></tr></thead>
                        <tbody id="itemsBody">
                            <?php if ($items): foreach ($items as $item): ?>
                            <tr class="editable-row">
                                <td>
                                    <input type="hidden" name="product_id[]" value="<?=$item['product_id']?>">
                                    <span class="product-display"><?=htmlspecialchars($item['product_name'])?> <small style="color:var(--gray-500)">[<?=$item['sku']?>] <?=$item['spec']?></small></span>
                                </td>
                                <td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="<?=$item['quantity']?>" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>
                                <td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="<?=$item['price']?>" onchange="calcRow(this)" required></td>
                                <td><input type="text" class="form-control amount-display" value="<?=$item['amount']?>" readonly></td>
                                <td><input type="text" name="item_remark[]" class="form-control" value="<?=htmlspecialchars($item['remark']??'')?>" placeholder="行备注"></td>
                                <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcTotal();">×</button></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr id="emptyRow" class="editable-row" style="display:none;">
                                <td>
                                    <input type="hidden" name="product_id[]" value="">
                                    <span class="product-display"></span>
                                </td>
                                <td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="1" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>
                                <td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="0" onchange="calcRow(this)" required></td>
                                <td><input type="text" class="form-control amount-display" value="0" readonly></td>
                                <td><input type="text" name="item_remark[]" class="form-control" placeholder="行备注"></td>
                                <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcTotal();">×</button></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right"><strong>合计：</strong></td>
                                <td><strong id="totalAmount">¥<?= $order ? format_money($order['total_amount']) : '0.00' ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if (!$items): ?>
                <div class="empty-state" id="emptyHint" style="padding:24px;"><i class="fa-solid fa-cart-plus"></i><p>点击"添加商品"选择商品</p></div>
                <?php endif; ?>
            </div>

            <div class="form-group mt-2">
                <label class="form-label">总备注</label>
                <textarea name="remark" class="form-control" rows="2" placeholder="整单备注信息"><?= htmlspecialchars($order['remark']??'') ?></textarea>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:16px 20px;">
            <a href="order.php" class="btn btn-outline">取消</a>
            <button type="submit" class="btn btn-primary">保存订单</button>
        </div>
    </form>
</div>

<!-- ===== 商品选择弹窗 ===== -->
<div class="modal-overlay" id="productModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-search"></i> 选择商品</h3>
            <button class="modal-close" onclick="closeModal('productModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="mb-2">
                <input type="text" id="productSearch" class="form-control" placeholder="搜索商品名称 / SKU / 规格..." oninput="filterProducts()">
            </div>
            <div style="max-height:420px;overflow-y:auto;">
                <table class="table-select" style="width:100%;">
                    <thead><tr><th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选/取消"></th><th style="width:52px;">图片</th><th>SKU</th><th>商品名称</th><th>规格型号</th><th>单位</th><th style="width:80px;">采购价</th><th style="width:100px;">数量</th></tr></thead>
                    <tbody id="productList"></tbody>
                </table>
                <div id="noProduct" style="display:none;text-align:center;padding:24px;color:var(--gray-500);">没有匹配的商品</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('productModal')">取消</button>
            <button class="btn btn-primary" onclick="addSelectedProducts()"><i class="fa-solid fa-check"></i> 确认添加</button>
        </div>
    </div>
</div>

<style>
.table-select { border-collapse:collapse; }
.table-select th, .table-select td { padding:8px 10px; border-bottom:1px solid var(--gray-200); font-size:13px; text-align:left; }
.table-select th { background:var(--gray-50); position:sticky; top:0; z-index:1; }
.table-select tbody tr { cursor:pointer; transition:background 0.15s; }
.table-select tbody tr:hover { background:var(--primary-light); }
.table-select tbody tr.selected { background:#e0e7ff; }
.product-display { display:block; line-height:1.4; }
.product-display small { font-size:12px; }
/* 数量步进器 */
.qty-stepper { display:flex; align-items:center; gap:0; border:1px solid var(--gray-300); border-radius:6px; overflow:hidden; width:100%; }
.qty-stepper .stepper-btn { width:32px; height:32px; border:none; background:var(--gray-50); color:var(--gray-700); font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.15s; flex-shrink:0; }
.qty-stepper .stepper-btn:hover { background:var(--gray-200); }
.qty-stepper .qty-input { border:none !important; border-radius:0 !important; width:100%; min-width:40px; height:32px; padding:0 4px; font-size:13px; -moz-appearance:textfield; }
.qty-stepper .qty-input::-webkit-inner-spin-button,
.qty-stepper .qty-input::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
</style>

<script>
// 产品数据
var allProducts = <?= json_encode($productsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// 已勾选商品的数量：{商品ID: 数量}
// filterProducts() 每次输入关键词都会重建整个 tbody，若不在这里留存，
// 用户换一个搜索词再切回来，之前勾选的行和填好的数量就被清空了。
var pickedQty = {};

// 勾选状态变化时同步数量表；取消勾选即丢弃该行数量
function syncPick(tr, checked) {
    var pid = tr.getAttribute('data-id');
    if (!checked) { delete pickedQty[pid]; return; }
    var inp = tr.querySelector('.sel-qty');
    var v = inp ? parseFloat(inp.value) : 1;
    pickedQty[pid] = (isFinite(v) && v > 0) ? v : 1;
}

// 输入数量时自动勾选该行。
// 否则用户填了数量却没打勾，点「确认」时该行会被 addSelectedProducts 静默跳过，且毫无提示。
// 不清空/非法的输入不会被在这里强行改写 value（避免用户想输 5 时先清成 1 导致变成 15），只取消勾选。
function pickQty(input, pid) {
    var tr = input.closest('tr');
    var cb = tr.querySelector('.product-check');
    var v = parseFloat(input.value);
    if (!isFinite(v) || v <= 0) {
        delete pickedQty[pid];
        cb.checked = false;
        tr.classList.remove('selected');
    } else {
        pickedQty[pid] = v;
        cb.checked = true;
        tr.classList.add('selected');
    }
    updateSelectAll();
}

function filterProducts() {
    var q = document.getElementById('productSearch').value.toLowerCase();
    var tbody = document.getElementById('productList');
    var noResult = document.getElementById('noProduct');
    var selectAll = document.getElementById('selectAll');
    selectAll.checked = false;
    var visible = 0;
    var rows = '';
    allProducts.forEach(function(p, idx) {
        var text = (p.sku + ' ' + p.name + ' ' + (p.spec||'') + ' ' + (p.unit_name||'')).toLowerCase();
        if (q && text.indexOf(q) === -1) return;
        visible++;
        var spec = p.spec || '-';
        var picked = pickedQty.hasOwnProperty(p.id);
        var qty = picked ? pickedQty[p.id] : 1;
        rows += '<tr'+(picked?' class="selected"':'')+' data-id="'+p.id+'" data-price="'+p.purchase_price+'" data-name="'+escapeHtml(p.name)+'" data-sku="'+escapeHtml(p.sku)+'" data-spec="'+escapeHtml(spec)+'" data-unit="'+(p.unit_name||'')+'" onclick="toggleProductRow(this)">'
            + '<td><input type="checkbox" class="product-check"'+(picked?' checked':'')+' onclick="event.stopPropagation();syncRowCheck(this);"></td>'
            + '<td>'+productThumbHtml(p.image_url, 40)+'</td>'
            + '<td>'+escapeHtml(p.sku)+'</td>'
            + '<td><strong>'+escapeHtml(p.name)+'</strong></td>'
            + '<td>'+escapeHtml(spec)+'</td>'
            + '<td>'+(p.unit_name||'-')+'</td>'
            + '<td>¥'+p.purchase_price.toFixed(2)+'</td>'
            + '<td><input type="number" class="form-control sel-qty" value="'+qty+'" min="1" step="1" style="width:80px;text-align:center;"'
            + ' onclick="event.stopPropagation()" oninput="pickQty(this,'+p.id+')" title="输入本次数量，自动勾选"></td>'
            + '</tr>';
    });
    tbody.innerHTML = rows;
    noResult.style.display = visible === 0 ? '' : 'none';
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// 商品缩略图：无图时返回灰色占位方块，避免该列塌缩导致每次搜索都抖动。
// 返回的 HTML 已自行转义，不要再对它二次 escapeHtml。
// 点击图片调用 previewImage() 放大 —— 该函数由 assets/js/print-image.js 提供，
// includes/footer.php 已全局引入，本页 :387 处 require 了 footer.php，可直接用。
function productThumbHtml(url, size) {
    size = size || 40;
    if (!url) {
        return '<span style="display:inline-block;width:'+size+'px;height:'+size+'px;background:var(--gray-100);border-radius:4px;text-align:center;line-height:'+size+'px;color:var(--gray-400);font-size:16px;"><i class="fa-solid fa-box"></i></span>';
    }
    // src 属性：escapeHtml() 不转义双引号，这里必须自己转，否则带引号的路径会截断属性
    var src = String(url).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    // onclick 参数：JSON.stringify 生成合法的 JS 字符串字面量，再把双引号转 &quot; 以便安全放进 HTML 属性
    var arg = JSON.stringify(String(url)).replace(/"/g,'&quot;');
    return '<img src="'+src+'" loading="lazy" decoding="async" style="width:'+size+'px;height:'+size+'px;object-fit:cover;border-radius:4px;cursor:pointer;"'
        + ' onclick="previewImage('+arg+')" onerror="this.onerror=null;this.style.visibility=\'hidden\';" title="点击放大" alt="">';
}

function toggleProductRow(tr) {
    var cb = tr.querySelector('.product-check');
    cb.checked = !cb.checked;
    tr.classList.toggle('selected', cb.checked);
    syncPick(tr, cb.checked);
    updateSelectAll();
}

function syncRowCheck(cb) {
    var tr = cb.closest('tr');
    tr.classList.toggle('selected', cb.checked);
    syncPick(tr, cb.checked);
    updateSelectAll();
}

function toggleSelectAll(cb) {
    var checks = document.querySelectorAll('#productList .product-check');
    checks.forEach(function(c) { c.checked = cb.checked; });
    document.querySelectorAll('#productList tr').forEach(function(r) {
        r.classList.toggle('selected', cb.checked);
        syncPick(r, cb.checked);
    });
    updateSelectAll();
}

function updateSelectAll() {
    var checks = document.querySelectorAll('#productList .product-check');
    var allChecked = checks.length > 0 && Array.from(checks).every(function(c) { return c.checked; });
    document.getElementById('selectAll').checked = allChecked;
}

// 每次打开弹窗都清空已选数量：本次选择是新一轮操作，不应残留上一次的数据
function openProductModal() {
    pickedQty = {};
    openModal('productModal');
    filterProducts(); // 初始渲染
    document.getElementById('productSearch').value = '';
    setTimeout(function() { document.getElementById('productSearch').focus(); }, 150);
}

function addSelectedProducts() {
    var checked = document.querySelectorAll('#productList .product-check:checked');
    if (checked.length === 0) { alert('请至少选择一个商品'); return; }

    var tbody = document.getElementById('itemsBody');
    var emptyHint = document.getElementById('emptyHint');
    if (emptyHint) emptyHint.style.display = 'none';

    var existingIds = {};
    tbody.querySelectorAll('input[name="product_id[]"]').forEach(function(inp) {
        if (inp.value) existingIds[inp.value] = true;
    });

    var addedCount = 0;
    checked.forEach(function(cb) {
        var tr = cb.closest('tr');
        var pid = tr.getAttribute('data-id');
        if (existingIds[pid]) return; // 跳过已存在的商品
        existingIds[pid] = true;

        var name = tr.getAttribute('data-name');
        var sku = tr.getAttribute('data-sku');
        var spec = tr.getAttribute('data-spec');
        var price = tr.getAttribute('data-price');
        var unit = tr.getAttribute('data-unit');

        // 取弹窗里填的数量（默认 1）；非法值兜底为 1
        var qtyInp = tr.querySelector('.sel-qty');
        var qty = qtyInp ? (parseFloat(qtyInp.value) || 1) : 1;
        if (qty <= 0) qty = 1;
        // 小计不能再用原来的 value="'+price+'"（那是写死的「单价×1」）。
        // calcTotal() 是累加 .amount-display 的值，不填对这里，行小计和总合计都会显示成单价×1。
        // 后端保存时会用 数量×单价 重新计算，不受影响，但用户保存前看到的金额必须是正确的。
        var amount = (parseFloat(price) * qty).toFixed(2);

        var rowHtml = '<tr class="editable-row">'
            + '<td><input type="hidden" name="product_id[]" value="'+pid+'"><span class="product-display">'+name+' <small style="color:var(--gray-500)">['+sku+'] '+spec+'</small></span></td>'
            + '<td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="'+qty+'" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>'
            + '<td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="'+price+'" onchange="calcRow(this)" required></td>'
            + '<td><input type="text" class="form-control amount-display" value="'+amount+'" readonly></td>'
            + '<td><input type="text" name="item_remark[]" class="form-control" placeholder="行备注"></td>'
            + '<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();calcTotal();">×</button></td>'
            + '</tr>';
        tbody.insertAdjacentHTML('beforeend', rowHtml);
        addedCount++;
    });

    closeModal('productModal');
    calcTotal();
}

function calcRow(el) {
    var row = el.closest('tr');
    var qty = parseFloat(row.querySelector('.qty-input').value)||0;
    var price = parseFloat(row.querySelector('.price-input').value)||0;
    row.querySelector('.amount-display').value = (qty*price).toFixed(2);
    calcTotal();
}
function qtyDown(btn) {
    var inp = btn.parentElement.querySelector('.qty-input');
    var v = parseInt(inp.value)||1;
    if (v > 1) { inp.value = v - 1; calcRow(inp); }
}
function qtyUp(btn) {
    var inp = btn.parentElement.querySelector('.qty-input');
    var v = parseInt(inp.value)||0;
    inp.value = v + 1;
    calcRow(inp);
}
function calcTotal() {
    var total = 0;
    document.querySelectorAll('.amount-display').forEach(function(a){ total+=parseFloat(a.value)||0; });
    document.getElementById('totalAmount').textContent = '¥'+total.toFixed(2);
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
