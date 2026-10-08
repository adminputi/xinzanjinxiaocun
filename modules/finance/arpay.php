<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance_arpay');
$pdo = getDB();

// 应收/应付一律走统一口径函数，保证与首页看板、账龄分析、客户对账算出同一个数
$arRows  = get_ar_by_customer(0);
$apRows  = get_ap_by_supplier(0);
$arTotal = get_ar_totals();
$apTotal = get_ap_totals();

// 保持原展示范围：有订单或有期初应收的客户才列入
$receivables = array_values(array_filter($arRows, function ($r) {
    return $r['order_total'] > 0 || abs($r['initial']) > 0.000001;
}));
$payables = array_values(array_filter($apRows, function ($p) {
    return $p['order_total'] > 0;
}));

$totalAR = $arTotal['balance'];
$totalAP = $apTotal['balance'];
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
    <?php if ($receivables): foreach ($receivables as $r): $bal = $r['balance']; ?>
    <tr>
        <td><strong><?=htmlspecialchars($r['name'])?></strong></td>
        <td><?=htmlspecialchars(($r['phone'] ?? '') ?: '-')?></td>
        <td><span class="badge badge-info"><?=$r['order_count']?></span></td>
        <td>¥<?=format_money($r['order_total']+$r['initial'])?></td>
        <td style="color:var(--success)">¥<?=format_money($r['received'])?></td>
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
    <?php if ($payables): foreach ($payables as $p): $bal = $p['balance']; ?>
    <tr>
        <td><strong><?=htmlspecialchars($p['name'])?></strong></td>
        <td><?=htmlspecialchars(($p['phone'] ?? '') ?: '-')?></td>
        <td><span class="badge badge-info"><?=$p['order_count']?></span></td>
        <td>¥<?=format_money($p['order_total'])?></td>
        <td style="color:var(--success)">¥<?=format_money($p['paid'])?></td>
        <td style="color:<?=$bal>0?'var(--danger)':'var(--success)'?>;font-weight:bold;">¥<?=format_money($bal)?></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>暂无应付账款</p></div></td></tr>
    <?php endif; ?>
    </tbody>
    </table></div></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
