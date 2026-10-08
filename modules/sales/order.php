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

/**
 * 删除销售订单后，把上游逐级回退，否则整条链会卡死：
 * 合同停在执行中/已完成 → 编辑不了；报价单停在「已转合同」→ 也编辑不了。
 * 只在「该合同已无任何有效订单」时才回退，所以一份合同分多次下单、只删其中一单不会误退。
 * 必须在调用方的事务内执行。
 */
function rollback_upstream_on_order_delete($pdo, $order) {
    $cid = intval($order['contract_id'] ?? 0);
    if (!$cid) return;

    $st = $pdo->prepare("SELECT * FROM sales_contracts WHERE id=?");
    $st->execute([$cid]);
    $contract = $st->fetch();
    if (!$contract) return;
    // 已终止的合同不复活：终止是明确的业务动作，不该被删订单悄悄撤销
    if (!in_array($contract['status'], ['executing', 'completed'], true)) return;

    // 本单已删，这里数的是剩下的有效订单
    $stc = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE contract_id=? AND status<>'cancelled'");
    $stc->execute([$cid]);
    if (intval($stc->fetchColumn()) > 0) {
        // 还有别的订单在履约，合同维持执行中
        $pdo->prepare("UPDATE sales_contracts SET status='executing' WHERE id=?")->execute([$cid]);
        return;
    }

    // 回退一级：回到「转销售订单的上一步」——已生效，可再次编辑、可再次转单
    $pdo->prepare("UPDATE sales_contracts SET status='confirmed' WHERE id=?")->execute([$cid]);

    // 再向上回退一级：报价单恢复可编辑（合同明细取自报价单，改明细要能从这里改）。
    // 保留 contract_id 不清空——关联合同还在，且「转合同」靠它避免重复生成第二份合同
    if (!empty($contract['quote_id'])) {
        $pdo->prepare("UPDATE sales_quotes SET status='draft' WHERE id=? AND contract_id=? AND status='contracted'")
            ->execute([$contract['quote_id'], $cid]);
    }

    add_log(get_user_id(), 'update', 'sales_contract',
        "删除订单后回退上游: 合同 {$contract['contract_no']} → 已生效（可再次编辑/转单），来源报价单恢复可编辑");
}

// 删除订单（仅admin；无下游单据、无已收金额才可删，已生效的走「取消订单」留痕）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $st->execute([$oid]);
    $order = $st->fetch();
    if (!$order) {
        flash_set('订单不存在');
    } else {
        $chk = check_refs($oid, [
            '销售出库单' => "SELECT COUNT(*) FROM sales_outstocks WHERE order_id=?",
            '销售报价'   => "SELECT COUNT(*) FROM sales_quotes WHERE order_id=?",
            '收款单'     => "SELECT COUNT(*) FROM receipts WHERE order_id=?",
        ]);
        if (!$chk['ok']) {
            flash_set($chk['msg'] . ' 如需终止该订单，请改用「取消订单」。');
        } elseif (floatval($order['received_amount'] ?? 0) > 0) {
            flash_set('该订单已收款 ¥' . format_money($order['received_amount']) . '，不允许删除（删除会让已收金额凭空消失）。');
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$oid]);
                $pdo->prepare("DELETE FROM sales_orders WHERE id=?")->execute([$oid]);
                // 同步回退上游（合同 → 报价单），否则合同会一直卡在执行中改不了
                rollback_upstream_on_order_delete($pdo, $order);
                add_log(get_user_id(), 'delete', 'sales_order', "删除订单: {$order['bill_no']}(ID:$oid)");
                $pdo->commit();
                flash_set('订单已删除：' . $order['bill_no'], 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash_set('删除失败：' . $e->getMessage());
            }
        }
    }
    redirect("order.php?page=$page");
}
?>

<?php flash_show(); ?>
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
