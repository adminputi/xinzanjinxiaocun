<?php
/**
 * 修改密码 API
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    json_response(false, '未登录');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'change_password') {
    json_response(false, '非法请求');
}

// CSRF 保护
csrf_verify();

$userId = $_SESSION['user_id'];
$oldPassword = trim($_POST['old_password'] ?? '');
$newPassword = trim($_POST['new_password'] ?? '');

if (empty($oldPassword) || empty($newPassword)) {
    json_response(false, '请填写所有字段');
}

// 密码强度检查：至少8位，包含大小写字母和数字
if (strlen($newPassword) < 8) {
    json_response(false, '新密码至少需要8个字符');
}
if (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword)) {
    json_response(false, '新密码需要同时包含大写和小写字母');
}
if (!preg_match('/[0-9]/', $newPassword)) {
    json_response(false, '新密码需要包含至少一个数字');
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !password_verify($oldPassword, $user['password'])) {
    json_response(false, '原密码错误');
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->execute([$newHash, $userId]);

add_log($userId, 'update', 'password', '修改密码');
json_response(true, '密码修改成功');
