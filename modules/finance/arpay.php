<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance_arpay');
$pdo = getDB();

// 应收汇总（含期初应收，减已确认退货）
$receivables = $pdo->query("SELECT c.id, c.name as customer_name, c.phone, c.initial_balance,
    COALESCE(SUM(so.total_amount),0) as order_total,
    COALESCE(SUM(so.received_amount),0) as received_total,
    (SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE customer_id=c.id AND status='confirmed') as return_total,
    COUNT(DISTINCT so.id) as order_count
FROM customers c
LEFT JOIN sales_orders so ON c.id=so.customer_id AND so.status NOT IN('draft','cancelled')
WHERE c.status=1
GROUP BY c.id HAVING COALESCE(SUM(so.total_amount),0)+c.initial_balance > 0 ORDER BY (COALESCE(SUM(so.total_amount),0)-COALESCE(SUM(so.received_amount),0)+c.initial_balance-(SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE customer_id=c.id AND status='confirmed')) DESC")->fetchAll();

// 应付汇总（减已确认退货）
$payables = $pdo->query("SELECT s.id, s.name as supplier_name, s.phone,
    COALESCE(SUM(po.total_amount),0) as order_total,
    COALESCE(SUM(po.paid_amount),0) as paid_total,
    (SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE supplier_id=s.id AND status='confirmed') as return_total,
    COUNT(DISTINCT po.id) as order_count
FROM suppliers s
LEFT JOIN purchase_orders po ON s.id=po.supplier_id AND po.status NOT IN('draft','cancelled')
WHERE s.status=1
GROUP BY s.id HAVING COALESCE(SUM(po.total_amount),0) > 0 ORDER BY (COALESCE(SUM(po.total_amount),0)-COALESCE(SUM(po.paid_amount),0)-(SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE supplier_id=s.id AND status='confirmed')) DESC")->fetchAll();

$totalAR = array_sum(array_map(function($r){return $r['order_total']-$r['received_total']+$r['initial_balance']-$r['return_total'];}, $receivables));
$totalAP = array_sum(array_map(function($p){return $p['order_total']-$p['paid_total']-$p['return_total'];}, $payables));
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-scale-balanced"></i> 应收应付</h1>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-file-invoice-dollar"></i></div><div class="stat-content"><div class="stat-label">应收账款</div><div class="stat-value">¥<?=format_money($totalAR)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-file-invoice"></i></div><div class="stat-content"><div class="stat-label">应付账款</div><div class="stat-value">¥<?=format_money($totalAP)?></div></div></div>
</div>

<div class="tabs">
    <div class="tab-item active" onclick="switchTab('ar',event)">应收账款</div>
    <div class="tab-item" onclick="switchTab('ap',event)">应付账款</div>
</div>

<!-- 应收账款 -->
<div class="tab-content active" data-tab="ar">
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
    <table>
    <thead><tr><th>客户</th><th>电话</th><th>订单数</th><th>订单金额</th><th>已收金额</th><th>应收余额</th></tr></thead>
    <tbody>
    <?php if ($receivables): foreach ($receivables as $r): $bal = $r['order_total'] - $r['received_total'] + $r['initial_balance'] - $r['return_total']; ?>
    <tr>
        <td><strong><?=htmlspecialchars($r['customer_name'])?></strong></td>
        <td><?=htmlspecialchars($r['phone']?:'-')?></td>
        <td><span class="badge badge-info"><?=$r['order_count']?></span></td>
        <td>¥<?=format_money($r['order_total']+$r['initial_balance'])?></td>
        <td style="color:var(--success)">¥<?=format_money($r['received_total'])?></td>
        <td style="color:<?=$bal>0?'var(--danger)':'var(--success)'?>;font-weight:bold;">¥<?=format_money($bal)?></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无应收账款</p></div></td></tr>
    <?php endif; ?>
    </tbody>
    </table></div></div></div>
</div>

<!-- 应付账款 -->
<div class="tab-content" data-tab="ap">
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
    <table>
    <thead><tr><th>供应商</th><th>电话</th><th>订单数</th><th>订单金额</th><th>已付金额</th><th>应付余额</th></tr></thead>
    <tbody>
    <?php if ($payables): foreach ($payables as $p): $bal = $p['order_total'] - $p['paid_total'] - $p['return_total']; ?>
    <tr>
        <td><strong><?=htmlspecialchars($p['supplier_name'])?></strong></td>
        <td><?=htmlspecialchars($p['phone']?:'-')?></td>
        <td><span class="badge badge-info"><?=$p['order_count']?></span></td>
        <td>¥<?=format_money($p['order_total'])?></td>
        <td style="color:var(--success)">¥<?=format_money($p['paid_total'])?></td>
        <td style="color:<?=$bal>0?'var(--danger)':'var(--success)'?>;font-weight:bold;">¥<?=format_money($bal)?></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>暂无应付账款</p></div></td></tr>
    <?php endif; ?>
    </tbody>
    </table></div></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
