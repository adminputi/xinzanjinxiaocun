<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

$isAjax = (($_POST['_ajax'] ?? '') === '1' || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_quote');
$pdo = getDB();
run_migrations();

$id = intval($_GET['id'] ?? 0);
// 复制模式：?id=X&copy=1 —— 载入 X 的内容作为副本，保存时走「新增」分支生成全新单号
$isCopy = ($id > 0 && ($_GET['copy'] ?? '') === '1');
// 实际提交给后端的单据ID：复制时为 0，表示新建一张单据，原单据不被修改
$saveId = $isCopy ? 0 : $id;
$quote = null;
$items = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$id]);
    $quote = $stmt->fetch();
    if (!$quote) die('报价单不存在');
    // 非admin用户只能查看/操作自己的记录（复制同样受限）
    if ($_SESSION['user_role'] !== 'admin' && ($quote['user_id'] ?? 0) != get_user_id()) die('无权操作此记录');
    // 非draft/withdrawn状态不允许编辑；但允许复制（复制不改原单据，只生成新单）
    if (!in_array($quote['status'], ['draft', 'withdrawn']) && !$isCopy) {
        die('该报价单已转订单，无法编辑。<a href="quote.php">返回列表</a>');
    }
    // 用 LEFT JOIN：即使明细里的商品已被删除，复制出来的行也不会凭空消失
    $stmt = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.unit_id, u.name as unit_name FROM sales_quote_items i LEFT JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.quote_id=? ORDER BY i.id");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';

// 客户下拉要显示「名称 + 电话」（同名客户靠电话区分），所以电话单独取一份。
// $customers 仍保持 id=>name 结构，表单回填等既有代码不用跟着改
$customers = [];
$customerPhones = [];
if ($isAdmin) {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers WHERE status=1 ORDER BY id");
} else {
    $stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE status=1 AND owner_id=? ORDER BY id");
    $stmt->execute([get_user_id()]);
}
foreach ($stmt->fetchAll() as $c) {
    $customers[$c['id']] = $c['name'];
    $customerPhones[$c['id']] = $c['phone'] ?? '';
}

$employees = get_options('users', 'id', 'real_name', 'status=1');

// 原单（编辑/复制）的客户若已停用或不在可选范围内，补进来保证名称能正常显示在搜索框里，
// 避免界面上客户框空白、但隐藏字段仍带着旧的 customer_id 这种隐性错误
if ($quote && ($quote['customer_id'] ?? 0) && !isset($customers[$quote['customer_id']])) {
    $stmt = $pdo->prepare("SELECT name, phone FROM customers WHERE id=?");
    $stmt->execute([intval($quote['customer_id'])]);
    $oldCustomer = $stmt->fetch();
    if ($oldCustomer) {
        $customers[$quote['customer_id']] = $oldCustomer['name'];
        $customerPhones[$quote['customer_id']] = $oldCustomer['phone'] ?? '';
    }
}

// 业务员：非管理员只能开自己名下的单。前端把下拉锁死（禁用后不提交，另配隐藏域），
// 保存时 quote_form_save() 里再强制覆盖一次，防止改 POST 挂到别人名下
$currentUid = get_user_id();
$employeeLocked = !$isAdmin;
if ($currentUid && !isset($employees[$currentUid])) {
    // 当前账号若已被停用会不在列表里，补进来，否则锁定后下拉显示不出名字
    $stmt = $pdo->prepare("SELECT real_name FROM users WHERE id=?");
    $stmt->execute([$currentUid]);
    $currentName = $stmt->fetchColumn();
    if ($currentName) $employees[$currentUid] = $currentName;
}
$employeeValue = $quote ? intval($quote['employee_id'] ?? 0) : $currentUid;
if ($employeeLocked) $employeeValue = $currentUid;
// image 字段用于「添加商品」弹窗里显示商品缩略图（products.image 已存在于建表语句，无需迁移）
$products = $pdo->query("SELECT id, sku, name, spec, image, sale_price, (SELECT name FROM units WHERE id=unit_id) as unit_name FROM products WHERE status=1 ORDER BY id")->fetchAll();

/**
 * 报价单保存
 * @return array ['ok'=>bool,'msg'=>string,'redirect'=>string]
 */
function quote_form_save($pdo, $quote) {
    csrf_verify();
    $qid = intval($_POST['id'] ?? 0);
    $customerId = intval($_POST['customer_id'] ?? 0);
    $quoteDate = $_POST['quote_date'] ?? date('Y-m-d');
    $employeeId = intval($_POST['employee_id'] ?? 0);
    // 非管理员强制记在自己名下：前端只是禁用下拉，这里兜底防止改 POST 把单子挂给别人
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        $employeeId = get_user_id();
    }
    $remark = $_POST['remark'] ?? '';
    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $itemRemarks = $_POST['item_remark'] ?? [];

    if ($customerId <= 0) {
        return ['ok' => false, 'msg' => '请选择客户'];
    }

    // 先算明细与总金额（不涉及数据库）
    $totalAmount = 0;
    $itemsData = [];
    foreach ($pids as $i => $pid) {
        $qty = floatval($qtys[$i] ?? 0);
        $price = floatval($prices[$i] ?? 0);
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
    if (empty($itemsData)) {
        return ['ok' => false, 'msg' => '请至少添加一个商品'];
    }

    $pdo->beginTransaction();
    try {
        if ($qid > 0) {
            $billNo = $quote['bill_no'] ?? '';
            $pdo->prepare("UPDATE sales_quotes SET customer_id=?,total_amount=?,quote_date=?,employee_id=?,remark=? WHERE id=?")
                ->execute([$customerId, $totalAmount, $quoteDate, $employeeId, $remark, $qid]);
            $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$qid]);
            add_log(get_user_id(), 'update', 'sales_quote', "编辑销售报价单: $billNo");
            $msg = '报价单已保存';
        } else {
            $billNo = generate_bill_no('BJ');
            $pdo->prepare("INSERT INTO sales_quotes (bill_no,customer_id,total_amount,quote_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$billNo, $customerId, $totalAmount, $quoteDate, $employeeId, $remark, get_user_id(), date('Y-m-d H:i:s')]);
            $qid = $pdo->lastInsertId();
            $copyFrom = trim($_POST['copy_from'] ?? '');
            add_log(get_user_id(), 'create', 'sales_quote', $copyFrom ? "复制销售报价单: {$billNo}（来源 {$copyFrom}）" : "新建销售报价单: {$billNo}");
            $msg = $copyFrom ? '报价单已复制创建' : '报价单已创建';
        }

        $insStmt = $pdo->prepare("INSERT INTO sales_quote_items (quote_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
        foreach ($itemsData as $it) {
            $insStmt->execute([$qid, $it['pid'], $it['qty'], $it['price'], $it['amount'], $it['remark']]);
        }

        $pdo->commit();
        return ['ok' => true, 'msg' => $msg, 'redirect' => 'quote.php'];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Quote save error: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '保存失败：' . $e->getMessage()];
    }
}

// ===== POST 处理：AJAX 返回 JSON（前端提示后回列表），普通提交走 PRG =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = null;
    try {
        $postResult = quote_form_save($pdo, $quote);
    } catch (Exception $e) {
        error_log('Quote save error: ' . $e->getMessage());
        $postResult = ['ok' => false, 'msg' => '保存失败：' . $e->getMessage()];
    }

    if ($isAjax) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($postResult, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$postResult['ok']) {
        $error = $postResult['msg'];
    } else {
        flash_set($postResult['msg'], 'success');
        if (!empty($postResult['redirect'])) {
            redirect($postResult['redirect']);
        }
    }
}

if (!$isAjax) {
    require_once __DIR__ . '/../../includes/header.php';
}

// 产品数据转为JSON供JS使用
$productsJson = [];
foreach ($products as $p) {
    $productsJson[] = [
        'id' => $p['id'],
        'sku' => $p['sku'],
        'name' => $p['name'],
        'spec' => $p['spec'],
        // 图片路径在库里是 uploads/products/... ，本页位于 /modules/sales/ 下，需补 ../../ 前缀
        'image_url' => $p['image'] ? '../../' . $p['image'] : '',
        'sale_price' => floatval($p['sale_price']),
        'unit_name' => $p['unit_name'],
    ];
}
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-<?= $saveId>0?'pen-to-square':($isCopy?'clone':'plus') ?>"></i> <?= $saveId>0?'编辑':($isCopy?'复制':'新增') ?>销售报价</h1>
    <a href="quote.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
</div>
<?php if ($isCopy): ?><div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> 正在复制报价单 <strong><?= htmlspecialchars($quote['bill_no']) ?></strong>。修改客户、业务员、报价日期等信息后保存，将生成一份<strong>全新单号</strong>的报价单，原单据不受影响。</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (function_exists('flash_show')) { flash_show(); } ?>

<div class="card">
    <form method="post" id="quoteForm" action="quote_form.php<?= $saveId > 0 ? '?id='.$saveId : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $saveId ?>">
        <?php if ($isCopy): ?><input type="hidden" name="copy_from" value="<?= htmlspecialchars($quote['bill_no']) ?>"><?php endif; ?>
        <div id="quoteFormMsg"></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">客户 <span class="required">*</span></label>
                    <input type="text" id="customerSearch" class="form-control" placeholder="输入客户名称或电话搜索..." autocomplete="off" onfocus="showCustomerDropdown()" oninput="filterCustomers()" onkeydown="handleCustomerKey(event)" value="<?= $quote && $quote['customer_id'] ? htmlspecialchars($customers[$quote['customer_id']]??'') : '' ?>">
                    <input type="hidden" name="customer_id" id="customerId" value="<?= $quote['customer_id']??'' ?>" required>
                    <div class="search-dropdown" id="customerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:260px;overflow-y:auto;background:#fff;border:1px solid var(--gray-300);border-radius:6px;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">业务员<?php if ($employeeLocked): ?> <span style="font-weight:400;color:var(--gray-500);font-size:12px;">（默认本人，不可修改）</span><?php endif; ?></label>
                    <?php if ($employeeLocked): ?>
                    <!-- 禁用的 select 不会随表单提交，所以真正提交的是这个隐藏域 -->
                    <input type="hidden" name="employee_id" value="<?= intval($employeeValue) ?>">
                    <select class="form-control" disabled>
                        <option selected><?= htmlspecialchars($employees[$employeeValue] ?? '未知用户') ?></option>
                    </select>
                    <?php else: ?>
                    <select name="employee_id" class="form-control">
                        <option value="0">选择业务员</option>
                        <?php foreach($employees as $eid=>$ename): ?><option value="<?=$eid?>" <?=$employeeValue==$eid?'selected':''?>><?=htmlspecialchars($ename)?></option><?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">报价日期</label>
                    <input type="date" name="quote_date" class="form-control" value="<?= $isCopy ? date('Y-m-d') : ($quote['quote_date']??date('Y-m-d')) ?>">
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
                        <thead><tr><th style="width:78px">序号</th><th>商品名称</th><th style="width:130px">数量</th><th style="width:110px">单价(¥)</th><th style="width:120px">金额(¥)</th><th style="width:140px">备注</th><th style="width:130px">操作</th></tr></thead>
                        <tbody id="itemsBody">
                            <?php if ($items): foreach ($items as $idx => $item): ?>
                            <tr class="editable-row">
                                <td><input type="number" class="form-control sort-input" value="<?=$idx+1?>" min="1" onchange="sortByNumber(this)" style="text-align:center;" title="输入序号可直接调整排列"></td>
                                <td>
                                    <input type="hidden" name="product_id[]" value="<?=$item['product_id']?>">
                                    <?php
                                    // LEFT JOIN 后商品可能已被删除，这里做名称兜底，保证数量/单价仍完整保留
                                    $itemName = $item['product_name'] !== null && $item['product_name'] !== '' ? $item['product_name'] : ('商品已删除(ID:' . intval($item['product_id']) . ')');
                                    $itemMeta = trim(($item['sku'] ? '[' . $item['sku'] . '] ' : '') . ($item['spec'] ?? ''));
                                    ?>
                                    <span class="product-display"><?=htmlspecialchars($itemName)?><?= $itemMeta !== '' ? ' <small style="color:var(--gray-500)">'.htmlspecialchars($itemMeta).'</small>' : '' ?></span>
                                </td>
                                <td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="<?=$item['quantity']?>" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>
                                <td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="<?=$item['price']?>" onchange="calcRow(this)" required></td>
                                <td><input type="text" class="form-control amount-display" value="<?=$item['amount']?>" readonly></td>
                                <td><input type="text" name="item_remark[]" class="form-control" value="<?=htmlspecialchars($item['remark']??'')?>" placeholder="行备注"></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline move-up" onclick="moveRow(this,-1)" title="上移">↑</button>
                                    <button type="button" class="btn btn-sm btn-outline move-down" onclick="moveRow(this,1)" title="下移">↓</button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();renumber();calcTotal();" title="删除">×</button>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr id="emptyRow" class="editable-row" style="display:none;">
                                <td><input type="number" class="form-control sort-input" value="1" min="1" onchange="sortByNumber(this)" style="text-align:center;" title="输入序号可直接调整排列"></td>
                                <td><input type="hidden" name="product_id[]" value=""><span class="product-display"></span></td>
                                <td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="1" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>
                                <td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="0" onchange="calcRow(this)" required></td>
                                <td><input type="text" class="form-control amount-display" value="0" readonly></td>
                                <td><input type="text" name="item_remark[]" class="form-control" placeholder="行备注"></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline move-up" onclick="moveRow(this,-1)" title="上移">↑</button>
                                    <button type="button" class="btn btn-sm btn-outline move-down" onclick="moveRow(this,1)" title="下移">↓</button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();renumber();calcTotal();" title="删除">×</button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-right"><strong>合计：</strong></td>
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
                    <thead><tr><th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选/取消"></th><th style="width:52px;">图片</th><th>SKU</th><th>商品名称</th><th>规格型号</th><th>单位</th><th style="width:80px;">售价</th><th style="width:100px;">数量</th></tr></thead>
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
/* 序号列：隐藏 number 的上下微调箭头并收窄内距，否则列宽里数字会被箭头和内距挤没了。
   微调箭头在这里也用不上（改序号靠直接输入或 ↑↓ 按钮）。写法同上方 .qty-input */
.sort-input { padding-left:8px; padding-right:8px; -moz-appearance:textfield; }
.sort-input::-webkit-inner-spin-button, .sort-input::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
</style>

<script>
var allCustomers = <?= json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var allCustomerPhones = <?= json_encode($customerPhones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/**
 * 客户下拉渲染：每行显示「客户名称 + 电话」。
 * 同名客户很多，只看名称分不清是谁；同时支持按电话反查。
 */
function customerRowHtml(id, name) {
    var phone = allCustomerPhones[id] || '';
    var html = '<div class="search-dropdown-item" data-id="'+id+'" data-name="'+escapeHtml(name)+'" onclick="selectCustomer(this)" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-100);transition:background 0.15s;">';
    html += '<div>'+escapeHtml(name)+'</div>';
    if (phone) {
        html += '<div style="font-size:12px;color:var(--gray-500);margin-top:2px;"><i class="fa-solid fa-phone" style="margin-right:4px;"></i>'+escapeHtml(phone)+'</div>';
    }
    return html + '</div>';
}

function filterCustomers() {
    var q = (document.getElementById('customerSearch').value||'').toLowerCase();
    var dropdown = document.getElementById('customerDropdown');
    var html = '';
    var count = 0;
    for (var id in allCustomers) {
        if (!allCustomers.hasOwnProperty(id)) continue;
        var name = allCustomers[id];
        var phone = allCustomerPhones[id] || '';
        // 名称或电话任一命中即可，方便直接用手机号定位客户
        if (q && name.toLowerCase().indexOf(q) === -1 && phone.toLowerCase().indexOf(q) === -1) continue;
        count++;
        html += customerRowHtml(id, name);
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
    var tr = input.closest('tr'), cb = tr.querySelector('.product-check');
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
    document.getElementById('selectAll').checked = false;
    var rows = '';
    allProducts.forEach(function(p) {
        var text = (p.sku + ' ' + p.name + ' ' + (p.spec||'') + ' ' + (p.unit_name||'')).toLowerCase();
        if (q && text.indexOf(q) === -1) return;
        var spec = p.spec || '-';
        var picked = pickedQty.hasOwnProperty(p.id);
        var qty = picked ? pickedQty[p.id] : 1;
        rows += '<tr'+(picked?' class="selected"':'')+' data-id="'+p.id+'" data-price="'+p.sale_price+'" data-name="'+escapeHtml(p.name)+'" data-sku="'+escapeHtml(p.sku)+'" data-spec="'+escapeHtml(spec)+'" data-unit="'+(p.unit_name||'')+'" onclick="toggleProductRow(this)">'
            + '<td><input type="checkbox" class="product-check"'+(picked?' checked':'')+' onclick="event.stopPropagation();syncRowCheck(this);"></td>'
            + '<td>'+productThumbHtml(p.image_url, 40)+'</td>'
            + '<td>'+escapeHtml(p.sku)+'</td><td><strong>'+escapeHtml(p.name)+'</strong></td>'
            + '<td>'+escapeHtml(spec)+'</td><td>'+(p.unit_name||'-')+'</td><td>¥'+p.sale_price.toFixed(2)+'</td>'
            + '<td><input type="number" class="form-control sel-qty" value="'+qty+'" min="1" step="1" style="width:80px;text-align:center;"'
            + ' onclick="event.stopPropagation()" oninput="pickQty(this,'+p.id+')" title="输入本次数量，自动勾选"></td></tr>';
    });
    tbody.innerHTML = rows || '';
    noResult.style.display = rows ? 'none' : '';
}

function escapeHtml(str) { var div = document.createElement('div'); div.textContent = str; return div.innerHTML; }

// 商品缩略图：无图时返回灰色占位方块，避免该列塌缩导致每次搜索都抖动。
// 返回的 HTML 已自行转义，不要再对它二次 escapeHtml。
// 点击图片调用 previewImage() 放大 —— 该函数由 assets/js/print-image.js 提供，
// includes/footer.php 已全局引入，本页 :486 处 require 了 footer.php，可直接用。
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

function toggleProductRow(tr) { var cb = tr.querySelector('.product-check'); cb.checked = !cb.checked; tr.classList.toggle('selected', cb.checked); syncPick(tr, cb.checked); updateSelectAll(); }
function syncRowCheck(cb) { var tr = cb.closest('tr'); tr.classList.toggle('selected', cb.checked); syncPick(tr, cb.checked); updateSelectAll(); }
function toggleSelectAll(cb) { document.querySelectorAll('#productList .product-check').forEach(function(c){c.checked=cb.checked;}); document.querySelectorAll('#productList tr').forEach(function(r){r.classList.toggle('selected',cb.checked);syncPick(r,cb.checked);}); updateSelectAll(); }
function updateSelectAll() { var checks = document.querySelectorAll('#productList .product-check'); document.getElementById('selectAll').checked = checks.length > 0 && Array.from(checks).every(function(c){return c.checked;}); }
// 每次打开弹窗都清空已选数量：本次选择是新一轮操作，不应残留上一次的数据
function openProductModal() { pickedQty = {}; openModal('productModal'); filterProducts(); document.getElementById('productSearch').value = ''; setTimeout(function(){document.getElementById('productSearch').focus();},150); }

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
        // 取弹窗里填的数量（默认 1）；非法值兜底为 1
        var qtyInp = tr.querySelector('.sel-qty');
        var qty = qtyInp ? (parseFloat(qtyInp.value) || 1) : 1;
        if (qty <= 0) qty = 1;
        // 小计不能再用原来的 value="'+price+'"（那是写死的「单价×1」）。
        // calcTotal() 是累加 .amount-display 的值，不填对这里，行小计和总合计都会显示成单价×1。
        // 后端保存时会用 数量×单价 重新计算，不受影响，但用户保存前看到的金额必须是正确的。
        var amount = (parseFloat(price) * qty).toFixed(2);
        var rowHtml = '<tr class="editable-row">'
            + '<td><input type="number" class="form-control sort-input" value="1" min="1" onchange="sortByNumber(this)" style="text-align:center;" title="输入序号可直接调整排列"></td>'
            + '<td><input type="hidden" name="product_id[]" value="'+pid+'"><span class="product-display">'+name+' <small style="color:var(--gray-500)">['+sku+'] '+spec+'</small></span></td>'
            + '<td><div class="qty-stepper"><button type="button" class="stepper-btn" onclick="qtyDown(this)">−</button><input type="number" name="quantity[]" class="form-control qty-input" value="'+qty+'" min="1" step="1" onchange="calcRow(this)" style="text-align:center;" required><button type="button" class="stepper-btn" onclick="qtyUp(this)">+</button></div></td>'
            + '<td><input type="number" step="0.01" name="price[]" class="form-control number-input price-input" value="'+price+'" onchange="calcRow(this)" required></td>'
            + '<td><input type="text" class="form-control amount-display" value="'+amount+'" readonly></td>'
            + '<td><input type="text" name="item_remark[]" class="form-control" placeholder="行备注"></td>'
            + '<td style="white-space:nowrap;">'
            + '<button type="button" class="btn btn-sm btn-outline move-up" onclick="moveRow(this,-1)" title="上移">↑</button>'
            + '<button type="button" class="btn btn-sm btn-outline move-down" onclick="moveRow(this,1)" title="下移">↓</button>'
            + '<button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();renumber();calcTotal();" title="删除">×</button>'
            + '</td></tr>';
        tbody.insertAdjacentHTML('beforeend', rowHtml);
    });
    closeModal('productModal'); calcTotal(); renumber();
}
function calcRow(el) { var row=el.closest('tr'), qty=parseFloat(row.querySelector('.qty-input').value)||0, price=parseFloat(row.querySelector('.price-input').value)||0; row.querySelector('.amount-display').value=(qty*price).toFixed(2); calcTotal(); }
function qtyDown(btn){var inp=btn.parentElement.querySelector('.qty-input'),v=parseInt(inp.value)||1;if(v>1){inp.value=v-1;calcRow(inp);}}
function qtyUp(btn){var inp=btn.parentElement.querySelector('.qty-input'),v=parseInt(inp.value)||0;inp.value=v+1;calcRow(inp);}
function calcTotal(){var total=0;document.querySelectorAll('.amount-display').forEach(function(a){total+=parseFloat(a.value)||0;});document.getElementById('totalAmount').textContent='¥'+total.toFixed(2);}

// ===== 明细行排序（↑↓ 按钮 / 序号输入框）=====
// 注意：#emptyRow 是 display:none 的占位行，也带 editable-row，必须排除，
// 否则新增场景下它会被算进行数，序号从 2 开始、↑↓ 禁用状态也会错。
function getItemRows(){
    return Array.prototype.filter.call(
        document.querySelectorAll('#itemsBody tr.editable-row'),
        function(r){ return r.id !== 'emptyRow' && r.style.display !== 'none'; }
    );
}
// 重排序号，并刷新 ↑↓ 可用态（首行禁用↑，末行禁用↓）
function renumber(){
    var rows = getItemRows();
    rows.forEach(function(row, i){
        var s = row.querySelector('.sort-input'); if (s) s.value = i + 1;
        var u = row.querySelector('.move-up');    if (u) u.disabled = (i === 0);
        var d = row.querySelector('.move-down');  if (d) d.disabled = (i === rows.length - 1);
    });
}
// 上移/下移   dir: -1 上移 / 1 下移
function moveRow(btn, dir){
    var row = btn.closest('tr');
    var sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!sib || sib.id === 'emptyRow' || sib.style.display === 'none' || !sib.classList.contains('editable-row')) return;
    if (dir < 0) { row.parentNode.insertBefore(row, sib); }
    else         { row.parentNode.insertBefore(sib, row); }
    renumber();
}
// 在序号框里直接输入目标序号跳位；越界值自动收敛到首/末行
function sortByNumber(input){
    var rows = getItemRows(), row = input.closest('tr'), from = rows.indexOf(row);
    if (from < 0) return;
    var n = parseInt(input.value, 10);
    if (isNaN(n) || n < 1) n = 1;
    if (n > rows.length) n = rows.length;
    if (n === from + 1) { renumber(); return; }   // 位置没变，只校正显示
    if (n - 1 < from) { row.parentNode.insertBefore(row, rows[n - 1]); }
    else              { row.parentNode.insertBefore(row, rows[n - 1].nextSibling); }
    renumber();
}

// ===== AJAX 保存：成功后整页跳转回列表，失败在表单内提示，不再出现空白页 =====
function bindQuoteForm(){
    var form = document.getElementById('quoteForm');
    if (!form || form.__bound) return;
    form.__bound = true;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (!document.getElementById('customerId').value) { alert('请选择客户'); return false; }
        if (!document.querySelectorAll('#itemsBody input[name="product_id[]"]').length) { alert('请至少添加一个商品'); return false; }

        var btn = form.querySelector('button[type="submit"]');
        submitQuoteForm(form, btn, 0);
        return false;
    });
}
// 把服务端下发的新令牌写回页面所有隐藏域（不依赖 main.js，兼容旧缓存脚本）
function setQuoteCsrf(token){
    if (!token) return;
    if (window.refreshCsrfToken) { window.refreshCsrfToken(token); return; }
    document.querySelectorAll('input[name="_csrf_token"]').forEach(function(inp){ inp.value = token; });
}
// retried：令牌失效后自动重试的标记，最多重试一次，避免死循环
function submitQuoteForm(form, btn, retried){
    var oldText = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '保存中…'; }

    var fd = new FormData(form);
    fd.append('_ajax', '1');
    fetch(form.getAttribute('action') || 'quote_form.php', {
        method: 'POST',
        // 必须显式声明表单编码：body 用字符串时浏览器不会自动补 Content-Type，
        // 否则 PHP 按 text/plain 处理，$_POST 为空，令牌也就“提交长度=0”
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin',
        body: new URLSearchParams(fd).toString()
    }).then(function(r){ return r.json(); })
      .then(function(res){
          // 令牌过期：用服务端下发的新令牌写回隐藏域并自动重试一次，避免丢失已填内容
          if (res && res.csrf_expired && !retried) {
              setQuoteCsrf(res.csrf_token);
              submitQuoteForm(form, btn, 1);
              return;
          }
          if (res.ok) {
              if (window.showToast) window.showToast(res.msg, 'success');
              window.location.href = res.redirect || 'quote.php';
          } else {
              var msg = res.msg || res.message || '保存失败';
              if (res && res.csrf_expired) {
                  msg += '（已用新令牌自动重试一次仍失败，请刷新页面后重试）';
                  if (res.csrf_debug) console.warn('CSRF 诊断：', res.csrf_debug);
              }
              showQuoteMsg(msg);
              if (btn) { btn.disabled = false; btn.innerHTML = oldText; }
          }
      })
      .catch(function(err){
          showQuoteMsg('请求失败：' + err.message);
          if (btn) { btn.disabled = false; btn.innerHTML = oldText; }
      });
}
function showQuoteMsg(msg){
    var box = document.getElementById('quoteFormMsg');
    if (box) box.innerHTML = '<div class="alert alert-danger">' + String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
    else alert(msg);
    window.scrollTo(0, 0);
}
// 页面加载后按明细行重算合计，保证显示的金额与实际明细一致（复制场景下以明细为准）
calcTotal();
renumber();
bindQuoteForm();
window.rebindPageForms = bindQuoteForm;
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
