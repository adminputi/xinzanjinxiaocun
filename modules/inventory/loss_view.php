<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('loss_manage');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT l.*, w.name as warehouse_name FROM loss_orders l LEFT JOIN warehouses w ON l.warehouse_id=w.id WHERE l.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('单据不存在');

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec FROM loss_items i JOIN products p ON i.product_id=p.id WHERE i.loss_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

// 处理操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'confirm' && $order['status'] === 'draft') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE loss_orders SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
            foreach ($items as $it) {
                $changeQty = $order['type']=='loss' ? -$it['quantity'] : $it['quantity'];
                update_inventory($it['product_id'], $order['warehouse_id'], $changeQty, 'loss', $order['bill_no'], 'loss', get_user_id(), $it['reason']??'');
            }
            add_log(get_user_id(), 'confirm', 'loss_order', "确认报损报溢: {$order['bill_no']}");
            $pdo->commit();
            redirect("loss_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Loss view confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
    } elseif ($postAction === 'withdraw' && $order['status'] === 'confirmed') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE loss_orders SET status='draft' WHERE id=?")->execute([$id]);
            foreach ($items as $it) {
                $reverseQty = $order['type']=='loss' ? $it['quantity'] : -$it['quantity'];
                update_inventory($it['product_id'], $order['warehouse_id'], $reverseQty, 'loss', $order['bill_no'], 'loss', get_user_id(), '撤回报损报溢');
            }
            add_log(get_user_id(), 'withdraw', 'loss_order', "撤回报损报溢: {$order['bill_no']}");
            $pdo->commit();
            redirect("loss_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Loss view withdraw error: ' . $e->getMessage()); $error = '撤回失败，请查看系统日志'; }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 报损报溢详情 #<?=$order['bill_no']?></h1>
    <div class="page-actions">
        <?php if ($order['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认后库存将变更，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> 确认</button>
        </form>
        <a href="loss.php?edit=<?=$id?>" class="btn btn-outline"><i class="fa-solid fa-pen"></i> 编辑</a>
        <?php endif; ?>
        <?php if ($order['status'] === 'confirmed'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-undo"></i> 撤回</button>
        </form>
        <?php endif; ?>
        <a href="loss.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">报损报溢单 #<?=$order['bill_no']?></h3>
        <span class="badge badge-<?=$order['status']=='confirmed'?'success':($order['status']=='cancelled'?'gray':'warning')?>"><?=$order['status']=='confirmed'?'已确认':($order['status']=='cancelled'?'已取消':'草稿')?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>仓库：</strong><?=htmlspecialchars($order['warehouse_name']?:'-')?></div>
            <div><strong>类型：</strong><span class="badge badge-<?=$order['type']=='loss'?'danger':'success'?>"><?=$order['type']=='loss'?'报损（减少库存）':'报溢（增加库存）'?></span></div>
            <div><strong>日期：</strong><?=$order['order_date']?></div>
            <div><strong>创建时间：</strong><?=$order['created_at']?></div>
            <div><strong>制单人：</strong><?=htmlspecialchars($order['user_id']?(get_user_name()):'系统')?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>数量</th><th>单价</th><th>金额</th><th>原因</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): ?>
                <tr>
                    <td><?=$i++?></td>
                    <td><?=$item['sku']?></td>
                    <td><strong><?=htmlspecialchars($item['product_name'])?></strong></td>
                    <td><?=$item['spec']?:'-'?></td>
                    <td><?=$item['quantity']?></td>
                    <td>¥<?=format_money($item['amount']/($item['quantity']?:1))?></td>
                    <td>¥<?=format_money($item['amount'])?></td>
                    <td><?=htmlspecialchars($item['reason']?:'-')?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="8"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <?php 
                $totalAmt = array_sum(array_column($items, 'amount'));
                ?>
                <tr><td colspan="6" class="text-right"><strong>合计：</strong></td><td><strong>¥<?=format_money($totalAmt)?></strong></td><td></td></tr>
            </tfoot>
        </table></div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>备注：</strong><?=nl2br(htmlspecialchars($order['remark']))?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
