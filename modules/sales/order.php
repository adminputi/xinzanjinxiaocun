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

$stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, w.name as warehouse_name, u.real_name as employee_name, (SELECT COUNT(*) FROM sales_outstocks WHERE order_id=o.id AND status='confirmed') as outstock_count FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN warehouses w ON o.warehouse_id=w.id LEFT JOIN users u ON o.employee_id=u.id $where ORDER BY o.id DESC LIMIT $offset,$perPage");
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

// 撤销出库（shipped → confirmed）仅admin + 必填原因
// Q1=B：出库单软删除（置 cancelled），库存回滚，单据与明细保留留痕
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'undo_outstock') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $reason = trim($_POST['cancel_reason']??'');
    $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $st->execute([$oid]);
    $order = $st->fetch();
    if (!$order) {
        flash_set('订单不存在');
    } elseif ($order['status'] !== 'shipped') {
        flash_set('该订单未出库，无需撤销');
    } elseif ($reason === '') {
        flash_set('撤销出库必须填写原因');
    } else {
        $outstock = get_order_active_outstock($oid);
        if (!$outstock) {
            // 状态仍是 shipped 却没有有效出库单（历史脏数据）：兜底把状态拉回已确认
            $pdo->prepare("UPDATE sales_orders SET status='confirmed' WHERE id=?")->execute([$oid]);
            add_log(get_user_id(), 'undo', 'sales_outstock', "订单 {$order['bill_no']} 无有效出库单，状态恢复为已确认");
            flash_set('未找到有效出库单，订单状态已恢复为「已确认」', 'success');
        } else {
            $pdo->beginTransaction();
            try {
                undo_sales_outstock(intval($outstock['id']), $reason);
                $pdo->commit();
                flash_set('已撤销出库 ' . $outstock['bill_no'] . '：库存已恢复，出库单已作废留痕，订单回到「已确认」', 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash_set('撤销出库失败：' . $e->getMessage());
            }
        }
    }
    redirect("order.php?page=$page");
}

// 取消订单（status='cancelled'，留痕不物理删）仅admin；逐笔收款的去处由界面指定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'cancel') {
    csrf_verify();
    if (!$isAdmin) die('无权限');
    $oid = intval($_POST['id']??0);
    $reason = trim($_POST['cancel_reason']??'');
    $acts = $_POST['alloc_action'] ?? [];
    if (!is_array($acts)) { $acts = []; }
    if ($reason === '') {
        flash_set('取消订单必须填写原因');
    } else {
        $pdo->beginTransaction();
        try {
            cancel_sales_order($oid, $reason, $acts);
            $pdo->commit();
            flash_set('订单已取消（款项已按指定方式处理，留痕不可删）：' . ($order['bill_no'] ?? ''), 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('取消订单失败：' . $e->getMessage());
        }
    }
    redirect("order.php?page=$page");
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
            '销售出库单' => "SELECT COUNT(*) FROM sales_outstocks WHERE order_id=? AND status='confirmed'",
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
                rollback_upstream_on_order_cancel($pdo, $order);
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
<thead><tr><th>单号</th><th>客户</th><th>仓库</th><th>金额</th><th>已收</th><th>待收</th><th>业务员</th><th>日期</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><a href="order_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['bill_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['customer_name']?:'-') ?></td>
    <td><?= htmlspecialchars($item['warehouse_name']?:'-') ?></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td style="color:var(--success)">¥<?= format_money($item['received_amount']) ?></td>
    <td style="color:var(--warning);font-weight:bold;">¥<?= format_money(max(0, floatval($item['total_amount']) - floatval($item['received_amount'] ?? 0))) ?></td>
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
            <?php if ($isAdmin): ?><a href="order_cancel.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" style="color:var(--danger);border-color:var(--danger)">取消订单</a><?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'shipped'): ?>
            <?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-success" onclick="openUndoOutstock(<?=$item['id']?>, '<?= htmlspecialchars($item['bill_no'], ENT_QUOTES) ?>')">已出库（点此撤销）</button><?php endif; ?>
            <a href="order_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无销售订单</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<?php if ($isAdmin): ?>
<!-- 撤销出库（软删除，保留出库单留痕） -->
<div class="modal-overlay" id="undoOutstockModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">撤销出库</h3><button class="modal-close" onclick="closeModal('undoOutstockModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="undo_outstock"><input type="hidden" name="id" id="undoId" value="0">
<div class="modal-body">
    <p style="margin-bottom:8px;">订单：<strong id="undoBillNo"></strong></p>
    <div class="form-group"><label class="form-label">撤销原因 <span class="required">*</span></label>
        <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="如：客户变更规格，先撤销出库再改合同"></textarea>
    </div>
    <div style="font-size:12px;color:var(--gray-600);">撤销后库存恢复到出库前，出库单置为「已撤销」并保留记录，订单回到「已确认」可再次出库，来源合同同步解锁。</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('undoOutstockModal')">关闭</button><button type="submit" class="btn btn-primary">确认撤销出库</button></div>
</form></div></div>
<script>
function openUndoOutstock(id, billNo) {
    document.getElementById('undoId').value = id;
    document.getElementById('undoBillNo').textContent = billNo;
    openModal('undoOutstockModal');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
