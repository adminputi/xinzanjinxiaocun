<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('sales_return');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT sr.*, c.name as customer_name, c.phone as customer_phone, w.name as warehouse_name FROM sales_returns sr LEFT JOIN customers c ON sr.customer_id=c.id LEFT JOIN warehouses w ON sr.warehouse_id=w.id WHERE sr.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('退货单不存在');
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($order['user_id'] ?? 0) != get_user_id()) die('无权查看此记录');

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_return_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.return_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'confirm' && $order['status'] === 'draft') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE sales_returns SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
            foreach ($items as $it) {
                update_inventory($it['product_id'], $order['warehouse_id'], $it['quantity'], 'in', $order['bill_no'], 'sales_return', get_user_id());
            }
            add_log(get_user_id(), 'confirm', 'sales_return', "确认退货: {$order['bill_no']}");
            $pdo->commit();
            redirect("return_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Return view confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
    } elseif ($postAction === 'withdraw' && $order['status'] === 'confirmed') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE sales_returns SET status='draft' WHERE id=?")->execute([$id]);
            foreach ($items as $it) {
                update_inventory($it['product_id'], $order['warehouse_id'], -$it['quantity'], 'out', $order['bill_no'], 'sales_return_cancel', get_user_id(), '撤回退货');
            }
            add_log(get_user_id(), 'withdraw', 'sales_return', "撤回退货: {$order['bill_no']}");
            $pdo->commit();
            redirect("return_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Return view withdraw error: ' . $e->getMessage()); $error = '撤回失败，请查看系统日志'; }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 销售退货单详情</h1>
    <div class="page-actions">
        <?php if ($order['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认退货后库存将增加，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> 确认退货</button>
        </form>
        <a href="return.php?edit=<?=$id?>" class="btn btn-outline"><i class="fa-solid fa-pen"></i> 编辑</a>
        <?php endif; ?>
        <?php if ($order['status'] === 'confirmed'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将减少，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-undo"></i> 撤回</button>
        </form>
        <?php endif; ?>
        <a href="return.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">销售退货单 #<?= $order['bill_no'] ?></h3>
        <span class="badge badge-<?= $order['status']=='confirmed'?'success':($order['status']=='cancelled'?'gray':'warning') ?>"><?= $order['status']=='confirmed'?'已确认':($order['status']=='cancelled'?'已取消':'草稿') ?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>客户：</strong><?= htmlspecialchars($order['customer_name']?:'-') ?></div>
            <div><strong>仓库：</strong><?= htmlspecialchars($order['warehouse_name']?:'-') ?></div>
            <div><strong>退货日期：</strong><?= $order['return_date'] ?></div>
            <div><strong>创建时间：</strong><?= $order['created_at'] ?></div>
            <div><strong>制单人：</strong><?= htmlspecialchars($order['user_id']?(get_user_name()):'系统') ?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): ?>
                <tr><td><?=$i++?></td><td><?=$item['sku']?></td><td><?=htmlspecialchars($item['product_name'])?></td><td><?=$item['spec']?:'-'?></td><td><?=$item['unit_name']?:'-'?></td><td><?=$item['quantity']?></td><td>¥<?=format_money($item['price'])?></td><td>¥<?=format_money($item['amount'])?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="8"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot><tr><td colspan="7" class="text-right"><strong>合计：</strong></td><td><strong>¥<?= format_money($order['total_amount']) ?></strong></td></tr></tfoot>
        </table></div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>备注：</strong><?= nl2br(htmlspecialchars($order['remark'])) ?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
