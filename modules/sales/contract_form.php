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

/**
 * 采购描述需要的明细概要：首行商品名 + 明细项数
 * 句式是「采购{首项}等 N 项（详见附件报价单）」，商品明细本身由附件报价单承载，正文不再逐行罗列
 * @return array ['first'=>string, 'count'=>int]
 */
function build_purchase_desc_info($pdo, $quoteId) {
    $st = $pdo->prepare("SELECT p.name as product_name
        FROM sales_quote_items i
        LEFT JOIN products p ON i.product_id=p.id
        WHERE i.quote_id=? ORDER BY i.id");
    $st->execute([intval($quoteId)]);
    $items = $st->fetchAll();
    if (!$items) return ['first' => '', 'count' => 0];
    // 商品被删除时 LEFT JOIN 出来是 NULL，兜底成「商品」
    return ['first' => $items[0]['product_name'] ?: '商品', 'count' => count($items)];
}

/**
 * 采购描述默认值：按合同样板句式
 * 「采购造雪机等 2 项（详见附件报价单），合同总金额为人民币 100000.00 元」
 * 只拼到金额为止 —— 后面的「人民币大写：XXX」由前端 numToCny() 追加，
 * 因为大写是全站唯一的 JS 实现（assets/js/num-cny.js），PHP 侧不重复实现。
 * 带出来的只是默认值，表单里随时可改。
 */
function build_purchase_desc($pdo, $quoteId, $totalAmount) {
    $info = build_purchase_desc_info($pdo, $quoteId);
    if (!$info['count']) return '';
    return '采购' . $info['first'] . '等 ' . $info['count'] . ' 项（详见附件报价单）'
         . '，合同总金额为人民币 ' . number_format(floatval($totalAmount), 2, '.', '') . ' 元';
}

// ===== AJAX：取报价单信息（客户/金额/采购描述），供下拉联动填充 =====
if (($_GET['ajax'] ?? '') === 'quote_info') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $qid = intval($_GET['id'] ?? 0);
    // 多带出客户的地址/联系人/电话：合同表单要据此生成顶部甲乙信息与签章文本的默认值
    $st = $pdo->prepare("SELECT q.*, c.name as customer_name, c.address as customer_address, c.contact as customer_contact, c.phone as customer_phone
        FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.id=?");
    $st->execute([$qid]);
    $q = $st->fetch();
    if (!$q) { echo json_encode(['ok' => false, 'msg' => '报价单不存在'], JSON_UNESCAPED_UNICODE); exit; }

    // 采购描述：前端按「首项商品名 + 项数 + 合同金额」拼装（大写金额由前端 numToCny 生成），
    // 这里只给原始素材，金额变化时不用来回请求
    $descInfo = build_purchase_desc_info($pdo, $qid);
    echo json_encode([
        'ok' => true,
        'customer_id'   => intval($q['customer_id']),
        'customer_name' => $q['customer_name'] ?: '-',
        'customer_address' => $q['customer_address'] ?: '',
        'customer_contact' => $q['customer_contact'] ?: '',
        'customer_phone'   => $q['customer_phone'] ?: '',
        'total_amount'  => floatval($q['total_amount']),
        'quote_no'      => $q['bill_no'],
        'desc_first'    => $descInfo['first'],
        'desc_count'    => intval($descInfo['count']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id      = intval($_GET['id'] ?? 0);
$quoteId = intval($_GET['quote_id'] ?? 0);
$err     = '';

$contract = null;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM sales_contracts WHERE id=?");
    $st->execute([$id]);
    $contract = $st->fetch();
    if (!$contract) { flash_set('合同不存在', 'danger'); redirect('contract.php'); }
    if (get_user_role() !== 'admin' && $contract['user_id'] != get_user_id()) {
        die('<div style="text-align:center;margin-top:100px;"><h3>无权限访问</h3><a href="contract.php">返回</a></div>');
    }
}

/**
 * 合同编辑锁：名下订单一旦出了库就锁死（Q4：已撤销的出库单不算，撤了自动解锁）。
 * 两条防线缺一不可——
 *   ① 打开表单时锁：禁止直接敲 contract_form.php?id=N 绕过列表页的状态判断；
 *   ② 保存时再锁一次：防止表单打开期间别人出了库、或直接 POST 提交。
 */
$contractLocked  = false;
$contractLockMsg = '';
function contract_delivery_lock_message($contractId) {
    $bills = contract_delivery_bills($contractId);
    if (!$bills) return '';
    $txt = [];
    foreach ($bills as $b) { $txt[] = $b['order_no'] . ' → 出库单 ' . $b['bill_no']; }
    return '该合同下的订单已出库（' . implode('；', $txt) . '），不能再修改合同条款。'
         . '如需修改，请先在「销售订单」中点击该单的「已出库（点此撤销）」撤销出库，合同会自动解锁。';
}
if ($id && contract_has_delivery($id)) {
    $contractLocked  = true;
    $contractLockMsg = contract_delivery_lock_message($id);
}

// ===== 保存 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id           = intval($_POST['id'] ?? 0);
    // 保存时的二次校验：表单可能是在出库前打开的，不重新判断就等于没锁
    if ($id && contract_has_delivery($id)) {
        flash_set(contract_delivery_lock_message($id) ?: '该合同下的订单已出库，不能修改合同条款');
        redirect('contract.php');
    }
    $newQuoteId   = intval($_POST['quote_id'] ?? 0);
    $totalAmount  = floatval($_POST['total_amount'] ?? 0);
    $paymentType  = trim($_POST['payment_type'] ?? 'full');
    $depositAmount= floatval($_POST['deposit_amount'] ?? 0);
    $prepDays     = intval($_POST['prep_days'] ?? 7);
    $paymentTerms = trim($_POST['payment_terms'] ?? '');
    $purchaseDesc = trim($_POST['purchase_desc'] ?? '');
    $deliveryPlace= trim($_POST['delivery_place'] ?? '');
    $receiverName = trim($_POST['receiver_name'] ?? '');
    $receiverPhone= trim($_POST['receiver_phone'] ?? '');
    $warranty     = trim($_POST['warranty'] ?? '');
    $taxNote      = trim($_POST['tax_note'] ?? '');
    // 乙方开户行 / 账号号码已移除（账号信息改由「乙方签章预设」整体带进 party_b_sign）
    $hotline      = trim($_POST['hotline'] ?? '');
    $partyAInfo   = trim($_POST['party_a_info'] ?? '');
    $partyBInfo   = trim($_POST['party_b_info'] ?? '');
    $partyASign   = trim($_POST['party_a_sign'] ?? '');
    $partyBSign   = trim($_POST['party_b_sign'] ?? '');
    $terms        = trim($_POST['terms'] ?? '');
    $templateId   = intval($_POST['template_id'] ?? 0);
    $signDate     = trim($_POST['sign_date'] ?? '');
    $effectiveDate= trim($_POST['effective_date'] ?? '');
    $expiryDate   = trim($_POST['expiry_date'] ?? '');
    $employeeId   = intval($_POST['employee_id'] ?? 0);
    // 非管理员强制记在自己名下：前端只是禁用下拉，这里兜底防止改 POST 把业绩挂给别人
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        $employeeId = get_user_id();
    }
    $remark       = trim($_POST['remark'] ?? '');

    if ($newQuoteId <= 0) {
        $err = '请选择来源报价单（合同明细即报价单明细，作为附件打印）';
    } else {
        $stq = $pdo->prepare("SELECT id, customer_id, total_amount FROM sales_quotes WHERE id=?");
        $stq->execute([$newQuoteId]);
        $quote = $stq->fetch();
        if (!$quote) {
            $err = '所选报价单不存在';
        } else {
            // 该报价单已被其他合同占用则拒绝
            $sto = $pdo->prepare("SELECT id FROM sales_contracts WHERE quote_id=? AND id<>? LIMIT 1");
            $sto->execute([$newQuoteId, $id]);
            if ($sto->fetch()) $err = '该报价单已被其他合同关联，请另选';
        }
    }

    if (!$err) {
        $balance = $totalAmount - $depositAmount;
        if ($balance < 0) $balance = 0;

        // 附件上传（不传则保留原值）
        $attachment = $contract['attachment'] ?? null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = upload_file('attachment', ['jpg','jpeg','png','gif','webp','bmp','pdf','doc','docx'], 20 * 1024 * 1024);
            if (!$up['success']) {
                $err = '附件上传失败：' . ($up['message'] ?? '未知错误');
            } else {
                $attachment = $up['path'];
            }
        }

        if (!$err) {
            $pdo->beginTransaction();
            try {
                if ($id) {
                    $pdo->prepare("UPDATE sales_contracts SET
                        quote_id=?, customer_id=?, total_amount=?, payment_type=?, deposit_amount=?, balance_amount=?,
                        prep_days=?, payment_terms=?, purchase_desc=?, delivery_place=?, receiver_name=?, receiver_phone=?,
                        warranty=?, tax_note=?, hotline=?,
                        party_a_info=?, party_b_info=?, party_a_sign=?, party_b_sign=?, terms=?, template_id=?,
                        sign_date=?, effective_date=?, expiry_date=?, employee_id=?, remark=?, attachment=?
                        WHERE id=?")
                        ->execute([
                            $newQuoteId, $quote['customer_id'], $totalAmount, $paymentType, $depositAmount, $balance,
                            $prepDays, $paymentTerms, $purchaseDesc, $deliveryPlace, $receiverName, $receiverPhone,
                            $warranty, $taxNote, $hotline,
                            $partyAInfo, $partyBInfo, $partyASign, $partyBSign, $terms, $templateId ?: null,
                            $signDate ?: null, $effectiveDate ?: null, $expiryDate ?: null, $employeeId, $remark, $attachment, $id
                        ]);
                    // 换了报价单：解绑旧的，允许其再次转合同/订单
                    if ($contract && $contract['quote_id'] != $newQuoteId) {
                        $pdo->prepare("UPDATE sales_quotes SET status='draft', contract_id=NULL WHERE id=? AND contract_id=?")
                            ->execute([$contract['quote_id'], $id]);
                    }
                    add_log(get_user_id(), 'update', 'sales_contract', "编辑销售合同: {$contract['contract_no']}");
                } else {
                    $contractNo = generate_bill_no('HT');
                    $pdo->prepare("INSERT INTO sales_contracts
                        (contract_no, customer_id, quote_id, template_id, total_amount, payment_type, deposit_amount, balance_amount,
                         prep_days, payment_terms, purchase_desc, delivery_place, receiver_name, receiver_phone,
                         warranty, tax_note, hotline,
                         party_a_info, party_b_info, party_a_sign, party_b_sign,
                         terms, sign_date, effective_date, expiry_date,
                         employee_id, remark, attachment, user_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $contractNo, $quote['customer_id'], $newQuoteId, $templateId ?: null, $totalAmount, $paymentType,
                            $depositAmount, $balance, $prepDays, $paymentTerms, $purchaseDesc, $deliveryPlace, $receiverName,
                            $receiverPhone, $warranty, $taxNote, $hotline,
                            $partyAInfo, $partyBInfo, $partyASign, $partyBSign, $terms,
                            $signDate ?: null, $effectiveDate ?: null, $expiryDate ?: null, $employeeId, $remark, $attachment, get_user_id()
                        ]);
                    $id = $pdo->lastInsertId();
                    add_log(get_user_id(), 'create', 'sales_contract', "新增销售合同: $contractNo");
                }

                // 报价单标记为已转合同，避免被其他合同重复关联
                $pdo->prepare("UPDATE sales_quotes SET status='contracted', contract_id=? WHERE id=?")
                    ->execute([$id, $newQuoteId]);

                $pdo->commit();
                flash_set('合同已保存', 'success');
                redirect('contract_view.php?id=' . $id);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Contract save error: ' . $e->getMessage());
                $err = '保存失败：' . $e->getMessage();
            }
        }
    }
}

// ===== 表单数据 =====
// 可选报价单：未转出、未被占用的；编辑时补上当前关联的
$st = $pdo->prepare("SELECT q.id, q.bill_no, q.total_amount, q.customer_id, c.name as customer_name
    FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id
    WHERE (q.status IN ('draft','withdrawn') AND q.contract_id IS NULL) OR q.id=?
    ORDER BY q.id DESC LIMIT 300");
$st->execute([$contract['quote_id'] ?? 0]);
$quoteList = $st->fetchAll();

$payTypes = $pdo->query("SELECT * FROM contract_payment_types WHERE status=1 ORDER BY sort, id")->fetchAll();
// 乙方签章预设：管理员在「销售合同 → 乙方签章预设」维护，这里下拉选用，选中后整块填进签章文本
$signPresets = $pdo->query("SELECT id, name, content, is_default FROM contract_sign_presets WHERE status=1 ORDER BY sort, id")->fetchAll();
// 默认预设：新建合同且签章文本为空时自动带出，省一次选择。编辑已有合同不覆盖（$v 只在新建时取默认）
$defaultPreset = '';
foreach ($signPresets as $sp) {
    if (!empty($sp['is_default'])) { $defaultPreset = (string)$sp['content']; break; }
}
$templates = $pdo->query("SELECT id, name FROM print_templates WHERE type='sales_contract' ORDER BY is_default DESC, id")->fetchAll();
$employees = $pdo->query("SELECT id, real_name FROM users WHERE status=1 ORDER BY id")->fetchAll();

// 业务员：非管理员只能签自己名下的合同（业绩归属跟着走）。
// 前端锁死下拉，保存时 contract 里再强制覆盖一次，防止改 POST 挂到别人名下
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$employeeLocked = !$isAdmin;
$currentUid = get_user_id();
$employeeValue = $contract ? intval($contract['employee_id'] ?? 0) : $currentUid;
if ($employeeLocked || !$employeeValue) $employeeValue = $currentUid;
// 当前账号若已被停用会不在列表里，补进来，否则锁定后下拉显示不出名字
$hasCurrent = false;
foreach ($employees as $e) { if (intval($e['id']) === $currentUid) { $hasCurrent = true; break; } }
if (!$hasCurrent) {
    $stU = $pdo->prepare("SELECT id, real_name FROM users WHERE id=?");
    $stU->execute([$currentUid]);
    $curUser = $stU->fetch();
    if ($curUser) $employees[] = $curUser;
}

// 新建时的默认值：没建过合同 = 下面这些固定值；建过 = 继承「我自己」上一份合同的值。
// 口径（2026-10-12 定）：价格说明固定「不含税不含运费」、热线固定 15612167779，
// 质保/备货周期没有固定值（第一次建合同时留空/默认 7 天）。
// 继承范围按 employee_id（业务员）而不是全库最近一份：每个业务员的第一份合同都拿固定预设，
// 之后沿用自己上一份，互不干扰。
$defaults = ['warranty'=>'', 'tax_note'=>'不含税不含运费', 'hotline'=>'15612167779', 'prep_days'=>7];
if (!$contract) {
    $stLast = $pdo->prepare("SELECT warranty, tax_note, hotline, prep_days FROM sales_contracts
                             WHERE employee_id=? ORDER BY id DESC LIMIT 1");
    $stLast->execute([get_user_id()]);
    $last = $stLast->fetch();
    if ($last) {
        foreach ($defaults as $k => $v) { if (($last[$k] ?? '') !== '') $defaults[$k] = $last[$k]; }
    }
}

$v = function($key, $default = '') use ($contract, $defaults) {
    if ($contract) return $contract[$key] ?? $default;
    return $defaults[$key] ?? $default;
};
// 与 $v 的区别：合同已存在（「报价单转合同」是先建一条空合同再进编辑页）但字段为空时，
// 照样补默认值。这是 2026-10-10 记过的坑：转合同入口不是新建，自动默认值必须按「为空就补」判断
$vd = function($key, $default = '') use ($contract, $defaults) {
    if ($contract) {
        $val = trim((string)($contract[$key] ?? ''));
        return $val !== '' ? $contract[$key] : $default;
    }
    return $defaults[$key] ?? $default;
};
$curQuoteId = $contract['quote_id'] ?? $quoteId;
$curAmount  = $contract['total_amount'] ?? 0;
$curCustomer= '';
// 客户完整信息：表单要用它生成「顶部甲方信息」和「甲方签章文本」的默认值
$custInfo = ['name'=>'', 'address'=>'', 'contact'=>'', 'phone'=>''];
if ($curQuoteId) {
    $stc = $pdo->prepare("SELECT c.name, c.address, c.contact, c.phone FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.id=?");
    $stc->execute([$curQuoteId]);
    if ($r = $stc->fetch()) {
        $custInfo = [
            'name'    => $r['name'] ?: '',
            'address' => $r['address'] ?: '',
            'contact' => $r['contact'] ?: '',
            'phone'   => $r['phone'] ?: '',
        ];
    }
    $curCustomer = $custInfo['name'] ?: '-';
}

// 采购描述为空就按所选报价单自动带出（可改）。
// 覆盖两种场景：① 报价单「转合同」——后端先建合同再跳编辑页，此时合同已存在但描述还是空的；
// ② 直接带 quote_id 新建。原先只在切换报价单下拉的 ajax 里生成，一进页面这块是空的
$curPurchaseDesc = $contract ? (string)($contract['purchase_desc'] ?? '') : ($defaults['purchase_desc'] ?? '');
// descInfo 是「首项商品名 + 项数」，交给前端拼装完整文案；
// descAuto 表示这值是系统自动带出的，才允许随合同金额变化重算，用户手改过就不再覆盖
$descInfo = ['first' => '', 'count' => 0];
$descAuto = false;
if (trim($curPurchaseDesc) === '' && $curQuoteId > 0) {
    $stAmt = $pdo->prepare("SELECT total_amount FROM sales_quotes WHERE id=?");
    $stAmt->execute([$curQuoteId]);
    $descInfo = build_purchase_desc_info($pdo, $curQuoteId);
    $curPurchaseDesc = build_purchase_desc($pdo, $curQuoteId, floatval($stAmt->fetchColumn()));
    $descAuto = $descInfo['count'] > 0;
}

// 乙方（本公司）信息取自系统设置，用于生成顶部乙方信息与乙方签章文本的默认值
$companyName    = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';

// 合同上的乙方电话留经办业务员本人的（客户要能直接找到签合同的人）：
// 优先取当前登录账号的电话，个人没维护才回退公司总机
$stEmpPhone = $pdo->prepare("SELECT phone FROM users WHERE id=?");
$stEmpPhone->execute([get_user_id()]);
$employeePhone = trim((string)$stEmpPhone->fetchColumn());
$salesPhone    = $employeePhone !== '' ? $employeePhone : $companyPhone;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> <?= $contract ? '编辑' : '新增' ?>销售合同</h1>
    <a href="contract.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($contractLocked): ?>
<div class="alert alert-danger" style="display:flex;gap:8px;align-items:flex-start;">
    <i class="fa-solid fa-lock" style="margin-top:3px;"></i>
    <div><strong>合同已锁定（订单已出库）</strong><br><?= htmlspecialchars($contractLockMsg) ?></div>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="contractForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card"><div class="card-header"><strong>基本信息</strong></div><div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">来源报价单 <span class="required">*</span></label>
                <select name="quote_id" id="quoteId" class="form-control" required onchange="onQuoteChange()">
                    <option value="">请选择报价单</option>
                    <?php foreach ($quoteList as $q): ?>
                    <option value="<?=$q['id']?>" <?= $curQuoteId == $q['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($q['bill_no']) ?> — <?= htmlspecialchars($q['customer_name'] ?: '未指定客户') ?> — ¥<?= format_money($q['total_amount']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--gray-500);">合同明细即报价单明细，打印时作为附件</small>
            </div>
            <div class="form-group">
                <label class="form-label">甲方（客户）</label>
                <div class="form-control" style="background:var(--gray-100);" id="customerText"><?= htmlspecialchars($curCustomer) ?></div>
            </div>
            <div class="form-group">
                <label class="form-label">合同总金额(¥)</label>
                <input type="number" step="0.01" name="total_amount" id="totalAmount" class="form-control" value="<?= htmlspecialchars($curAmount) ?>" oninput="renderTerms(); refreshPurchaseDesc();">
            </div>
            <div class="form-group">
                <label class="form-label">业务员<?php if ($employeeLocked): ?> <span style="font-weight:400;color:var(--gray-500);font-size:12px;">（默认本人，不可修改）</span><?php endif; ?></label>
                <?php if ($employeeLocked): ?>
                <!-- 禁用的 select 不会随表单提交，所以真正提交的是这个隐藏域 -->
                <input type="hidden" name="employee_id" value="<?= intval($employeeValue) ?>">
                <select class="form-control" disabled>
                    <?php $lockedName = '未知用户'; foreach ($employees as $e) { if (intval($e['id']) === intval($employeeValue)) { $lockedName = $e['real_name']; break; } } ?>
                    <option selected><?= htmlspecialchars($lockedName) ?></option>
                </select>
                <?php else: ?>
                <select name="employee_id" class="form-control">
                    <option value="0">-</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?=$e['id']?>" <?= $employeeValue == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['real_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">签约日期</label>
                <input type="date" name="sign_date" id="signDate" class="form-control" value="<?= htmlspecialchars($contract['sign_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">生效日期</label>
                <input type="date" name="effective_date" class="form-control" value="<?= htmlspecialchars($contract['effective_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">到期日期</label>
                <input type="date" name="expiry_date" class="form-control" value="<?= htmlspecialchars($contract['expiry_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">合同模板</label>
                <select name="template_id" class="form-control">
                    <option value="0">默认模板</option>
                    <?php foreach ($templates as $t): ?>
                    <option value="<?=$t['id']?>" <?= ($contract['template_id'] ?? 0) == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div></div>

    <div class="card"><div class="card-header"><strong>合同双方信息</strong></div><div class="card-body">
        <small style="color:var(--gray-500);display:block;margin-bottom:10px;">
            默认按客户资料 / 系统设置自动生成，可手工修改。支持换行，一行一项，打印时原样分行输出。
        </small>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">顶部 · 甲方信息</label>
                <textarea name="party_a_info" id="partyAInfo" class="form-control" rows="4"><?= htmlspecialchars($v('party_a_info')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">顶部 · 乙方信息</label>
                <textarea name="party_b_info" id="partyBInfo" class="form-control" rows="4"><?= htmlspecialchars($v('party_b_info')) ?></textarea>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">签章 · 甲方（地址 / 联系人等）</label>
                <textarea name="party_a_sign" id="partyASign" class="form-control" rows="4"><?= htmlspecialchars($v('party_a_sign')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">签章 · 乙方（账号 / 开户行等）</label>
                <?php if ($signPresets): ?>
                <select id="signPresetSelect" class="form-control" style="margin-bottom:6px;" onchange="onSignPresetChange()">
                    <option value="">— 选择签章预设 —</option>
                    <?php foreach ($signPresets as $sp): ?>
                    <option value="<?= intval($sp['id']) ?>" data-content="<?= htmlspecialchars($sp['content'] ?? '') ?>"><?= htmlspecialchars($sp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <small style="color:var(--gray-500);display:block;margin-bottom:6px;">暂无签章预设（管理员可在「销售合同 → 乙方签章预设」维护）</small>
                <?php endif; ?>
                <textarea name="party_b_sign" id="partyBSign" class="form-control" rows="4"><?= htmlspecialchars($vd('party_b_sign', $defaultPreset)) ?></textarea>
            </div>
        </div>
    </div></div>

    <div class="card"><div class="card-header"><strong>付款方式</strong></div><div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">付款方式</label>
                <select name="payment_type" id="paymentType" class="form-control" onchange="renderTerms(true)">
                    <?php foreach ($payTypes as $pt): ?>
                    <option value="<?= htmlspecialchars($pt['code']) ?>" <?= ($contract['payment_type'] ?? 'full') === $pt['code'] ? 'selected' : '' ?>><?= htmlspecialchars($pt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="depositRow" style="display:none;">
                <label class="form-label">定金金额(¥)</label>
                <input type="number" step="0.01" name="deposit_amount" id="depositAmount" class="form-control" value="<?= htmlspecialchars($v('deposit_amount', 0)) ?>" oninput="renderTerms()">
            </div>
            <div class="form-group" id="prepRow" style="display:none;">
                <label class="form-label">备货周期（工作日）</label>
                <input type="number" name="prep_days" id="prepDays" class="form-control" value="<?= htmlspecialchars($v('prep_days', 7)) ?>" oninput="renderTerms()">
            </div>
            <div class="form-group">
                <label class="form-label">尾款(¥)</label>
                <div class="form-control" style="background:var(--gray-100);" id="balanceText">0.00</div>
                <small style="color:var(--gray-500);">自动计算：总金额 − 定金</small>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">付款条款（自动生成，可修改）</label>
            <textarea name="payment_terms" id="paymentTerms" class="form-control" rows="4"><?= htmlspecialchars($contract['payment_terms'] ?? '') ?></textarea>
        </div>
    </div></div>

    <div class="card"><div class="card-header"><strong>交货与验收</strong></div><div class="card-body">
        <div class="form-group">
            <label class="form-label">采购描述（金额自动带出，可修改）</label>
            <input type="text" name="purchase_desc" id="purchaseDesc" class="form-control" value="<?= htmlspecialchars($curPurchaseDesc) ?>" placeholder="如：采购造雪机 2 套，每套单价 50000.00 元，合同总金额为人民币 100000.00 元">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">价格说明</label>
                <input type="text" name="tax_note" class="form-control" value="<?= htmlspecialchars($vd('tax_note')) ?>" placeholder="如：不含税不含运费">
            </div>
            <div class="form-group">
                <label class="form-label">收货地点</label>
                <input type="text" name="delivery_place" id="deliveryPlace" class="form-control" value="<?= htmlspecialchars($vd('delivery_place', $custInfo['address'])) ?>">
                <small style="color:var(--gray-500);">默认取客户资料地址</small>
            </div>
            <div class="form-group">
                <label class="form-label">甲方授权接货经办人</label>
                <input type="text" name="receiver_name" id="receiverName" class="form-control" value="<?= htmlspecialchars($vd('receiver_name', $custInfo['name'])) ?>">
                <small style="color:var(--gray-500);">默认取客户名称</small>
            </div>
            <div class="form-group">
                <label class="form-label">接货人电话</label>
                <input type="text" name="receiver_phone" id="receiverPhone" class="form-control" value="<?= htmlspecialchars($vd('receiver_phone', $custInfo['phone'])) ?>">
                <small style="color:var(--gray-500);">默认取客户电话</small>
            </div>
        </div>
    </div></div>

    <div class="card"><div class="card-header"><strong>商务条款</strong></div><div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">质保条款</label>
                <input type="text" name="warranty" class="form-control" value="<?= htmlspecialchars($v('warranty')) ?>" placeholder="如：造雪机主机质保3年">
            </div>
            <div class="form-group">
                <label class="form-label">技术支持热线</label>
                <input type="text" name="hotline" class="form-control" value="<?= htmlspecialchars($vd('hotline')) ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">其他约定</label>
            <textarea name="terms" class="form-control" rows="3"><?= htmlspecialchars($v('terms')) ?></textarea>
        </div>
    </div></div>

    <div class="card"><div class="card-header"><strong>其他</strong></div><div class="card-body">
        <div class="form-group">
            <label class="form-label">盖章扫描件（选传）</label>
            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx">
            <?php if (!empty($contract['attachment'])): ?>
            <small>当前：<a href="../../<?= htmlspecialchars($contract['attachment']) ?>" target="_blank">查看已上传附件</a></small>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label class="form-label">备注</label>
            <textarea name="remark" class="form-control" rows="2"><?= htmlspecialchars($v('remark')) ?></textarea>
        </div>
    </div></div>

    <div style="margin-top:16px;">
        <?php if ($contractLocked): ?>
        <button type="button" class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed;"><i class="fa-solid fa-lock"></i> 已出库，合同已锁定</button>
        <?php else: ?>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存合同</button>
        <?php endif; ?>
        <a href="contract.php" class="btn btn-outline">取消</a>
    </div>
</form>

<?php if ($contractLocked): ?>
<script>
// 锁定时的兜底：把所有输入控件置灰，避免有人靠改 DOM 提交
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contractForm');
    if (!form) return;
    form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
        if (el.type === 'hidden') return;
        el.disabled = true;
    });
});
</script>
<?php endif; ?>

<script>
// 付款方式模板（含变量占位），由后端注入
var payTypes = <?= json_encode(array_reduce($payTypes, function($carry, $pt){
    $carry[$pt['code']] = [
        'template' => $pt['template'],
        'need_deposit' => intval($pt['need_deposit']),
        'need_days' => intval($pt['need_days']),
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE) ?>;

// termsEdited：用户手工改过条款后不再自动覆盖，避免覆盖掉手改内容
var termsEdited = <?= (!empty($contract['payment_terms'])) ? 'true' : 'false' ?>;
document.getElementById('paymentTerms').addEventListener('input', function(){ termsEdited = true; });

// numToCny 已抽取为全站唯一实现：assets/js/num-cny.js
// （由 includes/header.php 在页面 head 里引入，本页不再内联定义，避免多份副本算出不同结果）

function money(n) { return (Math.round(Number(n) * 100) / 100).toFixed(2); }

// 切换付款方式时强制重算（force=true 视为重新生成）
function renderTerms(force) {
    var code = document.getElementById('paymentType').value;
    var t = payTypes[code];
    document.getElementById('depositRow').style.display = (t && t.need_deposit) ? '' : 'none';
    document.getElementById('prepRow').style.display = (t && t.need_days) ? '' : 'none';

    var total = parseFloat(document.getElementById('totalAmount').value) || 0;
    var deposit = parseFloat(document.getElementById('depositAmount').value) || 0;
    var balance = total - deposit; if (balance < 0) balance = 0;
    document.getElementById('balanceText').textContent = money(balance);

    if (!t) return;
    if (termsEdited && !force) return;   // 用户手改过且未切换方式，不覆盖

    var prep = parseInt(document.getElementById('prepDays').value) || 0;
    var txt = t.template
        .replace(/\{total_amount_cn\}/g, numToCny(total))
        .replace(/\{total_amount\}/g, money(total))
        .replace(/\{deposit_amount_cn\}/g, numToCny(deposit))
        .replace(/\{deposit_amount\}/g, money(deposit))
        .replace(/\{balance_amount_cn\}/g, numToCny(balance))
        .replace(/\{balance_amount\}/g, money(balance))
        .replace(/\{prep_days\}/g, prep);
    document.getElementById('paymentTerms').value = txt;
    termsEdited = false;
}

function onQuoteChange() {
    var qid = document.getElementById('quoteId').value;
    if (!qid) return;
    fetch('contract_form.php?ajax=quote_info&id=' + encodeURIComponent(qid), {
        headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'
    }).then(function(r){ return r.json(); })
      .then(function(d){
          if (!d.ok) { alert(d.msg || '获取报价单信息失败'); return; }
          document.getElementById('totalAmount').value = d.total_amount;
          document.getElementById('customerText').textContent = d.customer_name;
          // 采购描述：换成新报价单的素材（首项商品名 + 项数），再按合同金额重算
          descInfo = { first: d.desc_first || '', count: parseInt(d.desc_count || 0, 10) };
          refreshPurchaseDesc();
          // 换了客户：顶部甲方信息与甲方签章文本按新客户重算（手工改过的不覆盖）
          custInfo = {
              name:    (d.customer_name === '-') ? '' : d.customer_name,
              address: d.customer_address || '',
              contact: d.customer_contact || '',
              phone:   d.customer_phone || ''
          };
          fillPartyBlocks();
          fillDeliveryDefaults();
          renderTerms(true);
      })
      .catch(function(e){ alert('请求失败：' + e.message); });
}

// ===== 乙方签章预设：选中后整块填进「签章 · 乙方」，填完仍可手工改 =====
function onSignPresetChange() {
    var sel = document.getElementById('signPresetSelect');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var content = opt ? (opt.getAttribute('data-content') || '') : '';
    if (content === '') return;
    var box = document.getElementById('partyBSign');
    if (box.value.trim() !== '' && !confirm('将用所选预设替换当前「签章 · 乙方」的内容，确定吗？')) {
        sel.value = '';
        return;
    }
    box.value = content;
    sel.value = '';   // 复位成「— 选择签章预设 —」，方便下次再选
}

// ===== 交货与验收：收货地点 / 接货经办人 / 接货电话 默认取客户资料 =====
// 沿用采购描述的套路：用户手改过就锁定，不再被换报价单冲掉。
// 初始标记在页面底部 fillPartyBlocks() 之后做（那里 custInfo 才有值）
var deliveryAuto = { deliveryPlace: false, receiverName: false, receiverPhone: false };
['deliveryPlace', 'receiverName', 'receiverPhone'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
        deliveryAuto[id] = (this.value === '');
    });
});

function fillDeliveryDefaults() {
    var map = {
        'deliveryPlace': custInfo.address || '',
        'receiverName':  custInfo.name || '',
        'receiverPhone': custInfo.phone || ''
    };
    Object.keys(map).forEach(function (id) {
        if (!deliveryAuto[id]) return;
        var el = document.getElementById(id);
        if (el) el.value = map[id];
    });
}

renderTerms(<?= $contract ? 'false' : 'true' ?>);

// ===== 合同双方信息：默认值联动 =====
var custInfo = <?= json_encode($custInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var companyInfo = <?= json_encode(['name'=>$companyName, 'address'=>$companyAddress, 'phone'=>$salesPhone], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// ===== 采购描述：采购{首项商品}等 N 项（详见附件报价单），合同总金额为人民币 X 元，人民币大写：X =====
// 金额取「合同总金额」输入框的当前值（即本合同实际金额），改金额描述跟着变
var descInfo = <?= json_encode($descInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// 只有系统自动带出的才允许随金额重算；用户手改过就锁定，清空则回到自动
var descAuto = <?= $descAuto ? 'true' : 'false' ?>;
document.getElementById('purchaseDesc').addEventListener('input', function(){
    descAuto = (this.value === '');
});

function buildPurchaseDescText() {
    if (!descInfo || !descInfo.count) return '';
    var total = parseFloat(document.getElementById('totalAmount').value) || 0;
    // 大写金额用全站唯一实现 numToCny（assets/js/num-cny.js），本页不另写一份
    return '采购' + (descInfo.first || '商品') + '等 ' + descInfo.count + ' 项（详见附件报价单）'
         + '，合同总金额为人民币 ' + money(total) + ' 元'
         + '，人民币大写：' + numToCny(total);
}

function refreshPurchaseDesc() {
    if (!descAuto) return;   // 用户手改过，不覆盖
    document.getElementById('purchaseDesc').value = buildPurchaseDescText();
}

var partyFieldIds = {a_info:'partyAInfo', b_info:'partyBInfo', a_sign:'partyASign', b_sign:'partyBSign'};
var partyEdited = {};
for (var pk in partyFieldIds) {
    (function(k){
        var el = document.getElementById(partyFieldIds[k]);
        // 已有值（编辑旧合同）视为用户已定制，不再被联动覆盖
        partyEdited[k] = (el.value.trim() !== '');
        el.addEventListener('input', function(){
            var empty = (el.value.trim() === '');
            partyEdited[k] = !empty;
            // 清空 = 放弃定制、回到自动联动，立刻按当前客户/公司资料重算。
            // 老合同的旧默认值（比如还是「单位名称：」）清空一下就能刷新成新版
            if (empty) fillPartyBlocks();
        });
    })(pk);
}

// 值为空就整行不输出，避免出现「地址：」这种空行。
// title 是无条件的首行（如「甲方（签章）：」），不参与空值判断
function buildLines(pairs, title) {
    var out = [];
    if (title) out.push(title);
    for (var i = 0; i < pairs.length; i++) {
        var v = String(pairs[i][1] == null ? '' : pairs[i][1]).trim();
        if (v === '') continue;
        out.push(pairs[i][0] + '：' + v);
    }
    return out.join('\n');
}

function fillPartyBlocks() {
    var signDate = document.getElementById('signDate').value || '';
    if (!partyEdited.a_info) document.getElementById('partyAInfo').value = buildLines([['甲方', custInfo.name], ['地址', custInfo.address], ['联系人', custInfo.contact], ['电话', custInfo.phone]]);
    if (!partyEdited.b_info) document.getElementById('partyBInfo').value = buildLines([['乙方', companyInfo.name], ['地址', companyInfo.address], ['电话', companyInfo.phone]]);
    // 签章块首行放标题，模板里不再重复写（否则打印出来两行标题）
    if (!partyEdited.a_sign) document.getElementById('partyASign').value = buildLines([['地址', custInfo.address], ['联系人', custInfo.contact], ['联系电话', custInfo.phone], ['签约日期', signDate]], '甲方（签章）：');
    // 乙方的账号/开户行不再单独存字段，由「签章预设」下拉整体带进文本框（有默认预设时 PHP 已填好，不会走到这）
    if (!partyEdited.b_sign) document.getElementById('partyBSign').value = buildLines([['联系电话', companyInfo.phone], ['签约日期', signDate]], '乙方（签章）：');
}

// 签约日期变化就重算签章块（手工改过的不覆盖）。bankAccount/bankName 输入框已删除，别再往列表里加，否则 null.addEventListener 会打断整个脚本
['signDate'].forEach(function(id){
    var el = document.getElementById(id);
    el.addEventListener('input', fillPartyBlocks);
    el.addEventListener('change', fillPartyBlocks);
});
fillPartyBlocks();
// 收货三件套初始补默认：PHP 端已按「为空就补」填过一次（转合同进来的场景）。
// 这里把「为空」或「与客户默认一致」的字段标记为自动带出，之后换报价单才会跟着联动；
// 用户填过其他内容的视为手改，不动
['deliveryPlace', 'receiverName', 'receiverPhone'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    var defMap = { deliveryPlace: custInfo.address || '', receiverName: custInfo.name || '', receiverPhone: custInfo.phone || '' };
    if (el.value.trim() === '') {
        el.value = defMap[id];
        deliveryAuto[id] = true;
    } else {
        deliveryAuto[id] = (el.value === defMap[id]);
    }
});
// 页面进来先按当前合同金额补一次大写（PHP 只拼到金额，大写由 numToCny 生成）
refreshPurchaseDesc();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
