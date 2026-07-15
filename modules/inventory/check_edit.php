<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('check_manage');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, w.name as warehouse_name FROM check_orders c LEFT JOIN warehouses w ON c.warehouse_id=w.id WHERE c.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('单据不存在');
if ($order['status'] != 'draft') { redirect('check_view.php?id='.$id); }

$stmt = $pdo->prepare("SELECT ci.*, p.name as product_name, p.sku, p.spec FROM check_items ci JOIN products p ON ci.product_id=p.id WHERE ci.check_id=? ORDER BY p.name");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ids = $_POST['item_id'] ?? [];
    $actuals = $_POST['actual_qty'] ?? [];
    $remarks = $_POST['item_remark'] ?? [];

    // 不管是save还是confirm，都先保存盘点数据
    if (!empty($ids)) {
        $pdo->beginTransaction();
        try {
            $updStmt = $pdo->prepare("UPDATE check_items SET actual_qty=?, diff_qty=?-book_qty, remark=? WHERE id=?");
            foreach ($ids as $i => $iid) {
                $actual = floatval($actuals[$i]??0);
                $remark = $remarks[$i]??'';
                $updStmt->execute([$actual, $actual, $remark, intval($iid)]);
            }
            $pdo->commit();
            $success = '盘点数据已保存';
            $st = $pdo->prepare("SELECT ci.*, p.name as product_name, p.sku, p.spec FROM check_items ci JOIN products p ON ci.product_id=p.id WHERE ci.check_id=? ORDER BY p.name");
            $st->execute([$id]);
            $items = $st->fetchAll();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Check edit save error: ' . $e->getMessage()); $error = '保存失败，请查看系统日志'; }
    }

    // 确认盘点：先校验负库存，再更新库存
    if (($_POST['action']??'') === 'confirm' && !isset($error)) {
        // 负库存校验：实盘数量不能为负数
        $negStockProducts = [];
        foreach ($items as $item) {
            if (floatval($item['actual_qty']) < 0) {
                $negStockProducts[] = $item['product_name'];
            }
        }
        if (!empty($negStockProducts)) {
            $error = '以下商品为负库存，请检查：' . implode('、', $negStockProducts);
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT * FROM check_items WHERE check_id=?");
                $st->execute([$id]);
                $allItems = $st->fetchAll();
                foreach ($allItems as $item) {
                    if ($item['diff_qty'] != 0) {
                        update_inventory($item['product_id'], $order['warehouse_id'], $item['diff_qty'], 'check', $order['bill_no'], 'check', get_user_id(), '盘点调整');
                    }
                }
                $pdo->prepare("UPDATE check_orders SET status='confirmed' WHERE id=?")->execute([$id]);
                add_log(get_user_id(), 'confirm', 'check_order', "确认盘点: {$order['bill_no']}");
                $pdo->commit();
                redirect('check.php');
            } catch (Exception $e) { $pdo->rollBack(); error_log('Check edit confirm error: ' . $e->getMessage()); $error = '确认失败，请查看系统日志'; }
        }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clipboard-list"></i> 盘点 #<?=$order['bill_no']?></h1>
    <div class="page-actions">
        <?php if (isset($success)): ?><span class="badge badge-success"><?=$success?></span><?php endif; ?>
        <a href="check.php" class="btn btn-outline">返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<form method="post" id="checkForm">
<?= csrf_field() ?>
<input type="hidden" name="action" id="checkAction" value="save">
<div class="card">
    <div class="card-header"><span>仓库：<strong><?=htmlspecialchars($order['warehouse_name'])?></strong> | 日期：<?=$order['check_date']?></span></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>商品</th><th>SKU</th><th>规格</th><th>账面库存</th><th>实盘数量</th><th>差异</th><th>备注</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): $diff = floatval($item['actual_qty']) - floatval($item['book_qty']); ?>
                <tr>
                    <td><strong><?=htmlspecialchars($item['product_name'])?></strong></td>
                    <td><?=$item['sku']?></td>
                    <td><?=$item['spec']?:'-'?></td>
                    <td><span class="badge badge-info"><?=$item['book_qty']?></span></td>
                    <td><input type="number" step="0.01" name="actual_qty[]" class="form-control" value="<?=$item['actual_qty']?>" style="width:120px;"></td>
                    <td style="color:<?=$diff!=0?($diff>0?'var(--success)':'var(--danger)'):'var(--gray-500)'?>;font-weight:bold;"><?=$diff!=0?($diff>0?'+'.$diff:$diff):'0'?></td>
                    <td><input type="text" name="item_remark[]" class="form-control" value="<?=htmlspecialchars($item['remark'])?>" style="width:150px;" placeholder="差异原因"></td>
                    <input type="hidden" name="item_id[]" value="<?=$item['id']?>">
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="mt-2 flex-between">
    <span></span>
    <div class="form-row" style="gap:8px;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存盘点数据</button>
        <button type="button" class="btn btn-success" onclick="confirmCheck()"><i class="fa-solid fa-check"></i> 确认盘点</button>
    </div>
</div>
</form>

<script>
function confirmCheck(){
    if(!confirm('确认盘点后将更新库存，是否继续？')) return;
    document.getElementById('checkAction').value='confirm';
    document.getElementById('checkForm').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
