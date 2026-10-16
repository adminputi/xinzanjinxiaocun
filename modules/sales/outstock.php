<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
// 业务员 / 状态筛选：报价、合同、订单、出库四个列表共用同一套
require_once __DIR__ . '/bill_filter.php';
require_permission('sales_outstock');
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
// 出库的三个动作分开授权：能看这张列表（sales_outstock，上面已校验）
// ≠ 能出库（create）≠ 能撤销（undo）≠ 能看全部（all）
$canCreate = check_sales_outstock_create();
$canUndo   = check_sales_outstock_undo();
$seeAll    = check_sales_outstock_all();

// 执行数据库迁移（替代运行时 ALTER TABLE）
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$employee = trim($_GET['employee'] ?? '');   // 按业务员筛：下拉直接选某个人
$status   = $_GET['status'] ?? '';           // 按状态筛

$where = ''; $params = [];
$conditions = [];
// 出库单的业务员有两处：employee_id（关联 users.real_name）和 salesperson_name
// （单据上直接存的名字），历史单据存在 employee_id=0 但名字有值，两边都要认
if ($search) {
    $conditions[] = "(so.bill_no LIKE ? OR c.name LIKE ?
                      OR IFNULL(u.real_name,'') LIKE ? OR IFNULL(so.salesperson_name,'') LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($employee !== '') {
    $ec = sales_emp_cond($pdo, $employee, 'so', 'employee_id', 'salesperson_name');
    if ($ec['cond'] !== '') { $conditions[] = $ec['cond']; $params = array_merge($params, $ec['params']); }
}
if ($status !== '') {
    $conditions[] = "so.status = ?";
    $params[] = $status;
}
// 出库单可见范围：与出库详情页同一口径 —— 客户归属本人，或本人亲手创建。
//
// 原来只按 user_id（创建人）过滤，这里有个坑：出库单通常是库管在订单页
// 替业务员点的「出库」，user_id 记的是库管，于是业务员永远看不到自己客户的
// 出库单（表现就是「明明给了销售出库权限却看不到单子」）。
//
// 保留 user_id 这一支是兜底：库管替别人出货时，也不会把自己刚出的单弄丢。
// 勾了 sales_outstock_all（或 sales_view_all）的人不加限制。
// 注：没有关联客户的出库单（customer_id 为空或客户已删）c.owner_id 为 NULL，
//     谁都匹配不上，仅管理员可见 —— 与 CRM/销售/追踪全站口径一致。
if (!$seeAll) {
    $conditions[] = "(c.owner_id = ? OR so.user_id = ?)";
    $params[] = get_user_id();
    $params[] = get_user_id();
}
if ($conditions) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
// COUNT 和列表都必须 JOIN users：搜索/筛选用到 u.real_name，漏了会报 Unknown column
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_outstocks so LEFT JOIN customers c ON so.customer_id=c.id LEFT JOIN users u ON so.employee_id=u.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name, w.name as warehouse_name, u.real_name as employee_name FROM sales_outstocks so LEFT JOIN customers c ON so.customer_id=c.id LEFT JOIN warehouses w ON so.warehouse_id=w.id LEFT JOIN users u ON so.employee_id=u.id $where ORDER BY so.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

// 状态筛选只列实际会出现的两种：出库即 confirmed，撤销后为 cancelled
// （draft 在出库单里不出现，所以不列，避免选了个永远为空的选项）
$statusLabels = ['confirmed'=>'已出库','cancelled'=>'已撤销'];

$customers = get_options('customers','id','name','status=1');
// 获取所有客户详细信息用于下拉
$custList = $pdo->query("SELECT id,name,phone,address,contact FROM customers WHERE status=1 ORDER BY name")->fetchAll();
$warehouses = get_options('warehouses','id','name','status=1');
$employees = get_options('users','id','real_name','status=1');
$empList = $pdo->query("SELECT id,real_name as name,phone FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
$products = $pdo->query("SELECT id,sku,name,spec,sale_price,(SELECT name FROM units WHERE id=unit_id) as unit_name FROM products WHERE status=1")->fetchAll();

// 从销售订单跳转过来的预填数据
$fromOrder = null; $fromItems = [];
// 从订单页「出库」按钮跳过来时预填单据。没 create 权限的一律忽略这个参数：
// 否则销售只要手敲 outstock.php?from_order=xx 就能绕过按钮打开新增出库弹窗。
if ($canCreate && isset($_GET['from_order']) && intval($_GET['from_order']) > 0) {
    $oid = intval($_GET['from_order']);
    // 检查是否已经出库过：
    // 只拦「有效出库单」——已撤销的（status='cancelled'）允许重新出库，否则撤销后这张订单永远出不了货
    $stmt = $pdo->prepare("SELECT id, bill_no FROM sales_outstocks WHERE order_id=? AND status='confirmed' LIMIT 1");
    $stmt->execute([$oid]);
    $existingOutstock = $stmt->fetch();
    if ($existingOutstock) {
        $error = '该订单已出库，单号：' . htmlspecialchars($existingOutstock['bill_no']) . '，不允许重复出库（如需重出，请先撤销该出库单）';
    } else {
        $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, c.id as cid, u.real_name as emp_name, u.phone as emp_phone FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN users u ON o.employee_id=u.id WHERE o.id=?");
        $stmt->execute([$oid]);
        $fromOrder = $stmt->fetch();
        if ($fromOrder) {
            // 使用订单明细的实际单价 i.price，而非产品主档的 sale_price；同时获取行备注
            $stmt = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, i.price as order_price FROM sales_order_items i JOIN products p ON i.product_id=p.id WHERE i.order_id=?");
            $stmt->execute([$oid]);
            $fromItems = $stmt->fetchAll();
        }
    }
}

// 当前登录用户即默认操作人
$currentUser = get_user_name();
$currentEmpId = get_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    // 藏了按钮还不够：直接构造 POST 一样能建单，这里必须再拦一道
    if (!$canCreate) die('无权限：执行出库需要「销售出库：执行出库」权限');
    $customerId = intval($_POST['customer_id']??0);
    $warehouseId = intval($_POST['warehouse_id']??0);
    $outstockDate = $_POST['outstock_date']??date('Y-m-d');
    $employeeId = intval($_POST['employee_id']??0);
    $receiverName = trim($_POST['receiver_name'] ?? '');
    $receiverPhone = trim($_POST['receiver_phone'] ?? '');
    $salespersonName = trim($_POST['salesperson_name'] ?? '');
    $salespersonPhone = trim($_POST['salesperson_phone'] ?? '');
    // 从选中的用户自动获取业务员信息
    if ($employeeId > 0) {
        $stmt = $pdo->prepare("SELECT real_name, phone FROM users WHERE id=?");
        $stmt->execute([$employeeId]);
        $userInfo = $stmt->fetch();
        if ($userInfo) {
            $salespersonName = $salespersonName ?: $userInfo['real_name'];
            $salespersonPhone = $salespersonPhone ?: ($userInfo['phone'] ?? '');
        }
    }
    $remark = $_POST['remark']??'';
    $pids = $_POST['product_id']??[];
    $qtys = $_POST['quantity']??[];
    $prices = $_POST['price']??[];
    $itemRemarks = $_POST['item_remark'] ?? [];





    $pdo->beginTransaction();
    try {
        $totalAmount = 0; $itemData = [];
        foreach ($pids as $i => $pid) {
            $qty = floatval($qtys[$i]??0); $price = floatval($prices[$i]??0);
            if ($pid && $qty>0) { $amt = $qty*$price; $totalAmount += $amt; $itemData[]=['pid'=>intval($pid),'qty'=>$qty,'price'=>$price,'amt'=>$amt,'remark'=>$itemRemarks[$i]??'']; }
        }
        if (empty($itemData)) { $error='至少添加一个商品'; $pdo->rollBack(); }
        else {
            // 库存检查：出库前验证每个商品的库存是否充足（使用行锁防止超卖）
            foreach ($itemData as $it) {
                $stockCheck = $pdo->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE product_id=? AND warehouse_id=? FOR UPDATE");
                $stockCheck->execute([$it['pid'], $warehouseId]);
                $currentStock = floatval($stockCheck->fetchColumn());
                if ($currentStock < $it['qty']) {
                    $stmt = $pdo->prepare("SELECT name FROM products WHERE id=?");
                    $stmt->execute([$it['pid']]);
                    $pname = $stmt->fetchColumn();
                    throw new Exception("商品库存不足，当前库存：{$currentStock}，出库数量：{$it['qty']}");
                }
            }
            $billNo = generate_bill_no('CK');
            $pdo->prepare("INSERT INTO sales_outstocks (bill_no,order_id,customer_id,warehouse_id,total_amount,status,outstock_date,employee_id,receiver_name,receiver_phone,salesperson_name,salesperson_phone,remark,user_id,created_at) VALUES (?,?,?,?,?,'confirmed',?,?,?,?,?,?,?,?,?)")->execute([$billNo,intval($_POST['order_id']??0),$customerId,$warehouseId,$totalAmount,$outstockDate,$employeeId,$receiverName,$receiverPhone,$salespersonName,$salespersonPhone,$remark,get_user_id(),date('Y-m-d H:i:s')]);
            $oid = $pdo->lastInsertId();
            $insStmt = $pdo->prepare("INSERT INTO sales_outstock_items (outstock_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
            foreach ($itemData as $it) {
                $insStmt->execute([$oid,$it['pid'],$it['qty'],$it['price'],$it['amt'],$it['remark']]);
                update_inventory($it['pid'], $warehouseId, -$it['qty'], 'out', $billNo, 'sales_outstock', get_user_id());
            }
            // 更新关联订单状态为已出库
            $orderId = intval($_POST['order_id']??0);
            if ($orderId > 0) {
                $pdo->prepare("UPDATE sales_orders SET status='shipped' WHERE id=?")->execute([$orderId]);
            }
            add_log(get_user_id(), 'create', 'sales_outstock', "销售出库: $billNo");
            $pdo->commit();
            redirect('outstock.php');
        }
    } catch (Exception $e) { $pdo->rollBack(); error_log('Outstock save error: '.$e->getMessage()); $error = '保存失败：' . $e->getMessage(); }

}

// 撤销出库（恢复库存 + 出库单置「已撤销」留痕 + 订单回到已确认）仅admin，原因必填
// Q1=B：不再物理删除，避免「出了库又撤」的痕迹在单据层面彻底消失
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'undo') {
    csrf_verify();
    if (!$canUndo) die('无权限：撤销出库需要「销售出库：撤销出库」权限');
    $oid = intval($_POST['id']??0);
    $cancelReason = trim($_POST['cancel_reason'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM sales_outstocks WHERE id=?");
    $stmt->execute([$oid]);
    $outstock = $stmt->fetch();
    if (!$outstock) {
        flash_set('出库单不存在');
    } elseif ($outstock['status'] !== 'confirmed') {
        flash_set('该出库单已撤销，无需重复操作');
    } elseif ($cancelReason === '') {
        flash_set('撤销出库必须填写原因');
    } else {
        $pdo->beginTransaction();
        try {
            undo_sales_outstock($oid, $cancelReason);
            $pdo->commit();
            flash_set('已撤销出库 ' . $outstock['bill_no'] . '：库存已恢复，出库单留痕保留', 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Undo outstock error: '.$e->getMessage());
            flash_set('撤销出库失败：' . $e->getMessage());
        }
    }
    redirect('outstock.php');
}

?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-truck-fast"></i> 销售出库</h1>
    <?php // 新增出库单原来谁都能点，销售也能自己开单；现在收口到 create 权限 ?>
    <?php if ($canCreate): ?>
    <button class="btn btn-primary" onclick="openOutModal()"><i class="fa-solid fa-plus"></i> 新增出库单</button>
    <?php endif; ?>
</div>
<?php flash_show(); ?>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<?php // 业务员下拉：出库单特殊，employee_id 关联的名字和 salesperson_name 文本名要合并去重 ?>
<?php $empOptions = $isAdmin ? sales_emp_options($pdo, 'sales_outstocks', 'employee_id', 'salesperson_name') : []; ?>
<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/客户/业务员..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php // 业务员筛选只给管理员：其他人本来就只看得到自己出的单，下拉里也只会有一个人 ?>
    <?php if ($isAdmin): ?><?= sales_emp_select($empOptions, $employee) ?><?php endif; ?>
    <?= sales_status_select($statusLabels, $status) ?>
    <?php if($search || $employee || $status): ?><a href="outstock.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>仓库</th><th>金额</th><th>日期</th><th>业务员</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['customer_name']?:'-')?></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><strong>¥<?=format_money($item['total_amount'])?></strong></td>
    <td><?=$item['outstock_date']?></td>
    <td><?=htmlspecialchars($item['salesperson_name']?:'-')?></td>
    <td><?php if ($item['status']==='cancelled'): ?><span class="badge badge-danger" title="已撤销，库存已恢复，不计入已出库">已撤销</span><?php else: ?><span class="badge badge-success">已出库</span><?php endif; ?></td>
    <td>
        <a href="outstock_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="详情"><i class="fa-solid fa-eye"></i></a>
        <a href="outstock_view.php?id=<?=$item['id']?>&print=1" class="btn btn-sm btn-outline" title="打印出库单"><i class="fa-solid fa-print"></i></a>
        <?php if ($item['status']=='confirmed' && $canUndo): ?>
        <a href="javascript:void(0)" class="btn btn-sm btn-outline" title="撤销出库（恢复库存，出库单置已撤销留痕）" style="color:var(--warning)" onclick="showUndoModal(<?=$item['id']?>,'<?=$item['bill_no']?>')"><i class="fa-solid fa-rotate-left"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-truck-fast"></i><p>暂无出库记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php // 翻页要带上当前所有筛选条件：原来写死成 ?page=N，一点第 2 页搜索词就丢了 ?>
<?php for($i=1;$i<=$pages;$i++): ?><a href="?<?=sales_page_query($i, ['search','employee','status'])?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<!-- 新增出库单弹窗 -->
<div class="modal-overlay" id="outModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title">新增销售出库</h3><button class="modal-close" onclick="closeModal('outModal')">&times;</button></div>
<form method="post" onsubmit="return syncCustomerInfo()" style="display:flex;flex-direction:column;flex:1;min-height:0;"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="order_id" id="oform_order_id" value="<?=$fromOrder['id']??0?>">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">客户 <span class="required">*</span></label>
            <div style="display:flex;gap:6px;">
                <select name="customer_id" id="ocust" class="form-control searchable" data-ajax="/api/search_partners.php?type=customer" onchange="onCustChange()" required style="flex:1;">
                    <option value="">选择客户</option>
                    <?php foreach($custList as $c): ?>
                    <option value="<?=$c['id']?>" data-phone="<?=htmlspecialchars($c['phone'])?>" data-address="<?=htmlspecialchars($c['address'])?>" data-contact="<?=htmlspecialchars($c['contact'])?>"><?=htmlspecialchars($c['name'])?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline" onclick="showNewCustomer()" style="white-space:nowrap;"><i class="fa-solid fa-plus"></i> 新增</button>
            </div>
        </div>
        <div class="form-group"><label class="form-label">仓库 <span class="required">*</span></label><select name="warehouse_id" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">出库日期</label><input type="date" name="outstock_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    </div>

    <!-- 新增客户内联表单 -->
    <div id="newCustomerForm" style="display:none;background:#f8fafc;padding:16px;border-radius:8px;margin-bottom:12px;border:1px solid #e2e8f0;">
        <div class="flex-between mb-2"><strong>新增客户信息</strong><button type="button" class="btn btn-sm btn-outline" onclick="hideNewCustomer()">取消</button></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">客户名称 <span class="required">*</span></label><input type="text" id="nc_name" class="form-control" placeholder="必填"></div>
            <div class="form-group"><label class="form-label">客户电话</label><input type="text" id="nc_phone" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">客户地址</label><input type="text" id="nc_address" class="form-control"></div>
        <button type="button" class="btn btn-primary btn-sm" onclick="addNewCustomer()">保存客户并继续</button>
        <span id="nc_msg" style="margin-left:10px;font-size:12px;"></span>
    </div>

    <div class="form-row">
        <div class="form-group"><label class="form-label">接货人员</label><input type="text" name="receiver_name" id="oreceiver" class="form-control" placeholder="接货人姓名"></div>
        <div class="form-group"><label class="form-label">接货人电话</label><input type="text" name="receiver_phone" id="oreceiver_phone" class="form-control" placeholder="接货人电话"></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">业务员</label>
            <select name="employee_id" id="osalesperson_select" class="form-control" onchange="onSalespersonSelect()">
                <option value="0">选择业务员</option>
                <?php foreach($empList as $e): ?>
                <option value="<?=$e['id']?>" data-phone="<?=htmlspecialchars($e['phone']??'')?>"><?=htmlspecialchars($e['name'])?> <?=htmlspecialchars($e['phone']??'')?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">业务员电话</label><input type="text" name="salesperson_phone" id="osalesperson_phone" class="form-control" placeholder="自动填充" readonly></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">操作人</label>
            <input type="text" class="form-control" value="<?=htmlspecialchars($currentUser)?>" readonly>
            <small style="color:var(--gray-500);">操作人默认为当前登录账户</small>
        </div>
    </div>

    <div class="flex-between mb-2"><label class="form-label" style="margin:0;">商品明细</label><?php if (!$fromOrder): ?><button type="button" class="btn btn-sm btn-outline" onclick="addORow()"><i class="fa-solid fa-plus"></i> 添加</button><?php endif; ?></div>
    <div class="table-container"><table>
        <thead><tr><th>商品</th><th style="width:90px">数量</th><th style="width:90px">单价</th><th style="width:100px">金额</th><th style="width:110px">备注</th><?php if (!$fromOrder): ?><th style="width:50px"></th><?php endif; ?></tr></thead>
        <tbody id="outItems"><?php if (!$fromOrder): ?><tr id="outTpl">
            <td><select name="product_id[]" class="form-control psel" onchange="updateOp(this)" required><option value="">选择商品</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['sale_price']?>"><?=$p['name'].' ['.$p['sku'].']'?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="1" name="quantity[]" class="form-control oqty" value="1" onchange="calcORow(this)" required></td>
            <td><input type="number" step="0.01" name="price[]" class="form-control oprice" value="0" onchange="calcORow(this)" required></td>
            <td><input type="text" class="form-control oamt" value="0" readonly></td>
            <td><input type="text" name="item_remark[]" class="form-control" placeholder="备注"></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcOTotal()">×</button></td>
        </tr><?php endif; ?></tbody>
        <tfoot><tr><td colspan="4" class="text-right"><strong>合计：</strong></td><td><strong id="outTotal">¥0.00</strong></td><?php if (!$fromOrder): ?><td></td><?php endif; ?></tr></tfoot>
    </table></div>
    <div class="form-group mt-2"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('outModal')">取消</button><button type="submit" class="btn btn-primary">保存出库</button></div>
</form></div></div>

<script>
var outProductOptionsHtml='<?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['sale_price']?>"><?=js_escape($p['name']).' ['.js_escape($p['sku']).']'?></option><?php endforeach; ?>';

function buildOutRow(pid,qty,price,amt,canDelete,remark){
    var tr=document.createElement('tr');
    var html='<td><select name="product_id[]" class="form-control psel" onchange="updateOp(this)" required><option value="">选择商品</option>'+outProductOptionsHtml+'</select></td>';
    html+='<td><input type="number" step="1" name="quantity[]" class="form-control oqty" value="'+qty+'" onchange="calcORow(this)" required></td>';
    html+='<td><input type="number" step="0.01" name="price[]" class="form-control oprice" value="'+price+'" onchange="calcORow(this)" required></td>';
    html+='<td><input type="text" class="form-control oamt" value="'+amt+'" readonly></td>';
    html+='<td><input type="text" name="item_remark[]" class="form-control" value="'+(remark||'')+'" placeholder="备注"></td>';
    if(canDelete) html+='<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();calcOTotal()">×</button></td>';
    tr.innerHTML=html;
    if(pid){ var sel=tr.querySelector('.psel'); for(var i=0;i<sel.options.length;i++){ if(sel.options[i].value==pid){sel.selectedIndex=i;break;} } }
    document.getElementById('outItems').appendChild(tr);
}

function openOutModal(){
    document.getElementById('ocust').selectedIndex=0;
    document.getElementById('oreceiver').value='';
    document.getElementById('oreceiver_phone').value='';
    document.getElementById('osalesperson_select').selectedIndex=0;
    document.getElementById('osalesperson_phone').value='';
    document.getElementById('oform_order_id').value='0';
    hideNewCustomer();
    var items=document.getElementById('outItems');
    items.innerHTML='';
    <?php if ($fromOrder): ?>
    document.getElementById('oform_order_id').value='<?=$fromOrder['id']?>';
    var custSel=document.getElementById('ocust');
    for(var i=0;i<custSel.options.length;i++){
        if(custSel.options[i].value=='<?=$fromOrder['cid']?>'){custSel.selectedIndex=i;onCustChange();break;}
    }
    var whSel=document.querySelector('[name="warehouse_id"]');
    for(var i=0;i<whSel.options.length;i++){
        if(whSel.options[i].value=='<?=$fromOrder['warehouse_id']?>'){whSel.selectedIndex=i;break;}
    }
    // 传递订单业务员信息
    <?php if(!empty($fromOrder['employee_id'])): ?>
    var empSel=document.getElementById('osalesperson_select');
    for(var i=0;i<empSel.options.length;i++){
        if(empSel.options[i].value=='<?=$fromOrder['employee_id']?>'){empSel.selectedIndex=i;onSalespersonSelect();break;}
    }
    <?php endif; ?>
    // 传递订单总备注
    document.querySelector('[name="remark"]').value='<?=js_escape($fromOrder['remark']??'')?>';
    <?php foreach($fromItems as $fi): ?>
    buildOutRow('<?=$fi['product_id']?>','<?=$fi['quantity']?>','<?=$fi['order_price']?>','<?=$fi['amount']?>',false,'<?=js_escape($fi['remark']??'')?>');
    <?php endforeach; ?>
    <?php endif; ?>
    calcOTotal();
    openModal('outModal');
}
function onSalespersonSelect(){
    var sel=document.getElementById('osalesperson_select');
    var opt=sel.options[sel.selectedIndex];
    var phone=document.getElementById('osalesperson_phone');
    if(opt&&opt.value!=='0'){ phone.value=opt.getAttribute('data-phone')||''; }
    else{ phone.value=''; }
}
function onCustChange(){
    var sel=document.getElementById('ocust');
    var opt=sel.options[sel.selectedIndex];
    if(opt.value==='') return;
    document.getElementById('oreceiver').value=opt.getAttribute('data-contact')||'';
    document.getElementById('oreceiver_phone').value=opt.getAttribute('data-phone')||'';
}
function showNewCustomer(){
    document.getElementById('newCustomerForm').style.display='block';
    document.getElementById('nc_msg').textContent='';
}
function hideNewCustomer(){
    document.getElementById('newCustomerForm').style.display='none';
    document.getElementById('nc_name').value='';
    document.getElementById('nc_phone').value='';
    document.getElementById('nc_address').value='';
    document.getElementById('nc_msg').textContent='';
}
function addNewCustomer(){
    var name=document.getElementById('nc_name').value.trim();
    if(!name){document.getElementById('nc_msg').innerHTML='<span style="color:red">请输入客户名称</span>';return;}
    var phone=document.getElementById('nc_phone').value.trim();
    var address=document.getElementById('nc_address').value.trim();
    var formData=new URLSearchParams();
    formData.append('_csrf_token','<?= csrf_token() ?>');
    formData.append('action','add_customer');
    formData.append('cust_name',name);
    formData.append('cust_phone',phone);
    formData.append('cust_address',address);
    document.getElementById('nc_msg').innerHTML='<span style="color:#666">保存中...</span>';
    fetch('ajax_add_customer.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:formData.toString()})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.success){
            document.getElementById('nc_msg').innerHTML='<span style="color:green">已添加: '+d.name+'</span>';
            // add to dropdown
            var sel=document.getElementById('ocust');
            var opt=document.createElement('option');
            opt.value=d.id;
            opt.textContent=d.name;
            opt.setAttribute('data-phone',d.phone);
            opt.setAttribute('data-address',d.address);
            opt.setAttribute('data-contact',d.contact||'');
            sel.appendChild(opt);
            sel.value=d.id;
            // fill receiver info
            document.getElementById('oreceiver').value=d.contact||'';
            document.getElementById('oreceiver_phone').value=d.phone||'';
            setTimeout(hideNewCustomer,1500);
        } else {
            document.getElementById('nc_msg').innerHTML='<span style="color:red">'+d.message+'</span>';
        }
    }).catch(function(e){document.getElementById('nc_msg').innerHTML='<span style="color:red">网络错误</span>';});
}
function syncCustomerInfo(){
    // ensure receiver info syncs (already handled via onchange)
    return true;
}
function addORow(){if(!document.getElementById('outTpl'))return;var tpl=document.getElementById('outTpl');var row=tpl.cloneNode(true);row.removeAttribute('id');row.querySelector('select').selectedIndex=0;row.querySelector('.oqty').value=1;row.querySelector('.oprice').value=0;row.querySelector('.oamt').value=0;document.getElementById('outItems').appendChild(row);}
function calcORow(el){var row=el.closest('tr');var q=parseFloat(row.querySelector('.oqty').value)||0;var p=parseFloat(row.querySelector('.oprice').value)||0;row.querySelector('.oamt').value=(q*p).toFixed(2);calcOTotal();}
function calcOTotal(){var t=0;document.querySelectorAll('#outItems .oamt').forEach(function(a){t+=parseFloat(a.value)||0;});document.getElementById('outTotal').textContent='¥'+t.toFixed(2);}
function updateOp(sel){var row=sel.closest('tr');var p=sel.options[sel.selectedIndex].getAttribute('data-price')||0;row.querySelector('.oprice').value=p;calcORow(row.querySelector('.oprice'));}
function showUndoModal(id,billNo){
    document.getElementById('undoId').value=id;
    document.getElementById('undoBillNo').textContent=billNo;
    document.getElementById('undoReason').value='';
    openModal('undoModal');
}
<?php if ($fromOrder): ?>document.addEventListener('DOMContentLoaded',function(){openOutModal();});<?php endif; ?>
</script>

<!-- 撤销出库原因弹窗 -->
<div class="modal-overlay" id="undoModal">
    <div class="modal modal-sm">
        <div class="modal-header"><h3 class="modal-title">撤销出库</h3><button class="modal-close" onclick="closeModal('undoModal')">&times;</button></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="undo">
            <input type="hidden" name="id" id="undoId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">出库单号</label>
                    <span id="undoBillNo" style="font-weight:bold;"></span>
                </div>
                <div class="form-group">
                    <label class="form-label">撤销原因 <span class="required">*</span></label>
                    <textarea name="cancel_reason" id="undoReason" class="form-control" rows="3" required placeholder="请输入撤销原因"></textarea>
                </div>
                <p style="color:var(--warning);font-size:13px;">⚠️ 撤销后库存将自动恢复，出库记录将被删除，关联订单恢复为已锁定状态。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('undoModal')">取消</button>
                <button type="submit" class="btn btn-warning">确认撤销</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
