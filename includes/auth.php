<?php
/**
 * 认证与权限检查
 */
require_once __DIR__ . '/functions.php';

// 检查是否已登录
if (!in_array(basename($_SERVER['PHP_SELF']), ['index.php', 'login.php', 'install.php', 'track.php'])) {
    if (!isset($_SESSION['user_id'])) {
        $isApi = strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
        if ($isApi) {
            json_response(false, '未登录或登录已过期');
        }
        $rd = dirname($_SERVER['PHP_SELF']);
        $d = ($rd === '/' || $rd === '\\') ? 0 : substr_count($rd, '/');
        $loginPath = ($d > 0 ? str_repeat('../', $d) . '/' : '') . 'index.php';
        redirect($loginPath);
    }

    // 每次页面加载时从数据库刷新用户权限，确保角色权限修改后实时生效
    // 对 admin 角色跳过（admin 拥有所有权限）
    if (isset($_SESSION['user_id'], $_SESSION['role_id']) && ($_SESSION['user_role'] ?? '') !== 'admin') {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE id = ?");
            $stmt->execute([$_SESSION['role_id']]);
            $role = $stmt->fetch();
            if ($role) {
                $_SESSION['permissions'] = json_decode($role['permissions'] ?: '[]', true);
                // error_log("[auth.php] 权限已刷新: user_id={$_SESSION['user_id']} role_id={$_SESSION['role_id']} role={$_SESSION['user_role']} perms=" . json_encode($_SESSION['permissions']));
            } else {
                // error_log("[auth.php] 权限刷新失败: role_id={$_SESSION['role_id']} 在roles表中未找到");
            }
        } catch (Exception $e) {
            // error_log("[auth.php] 权限刷新异常: " . $e->getMessage());
        }
    }
}

/**
 * 登录暴力破解防护 - 检查是否被锁定
 */
function is_login_locked($username) {
    $maxAttempts = 5;       // 最多尝试次数
    $lockMinutes = 15;      // 锁定时长（分钟）
    $key = 'login_attempts_' . hash('sha256', $username);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'last_time' => 0];
    if ($attempts['count'] >= $maxAttempts) {
        $elapsed = time() - $attempts['last_time'];
        if ($elapsed < $lockMinutes * 60) {
            return true; // 仍被锁定
        }
        // 锁定时间已过，重置
        unset($_SESSION[$key]);
    }
    return false;
}

/**
 * 记录登录失败
 */
function record_login_failure($username) {
    $key = 'login_attempts_' . hash('sha256', $username);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'last_time' => 0];
    $attempts['count']++;
    $attempts['last_time'] = time();
    $_SESSION[$key] = $attempts;
}

/**
 * 清除登录失败记录
 */
function clear_login_attempts($username) {
    $key = 'login_attempts_' . hash('sha256', $username);
    unset($_SESSION[$key]);
}

/**
 * 用户登录
 */
function user_login($username, $password) {
    // 暴力破解防护
    if (is_login_locked($username)) {
        return false;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name, r.permissions FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.username = ? AND u.status = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // 重新生成 Session ID 防止 Session Fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['real_name'] ?: $user['username'];
        $_SESSION['user_role'] = $user['role_name'];
        $_SESSION['permissions'] = json_decode($user['permissions'] ?: '[]', true);
        $_SESSION['role_id'] = $user['role_id'];

        // 清除失败记录
        clear_login_attempts($username);
        // 重新初始化 CSRF Token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // 更新登录信息
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), login_ip = ? WHERE id = ?");
        $stmt->execute([get_client_ip(), $user['id']]);

        add_log($user['id'], 'login', 'auth', '用户登录成功');
        return true;
    }

    // 记录登录失败
    record_login_failure($username);
    return false;
}

/**
 * 用户登出
 */
function user_logout() {
    if (isset($_SESSION['user_id'])) {
        add_log($_SESSION['user_id'], 'logout', 'auth', '用户登出');
    }
    // 彻底清除session数据，防止残留
    $_SESSION = [];
    // 删除客户端session cookie，确保下次访问生成全新session
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    redirect('index.php');
}
