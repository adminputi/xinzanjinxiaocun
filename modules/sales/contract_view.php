<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_contract');
$pdo = getDB();
run_migrations();

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM sales_contracts WHERE id=?");
$st->execute([$id]);
$contract = $st->fetch();
if (!$contract) { flash_set('合同不存在', 'danger'); redirect('contract.php'); }

// 来源报价单与其明细（合同明细即报价明细，打印时作为附件）
$st = $pdo->prepare("SELECT q.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.contact as customer_contact
    FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.id=?");
$st->execute([$contract['quote_id']]);
$quote = $st->fetch();

$st = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.image as product_image, p.description as product_description, u.name as unit_name
    FROM sales_quote_items i
    LEFT JOIN products p ON i.product_id=p.id
    LEFT JOIN units u ON p.unit_id=u.id
    WHERE i.quote_id=? ORDER BY i.id");
$st->execute([$contract['quote_id']]);
$items = $st->fetchAll();

// 已转订单：金额汇总（合同按金额控制履约，不逐商品记录已转数量）
$st = $pdo->prepare("SELECT id, bill_no, total_amount, status FROM sales_orders WHERE contract_id=? AND status<>'cancelled' ORDER BY id");
$st->execute([$id]);
$orders = $st->fetchAll();
$orderedAmount = 0;
foreach ($orders as $o) { $orderedAmount += floatval($o['total_amount']); }
$remainAmount = floatval($contract['total_amount']) - $orderedAmount;
if ($remainAmount < 0) $remainAmount = 0;

$employeePhone = '';
$st = $pdo->prepare("SELECT real_name, phone FROM users WHERE id=?");
$st->execute([$contract['employee_id']]);
if ($row = $st->fetch()) {
    $employeeName  = $row['real_name'] ?: '';
    $employeePhone = $row['phone'] ?: '';
} else {
    $employeeName = '';
}

$companyName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';

// 生成「标签：值」多行文本；值为空则整行不输出，避免出现「地址：」这种空行
function contract_party_lines($pairs) {
    $out = [];
    foreach ($pairs as $label => $val) {
        $val = trim((string)($val ?? ''));
        if ($val === '') continue;
        $out[] = $label . '：' . $val;
    }
    return implode("\n", $out);
}

// 签章块：首行是「甲方（签章）：」这类标题，其余按「标签：值」输出。
// 标题放进文本块里，模板就不必再写一遍，否则会重复显示两行标题
function contract_party_sign($party, $pairs) {
    $body = contract_party_lines($pairs);
    return $party . '（签章）：' . ($body === '' ? '' : "\n" . $body);
}

// 这四个块是后加的字段，老合同里必然为空。为空时按现有资料就地生成默认内容，
// 否则打印出来顶部双方信息和签章区会是一片空白
if (trim((string)($contract['party_a_info'] ?? '')) === '') {
    $contract['party_a_info'] = contract_party_lines([
        '甲方'   => $quote['customer_name'] ?? '',
        '地址'   => $quote['customer_address'] ?? '',
        '联系人' => $quote['customer_contact'] ?? '',
        '电话'   => $quote['customer_phone'] ?? '',
    ]);
}
if (trim((string)($contract['party_b_info'] ?? '')) === '') {
    $contract['party_b_info'] = contract_party_lines([
        '乙方' => $companyName,
        '地址' => $companyAddress,
        '电话' => $companyPhone,
    ]);
}
if (trim((string)($contract['party_a_sign'] ?? '')) === '') {
    $contract['party_a_sign'] = contract_party_sign('甲方', [
        '地址'     => $quote['customer_address'] ?? '',
        '联系人'   => $quote['customer_contact'] ?? '',
        '联系电话' => $quote['customer_phone'] ?? '',
        '签约日期' => $contract['sign_date'] ?? '',
    ]);
}
if (trim((string)($contract['party_b_sign'] ?? '')) === '') {
    $contract['party_b_sign'] = contract_party_sign('乙方', [
        '账号号码' => $contract['bank_account'] ?? '',
        '开户行'   => $contract['bank_name'] ?? '',
        '联系电话' => $companyPhone,
        '签约日期' => $contract['sign_date'] ?? '',
    ]);
}

$statusLabels = ['draft'=>'草稿','confirmed'=>'已生效','executing'=>'执行中','completed'=>'已完成','terminated'=>'已终止'];
$statusBadges = ['draft'=>'warning','confirmed'=>'info','executing'=>'primary','completed'=>'success','terminated'=>'gray'];

$err = ''; $ok = '';

// ===== 转订单：按剩余额度控制，支持一份合同分多次下单 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'to_order') {
    csrf_verify();
    if (!in_array($contract['status'], ['confirmed', 'executing'], true)) {
        $err = '仅「已生效」或「执行中」的合同可转订单';
    } else {
        $qtyPost = $_POST['qty'] ?? [];
        $orderItems = [];
        $thisAmount = 0;
        foreach ($items as $it) {
            $q = floatval($qtyPost[$it['id']] ?? 0);
            if ($q <= 0) continue;
            $amt = round($q * floatval($it['price']), 2);
            $orderItems[] = [
                'product_id' => $it['product_id'],
                'quantity'   => $q,
                'price'      => $it['price'],
                'amount'     => $amt,
                'remark'     => $it['remark'],
            ];
            $thisAmount += $amt;
        }
        if (!$orderItems) {
            $err = '请至少填写一项转出数量';
        } elseif ($remainAmount <= 0.009) {
            $err = '合同额度已全部转出，无法再生成订单';
        } elseif ($thisAmount > $remainAmount + 0.01) {
            $err = '本次金额 ¥' . number_format($thisAmount, 2) . ' 超出合同剩余额度 ¥' . number_format($remainAmount, 2) . '，请调减数量';
        } else {
            $wh = $pdo->query("SELECT id FROM warehouses WHERE status=1 LIMIT 1")->fetch();
            $warehouseId = $wh ? $wh['id'] : 0;
            if (!$warehouseId) {
                $err = '系统未设置仓库，请先在仓库管理中添加仓库';
            } else {
                $pdo->beginTransaction();
                try {
                    $orderBillNo = generate_bill_no('XS');
                    $pdo->prepare("INSERT INTO sales_orders (bill_no,customer_id,warehouse_id,total_amount,status,order_date,employee_id,remark,contract_id,user_id,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $orderBillNo, $contract['customer_id'], $warehouseId, $thisAmount, 'draft',
                            date('Y-m-d'), $contract['employee_id'], $contract['remark'], $id, get_user_id(), date('Y-m-d H:i:s')
                        ]);
                    $orderId = $pdo->lastInsertId();
                    $insStmt = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
                    foreach ($orderItems as $oi) {
                        $insStmt->execute([$orderId, $oi['product_id'], $oi['quantity'], $oi['price'], $oi['amount'], $oi['remark']]);
                    }
                    // 额度转完置 completed，否则 executing
                    $newStatus = ($thisAmount >= $remainAmount - 0.01) ? 'completed' : 'executing';
                    $pdo->prepare("UPDATE sales_contracts SET status=? WHERE id=?")->execute([$newStatus, $id]);
                    add_log(get_user_id(), 'create', 'sales_contract', "合同转订单: {$contract['contract_no']} → {$orderBillNo}");
                    $pdo->commit();
                    flash_set("已生成销售订单 {$orderBillNo}", 'success');
                    redirect('contract_view.php?id=' . $id);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Contract to_order error: ' . $e->getMessage());
                    $err = '转订单失败：' . $e->getMessage();
                }
            }
        }
    }
}

// ===== 打印模板：优先用合同指定模板，否则取 sales_contract 默认模板 =====
ensure_print_templates_table($pdo);

// ===== 附件页模板 =====
// 合同正文之后另起一页，按「报价单」的完整版式渲染（黑底标题 / TO 客户 / 商品表 / 合计 / 备注），
// 而不是把明细表内嵌进合同正文。取模板规则与 quote_view.php 保持一致：
// 用户在「打印模板」里改报价单版式，合同附件页同步生效。
$quoteTpl = null;
$st = $pdo->prepare("SELECT * FROM print_templates WHERE name=? LIMIT 1");
$st->execute(['产品项目方案单（含图片+描述）']);
$quoteTpl = $st->fetch();
if (!$quoteTpl) {
    $st = $pdo->prepare("SELECT * FROM print_templates WHERE type='quote' ORDER BY is_default DESC, id LIMIT 1");
    $st->execute();
    $quoteTpl = $st->fetch();
}
$quoteTplContent = $quoteTpl['content'] ?? '';
// 兜底：报价单模板尚未初始化（用户从未访问过「打印模板」页）时，用简化版式保证附件页一定有内容
if ($quoteTplContent === '') {
    $quoteTplContent = '<div style="font-family:SimSun,Arial;padding:10px;color:#000;">'
        . '<div style="background:#000;color:#fff;padding:12px 16px;font-size:22px;font-weight:bold;letter-spacing:4px;">产品报价单</div>'
        . '<div style="font-size:13px;line-height:1.9;padding:8px 0;">'
        . '<div><strong>报价单号：</strong>{bill_no}　<strong>报价日期：</strong>{bill_date}</div>'
        . '<div><strong>TO：</strong>{customer_name}　电话：{customer_phone}　联系人：{customer_contact}</div>'
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#1e6bb8;color:#fff;">'
        . '<th style="padding:5px;">序号</th><th style="padding:5px;">商品名称</th><th style="padding:5px;">规格</th>'
        . '<th style="padding:5px;">单位</th><th style="padding:5px;">数量</th><th style="padding:5px;">单价</th>'
        . '<th style="padding:5px;">金额</th><th style="padding:5px;">备注</th>'
        . '</tr></thead><tbody>{items}</tbody></table>'
        . '<div style="font-size:13px;font-weight:bold;padding:8px 0;text-align:right;">合计：{total_amount}（大写：{total_amount_cn}）</div>'
        . '<div style="font-size:12px;line-height:1.9;"><strong>备注：</strong><br>{remark}</div>'
        . '</div>';
}

$tpl = null;
if (!empty($contract['template_id'])) {
    $st = $pdo->prepare("SELECT * FROM print_templates WHERE id=? AND type='sales_contract'");
    $st->execute([$contract['template_id']]);
    $tpl = $st->fetch();
}
if (!$tpl) {
    $st = $pdo->prepare("SELECT * FROM print_templates WHERE type='sales_contract' ORDER BY is_default DESC, id LIMIT 1");
    $st->execute();
    $tpl = $st->fetch();
}
// 兜底：模板尚未初始化时也能打印（结构与 print_tpl.php 中初始化的默认合同模板一致）
if (!$tpl || empty($tpl['content'])) {
    $tpl = ['content' => '<div style="text-align:center;font-size:20px;font-weight:bold;margin-bottom:12px;">供 货 合 同</div>'
        . '<div style="text-align:right;font-size:12px;color:#555;">合同编号：{contract_no}</div>'
        // 逐个 td 写 text-align:left：打印 CSS 有 `table td{text-align:center}`，
        // 只写在 <table> 上靠继承会被该选择器打回（继承优先级低于直接命中元素的规则）
        // 顶部双方信息：纯文本块，甲方在上、乙方在下，不加边框也不用表格。
        // 不用 table 是避开打印 CSS 的 `table td{text-align:center}`，整块需要左对齐
        . '<div style="font-size:12px;line-height:1.9;text-align:left;margin:8px 0;">'
        . '<div style="font-weight:bold;">甲方（需方）</div>'
        . '<div style="margin-bottom:8px;">{party_a_info}</div>'
        . '<div style="font-weight:bold;">乙方（供方）</div>'
        . '<div>{party_b_info}</div>'
        . '</div>'
        . '<div style="font-size:12px;line-height:1.8;">根据《中华人民共和国民法典》及相关法律的规定，就甲方向乙方购买商品事宜签订如下合同：</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第一条 设备采购清单</div>'
        . '<div style="font-size:12px;line-height:1.8;">{purchase_desc}，{tax_note}；本合同设备备货周期为 {prep_days} 个工作日。</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第二条 付款方式、步骤</div>'
        . '<div style="font-size:12px;line-height:1.8;">{payment_terms}</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第三条 收货时间、地点和验收</div>'
        . '<div style="font-size:12px;line-height:1.8;">收货地点：{delivery_place}　甲方授权接货经办人：{receiver_name}　电话：{receiver_phone}<br>'
        . '收到货后甲方验货，对产品质量有异议的，应当在收到货物之日以书面方式向乙方提出异议。逾期未提出的，视为验收合格，乙方完成交付义务。</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第四条 产品的保修</div>'
        . '<div style="font-size:12px;line-height:1.8;">{warranty}。24小时技术支持热线：{hotline}</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第五条 争议的解决方式</div>'
        . '<div style="font-size:12px;line-height:1.8;">履行合同过程中发生争议，双方应本着友好协商的态度解决。如协商无效，可向原告所在地人民法院提起相关诉讼。</div>'
        . '<div style="font-size:13px;font-weight:bold;margin:8px 0;">第六条 其他</div>'
        . '<div style="font-size:12px;line-height:1.8;">{terms}<br>本合同自双方授权代表签字之日起生效；本合同一式两份，双方各执一份。</div>'
        . '<div style="text-align:right;font-size:13px;font-weight:bold;padding:6px 0;">合同总金额：￥{total_amount}（大写：{total_amount_cn}）</div>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:12px;"><tr>'
        // 首行标题已包含在 {party_a_sign}/{party_b_sign} 的默认值里，模板不再重复写
        . '<td style="border:1px solid #000;padding:8px;font-size:12px;vertical-align:top;text-align:left;">{party_a_sign}</td>'
        . '<td style="border:1px solid #000;padding:8px;font-size:12px;vertical-align:top;text-align:left;">{party_b_sign}</td></tr></table>'
        // 附件：整份报价单，由 {attachment} 占位替换，打印时自动另起一页
        . '{attachment}'];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> 合同 <?= htmlspecialchars($contract['contract_no']) ?></h1>
    <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-primary" onclick="printContract()"><i class="fa-solid fa-print"></i> 打印</button>
        <button type="button" class="btn btn-outline" onclick="exportPDF()"><i class="fa-solid fa-file-pdf"></i> 导出PDF</button>
        <a href="contract.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card"><div class="card-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">状态</label>
            <div><span class="badge badge-<?= $statusBadges[$contract['status']] ?? 'gray' ?>"><?= $statusLabels[$contract['status']] ?? $contract['status'] ?></span></div>
        </div>
        <div class="form-group"><label class="form-label">甲方（客户）</label>
            <div><?= htmlspecialchars($quote['customer_name'] ?? '-') ?></div>
        </div>
        <div class="form-group"><label class="form-label">来源报价单</label>
            <div><a href="quote_view.php?id=<?= $contract['quote_id'] ?>"><?= htmlspecialchars($quote['bill_no'] ?? '-') ?></a></div>
        </div>
        <div class="form-group"><label class="form-label">业务员</label>
            <div><?= htmlspecialchars($employeeName ?: '-') ?></div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">合同金额</label>
            <div><strong>¥<?= format_money($contract['total_amount']) ?></strong></div>
        </div>
        <div class="form-group"><label class="form-label">已转订单金额</label>
            <div>¥<?= format_money($orderedAmount) ?></div>
        </div>
        <div class="form-group"><label class="form-label">剩余额度</label>
            <div><strong style="color:<?= $remainAmount > 0.009 ? 'var(--success,green)' : 'var(--gray-500)' ?>;">¥<?= format_money($remainAmount) ?></strong></div>
        </div>
        <div class="form-group"><label class="form-label">签约日期</label>
            <div><?= htmlspecialchars($contract['sign_date'] ?: '-') ?></div>
        </div>
    </div>
</div></div>

<div class="card"><div class="card-header"><strong>付款方式</strong></div><div class="card-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">定金(¥)</label><div><?= format_money($contract['deposit_amount']) ?></div></div>
        <div class="form-group"><label class="form-label">尾款(¥)</label><div><?= format_money($contract['balance_amount']) ?></div></div>
        <div class="form-group"><label class="form-label">备货周期</label><div><?= intval($contract['prep_days']) ?> 个工作日</div></div>
        <div class="form-group"><label class="form-label">价格说明</label><div><?= htmlspecialchars($contract['tax_note'] ?: '-') ?></div></div>
    </div>
    <div class="form-group"><label class="form-label">付款条款</label>
        <div class="pre-wrap"><?= nl2br(htmlspecialchars($contract['payment_terms'] ?: '-')) ?></div>
    </div>
</div></div>

<div class="card"><div class="card-header"><strong>交货与验收</strong></div><div class="card-body">
    <div class="form-group"><label class="form-label">采购描述</label><div><?= htmlspecialchars($contract['purchase_desc'] ?: '-') ?></div></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">收货地点</label><div><?= htmlspecialchars($contract['delivery_place'] ?: '-') ?></div></div>
        <div class="form-group"><label class="form-label">接货经办人</label><div><?= htmlspecialchars($contract['receiver_name'] ?: '-') ?></div></div>
        <div class="form-group"><label class="form-label">接货人电话</label><div><?= htmlspecialchars($contract['receiver_phone'] ?: '-') ?></div></div>
        <div class="form-group"><label class="form-label">质保</label><div><?= htmlspecialchars($contract['warranty'] ?: '-') ?></div></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">技术支持热线</label><div><?= htmlspecialchars($contract['hotline'] ?: '-') ?></div></div>
        <div class="form-group"><label class="form-label">乙方开户行</label><div><?= htmlspecialchars($contract['bank_name'] ?: '-') ?></div></div>
        <div class="form-group"><label class="form-label">乙方账号</label><div><?= htmlspecialchars($contract['bank_account'] ?: '-') ?></div></div>
    </div>
    <?php if ($contract['terms']): ?>
    <div class="form-group"><label class="form-label">其他约定</label><div class="pre-wrap"><?= nl2br(htmlspecialchars($contract['terms'])) ?></div></div>
    <?php endif; ?>
    <?php if ($contract['attachment']): ?>
    <div class="form-group"><label class="form-label">盖章扫描件</label>
        <div><a href="../../<?= htmlspecialchars($contract['attachment']) ?>" target="_blank">查看附件</a></div>
    </div>
    <?php endif; ?>
</div></div>

<div class="card"><div class="card-header"><strong>合同明细（来源报价单，打印时作为附件）</strong></div>
<div class="card-body" style="padding:0;"><div class="table-container">
<?php if (in_array($contract['status'], ['confirmed','executing'], true) && $remainAmount > 0.009): ?>
<form method="post" id="toOrderForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="to_order">
    <input type="hidden" name="id" value="<?= $contract['id'] ?>">
<?php endif; ?>
<table>
<thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价(¥)</th><th>金额(¥)</th>
<?php if (in_array($contract['status'], ['confirmed','executing'], true) && $remainAmount > 0.009): ?><th>本次转出数量</th><?php endif; ?>
</tr></thead>
<tbody>
<?php if ($items): foreach ($items as $i => $it): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($it['product_name'] ?: '商品已删除(ID:' . intval($it['product_id']) . ')') ?></td>
    <td><?= htmlspecialchars($it['spec'] ?: '-') ?></td>
    <td><?= htmlspecialchars($it['unit_name'] ?: '-') ?></td>
    <td><?= htmlspecialchars($it['quantity'] + 0) ?></td>
    <td><?= format_money($it['price']) ?></td>
    <td><?= format_money($it['amount']) ?></td>
    <?php if (in_array($contract['status'], ['confirmed','executing'], true) && $remainAmount > 0.009): ?>
    <td><input type="number" name="qty[<?= $it['id'] ?>]" class="form-control" style="width:110px;text-align:center;" value="<?= htmlspecialchars($it['quantity'] + 0) ?>" min="0" max="<?= htmlspecialchars($it['quantity'] + 0) ?>" step="0.01"></td>
    <?php endif; ?>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-boxes-stacked"></i><p>该报价单暂无明细</p></div></td></tr>
<?php endif; ?>
</tbody>
</table>
<?php if (in_array($contract['status'], ['confirmed','executing'], true) && $remainAmount > 0.009): ?>
<div style="padding:12px;">
    <button type="submit" class="btn btn-success"><i class="fa-solid fa-file-export"></i> 生成销售订单</button>
    <small style="color:var(--gray-500);margin-left:8px;">剩余额度 ¥<?= format_money($remainAmount) ?>，可分多次转出</small>
</div>
</form>
<?php endif; ?>
</div></div></div>

<?php if ($orders): ?>
<div class="card"><div class="card-header"><strong>已生成销售订单</strong></div>
<div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>订单号</th><th>金额(¥)</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($orders as $o): ?>
<tr>
    <td><a href="order_view.php?id=<?=$o['id']?>"><strong><?= htmlspecialchars($o['bill_no']) ?></strong></a></td>
    <td><?= format_money($o['total_amount']) ?></td>
    <td><?= htmlspecialchars($o['status']) ?></td>
    <td><a href="order_view.php?id=<?=$o['id']?>" class="btn btn-sm btn-outline">详情</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div></div>
<?php endif; ?>

<div id="printContent" style="display:none;"></div>

<script>
// numToCny 已抽取为全站唯一实现：assets/js/num-cny.js
// （由 includes/header.php 在页面 head 里引入，本页不再内联定义，避免多份副本算出不同结果）
// 打印/导出依赖这个函数替换 {total_amount_cn}，缺了会导致渲染流程中断

var printData = {
    contract_no: '<?= js_escape($contract['contract_no']) ?>',
    quote_no: '<?= js_escape($quote['bill_no'] ?? '') ?>',
    sign_date: '<?= js_escape($contract['sign_date'] ?? '') ?>',
    customer_name: '<?= js_escape($quote['customer_name'] ?? '') ?>',
    customer_phone: '<?= js_escape($quote['customer_phone'] ?? '') ?>',
    customer_address: '<?= js_escape($quote['customer_address'] ?? '') ?>',
    customer_contact: '<?= js_escape($quote['customer_contact'] ?? '') ?>',
    company_name: '<?= js_escape($companyName) ?>',
    company_address: '<?= js_escape($companyAddress) ?>',
    company_phone: '<?= js_escape($companyPhone) ?>',
    employee_name: '<?= js_escape($employeeName) ?>',
    total_amount: '<?= number_format(floatval($contract['total_amount']), 2, '.', '') ?>',
    deposit_amount: '<?= number_format(floatval($contract['deposit_amount']), 2, '.', '') ?>',
    balance_amount: '<?= number_format(floatval($contract['balance_amount']), 2, '.', '') ?>',
    prep_days: '<?= intval($contract['prep_days']) ?>',
    payment_terms: '<?= js_escape($contract['payment_terms'] ?? '') ?>',
    purchase_desc: '<?= js_escape($contract['purchase_desc'] ?? '') ?>',
    delivery_place: '<?= js_escape($contract['delivery_place'] ?? '') ?>',
    receiver_name: '<?= js_escape($contract['receiver_name'] ?? '') ?>',
    receiver_phone: '<?= js_escape($contract['receiver_phone'] ?? '') ?>',
    warranty: '<?= js_escape($contract['warranty'] ?? '') ?>',
    tax_note: '<?= js_escape($contract['tax_note'] ?? '') ?>',
    bank_name: '<?= js_escape($contract['bank_name'] ?? '') ?>',
    bank_account: '<?= js_escape($contract['bank_account'] ?? '') ?>',
    party_a_info: '<?= js_escape($contract['party_a_info'] ?? '') ?>',
    party_b_info: '<?= js_escape($contract['party_b_info'] ?? '') ?>',
    party_a_sign: '<?= js_escape($contract['party_a_sign'] ?? '') ?>',
    party_b_sign: '<?= js_escape($contract['party_b_sign'] ?? '') ?>',
    hotline: '<?= js_escape($contract['hotline'] ?? '') ?>',
    terms: '<?= js_escape($contract['terms'] ?? '') ?>',
    remark: '<?= js_escape($contract['remark'] ?? '') ?>',
    items: <?= json_encode(array_map(function ($it) {
        return [
            'product_name' => $it['product_name'] ?: '商品已删除(ID:' . intval($it['product_id']) . ')',
            'sku' => $it['sku'] ?: '',
            'spec' => $it['spec'] ?: '',
            'unit_name' => $it['unit_name'] ?: '',
            'quantity' => $it['quantity'],
            'price' => $it['price'],
            'amount' => $it['amount'],
            'remark' => $it['remark'] ?: '',
            'product_image' => $it['product_image'] ?: '',
            'product_description' => $it['product_description'] ?: '',
        ];
    }, $items), JSON_UNESCAPED_UNICODE) ?>
};
printData.total_amount_cn = numToCny(<?= floatval($contract['total_amount']) ?>);
printData.deposit_amount_cn = numToCny(<?= floatval($contract['deposit_amount']) ?>);
printData.balance_amount_cn = numToCny(<?= floatval($contract['balance_amount']) ?>);

// ===== 附件页：整份报价单（合同正文后另起一页）=====
var quoteTplHtml = <?= json_encode($quoteTplContent, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// 附件页用独立的数据对象：{remark} 在合同与报价单里含义不同（合同备注 vs 报价备注），
// 若共用 printData 会互相污染
var quotePrintData = {
    bill_no: '<?= js_escape($quote['bill_no'] ?? '') ?>',
    bill_date: '<?= js_escape($quote['quote_date'] ?? '') ?>',
    customer_name: '<?= js_escape($quote['customer_name'] ?? '') ?>',
    customer_phone: '<?= js_escape($quote['customer_phone'] ?? '') ?>',
    customer_address: '<?= js_escape($quote['customer_address'] ?? '') ?>',
    customer_contact: '<?= js_escape($quote['customer_contact'] ?? '') ?>',
    employee_name: '<?= js_escape($employeeName) ?>',
    employee_phone: '<?= js_escape($employeePhone) ?>',
    total_amount: '¥<?= format_money($quote['total_amount'] ?? 0) ?>',
    remark: '<?= js_escape($quote['remark'] ?? '') ?>',
    company_name: '<?= js_escape($companyName) ?>',
    company_address: '<?= js_escape($companyAddress) ?>',
    company_phone: '<?= js_escape($companyPhone) ?>'
};
quotePrintData.total_amount_cn = numToCny(<?= floatval($quote['total_amount'] ?? 0) ?>);
// 与合同共用同一个 items 数组引用：preparePrintImages 是就地给元素挂 image_base64，
// 共用引用可保证图片只转换一次，合同正文与附件页都能拿到 base64
quotePrintData.items = printData.items;

// 合同明细即报价明细，首屏只输出图片路径，打印时再按需转 dataURL（公共实现见 assets/js/print-image.js）

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

// 渲染附件页：整份报价单。外层套 .contract-attachment，由打印 CSS 强制另起一页
function renderAttachmentPage() {
    if (!quoteTplHtml) return '';
    var html = quoteTplHtml.replace(/\{items\}/g, buildItemsHtml(printData.items, quoteTplHtml));
    var brKeys = ['remark'];
    for (var key in quotePrintData) {
        if (!quotePrintData.hasOwnProperty(key) || key === 'items') continue;
        var val = String(quotePrintData[key] == null ? '' : quotePrintData[key]);
        if (brKeys.indexOf(key) !== -1) val = val.replace(/\n/g, '<br>');
        var re = new RegExp('\\{' + key + '\\}', 'g');
        html = html.replace(re, function(){ return val; });
    }
    return '<div class="contract-attachment">' + html + '</div>';
}

function renderPrintContent() {
    var tpl = <?= json_encode($tpl['content'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    // 这些字段可能含换行：HTML 会折叠换行，转成 <br> 打印才能正常分行
    // 双方信息块是自由文本，可含换行，同样需要转 <br>
    var brKeys = ['remark', 'terms', 'payment_terms', 'purchase_desc',
                  'party_a_info', 'party_b_info', 'party_a_sign', 'party_b_sign'];
    tpl = tpl.replace(/\{items\}/g, buildItemsHtml(printData.items, tpl));
    for (var key in printData) {
        if (printData.hasOwnProperty(key) && key !== 'items') {
            var val = String(printData[key] == null ? '' : printData[key]);
            if (brKeys.indexOf(key) !== -1) val = val.replace(/\n/g, '<br>');
            var re = new RegExp('\\{' + key + '\\}', 'g');
            // 用函数形式返回替换值：避免值里的 $& / $1 被当成正则捕获组引用而丢失
            tpl = tpl.replace(re, function(){ return val; });
        }
    }
    // 附件必须最后插入：报价单模板里同样有 {customer_name}/{remark} 等占位，
    // 插早了会被上面合同变量的循环替换掉（尤其 {remark}，合同备注与报价备注含义不同）
    var attachmentHtml = renderAttachmentPage();
    if (attachmentHtml) {
        // 模板写了 {attachment} 就插在指定位置，没写则追加到末尾，保证附件一定会出现
        tpl = /\{attachment\}/.test(tpl)
            ? tpl.replace(/\{attachment\}/g, function(){ return attachmentHtml; })
            : tpl + attachmentHtml;
    } else {
        tpl = tpl.replace(/\{attachment\}/g, '');
    }
    // 外层包 .contract-doc：打印 CSS 里 `table td` 默认居中（报价单、出库单等单据需要），
    // 但合同正文的表格（甲乙双方、签章区）应按阅读习惯左对齐。
    // 用容器类做区分，不依赖数据库里的模板内容是否带内联样式
    document.getElementById('printContent').innerHTML = '<div class="contract-doc">' + tpl + '</div>';
}

var PRINT_CSS = '<style>body{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}'
    + '*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}'
    + 'table{border-collapse:collapse;width:100%;}'
    + 'table th,table td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;vertical-align:middle;}'
    + 'table th{font-weight:bold;}'
    // 合同正文的表格左对齐（甲乙双方表、签章区）。
    // 必须靠 `.contract-doc` 这类容器规则来兜底：模板内容存在数据库里，
    // 给模板加内联样式对"已入库的旧模板"不生效，只有 CSS 能立刻覆盖全部历史模板。
    // 特异性：.contract-doc table td (0,0,1,2) > table td (0,0,0,2)，能压过上面的居中
    + '.contract-doc table td{text-align:left;}'
    // 附件是整份报价单，恢复单据惯用的居中排版（特异性更高，覆盖上一条）
    + '.contract-doc .contract-attachment table td{text-align:center;}'
    // 合同附件（整份报价单）强制另起一页；break-before 是 CSS3 标准写法，兼容新版浏览器
    + '.contract-attachment{page-break-before:always;break-before:page;}'
    + '@media print{body{padding:0;margin:0;}.noprint{display:none;}}</style>';

function printContract() {
    runPrintAction(function () {
        renderPrintContent();
        var win = window.open('', '_blank', 'width=900,height=600');
        win.document.write('<html><head><title>合同打印</title>');
        win.document.write(PRINT_CSS);
        win.document.write('</head><body>');
        win.document.write(document.getElementById('printContent').innerHTML);
        win.document.write('</body></html>');
        win.document.close();
        setTimeout(function(){ win.print(); }, 500);
    }, { items: printData.items });
}

function exportPDF() {
    runPrintAction(function () {
        renderPrintContent();
        var win = window.open('', '_blank', 'width=900,height=600');
        win.document.write('<html><head><title>合同 - <?= js_escape($contract['contract_no']) ?></title>');
        win.document.write(PRINT_CSS);
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
