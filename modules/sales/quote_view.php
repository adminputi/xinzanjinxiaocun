<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_quote');
$pdo = getDB();
run_migrations();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.contact as customer_contact, u.real_name as employee_name, u.phone as employee_phone FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id LEFT JOIN users u ON q.employee_id=u.id WHERE q.id=?");
$stmt->execute([$id]);
$quote = $stmt->fetch();
if (!$quote) { die('报价单不存在'); }
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($quote['user_id'] ?? 0) != get_user_id()) { die('无权查看此记录'); }

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.image as product_image, p.description as product_description, u.name as unit_name FROM sales_quote_items i LEFT JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.quote_id=? ORDER BY i.id");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();
// LEFT JOIN 后商品可能已被物理删除（历史单据会留下悬空明细），这里补名称兜底，与 quote_form.php 保持一致：
// 保证详情页与打印时该行不会凭空消失，明细金额之和也才能与主表的 total_amount 对得上
foreach ($items as $k => $it) {
    if ($it['product_name'] === null || $it['product_name'] === '') {
        $items[$k]['product_name'] = '商品已删除(ID:' . intval($it['product_id']) . ')';
    }
}
// 注意：商品图片【不在服务端转 base64】。
// 以前的做法是逐行读取图片文件并 base64 内联到页面的 printData 里，
// 图片多/大时单页 HTML 会膨胀到几十上百 MB，浏览器必须下载并解析完才会结束加载，
// 表现为打开详情页时标签一直转圈、无法操作。
// 现在首屏只输出图片路径（缩略图由浏览器异步加载），
// 只有点击「打印 / 导出PDF」时才在前端按需把图片转成（压缩后的）dataURL。

$statusLabels = ['draft'=>'可编辑','quoted'=>'已转订单','contracted'=>'已转合同','withdrawn'=>'已撤回'];
$statusBadges = ['draft'=>'warning','quoted'=>'info','contracted'=>'primary','withdrawn'=>'gray'];

// 确保打印模板表存在
ensure_print_templates_table($pdo);

// 获取"产品项目方案单"模板
$tplStmt = $pdo->prepare("SELECT * FROM print_templates WHERE name=? LIMIT 1");
$tplStmt->execute(['产品项目方案单（含图片+描述）']);
$tpl = $tplStmt->fetch();
if (!$tpl) {
    // 模板不存在，自动初始化
    $quoteTplContent = '<div style="font-family:SimSun,Arial;padding:10px;max-width:900px;margin:0 auto;color:#000;">'
    . '<div style="background:#000;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-radius:4px 4px 0 0;">'
    . '<h1 style="margin:0;font-size:28px;font-weight:bold;letter-spacing:4px;">产品项目方案单</h1>'
    . '<div style="text-align:right;line-height:1.7;">'
    . '<div style="font-size:18px;font-weight:bold;">{company_name}</div>'
    . '<div style="font-size:13px;opacity:0.9;">{company_address}</div>'
    . '</div></div>'
    . '<div style="padding:10px 0 4px 0;font-size:14px;line-height:1.9;">'
    . '<div><strong>TO：</strong>{customer_name}</div>'
    . '<div style="display:flex;flex-wrap:wrap;gap:20px;">'
    . '<span><strong>报价日期：</strong>{bill_date}</span>'
    . '<span><strong>电话：</strong>{customer_phone}</span>'
    . '<span><strong>联系人：</strong>{customer_contact}</span>'
    . '</div></div>'
    . '<div style="background:#ffc000;padding:8px 16px;font-weight:bold;margin:8px 0 0 0;font-size:14px;border:1px solid #000;border-bottom:none;">感谢您对本公司的支持与信赖，贵公司所需产品报价如下：</div>'
    . '<table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse;width:100%;font-size:12px;border:1px solid #000;table-layout:fixed;">'
    . '<thead><tr style="background:#1e6bb8;color:#fff;font-size:14px;font-weight:bold;">'
    . '<th style="width:55px;">编码</th>'
    . '<th style="width:100px;">产品图片</th>'
    . '<th style="width:75px;">产品名称</th>'
    . '<th>描述</th>'
    . '<th style="width:75px;">价格</th>'
    . '<th style="width:50px;">数量</th>'
    . '<th style="width:80px;">金额</th>'
    . '</tr></thead>'
    . '<tbody>{items}</tbody>'
    . '</table>'
    . '<div style="background:#ffc000;padding:10px 16px;display:flex;justify-content:space-between;font-weight:bold;font-size:14px;border:1px solid #000;border-top:none;">'
    . '<span>小写合计（人民币）：{total_amount}</span>'
    . '<span>大写合计（人民币）：{total_amount_cn}</span>'
    . '</div>'
    . '<div style="background:#ffc000;padding:10px 16px;font-size:13px;line-height:1.9;border:1px solid #000;border-top:1px dashed #000;">'
    . '<strong>备注：</strong><br>{remark}'
    . '</div>'
    . '</div>';
    try {
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,0)")
            ->execute(['产品项目方案单（含图片+描述）', 'quote', $quoteTplContent]);
    } catch (Exception $e) {
        error_log('print_templates quote init failed: ' . $e->getMessage());
    }
    $tpl = ['content' => $quoteTplContent];
}

$companyName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';

// 检查关联订单状态
$orderShipped = false;
$orderInfo = null;
if ($quote['order_id']) {
    $stmt3 = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt3->execute([$quote['order_id']]);
    $orderInfo = $stmt3->fetch();
    $orderShipped = ($orderInfo && $orderInfo['status'] === 'shipped');
}

?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> 销售报价详情</h1>
    <div class="page-actions">
        <button class="btn btn-primary" id="btnPrint" onclick="printQuote()"><i class="fa-solid fa-print"></i> 打印</button>
        <button class="btn btn-success" id="btnExport" onclick="exportPDF()"><i class="fa-solid fa-file-pdf"></i> 导出PDF</button>
        <?php if (!empty($quote['contract_id'])): ?>
        <a href="contract_form.php?id=<?=intval($quote['contract_id'])?>" class="btn btn-outline"><i class="fa-solid fa-file-signature"></i> 查看合同</a>
        <?php elseif (in_array($quote['status'], ['draft','withdrawn'], true)): ?>
        <form method="post" action="quote.php" style="display:inline;" onsubmit="return confirm('确定将该报价单转为销售合同吗？将生成一份草稿合同，明细沿用本报价单。');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="to_contract">
            <input type="hidden" name="id" value="<?=$quote['id']?>">
            <button type="submit" class="btn btn-outline"><i class="fa-solid fa-file-signature"></i> 转合同</button>
        </form>
        <?php endif; ?>
        <a href="quote.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
    </div>
</div>

<div class="card"><div class="card-body">
    <div class="detail-grid">
        <div class="detail-group">
            <label>报价单号</label>
            <span><strong><?= htmlspecialchars($quote['bill_no']) ?></strong></span>
        </div>
        <div class="detail-group">
            <label>客户</label>
            <span><?= htmlspecialchars($quote['customer_name']?:'-') ?></span>
        </div>
        <div class="detail-group">
            <label>电话</label>
            <span><?= htmlspecialchars($quote['customer_phone']?:'-') ?></span>
        </div>
        <div class="detail-group">
            <label>联系人</label>
            <span><?= htmlspecialchars($quote['customer_contact']?:'-') ?></span>
        </div>
        <div class="detail-group">
            <label>地址</label>
            <span><?= htmlspecialchars($quote['customer_address']?:'-') ?></span>
        </div>
        <div class="detail-group">
            <label>金额</label>
            <span><strong style="color:var(--danger)">¥<?= format_money($quote['total_amount']) ?></strong></span>
        </div>
        <div class="detail-group">
            <label>业务员</label>
            <span><?= htmlspecialchars($quote['employee_name']?:'-') ?></span>
        </div>
        <div class="detail-group">
            <label>报价日期</label>
            <span><?= htmlspecialchars($quote['quote_date']) ?></span>
        </div>
        <div class="detail-group">
            <label>状态</label>
            <span><span class="badge badge-<?= $statusBadges[$quote['status']]??'gray' ?>"><?= $statusLabels[$quote['status']]??$quote['status'] ?></span></span>
        </div>
        <?php if ($quote['order_id'] && $orderInfo): ?>
        <div class="detail-group">
            <label>关联订单</label>
            <span><a href="order_view.php?id=<?=$quote['order_id']?>"><strong><?= htmlspecialchars($orderInfo['bill_no']) ?></strong></a>
            <span class="badge badge-<?= $orderInfo['status']==='shipped'?'success':($orderInfo['status']==='draft'?'warning':'info') ?>"><?= $orderInfo['status'] ?></span></span>
        </div>
        <?php endif; ?>
        <div class="detail-group" style="grid-column:1/-1;">
            <label>备注</label>
            <span class="pre-wrap"><?= nl2br(htmlspecialchars($quote['remark']?:'无')) ?></span>
        </div>
    </div>
</div></div>

<div class="card mt-2"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th style="width:52px;">图片</th><th>SKU</th><th>商品名称</th><th>规格</th><th>单位</th><th>单价</th><th>数量</th><th>金额</th><th>备注</th></tr></thead>
<tbody>
<?php if ($items): foreach ($items as $it): ?>
<tr>
    <td>
    <?php if (!empty($it['product_image'])): ?>
    <img src="../../<?= htmlspecialchars($it['product_image']) ?>" loading="lazy" decoding="async" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" onclick="previewImage('../../<?= htmlspecialchars($it['product_image']) ?>')" title="点击放大" alt="">
    <?php else: ?>
    <span style="display:inline-block;width:40px;height:40px;background:var(--gray-100);border-radius:4px;text-align:center;line-height:40px;color:var(--gray-400);"><i class="fa-solid fa-box"></i></span>
    <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($it['sku']?:'-') ?></td>
    <td><strong><?= htmlspecialchars($it['product_name']) ?></strong></td>
    <td><?= htmlspecialchars($it['spec']?:'-') ?></td>
    <td><?= htmlspecialchars($it['unit_name']?:'-') ?></td>
    <td>¥<?= format_money($it['price']) ?></td>
    <td><?= $it['quantity'] ?></td>
    <td><strong>¥<?= format_money($it['amount']) ?></strong></td>
    <td><?= htmlspecialchars($it['remark']?:'') ?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-box"></i><p>暂无明细</p></div></td></tr>
<?php endif; ?>
</tbody>
<tfoot>
    <tr><td colspan="7" class="text-right"><strong>合计：</strong></td><td><strong style="color:var(--danger)">¥<?= format_money($quote['total_amount']) ?></strong></td><td></td></tr>
</tfoot>
</table></div></div></div>

<style>
.detail-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px 24px; }
.detail-group label { display:block; font-size:12px; color:var(--gray-500); margin-bottom:2px; }
.detail-group span { font-size:14px; }
.pre-wrap { white-space:pre-wrap; }
</style>

<!-- 打印内容区域（隐藏） -->
<div id="printContent" style="display:none;"></div>

<script>
var printData = {
    bill_no: '<?= js_escape($quote['bill_no']) ?>',
    bill_date: '<?= js_escape($quote['quote_date']) ?>',
    customer_name: '<?= js_escape($quote['customer_name']??'') ?>',
    customer_phone: '<?= js_escape($quote['customer_phone']??'') ?>',
    customer_address: '<?= js_escape($quote['customer_address']??'') ?>',
    customer_contact: '<?= js_escape($quote['customer_contact']??'') ?>',
    employee_name: '<?= js_escape($quote['employee_name']??'') ?>',
    employee_phone: '<?= js_escape($quote['employee_phone']??'') ?>',
    total_amount: '¥<?= format_money($quote['total_amount']) ?>',
    total_amount_cn: '',
    remark: '<?= js_escape($quote['remark']??'') ?>',
    company_name: '<?= js_escape($companyName) ?>',
    company_address: '<?= js_escape($companyAddress) ?>',
    company_phone: '<?= js_escape($companyPhone) ?>',
    items: <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
};

// numToCny 已抽取为全站唯一实现：assets/js/num-cny.js
// （由 includes/header.php 在页面 head 里引入，本页不再内联定义，避免多份副本算出不同结果）
printData.total_amount_cn = numToCny(<?= $quote['total_amount'] ?>);

// 商品图片：首屏不加载、不编码，只在打印/导出时按需转 dataURL
// （公共实现见 assets/js/print-image.js：absUrl / preparePrintImages / runPrintAction / previewImage）

function buildItemsHtml(items, templateHtml) {
    if (!items || !items.length) return '<tr><td colspan="10">暂无明细数据</td></tr>';
    var colMap = {
        '序号':'__idx__','SKU':'sku','编码':'sku','商品名称':'product_name','产品名称':'product_name',
        '规格':'spec','单位':'unit_name','数量':'quantity','单价':'price','价格':'price',
        '金额':'amount','备注':'__remark__',
        '产品图片':'__image__','描述':'__description__'
    };
    var theadMatch = templateHtml.match(/<thead>([\s\S]*?)<\/thead>/);
    var columns = [];
    if (theadMatch) {
        var thRe = /<th[^>]*>(.*?)<\/th>/g, m;
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
            } else if (field === '__image__') {
                if (item.image_base64) {
                    rows += '<td><img src="' + item.image_base64 + '" style="max-width:100px;max-height:75px;object-fit:contain;" alt=""></td>';
                } else if (item.product_image) {
                    rows += '<td><img src="' + absUrl(item.product_image) + '" loading="lazy" style="max-width:100px;max-height:75px;object-fit:contain;" alt=""></td>';
                } else {
                    rows += '<td style="color:#999;">-</td>';
                }
            } else if (field === '__description__') {
                var desc = (item.product_description || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                rows += '<td style="text-align:left;vertical-align:top;line-height:1.5;white-space:pre-line;">' + desc + '</td>';
            } else if (field && item[field] !== undefined && item[field] !== null) {
                var val = String(item[field]);
                if (field === 'price' || field === 'amount') val = '¥' + Number(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                var tdStyle = '';
                if (columns[c] === '产品名称' || columns[c] === '商品名称') {
                    tdStyle = ' style="text-align:left;word-break:break-all;"';
                }
                rows += '<td' + tdStyle + '>' + val + '</td>';
            } else {
                rows += '<td></td>';
            }
        }
        rows += '</tr>';
    }
    return rows;
}

function renderPrintContent() {
    var tpl = <?= json_encode($tpl['content'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    // 替换变量
    tpl = tpl.replace(/\{items\}/g, buildItemsHtml(printData.items, tpl));
    for (var key in printData) {
        if (printData.hasOwnProperty(key) && key !== 'items') {
            var val = String(printData[key] == null ? '' : printData[key]);
            // 总备注可能分行：HTML 会把换行折叠成空格，转成 <br> 打印出来才能正常分行
            if (key === 'remark') val = val.replace(/\n/g, '<br>');
            var re = new RegExp('\\{' + key + '\\}', 'g');
            // 用函数形式返回替换值：避免值里的 $& / $1 被当成正则捕获组引用而丢失
            tpl = tpl.replace(re, function(){ return val; });
        }
    }
    document.getElementById('printContent').innerHTML = tpl;
}

function printQuote() {
    runPrintAction(function () {
    renderPrintContent();
    var win = window.open('', '_blank', 'width=900,height=600');
    win.document.write('<html><head><title>报价单打印</title>');
    win.document.write('<style>body{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;vertical-align:middle;}table th{font-weight:bold;}@media print{body{padding:0;margin:0;}.noprint{display:none;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('printContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
    }, { items: printData.items });
}

function exportPDF() {
    runPrintAction(function () {
    renderPrintContent();
    var win = window.open('', '_blank', 'width=900,height=600');
    win.document.write('<html><head><title>报价单 - <?= js_escape($quote['bill_no']) ?></title>');
    win.document.write('<style>body{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;vertical-align:middle;}table th{font-weight:bold;}@media print{body{padding:0;margin:0;}.noprint{display:none;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('printContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){
        win.print();
        setTimeout(function(){ win.close(); }, 1000);
    }, 500);
    }, { items: printData.items });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
