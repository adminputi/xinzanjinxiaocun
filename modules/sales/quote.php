<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_quote');
$pdo = getDB();
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$isAdmin = (get_user_role() === 'admin');

$where = ''; $params = [];
$conditions = [];
if ($search) {
    $conditions[] = "(q.bill_no LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
// 非admin用户只看自己创建的报价单
if (!$isAdmin) {
    $conditions[] = "q.user_id = ?";
    $params[] = get_user_id();
}
if ($conditions) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, u.real_name as employee_name, so.status as order_status, so.bill_no as order_bill_no FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id LEFT JOIN users u ON q.employee_id=u.id LEFT JOIN sales_orders so ON q.order_id=so.id $where ORDER BY q.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$statusLabels = ['draft'=>'可编辑','quoted'=>'已转订单','withdrawn'=>'已撤回'];
$statusBadges = ['draft'=>'warning','quoted'=>'info','withdrawn'=>'gray'];

// 删除报价单（仅draft状态）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    csrf_verify();
    $qid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$qid]);
    $quote = $stmt->fetch();
    if (!$quote) die('报价单不存在');
    if (!$isAdmin && ($quote['user_id'] ?? 0) != get_user_id()) die('无权限');
    if ($quote['status'] !== 'draft') die('该报价单已转订单，无法删除');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$qid]);
        $pdo->prepare("DELETE FROM sales_quotes WHERE id=?")->execute([$qid]);
        add_log(get_user_id(), 'delete', 'sales_quote', "删除报价单: {$quote['bill_no']}");
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); }
    redirect("quote.php?page=$page");
}

// 转销售订单（draft/withdrawn → quoted）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'convert') {
    csrf_verify();
    $qid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$qid]);
    $quote = $stmt->fetch();
    if (!$quote) die('报价单不存在');
    if (!$isAdmin && ($quote['user_id'] ?? 0) != get_user_id()) die('无权限');
    if (!in_array($quote['status'], ['draft','withdrawn'])) die('当前状态不可转订单');
    
    $pdo->beginTransaction();
    try {
        // 获取报价明细
        $stmt2 = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=?");
        $stmt2->execute([$qid]);
        $items = $stmt2->fetchAll();
        if (empty($items)) { $pdo->rollBack(); die('报价单没有明细'); }
        
        // 创建销售订单（取第一个可用仓库）
        $orderBillNo = generate_bill_no('XS');
        $wh = $pdo->query("SELECT id FROM warehouses WHERE status=1 LIMIT 1")->fetch();
        $warehouseId = $wh ? $wh['id'] : 0;
        if (!$warehouseId) { $pdo->rollBack(); die('系统未设置仓库，请先在仓库管理中添加仓库'); }
        $pdo->prepare("INSERT INTO sales_orders (bill_no,customer_id,warehouse_id,total_amount,status,order_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$orderBillNo, $quote['customer_id'], $warehouseId, $quote['total_amount'], 'draft', $quote['quote_date'], $quote['employee_id'], $quote['remark'], get_user_id(), date('Y-m-d H:i:s')]);
        $orderId = $pdo->lastInsertId();
        
        // 插入订单明细
        $insStmt = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
        foreach ($items as $it) {
            $insStmt->execute([$orderId, $it['product_id'], $it['quantity'], $it['price'], $it['amount'], $it['remark']]);
        }
        
        // 更新报价单状态
        $pdo->prepare("UPDATE sales_quotes SET status='quoted', order_id=? WHERE id=?")->execute([$orderId, $qid]);
        add_log(get_user_id(), 'create', 'sales_quote', "报价单转订单: {$quote['bill_no']} → {$orderBillNo}");
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); error_log('Quote convert error: '.$e->getMessage()); die('转换失败: '.$e->getMessage()); }
    redirect("quote.php?page=$page");
}

// 撤回重新报价（quoted → withdrawn）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'withdraw') {
    csrf_verify();
    $qid = intval($_POST['id']??0);
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$qid]);
    $quote = $stmt->fetch();
    if (!$quote) die('报价单不存在');
    if (!$isAdmin && ($quote['user_id'] ?? 0) != get_user_id()) die('无权限');
    if ($quote['status'] !== 'quoted') die('当前状态不可撤回');
    if (!$quote['order_id']) die('未关联订单');
    
    // 检查关联订单状态
    $stmt2 = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt2->execute([$quote['order_id']]);
    $order = $stmt2->fetch();
    if (!$order) {
        // 订单已被手动删除，直接恢复报价单
        $pdo->prepare("UPDATE sales_quotes SET status='withdrawn', order_id=NULL WHERE id=?")->execute([$qid]);
        add_log(get_user_id(), 'update', 'sales_quote', "撤回报价单（订单已不存在）: {$quote['bill_no']}");
        redirect("quote.php?page=$page");
    }
    if ($order['status'] === 'shipped') {
        die('关联的销售订单已出库，无法撤回');
    }
    
    $pdo->beginTransaction();
    try {
        // 删除订单明细
        $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$quote['order_id']]);
        // 删除订单
        $pdo->prepare("DELETE FROM sales_orders WHERE id=?")->execute([$quote['order_id']]);
        // 恢复报价单
        $pdo->prepare("UPDATE sales_quotes SET status='withdrawn', order_id=NULL WHERE id=?")->execute([$qid]);
        add_log(get_user_id(), 'update', 'sales_quote', "撤回报价单: {$quote['bill_no']}，删除关联订单: {$order['bill_no']}");
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); error_log('Quote withdraw error: '.$e->getMessage()); }
    redirect("quote.php?page=$page");
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> 销售报价</h1>
    <a href="quote_form.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 新增销售报价</a>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/客户..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="quote.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>金额</th><th>业务员</th><th>报价日期</th><th>关联订单</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): 
    // 判断关联订单是否已出库
    $orderShipped = ($item['order_status'] === 'shipped');
?>
<tr>
    <td><a href="quote_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['bill_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['customer_name']?:'-') ?></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td><?= htmlspecialchars($item['employee_name']?:'-') ?></td>
    <td><?= $item['quote_date'] ?></td>
    <td><?= $item['order_bill_no'] ? '<a href="order_view.php?id='.$item['order_id'].'">'.htmlspecialchars($item['order_bill_no']).'</a>' : '-' ?></td>
    <td><span class="badge badge-<?= $statusBadges[$item['status']]??'gray' ?>"><?= $statusLabels[$item['status']]??$item['status'] ?></span></td>
    <td>
        <div class="table-actions">
            <?php if ($orderShipped): ?>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'draft'): ?>
            <a href="quote_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <form method="post" style="display:inline" onsubmit="return confirm('确定将该报价单转为销售订单吗？转换后报价单将被锁定。')"><?= csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success">转销售订单</button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('确定删除该报价单吗？删除后不可恢复。')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-danger">删除</button></form>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'quoted'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('确定撤回该报价吗？关联的销售订单将被删除，报价单可再次转订单。')"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-warning">撤回重新报价</button></form>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'withdrawn'): ?>
            <a href="quote_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <form method="post" style="display:inline" onsubmit="return confirm('确定重新转为销售订单吗？')"><?= csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-success">转销售订单</button></form>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无销售报价单</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
