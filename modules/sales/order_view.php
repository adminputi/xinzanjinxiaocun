<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('sales_order');
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, w.name as warehouse_name, u.real_name as employee_name FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN warehouses w ON o.warehouse_id=w.id LEFT JOIN users u ON o.employee_id=u.id WHERE o.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { die('订单不存在'); }
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($order['user_id'] ?? 0) != get_user_id()) { die('无权查看此记录'); }

// 查询追踪码及打印开关
$printTracking = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='print_tracking_code'")->fetchColumn();
$printTracking = ($printTracking === false) ? '1' : $printTracking; // 默认开启
$trackingCode = null;
if ($printTracking === '1') {
    // 通过JOIN一次性查找：tracking_codes 可能通过order_id直接关联，也可能通过outstock_id间接关联
    $stmtTracking = $pdo->prepare("
        SELECT tc.tracking_no, tc.qrcode_path FROM tracking_codes tc
        LEFT JOIN sales_outstocks so ON tc.outstock_id = so.id
        WHERE tc.order_id = ? OR so.order_id = ?
        ORDER BY tc.id DESC
        LIMIT 1
    ");
    $stmtTracking->execute([$id, $id]);
    $trackingCode = $stmtTracking->fetch();
}
$trackingNoHtml = '';
if ($trackingCode) {
    $qrImg = '';
    if (!empty($trackingCode['qrcode_path']) && file_exists(__DIR__ . '/../../' . $trackingCode['qrcode_path'])) {
        $fullPath = __DIR__ . '/../../' . $trackingCode['qrcode_path'];
        $imgData = @file_get_contents($fullPath);
        if ($imgData !== false && strlen($imgData) > 100) {
            $base64 = base64_encode($imgData);
            $src = 'data:image/png;base64,' . $base64;
            $qrImg = '<img src="' . htmlspecialchars($src) . '" style="width:80px;height:80px;" alt="追踪码二维码"><br>';
        }
    }
    $trackingNoHtml = '<div style="text-align:center;font-size:12px;">' . $qrImg . '追踪码：' . htmlspecialchars($trackingCode['tracking_no']) . '</div>';
}

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_order_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.order_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();
$statusLabels = ['draft'=>'可编辑','confirmed'=>'已锁定','shipped'=>'已出库'];

// 确保打印模板表存在且模板已更新到最新版本
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `print_templates` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `type` VARCHAR(30) NOT NULL DEFAULT 'sales_order', `content` TEXT, `is_default` TINYINT DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
$tplCount = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_order'")->fetchColumn();
$needUpdate = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_order' AND (content NOT LIKE '%<thead>%' OR content NOT LIKE '%{tracking_no}%')")->fetchColumn();
if ($needUpdate > 0 || $tplCount < 2) {
    $pdo->exec("DELETE FROM print_templates WHERE type='sales_order' AND (content NOT LIKE '%<thead>%' OR content NOT LIKE '%{tracking_no}%')");
    $existA = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_order' AND name='默认销售单（含单价金额）'")->fetchColumn();
    $existB = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_order' AND name='默认销售单（不含单价金额）'")->fetchColumn();
    if ($existA == 0) {
        $defaultTplA = '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;"><div style="flex:1;text-align:center;"><h2>{company_name}</h2><h3>销售单</h3></div><div style="flex-shrink:0;">{tracking_no}</div></div><div style="margin-bottom:12px;"><span>单号：{bill_no}</span><span style="margin-left:24px;">日期：{bill_date}</span></div><div style="margin-bottom:12px;"><span>客户：{customer_name}</span><span style="margin-left:24px;">电话：{customer_phone}</span></div><div style="margin-bottom:12px;"><span>地址：{customer_address}</span><span style="margin-left:24px;">仓库：{warehouse_name}</span></div><table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;"><thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead><tbody>{items}</tbody><tfoot><tr><td colspan="7" align="right"><strong>合计金额：</strong></td><td>{total_amount}</td></tr></tfoot></table><div style="margin-top:16px;display:flex;justify-content:space-between;"><span>制单人：{user_name}</span><span>打印时间：' . date('Y-m-d H:i:s') . '</span></div>';
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,1)")->execute(['默认销售单（含单价金额）','sales_order',$defaultTplA]);
    }
    if ($existB == 0) {
        $defaultTplB = '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;"><div style="flex:1;text-align:center;"><h2>{company_name}</h2><h3>销售单（送货单）</h3></div><div style="flex-shrink:0;">{tracking_no}</div></div><div style="margin-bottom:12px;"><span>单号：{bill_no}</span><span style="margin-left:24px;">日期：{bill_date}</span></div><div style="margin-bottom:12px;"><span>客户：{customer_name}</span><span style="margin-left:24px;">电话：{customer_phone}</span></div><div style="margin-bottom:12px;"><span>地址：{customer_address}</span><span style="margin-left:24px;">仓库：{warehouse_name}</span></div><table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;"><thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>备注</th></tr></thead><tbody>{items}</tbody></table><div style="margin-top:40px;display:flex;justify-content:space-between;flex-wrap:wrap;"><span>制单人：{user_name}</span><span>审核人：___________</span><span>客户签字：___________</span><span>业务签字：___________</span></div>';
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,0)")->execute(['默认销售单（不含单价金额）','sales_order',$defaultTplB]);
    }
}

// 获取所有打印模板
$templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC, id ASC")->fetchAll();
$selectedTplId = intval($_GET['tpl_id'] ?? 0);
if ($selectedTplId > 0) {
    $stmt3 = $pdo->prepare("SELECT * FROM print_templates WHERE id=?");
    $stmt3->execute([$selectedTplId]);
    $tpl = $stmt3->fetch();
} else {
    // 优先使用 sales_order 类型的默认模板
    $tpl = $pdo->query("SELECT * FROM print_templates WHERE is_default=1 AND type='sales_order' LIMIT 1")->fetch();
    if (!$tpl) $tpl = $pdo->query("SELECT * FROM print_templates WHERE is_default=1 AND type='sales_outstock' LIMIT 1")->fetch();
    if (!$tpl) $tpl = $pdo->query("SELECT * FROM print_templates WHERE is_default=1 LIMIT 1")->fetch();
}
if (!$tpl && $templates) $tpl = $templates[0];

$companyName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 销售订单详情</h1>
    <div class="page-actions">
        <div style="display:flex;align-items:center;gap:4px;">
            <select id="tplSelect" class="form-control" style="width:auto;display:inline-block;" onchange="selectTpl(this.value)">
                <?php 
                $typeLabels = ['sales_order'=>'销售单','sales_outstock'=>'销售出库单','purchase_order'=>'采购单','purchase_instock'=>'采购入库单'];
                foreach ($templates as $tp): ?>
                <option value="<?=$tp['id']?>" <?=($tpl && $tpl['id']==$tp['id'])?'selected':''?>><?=htmlspecialchars($tp['name'])?> [<?=$typeLabels[$tp['type']]??$tp['type']?>]<?=$tp['is_default']?' ★':''?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline" onclick="printOrder()"><i class="fa-solid fa-print"></i> 打印</button>
        </div>
        <a href="order.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">销售订单 #<?= $order['bill_no'] ?></h3>
        <span class="badge badge-<?= ['draft'=>'warning','confirmed'=>'info','shipped'=>'success'][$order['status']] ?>"><?= $statusLabels[$order['status']] ?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>客户：</strong><?= htmlspecialchars($order['customer_name']?:'-') ?></div>
            <div><strong>仓库：</strong><?= htmlspecialchars($order['warehouse_name']?:'-') ?></div>
            <div><strong>业务员：</strong><?= htmlspecialchars($order['employee_name']?:'-') ?></div>
            <div><strong>订单日期：</strong><?= $order['order_date'] ?></div>
            <div><strong>预计交货：</strong><?= $order['delivery_date']?:'-' ?></div>
            <div><strong>已收金额：</strong><span style="color:var(--success)">¥<?= format_money($order['received_amount']) ?></span></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): ?>
                <tr><td><?=$i++?></td><td><?=$item['sku']?></td><td><?=htmlspecialchars($item['product_name'])?></td><td><?=$item['spec']?:'-'?></td><td><?=$item['unit_name']?:'-'?></td><td><?=$item['quantity']?></td><td>¥<?=format_money($item['price'])?></td><td>¥<?=format_money($item['amount'])?></td><td><?=htmlspecialchars($item['remark']??'')?:'-'?></td></tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="8" class="text-right"><strong>合计：</strong></td><td><strong>¥<?= format_money($order['total_amount']) ?></strong></td></tr></tfoot>
        </table></div>
        <?php if ($order['remark']): ?><div class="mt-2"><strong>总备注：</strong><?= nl2br(htmlspecialchars($order['remark'])) ?></div><?php endif; ?>
    </div>
</div>

<!-- 打印区域 -->
<div id="printContent" style="display:none;"></div>

<script>
// 商品明细数据
var printItems = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// 智能生成商品明细行：根据模板<thead>列名自动匹配数据字段
function buildItemsHtml(items, templateHtml) {
    if (!items || !items.length) return '<tr><td colspan="10">暂无明细数据</td></tr>';
    var colMap = {
        '序号':'__idx__','SKU':'sku','商品名称':'product_name','规格':'spec',
        '单位':'unit_name','数量':'quantity','单价':'price','金额':'amount','备注':'__remark__'
    };
    var theadMatch = templateHtml.match(/<thead>([\s\S]*?)<\/thead>/);
    var columns = [];
    if (theadMatch) {
        var thRe = /<th>(.*?)<\/th>/g, m;
        while ((m = thRe.exec(theadMatch[1])) !== null) {
            var col = m[1].trim();
            if (col) columns.push(col);
        }
    }
    if (!columns.length) columns = ['序号','商品名称','规格','单位','数量','单价','金额','备注'];
    var rows = '';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        rows += '<tr>';
        for (var c = 0; c < columns.length; c++) {
            var field = colMap[columns[c]] || '';
            if (field === '__idx__') {
                rows += '<td>' + (i + 1) + '</td>';
            } else if (field === '__remark__') {
                rows += '<td>' + (item.remark || '') + '</td>';
            } else if (field && item[field] !== undefined && item[field] !== null) {
                var val = String(item[field]);
                if (field === 'price' || field === 'amount') val = '¥' + Number(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                rows += '<td>' + val + '</td>';
            } else {
                rows += '<td></td>';
            }
        }
        rows += '</tr>';
    }
    return rows;
}

var allTemplates = <?= json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var currentTpl = <?= $tpl ? json_encode($tpl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : 'null' ?>;
var printData = {
    bill_no: '<?= js_escape($order['bill_no']) ?>',
    bill_date: '<?= js_escape($order['order_date']) ?>',
    customer_name: '<?= js_escape($order['customer_name']??'') ?>',
    customer_phone: '<?= js_escape($order['customer_phone']??'') ?>',
    customer_address: '<?= js_escape($order['customer_address']??'') ?>',
    warehouse_name: '<?= js_escape($order['warehouse_name']??'') ?>',
    total_amount: '¥<?= format_money($order['total_amount']) ?>',
    remark: '<?= js_escape($order['remark']??'') ?>',
    user_name: '<?= js_escape(get_user_name()) ?>',
    company_name: '<?= js_escape($companyName ?? SITE_NAME) ?>',
    tracking_no: '<?= js_escape($trackingNoHtml) ?>'
};

function selectTpl(id) {
    for (var i = 0; i < allTemplates.length; i++) {
        if (allTemplates[i].id == id) { currentTpl = allTemplates[i]; break; }
    }
    renderPrintContent();
}
function renderPrintContent() {
    if (!currentTpl) return;
    var html = currentTpl.content;
    for (var k in printData) {
        html = html.replace(new RegExp('\\{'+k+'\\}', 'g'), printData[k]);
    }
    html = html.replace(/\{items\}/g, buildItemsHtml(printItems, currentTpl.content));
    document.getElementById('printContent').innerHTML = html;
}
if (currentTpl) renderPrintContent();

function printOrder() {
    if (!currentTpl) return;
    renderPrintContent();
    var win = window.open('', '_blank', 'width=800,height=600');
    win.document.write('<html><head><title>销售单打印</title>');
    win.document.write('<style>body{font-family:SimSun;padding:20px;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:6px;text-align:center;font-size:13px;}@media print{body{padding:0;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('printContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
