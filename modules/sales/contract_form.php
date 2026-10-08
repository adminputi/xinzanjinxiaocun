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

    $st2 = $pdo->prepare("SELECT i.*, p.name as product_name, u.name as unit_name
        FROM sales_quote_items i
        LEFT JOIN products p ON i.product_id=p.id
        LEFT JOIN units u ON p.unit_id=u.id
        WHERE i.quote_id=? ORDER BY i.id");
    $st2->execute([$qid]);
    $items = $st2->fetchAll();

    // 采购描述：按明细首行套样板句式，金额自动带出，用户仍可改
    $desc = '';
    if ($items) {
        $f = $items[0];
        $unit = $f['unit_name'] ?: '';
        $desc = '采购' . ($f['product_name'] ?: '商品') . ' ' . (floatval($f['quantity']) + 0) . $unit
              . '，每' . ($unit ?: '件') . '单价 ' . number_format(floatval($f['price']), 2, '.', '') . ' 元'
              . '，合同总金额为人民币 ' . number_format(floatval($q['total_amount']), 2, '.', '') . ' 元';
    }
    echo json_encode([
        'ok' => true,
        'customer_id'   => intval($q['customer_id']),
        'customer_name' => $q['customer_name'] ?: '-',
        'customer_address' => $q['customer_address'] ?: '',
        'customer_contact' => $q['customer_contact'] ?: '',
        'customer_phone'   => $q['customer_phone'] ?: '',
        'total_amount'  => floatval($q['total_amount']),
        'quote_no'      => $q['bill_no'],
        'purchase_desc' => $desc,
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

// ===== 保存 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id           = intval($_POST['id'] ?? 0);
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
    $bankName     = trim($_POST['bank_name'] ?? '');
    $bankAccount  = trim($_POST['bank_account'] ?? '');
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
                        warranty=?, tax_note=?, bank_name=?, bank_account=?, hotline=?,
                        party_a_info=?, party_b_info=?, party_a_sign=?, party_b_sign=?, terms=?, template_id=?,
                        sign_date=?, effective_date=?, expiry_date=?, employee_id=?, remark=?, attachment=?
                        WHERE id=?")
                        ->execute([
                            $newQuoteId, $quote['customer_id'], $totalAmount, $paymentType, $depositAmount, $balance,
                            $prepDays, $paymentTerms, $purchaseDesc, $deliveryPlace, $receiverName, $receiverPhone,
                            $warranty, $taxNote, $bankName, $bankAccount, $hotline,
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
                         warranty, tax_note, bank_name, bank_account, hotline,
                         party_a_info, party_b_info, party_a_sign, party_b_sign,
                         terms, sign_date, effective_date, expiry_date,
                         employee_id, remark, attachment, user_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $contractNo, $quote['customer_id'], $newQuoteId, $templateId ?: null, $totalAmount, $paymentType,
                            $depositAmount, $balance, $prepDays, $paymentTerms, $purchaseDesc, $deliveryPlace, $receiverName,
                            $receiverPhone, $warranty, $taxNote, $bankName, $bankAccount, $hotline,
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
$templates = $pdo->query("SELECT id, name FROM print_templates WHERE type='sales_contract' ORDER BY is_default DESC, id")->fetchAll();
$employees = $pdo->query("SELECT id, real_name FROM users WHERE status=1 ORDER BY id")->fetchAll();

// 新建时从最近一份合同带出公司侧固定信息，减少重复录入
$defaults = ['warranty'=>'', 'tax_note'=>'不含税不含运费', 'bank_name'=>'', 'bank_account'=>'', 'hotline'=>'', 'prep_days'=>7];
if (!$contract) {
    $last = $pdo->query("SELECT warranty, tax_note, bank_name, bank_account, hotline, prep_days FROM sales_contracts ORDER BY id DESC LIMIT 1")->fetch();
    if ($last) {
        foreach ($defaults as $k => $v) { if (($last[$k] ?? '') !== '') $defaults[$k] = $last[$k]; }
    }
}

$v = function($key, $default = '') use ($contract, $defaults) {
    if ($contract) return $contract[$key] ?? $default;
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

// 乙方（本公司）信息取自系统设置，用于生成顶部乙方信息与乙方签章文本的默认值
$companyName    = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> <?= $contract ? '编辑' : '新增' ?>销售合同</h1>
    <a href="contract.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回列表</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

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
                <input type="number" step="0.01" name="total_amount" id="totalAmount" class="form-control" value="<?= htmlspecialchars($curAmount) ?>" oninput="renderTerms()">
            </div>
            <div class="form-group">
                <label class="form-label">业务员</label>
                <select name="employee_id" class="form-control">
                    <option value="0">-</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?=$e['id']?>" <?= ($contract['employee_id'] ?? get_user_id()) == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['real_name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <textarea name="party_b_sign" id="partyBSign" class="form-control" rows="4"><?= htmlspecialchars($v('party_b_sign')) ?></textarea>
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
            <input type="text" name="purchase_desc" id="purchaseDesc" class="form-control" value="<?= htmlspecialchars($v('purchase_desc')) ?>" placeholder="如：采购造雪机 2 套，每套单价 50000.00 元，合同总金额为人民币 100000.00 元">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">价格说明</label>
                <input type="text" name="tax_note" class="form-control" value="<?= htmlspecialchars($v('tax_note')) ?>" placeholder="如：不含税不含运费">
            </div>
            <div class="form-group">
                <label class="form-label">收货地点</label>
                <input type="text" name="delivery_place" class="form-control" value="<?= htmlspecialchars($v('delivery_place')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">甲方授权接货经办人</label>
                <input type="text" name="receiver_name" class="form-control" value="<?= htmlspecialchars($v('receiver_name')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">接货人电话</label>
                <input type="text" name="receiver_phone" class="form-control" value="<?= htmlspecialchars($v('receiver_phone')) ?>">
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
                <input type="text" name="hotline" class="form-control" value="<?= htmlspecialchars($v('hotline')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">乙方开户行</label>
                <input type="text" name="bank_name" id="bankName" class="form-control" value="<?= htmlspecialchars($v('bank_name')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">乙方账号号码</label>
                <input type="text" name="bank_account" id="bankAccount" class="form-control" value="<?= htmlspecialchars($v('bank_account')) ?>">
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
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存合同</button>
        <a href="contract.php" class="btn btn-outline">取消</a>
    </div>
</form>

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

function numToCny(num) {
    if (num === null || num === undefined || isNaN(num)) return '';
    num = Math.abs(Number(num));
    if (num === 0) return '零元整';
    var upper = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    var unit = ['', '拾', '佰', '仟'];
    var bigUnit = ['', '万', '亿', '万亿'];
    var s = num.toFixed(2);
    var parts = s.split('.');
    var intPart = parts[0];
    var decPart = parts[1];
    var intStr = '';
    var len = intPart.length;
    for (var i = 0; i < len; i++) {
        var n = parseInt(intPart.charAt(i), 10);
        var posInGroup = (len - 1 - i) % 4;
        var groupIdx = Math.floor((len - 1 - i) / 4);
        var u = unit[posInGroup];
        var bu = bigUnit[groupIdx];
        if (n !== 0) {
            intStr += upper[n] + u + (u === '' ? bu : '');
        } else {
            if (intStr.length > 0 && intStr.slice(-1) !== '零' && posInGroup !== 0) {
                intStr += '零';
            }
        }
    }
    intStr = intStr.replace(/零+$/, '').replace(/零+/g, '零');
    var result = intStr + '元';
    var jiao = parseInt(decPart.charAt(0), 10);
    var fen = parseInt(decPart.charAt(1), 10);
    if (jiao === 0 && fen === 0) {
        result += '整';
    } else {
        if (jiao > 0) result += upper[jiao] + '角';
        else if (fen > 0) result += '零';
        if (fen > 0) result += upper[fen] + '分';
    }
    return result;
}

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
          document.getElementById('purchaseDesc').value = d.purchase_desc;
          // 换了客户：顶部甲方信息与甲方签章文本按新客户重算（手工改过的不覆盖）
          custInfo = {
              name:    (d.customer_name === '-') ? '' : d.customer_name,
              address: d.customer_address || '',
              contact: d.customer_contact || '',
              phone:   d.customer_phone || ''
          };
          fillPartyBlocks();
          renderTerms(true);
      })
      .catch(function(e){ alert('请求失败：' + e.message); });
}

renderTerms(<?= $contract ? 'false' : 'true' ?>);

// ===== 合同双方信息：默认值联动 =====
var custInfo = <?= json_encode($custInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var companyInfo = <?= json_encode(['name'=>$companyName, 'address'=>$companyAddress, 'phone'=>$companyPhone], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

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
    var signDate    = document.getElementById('signDate').value || '';
    var bankAccount = document.getElementById('bankAccount').value || '';
    var bankName    = document.getElementById('bankName').value || '';
    if (!partyEdited.a_info) document.getElementById('partyAInfo').value = buildLines([['甲方', custInfo.name], ['地址', custInfo.address], ['联系人', custInfo.contact], ['电话', custInfo.phone]]);
    if (!partyEdited.b_info) document.getElementById('partyBInfo').value = buildLines([['乙方', companyInfo.name], ['地址', companyInfo.address], ['电话', companyInfo.phone]]);
    // 签章块首行放标题，模板里不再重复写（否则打印出来两行标题）
    if (!partyEdited.a_sign) document.getElementById('partyASign').value = buildLines([['地址', custInfo.address], ['联系人', custInfo.contact], ['联系电话', custInfo.phone], ['签约日期', signDate]], '甲方（签章）：');
    if (!partyEdited.b_sign) document.getElementById('partyBSign').value = buildLines([['账号号码', bankAccount], ['开户行', bankName], ['联系电话', companyInfo.phone], ['签约日期', signDate]], '乙方（签章）：');
}

// 乙方两块依赖账号 / 开户行 / 签约日期，任一变化就重算（手工改过的不覆盖）
['signDate','bankAccount','bankName'].forEach(function(id){
    var el = document.getElementById(id);
    el.addEventListener('input', fillPartyBlocks);
    el.addEventListener('change', fillPartyBlocks);
});
fillPartyBlocks();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
