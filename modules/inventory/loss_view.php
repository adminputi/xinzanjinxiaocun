<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('loss_manage');
require_once __DIR__ . '/../../includes/migration.php';
$pdo = getDB();
run_migrations();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT l.*, w.name as warehouse_name FROM loss_orders l LEFT JOIN warehouses w ON l.warehouse_id=w.id WHERE l.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('单据不存在');

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec FROM loss_items i JOIN products p ON i.product_id=p.id WHERE i.loss_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

// 含盘盈（加过库存）的单不允许撤回——成本无法精确还原，只能另开反向单
$hasPlus = false;
foreach ($items as $it) {
    $actual = $it['actual_diff'] !== null ? floatval($it['actual_diff']) : floatval($it['quantity']);
    if ($actual > 0.000001) { $hasPlus = true; break; }
}

// 处理操作（逻辑抽到 includes/functions.php，列表页与详情页共用同一份）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'confirm' && $order['status'] === 'draft') {
        $res = confirm_loss_order($pdo, $id);
        if (!$res['ok']) $error = $res['msg'];
        redirect("loss_view.php?id=$id");
    } elseif ($postAction === 'withdraw' && $order['status'] === 'confirmed') {
        $res = withdraw_loss_order($pdo, $id);
        if (!$res['ok']) $error = $res['msg'];
        redirect("loss_view.php?id=$id");
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 库存调整详情 #<?=$order['bill_no']?></h1>
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
        <?php if ($hasPlus): ?>
        <span class="btn btn-warning" style="opacity:.5;cursor:not-allowed;" title="含盘盈（加库存），撤回后成本无法精确还原，请另开一张反向调整单"><i class="fa-solid fa-undo"></i> 不可撤回</span>
        <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('撤回后库存将恢复，确定？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-undo"></i> 撤回</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <a href="loss.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($hasPlus && $order['status'] === 'confirmed'): ?>
<div class="alert alert-info">本单含盘盈（增加库存），成本已按填写的单价加权，<strong>不允许撤回</strong>。如需冲回，请另开一张反向的库存调整单。</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">库存调整单 #<?=$order['bill_no']?></h3>
        <span class="badge badge-<?=$order['status']=='confirmed'?'success':($order['status']=='cancelled'?'gray':'warning')?>"><?=$order['status']=='confirmed'?'已确认':($order['status']=='cancelled'?'已取消':'草稿')?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>仓库：</strong><?=htmlspecialchars($order['warehouse_name']?:'-')?></div>
            <div><strong>类型：</strong><span class="badge badge-<?=$order['type']=='loss'?'danger':($order['type']=='overflow'?'success':'info')?>"><?=$order['type']=='loss'?'报损（减库存）':($order['type']=='overflow'?'报溢（加库存）':'混合（有加有减）')?></span></div>
            <div><strong>日期：</strong><?=$order['order_date']?:$order['created_at']?></div>
            <div><strong>创建时间：</strong><?=$order['created_at']?></div>
            <div><strong>制单人：</strong><?=htmlspecialchars($order['user_id']?(get_user_name()):'系统')?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>方式</th><th>数量</th><th>实际增减</th><th>成本单价</th><th>金额</th><th>原因</th></tr></thead>
            <tbody>
                <?php $i=1; $totalAmt=0; foreach($items as $item):
                    $isTarget = $item['target_qty'] !== null;
                    $showQty  = $isTarget ? floatval($item['target_qty']) : floatval($item['quantity']);
                    $actual   = $item['actual_diff'] !== null ? floatval($item['actual_diff']) : null;
                    $price    = floatval($item['price']);
                    $amt      = floatval($item['amount']);
                    $totalAmt += $amt;
                ?>
                <tr>
                    <td><?=$i++?></td>
                    <td><?=$item['sku']?></td>
                    <td><strong><?=htmlspecialchars($item['product_name'])?></strong></td>
                    <td><?=$item['spec']?:'-'?></td>
                    <td><?=$isTarget?'调整为':'增减'?></td>
                    <td><?=$showQty?></td>
                    <td>
                        <?php if ($order['status'] === 'confirmed' && $actual !== null): ?>
                        <span style="color:<?=$actual>0?'var(--success)':($actual<0?'var(--danger)':'var(--gray-500)')?>;font-weight:bold;"><?=$actual>0?'+'.$actual:$actual?></span>
                        <?php else: ?>
                        <span style="color:var(--gray-400);">待确认</span>
                        <?php endif; ?>
                    </td>
                    <td>¥<?=format_money($price)?></td>
                    <td>¥<?=format_money($amt)?></td>
                    <td><?=htmlspecialchars($item['reason']?:'-')?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="10"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="8" class="text-right"><strong>合计：</strong></td><td><strong>¥<?=format_money($totalAmt)?></strong></td><td></td></tr>
            </tfoot>
        </table></div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>备注：</strong><?=nl2br(htmlspecialchars($order['remark']))?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
