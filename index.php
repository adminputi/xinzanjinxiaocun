<?php
/**
 * 登录页面
 */
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
// 已登录直接跳转
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// 生成CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';

// 检查系统是否已安装
$isInstalled = file_exists(__DIR__ . '/config/installed.lock');

// 登录页系统显示名称：默认值（未安装时使用）
$loginSiteName = '进销存管理系统';

// 检查是否启用验证码
$captchaEnabled = false;
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    try {
        $captchaRow = getDB()->query("SELECT setting_value FROM system_settings WHERE setting_key='captcha_enabled'")->fetch();
        $captchaEnabled = ($captchaRow && $captchaRow['setting_value'] === '1');
    } catch (Exception $e) { /* 安装前无表 */ }
    // 登录页系统显示名称：授权客户使用系统设置中的名称，未授权显示默认 SITE_NAME
    $loginSiteName = defined('SITE_NAME') ? SITE_NAME : '进销存管理系统';
    if (file_exists(__DIR__ . '/includes/license.php')) {
        require_once __DIR__ . '/includes/license.php';
        $licenseStatus = license_get_status();
        if ($licenseStatus['status'] === 'active') {
            try {
                $row = getDB()->query("SELECT setting_value FROM system_settings WHERE setting_key='site_name'")->fetch();
                if ($row && !empty(trim($row['setting_value']))) {
                    $loginSiteName = $row['setting_value'];
                }
            } catch (Exception $e) {}
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    $token = $_POST['_csrf_token'] ?? '';
    $csrfOk = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    if (!$csrfOk) {
        $error = '安全验证失败，请刷新页面重试';
    } else {
        // 验证码校验（仅当启用时）
        $captchaOk = true;
        if ($captchaEnabled) {
            $captchaInput = trim($_POST['captcha'] ?? '');
            $captchaAnswer = $_SESSION['captcha_answer'] ?? null;
            $captchaOk = ($captchaInput !== '' && $captchaAnswer !== null && intval($captchaInput) === intval($captchaAnswer));
            unset($_SESSION['captcha_answer']); // 一次性使用
            if (!$captchaOk) {
                $error = '验证码错误，请重新输入';
            }
        }
        
        if ($captchaOk) {
            require_once __DIR__ . '/includes/auth.php';
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                $error = '请输入用户名和密码';
            } elseif (user_login($username, $password)) {
                header('Location: dashboard.php');
                exit;
            } else {
                $error = '用户名或密码错误，或账号已被禁用';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?= $loginSiteName ?></title>
    <link rel="stylesheet" href="<?= defined('CDN_FONTAWESOME') ? CDN_FONTAWESOME : 'https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css' ?>">
    <style>
        :root { --primary: #4361ee; --danger: #ef4444; --gray-100: #f1f5f9; --gray-300: #cbd5e1; --gray-500: #64748b; --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b; --radius: 8px; --radius-lg: 12px; --shadow-lg: 0 20px 60px rgba(0,0,0,0.15); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans SC", sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; }
        .login-card { background: white; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); padding: 40px; width: 100%; max-width: 400px; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .login-card h1 { text-align: center; font-size: 24px; color: var(--gray-800); margin-bottom: 4px; }
        .login-card h1 i { color: var(--primary); margin-right: 6px; }
        .login-card .subtitle { text-align: center; color: var(--gray-500); font-size: 13px; margin-bottom: 32px; }
        .alert { padding: 10px 14px; border-radius: var(--radius); margin-bottom: 16px; font-size: 13px; background: #fef2f2; color: #991b1b; text-align: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 15px; outline: none; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .btn { width: 100%; padding: 12px; border-radius: var(--radius); font-size: 15px; font-weight: 600; border: none; cursor: pointer; background: var(--primary); color: white; margin-top: 8px; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .info { text-align: center; margin-top: 16px; font-size: 12px; color: var(--gray-500); }
        .captcha-row { display: flex; gap: 10px; align-items: center; }
        .captcha-row .form-control { width: auto; flex: 1; }
        .captcha-img { border-radius: var(--radius); border: 1px solid var(--gray-300); cursor: pointer; height: 44px; }
        .captcha-refresh { font-size: 12px; color: var(--gray-500); cursor: pointer; white-space: nowrap; user-select: none; }
        .captcha-refresh:hover { color: var(--primary); }
        @media (max-width: 768px) {
            .captcha-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .captcha-row .form-control { width: 100%; }
            .captcha-img { height: 48px; align-self: center; }
            .captcha-refresh { text-align: center; }
        }
    </style>
</head>
<body>
<div class="login-card">
    <h1><i class="fa-solid fa-boxes-stacked"></i><?= $loginSiteName ?></h1>
    <p class="subtitle">请登录您的账号</p>

    <?php if ($error): ?><div class="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <div class="form-group">
            <label>用户名</label>
            <input type="text" name="username" class="form-control" placeholder="请输入用户名" required autofocus>
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" name="password" class="form-control" placeholder="请输入密码" required>
        </div>
        <?php if ($captchaEnabled): ?>
        <div class="form-group">
            <label>验证码</label>
            <div class="captcha-row">
                <input type="text" name="captcha" class="form-control" placeholder="请输入计算结果" required autocomplete="off" maxlength="5">
                <img src="captcha.php?t=<?= time() ?>" class="captcha-img" id="captchaImg" alt="验证码" onclick="refreshCaptcha()" title="点击刷新验证码">
                <span class="captcha-refresh" onclick="refreshCaptcha()"><i class="fa-solid fa-rotate-right"></i> 换一张</span>
            </div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn"><i class="fa-solid fa-right-to-bracket"></i> 登 录</button>
    </form>
    <?php if (!$isInstalled): ?>
    <p class="info">首次使用？<a href="install/index.php">点击安装系统</a></p>
    <?php endif; ?>
</div>
<?php if ($captchaEnabled): ?>
<script>
function refreshCaptcha() {
    var img = document.getElementById('captchaImg');
    img.src = 'captcha.php?t=' + Date.now();
}
</script>
<?php endif; ?>
</body>
</html>
