<?php
/**
 * CRM 客户表单处理
 */
require_once __DIR__ . '/../../includes/api_init.php';
require_permission('crm_customer_edit');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'message' => '非法请求']));
}

// JSON 友好的 CSRF 验证
$token = $_POST['_csrf_token'] ?? '';
if (!csrf_is_valid($token)) {
    csrf_regenerate();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '页面已过期（安全验证失败），请刷新页面后重试',
        'csrf_expired' => true,
        'csrf_token' => $_SESSION['csrf_token'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// 校验通过后不再轮换令牌，避免同一会话其它页面的令牌失效

$id = intval($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$type = $_POST['type'] ?? 'company';
$contact = trim($_POST['contact'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$company = trim($_POST['company'] ?? '');
$wechat = trim($_POST['wechat'] ?? '');
$email = trim($_POST['email'] ?? '');
$sourceId = intval($_POST['source_id'] ?? 0) ?: null;
// 意向是 ENUM('高','中','低')，下拉「请选择」提交的是空串，
// 直接入库在严格 SQL 模式下会报错，必须转成 NULL
$intention = trim($_POST['intention'] ?? '') ?: null;
$address = trim($_POST['address'] ?? '');
$remark = trim($_POST['remark'] ?? '');
$intendedProduct = trim($_POST['intended_product'] ?? '');
$developedAt = trim($_POST['developed_at'] ?? '') ?: null;
// 客户编码与期初应收原先 CRM 这边没有，补回来与主数据口径一致
$code = trim($_POST['code'] ?? '');
$initialBalance = floatval($_POST['initial_balance'] ?? 0);

if (!$name) json_response(false, '请输入客户名称');

if ($code === '') {
    if ($id > 0) {
        // 编辑时留空表示不改动编码，沿用原值（自动生成的编码不该被一次空提交清掉）
        $st = $pdo->prepare("SELECT code FROM customers WHERE id=?");
        $st->execute([$id]);
        $code = (string)$st->fetchColumn();
    } else {
        $code = generate_bill_no('KH');
    }
}
// 编码查重：code 列没有 UNIQUE 约束（历史重复数据会让加约束直接失败），
// 只能在应用层挡，否则两个入口各生成各的会撞号
$dup = $pdo->prepare("SELECT id FROM customers WHERE code<>'' AND code=? AND id<>? LIMIT 1");
$dup->execute([$code, $id]);
if ($code !== '' && $dup->fetch()) json_response(false, "客户编码「{$code}」已被其它客户使用，请换一个");

$userId = get_user_id();

if ($id > 0) {
    $pdo->prepare("UPDATE customers SET code=?,name=?,type=?,contact=?,phone=?,company=?,wechat=?,email=?,source_id=?,intention=?,intended_product=?,address=?,remark=?,developed_at=?,initial_balance=?,updated_at=NOW() WHERE id=?")
        ->execute([$code, $name, $type, $contact, $phone, $company, $wechat, $email, $sourceId, $intention, $intendedProduct, $address, $remark, $developedAt, $initialBalance, $id]);
    add_log($userId, 'update', 'crm', "编辑客户: {$name}");
} else {
    // owner_id=创建人, in_pool=0(归入客户资料而非公海), last_followed_at=NOW()(防止被自动回收)
    $pdo->prepare("INSERT INTO customers (code,name,type,contact,phone,company,wechat,email,source_id,intention,intended_product,address,remark,developed_at,initial_balance,owner_id,in_pool,last_followed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,NOW(),NOW())")
        ->execute([$code, $name, $type, $contact, $phone, $company, $wechat, $email, $sourceId, $intention, $intendedProduct, $address, $remark, $developedAt, $initialBalance, $userId, $userId]);
    add_log($userId, 'create', 'crm', "新增客户: {$name}");
}

json_response(true, '保存成功');
