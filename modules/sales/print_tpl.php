<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('print_template');
$pdo = getDB();
ensure_print_templates_table($pdo);

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
$typeLabels = ['sales_order'=>'销售单','sales_outstock'=>'销售出库单','purchase_order'=>'采购单','purchase_instock'=>'采购入库单','quote'=>'报价方案单','product_catalog'=>'产品目录','sales_contract'=>'销售合同'];

// 自动初始化"产品项目方案单"模板（首次访问时插入，已存在则跳过）
$quoteTplName = '产品项目方案单（含图片+描述）';
$existQuote = $pdo->prepare("SELECT COUNT(*) FROM print_templates WHERE name=?");
$existQuote->execute([$quoteTplName]);
if ($existQuote->fetchColumn() == 0) {
    $quoteTplContent = '<div style="font-family:SimSun,Arial;padding:10px;max-width:900px;margin:0 auto;color:#000;">'
    // 顶部黑底标题区
    . '<div style="background:#000;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-radius:4px 4px 0 0;">'
    . '<h1 style="margin:0;font-size:28px;font-weight:bold;letter-spacing:4px;">产品项目方案单</h1>'
    . '<div style="text-align:right;line-height:1.7;">'
    . '<div style="font-size:18px;font-weight:bold;">{company_name}</div>'
    . '<div style="font-size:13px;opacity:0.9;">{company_address}</div>'
    . '</div></div>'
    // TO 客户区
    . '<div style="padding:10px 0 4px 0;font-size:14px;line-height:1.9;">'
    . '<div><strong>TO：</strong>{customer_name}</div>'
    . '<div style="display:flex;flex-wrap:wrap;gap:20px;">'
    . '<span><strong>报价日期：</strong>{bill_date}</span>'
    . '<span><strong>电话：</strong>{customer_phone}</span>'
    . '<span><strong>联系人：</strong>{customer_contact}</span>'
    . '</div></div>'
    // 致谢语
    . '<div style="background:#ffc000;padding:8px 16px;font-weight:bold;margin:8px 0 0 0;font-size:14px;border:1px solid #000;border-bottom:none;">感谢您对本公司的支持与信赖，贵公司所需产品报价如下：</div>'
    // 商品表
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
    // 合计区
    . '<div style="background:#ffc000;padding:10px 16px;display:flex;justify-content:space-between;font-weight:bold;font-size:14px;border:1px solid #000;border-top:none;">'
    . '<span>小写合计（人民币）：{total_amount}</span>'
    . '<span>大写合计（人民币）：{total_amount_cn}</span>'
    . '</div>'
    // 备注条款
    . '<div style="background:#ffc000;padding:10px 16px;font-size:13px;line-height:1.9;border:1px solid #000;border-top:1px dashed #000;">'
    . '<strong>备注：</strong><br>{remark}'
    . '</div>'
    . '</div>';
    try {
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,0)")
            ->execute([$quoteTplName, 'quote', $quoteTplContent]);
    } catch (Exception $e) {
        error_log('print_templates quote init failed: ' . $e->getMessage());
    }
    $templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC")->fetchAll();
}

// 自动初始化"产品销售合同"模板（首次访问时插入，已存在则跳过）
// 条款结构参照造雪机供货合同样板：六条式（采购清单/付款/验收/保修/争议/其他）+ 报价明细作附件
$contractTplName = '产品销售合同（标准版）';
$existContract = $pdo->prepare("SELECT COUNT(*) FROM print_templates WHERE name=?");
$existContract->execute([$contractTplName]);
if ($existContract->fetchColumn() == 0) {
    $contractTplContent = '<div style="font-family:SimSun,Arial;padding:10px;max-width:900px;margin:0 auto;color:#000;">'
    . '<div style="text-align:center;font-size:22px;font-weight:bold;letter-spacing:6px;margin-bottom:6px;">供 货 合 同</div>'
    . '<div style="text-align:right;font-size:12px;color:#555;margin-bottom:8px;">合同编号：{contract_no}</div>'
    // 甲乙双方
    // 顶部双方信息：纯文本块，甲方在上、乙方在下，不加边框也不用表格。
    // 不用 table 是因为打印 CSS 里 `table td{text-align:center}` 会强制居中，
    // 而这里整块都要左对齐；div 不命中该规则，天然按阅读习惯靠左
    // 两块内容默认按客户资料 / 系统设置联动生成，可在合同编辑页整块改写（支持换行）
    . '<div style="font-size:12px;line-height:1.9;text-align:left;margin-bottom:10px;">'
    . '<div style="font-weight:bold;">甲方（需方）</div>'
    . '<div style="margin-bottom:8px;">{party_a_info}</div>'
    . '<div style="font-weight:bold;">乙方（供方）</div>'
    . '<div>{party_b_info}</div>'
    . '</div>'
    . '<div style="font-size:12px;line-height:1.9;margin:10px 0;">根据《中华人民共和国民法典》及相关法律的规定，就甲方向乙方购买商品事宜，双方经友好协商签订如下合同：</div>'
    // 第一条 采购清单
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第一条 设备采购清单</div>'
    . '<div style="font-size:12px;line-height:1.9;">{purchase_desc}，{tax_note}；本合同设备备货周期为 {prep_days} 个工作日。</div>'
    // 第二条 付款
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第二条 付款方式、步骤</div>'
    . '<div style="font-size:12px;line-height:1.9;">{payment_terms}</div>'
    // 第三条 收货验收
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第三条 收货时间、地点和验收</div>'
    . '<div style="font-size:12px;line-height:1.9;">收货地点：{delivery_place}　甲方授权接货经办人：{receiver_name}　电话：{receiver_phone}<br>'
    . '收到货后甲方验货，对产品质量有异议的，应当在收到货物之日以书面方式向乙方提出异议。逾期未提出的，视为验收合格，乙方完成交付义务。</div>'
    // 第四条 保修
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第四条 产品的保修</div>'
    . '<div style="font-size:12px;line-height:1.9;">{warranty}。24小时技术支持热线：{hotline}</div>'
    // 第五条 争议解决
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第五条 争议的解决方式</div>'
    . '<div style="font-size:12px;line-height:1.9;">履行合同过程中发生争议，双方应本着友好协商的态度解决。如协商无效，可向原告所在地人民法院提起相关诉讼。</div>'
    // 第六条 其他
    . '<div style="font-size:13px;font-weight:bold;margin:10px 0 4px;">第六条 其他</div>'
    . '<div style="font-size:12px;line-height:1.9;">{terms}<br>本合同自双方授权代表签字之日起生效；本合同一式两份，双方各执一份。</div>'
    // 合同总金额（附件已独立成页，不再内嵌明细表，故总金额上移到签章区之前）
    . '<div style="text-align:right;font-size:13px;font-weight:bold;padding:6px 0;">合同总金额：￥{total_amount}（大写：{total_amount_cn}）</div>'
    // 签章区
    . '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:14px;"><tr>'
    // 签章区两块同样是可自定义文本。首行标题（甲方（签章）：）已包含在文本块默认值里，
    // 模板不再重复写，否则打印出来是两行标题
    . '<td style="border:1px solid #000;padding:10px;vertical-align:top;height:110px;text-align:left;">{party_a_sign}</td>'
    . '<td style="border:1px solid #000;padding:10px;vertical-align:top;height:110px;text-align:left;">{party_b_sign}</td>'
    . '</tr></table>'
    // 附件：整份报价单，由 {attachment} 占位替换，打印时自动另起一页（版式取自 quote 类模板）
    . '{attachment}'
    . '</div>';
    try {
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,1)")
            ->execute([$contractTplName, 'sales_contract', $contractTplContent]);
    } catch (Exception $e) {
        error_log('print_templates sales_contract init failed: ' . $e->getMessage());
    }
    $templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC")->fetchAll();
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-print"></i> 打印模板</h1>
    <button class="btn btn-primary" onclick="openModal('tplModal');document.getElementById('tplTitle').textContent='新增模板';document.getElementById('tplId').value=0;document.getElementById('tplName').value='';document.getElementById('tplContent').value='';"><i class="fa-solid fa-plus"></i> 新增模板</button>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">模板列表</h3>
        <div class="alert alert-info" style="margin:0;padding:6px 12px;font-size:12px;">可用变量：{company_name} {company_address} {bill_no} {bill_date} {customer_name} {customer_phone} {customer_address} {customer_contact} {supplier_name} {warehouse_name} {employee_name} {employee_phone} {items} {total_amount} {total_amount_cn} {remark} {user_name} {tracking_no}<br><strong>销售合同专用：</strong>{contract_no} {quote_no} {sign_date} {purchase_desc} {tax_note} {prep_days} {payment_terms} {deposit_amount} {deposit_amount_cn} {balance_amount} {balance_amount_cn} {delivery_place} {receiver_name} {receiver_phone} {warranty} {hotline} {bank_name} {bank_account} {attachment}</div>
    </div>
    <div class="card-body">
        <h4 style="margin-bottom:10px;font-size:14px;">📋 可用变量说明</h4>
        <!-- table-layout:fixed —— 默认 auto 布局会尽量容纳最长内容，{items} 的说明过长会把整表撑宽；
             改 fixed 后按 th 指定的宽度分配，超出部分自动换行（word-break 可继承到 td） -->
        <table style="font-size:13px;width:100%;border-collapse:collapse;table-layout:fixed;word-break:break-word;overflow-wrap:break-word;">
            <thead><tr style="background:#f1f5f9;"><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;width:170px;">变量名</th><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;">说明</th><th style="padding:6px 10px;text-align:left;border:1px solid #e2e8f0;width:130px;">适用单据</th></tr></thead>
            <tbody>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{company_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">公司名称（系统设置中配置）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bill_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据编号</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bill_date}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据日期</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{company_address}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">公司地址（系统设置中配置）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_phone}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户联系电话</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_address}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户地址</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{customer_contact}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">客户联系人</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{supplier_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">供应商名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">采购单/采购入库单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{warehouse_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">仓库名称</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{employee_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">业务员姓名</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单/报价单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{employee_phone}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">业务员电话</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单/报价单</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{items}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">商品明细行，自动匹配模板 &lt;thead&gt; 列名生成 &lt;tr&gt;。<br>支持的列名：<strong>序号</strong>、SKU/编码、商品名称/产品名称、规格、单位、数量、单价/价格、金额、备注、产品图片（base64 嵌入，120×90px）、描述（取商品描述字段）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{total_amount}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">合计金额</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{total_amount_cn}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">合计金额大写（手动填写）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{remark}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">单据总备注</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{user_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">制单人（当前登录用户）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">全部</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{tracking_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">售后追踪码（含二维码图片和追踪码编号，无追踪码时为空）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售单/销售出库单</td></tr>
            <tr style="background:#f8fafc;"><td colspan="3" style="padding:5px 10px;border:1px solid #e2e8f0;font-weight:bold;">销售合同专用（以下变量仅 type=销售合同 的模板有效）</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{contract_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">合同编号（HT+日期+序号）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{quote_no}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">来源报价单号</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{sign_date}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">签约日期</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{purchase_desc}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">采购描述（选报价单后自动生成，可改）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{tax_note}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">价格说明，如「不含税不含运费」</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{prep_days}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">备货周期（工作日）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{payment_terms}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">付款条款（按所选付款方式自动生成，可手工改）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{deposit_amount}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">定金金额</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{deposit_amount_cn}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">定金金额大写</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{balance_amount}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">尾款金额（合同总额 - 定金）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{balance_amount_cn}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">尾款金额大写</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{delivery_place}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">收货地点</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{receiver_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">甲方授权接货经办人</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{receiver_phone}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">接货人电话</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{warranty}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">质保条款，如「主机质保3年」</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{hotline}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">技术支持热线</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bank_name}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">乙方（供方）开户行</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{bank_account}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">乙方（供方）账号号码</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
            <tr><td style="padding:4px 10px;border:1px solid #e2e8f0;"><code>{attachment}</code></td><td style="padding:4px 10px;border:1px solid #e2e8f0;">附件：整份报价单，打印时<strong>自动另起一页</strong>（版式取自报价单模板）</td><td style="padding:4px 10px;border:1px solid #e2e8f0;">销售合同</td></tr>
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
                        <button class="btn btn-sm btn-outline" title="复制该模板：基于它生成一份副本，改个名就能改成冬季版/夏季版" onclick="copyTpl(<?= htmlspecialchars(json_encode($tpl, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-copy"></i></button>
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
// ===== 打印样式：预览弹窗与打印窗口共用同一份定义 =====
// 以前预览走页面 CSS、打印走另一套内联样式，两边不一致，
// 于是出现「预览靠左、打印却居中」这类差异。现在只在这里维护一份规则：
//   - 打印窗口：原样写入新窗口（全局选择器）
//   - 预览弹窗：把 body / table 等顶层选择器限定到 #previewContent 内后注入本页
// 用 __ROOT__ 作为「根选择器」占位符，一份规则派生出两种用法：
//   - 打印窗口：__ROOT__ → body（全局生效）
//   - 预览弹窗：__ROOT__ → #previewContent（限定作用域，不影响后台其它表格）
// 注意不要用 replace('table') 这类字面替换：那会把 `.contract-doc table td` 里的
// table 也一起替换，拼出 `.contract-doc #previewContent table td` 这种永不匹配的选择器
var PRINT_CSS_TMPL = '__ROOT__{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}'
    + '*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}'
    + '__ROOT__ table{border-collapse:collapse;width:100%;}'
    + '__ROOT__ table th,__ROOT__ table td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;vertical-align:middle;}'
    + '__ROOT__ table th{font-weight:bold;}'
    // 合同正文的表格左对齐（甲乙双方表、签章区），与 contract_view.php 的打印效果保持一致；
    // 其它单据的明细表依赖上面的居中，所以只针对 .contract-doc 生效
    + '__ROOT__ .contract-doc table td{text-align:left;}'
    // 合同附件是整份报价单，恢复单据惯用的居中排版（特异性更高，覆盖上一条）
    + '__ROOT__ .contract-doc .contract-attachment table td{text-align:center;}'
    + '@media print{__ROOT__{padding:0;margin:0;}.noprint{display:none;}}';

// 打印窗口用（全局）
var PRINT_CSS_RULES = PRINT_CSS_TMPL.replace(/__ROOT__/g, 'body');
// 预览弹窗用（限定到 #previewContent 内）
var PREVIEW_SCOPE_CSS = PRINT_CSS_TMPL.replace(/__ROOT__/g, '#previewContent');

(function () {
    var st = document.createElement('style');
    st.textContent = PREVIEW_SCOPE_CSS;
    document.head.appendChild(st);
})();

// 数字转中文大写金额（用于 {total_amount_cn} 变量）
// numToCny 已抽取为全站唯一实现：assets/js/num-cny.js
// （由 includes/header.php 在页面 head 里引入，本页不再内联定义，避免多份副本算出不同结果）

// 智能生成商品明细行：根据模板<thead>列名自动匹配数据字段
function buildItemsHtml(items, templateHtml) {
    if (!items || !items.length) return '<tr><td colspan="10">暂无明细数据</td></tr>';
    // 列名 → 数据字段 映射（支持产品项目方案单：产品图片/描述/编码/产品名称/价格）
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

function editTpl(d){
    document.getElementById('tplTitle').textContent='编辑模板';
    document.getElementById('tplId').value=d.id;
    document.getElementById('tplName').value=d.name;
    document.getElementById('tplType').value=d.type;
    document.getElementById('tplDefault').value=d.is_default;
    document.getElementById('tplContent').value=d.content;
    openModal('tplModal');
}
// 复制 = 打开同一个编辑弹窗，但把 id 归零，保存时自然走新增分支（后端 id=0 即 INSERT），
// 所以后端一行都不用改。
// is_default 强制 0：后端一旦收到 is_default=1，会先把其它模板的默认标记全清掉，
// 副本要是带着默认标记就会把原模板顶下去
function copyTpl(d){
    document.getElementById('tplTitle').textContent='复制模板';
    document.getElementById('tplId').value=0;
    document.getElementById('tplName').value=d.name + ' 副本';
    document.getElementById('tplType').value=d.type;
    document.getElementById('tplDefault').value='0';
    document.getElementById('tplContent').value=d.content;
    openModal('tplModal');
    // 名称全选并聚焦：副本默认叫「XXX 副本」，光标直接落在上面方便改成「冬季版」这类名字
    var nameEl = document.getElementById('tplName');
    nameEl.focus();
    nameEl.select();
}
function previewTpl(d){
    var sampleItems = [
        {sku:'D2',product_name:'造雪机',product_image:'',product_description:'外形尺寸：长2600mm宽2100mm高宽2200mm<br>造雪机喷嘴数量：216个<br>出雪量：6.22-64.48m³/h<br>电压值：7.5 kw/h<br>喷射半径为65m-60m<br>保修时间：3年',unit_name:'台',quantity:4,price:'55000.00',amount:'220000.00'},
        {sku:'D3',product_name:'滑圈',product_image:'',product_description:'滑圈成人100公分<br>材质：全新HDPE聚乙烯<br>橡皮 加厚牛筋布<br>内胆：加厚耐磨丁基胶',unit_name:'个',quantity:100,price:'95.00',amount:'9500.00'},
        {sku:'D5',product_name:'枫光无限雪地转转',product_image:'',product_description:'树形树高3.3米<br>树形直径：6米<br>臂长：2.8米<br>转盘高度：1.8米<br>转盘直径：0.47米<br>电机：3kw<br>电压：380V<br>乘坐人数4人<br>驱动减速机',unit_name:'台',quantity:1,price:'10800.00',amount:'10800.00'},
        {sku:'S1',product_name:'香蕉船',product_image:'',product_description:'单人<br>材质：PVC夹网布<br>尺寸规格可定做',unit_name:'条',quantity:1,price:'1180.00',amount:'1180.00'}
    ];
    var sample = {
        company_name: '牡丹江万丰机械制造有限公司',
        company_address: '河北省石家庄市藁城区廉北路31号',
        bill_no: 'CK20261103001',
        bill_date: '2026年11月3日',
        customer_name: '（客户单位）',
        customer_phone: '15612167779',
        customer_address: '',
        customer_contact: '丁毅',
        supplier_name: '李四供应商',
        warehouse_name: '总仓',
        employee_name: '张业务',
        employee_phone: '13800138000',
        total_amount: '¥241,480.00',
        total_amount_cn: '贰拾肆万壹仟肆佰捌拾元整',
        user_name: '丁毅',
        remark: '1、以上报价为不含税不含运费价格。\n2、报价有效期为7天，逾期请及时与我司商务联系。\n3、付款方式：全款发货。',
        tracking_no: ''
    };
    // 自动从 total_amount 计算中文大写
    var rawAmt = parseFloat(String(sample.total_amount).replace(/[¥,\s]/g, ''));
    if (!isNaN(rawAmt)) sample.total_amount_cn = numToCny(rawAmt);
    var html = d.content;
    for (var k in sample) {
        var v = String(sample[k] == null ? '' : sample[k]);
        // 与详情页打印保持一致：示例备注本身是多行文本（见上方 remark），
        // 不转 <br> 的话 HTML 会把换行折叠成一行，预览效果就和实际打印不一致了
        if (k === 'remark') v = v.replace(/\n/g, '<br>');
        html = html.replace(new RegExp('\\{'+k+'\\}', 'g'), function(){ return v; });
    }
    html = html.replace(/\{items\}/g, buildItemsHtml(sampleItems, d.content));
    if (d.type === 'sales_contract') {
        // {attachment} 在预览里没有真实报价单可填，换成提示条，免得直接把占位符显示出来
        html = html.replace(/\{attachment\}/g, '<div style="margin-top:16px;padding:10px;border:1px dashed #bbb;color:#888;font-size:12px;text-align:center;">［此处另起一页，插入附件：整份报价单］</div>');
        // 包 .contract-doc，使正文表格左对齐 —— 与合同详情页的打印效果保持一致
        html = '<div class="contract-doc">' + html + '</div>';
    }
    document.getElementById('previewContent').innerHTML = html;
    openModal('previewModal');
}
function printPreview(){
    var win = window.open('', '_blank', 'width=900,height=600');
    win.document.write('<html><head><title>模板预览打印</title>');
    win.document.write('<style>' + PRINT_CSS_RULES + '</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('previewContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
