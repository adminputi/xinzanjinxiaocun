<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('supplier_view');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
$st->execute([$id]);
$supplier = $st->fetch();
if (!$supplier) die('供应商不存在');

// 采购订单
$st = $pdo->prepare("SELECT * FROM purchase_orders WHERE supplier_id=? AND status NOT IN('draft','cancelled') ORDER BY id DESC LIMIT 30");
$st->execute([$id]);
$orders = $st->fetchAll();

// 采购入库记录
$st = $pdo->prepare("SELECT pi.*, w.name as warehouse_name FROM purchase_instocks pi LEFT JOIN warehouses w ON pi.warehouse_id=w.id WHERE pi.supplier_id=? AND pi.status='confirmed' ORDER BY pi.id DESC LIMIT 30");
$st->execute([$id]);
$instocks = $st->fetchAll();

// 采购退货记录
$st = $pdo->prepare("SELECT pr.*, w.name as warehouse_name FROM purchase_returns pr LEFT JOIN warehouses w ON pr.warehouse_id=w.id WHERE pr.supplier_id=? AND pr.status='confirmed' ORDER BY pr.id DESC LIMIT 20");
$st->execute([$id]);
$returns = $st->fetchAll();

// 付款记录
$st = $pdo->prepare("SELECT * FROM payments WHERE supplier_id=? ORDER BY id DESC LIMIT 20");
$st->execute([$id]);
$payments = $st->fetchAll();

// 总采购金额
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE supplier_id=? AND status NOT IN('draft','cancelled')");
$st->execute([$id]);
$totalOrderAmt = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM purchase_orders WHERE supplier_id=? AND status NOT IN('draft','cancelled')");
$st->execute([$id]);
$totalPaid = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE supplier_id=?");
$st->execute([$id]);
$totalPayment = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_instocks WHERE supplier_id=? AND status='confirmed'");
$st->execute([$id]);
$totalInstockAmt = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE supplier_id=? AND status='confirmed'");
$st->execute([$id]);
$totalReturnAmt = $st->fetchColumn();

$statusLabels = ['draft'=>'草稿','confirmed'=>'已确认','received'=>'已收货','partial'=>'部分完成','completed'=>'已完成','cancelled'=>'已取消'];
$statusBadge = ['draft'=>'warning','confirmed'=>'info','received'=>'primary','partial'=>'warning','completed'=>'success','cancelled'=>'gray'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-truck"></i> 供应商详情</h1>
    <a href="supplier.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
</div>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><?=htmlspecialchars($supplier['name'])?></h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div><strong>编码：</strong><?=htmlspecialchars($supplier['code']?:'-')?></div>
            <div><strong>联系人：</strong><?=htmlspecialchars($supplier['contact']?:'-')?></div>
            <div><strong>电话：</strong><?=htmlspecialchars($supplier['phone']?:'-')?></div>
            <div><strong>邮箱：</strong><?=htmlspecialchars($supplier['email']?:'-')?></div>
            <div><strong>地址：</strong><?=htmlspecialchars($supplier['address']?:'-')?></div>
            <div><strong>开户行：</strong><?=htmlspecialchars($supplier['bank_name']?:'-')?></div>
            <div><strong>银行账号：</strong><?=htmlspecialchars($supplier['bank_account']?:'-')?></div>
            <div><strong>税号：</strong><?=htmlspecialchars($supplier['tax_no']?:'-')?></div>
            <div><strong>创建时间：</strong><?=$supplier['created_at']?></div>
        </div>
    </div>
</div>

<div class="stats-grid mb-3">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div><div class="stat-content"><div class="stat-label">采购订单总额</div><div class="stat-value">¥<?=format_money($totalOrderAmt)?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div><div class="stat-content"><div class="stat-label">已付款金额</div><div class="stat-value">¥<?=format_money($totalPaid)?></div></div></div>
    <div class="stat-card"><div class="stat-icon cyan"><i class="fa-solid fa-boxes-packing"></i></div><div class="stat-content"><div class="stat-label">入库总额</div><div class="stat-value">¥<?=format_money($totalInstockAmt)?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-balance-scale"></i></div><div class="stat-content"><div class="stat-label">应付余额</div><div class="stat-value">¥<?=format_money($totalOrderAmt - $totalPaid - $totalReturnAmt)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-rotate-left"></i></div><div class="stat-content"><div class="stat-label">退货总额</div><div class="stat-value">¥<?=format_money($totalReturnAmt)?></div></div></div>
</div>

<!-- 采购订单 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-list"></i> 采购订单</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>日期</th><th>金额</th><th>已付</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($orders): foreach($orders as $o): ?>
            <tr>
                <td><strong><?=$o['bill_no']?></strong></td>
                <td><?=$o['order_date']?></td>
                <td>¥<?=format_money($o['total_amount'])?></td>
                <td style="color:var(--success)">¥<?=format_money($o['paid_amount'])?></td>
                <td><span class="badge badge-<?=$statusBadge[$o['status']]?>"><?=$statusLabels[$o['status']]?></span></td>
                <td><a href="../purchase/order_view.php?id=<?=$o['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6"><div class="empty-state"><p>暂无采购订单</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 入库记录 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-boxes-packing"></i> 入库记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>仓库</th><th>日期</th><th>金额</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($instocks): foreach($instocks as $is): ?>
            <tr>
                <td><strong><?=$is['bill_no']?></strong></td>
                <td><?=htmlspecialchars($is['warehouse_name']?:'-')?></td>
                <td><?=$is['instock_date']?></td>
                <td>¥<?=format_money($is['total_amount'])?></td>
                <td><a href="../purchase/instock_view.php?id=<?=$is['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无入库记录</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 退货记录 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-rotate-left"></i> 退货记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>仓库</th><th>日期</th><th>金额</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($returns): foreach($returns as $r): ?>
            <tr>
                <td><strong><?=$r['bill_no']?></strong></td>
                <td><?=htmlspecialchars($r['warehouse_name']?:'-')?></td>
                <td><?=$r['return_date']?></td>
                <td style="color:var(--danger)">¥<?=format_money($r['total_amount'])?></td>
                <td><a href="../purchase/return_view.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无退货记录</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 付款记录 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-credit-card"></i> 付款记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>金额</th><th>方式</th><th>日期</th><th>关联单据</th></tr></thead>
            <tbody>
            <?php if($payments): foreach($payments as $pay): ?>
            <tr>
                <td><strong><?=$pay['bill_no']?></strong></td>
                <td style="color:var(--danger)">¥<?=format_money($pay['amount'])?></td>
                <td><?=['cash'=>'现金','bank'=>'银行','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'][$pay['pay_method']]??$pay['pay_method']?></td>
                <td><?=$pay['payment_date']?></td>
                <td><?=$pay['related_bill_no']?:'-'?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无付款记录</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
