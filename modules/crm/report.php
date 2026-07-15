<?php
/**
 * CRM 客户报表
 * 功能：客户统计、来源分析、跟进统计、销售业绩等
 */
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_report');
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$ownerId = intval($_GET['owner_id'] ?? 0);

// 用户过滤条件
$userWhere = '';
$userParams = [];
if (!$isAdmin) {
    $userWhere = " AND owner_id=?";
    $userParams = [$userId];
} elseif ($ownerId > 0) {
    $userWhere = " AND owner_id=?";
    $userParams = [$ownerId];
}

// 跟进过滤条件（按user_id）
$followUserWhere = '';
$followUserParams = [];
if (!$isAdmin) {
    $followUserWhere = " AND user_id=?";
    $followUserParams = [$userId];
} elseif ($ownerId > 0) {
    $followUserWhere = " AND user_id=?";
    $followUserParams = [$ownerId];
}

// 概览统计
$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE 1=1 $userWhere");
$stmt->execute($userParams);
$totalCustomers = $stmt->fetchColumn();

$poolCount = $pdo->query("SELECT COUNT(*) FROM customers WHERE in_pool=1")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE 1=1 $userWhere AND created_at>=? AND created_at<=?");
$stmt->execute(array_merge($userParams, [$dateFrom, $dateTo.' 23:59:59']));
$newCustomers = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_followups WHERE created_at>=? AND created_at<=? $followUserWhere");
$stmt->execute(array_merge([$dateFrom, $dateTo.' 23:59:59'], $followUserParams));
$followCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_followups WHERE created_at>=? AND created_at<=? AND result='已成交' $followUserWhere");
$stmt->execute(array_merge([$dateFrom, $dateTo.' 23:59:59'], $followUserParams));
$dealCount = $stmt->fetchColumn();

$salesWhere = " AND so.employee_id=?";
$salesParams = [$dateFrom, $dateTo.' 23:59:59'];
if (!$isAdmin) {
    $salesParams[] = $userId;
} elseif ($ownerId > 0) {
    $salesParams[] = $ownerId;
} else {
    $salesWhere = '';
}
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders so WHERE so.order_date>=? AND so.order_date<=? AND so.status NOT IN('draft','cancelled') $salesWhere");
$stmt->execute($salesParams);
$totalSales = $stmt->fetchColumn();

// 来源分布
$stmt = $pdo->prepare("SELECT cs.name, COUNT(c.id) as cnt FROM customers c LEFT JOIN customer_sources cs ON c.source_id=cs.id WHERE c.in_pool=0 $userWhere GROUP BY cs.id ORDER BY cnt DESC LIMIT 10");
$stmt->execute($userParams);
$sourceStats = $stmt->fetchAll();

// 意向分布
$intentionDist = $pdo->query("SELECT COALESCE(intention,'未知') as label, COUNT(*) as cnt FROM customers WHERE in_pool=0 GROUP BY intention")->fetchAll();

// 经理排名
$ownerRankStmt = $pdo->prepare("SELECT u.real_name, COUNT(c.id) as cnt FROM customers c LEFT JOIN users u ON c.owner_id=u.id WHERE 1=1 $userWhere AND c.created_at>=? AND c.created_at<=? GROUP BY c.owner_id ORDER BY cnt DESC LIMIT 10");
$ownerRankStmt->execute(array_merge($userParams, [$dateFrom, $dateTo.' 23:59:59']));
$ownerRank = $ownerRankStmt->fetchAll();

// 跟进类型分布
$stmt = $pdo->prepare("SELECT follow_type as label, COUNT(*) as cnt FROM customer_followups WHERE created_at>=? AND created_at<=? $followUserWhere GROUP BY follow_type ORDER BY cnt DESC");
$stmt->execute(array_merge([$dateFrom, $dateTo.' 23:59:59'], $followUserParams));
$followTypes = $stmt->fetchAll();

$users = $pdo->query("SELECT id,real_name FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-chart-simple"></i> 客户报表</h1>
</div>

<form class="filter-bar" method="get">
    <label>从 <input type="date" name="date_from" value="<?=$dateFrom?>" class="form-control" style="width:140px;"></label>
    <label>到 <input type="date" name="date_to" value="<?=$dateTo?>" class="form-control" style="width:140px;"></label>
    <?php if ($isAdmin): ?>
    <select name="owner_id" class="form-control" style="width:130px;">
        <option value="0">全部经理</option>
        <?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=$ownerId==$u['id']?'selected':''?>><?=htmlspecialchars($u['real_name'])?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="report.php" class="btn btn-outline btn-sm">重置</a>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px;">
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:32px;font-weight:bold;color:var(--primary);"><?=$totalCustomers?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">客户总数</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:32px;font-weight:bold;color:var(--warning);"><?=$poolCount?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">公海客户</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:32px;font-weight:bold;color:var(--success);"><?=$newCustomers?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">新增客户</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:32px;font-weight:bold;color:var(--info);"><?=$followCount?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">跟进次数</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:32px;font-weight:bold;color:var(--danger);"><?=$dealCount?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">成交数</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:28px;font-weight:bold;color:var(--primary);">¥<?=format_money($totalSales)?></div>
        <div style="font-size:13px;color:var(--gray-500);margin-top:4px;">销售额</div>
    </div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
        <div class="card-header" style="padding:12px 16px;border-bottom:1px solid var(--gray-200);font-weight:600;">客户来源分布</div>
        <div class="card-body" style="padding:16px;">
        <?php if ($sourceStats): $maxSrc = max(array_column($sourceStats,'cnt')?:[1]); ?>
        <table style="width:100%;font-size:13px;">
            <?php foreach($sourceStats as $s): ?>
            <tr><td style="padding:4px 0;"><?=htmlspecialchars($s['name'])?:'未设置'?></td><td style="text-align:right;padding:4px 8px;"><?=$s['cnt']?>个</td><td style="width:100px;padding:4px 0;"><div style="height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden;"><div style="height:100%;background:var(--primary);width:<?=max(1,round($s['cnt']/$maxSrc*100))?>%;border-radius:4px;"></div></div></td></tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?><div class="empty-state"><p>暂无数据</p></div><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header" style="padding:12px 16px;border-bottom:1px solid var(--gray-200);font-weight:600;">意向程度分布</div>
        <div class="card-body" style="padding:16px;">
        <?php if ($intentionDist): $totalInt = array_sum(array_column($intentionDist,'cnt')); $intColors = ['高'=>'var(--success)','中'=>'var(--warning)','低'=>'var(--info)','未知'=>'var(--gray-400)']; ?>
        <table style="width:100%;font-size:13px;">
            <?php foreach($intentionDist as $i): ?>
            <tr><td style="padding:4px 0;"><?=$i['label']?></td><td style="text-align:right;padding:4px 8px;"><?=$i['cnt']?>个</td><td style="width:100px;padding:4px 0;"><div style="height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden;"><div style="height:100%;background:<?=$intColors[$i['label']]??'var(--gray-400)'?>;width:<?=$totalInt>0?max(1,round($i['cnt']/$totalInt*100)):0?>%;border-radius:4px;"></div></div></td></tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?><div class="empty-state"><p>暂无数据</p></div><?php endif; ?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="card">
        <div class="card-header" style="padding:12px 16px;border-bottom:1px solid var(--gray-200);font-weight:600;">新增客户排名</div>
        <div class="card-body" style="padding:0;">
        <?php if ($ownerRank): ?>
        <table><thead><tr><th>排名</th><th>业务经理</th><th style="text-align:right;">新增客户</th></tr></thead><tbody>
        <?php $rank=1; foreach($ownerRank as $r): ?>
        <tr><td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:<?=$rank<=3?'var(--primary)':'var(--gray-300)'?>;color:<?=$rank<=3?'#fff':'var(--gray-600)'?>;font-size:12px;font-weight:bold;"><?=$rank?></span></td><td><?=htmlspecialchars($r['real_name'])?:'公海（未认领）'?></td><td style="text-align:right;font-weight:600;"><?=$r['cnt']?>个</td></tr>
        <?php $rank++; endforeach; ?>
        </tbody></table>
        <?php else: ?><div class="card-body"><div class="empty-state"><p>暂无数据</p></div></div><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header" style="padding:12px 16px;border-bottom:1px solid var(--gray-200);font-weight:600;">跟进类型分布</div>
        <div class="card-body" style="padding:0;">
        <?php if ($followTypes): $totalFt = array_sum(array_column($followTypes,'cnt')); ?>
        <table><thead><tr><th>方式</th><th style="text-align:right;">次数</th><th>占比</th></tr></thead><tbody>
        <?php foreach($followTypes as $ft): ?>
        <tr><td><?=$ft['label']?></td><td style="text-align:right;font-weight:600;"><?=$ft['cnt']?>次</td><td style="width:100px;"><div style="height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden;"><div style="height:100%;background:var(--primary);width:<?=$totalFt>0?max(1,round($ft['cnt']/$totalFt*100)):0?>%;border-radius:4px;"></div></div></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php else: ?><div class="card-body"><div class="empty-state"><p>暂无数据</p></div></div><?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
