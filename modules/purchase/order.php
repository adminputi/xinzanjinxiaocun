<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_order');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$where = ''; $params = [];
if ($status) { $where = "WHERE o.status=?"; $params[] = $status; }
if ($search) {
    $where = ($where?:'WHERE 1=1')." AND (o.bill_no LIKE ? OR s.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders o LEFT JOIN suppliers s ON o.supplier_id=s.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT o.*, s.name as supplier_name, w.name as warehouse_name, u.real_name as employee_name, (SELECT COUNT(*) FROM purchase_instocks WHERE order_id=o.id) as instock_count FROM purchase_orders o LEFT JOIN suppliers s ON o.supplier_id=s.id LEFT JOIN warehouses w ON o.warehouse_id=w.id LEFT JOIN users u ON o.employee_id=u.id $where ORDER BY o.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$suppliers = get_options('suppliers', 'id', 'name', 'status=1');
$warehouses = get_options('warehouses', 'id', 'name', 'status=1');
$employees = get_options('users', 'id', 'real_name', 'status=1');

$statusLabels = ['draft'=>'草稿','confirmed'=>'已确认','received'=>'已入库','partial'=>'部分入库','completed'=>'已完成','cancelled'=>'已取消'];
$statusBadges = ['draft'=>'warning','confirmed'=>'info','received'=>'primary','partial'=>'info','completed'=>'success','cancelled'=>'gray'];

// 新建/编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_order') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    $supplierId = intval($_POST['supplier_id']??0);
    $warehouseId = intval($_POST['warehouse_id']??0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDate = $_POST['expected_date'] ?? '';
    $employeeId = intval($_POST['employee_id']??0);
    $remark = $_POST['remark'] ?? '';
    $products = $_POST['products'] ?? [];
    $qtys = $_POST['qtys'] ?? [];
    $prices = $_POST['prices'] ?? [];
    $itemRemarks = $_POST['item_remark'] ?? [];

    if (empty($products)) { $error = '请至少添加一个商品'; }
    else {
        $pdo->beginTransaction();
        try {
            $totalAmount = 0;
            $itemData = [];
            foreach ($products as $i => $pid) {
                $qty = floatval($qtys[$i]??0);
                $price = floatval($prices[$i]??0);
                $amount = $qty * $price;
                $totalAmount += $amount;
                $itemData[] = ['pid'=>$pid,'qty'=>$qty,'price'=>$price,'amount'=>$amount,'remark'=>$itemRemarks[$i]??''];
            }

            if ($id > 0) {
                $billNo = $_POST['bill_no'];
                $pdo->prepare("UPDATE purchase_orders SET supplier_id=?,warehouse_id=?,total_amount=?,order_date=?,expected_date=?,employee_id=?,remark=? WHERE id=?")
                    ->execute([$supplierId,$warehouseId,$totalAmount,$orderDate,$expectedDate?:null,$employeeId,$remark,$id]);
                $pdo->prepare("DELETE FROM purchase_order_items WHERE order_id=?")->execute([$id]);
                add_log(get_user_id(), 'update', 'purchase_order', "编辑采购订单: $billNo");
            } else {
                $billNo = generate_bill_no('CG');
                $pdo->prepare("INSERT INTO purchase_orders (bill_no,supplier_id,warehouse_id,total_amount,order_date,expected_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$billNo,$supplierId,$warehouseId,$totalAmount,$orderDate,$expectedDate?:null,$employeeId,$remark,get_user_id(),date('Y-m-d H:i:s')]);
                $id = $pdo->lastInsertId();
                add_log(get_user_id(), 'create', 'purchase_order', "新建采购订单: $billNo");
            }

            $insStmt = $pdo->prepare("INSERT INTO purchase_order_items (order_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
            foreach ($itemData as $item) {
                $insStmt->execute([$id,$item['pid'],$item['qty'],$item['price'],$item['amount'],$item['remark']]);
            }
            $pdo->commit();
            redirect("order.php?page=$page");
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Purchase order save error: '.$e->getMessage());
            $error = '保存失败，请稍后重试';
        }
    }
}

// 状态变更
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'change_status') {
    csrf_verify();
    $oid = intval($_POST['id']??0);
    $newStatus = $_POST['status'] ?? '';
    $allowed = ['draft','confirmed','received','partial','completed','cancelled'];
    if (in_array($newStatus, $allowed)) {
        $pdo->prepare("UPDATE purchase_orders SET status=? WHERE id=?")->execute([$newStatus,$oid]);
        add_log(get_user_id(), 'update', 'purchase_order', "变更状态: ID=$oid -> $newStatus");
    }
    redirect("order.php?page=$page");
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice"></i> 采购订单</h1>
    <button class="btn btn-primary" onclick="location.href='order_form.php'"><i class="fa-solid fa-plus"></i> 新增采购订单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/供应商..." value="<?= htmlspecialchars($search) ?>"></div>
    <select name="status" class="form-control" style="min-width:120px;">
        <option value="">全部状态</option>
        <?php foreach($statusLabels as $k=>$v): ?><option value="<?=$k?>" <?=$status==$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search||$status): ?><a href="order.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>供应商</th><th>仓库</th><th>金额</th><th>采购员</th><th>日期</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><a href="order_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['bill_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['supplier_name']?:'-') ?></td>
    <td><?= htmlspecialchars($item['warehouse_name']?:'-') ?></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td><?= htmlspecialchars($item['employee_name']?:'-') ?></td>
    <td><?= $item['order_date'] ?></td>
    <td><span class="badge badge-<?= $statusBadges[$item['status']]??'gray' ?>"><?= $statusLabels[$item['status']]??$item['status'] ?></span></td>
    <td>
        <div class="table-actions">
            <?php if ($item['status']=='draft'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?=$item['id']?>"><input type="hidden" name="status" value="confirmed"><button class="btn btn-sm btn-success">确认</button></form>
            <a href="order_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-pen"></i></a>
            <form method="post" style="display:inline" onsubmit="return confirm('确定取消？')"><?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="id" value="<?=$item['id']?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-danger">取消</button></form>
            <?php endif; ?>
            <?php if ($item['status']=='confirmed' || $item['status']=='draft'): ?>
            <?php if ($item['instock_count'] > 0): ?>
            <span class="badge badge-success" style="cursor:default;">已入库</span>
            <?php else: ?>
            <a href="../purchase/instock.php?from_order=<?=$item['id']?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-boxes-packing"></i> 入库</a>
            <?php endif; ?>
            <?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>暂无采购订单</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&status=<?=$status?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
