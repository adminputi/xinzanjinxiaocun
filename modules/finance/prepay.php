<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('finance_receive');

run_migrations();
$pdo = getDB();

$custId = intval($_GET['customer_id'] ?? 0);
$customers = get_options('customers', 'id', 'name', 'status=1');

/**
 * 预收款的本质：客户名下还没认领到订单的那部分收款。
 * 同一个表里的每一行只有三种归宿：
 *   order_id=0            → 还在预收池里（可用余额）
 *   order_id>0            → 已被抵扣到某张订单上（订单已收里）
 *   amount<0（退款红冲）  → 这部分钱已经退回客户了
 * 所以「来龙去脉」不需要第二套账，把这三类行都列出来就是完整流水。
 */
$summarySql = "SELECT a.customer_id,
        COALESCE(SUM(CASE WHEN a.amount>0 AND a.order_id=0 THEN a.amount END),0) AS pool,
        COALESCE(SUM(CASE WHEN a.amount>0 AND a.order_id>0 THEN a.amount END),0) AS to_order,
        COALESCE(SUM(CASE WHEN a.amount<0 THEN a.amount END),0) AS refunded
    FROM receipt_allocations a
    JOIN receipts r ON r.id = a.receipt_id
    WHERE r.status='confirmed'
    GROUP BY a.customer_id";

$summary = [];
foreach ($pdo->query($summarySql)->fetchAll() as $r) {
    $summary[intval($r['customer_id'])] = [
        'pool'     => floatval($r['pool']),
        'to_order' => floatval($r['to_order']),
        'refunded' => floatval($r['refunded']),
    ];
}

// 明细：只查被选中客户
$rows = [];
if ($custId > 0) {
    $st = $pdo->prepare("SELECT a.id, a.order_id, a.amount, a.remark AS alloc_remark, a.created_at, a.refund_of,
            r.bill_no, r.receipt_date, r.purpose, r.pay_method, r.remark AS receipt_remark,
            o.bill_no AS order_no
        FROM receipt_allocations a
        JOIN receipts r ON r.id = a.receipt_id
        LEFT JOIN sales_orders o ON o.id = a.order_id
        WHERE a.customer_id=? AND r.status='confirmed'
        ORDER BY a.id ASC");
    $st->execute([$custId]);
    $rows = $st->fetchAll();
}

$totalPool = 0.0;
foreach ($summary as $v) { $totalPool += $v['pool']; }
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-piggy-bank"></i> 预收款流水</h1>
    <div style="font-size:13px;color:var(--gray-600);">全公司预收款余额合计 <strong style="color:var(--success)">¥<?= format_money($totalPool) ?></strong></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">按客户查看预收款</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container">
    <table>
        <thead><tr><th>客户</th><th>可用预收款</th><th>已抵扣到订单</th><th>已退款</th><th>操作</th></tr></thead>
        <tbody>
        <?php if ($summary): foreach ($summary as $cid => $v): if (!$cid) continue; ?>
        <tr>
            <td><?= htmlspecialchars($customers[$cid] ?? ('客户#' . $cid)) ?></td>
            <td style="color:var(--success);font-weight:bold;">¥<?= format_money($v['pool']) ?></td>
            <td>¥<?= format_money($v['to_order']) ?></td>
            <td style="color:var(--danger);">¥<?= format_money(abs($v['refunded'])) ?></td>
            <td><a class="btn btn-sm btn-outline" href="prepay.php?customer_id=<?= $cid ?>">查看流水</a></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-piggy-bank"></i><p>暂无预收款</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div></div>
</div>

<?php if ($custId > 0): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title">流水明细：<?= htmlspecialchars($customers[$custId] ?? ('客户#' . $custId)) ?></h3>
        <a href="prepay.php" class="btn btn-sm btn-outline">收起</a>
    </div>
    <div class="card-body">
        <div style="font-size:13px;color:var(--gray-600);margin-bottom:8px;">
            「归属」列说明这笔钱现在在哪：<strong>预收款</strong>=还留在池里可抵扣后续订单；<strong>订单号</strong>=已经抵扣到该订单；<strong>退款</strong>=已退回客户。
        </div>
        <div class="table-container">
        <table>
            <thead><tr><th>收款单号</th><th>收款日期</th><th>用途</th><th>金额</th><th>归属</th><th>备注</th></tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $a): ?>
            <tr<?= $a['amount'] < 0 ? ' style="color:var(--danger)"' : '' ?>>
                <td><?= htmlspecialchars($a['bill_no']) ?></td>
                <td><?= htmlspecialchars($a['receipt_date'] ?? '-') ?></td>
                <td><?= htmlspecialchars($a['purpose'] ?? '') ?></td>
                <td><strong><?= $a['amount'] < 0 ? '-' : '' ?>¥<?= format_money(abs($a['amount'])) ?></strong></td>
                <td>
                    <?php if ($a['amount'] < 0): ?>
                        <span class="badge badge-danger">退款退回客户</span>
                    <?php elseif ($a['order_id'] > 0): ?>
                        <span class="badge badge-info">已抵扣：<?= htmlspecialchars($a['order_no']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-success">预收款（可用）</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:260px;white-space:pre-line;word-break:break-all;"><?= htmlspecialchars($a['alloc_remark'] ?: ($a['receipt_remark'] ?? '') ?: '-') ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p>该客户暂无收款流水</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
