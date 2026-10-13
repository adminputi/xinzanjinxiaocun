<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_order');

run_migrations();
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
if (!$isAdmin) { die('无权限：取消订单仅限管理员操作'); }

$id = intval($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT o.*, c.name AS customer_name FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE o.id=?");
$st->execute([$id]);
$order = $st->fetch();
if (!$order) { flash_set('订单不存在'); redirect('order.php'); }
if ($order['status'] === 'cancelled') { flash_set('该订单已取消，无需重复操作'); redirect('order.php'); }

$allocs   = get_order_receipt_allocations($id);
$blockers = contract_delivery_bills(intval($order['contract_id'] ?? 0));
$delivered = order_has_delivery($id);
$prepay   = get_customer_prepay(intval($order['customer_id']));

// ============ 提交取消 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_verify();
    $reason = trim($_POST['cancel_reason'] ?? '');
    $acts   = $_POST['alloc_action'] ?? [];
    if (!is_array($acts)) { $acts = []; }
    if ($reason === '') {
        flash_set('取消订单必须填写原因');
    } else {
        $pdo->beginTransaction();
        try {
            cancel_sales_order($id, $reason, $acts);
            $pdo->commit();
            flash_set('订单已取消：' . $order['bill_no'] . '（留痕不可删除，款项已按指定方式处理）', 'success');
            redirect('order.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('取消订单失败：' . $e->getMessage());
        }
    }
    redirect('order_cancel.php?id=' . $id);
}

$total    = floatval($order['total_amount']);
$received = floatval($order['received_amount']);
?>
<?php flash_show(); ?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-ban"></i> 取消销售订单</h1>
    <a href="order.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回订单列表</a>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">订单信息</h3></div>
    <div class="card-body">
        <table style="width:auto;">
            <tr><td style="padding:4px 20px 4px 0;color:var(--gray-600);">订单单号</td><td><strong><?= htmlspecialchars($order['bill_no']) ?></strong></td></tr>
            <tr><td style="padding:4px 20px 4px 0;color:var(--gray-600);">客户</td><td><?= htmlspecialchars($order['customer_name'] ?: '-') ?></td></tr>
            <tr><td style="padding:4px 20px 4px 0;color:var(--gray-600);">订单金额</td><td><strong>¥<?= format_money($total) ?></strong></td></tr>
            <tr><td style="padding:4px 20px 4px 0;color:var(--gray-600);">已收金额</td><td style="color:var(--success)">¥<?= format_money($received) ?></td></tr>
            <tr><td style="padding:4px 20px 4px 0;color:var(--gray-600);">当前状态</td><td><?= htmlspecialchars($order['status']) ?></td></tr>
        </table>

        <?php if ($delivered): ?>
            <div style="margin-top:12px;padding:10px 12px;border-radius:var(--radius);background:#fff5f5;border:1px solid #f5c2c7;color:#842029;font-size:13px;">
                该订单已出库，<strong>取消前必须先撤销出库</strong>。请到「销售订单」列表点击该单的「已出库（点此撤销）」按钮，撤销后再来取消。
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$delivered): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">

    <?php if ($allocs): ?>
    <div class="card">
        <div class="card-header"><h3 class="card-title">该订单名下的收款（必须逐笔指定处理方式）</h3></div>
        <div class="card-body">
            <div style="font-size:13px;color:var(--gray-600);margin-bottom:10px;">
                取消订单不能让钱悬空：请对每一笔选择去处。<br>
                <strong>转客户预收款</strong>：钱没退，留在该客户名下（当前预收款 ¥<?= format_money($prepay) ?>），以后开新订单可一键抵扣。<br>
                <strong>退款红冲</strong>：钱已退给客户，系统会生成退款记录并冲减相应金额。
            </div>
            <div class="table-container">
            <table>
                <thead><tr><th>收款单号</th><th>日期</th><th>金额</th><th>备注</th><th>处理方式</th></tr></thead>
                <tbody>
                <?php foreach ($allocs as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['bill_no']) ?></td>
                    <td><?= htmlspecialchars($a['receipt_date'] ?? '-') ?></td>
                    <td><strong>¥<?= format_money($a['amount']) ?></strong></td>
                    <td><?= htmlspecialchars($a['alloc_remark'] ?? '') ?></td>
                    <td>
                        <label style="margin-right:12px;">
                            <input type="radio" name="alloc_action[<?= $a['id'] ?>]" value="prepay" checked>
                            转客户预收款
                        </label>
                        <label>
                            <input type="radio" name="alloc_action[<?= $a['id'] ?>]" value="refund">
                            退款红冲（可退 ¥<?= format_money($a['refundable']) ?>）
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body" style="font-size:13px;color:var(--gray-600);">该订单名下没有已收记录，取消不涉及款项处理。</div></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3 class="card-title">取消原因</h3></div>
        <div class="card-body">
            <div class="form-group">
                <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="如：客户取消采购计划，双方协商一致终止"></textarea>
            </div>
            <div style="font-size:12px;color:var(--gray-600);margin-bottom:12px;">
                取消后订单状态变为「已取消」，单据保留不可删除；若该合同下已无其他有效订单，合同会自动退回「已生效」可再次编辑，来源报价单同步恢复可编辑。
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('确定取消该订单吗？取消后不可恢复。')">
                <i class="fa-solid fa-ban"></i> 确认取消订单
            </button>
            <a href="order.php" class="btn btn-outline">返回</a>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
