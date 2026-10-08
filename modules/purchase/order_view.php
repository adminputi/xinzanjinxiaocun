<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_order');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT o.*, s.name as supplier_name, s.phone as supplier_phone, w.name as warehouse_name, u.real_name as employee_name FROM purchase_orders o LEFT JOIN suppliers s ON o.supplier_id=s.id LEFT JOIN warehouses w ON o.warehouse_id=w.id LEFT JOIN users u ON o.employee_id=u.id WHERE o.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { die('订单不存在'); }
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($order['user_id'] ?? 0) != get_user_id()) { die('无权查看此记录'); }

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM purchase_order_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.order_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();
$statusLabels = ['draft'=>'草稿','confirmed'=>'已确认','received'=>'已入库','partial'=>'部分入库','completed'=>'已完成','cancelled'=>'已取消'];

// 删除前置校验：无关联入库单 且 无付款记录
$stmt3 = $pdo->prepare("SELECT COUNT(*) FROM purchase_instocks WHERE order_id=?");
$stmt3->execute([$id]);
$instockCount = intval($stmt3->fetchColumn());
$canDelete = ($instockCount === 0 && floatval($order['paid_amount'] ?? 0) == 0);
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 采购订单详情</h1>
    <div class="page-actions">
        <?php if (floatval($order['total_amount']) - floatval($order['paid_amount'] ?? 0) > 0.005): ?>
        <a class="btn btn-success" href="../finance/payment.php?order_id=<?=$order['id']?>"><i class="fa-solid fa-credit-card"></i> 登记付款</a>
        <?php endif; ?>
        <button class="btn btn-outline" onclick="window.print()"><i class="fa-solid fa-print"></i> 打印</button>
        <?php if ($canDelete): ?>
        <form method="post" action="order.php" style="display:inline" onsubmit="return confirm('⚠️ 确定删除此采购订单吗？\n\n删除后不可恢复。\n\n单号：<?=$order['bill_no']?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$order['id']?>">
            <button class="btn btn-danger"><i class="fa-solid fa-trash"></i> 删除</button>
        </form>
        <?php endif; ?>
        <a href="order.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<div class="card" id="printArea">
    <div class="card-header">
        <h3 class="card-title">采购订单 #<?= $order['bill_no'] ?></h3>
        <span class="badge badge-<?= ['draft'=>'warning','confirmed'=>'info','received'=>'primary','partial'=>'info','completed'=>'success','cancelled'=>'gray'][$order['status']] ?>"><?= $statusLabels[$order['status']] ?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>供应商：</strong><?= htmlspecialchars($order['supplier_name']?:'-') ?></div>
            <div><strong>仓库：</strong><?= htmlspecialchars($order['warehouse_name']?:'-') ?></div>
            <div><strong>采购员：</strong><?= htmlspecialchars($order['employee_name']?:'-') ?></div>
            <div><strong>订单日期：</strong><?= $order['order_date'] ?></div>
            <div><strong>预计到货：</strong><?= $order['expected_date']?:'-' ?></div>
            <div><strong>订单状态：</strong><?= $statusLabels[$order['status']] ?></div>
        </div>
        <div class="table-container">
            <table>
                <thead><tr><th>#</th><th>商品SKU</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>已入库</th><th>备注</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($items as $item): ?>
                    <tr>
                        <td><?=$i++?></td><td><?=$item['sku']?></td><td><?=htmlspecialchars($item['product_name'])?></td>
                        <td><?=$item['spec']?:'-'?></td><td><?=$item['unit_name']?:'-'?></td>
                        <td><?=$item['quantity']?></td><td>¥<?=format_money($item['price'])?></td>
                        <td>¥<?=format_money($item['amount'])?></td>
                        <td><?=$item['received_qty']?></td>
                        <td><?=htmlspecialchars($item['remark']??'')?:'-'?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="8" class="text-right"><strong>合计：</strong></td><td><strong>¥<?= format_money($order['total_amount']) ?></strong></td><td></td></tr></tfoot>
            </table>
        </div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>总备注：</strong><?= nl2br(htmlspecialchars($order['remark'])) ?></div><?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
