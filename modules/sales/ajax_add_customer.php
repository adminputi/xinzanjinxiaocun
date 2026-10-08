<?php
/**
 * AJAX: 快速新增客户
 */
require_once __DIR__ . '/../../includes/functions.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'未登录']); exit; }

$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add_customer') {
    // 权限检查
    if (!check_permission('sales')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'无操作权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // CSRF验证
    $token = $_POST['_csrf_token'] ?? '';
    if (!csrf_is_valid($token)) {
        csrf_regenerate();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'页面已过期（安全验证失败），请刷新页面后重试','csrf_expired'=>true,'csrf_token'=>$_SESSION['csrf_token']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = trim($_POST['cust_name'] ?? '');
    $phone = trim($_POST['cust_phone'] ?? '');
    $address = trim($_POST['cust_address'] ?? '');
    $contact = trim($_POST['cust_contact'] ?? '');
    $contactPhone = trim($_POST['cust_contact_phone'] ?? '');
    if (empty($name)) { echo json_encode(['success'=>false,'message'=>'客户名称不能为空'], JSON_UNESCAPED_UNICODE); exit; }
    $pdo->prepare("INSERT INTO customers (name,phone,address,contact,created_at) VALUES (?,?,?,?,?)")->execute([$name,$phone,$address,$contact,date('Y-m-d H:i:s')]);
    $newId = $pdo->lastInsertId();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'id'=>$newId,'name'=>$name,'phone'=>$phone,'address'=>$address,'contact'=>$contact,'contact_phone'=>$contactPhone], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['success'=>false,'message'=>'无效请求']);
