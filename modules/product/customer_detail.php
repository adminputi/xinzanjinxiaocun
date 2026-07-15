<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('customer_view');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);
$year = intval($_GET['year'] ?? date('Y'));

$st = $pdo->prepare("SELECT * FROM customers WHERE id=?");
$st->execute([$id]);
$customer = $st->fetch();
if (!$customer) die('客户不存在');

// 历史订单
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$st = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled')");
$st->execute([$id]);
$totalOrders = $st->fetchColumn();
$pages = ceil($totalOrders/$perPage);
$st = $pdo->prepare("SELECT * FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled') ORDER BY id DESC LIMIT $offset,$perPage");
$st->execute([$id]);
$orders = $st->fetchAll();

// 出库记录
$st = $pdo->prepare("SELECT so.*, w.name as warehouse_name FROM sales_outstocks so LEFT JOIN warehouses w ON so.warehouse_id=w.id WHERE so.customer_id=? AND so.status='confirmed' ORDER BY so.id DESC LIMIT 50");
$st->execute([$id]);
$outstocks = $st->fetchAll();

// 退货记录
$st = $pdo->prepare("SELECT sr.*, w.name as warehouse_name FROM sales_returns sr LEFT JOIN warehouses w ON sr.warehouse_id=w.id WHERE sr.customer_id=? AND sr.status='confirmed' ORDER BY sr.id DESC LIMIT 20");
$st->execute([$id]);
$returns = $st->fetchAll();

// 收款记录
$st = $pdo->prepare("SELECT * FROM receipts WHERE customer_id=? ORDER BY id DESC LIMIT 20");
$st->execute([$id]);
$receipts = $st->fetchAll();

// 总订单金额
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled')");
$st->execute([$id]);
$totalOrderAmt = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(received_amount),0) FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled')");
$st->execute([$id]);
$totalReceived = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE customer_id=?");
$st->execute([$id]);
$totalReceipt = $st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE customer_id=? AND status='confirmed'");
$st->execute([$id]);
$totalReturnAmt = $st->fetchColumn();
$initialBalance = floatval($customer['initial_balance'] ?? 0);

// 按月/季度/年统计
$st = $pdo->prepare("SELECT DATE_FORMAT(order_date,'%Y-%m') as ym, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as amt, COALESCE(SUM(received_amount),0) as recv FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled') AND YEAR(order_date)=? GROUP BY DATE_FORMAT(order_date,'%Y-%m') ORDER BY ym");
$st->execute([$id, $year]);
$monthlyStats = $st->fetchAll();
$st = $pdo->prepare("SELECT CONCAT(YEAR(order_date),'Q',QUARTER(order_date)) as q, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as amt, COALESCE(SUM(received_amount),0) as recv FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled') AND YEAR(order_date)=? GROUP BY q ORDER BY q");
$st->execute([$id, $year]);
$quarterlyStats = $st->fetchAll();
$st = $pdo->prepare("SELECT YEAR(order_date) as yr, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as amt, COALESCE(SUM(received_amount),0) as recv FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled') GROUP BY yr ORDER BY yr DESC");
$st->execute([$id]);
$yearlyStats = $st->fetchAll();

$statusLabels = ['draft'=>'草稿','confirmed'=>'已确认','shipped'=>'已发货','partial'=>'部分完成','completed'=>'已完成','cancelled'=>'已取消'];
$statusBadge = ['draft'=>'warning','confirmed'=>'info','shipped'=>'primary','partial'=>'warning','completed'=>'success','cancelled'=>'gray'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-user"></i> 客户详情</h1>
    <a href="customer.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
</div>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><?=htmlspecialchars($customer['name'])?></h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div><strong>编码：</strong><?=htmlspecialchars($customer['code']?:'-')?></div>
            <div><strong>类型：</strong><?=$customer['type']=='individual'?'个人':'企业'?></div>
            <div><strong>联系人：</strong><?=htmlspecialchars($customer['contact']?:'-')?></div>
            <div><strong>电话：</strong><?=htmlspecialchars($customer['phone']?:'-')?></div>
            <div><strong>邮箱：</strong><?=htmlspecialchars($customer['email']?:'-')?></div>
            <div><strong>地址：</strong><?=htmlspecialchars($customer['address']?:'-')?></div>
            <div><strong>期初应收：</strong>¥<?=format_money($customer['initial_balance'])?></div>
            <div><strong>创建时间：</strong><?=$customer['created_at']?></div>
        </div>
    </div>
</div>

<!-- 财务统计卡片 -->
<div class="stats-grid mb-3">
    <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div><div class="stat-content"><div class="stat-label">订单总额+期初</div><div class="stat-value">¥<?=format_money($totalOrderAmt+$initialBalance)?></div></div></div>
    <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div><div class="stat-content"><div class="stat-label">订单已收</div><div class="stat-value">¥<?=format_money($totalReceived)?></div></div></div>
    <div class="stat-card"><div class="stat-icon cyan"><i class="fa-solid fa-money-bill-wave"></i></div><div class="stat-content"><div class="stat-label">收款总额</div><div class="stat-value">¥<?=format_money($totalReceipt)?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fa-solid fa-balance-scale"></i></div><div class="stat-content"><div class="stat-label">应收余额</div><div class="stat-value">¥<?=format_money($totalOrderAmt + $initialBalance - $totalReceived - $totalReturnAmt)?></div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-rotate-left"></i></div><div class="stat-content"><div class="stat-label">退货总额</div><div class="stat-value">¥<?=format_money($totalReturnAmt)?></div></div></div>
</div>

<!-- 年度统计 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-line"></i> <?=$year?>年 销售统计</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <!-- 月度统计 -->
            <div>
                <h4 style="margin:0 0 10px 0;font-size:14px;color:var(--gray-600);">月度统计</h4>
                <div class="table-container"><table>
                    <thead><tr><th>月份</th><th>订单数</th><th>金额</th><th>已收</th></tr></thead>
                    <tbody>
                    <?php if($monthlyStats): foreach($monthlyStats as $m): ?>
                    <tr><td><?=$m['ym']?></td><td><?=$m['cnt']?></td><td>¥<?=format_money($m['amt'])?></td><td>¥<?=format_money($m['recv'])?></td></tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
            <!-- 季度统计 -->
            <div>
                <h4 style="margin:0 0 10px 0;font-size:14px;color:var(--gray-600);">季度统计</h4>
                <div class="table-container"><table>
                    <thead><tr><th>季度</th><th>订单数</th><th>金额</th><th>已收</th></tr></thead>
                    <tbody>
                    <?php if($quarterlyStats): foreach($quarterlyStats as $q): ?>
                    <tr><td><?=$q['q']?></td><td><?=$q['cnt']?></td><td>¥<?=format_money($q['amt'])?></td><td>¥<?=format_money($q['recv'])?></td></tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
        <!-- 年度汇总 -->
        <h4 style="margin:16px 0 10px 0;font-size:14px;color:var(--gray-600);">年度统计（历年汇总）</h4>
        <div class="table-container"><table>
            <thead><tr><th>年份</th><th>订单数</th><th>金额</th><th>已收</th><th>回款率</th></tr></thead>
            <tbody>
            <?php if($yearlyStats): foreach($yearlyStats as $y): ?>
            <tr><td><strong><?=$y['yr']?></strong></td><td><?=$y['cnt']?></td><td>¥<?=format_money($y['amt'])?></td><td>¥<?=format_money($y['recv'])?></td><td><?=$y['amt']>0?round($y['recv']/$y['amt']*100,1).'%':'0%'?></td></tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">暂无数据</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 订单列表 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-list"></i> 历史订单 (<?=$totalOrders?>)</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>日期</th><th>金额</th><th>已收</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($orders): foreach($orders as $o): ?>
            <tr>
                <td><strong><?=$o['bill_no']?></strong></td>
                <td><?=$o['order_date']?></td>
                <td>¥<?=format_money($o['total_amount'])?></td>
                <td style="color:var(--success)">¥<?=format_money($o['received_amount'])?></td>
                <td><span class="badge badge-<?=$statusBadge[$o['status']]?>"><?=$statusLabels[$o['status']]?></span></td>
                <td><a href="../sales/order_view.php?id=<?=$o['id']?>" class="btn btn-sm btn-outline" title="查看"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6"><div class="empty-state"><p>暂无订单</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php if($pages>1): ?><div class="pagination"><?php for($i=1;$i<=$pages;$i++): ?><a href="?id=<?=$id?>&page=<?=$i?>&year=<?=$year?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<!-- 出库记录 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-truck-fast"></i> 出库记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>仓库</th><th>日期</th><th>金额</th><th>操作</th></tr></thead>
            <tbody>
            <?php if($outstocks): foreach($outstocks as $os): ?>
            <tr>
                <td><strong><?=$os['bill_no']?></strong></td>
                <td><?=htmlspecialchars($os['warehouse_name']?:'-')?></td>
                <td><?=$os['outstock_date']?></td>
                <td>¥<?=format_money($os['total_amount'])?></td>
                <td><a href="../sales/outstock_view.php?id=<?=$os['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无出库记录</p></div></td></tr>
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
                <td><a href="../sales/return_view.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无退货记录</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 收款记录 -->
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-money-bill-wave"></i> 收款记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>单号</th><th>金额</th><th>方式</th><th>日期</th><th>关联单据</th></tr></thead>
            <tbody>
            <?php if($receipts): foreach($receipts as $rec): ?>
            <tr>
                <td><strong><?=$rec['bill_no']?></strong></td>
                <td style="color:var(--success)">¥<?=format_money($rec['amount'])?></td>
                <td><?=['cash'=>'现金','bank'=>'银行','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'][$rec['pay_method']]??$rec['pay_method']?></td>
                <td><?=$rec['receipt_date']?></td>
                <td><?=$rec['related_bill_no']?:'-'?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5"><div class="empty-state"><p>暂无收款记录</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
