<?php
/**
 * 系统安装向导
 */
// 安装阶段只记录错误到日志，不在页面上暴露
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$step = isset($_POST['step']) ? intval($_POST['step']) : (isset($_GET['step']) ? intval($_GET['step']) : 1);
$errors = [];
$success = '';

// 检查是否已安装
if (file_exists(__DIR__ . '/../config/installed.lock') && $step < 4) {
    die('<div style="text-align:center;margin-top:100px;"><h3>系统已安装</h3><p>如需重新安装，请删除 config/installed.lock 文件</p><a href="../index.php">返回首页</a></div>');
}

$dbConfig = [
    'host' => $_POST['db_host'] ?? 'localhost',
    'port' => $_POST['db_port'] ?? '3306',
    'name' => $_POST['db_name'] ?? 'jinxiaocun',
    'user' => $_POST['db_user'] ?? 'root',
    'pass' => $_POST['db_pass'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // 验证数据库连接
        try {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);

            // 创建数据库
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbConfig['name']}`");

            // 过滤站点名称中的特殊字符，防止 PHP 代码注入
            $siteName = addcslashes(trim($_POST['site_name'] ?? '进销存管理系统'), "'\\");
            if (empty($siteName)) $siteName = '进销存管理系统';
            
            // 保存配置（含新增的安全和CDN配置）
            $siteUrl = trim($_POST['site_url'] ?? '');
            $configContent = "<?php
define('DB_HOST', '{$dbConfig['host']}');
define('DB_NAME', '{$dbConfig['name']}');
define('DB_USER', '{$dbConfig['user']}');
define('DB_PASS', '{$dbConfig['pass']}');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', '{$dbConfig['port']}');
define('SITE_NAME', '" . $siteName . "');
define('SITE_URL', '" . addcslashes($siteUrl, "'\\") . "');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('ITEMS_PER_PAGE', 20);
define('LOW_STOCK_DAYS', 30);
define('SECURE_HASH', '" . bin2hex(random_bytes(32)) . "');

// CDN 资源配置（可修改为本地路径以支持离线部署）
define('CDN_FONTAWESOME', 'https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css');
define('CDN_CHARTJS', 'https://cdn.bootcdn.net/ajax/libs/Chart.js/4.4.0/chart.umd.min.js');

// 安全模式：生产环境设为 true，禁止访问 check.php 等诊断工具
define('PRODUCTION_MODE', false);
date_default_timezone_set('Asia/Shanghai');

function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        try {
            \$dsn = \"mysql:host=\" . DB_HOST . \";port=\" . DB_PORT . \";dbname=\" . DB_NAME . \";charset=\" . DB_CHARSET;
            \$options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4\"
            ];
            \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options);
        } catch (PDOException \$e) {
            die(\"数据库连接失败，请检查数据库配置\");
        }
    }
    return \$pdo;
}
";
            file_put_contents(__DIR__ . '/../config/database.php', $configContent);

            // 执行建表
            require_once __DIR__ . '/../config/database.php';
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            // 按 ; + 换行 拆分，避免把模板内联CSS的分号误拆
            $sql = str_replace("\r\n", "\n", $sql);
            $statements = array_filter(array_map('trim', explode(";\n", $sql)));
            $pdo2 = getDB();
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    $pdo2->exec($stmt . ';');
                }
            }

            $success = '数据库配置成功，请设置管理员账号';
            $step = 2;
        } catch (PDOException $e) {
            error_log('Install database error: ' . $e->getMessage());
            $errors[] = '数据库连接失败，请检查数据库配置是否正确';
        }
    } elseif ($step == 2) {
        // 创建管理员
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $realName = $_POST['real_name'] ?? '';

        if (strlen($username) < 3) $errors[] = '用户名至少3个字符';
        if (strlen($password) < 8) $errors[] = '密码至少8个字符';
        elseif (!preg_match('/[a-z]/', $password)) $errors[] = '密码需要包含小写字母';
        elseif (!preg_match('/[A-Z]/', $password)) $errors[] = '密码需要包含大写字母';
        elseif (!preg_match('/[0-9]/', $password)) $errors[] = '密码需要包含数字';
        if (empty($realName)) $errors[] = '请输入真实姓名';

        if (empty($errors)) {
            require_once __DIR__ . '/../config/database.php';
            $pdo = getDB();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, password, real_name, role_id, status, created_at) VALUES (?,?,?,1,1,NOW())")
                ->execute([$username, $hash, $realName]);

            file_put_contents(__DIR__ . '/../config/installed.lock', date('Y-m-d H:i:s'));
            $success = '安装完成！请使用管理员账号登录';
            $step = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安装 - 进销存管理系统</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --success: #10b981; --gray-100: #f1f5f9; --gray-200: #e2e8f0; --gray-300: #cbd5e1; --gray-500: #64748b; --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b; --danger: #ef4444; --radius: 8px; --radius-lg: 12px; --shadow: 0 1px 3px rgba(0,0,0,0.1); --shadow-lg: 0 10px 15px rgba(0,0,0,0.1); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans SC", sans-serif; font-size: 14px; color: var(--gray-700); background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .install-card { background: white; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); padding: 40px; width: 100%; max-width: 520px; }
        .install-card h1 { text-align: center; font-size: 22px; color: var(--gray-800); margin-bottom: 8px; }
        .install-card .sub { text-align: center; color: var(--gray-500); font-size: 13px; margin-bottom: 28px; }
        .steps { display: flex; justify-content: center; gap: 12px; margin-bottom: 28px; }
        .step { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; border: 2px solid var(--gray-300); color: var(--gray-500); }
        .step.active { background: var(--primary); color: white; border-color: var(--primary); }
        .step.done { background: var(--success); color: white; border-color: var(--success); }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 14px; outline: none; transition: border-color .2s; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .btn { width: 100%; padding: 12px; border-radius: var(--radius); font-size: 15px; font-weight: 600; border: none; cursor: pointer; background: var(--primary); color: white; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .alert { padding: 10px 14px; border-radius: var(--radius); margin-bottom: 16px; font-size: 13px; }
        .alert-error { background: #fef2f2; color: #991b1b; }
        .alert-success { background: #f0fdf4; color: #166534; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    </style>
</head>
<body>
<div class="install-card">
    <h1><i class="fa-solid fa-boxes-stacked"></i> 进销存管理系统</h1>
    <p class="sub">安装向导</p>

    <div class="steps">
        <div class="step <?= $step == 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">1</div>
        <div class="step <?= $step == 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2</div>
        <div class="step <?= $step == 3 ? 'active' : '' ?>">3</div>
    </div>

    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= $e ?></div><?php endforeach; ?>
    <?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <?php if ($step == 1): ?>
    <form method="post">
        <input type="hidden" name="step" value="1">
        <div class="form-group"><label class="form-label">数据库主机</label><input class="form-control" name="db_host" value="localhost"></div>
        <div class="row">
            <div class="form-group"><label class="form-label">端口</label><input class="form-control" name="db_port" value="3306"></div>
            <div class="form-group"><label class="form-label">数据库名</label><input class="form-control" name="db_name" value="jinxiaocun"></div>
        </div>
        <div class="row">
            <div class="form-group"><label class="form-label">用户名</label><input class="form-control" name="db_user" value="root"></div>
            <div class="form-group"><label class="form-label">密码</label><input class="form-control" type="password" name="db_pass"></div>
        </div>
        <div class="form-group"><label class="form-label">系统名称</label><input class="form-control" name="site_name" value="进销存管理系统"></div>
        <div class="form-group"><label class="form-label">站点地址</label><input class="form-control" name="site_url" placeholder="如 http://example.com（可选，用于二维码生成）"></div>
        <button type="submit" class="btn">下一步 &raquo;</button>
    </form>

    <?php elseif ($step == 2): ?>
    <form method="post">
        <input type="hidden" name="step" value="2">
        <input type="hidden" name="db_host" value="<?= $dbConfig['host'] ?>">
        <input type="hidden" name="db_port" value="<?= $dbConfig['port'] ?>">
        <input type="hidden" name="db_name" value="<?= $dbConfig['name'] ?>">
        <input type="hidden" name="db_user" value="<?= $dbConfig['user'] ?>">
        <input type="hidden" name="db_pass" value="<?= $dbConfig['pass'] ?>">
        <div class="form-group"><label class="form-label">管理员用户名 <span style="color:red">*</span></label><input class="form-control" name="username" required minlength="3" placeholder="至少3个字符"></div>
        <div class="form-group"><label class="form-label">真实姓名 <span style="color:red">*</span></label><input class="form-control" name="real_name" required placeholder="如：张三"></div>
        <div class="form-group"><label class="form-label">登录密码 <span style="color:red">*</span></label><input class="form-control" type="password" name="password" required minlength="8" placeholder="至少8位，含大小写+数字"></div>
        <button type="submit" class="btn">完成安装</button>
    </form>

    <?php elseif ($step == 3): ?>
    <div style="text-align:center;">
        <i class="fa-solid fa-circle-check" style="font-size:48px;color:var(--success);margin-bottom:16px;"></i>
        <p style="margin-bottom:20px;color:var(--gray-600);">系统安装完成，请使用管理员账号登录</p>
        <a href="../index.php" class="btn" style="display:inline-block;text-align:center;width:auto;padding:10px 40px;margin-bottom:20px;">前往登录</a>
        
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin-top:16px;text-align:left;">
            <strong style="color:#856404;"><i class="fa-solid fa-triangle-exclamation"></i> 安全提示</strong>
            <ul style="margin:8px 0 0 16px;font-size:12px;color:#856404;line-height:1.8;">
                <li>请删除 <code>install/</code> 目录，防止被重新安装</li>
                <li>请删除 <code>check.php</code> 诊断文件</li>
                <li>建议修改 <code>config/database.php</code> 中 <code>PRODUCTION_MODE</code> 为 <code>true</code></li>
                <li>确保 <code>config/.secure_hash</code> 和 <code>config/secure_hash.php</code> 的权限安全</li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
