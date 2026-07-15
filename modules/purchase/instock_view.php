<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('purchase_instock');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT pi.*, s.name as supplier_name, s.phone as supplier_phone, s.address as supplier_address, w.name as warehouse_name, u.real_name as employee_name FROM purchase_instocks pi LEFT JOIN suppliers s ON pi.supplier_id=s.id LEFT JOIN warehouses w ON pi.warehouse_id=w.id LEFT JOIN users u ON pi.employee_id=u.id WHERE pi.id=?");
$stmt->execute([$id]);
$instock = $stmt->fetch();
if (!$instock) { die('入库单不存在'); }
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($instock['user_id'] ?? 0) != get_user_id()) { die('无权查看此记录'); }

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM purchase_instock_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.instock_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

// 获取打印模板
$tpl = $pdo->query("SELECT * FROM print_templates WHERE type='purchase_instock' AND is_default=1 LIMIT 1")->fetch();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 采购入库单详情</h1>
    <div class="page-actions">
        <?php if ($tpl): ?><button class="btn btn-outline" onclick="printInstock()"><i class="fa-solid fa-print"></i> 打印</button><?php endif; ?>
        <a href="instock.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">采购入库单 #<?= $instock['bill_no'] ?></h3>
        <span class="badge badge-<?= $instock['status']=='confirmed' ? 'success' : 'warning' ?>"><?= $instock['status']=='confirmed'?'已入库':'草稿' ?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>供应商：</strong><?= htmlspecialchars($instock['supplier_name']?:'-') ?></div>
            <div><strong>仓库：</strong><?= htmlspecialchars($instock['warehouse_name']?:'-') ?></div>
            <div><strong>经办人：</strong><?= htmlspecialchars($instock['employee_name']?:'-') ?></div>
            <div><strong>入库日期：</strong><?= $instock['instock_date'] ?></div>
            <div><strong>关联订单：</strong><?= $instock['order_id']>0 ? '#'.$instock['order_id'] : '-' ?></div>
            <div><strong>创建时间：</strong><?= $instock['created_at'] ?></div>
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
            <tfoot><tr><td colspan="7" class="text-right"><strong>合计：</strong></td><td><strong>¥<?= format_money($instock['total_amount']) ?></strong></td></tr></tfoot>
        </table></div>
        <?php if ($instock['remark']): ?><div class="mt-2"><strong>备注：</strong><?= nl2br(htmlspecialchars($instock['remark'])) ?></div><?php endif; ?>
    </div>
</div>

<?php if ($tpl): ?>
<!-- 打印区域 -->
<div id="printContent" style="display:none;">
<?php
$companyName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$html = str_replace(
    ['{company_name}','{bill_no}','{bill_date}','{supplier_name}','{items}','{total_amount}','{user_name}','{warehouse_name}','{remark}'],
    [$companyName,$instock['bill_no'],$instock['instock_date'],$instock['supplier_name']??'',build_items_html($items, $tpl['content']),format_money($instock['total_amount']),get_user_name(),$instock['warehouse_name']??'',$instock['remark']??''],
    $tpl['content']
);
echo $html;
?>
</div>
<script>
function printInstock() {
    var win = window.open('', '_blank', 'width=800,height=600');
    win.document.write('<html><head><title>采购入库单打印</title>');
    win.document.write('<style>body{font-family:SimSun;padding:20px;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:6px;text-align:center;font-size:13px;}@media print{body{padding:0;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('printContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
