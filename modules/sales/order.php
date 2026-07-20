<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('sales_order');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$isAdmin = (get_user_role() === 'admin');

$where = ''; $params = [];
$conditions = [];
if ($search) {
    $conditions[] = "(o.bill_no LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
// 非admin用户只看自己创建的订单
if (!$isAdmin) {
    $conditions[] = "o.user_id = ?";
    $params[] = get_user_id();
}
if ($conditions) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, w.name as warehouse_name, u.real_name as employee_name, (SELECT COUNT(*) FROM sales_outstocks WHERE order_id=o.id) as outstock_count FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN warehouses w ON o.warehouse_id=w.id LEFT JOIN users u ON o.employee_id=u.id $where ORDER BY o.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$statusLabels = ['draft'=>'可编辑','confirmed'=>'已锁定','shipped'=>'已出库'];
$statusBadges = ['draft'=>'warning','confirmed'=>'info','shipped'=>'success'];

// 确认订单（draft → confirmed）仅admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'confirm') {
    csrf_verify();
    if (!$isAdmin) die('仅管理员可确认订单');
    $oid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt->execute([$oid]);
    $order = $stmt->fetch();
    if ($order && $order['status'] === 'draft') {
        $pdo->prepare("UPDATE sales_orders SET status='confirmed' WHERE id=?")->execute([$oid]);
        add_log(get_user_id(), 'update', 'sales_order', "确认订单: {$order['bill_no']}");
    }
    redirect("order.php?page=$page");
}

// 解锁订单（confirmed → draft）仅admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'unlock') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt->execute([$oid]);
    $order = $stmt->fetch();
    if ($order && $order['status'] === 'confirmed') {
        $pdo->prepare("UPDATE sales_orders SET status='draft' WHERE id=?")->execute([$oid]);
        add_log(get_user_id(), 'update', 'sales_order', "解锁订单: {$order['bill_no']}");
    }
    redirect("order.php?page=$page");
}

// 撤销出库（shipped → confirmed）仅admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'undo_outstock') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt->execute([$oid]);
    $order = $stmt->fetch();
    if ($order && $order['status'] === 'shipped') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM sales_outstocks WHERE order_id=? LIMIT 1");
            $stmt->execute([$oid]);
            $outstock = $stmt->fetch();
            if ($outstock) {
                // 恢复库存
                $stmt = $pdo->prepare("SELECT * FROM sales_outstock_items WHERE outstock_id=?");
                $stmt->execute([$outstock['id']]);
                $items = $stmt->fetchAll();
                foreach ($items as $item) {
                    update_inventory($item['product_id'], $outstock['warehouse_id'], $item['quantity'], 'in', $outstock['bill_no'], 'sales_outstock_undo', get_user_id(), '撤销出库恢复库存');
                }
                // 删除出库记录
                $pdo->prepare("DELETE FROM sales_outstock_items WHERE outstock_id=?")->execute([$outstock['id']]);
                $pdo->prepare("DELETE FROM sales_outstocks WHERE id=?")->execute([$outstock['id']]);
                add_log(get_user_id(), 'undo', 'sales_outstock', "撤销出库并删除出库单: {$outstock['bill_no']}");
            }
            // 恢复订单状态
            $pdo->prepare("UPDATE sales_orders SET status='confirmed' WHERE id=?")->execute([$oid]);
            add_log(get_user_id(), 'update', 'sales_order', "撤销出库恢复订单: {$order['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Undo outstock error: '.$e->getMessage()); }
    }
    redirect("order.php?page=$page");
}

// 删除订单（仅admin）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$oid]);
        $pdo->prepare("DELETE FROM sales_orders WHERE id=?")->execute([$oid]);
        add_log(get_user_id(), 'delete', 'sales_order', "删除订单: ID=$oid");
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); }
    redirect("order.php?page=$page");
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> 销售订单</h1>
    <a href="order_form.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 新增销售订单</a>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/客户..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="order.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>仓库</th><th>金额</th><th>已收</th><th>业务员</th><th>日期</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><a href="order_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['bill_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['customer_name']?:'-') ?></td>
    <td><?= htmlspecialchars($item['warehouse_name']?:'-') ?></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td style="color:var(--success)">¥<?= format_money($item['received_amount']) ?></td>
    <td><?= htmlspecialchars($item['employee_name']?:'-') ?></td>
    <td><?= $item['order_date'] ?></td>
    <td><span class="badge badge-<?= $statusBadges[$item['status']]??'gray' ?>"><?= $statusLabels[$item['status']]??$item['status'] ?></span></td>
    <td>
        <div class="table-actions">
            <?php if ($item['status'] === 'draft'): ?>
            <?php if ($isAdmin): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success">确认</button></form>
            <a href="order_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <form method="post" style="display:inline" onsubmit="return confirm('确定删除该订单吗？删除后不可恢复。')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-danger">删除</button></form>
            <?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'confirmed'): ?>
            <?php if ($isAdmin): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm" style="color:var(--gray-400);border-color:var(--gray-300);background:var(--gray-50);">已锁定</button></form><?php endif; ?>
            <?php if ($isAdmin): ?><a href="../sales/outstock.php?from_order=<?=$item['id']?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-truck-fast"></i> 出库</a><?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'shipped'): ?>
            <?php if ($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('确定撤销出库吗？库存将恢复，出库记录将被删除。')"><?= csrf_field() ?><input type="hidden" name="action" value="undo_outstock"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success">已出库</button></form><?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无销售订单</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
