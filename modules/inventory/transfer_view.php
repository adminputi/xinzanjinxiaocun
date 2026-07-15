<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('transfer_manage');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT t.*, w1.name as from_name, w2.name as to_name FROM transfers t LEFT JOIN warehouses w1 ON t.from_warehouse_id=w1.id LEFT JOIN warehouses w2 ON t.to_warehouse_id=w2.id WHERE t.id=?");
$stmt->execute([$id]);
$transfer = $stmt->fetch();
if (!$transfer) die('调拨单不存在');

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec FROM transfer_items i JOIN products p ON i.product_id=p.id WHERE i.transfer_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

// 处理操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'confirm' && $transfer['status'] === 'draft') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE transfers SET status='confirmed', created_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
            foreach ($items as $it) {
                update_inventory($it['product_id'], $transfer['from_warehouse_id'], -$it['quantity'], 'transfer_out', $transfer['bill_no'], 'transfer', get_user_id(), "调拨至仓库ID:{$transfer['to_warehouse_id']}");
                update_inventory($it['product_id'], $transfer['to_warehouse_id'], $it['quantity'], 'transfer_in', $transfer['bill_no'], 'transfer', get_user_id(), "从仓库ID:{$transfer['from_warehouse_id']}调入");
            }
            add_log(get_user_id(), 'confirm', 'transfer', "确认调拨: {$transfer['bill_no']}");
            $pdo->commit();
            redirect("transfer_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Transfer view confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
    } elseif ($postAction === 'withdraw' && $transfer['status'] === 'confirmed') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE transfers SET status='draft' WHERE id=?")->execute([$id]);
            foreach ($items as $it) {
                update_inventory($it['product_id'], $transfer['to_warehouse_id'], -$it['quantity'], 'transfer_out', $transfer['bill_no'], 'transfer', get_user_id(), "撤回调拨-从调入仓库扣回");
                update_inventory($it['product_id'], $transfer['from_warehouse_id'], $it['quantity'], 'transfer_in', $transfer['bill_no'], 'transfer', get_user_id(), "撤回调拨-恢复到调出仓库");
            }
            add_log(get_user_id(), 'withdraw', 'transfer', "撤回调拨: {$transfer['bill_no']}");
            $pdo->commit();
            redirect("transfer_view.php?id=$id");
        } catch (Exception $e) { $pdo->rollBack(); error_log('Transfer view withdraw error: ' . $e->getMessage()); $error = '撤回失败，请查看系统日志'; }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 调拨单详情 #<?=$transfer['bill_no']?></h1>
    <div class="page-actions">
        <?php if ($transfer['status'] === 'draft'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认后库存将变更，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> 确认</button>
        </form>
        <a href="transfer.php?edit=<?=$id?>" class="btn btn-outline"><i class="fa-solid fa-pen"></i> 编辑</a>
        <?php endif; ?>
        <?php if ($transfer['status'] === 'confirmed'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-undo"></i> 撤回</button>
        </form>
        <?php endif; ?>
        <a href="transfer.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">调拨单 #<?=$transfer['bill_no']?></h3>
        <span class="badge badge-<?=$transfer['status']=='confirmed'?'success':'warning'?>"><?=$transfer['status']=='confirmed'?'已确认':'草稿'?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>调出仓库：</strong><?=htmlspecialchars($transfer['from_name']?:'-')?></div>
            <div><strong>调入仓库：</strong><?=htmlspecialchars($transfer['to_name']?:'-')?></div>
            <div><strong>调拨日期：</strong><?=$transfer['transfer_date']?></div>
            <div><strong>创建时间：</strong><?=$transfer['created_at']?></div>
            <div><strong>制单人：</strong><?=htmlspecialchars(get_user_name())?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>数量</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): ?>
                <tr><td><?=$i++?></td><td><?=$item['sku']?></td><td><?=htmlspecialchars($item['product_name'])?></td><td><?=$item['spec']?:'-'?></td><td><?=$item['quantity']?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="5"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($transfer['remark']): ?><div class="mt-2"><strong>备注：</strong><?=nl2br(htmlspecialchars($transfer['remark']))?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
