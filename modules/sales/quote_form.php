<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_quote');
$pdo = getDB();
run_migrations();

$id = intval($_GET['id'] ?? 0);
$quote = null;
$items = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$id]);
    $quote = $stmt->fetch();
    if (!$quote) die('报价单不存在');
    // 非admin用户只能编辑自己的记录
    if ($_SESSION['user_role'] !== 'admin' && ($quote['user_id'] ?? 0) != get_user_id()) die('无权编辑此记录');
    // 非draft/withdrawn状态不允许编辑
    if (!in_array($quote['status'], ['draft', 'withdrawn'])) {
        die('该报价单已转订单，无法编辑。<a href="quote.php">返回列表</a>');
    }
    $stmt = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.unit_id, u.name as unit_name FROM sales_quote_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.quote_id=?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$customers = get_options('customers', 'id', 'name', 'status=1');
$employees = get_options('users', 'id', 'real_name', 'status=1');
$products = $pdo->query("SELECT id, sku, name, spec, sale_price, (SELECT name FROM units WHERE id=unit_id) as unit_name FROM products WHERE status=1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $qid = intval($_POST['id'] ?? 0);
    $customerId = intval($_POST['customer_id']??0);
    $quoteDate = $_POST['quote_date'] ?? date('Y-m-d');
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
            if ($qid > 0) {
                $billNo = $quote['bill_no'];
                $pdo->prepare("UPDATE sales_quotes SET customer_id=?,total_amount=?,quote_date=?,employee_id=?,remark=? WHERE id=?")
                    ->execute([$customerId,$totalAmount,$quoteDate,$employeeId,$remark,$qid]);
                $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$qid]);
                add_log(get_user_id(), 'update', 'sales_quote', "编辑销售报价单: $billNo");
            } else {
                $billNo = generate_bill_no('BJ');
                $pdo->prepare("INSERT INTO sales_quotes (bill_no,customer_id,total_amount,quote_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$billNo,$customerId,$totalAmount,$quoteDate,$employeeId,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                $qid = $pdo->lastInsertId();
                add_log(get_user_id(), 'create', 'sales_quote', "新建销售报价单: $billNo");
            }

            $insStmt = $pdo->prepare("INSERT INTO sales_quote_items (quote_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
            foreach ($itemsData as $it) { $insStmt->execute([$qid,$it['pid'],$it['qty'],$it['price'],$it['amount'],$it['remark']]); }

            $pdo->commit();
            redirect('quote.php');
        }
    } catch (Exception $e) { $pdo->rollBack(); error_log('Quote save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
}

// 产品数据转为JSON供JS使用
$productsJson = [];
foreach ($products as $p) {
    $productsJson[] = [
        'id' => $p['id'],
        'sku' => $p['sku'],
        'name' => $p['name'],
        'spec' => $p['spec'],
        'sale_price' => floatval($p['sale_price']),
        'unit_name' => $p['unit_name'],
    ];
}
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-<?= $id>0?'pen-to-square':'plus' ?>"></i> <?= $id>0?'编辑':'新增' ?>销售报价</h1>
    <a href="quote.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card">
    <form method="post" id="quoteForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">客户 <span class="required">*</span></label>
                    <input type="text" id="customerSearch" class="form-control" placeholder="输入客户名称搜索..." autocomplete="off" onfocus="showCustomerDropdown()" oninput="filterCustomers()" onkeydown="handleCustomerKey(event)" value="<?= $quote && $quote['customer_id'] ? htmlspecialchars($customers[$quote['customer_id']]??'') : '' ?>">
                    <input type="hidden" name="customer_id" id="customerId" value="<?= $quote['customer_id']??'' ?>" required>
                    <div class="search-dropdown" id="customerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:220px;overflow-y:auto;background:#fff;border:1px solid var(--gray-300);border-radius:6px;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">业务员</label>
                    <select name="employee_id" class="form-control">
                        <option value="0">选择业务员</option>
                        <?php foreach($employees as $eid=>$ename): ?><option value="<?=$eid?>" <?=$quote&&$quote['employee_id']==$eid?'selected':''?>><?=$ename?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">报价日期</label>
                    <input type="date" name="quote_date" class="form-control" value="<?= $quote['quote_date']??date('Y-m-d') ?>">
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
                                <td><input type="hidden" name="product_id[]" value=""><span class="product-display"></span></td>
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
                                <td><strong id="totalAmount">¥<?= $quote ? format_money($quote['total_amount']) : '0.00' ?></strong></td>
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
                <textarea name="remark" class="form-control" rows="2" placeholder="整单备注信息"><?= htmlspecialchars($quote['remark']??'') ?></textarea>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:16px 20px;">
            <a href="quote.php" class="btn btn-outline">取消</a>
            <button type="submit" class="btn btn-primary">保存报价单</button>
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
                    <thead><tr><th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选/取消"></th><th>SKU</th><th>商品名称</th><th>规格型号</th><th>单位</th><th style="width:80px;">售价</th></tr></thead>
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
.qty-stepper { display:flex; align-items:center; gap:0; border:1px solid var(--gray-300); border-radius:6px; overflow:hidden; width:100%; }
.qty-stepper .stepper-btn { width:32px; height:32px; border:none; background:var(--gray-50); color:var(--gray-700); font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.15s; flex-shrink:0; }
.qty-stepper .stepper-btn:hover { background:var(--gray-200); }
.qty-stepper .qty-input { border:none !important; border-radius:0 !important; width:100%; min-width:40px; height:32px; padding:0 4px; font-size:13px; -moz-appearance:textfield; }
.qty-stepper .qty-input::-webkit-inner-spin-button, .qty-stepper .qty-input::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
.search-dropdown-item:hover { background:var(--primary-light); }
.search-dropdown-item.active { background:var(--primary-light); }
</style>

<script>
var allCustomers = <?= json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function filterCustomers() {
    var q = (document.getElementById('customerSearch').value||'').toLowerCase();
    var dropdown = document.getElementById('customerDropdown');
    var html = '';
    var count = 0;
    for (var id in allCustomers) {
        if (!allCustomers.hasOwnProperty(id)) continue;
        var name = allCustomers[id];
        if (q && name.toLowerCase().indexOf(q) === -1) continue;
        count++;
        html += '<div class="search-dropdown-item" data-id="'+id+'" data-name="'+escapeHtml(name)+'" onclick="selectCustomer(this)" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-100);transition:background 0.15s;">'+escapeHtml(name)+'</div>';
    }
    if (count === 0) html = '<div style="padding:12px;text-align:center;color:var(--gray-400);font-size:13px;">未找到匹配的客户</div>';
    dropdown.innerHTML = html;
    dropdown.style.display = 'block';
}

function selectCustomer(el) {
    document.getElementById('customerSearch').value = el.getAttribute('data-name');
    document.getElementById('customerId').value = el.getAttribute('data-id');
    document.getElementById('customerDropdown').style.display = 'none';
}

function showCustomerDropdown() {
    if (!document.getElementById('customerDropdown').innerHTML) filterCustomers();
    document.getElementById('customerDropdown').style.display = 'block';
}

function handleCustomerKey(e) {
    var dropdown = document.getElementById('customerDropdown');
    if (!dropdown || dropdown.style.display === 'none') {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') showCustomerDropdown();
        return;
    }
    var items = dropdown.querySelectorAll('.search-dropdown-item');
    if (items.length === 0) return;
    var current = dropdown.querySelector('.search-dropdown-item.active');
    var idx = -1;
    if (current) { for (var i = 0; i < items.length; i++) { if (items[i] === current) { idx = i; break; } } }
    if (e.key === 'ArrowDown') {
        e.preventDefault(); idx = (idx + 1) % items.length;
        items.forEach(function(it){ it.classList.remove('active'); it.style.background=''; });
        items[idx].classList.add('active'); items[idx].style.background = 'var(--primary-light)'; items[idx].scrollIntoView({block:'nearest'});
    } else if (e.key === 'ArrowUp') {
        e.preventDefault(); idx = idx <= 0 ? items.length - 1 : idx - 1;
        items.forEach(function(it){ it.classList.remove('active'); it.style.background=''; });
        items[idx].classList.add('active'); items[idx].style.background = 'var(--primary-light)'; items[idx].scrollIntoView({block:'nearest'});
    } else if (e.key === 'Enter') { e.preventDefault(); if (current) current.click(); }
    else if (e.key === 'Escape') { dropdown.style.display = 'none'; }
}

document.addEventListener('click', function(e) {
    var input = document.getElementById('customerSearch');
    var dropdown = document.getElementById('customerDropdown');
    if (input && dropdown && !input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display = 'none';
});

var allProducts = <?= json_encode($productsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function filterProducts() {
    var q = document.getElementById('productSearch').value.toLowerCase();
    var tbody = document.getElementById('productList');
    var noResult = document.getElementById('noProduct');
    document.getElementById('selectAll').checked = false;
    var rows = '';
    allProducts.forEach(function(p) {
        var text = (p.sku + ' ' + p.name + ' ' + (p.spec||'') + ' ' + (p.unit_name||'')).toLowerCase();
        if (q && text.indexOf(q) === -1) return;
        var spec = p.spec || '-';
        rows += '<tr data-id="'+p.id+'" data-price="'+p.sale_price+'" data-name="'+escapeHtml(p.name)+'" data-sku="'+escapeHtml(p.sku)+'" data-spec="'+escapeHtml(spec)+'" data-unit="'+(p.unit_name||'')+'" onclick="toggleProductRow(this)">'
            + '<td><input type="checkbox" class="product-check" onclick="event.stopPropagation();syncRowCheck(this);"></td>'
            + '<td>'+escapeHtml(p.sku)+'</td><td><strong>'+escapeHtml(p.name)+'</strong></td>'
            + '<td>'+escapeHtml(spec)+'</td><td>'+(p.unit_name||'-')+'</td><td>¥'+p.sale_price.toFixed(2)+'</td></tr>';
    });
    tbody.innerHTML = rows || '';
    noResult.style.display = rows ? 'none' : '';
}

function escapeHtml(str) { var div = document.createElement('div'); div.textContent = str; return div.innerHTML; }
function toggleProductRow(tr) { var cb = tr.querySelector('.product-check'); cb.checked = !cb.checked; tr.classList.toggle('selected', cb.checked); updateSelectAll(); }
function syncRowCheck(cb) { cb.closest('tr').classList.toggle('selected', cb.checked); updateSelectAll(); }
function toggleSelectAll(cb) { document.querySelectorAll('#productList .product-check').forEach(function(c){c.checked=cb.checked;}); document.querySelectorAll('#productList tr').forEach(function(r){r.classList.toggle('selected',cb.checked);}); }
function updateSelectAll() { var checks = document.querySelectorAll('#productList .product-check'); document.getElementById('selectAll').checked = checks.length > 0 && Array.from(checks).every(function(c){return c.checked;}); }
function openProductModal() { openModal('productModal'); filterProducts(); document.getElementById('productSearch').value = ''; setTimeout(function(){document.getElementById('productSearch').focus();},150); }

function addSelectedProducts() {
    var checked = document.querySelectorAll('#productList .product-check:checked');
    if (checked.length === 0) { alert('请至少选择一个商品'); return; }
    var tbody = document.getElementById('itemsBody');
    var emptyHint = document.getElementById('emptyHint');
    if (emptyHint) emptyHint.style.display = 'none';
    var existingIds = {};
    tbody.querySelectorAll('input[name="product_id[]"]').forEach(function(inp){ if(inp.value)existingIds[inp.value]=true; });
    checked.forEach(function(cb){
        var tr = cb.closest('tr'), pid = tr.getAttribute('data-id');
        if (existingIds[pid]) return;
        existingIds[pid] = true;
        var name = tr.getAttribute('data-name'), sku = tr.getAttribute('data-sku'), spec = tr.getAttribute('data-spec'), price = tr.getAttribute('data-price');
        var rowHtml = '<tr class="editable-row">'
            + '<td><input type="hidden" name="product_id[]" value="'+pid+'"><span class="product-display">'+name+' <small style="color:var(--gray-500)">['+sku+'] '+spec+'</small></span></td>'
            + '<td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="1" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>'
            + '<td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="'+price+'" onchange="calcRow(this)" required></td>'
            + '<td><input type="text" class="form-control amount-display" value="'+price+'" readonly></td>'
            + '<td><input type="text" name="item_remark[]" class="form-control" placeholder="行备注"></td>'
            + '<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();calcTotal();">×</button></td></tr>';
        tbody.insertAdjacentHTML('beforeend', rowHtml);
    });
    closeModal('productModal'); calcTotal();
}
function calcRow(el) { var row=el.closest('tr'), qty=parseFloat(row.querySelector('.qty-input').value)||0, price=parseFloat(row.querySelector('.price-input').value)||0; row.querySelector('.amount-display').value=(qty*price).toFixed(2); calcTotal(); }
function qtyDown(btn){var inp=btn.parentElement.querySelector('.qty-input'),v=parseInt(inp.value)||1;if(v>1){inp.value=v-1;calcRow(inp);}}
function qtyUp(btn){var inp=btn.parentElement.querySelector('.qty-input'),v=parseInt(inp.value)||0;inp.value=v+1;calcRow(inp);}
function calcTotal(){var total=0;document.querySelectorAll('.amount-display').forEach(function(a){total+=parseFloat(a.value)||0;});document.getElementById('totalAmount').textContent='¥'+total.toFixed(2);}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
