<?php
/**
 * 用户个人资料 API
 * 支持：获取资料、更新资料、修改密码
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get') {
    // 获取当前用户资料
    $stmt = $pdo->prepare("SELECT real_name, email, phone FROM users WHERE id = ?");
    $stmt->execute([get_user_id()]);
    $user = $stmt->fetch();
    json_response(true, 'ok', [
        'real_name' => $user['real_name'] ?? '',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
    ]);
}

if ($action === 'update_profile') {
    // CSRF 保护
    csrf_verify();

    $realName = trim($_POST['real_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // 邮箱格式校验
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, '邮箱格式不正确');
    }

    $pdo->prepare("UPDATE users SET real_name = ?, email = ?, phone = ? WHERE id = ?")
        ->execute([$realName, $email, $phone, get_user_id()]);

    // 更新会话中的用户名
    if ($realName !== '') {
        $_SESSION['user_name'] = $realName;
    }

    add_log(get_user_id(), 'update', 'profile', '更新个人资料');
    json_response(true, '个人资料已更新');
}

if ($action === 'change_password') {
    // CSRF 保护
    csrf_verify();

    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($oldPassword) || empty($newPassword)) {
        json_response(false, '请填写所有密码字段');
    }
    if (strlen($newPassword) < 8) {
        json_response(false, '新密码至少需要8个字符');
    }
    if (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword)) {
        json_response(false, '新密码需要同时包含大写和小写字母');
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        json_response(false, '新密码需要包含至少一个数字');
    }
    if ($newPassword !== $confirmPassword) {
        json_response(false, '两次输入的新密码不一致');
    }

    // 验证原密码
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([get_user_id()]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPassword, $user['password'])) {
        json_response(false, '原密码错误');
    }

    // 更新密码
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, get_user_id()]);

    add_log(get_user_id(), 'update', 'profile', '修改密码');
    json_response(true, '密码修改成功');
}

json_response(false, '未知操作');
