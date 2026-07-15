<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('print_template');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $type = $_POST['type'] ?? 'sales_order';
        $content = $_POST['content'] ?? '';
        $isDefault = intval($_POST['is_default'] ?? 0);

        if ($isDefault) $pdo->exec("UPDATE print_templates SET is_default=0");
        if ($id > 0) {
            $pdo->prepare("UPDATE print_templates SET name=?,type=?,content=?,is_default=? WHERE id=?")->execute([$name,$type,$content,$isDefault,$id]);
        } else {
            $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default,created_at) VALUES (?,?,?,?,?)")->execute([$name,$type,$content,$isDefault,date('Y-m-d H:i:s')]);
        }
        add_log(get_user_id(), 'save', 'print_template', "保存打印模板: $name");
        redirect('print_tpl.php');
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM print_templates WHERE id=?")->execute([intval($_POST['id']??0)]);
        redirect('print_tpl.php');
    }
}

$templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC")->fetchAll();
$typeLabels = ['sales_order'=>'销售单','sales_outstock'=>'销售出库单','purchase_order'=>'采购单','purchase_instock'=>'采购入库单'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-print"></i> 打印模板</h1>
    <button class="btn btn-primary" onclick="openModal('tplModal');document.getElementById('tplTitle').textContent='新增模板';document.getElementById('tplId').value=0;document.getElementById('tplName').value='';document.getElementById('tplContent').value='';"><i class="fa-solid fa-plus"></i> 新增模板</button>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">模板列表</h3>
        <div class="alert alert-info" style="margin:0;padding:6px 12px;font-size:12px;">可用变量：{company_name} {bill_no} {bill_date} {customer_name} {customer_phone} {customer_address} {supplier_name} {warehouse_name} {items} {total_amount} {remark} {user_name} {tracking_no}</div>
    </div>
    <div class="card-body">
        <h4 style="margin-bottom:10px;font-size:14px;">📋 可用变量说明</h4>
        <table style="font-size:13px;width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9;"><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">变量名</th><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">说明</th><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">适用单据</th></tr></thead>
            <tbody>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{company_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">公司名称（系统设置中配置）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bill_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据编号</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bill_date}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据日期</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_phone}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户联系电话</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_address}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户地址</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{supplier_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">供应商名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">采购单/采购入库单</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{warehouse_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">仓库名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{items}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">商品明细行（自动匹配&lt;thead&gt;列名生成&lt;tr&gt;），支持的列名：序号、SKU、商品名称、规格、单位、数量、单价、金额、备注</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{total_amount}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">合计金额</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{remark}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据总备注</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{user_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">制单人（当前登录用户）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{tracking_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">售后追踪码（含二维码图片和追踪码编号，无追踪码时为空）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            </tbody>
        </table>
        <p style="font-size:12px;color:#666;margin-top:8px;">💡 提示：模板使用HTML格式，变量用花括号包裹。建议在模板末尾预留 制单人、审核人、客户签字、业务签字 等签字栏。</p>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>模板名称</th><th>类型</th><th>默认</th><th>更新时间</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach ($templates as $tpl): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($tpl['name']) ?></strong></td>
                    <td><?= $typeLabels[$tpl['type']]??$tpl['type'] ?></td>
                    <td><?= $tpl['is_default'] ? '<span class="badge badge-success">默认</span>' : '<span class="badge badge-gray">-</span>' ?></td>
                    <td><?= $tpl['updated_at'] ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="editTpl(<?= htmlspecialchars(json_encode($tpl, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline" onclick="previewTpl(<?= htmlspecialchars(json_encode($tpl, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-eye"></i></button>
                        <?php if (!$tpl['is_default']): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$tpl['id']?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="modal-overlay" id="tplModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="tplTitle">新增模板</h3><button class="modal-close" onclick="closeModal('tplModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tplId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">模板名称 <span class="required">*</span></label><input type="text" name="name" id="tplName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">类型</label><select name="type" id="tplType" class="form-control"><?php foreach($typeLabels as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">设为默认</label><select name="is_default" id="tplDefault" class="form-control"><option value="0">否</option><option value="1">是</option></select></div>
    </div>
    <div class="form-group">
        <label class="form-label">HTML模板内容 <span class="required">*</span></label>
        <textarea name="content" id="tplContent" class="form-control" rows="15" style="font-family:monospace;" required></textarea>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('tplModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 预览弹窗 -->
<div class="modal-overlay" id="previewModal"><div class="modal modal-lg" style="max-width:900px;"><div class="modal-header">
    <h3 class="modal-title">模板预览</h3>
    <button class="modal-close" onclick="closeModal('previewModal')">&times;</button>
</div>
<div class="modal-body" id="previewContent" style="background:#fff;min-height:300px;"></div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="printPreview()"><i class="fa-solid fa-print"></i> 打印</button>
    <button type="button" class="btn btn-outline" onclick="closeModal('previewModal')">关闭</button>
</div></div></div>

<script>
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

function editTpl(d){
    document.getElementById('tplTitle').textContent='编辑模板';
    document.getElementById('tplId').value=d.id;
    document.getElementById('tplName').value=d.name;
    document.getElementById('tplType').value=d.type;
    document.getElementById('tplDefault').value=d.is_default;
    document.getElementById('tplContent').value=d.content;
    openModal('tplModal');
}
function previewTpl(d){
    var sampleItems = [
        {product_name:'示例商品A',sku:'SP001',spec:'500ml',unit_name:'瓶',quantity:10,price:'100.00',amount:'1000.00'},
        {product_name:'示例商品B',sku:'SP002',spec:'1kg',unit_name:'袋',quantity:20,price:'50.00',amount:'1000.00'},
        {product_name:'示例商品C',sku:'SP003',spec:'100g',unit_name:'个',quantity:50,price:'211.60',amount:'10580.00'}
    ];
    var sample = {
        company_name: 'XX贸易有限公司',
        bill_no: 'CK20260704001',
        bill_date: '2026-07-04',
        customer_name: '张三客户',
        customer_phone: '13800138000',
        customer_address: 'XX省XX市XX区XX路XX号',
        supplier_name: '李四供应商',
        warehouse_name: '总仓',
        total_amount: '¥12,580.00',
        user_name: '管理员',
        tracking_no: '<div style="text-align:center;font-size:12px;">追踪码：ZS202607040001</div>'
    };
    var html = d.content;
    for (var k in sample) {
        html = html.replace(new RegExp('\\{'+k+'\\}', 'g'), sample[k]);
    }
    html = html.replace(/\{items\}/g, buildItemsHtml(sampleItems, d.content));
    document.getElementById('previewContent').innerHTML = html;
    openModal('previewModal');
}
function printPreview(){
    var win = window.open('', '_blank', 'width=800,height=600');
    win.document.write('<html><head><title>模板预览打印</title>');
    win.document.write('<style>body{font-family:SimSun;padding:20px;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:6px;text-align:center;font-size:13px;}@media print{body{padding:0;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('previewContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
