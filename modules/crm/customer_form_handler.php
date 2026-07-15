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
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_response(false, '安全验证失败，请刷新页面后重试');
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$id = intval($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$type = $_POST['type'] ?? 'company';
$contact = trim($_POST['contact'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$company = trim($_POST['company'] ?? '');
$wechat = trim($_POST['wechat'] ?? '');
$email = trim($_POST['email'] ?? '');
$sourceId = intval($_POST['source_id'] ?? 0) ?: null;
$intention = $_POST['intention'] ?? null;
$address = trim($_POST['address'] ?? '');
$remark = trim($_POST['remark'] ?? '');
$intendedProduct = trim($_POST['intended_product'] ?? '');

if (!$name) json_response(false, '请输入客户名称');
if (!$phone) json_response(false, '请输入电话号码');

$userId = get_user_id();

if ($id > 0) {
    $pdo->prepare("UPDATE customers SET name=?,type=?,contact=?,phone=?,company=?,wechat=?,email=?,source_id=?,intention=?,intended_product=?,address=?,remark=?,updated_at=NOW() WHERE id=?")
        ->execute([$name, $type, $contact, $phone, $company, $wechat, $email, $sourceId, $intention, $intendedProduct, $address, $remark, $id]);
    add_log($userId, 'update', 'crm', "编辑客户: {$name}");
} else {
    $code = generate_bill_no('KH');
    // owner_id=创建人, in_pool=0(归入客户资料而非公海), last_followed_at=NOW()(防止被自动回收)
    $pdo->prepare("INSERT INTO customers (code,name,type,contact,phone,company,wechat,email,source_id,intention,intended_product,address,remark,owner_id,in_pool,last_followed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,NOW(),NOW())")
        ->execute([$code, $name, $type, $contact, $phone, $company, $wechat, $email, $sourceId, $intention, $intendedProduct, $address, $remark, $userId, $userId]);
    add_log($userId, 'create', 'crm', "新增客户: {$name}");
}

json_response(true, '保存成功');
