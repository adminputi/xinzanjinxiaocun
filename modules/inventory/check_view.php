<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('check_manage');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, w.name as warehouse_name FROM check_orders c LEFT JOIN warehouses w ON c.warehouse_id=w.id WHERE c.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('单据不存在');

$stmt2 = $pdo->prepare("SELECT ci.*, p.name as product_name, p.sku, p.spec FROM check_items ci JOIN products p ON ci.product_id=p.id WHERE ci.check_id=? ORDER BY p.name");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 盘点详情 #<?=$order['bill_no']?></h1>
    <div class="page-actions">
        <a href="check_edit.php?id=<?=$id?>" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">盘点单 #<?=$order['bill_no']?></h3>
        <span class="badge badge-<?=$order['status']=='confirmed'?'success':'warning'?>"><?=$order['status']=='confirmed'?'已完成':'待盘点'?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>仓库：</strong><?=htmlspecialchars($order['warehouse_name']?:'-')?></div>
            <div><strong>盘点日期：</strong><?=$order['check_date']?></div>
            <div><strong>创建时间：</strong><?=$order['created_at']?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>账面库存</th><th>实盘数量</th><th>差异</th><th>备注</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): $diff = floatval($item['actual_qty']) - floatval($item['book_qty']); ?>
                <tr>
                    <td><?=$i++?></td>
                    <td><?=$item['sku']?></td>
                    <td><strong><?=htmlspecialchars($item['product_name'])?></strong></td>
                    <td><?=$item['spec']?:'-'?></td>
                    <td><?=$item['book_qty']?></td>
                    <td><?=$item['actual_qty']?></td>
                    <td style="color:<?=$diff!=0?($diff>0?'var(--success)':'var(--danger)'):'var(--gray-500)'?>;font-weight:bold;"><?=$diff!=0?($diff>0?'+'.$diff:$diff):'0'?></td>
                    <td><?=htmlspecialchars($item['remark']?:'-')?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="8"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>备注：</strong><?=nl2br(htmlspecialchars($order['remark']))?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
