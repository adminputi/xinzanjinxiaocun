<?php
/**
 * 公共函数库
 */

/**
 * 系统版本号 —— 页面显示的「系统版本 / 系统名称」都从这里取，升级时只改这一处
 * 注意：README.md、CHANGELOG.md 里的版本号属于文档正文，仍需手动同步
 */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'V1.3.2');
}

require_once __DIR__ . '/../config/database.php';
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

// 根输出缓冲：header.php 会先输出 HTML，若没有缓冲，任何后续的 header()/redirect()
// 都会变成 "headers already sent"，只能退化成 JS 跳转，页面会闪一下（甚至露出警告原文）。
//
// 注意：这里的判断不能写成 if (!ob_get_level())。
// php.ini 的 output_buffering 默认 4096（生产环境常见值）时，PHP 启动时已存在 1 层缓冲，
// 上面那种写法就不会再开新层，于是页面 HTML 直接进那层 4096 字节的缓冲，
// 写满即自动 flush 到客户端 —— 响应头一旦发出，redirect() 的 Location 就彻底失效，
// 表现为「保存/编辑后一片空白，什么也点不了」。
// 因此必须在最内层无条件再开一个「无大小限制」的缓冲层，
// 保证整页渲染完成前不会真正发出任何一个字节，header() 随时可用。
ob_start();

/**
 * JavaScript 字符串安全转义（PHP 值嵌入到 <script> 标签内 JS 单引号字符串时使用）
 * 在 addslashes 基础上额外将 </ 替换为 <\/，防止 </script> 标签闭合攻击；
 * 并把真实换行转成 \n —— addslashes 不管换行，含换行的文本（如多行总备注）
 * 会让 JS 字符串跨行触发 SyntaxError，整段脚本失效（打印/导出按钮因此「点了没反应」）。
 * 顺序不可颠倒：必须先 addslashes 再转换行，否则新插入的反斜杠会被二次转义成 \\，
 * 得到字面 "\n" 两个字符而不是换行。
 */
function js_escape($str) {
    $s = addslashes((string)$str);
    $s = str_replace(array("\r\n", "\r", "\n"), '\n', $s);
    return str_replace('</', '<\/', $s);
}

/**
 * 输出转义（防止 XSS），支持字符串和数组递归转义
 */
function esc($data) {
    if (is_array($data)) {
        return array_map('esc', $data);
    }
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

/**
 * 安全 SQL 标识符（表名/列名白名单校验）
 * 仅允许字母、数字、下划线，防止 SQL 注入
 */
function safe_identifier($name) {
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        return "`$name`";
    }
    throw new InvalidArgumentException("Invalid SQL identifier: " . substr($name, 0, 30));
}

/**
 * CSRF Token 初始化（session 启动后自动执行一次）
 */
function csrf_init() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['upload_token'])) {
        $_SESSION['upload_token'] = bin2hex(random_bytes(32));
    }
    // 令牌池：保留会话期内最近若干个有效令牌，
    // 避免多标签页 / 浏览器后退 / 旧页面里的令牌一提交就失效
    if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [$_SESSION['csrf_token']];
    }
}
csrf_init();

/**
 * 兜底：前端若漏发 Content-Type（body 传字符串时浏览器不会自动补），
 * PHP 按 text/plain 处理不会填充 $_POST，所有字段（含 CSRF 令牌）都会“丢失”。
 * 这里在 $_POST 为空时按 urlencoded 手动解析一次请求体。
 */
function ensure_post_body_parsed() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (!empty($_POST)) return;
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== false) return;     // JSON 接口自行处理
    if (strpos($ct, 'multipart/form-data') !== false) return;  // 文件上传由 PHP 解析
    $raw = @file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return;
    $firstChar = substr(ltrim($raw), 0, 1);
    if ($firstChar === '{' || $firstChar === '[') return;      // 疑似 JSON，不动
    if (strpos($raw, '=') === false) return;
    $parsed = [];
    parse_str($raw, $parsed);
    if (is_array($parsed)) {
        foreach ($parsed as $k => $v) {
            if (!array_key_exists($k, $_POST)) $_POST[$k] = $v;
        }
    }
}
ensure_post_body_parsed();

/**
 * 获取 CSRF Token 值
 */
function csrf_token() {
    $cur = $_SESSION['csrf_token'] ?? '';
    if ($cur !== '' && isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])
        && !in_array($cur, $_SESSION['csrf_tokens'], true)) {
        $_SESSION['csrf_tokens'][] = $cur;
        // 与 csrf_regenerate() 保持一致（20）：这里若只留 5 个，
        // 多开几个标签页就会把旧页面的令牌挤出池子，旧页面提交直接报“安全验证失败”
        if (count($_SESSION['csrf_tokens']) > 20) {
            $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], -20);
        }
    }
    return $cur;
}

/**
 * 判断令牌是否有效：命中当前令牌或会话内最近的历史令牌均可
 */
function csrf_is_valid($token) {
    if (!is_string($token) || $token === '') return false;
    $cur = $_SESSION['csrf_token'] ?? '';
    if ($cur !== '' && hash_equals($cur, $token)) return true;
    $pool = $_SESSION['csrf_tokens'] ?? [];
    if (!is_array($pool)) return false;
    foreach ($pool as $t) {
        if (is_string($t) && $t !== '' && hash_equals($t, $token)) return true;
    }
    return false;
}

/**
 * 生成新令牌（令牌失效时使用），新令牌同时进入令牌池
 */
function csrf_regenerate() {
    $t = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $t;
    $pool = (isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) ? $_SESSION['csrf_tokens'] : [];
    $pool[] = $t;
    // 保留最近 20 个令牌：只留 5 个时，多开几个标签页就会把旧页面的令牌挤出池子，
    // 导致用户在旧页面提交时直接报“安全验证失败”
    $_SESSION['csrf_tokens'] = array_slice($pool, -20);
    return $t;
}

/**
 * 输出 CSRF 隐藏域 HTML，用于表单中
 */
function csrf_field() {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * 验证 CSRF Token（POST 请求使用）
 */
function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['_csrf_token'] ?? '';
    if (!csrf_is_valid($token)) {
        // 重新生成 token 防止重放
        csrf_regenerate();
        $isAjaxCsrf = (($_POST['_ajax'] ?? '') === '1')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        $csrfMsg = '页面已过期（安全验证失败），请刷新页面后重试';
        if ($isAjaxCsrf) {
            // AJAX 请求必须返回 JSON，否则前端 r.json() 会抛出 "Unexpected token '<'"，
            // 用户只看到莫名报错，无法得知真实原因
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'success' => false,
                'msg' => $csrfMsg,
                'message' => $csrfMsg,
                'csrf_expired' => true,
                'csrf_token' => $_SESSION['csrf_token'],
                // 诊断信息：仅在排查令牌失效时使用（前端会展示在提示里）
                'csrf_debug' => 'URI=' . ($_SERVER['REQUEST_URI'] ?? '')
                    . ' | SID=' . substr(session_id(), 0, 6)
                    . ' | 提交令牌长度=' . strlen($token)
                    . ' 前缀=' . substr($token, 0, 6)
                    . ' | 会话令牌长度=' . strlen($_SESSION['csrf_token'])
                    . ' 前缀=' . substr($_SESSION['csrf_token'], 0, 6),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 不能再 die 出一张提示页：那是一个没有菜单、没有任何业务入口的页面，
        // 用户的感受就是“保存后一片空白，什么也点不了，只能点首页”。
        // 改为：回到刚才的页面 + 顶部提示「数据未被修改」，用户可立刻重做。
        // 有了根输出缓冲，此处 redirect() 的 Location 必定生效（不会退化成 JS 兜底）。
        flash_set('页面已过期（安全验证失败），数据未被修改，请刷新页面后重新操作', 'warning');
        $csrfBack = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($csrfBack === '') { $csrfBack = (string)($_SERVER['HTTP_REFERER'] ?? ''); }
        // 防 CRLF 注入（header() 不接受含换行的值）
        $csrfBack = str_replace(["\r", "\n", "\0"], '', $csrfBack);
        redirect($csrfBack !== '' ? $csrfBack : 'index.php');
    }
    // 验证通过后不再重新生成 token：
    // 每次成功后轮换会导致多标签页、浏览器后退、以及同一页面连续 AJAX 提交时
    // 页面上的隐藏域立即失效，后续提交全部报"安全验证失败"
    return true;
}

/**
 * 获取上传专用 Token 值（用于图片上传等不刷新页面的AJAX操作）
 */
function upload_token() {
    return $_SESSION['upload_token'] ?? '';
}

/**
 * 验证上传专用 Token（不影响主表单的 csrf_token）
 */
function upload_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['_upload_token'] ?? '';
    if (empty($_SESSION['upload_token']) || !hash_equals($_SESSION['upload_token'], $token)) {
        $_SESSION['upload_token'] = bin2hex(random_bytes(32));
        die(json_encode(['success' => false, 'message' => '上传安全验证失败，请刷新页面重试'], JSON_UNESCAPED_UNICODE));
    }
    // 验证通过后重新生成 upload_token
    $_SESSION['upload_token'] = bin2hex(random_bytes(32));
    return true;
}

/**
 * 安全过滤输入（仅去除首尾空白，不做HTML转义 - 输出时再转义）
 */
function safe_input($data) {
    if (is_array($data)) {
        return array_map('safe_input', $data);
    }
    return trim((string)$data);
}

/**
 * 输出安全响应头
 */
function set_security_headers() {
    // 禁止缓存含 CSRF 令牌的页面：否则浏览器后退/缓存恢复时页面里的令牌是旧的
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // 如果使用HTTPS，取消注释下面这行
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/**
 * JSON响应
 */
function json_response($success, $message, $data = null) {
    // 清除之前的所有输出（Notice/Warning/已渲染的HTML等），确保返回纯JSON。
    // 必须逐层清干净：只 ob_clean() 一层时，外层的残留内容仍会拼在 JSON 前面，
    // 前端 r.json() 会抛出 "Unexpected token '<'"
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 重定向
 */
function redirect($url) {
    // 清空所有输出缓冲：即使页面已输出过 HTML，只要还没真正 flush 出去，跳转依然有效
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        header("Location: $url");
        exit;
    }
    // 极端情况（响应已发出）：只能跳出当前页面范围内的内容，避免把旧页面残留在浏览器里
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>跳转中…</title></head><body style="font-family:sans-serif;text-align:center;padding:40px;">'
        . '<p>正在跳转…</p>'
        . '<script>location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ');</script>'
        . '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>'
        . '</body></html>';
    exit;
}

/**
 * 生成单据编号（含微秒防碰撞）
 */
function generate_bill_no($prefix) {
    $date = date('Ymd');
    $micro = substr((string)intval(microtime(true) * 1000), -4);
    $rand = str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);
    return $prefix . $date . $micro . $rand;
}

/**
 * 获取分页数据（参数化查询，防止 SQL 注入）
 * 
 * @param string $table      表名（仅允许字母数字下划线）
 * @param int    $page       当前页码
 * @param array  $conditions 条件数组，格式: [['field', 'op', value], ...] 或保持 '' 为空
 *                            支持的 op: =, !=, >, <, >=, <=, LIKE, IN
 * @param string $orderBy    排序字段（白名单校验）
 * @param string $orderDir   排序方向 ASC/DESC（白名单校验）
 * @param int    $perPage    每页条数
 * @return array
 */
function get_paginated_data($table, $page = 1, $conditions = [], $orderBy = 'id', $orderDir = 'DESC', $perPage = null) {
    $pdo = getDB();
    $perPage = $perPage ?: ITEMS_PER_PAGE;
    $offset = max(0, ($page - 1) * $perPage);
    
    // 白名单校验排序字段
    $allowedDirs = ['ASC', 'DESC'];
    $orderDir = strtoupper($orderDir);
    if (!in_array($orderDir, $allowedDirs)) {
        $orderDir = 'DESC';
    }
    
    // 安全引用表名
    $tableSafe = safe_identifier($table);
    
    // 构建 WHERE 子句
    $whereClauses = [];
    $params = [];
    
    if (!empty($conditions) && is_array($conditions)) {
        foreach ($conditions as $cond) {
            if (count($cond) < 3) continue;
            $field = $cond[0];
            $op = strtoupper(trim($cond[1]));
            $value = $cond[2];
            
            // 白名单校验操作符
            $allowedOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'IN', 'NOT IN'];
            if (!in_array($op, $allowedOps)) continue;
            
            // 安全引用字段名
            $fieldSafe = safe_identifier($field);
            
            if ($op === 'IN' || $op === 'NOT IN') {
                if (!is_array($value) || empty($value)) continue;
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $whereClauses[] = "$fieldSafe $op ($placeholders)";
                $params = array_merge($params, array_values($value));
            } else {
                $whereClauses[] = "$fieldSafe $op ?";
                $params[] = $value;
            }
        }
    }
    
    $whereStr = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // 安全引用排序列（支持 table.field 格式）
    $orderParts = explode('.', $orderBy);
    $orderSafe = implode('.', array_map(function($p) {
        return safe_identifier(trim($p));
    }, $orderParts));
    $orderDirSafe = $orderDir;
    
    // 查询数据
    $sql = "SELECT * FROM $tableSafe$whereStr ORDER BY $orderSafe $orderDirSafe LIMIT ?, ?";
    $queryParams = array_merge($params, [$offset, $perPage]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $data = $stmt->fetchAll();
    
    // 查询总数
    $countSql = "SELECT COUNT(*) FROM $tableSafe$whereStr";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    return [
        'data' => $data,
        'total' => $total,
        'pages' => max(1, ceil($total / $perPage)),
        'page' => $page
    ];
}

/**
 * 获取客户端真实IP（支持反向代理）
 */
function get_client_ip() {
    // 优先从可信代理头获取
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 记录操作日志（非关键操作，失败不中断业务）
 */
function add_log($userId, $action, $module, $content = '') {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO operation_logs (user_id, action, module, content, ip_address, created_at) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId, $action, $module, $content, get_client_ip(), date('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        // 日志写入失败应记录到错误日志，但不影响业务流程
        error_log("add_log failed [module=$module, action=$action]: " . $e->getMessage());
    }
}

/**
 * 获取当前用户ID
 */
function get_user_id() {
    return $_SESSION['user_id'] ?? 0;
}

/**
 * 获取当前用户名
 */
function get_user_name() {
    return $_SESSION['user_name'] ?? '未知';
}

/**
 * 获取当前用户角色
 */
function get_user_role() {
    return $_SESSION['user_role'] ?? '';
}

/**
 * 检查权限
 * 每次请求首次调用时直接从数据库加载权限（静态缓存），
 * 确保角色权限修改后实时生效，不依赖 $_SESSION['permissions'] 的时效性
 */
function check_permission($permission) {
    if (!isset($_SESSION['user_id'])) return false;
    if (($_SESSION['user_role'] ?? '') === 'admin') return true;

    static $permissions = null;

    // 首次调用时从数据库加载权限（仅一次查询，后续调用复用缓存）
    if ($permissions === null) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE id = ?");
            $stmt->execute([$_SESSION['role_id'] ?? 0]);
            $role = $stmt->fetch();
            $permissions = $role ? json_decode($role['permissions'] ?: '[]', true) : [];
            // 同步更新 session 中的权限，供其他代码读取
            $_SESSION['permissions'] = $permissions;
            // error_log("[check_permission] DB加载权限: user_id={$_SESSION['user_id']} role={$_SESSION['user_role']} role_id={$_SESSION['role_id']} perms=" . json_encode($permissions));
        } catch (Exception $e) {
            // 数据库查询失败时，回退到 session 中的权限
            $permissions = $_SESSION['permissions'] ?? [];
            // error_log("[check_permission] DB查询失败回退session: " . $e->getMessage() . " session_perms=" . json_encode($permissions));
        }
    }

    $result = in_array($permission, $permissions);
    // 权限拒绝日志（生产环境可注释）
    // if (!$result) {
    //     error_log("[check_permission] 拒绝: perm={$permission} user={$_SESSION['user_name']} role={$_SESSION['user_role']} available=" . json_encode($permissions));
    // }
    return $result;
}

/**
 * 站点根的 web 路径（兼容部署在子目录的情况）
 *
 * 思路：SCRIPT_FILENAME 是脚本的物理路径，SCRIPT_NAME 是它的 web 路径，
 * 两者去掉「站点根物理路径」这一段后剩下的相对部分是同一个值，
 * 用它的长度反过来从 SCRIPT_NAME 上截掉，就得到站点根的 web 前缀。
 * 取不到时退回按当前脚本目录层级拼相对路径（../）。
 *
 * @return string 站点根，如 ''（域名根部署）或 '/jinxiaocun'；无前导斜杠的情况是 '../..'
 */
function site_base_url() {
    static $base = null;
    if ($base !== null) return $base;

    $rootReal   = str_replace('\\', '/', dirname(__DIR__));            // 站点根物理路径（includes 的上级）
    $scriptReal = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptWeb  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if ($scriptReal !== '' && $scriptWeb !== '' && strpos($scriptReal, $rootReal) === 0) {
        $rel = substr($scriptReal, strlen($rootReal));                 // 如 /modules/after_sales/list.php
        if ($rel !== '' && substr($scriptWeb, -strlen($rel)) === $rel) {
            $base = rtrim(substr($scriptWeb, 0, strlen($scriptWeb) - strlen($rel)), '/');
            return $base;
        }
    }

    // 退化方案：按当前脚本所在目录深度拼相对路径
    $dir = trim(str_replace('\\', '/', dirname($scriptWeb)), '/');
    $depth = ($dir === '' || $dir === '.') ? 0 : substr_count($dir, '/') + 1;
    $base = $depth > 0 ? rtrim(str_repeat('../', $depth), '/') : '.';
    return $base;
}

/**
 * 生成站内绝对（根相对）URL
 *
 * @param string $path 相对站点根的路径，如 'index.php'、'modules/sales/outstock_view.php'
 */
function site_url($path = '') {
    return site_base_url() . '/' . ltrim($path, '/');
}

/**
 * 权限检查中间件
 */
function require_permission($permission) {
    if (!isset($_SESSION['user_id'])) {
        redirect(site_url('index.php'));
    }
    if (!check_permission($permission)) {
        $home = htmlspecialchars(site_url('index.php'));
        die('<div style="text-align:center;margin-top:100px;"><h3>无权限访问</h3><p>您没有访问此页面的权限，请联系管理员。</p><a href="' . $home . '">返回首页</a></div>');
    }
}

/**
 * 权限码是否依赖商业授权（用于角色权限页把未授权的赋权项置灰）
 *
 * @param string $permission 权限码
 * @return string|null 需要的授权功能标识（如 tracking / crm）；null 表示不受授权限制
 */
function perm_requires_feature($permission) {
    if (strpos($permission, 'tracking_') === 0) return 'tracking';
    if (strpos($permission, 'crm_') === 0) return 'crm';
    return null;
}

/**
 * 该权限码对应的功能当前是否已获得授权
 * 未安装授权模块时视为已授权（开源/自部署场景不受影响）
 */
function perm_is_licensed($permission) {
    $feature = perm_requires_feature($permission);
    if ($feature === null) return true;
    if (!function_exists('license_has_feature')) return true;
    return license_has_feature($feature);
}

/**
 * 全部权限码定义（唯一来源）
 * 角色权限页、权限白名单校验都从这里取，避免多处各写一份导致不一致。
 */
function perm_code_list() {
    return [
        'dashboard' => '首页看板',
        'master_data' => '主数据模块',
        'product_view' => '商品查看', 'product_edit' => '商品编辑（新增/修改/删除/改图）', 'product_category' => '商品分类管理', 'warehouse_view' => '仓库查看',
        'customer_view' => '客户查看', 'customer_edit' => '客户编辑（新增/修改/删除）', 'supplier_view' => '供应商查看', 'supplier_edit' => '供应商编辑（新增/修改/删除）', 'warehouse_edit' => '仓库编辑（新增/修改/删除）',
        'purchase_order' => '采购订单', 'purchase_instock' => '采购入库', 'purchase_return' => '采购退货', 'purchase_reconcile' => '采购对账',
        'sales_quote' => '销售报价', 'sales_contract' => '销售合同', 'sales_order' => '销售订单', 'sales_outstock' => '销售出库', 'sales_return' => '销售退货', 'sales_reconcile' => '客户对账', 'print_template' => '打印模板',
        // 销售收款：只覆盖「出库单/订单详情里的登记收款、修改收款状态」，不含收款记录列表与作废
        'sales_receive' => '销售收款登记（登记收款/修改收款状态）',
        'inventory_view' => '库存查看', 'inventory_log' => '库存变动', 'transfer_manage' => '调拨管理', 'check_manage' => '盘点管理', 'loss_manage' => '报损报溢',
        'finance_arpay' => '应收应付', 'finance_receive' => '收款记录', 'finance_payment' => '付款记录', 'finance_aging' => '账龄分析',
        'report_sales' => '销售报表', 'report_purchase' => '采购报表', 'report_inventory' => '库存报表', 'report_performance' => '业绩报表', 'report_io' => '出入库汇总',
        'system_users' => '用户管理', 'system_roles' => '角色管理', 'system_logs' => '操作日志', 'system_settings' => '系统设置',
        'crm_customer_view' => 'CRM客户查看', 'crm_customer_edit' => 'CRM客户编辑', 'crm_pool_claim' => 'CRM公海认领',
        'crm_pool_manage' => 'CRM公海管理', 'crm_followup_view' => 'CRM跟进查看', 'crm_followup_add' => 'CRM跟进添加',
        'crm_followup_edit' => 'CRM跟进编辑',
        'crm_source_manage' => 'CRM来源管理', 'crm_report' => 'CRM报表', 'crm_setting' => 'CRM公海设置',
        // 售后追踪：查看与编辑分开，可单独赋权给某个角色；删除一律仅管理员
        'tracking_view' => '追踪码查看（列表/查询/二维码）', 'tracking_edit' => '追踪码编辑（生成/编辑/加流程/加售后）',
        'tracking_status' => '追踪状态管理（默认仅管理员）',
        'tracking_all' => '追踪码查看全部（不受客户归属限制，默认仅管理员）',
    ];
}

/**
 * 菜单项是否对当前用户可见
 * 1) 未获得商业授权的功能直接隐藏（与访问时 403 保持一致，避免点了才报错）
 * 2) 售后追踪按「查看/编辑」两级判定，编辑权限隐含查看
 */
function menu_perm_ok($perm) {
    if ($perm === '' || $perm === null) return true;
    if (!perm_is_licensed($perm)) return false;
    if ($perm === 'tracking_view') return check_tracking_perm(false);
    if ($perm === 'tracking_edit') return check_tracking_perm(true);
    return check_permission($perm);
}

/**
 * 售后追踪（追踪码）权限判定
 * 分两级：查看（tracking_view）与编辑（tracking_edit），编辑权限隐含查看权限。
 * 管理员直通；删除操作不在本函数内，调用处单独判断「仅管理员」。
 *
 * @param bool $needEdit 是否要求编辑级权限
 */
function check_tracking_perm($needEdit = false) {
    if (!isset($_SESSION['user_id'])) return false;
    if (($_SESSION['user_role'] ?? '') === 'admin') return true;
    if (check_permission('tracking_edit')) return true;
    return !$needEdit && check_permission('tracking_view');
}

/**
 * 售后追踪：当前用户是否可查看全部追踪码（不受客户归属限制）
 * 管理员直通；其余角色默认只能看「归属自己的客户」的追踪码，
 * 如需放开（如售后主管、客服），可在角色权限中勾选 tracking_all。
 */
function check_tracking_all() {
    if (!isset($_SESSION['user_id'])) return false;
    if (($_SESSION['user_role'] ?? '') === 'admin') return true;
    return check_permission('tracking_all');
}

/**
 * 追踪码的数据范围条件（按客户归属隔离）
 *
 * 追踪链路：tracking_codes.outstock_id → sales_outstocks.customer_id → customers.owner_id
 * 调用方需自行 JOIN（别名固定）：
 *   LEFT JOIN sales_outstocks o ON o.id=tc.outstock_id
 *   LEFT JOIN customers c ON c.id=o.customer_id
 *
 * @return array ['where' => ' AND ...', 'params' => array] 直接拼在 WHERE 1=1 之后
 */
function tracking_scope_where() {
    if (check_tracking_all()) return ['where' => '', 'params' => []];
    // 未分配归属的客户（owner_id 为 NULL）仅管理员可见，与 CRM/销售模块保持一致
    return ['where' => ' AND c.owner_id = ?', 'params' => [get_user_id()]];
}

/**
 * 判断当前用户是否有权访问某条追踪码（按客户归属）
 *
 * @param PDO $pdo
 * @param int $trackingId tracking_codes.id
 * @return bool 追踪码不存在时返回 false
 */
function tracking_owner_ok($pdo, $trackingId) {
    if (check_tracking_all()) return true;
    $stmt = $pdo->prepare("SELECT c.owner_id FROM tracking_codes tc
        LEFT JOIN sales_outstocks o ON o.id = tc.outstock_id
        LEFT JOIN customers c ON c.id = o.customer_id
        WHERE tc.id = ?");
    $stmt->execute([intval($trackingId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return intval($row['owner_id']) === intval(get_user_id());
}

/**
 * 判断当前用户是否有权访问某张出库单（按客户归属）
 *
 * @param PDO $pdo
 * @param int $outstockId sales_outstocks.id
 */
function outstock_owner_ok($pdo, $outstockId) {
    if (check_tracking_all()) return true;
    $stmt = $pdo->prepare("SELECT c.owner_id FROM sales_outstocks o
        LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.id = ?");
    $stmt->execute([intval($outstockId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return intval($row['owner_id']) === intval(get_user_id());
}

/**
 * 判断当前用户是否有权访问某条流程/售后记录（先反查所属追踪码，再按客户归属判断）
 *
 * @param PDO   $pdo
 * @param string $table 仅允许 tracking_processes / tracking_after_sales
 * @param int   $recordId
 */
function tracking_child_owner_ok($pdo, $table, $recordId) {
    $allowed = ['tracking_processes', 'tracking_after_sales'];
    if (!in_array($table, $allowed, true)) return false;
    $stmt = $pdo->prepare("SELECT tracking_id FROM `$table` WHERE id = ?");
    $stmt->execute([intval($recordId)]);
    $trackingId = $stmt->fetchColumn();
    if (!$trackingId) return false;
    return tracking_owner_ok($pdo, $trackingId);
}

/**
 * 售后追踪权限校验中间件：无权限直接终止页面
 *
 * @param bool $needEdit 是否要求编辑级权限
 */
function require_tracking_perm($needEdit = false) {
    if (!isset($_SESSION['user_id'])) {
        redirect(site_url('index.php'));
    }
    if (!check_tracking_perm($needEdit)) {
        $home = htmlspecialchars(site_url('index.php'));
        die('<div style="text-align:center;margin-top:100px;"><h3>无权限访问</h3><p>您没有访问售后追踪功能的权限，请联系管理员在「角色权限」中勾选「追踪码查看」或「追踪码编辑」。</p><a href="' . $home . '">返回首页</a></div>');
    }
}

/**
 * 获取下拉选项（参数化查询，兼容旧版字符串 $where）
 * 
 * @param string       $table     表名
 * @param string       $keyField  键字段
 * @param string       $valueField 值字段
 * @param array|string $conditions 条件数组 [['field','op',value], ...] 或旧版字符串 'field=val'
 * @return array
 */
function get_options($table, $keyField = 'id', $valueField = 'name', $conditions = []) {
    $pdo = getDB();
    
    $tableSafe = safe_identifier($table);
    $keySafe = safe_identifier($keyField);
    $valueSafe = safe_identifier($valueField);
    
    // 向后兼容：旧版字符串格式 'status=1' 转换为新版数组格式
    if (is_string($conditions) && !empty($conditions)) {
        $conditions = parse_simple_where($conditions);
    }
    
    $whereClauses = [];
    $params = [];
    
    if (!empty($conditions) && is_array($conditions)) {
        foreach ($conditions as $cond) {
            if (count($cond) < 3) continue;
            try {
                $fieldSafe = safe_identifier($cond[0]);
                $op = strtoupper(trim($cond[1]));
                // 白名单校验操作符
                $allowedOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'IN', 'NOT IN'];
                if (!in_array($op, $allowedOps)) continue;
                $whereClauses[] = "$fieldSafe $op ?";
                $params[] = $cond[2];
            } catch (InvalidArgumentException $e) {
                error_log("get_options invalid field: " . $e->getMessage());
            }
        }
    }
    
    $whereStr = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
    $sql = "SELECT $keySafe, $valueSafe FROM $tableSafe$whereStr ORDER BY $valueSafe";
    
    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * 将简单的 WHERE 字符串解析为参数化条件数组
 * 仅支持 field=value 和 field1=val1 AND field2=val2 格式
 */
function parse_simple_where($whereStr) {
    $conditions = [];
    $parts = preg_split('/\s+AND\s+/i', trim($whereStr));
    foreach ($parts as $part) {
        $part = trim($part);
        // 匹配 field=value 或 field='value' 格式
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/', $part, $m)) {
            $value = trim($m[2], "'\"");
            // 尝试转为数字
            if (is_numeric($value)) {
                $value = strpos($value, '.') !== false ? floatval($value) : intval($value);
            }
            $conditions[] = [$m[1], '=', $value];
        }
    }
    return $conditions;
}

/**
 * 格式化金额
 */
function format_money($amount) {
    return number_format($amount, 2, '.', ',');
}

/**
 * 格式化日期
 */
function format_date($date, $format = 'Y-m-d H:i:s') {
    if (!$date) return '';
    return date($format, strtotime($date));
}

/**
 * 获取库存数量
 */
function get_stock($productId, $warehouseId = 0) {
    $pdo = getDB();
    $sql = "SELECT SUM(quantity) as total FROM inventory WHERE product_id = ?";
    $params = [$productId];
    if ($warehouseId > 0) {
        $sql .= " AND warehouse_id = ?";
        $params[] = $warehouseId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return floatval($stmt->fetchColumn() ?: 0);
}

/**
 * 更新库存
 */
/**
 * 读取系统设置（带缓存；表不存在或键缺失时返回默认值）
 */
function get_setting($key, $default = '') {
    static $cache = [];
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = ($v === false) ? $default : $v;
    } catch (Exception $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * 移动加权平均成本：采购入库时重算库存单价
 * 新成本 = (入库前库存 × 原成本 + 本次入库量 × 本次单价) / (入库前库存 + 本次入库量)
 * 入库前库存 <= 0 或原成本为 0 时，直接取本次单价
 *
 * @return float|false
 */
function update_avg_cost($productId, $warehouseId, $quantity, $price) {
    $pdo = getDB();
    $productId = intval($productId);
    $warehouseId = intval($warehouseId);
    $quantity = floatval($quantity);
    $price = floatval($price);
    if ($productId <= 0 || $warehouseId <= 0 || $quantity <= 0 || $price < 0) return false;
    try {
        $st = $pdo->prepare("SELECT quantity, COALESCE(avg_cost,0) as avg_cost FROM inventory WHERE product_id=? AND warehouse_id=?");
        $st->execute([$productId, $warehouseId]);
        $row = $st->fetch();
        $curQty = floatval($row['quantity'] ?? 0);
        $curCost = floatval($row['avg_cost'] ?? 0);
        $oldQty = max(0, $curQty - $quantity); // 本次入库前的库存
        if ($oldQty > 0.000001 && $curCost > 0) {
            $newCost = ($oldQty * $curCost + $quantity * $price) / ($oldQty + $quantity);
        } else {
            $newCost = $price;
        }
        $pdo->prepare("UPDATE inventory SET avg_cost=? WHERE product_id=? AND warehouse_id=?")
            ->execute([$newCost, $productId, $warehouseId]);
        return $newCost;
    } catch (Exception $e) {
        error_log('update_avg_cost error: ' . $e->getMessage());
        return false; // 成本计算失败不应阻断入库业务
    }
}

/**
 * 库存变动唯一入口：所有单据都必须通过本函数加减库存
 * - 参数校验：商品/仓库/数量非法时记录日志并返回 false（不静默写入脏数据）
 * - 负库存校验：系统开关 allow_negative_stock 关闭时，扣减后库存为负直接抛异常（调用方在事务中会回滚）
 *
 * @return bool
 * @throws Exception 库存不足或库存记录缺失
 */
function update_inventory($productId, $warehouseId, $quantity, $type, $billNo, $billType, $userId, $remark = '') {
    $pdo = getDB();
    $productId = intval($productId);
    $warehouseId = intval($warehouseId);
    $quantity = floatval($quantity);

    if ($productId <= 0 || $warehouseId <= 0 || abs($quantity) < 0.000001) {
        error_log("update_inventory 参数非法: product=$productId warehouse=$warehouseId qty=$quantity type=$type bill=$billNo");
        return false;
    }

    // 扣减前先锁定当前库存并校验，避免并发下扣成负数
    $st = $pdo->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE product_id=? AND warehouse_id=? FOR UPDATE");
    $st->execute([$productId, $warehouseId]);
    $current = floatval($st->fetchColumn());

    if ($quantity < 0 && $current + $quantity < -0.000001 && get_setting('allow_negative_stock', '0') !== '1') {
        $pname = $pdo->prepare("SELECT name FROM products WHERE id=?");
        $pname->execute([$productId]);
        $pname = $pname->fetchColumn() ?: ('#' . $productId);
        $wname = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $wname->execute([$warehouseId]);
        $wname = $wname->fetchColumn() ?: ('#' . $warehouseId);
        throw new Exception("库存不足：{$pname}（{$wname}）当前库存 {$current}，本次需扣减 " . abs($quantity) . "。如业务允许负库存，请到系统设置开启。");
    }

    // 更新库存表
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, created_at) 
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?, updated_at = ?");
    $stmt->execute([$productId, $warehouseId, $quantity, $now, $quantity, $now]);

    // 记录变动
    $stmt = $pdo->prepare("INSERT INTO inventory_logs (product_id, warehouse_id, change_quantity, current_quantity, type, bill_no, bill_type, user_id, remark, created_at) 
        SELECT ?, ?, ?, quantity, ?, ?, ?, ?, ?, ? FROM inventory WHERE product_id = ? AND warehouse_id = ?");
    $stmt->execute([$productId, $warehouseId, $quantity, $type, $billNo, $billType, $userId, $remark, $now, $productId, $warehouseId]);

    return true;
}

/**
 * 导出CSV
 */
function export_csv($headers, $data, $filename = 'export.csv') {
    // 丢弃页面已渲染的 HTML，保证下载的是纯 CSV
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');

    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * 获取文件 MIME 类型（兼容未安装 fileinfo 扩展的环境）
 * 优先级：mime_content_type() → finfo_open() → 扩展名/文件头兜底
 * 无法判定统一返回 application/octet-stream，调用方按此值放宽校验即可
 */
function detect_mime_type($path) {
    if (!is_file($path)) { return 'application/octet-stream'; }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) { return $mime; }
    }
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if ($mime) { return $mime; }
        }
    }

    // 兜底1：按扩展名
    $extMap = [
        'sql' => 'text/plain', 'txt' => 'text/plain', 'csv' => 'text/plain',
        'json' => 'application/json', 'xml' => 'text/xml',
        'xlsx' => 'application/zip', 'docx' => 'application/zip', 'pptx' => 'application/zip',
        'zip' => 'application/zip',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($extMap[$ext])) { return $extMap[$ext]; }

    // 兜底2：按文件头
    $head = @file_get_contents($path, false, null, 0, 8);
    if ($head !== false && substr($head, 0, 2) === 'PK') { return 'application/zip'; }

    return 'application/octet-stream';
}

/**
 * 判断 SQL 恢复时可忽略的错误（表/字段已存在、主键/唯一键冲突、字段不匹配等）
 * 老库恢复到新版结构上时这类错误属正常，跳过即可，不应中断整个恢复
 */
function is_ignorable_sql_error($e) {
    $msg = strtolower($e->getMessage());
    $needles = [
        'already exists',      // 表/索引已存在 1050/1061
        'duplicate entry',     // 主键/唯一键冲突 1062
        'duplicate key',
        'unknown column',      // 老库字段在新结构中不存在
        'unknown table',
        'check that column',
        'data truncated',      // 1265：老库值超出新结构 ENUM/长度，非严格模式下只是警告
    ];
    foreach ($needles as $n) {
        if (strpos($msg, $n) !== false) return true;
    }
    // PDOException::getCode() 返回 SQLSTATE，完整性约束类冲突一律按可跳过处理
    $sqlState = (string)($e instanceof PDOException ? $e->getCode() : '');
    if (in_array($sqlState, ['23000', '42000', '42S01', '42S21'], true)) return true;
    // errorInfo[1] 为 MySQL 错误码
    $errno = 0;
    if ($e instanceof PDOException && isset($e->errorInfo[1])) {
        $errno = intval($e->errorInfo[1]);
    }
    return in_array($errno, [1050, 1060, 1061, 1062, 1054, 1146], true);
}

/**
 * 清空当前数据库所有数据表（恢复前"先清库"选项使用）
 * @return int 删除的表数量
 */
function drop_all_tables($pdo) {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'");
    $st->execute([$db]);
    $tables = $st->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $count = 0;
    foreach ($tables as $t) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
            $count++;
        } catch (Exception $e) {}
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return $count;
}

/**
 * 执行 SQL 转储文件内容（备份恢复用）
 * @param PDO    $pdo
 * @param string $sql      整个 .sql 内容
 * @param string $conflict skip=已存在则跳过(INSERT IGNORE) / overwrite=覆盖(REPLACE INTO) / strict=原样执行
 * @return array ['executed'=>执行成功条数, 'skipped'=>跳过条数]
 */
function apply_sql_dump($pdo, $sql, $conflict = 'skip') {
    // 导入期间临时关闭严格模式：老库里超出 ENUM/长度的数据只会产生警告(1265)并被截断，
    // 严格模式下会直接抛 PDOException 中断整个恢复（如 print_templates.type、operation_logs.action）
    $oldSqlMode = null;
    try {
        $oldSqlMode = $pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    } catch (Exception $e) {}

    $result = apply_sql_dump_inner($pdo, $sql, $conflict);

    if ($oldSqlMode !== null) {
        try { $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($oldSqlMode)); } catch (Exception $e) {}
    }
    return $result;
}

/**
 * apply_sql_dump 的实际执行体，见 apply_sql_dump()
 */
function apply_sql_dump_inner($pdo, $sql, $conflict = 'skip') {
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    // 行尾分号后可能带空格/制表符，统一规整，保证能正确切分
    $sql = preg_replace('/;[ \t]*\n/', ";\n", $sql);
    $queries = explode(";\n", $sql);
    $executed = 0;
    $skipped  = 0;

    foreach ($queries as $q) {
        $q = trim($q);
        if ($q === '') continue;

        // 去掉开头/内部的注释行，避免把整段注释当成语句执行
        if (preg_match('/^(--|#|\/\*)/', $q)) {
            $lines = array_filter(array_map('trim', explode("\n", $q)), function ($l) {
                return $l !== '' && !preg_match('/^(--|#|\/\*)/', $l);
            });
            if (empty($lines)) continue;
            $q = implode("\n", $lines);
        }

        // INSERT [LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE] INTO 统一改写
        if ($conflict === 'skip') {
            $q = preg_replace('/^INSERT\s+(LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)?INTO\s/i',
                              'INSERT IGNORE INTO ', $q);
        } elseif ($conflict === 'overwrite') {
            $q = preg_replace('/^INSERT\s+(LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)?INTO\s/i',
                              'REPLACE INTO ', $q);
        }

        try {
            $pdo->exec($q);
            $executed++;
        } catch (Exception $e) {
            if (is_ignorable_sql_error($e)) { $skipped++; continue; }
            throw $e;
        }
    }

    return ['executed' => $executed, 'skipped' => $skipped];
}

/**
 * 上传文件
 */
function upload_file($fieldName, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], $maxSize = null) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '文件上传失败'];
    }

    $file = $_FILES[$fieldName];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => '不支持的文件类型'];
    }

    // 验证文件 MIME 类型（兼容无 fileinfo 扩展的服务器）
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        // Office 老格式（doc/xls/ppt）是 OLE 复合文档，finfo 常返回
        // application/x-ole-storage 或 application/CDFV2；
        // Office 新格式（docx/xlsx/pptx）本质是 zip 包，常返回 application/zip。
        // 这些变体必须一并允许，否则会误杀正常文件。
        $oleMimes = ['application/x-ole-storage', 'application/CDFV2', 'application/cdfv2', 'application/octet-stream'];
        $zipMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

        $mimeMap = [
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png', 'image/x-png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp'  => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'],
            'pdf'  => ['application/pdf', 'application/x-pdf'],
            'doc'  => array_merge(['application/msword'], $oleMimes),
            'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zipMimes),
            'xls'  => array_merge(['application/vnd.ms-excel'], $oleMimes),
            'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zipMimes),
            'ppt'  => array_merge(['application/vnd.ms-powerpoint'], $oleMimes),
            'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zipMimes),
            'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/x-compressed', 'application/octet-stream'],
            'rar'  => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar', 'application/octet-stream'],
        ];

        $allowedMimes = $mimeMap[$ext] ?? [];
        if (!empty($allowedMimes) && !in_array($mimeType, $allowedMimes)) {
            return ['success' => false, 'message' => '文件类型与扩展名不匹配（检测到：' . $mimeType . '）'];
        }
    }

    $maxSize = ($maxSize === null) ? 10 * 1024 * 1024 : intval($maxSize); // 默认 10MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => '文件大小超过限制（最大 ' . round($maxSize / 1024 / 1024) . 'MB）'];
    }

    // 使用纯随机文件名，不暴露时间信息
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = UPLOAD_DIR . $newName;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return [
            'success' => true,
            'filename' => $newName,
            'original_name' => $file['name'],
            'path' => 'uploads/' . $newName
        ];
    }
    return ['success' => false, 'message' => '文件保存失败（上传目录不可写？）'];
}

/**
 * 智能生成打印模板的商品明细行HTML
 * 根据模板<thead>中的列名自动匹配item数据字段，生成对应的<tr>行
 * 支持的列名：序号, SKU, 商品名称, 规格, 单位, 数量, 单价, 金额, 备注
 */
function build_items_html($items, $templateHtml = null) {
    if (empty($items)) return '<tr><td colspan="10">暂无明细数据</td></tr>';

    // 列名 → item数组字段 映射
    $fieldMap = [
        '序号'   => '__index__',
        'SKU'    => 'sku',
        '商品名称' => 'product_name',
        '规格'   => 'spec',
        '单位'   => 'unit_name',
        '数量'   => 'quantity',
        '单价'   => 'price',
        '金额'   => 'amount',
        '备注'   => '__remark__',
    ];

    // 如果提供了模板HTML，从中提取<thead>列名
    $columnNames = null;
    if ($templateHtml && preg_match('/<thead>(.*?)<\/thead>/s', $templateHtml, $m)) {
        preg_match_all('/<th>(.*?)<\/th>/', $m[1], $thMatches);
        $columnNames = array_map('trim', $thMatches[1]);
    }

    // 如果无法从模板提取列名，使用默认7列（序号,商品名称,规格,单位,数量,单价,金额,备注）
    if (empty($columnNames)) {
        $columnNames = ['序号', '商品名称', '规格', '单位', '数量', '单价', '金额', '备注'];
    }

    $rows = '';
    $idx = 1;
    foreach ($items as $item) {
        $row = '<tr>';
        foreach ($columnNames as $colName) {
            $field = $fieldMap[$colName] ?? null;
            if ($field === '__index__') {
                $row .= '<td>' . $idx . '</td>';
            } elseif ($field === '__remark__') {
                $row .= '<td>' . htmlspecialchars($item['remark'] ?? '') . '</td>';
            } elseif ($field && isset($item[$field])) {
                $val = $item[$field];
                if (in_array($field, ['price', 'amount'])) {
                    $val = '¥' . format_money($val);
                }
                $row .= '<td>' . htmlspecialchars((string)($val ?: '')) . '</td>';
            } else {
                $row .= '<td></td>';
            }
        }
        $row .= '</tr>';
        $rows .= $row;
        $idx++;
    }
    return $rows;
}

/**
 * 生成售后追踪二维码
 * 使用 Google Chart API 生成并缓存到本地
 */
function generate_tracking_qrcode($trackingNo) {
    $url = (SITE_URL ?: '') . '/track.php?code=' . urlencode($trackingNo);
    $filename = 'qrcode_' . $trackingNo . '.png';
    $savePath = __DIR__ . '/../uploads/qrcodes/' . $filename;

    // 如果已存在则直接返回
    if (file_exists($savePath)) {
        return 'uploads/qrcodes/' . $filename;
    }

    // 确保目录存在
    $dir = dirname($savePath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ],
    ]);

    // 优先使用 api.qrserver.com（更稳定）
    $apis = [
        'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($url),
        'https://quickchart.io/qr?text=' . urlencode($url) . '&size=300',
    ];

    foreach ($apis as $apiUrl) {
        $imageData = @file_get_contents($apiUrl, false, $context);
        if ($imageData && strlen($imageData) > 100 && file_put_contents($savePath, $imageData)) {
            return 'uploads/qrcodes/' . $filename;
        }
    }

    return false;
}

/**
 * 获取菜单
 */
function get_menu() {
    $menus = [
        // 1. 首页看板
        ['name' => '首页看板', 'url' => 'dashboard.php', 'icon' => 'gauge', 'perm' => 'dashboard'],
        // 2. 主数据（含数据导入）
        ['name' => '主数据', 'icon' => 'database', 'perm' => 'master_data', 'children' => [
            ['name' => '商品管理', 'url' => 'modules/product/list.php', 'icon' => 'box', 'perm' => 'product_view'],
            ['name' => '商品分类', 'url' => 'modules/product/category.php', 'icon' => 'tags', 'perm' => 'product_category'],
            ['name' => '商品单位', 'url' => 'modules/product/unit.php', 'icon' => 'weight-scale', 'perm' => 'product_category'],
            ['name' => '仓库管理', 'url' => 'modules/product/warehouse.php', 'icon' => 'warehouse', 'perm' => 'warehouse_view'],
            ['name' => '客户管理', 'url' => 'modules/product/customer.php', 'icon' => 'users', 'perm' => 'customer_view'],
            ['name' => '供应商管理', 'url' => 'modules/product/supplier.php', 'icon' => 'truck', 'perm' => 'supplier_view'],
            ['name' => '数据导入', 'url' => 'modules/product/import.php', 'icon' => 'file-import', 'perm' => 'master_data'],
        ]],
        // 3. 采购管理
        ['name' => '采购管理', 'icon' => 'cart-shopping', 'perm' => 'purchase', 'children' => [
            ['name' => '采购订单', 'url' => 'modules/purchase/order.php', 'icon' => 'file-lines', 'perm' => 'purchase_order'],
            ['name' => '采购入库', 'url' => 'modules/purchase/instock.php', 'icon' => 'right-to-bracket', 'perm' => 'purchase_instock'],
            ['name' => '采购退货', 'url' => 'modules/purchase/return.php', 'icon' => 'right-from-bracket', 'perm' => 'purchase_return'],
            ['name' => '采购对账', 'url' => 'modules/purchase/reconciliation.php', 'icon' => 'square-check', 'perm' => 'purchase_reconcile'],
        ]],
        // 4. 销售管理
        ['name' => '销售管理', 'icon' => 'bag-shopping', 'perm' => 'sales', 'children' => [
            ['name' => '销售报价', 'url' => 'modules/sales/quote.php', 'icon' => 'file-invoice-dollar', 'perm' => 'sales_quote'],
            ['name' => '销售合同', 'url' => 'modules/sales/contract.php', 'icon' => 'file-signature', 'perm' => 'sales_contract'],
            ['name' => '销售订单', 'url' => 'modules/sales/order.php', 'icon' => 'file-lines', 'perm' => 'sales_order'],
            ['name' => '销售出库', 'url' => 'modules/sales/outstock.php', 'icon' => 'right-from-bracket', 'perm' => 'sales_outstock'],
            ['name' => '销售退货', 'url' => 'modules/sales/return.php', 'icon' => 'right-to-bracket', 'perm' => 'sales_return'],
            ['name' => '客户对账', 'url' => 'modules/sales/reconciliation.php', 'icon' => 'square-check', 'perm' => 'sales_reconcile'],
            ['name' => '打印模板', 'url' => 'modules/sales/print_tpl.php', 'icon' => 'print', 'perm' => 'print_template'],
        ]],
        // 5. 客户管理CRM（紧接销售管理之后）
        ['name' => '客户管理CRM', 'icon' => 'id-card', 'perm' => 'crm_customer_view', 'children' => [
            ['name' => '客户资料', 'url' => 'modules/crm/customers.php', 'icon' => 'users', 'perm' => 'crm_customer_view'],
            ['name' => '客户公海', 'url' => 'modules/crm/pool.php', 'icon' => 'water', 'perm' => 'crm_pool_claim'],
            ['name' => '跟进记录', 'url' => 'modules/crm/followups.php', 'icon' => 'comments', 'perm' => 'crm_followup_view'],
            ['name' => '客户报表', 'url' => 'modules/crm/report.php', 'icon' => 'chart-simple', 'perm' => 'crm_report'],
            ['name' => '客户来源', 'url' => 'modules/crm/sources.php', 'icon' => 'filter', 'perm' => 'crm_source_manage'],
            ['name' => '公海设置', 'url' => 'modules/crm/settings.php', 'icon' => 'gear', 'perm' => 'crm_setting'],
        ]],
        // 6. 库存管理
        ['name' => '库存管理', 'icon' => 'boxes-stacked', 'perm' => 'inventory', 'children' => [
            ['name' => '实时库存', 'url' => 'modules/inventory/stock.php', 'icon' => 'eye', 'perm' => 'inventory_view'],
            ['name' => '库存变动', 'url' => 'modules/inventory/logs.php', 'icon' => 'clock-rotate-left', 'perm' => 'inventory_log'],
            ['name' => '调拨管理', 'url' => 'modules/inventory/transfer.php', 'icon' => 'arrows-rotate', 'perm' => 'transfer_manage'],
            ['name' => '盘点管理', 'url' => 'modules/inventory/check.php', 'icon' => 'clipboard-list', 'perm' => 'check_manage'],
            ['name' => '报损报溢', 'url' => 'modules/inventory/loss.php', 'icon' => 'triangle-exclamation', 'perm' => 'loss_manage'],
            ['name' => '库存校验', 'url' => 'modules/inventory/repair.php', 'icon' => 'stethoscope', 'perm' => 'check_manage'],
        ]],
        // 7. 售后追踪（紧接库存管理之后）
        ['name' => '售后追踪', 'icon' => 'qrcode', 'perm' => 'tracking_view', 'children' => [
            ['name' => '生成追踪码', 'url' => 'modules/after_sales/create.php', 'icon' => 'plus-circle', 'perm' => 'tracking_edit'],
            ['name' => '追踪码管理', 'url' => 'modules/after_sales/list.php', 'icon' => 'list', 'perm' => 'tracking_view'],
            ['name' => '追踪码查询', 'url' => 'modules/after_sales/query.php', 'icon' => 'search', 'perm' => 'tracking_view'],
            ['name' => '状态管理', 'url' => 'modules/after_sales/statuses.php', 'icon' => 'list-check', 'perm' => 'tracking_status'],
        ]],
        // 8. 财务管理
        ['name' => '财务管理', 'icon' => 'dollar-sign', 'perm' => 'finance', 'children' => [
            ['name' => '应收应付', 'url' => 'modules/finance/arpay.php', 'icon' => 'book-open', 'perm' => 'finance_arpay'],
            ['name' => '收款记录', 'url' => 'modules/finance/receive.php', 'icon' => 'arrow-trend-up', 'perm' => 'finance_receive'],
            ['name' => '付款记录', 'url' => 'modules/finance/payment.php', 'icon' => 'arrow-trend-down', 'perm' => 'finance_payment'],
            ['name' => '账龄分析', 'url' => 'modules/finance/aging.php', 'icon' => 'clock', 'perm' => 'finance_aging'],
        ]],
        // 9. 报表分析
        ['name' => '报表分析', 'icon' => 'chart-simple', 'perm' => 'report', 'children' => [
            ['name' => '销售排行', 'url' => 'modules/report/sales_rank.php', 'icon' => 'arrow-trend-up', 'perm' => 'report_sales'],
            ['name' => '采购统计', 'url' => 'modules/report/purchase_stats.php', 'icon' => 'chart-bar', 'perm' => 'report_purchase'],
            ['name' => '库存报表', 'url' => 'modules/report/inventory_report.php', 'icon' => 'chart-pie', 'perm' => 'report_inventory'],
            ['name' => '业绩报表', 'url' => 'modules/report/performance.php', 'icon' => 'user-check', 'perm' => 'report_performance'],
            ['name' => '出入库汇总', 'url' => 'modules/report/io_summary.php', 'icon' => 'chart-line', 'perm' => 'report_io'],
        ]],
        // 10. 系统管理
        ['name' => '系统管理', 'icon' => 'gear', 'perm' => 'system', 'children' => [
            ['name' => '用户管理', 'url' => 'modules/system/users.php', 'icon' => 'users', 'perm' => 'system_users'],
            ['name' => '角色权限', 'url' => 'modules/system/roles.php', 'icon' => 'shield-halved', 'perm' => 'system_roles'],
            ['name' => '操作日志', 'url' => 'modules/system/logs.php', 'icon' => 'file-lines', 'perm' => 'system_logs'],
            ['name' => '系统设置', 'url' => 'modules/system/settings.php', 'icon' => 'sliders', 'perm' => 'system_settings'],
            ['name' => '授权管理', 'url' => 'modules/system/license.php', 'icon' => 'key', 'perm' => 'system_settings'],
            ['name' => '系统初始化', 'url' => 'modules/system/init.php', 'icon' => 'rotate-left', 'perm' => 'system'],
            ['name' => '数据库备份', 'url' => 'modules/system/backup.php', 'icon' => 'database', 'perm' => 'system'],
        ]],
    ];
    return $menus;
}

/**
 * 从菜单数据构建页面路径→名称映射表（带缓存）
 * 自动覆盖 get_menu() 中的所有页面，无需手动维护
 */
function get_page_name_map() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $map = [];
    $menus = get_menu();
    foreach ($menus as $menu) {
        if (isset($menu['children'])) {
            foreach ($menu['children'] as $child) {
                if (!empty($child['url'])) {
                    $map[$child['url']] = $child['name'];
                }
            }
        } elseif (!empty($menu['url'])) {
            $map[$menu['url']] = $menu['name'];
        }
    }

    // 补充：不在菜单中的附属页面（详情、编辑等）。
    // 名称取自各页面自身的 <h1 class="page-title">，保持一致。
    // 注：原 'modules/product/form.php' 映射已移除 —— 该文件并不存在，
    //     商品编辑走 AJAX 弹窗 product/detail.php（返回 JSON，不渲染面包屑）。
    $secondary = [
        'modules/purchase/instock_view.php'  => '采购入库详情',
        'modules/purchase/order_form.php'    => '采购订单编辑',
        'modules/purchase/order_view.php'    => '采购订单详情',
        'modules/purchase/return_view.php'   => '采购退货单详情',
        'modules/sales/outstock_view.php'    => '销售出库详情',
        'modules/sales/order_form.php'       => '销售订单编辑',
        'modules/sales/order_view.php'       => '销售订单详情',
        'modules/sales/quote_form.php'       => '销售报价编辑',
        'modules/sales/quote_view.php'       => '销售报价详情',
        // 销售合同：列表页在菜单中（会被菜单映射覆盖，值一致），
        // 表单/详情/付款方式不在菜单里，必须在此补，否则面包屑会退回显示文件名
        'modules/sales/contract.php'         => '销售合同',
        'modules/sales/contract_form.php'    => '销售合同编辑',
        'modules/sales/contract_view.php'    => '销售合同详情',
        'modules/sales/payment_type.php'     => '合同付款方式',
        'modules/sales/return_view.php'      => '销售退货单详情',
        'modules/inventory/check_edit.php'   => '盘点编辑',
        'modules/inventory/check_view.php'   => '盘点详情',
        'modules/inventory/loss_view.php'    => '报损报溢详情',
        'modules/inventory/transfer_view.php' => '调拨单详情',
        'modules/product/customer_detail.php' => '客户详情',
        'modules/crm/customer_detail.php'    => 'CRM客户详情',
        'modules/product/supplier_detail.php' => '供应商详情',
        'modules/product/catalog_print.php'  => '产品目录打印',
        'modules/product/employee.php'       => '员工管理', // 已弃用，进入后立即跳转用户管理
    ];

    // 必须把合并结果写回 $cache：只 return 不回写的话，第二次调用起会直接命中
    // 仅含菜单项的 $cache，整个 $secondary 丢失，面包屑又会退回显示文件名
    return $cache = array_merge($secondary, $map); // $map 优先级更高
}

/**
 * 根据脚本路径获取页面名称（用于面包屑和标题）
 */
function get_page_name($scriptName) {
    $path = ltrim(str_replace('\\', '/', $scriptName), '/');
    $map  = get_page_name_map();
    return $map[$path] ?? basename($path);
}

// ============================================
// 授权许可自动加载 & 受保护功能路由检查
// 此检查在 functions.php 中执行，functions.php 被所有页面入口加载，
// 因此即使模块文件被替换/更新，授权检查依然生效。
//
// 安全设计：
// - 检查逻辑内联在本文件中，删除 license.php 不会绕过检查
// - license.php 只提供验证函数，不存在 = 视为无授权 → 拒绝访问
// - 开发时需将 src/includes/license.php（开发版）复制到 includes/ 目录
// ============================================

// 判断当前请求是否为受保护路径
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$isTrackingPath = (
    strpos($scriptPath, '/after_sales/') !== false &&
    strpos($scriptPath, '/track.php') === false
);
$isCrmPath = (strpos($scriptPath, '/crm/') !== false);

if ($isTrackingPath || $isCrmPath) {
    $_license_feature_ok = false;

    $licenseFile = __DIR__ . '/license.php';
    if (file_exists($licenseFile)) {
        require_once $licenseFile;
    }

    if (function_exists('license_has_feature')) {
        if ($isTrackingPath) {
            $_license_feature_ok = license_has_feature('tracking');
        } elseif ($isCrmPath) {
            $_license_feature_ok = license_has_feature('crm');
        }
    }

    if (!$_license_feature_ok) {
        $modName = $isCrmPath ? '客户管理CRM' : '售后溯源追踪';
        $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['success'=>false,'message'=>'该功能需要购买正式授权，请在系统设置中激活'], JSON_UNESCAPED_UNICODE));
        }
        die('<div style="text-align:center;margin-top:80px;font-family:sans-serif;"><h2 style="color:#e74c3c;">⚠ 功能未授权</h2><p>'.$modName.'需要购买正式授权，请在系统设置中激活。</p><p style="margin-top:20px;color:#888;">联系客服获取注册码</p></div>');
    }
}

/**
 * 脱敏：姓名（保留首字，其余补星）
 */
function mask_name($str) {
    $str = trim((string)$str);
    if ($str === '') return '';
    $len = mb_strlen($str, 'UTF-8');
    if ($len <= 1) return $str . '*';
    return mb_substr($str, 0, 1, 'UTF-8') . str_repeat('*', $len - 1);
}

/**
 * 脱敏：电话（保留前3后4，中间补4个星）
 */
function mask_phone($str) {
    $str = trim((string)$str);
    if ($str === '') return '';
    $digits = preg_replace('/\D/', '', $str);
    if (strlen($digits) >= 7) {
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }
    return str_repeat('*', strlen($str));
}

/**
 * 脱敏：地址（保留前6字 + ****）
 */
function mask_address($str) {
    $str = trim((string)$str);
    if ($str === '') return '';
    if (mb_strlen($str, 'UTF-8') <= 6) return '****';
    return mb_substr($str, 0, 6, 'UTF-8') . '****';
}

/**
 * 追踪码展示数据脱敏（扫码页专用）
 * 白名单精确匹配，仅脱敏客户/接货人的姓名、电话、地址；
 * 业务经理、售后人员及自定义字段不在白名单内，保持明码。
 */
function mask_tracking_public_data($row) {
    $rules = [
        'customer_name'    => 'name',
        'customer_phone'   => 'phone',
        'receiver_name'    => 'name',
        'receiver_phone'   => 'phone',
        'customer_address' => 'address',
    ];
    if (!empty($row['tracking_data']) && is_array($row['tracking_data'])) {
        foreach ($row['tracking_data'] as $k => $v) {
            if (!isset($rules[$k])) continue;
            $type = $rules[$k];
            // 结构一：create.php 保存的 {label:..., value:...} 对象
            if (is_array($v) && array_key_exists('value', $v)) {
                if (!is_string($v['value']) || $v['value'] === '') continue;
                $val = $v['value'];
                $row['tracking_data'][$k]['value'] = $type === 'name' ? mask_name($val)
                    : ($type === 'phone' ? mask_phone($val) : mask_address($val));
            }
            // 结构二：纯字符串（兼容）
            elseif (is_string($v) && $v !== '') {
                $row['tracking_data'][$k] = $type === 'name' ? mask_name($v)
                    : ($type === 'phone' ? mask_phone($v) : mask_address($v));
            }
        }
    }
    return $row;
}

/**
 * 客户成交判定 SQL 片段
 * 成交定义（满足其一即算已成交）：
 *   1) 存在非草稿、未取消的销售订单
 *   2) 存在 result='已成交' 的跟进记录
 * 注意：片段内部含 OR，用于 WHERE 时调用方需自行加括号包裹
 */
function sql_customer_is_deal($alias = 'c') {
    $oc = "status NOT IN('draft','cancelled')";
    return "EXISTS(SELECT 1 FROM sales_orders WHERE customer_id={$alias}.id AND $oc) "
         . "OR EXISTS(SELECT 1 FROM customer_followups WHERE customer_id={$alias}.id AND result='已成交')";
}

/**
 * 客户成交统计列（供列表/详情/导出复用）
 * 返回4列：成交单数、成交总额、跟进成交次数、最近成交日期
 * 最近成交日期取「订单日期」与「跟进成交日期」的较大者，无成交时为 '0000-00-00'
 */
function sql_customer_deal_stats($alias = 'c') {
    $oc = "FROM sales_orders WHERE customer_id={$alias}.id AND status NOT IN('draft','cancelled')";
    $fc = "FROM customer_followups WHERE customer_id={$alias}.id AND result='已成交'";
    return "(SELECT COUNT(*) $oc) AS deal_order_count, "
         . "(SELECT COALESCE(SUM(total_amount),0) $oc) AS deal_amount, "
         . "(SELECT COUNT(*) $fc) AS deal_follow_count, "
         . "GREATEST("
         . "IFNULL((SELECT MAX(DATE(order_date)) $oc),'0000-00-00'),"
         . "IFNULL((SELECT MAX(DATE(created_at)) $fc),'0000-00-00')"
         . ") AS last_deal_date";
}

/**
 * 判断一行客户数据是否成交（需含 deal_order_count / deal_follow_count 字段）
 */
function customer_is_deal($row) {
    return (intval($row['deal_order_count'] ?? 0) > 0 || intval($row['deal_follow_count'] ?? 0) > 0);
}

/**
 * 成交来源明细文案（徽章 hover 提示）
 * 例：销售订单 2 单 / ¥8,800.00 · 跟进已成交
 */
function customer_deal_tip($row) {
    $tips = [];
    if (intval($row['deal_order_count'] ?? 0) > 0) {
        $tips[] = '销售订单 ' . intval($row['deal_order_count']) . ' 单 / ¥' . format_money($row['deal_amount'] ?? 0);
    }
    if (intval($row['deal_follow_count'] ?? 0) > 0) {
        $tips[] = '跟进已成交';
    }
    return implode(' · ', $tips);
}

/**
 * 同步采购订单状态（依据已确认入库数量自动流转）
 * 判定口径（按商品行数量比较）：
 *   全部行 累计入库 >= 订单量  -> received（已入库；超交同样视为已入库）
 *   任一行 0 < 累计入库 < 订单量 -> partial（部分入库）
 *   全部行 累计入库 = 0        -> confirmed（回退为已确认）
 * 只统计 status='confirmed' 的入库单（草稿/已撤销的不计数）
 * 订单为 completed/cancelled 时跳过，不覆盖人工操作
 * @return string|false 返回同步后的状态，跳过或订单不存在时返回 false
 */
function sync_purchase_order_status($orderId) {
    $pdo = getDB();
    $orderId = intval($orderId);
    if ($orderId <= 0) return false;

    $st = $pdo->prepare("SELECT status FROM purchase_orders WHERE id=?");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) return false;
    if (in_array($order['status'], ['completed', 'cancelled'], true)) return false;

    $st = $pdo->prepare("SELECT id, product_id, quantity, received_qty FROM purchase_order_items WHERE order_id=?");
    $st->execute([$orderId]);
    $orderItems = $st->fetchAll();
    if (empty($orderItems)) return false;

    $received = [];
    $st = $pdo->prepare("SELECT ii.product_id, SUM(ii.quantity) as qty
        FROM purchase_instock_items ii
        JOIN purchase_instocks i ON i.id = ii.instock_id
        WHERE i.order_id = ? AND i.status = 'confirmed'
        GROUP BY ii.product_id");
    $st->execute([$orderId]);
    foreach ($st->fetchAll() as $r) {
        $received[intval($r['product_id'])] = floatval($r['qty']);
    }

    $allFull = true;
    $anyReceived = false;
    $updItem = $pdo->prepare("UPDATE purchase_order_items SET received_qty=? WHERE id=?");
    foreach ($orderItems as $it) {
        $need = floatval($it['quantity']);
        $got  = floatval($received[intval($it['product_id'])] ?? 0);
        if ($got > 0) $anyReceived = true;
        if ($got < $need) $allFull = false;
        // 回写明细已入库数量（详情页「已入库」列依赖此字段，此前从未被维护）
        if (abs(floatval($it['received_qty']) - $got) > 0.000001) {
            $updItem->execute([$got, $it['id']]);
        }
    }

    $newStatus = !$anyReceived ? 'confirmed' : ($allFull ? 'received' : 'partial');
    if ($newStatus !== $order['status']) {
        $pdo->prepare("UPDATE purchase_orders SET status=? WHERE id=?")->execute([$newStatus, $orderId]);
    }
    return $newStatus;
}

/**
 * ============ 统一提示消息（flash）============
 * 删除/校验失败时跨重定向保留提示，避免各页面各自造一套 $error
 */
function flash_set($message, $type = 'danger') {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
}

function flash_show() {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (empty($_SESSION['_flash'])) return;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    $type = in_array($f['type'], ['danger', 'success', 'warning', 'info'], true) ? $f['type'] : 'danger';
    echo '<div class="alert alert-' . $type . '">' . htmlspecialchars($f['message']) . '</div>';
}

/**
 * 主档（商品/客户/供应商/仓库/单位/分类）删除前的引用检查
 * 主档一律不物理删除：被任何单据引用即拒绝，引导改为「停用」
 *
 * @param int   $id     主档ID
 * @param array $checks ['显示名' => 'SELECT COUNT(*) FROM 表 WHERE 字段=?']
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function check_refs($id, array $checks) {
    $pdo = getDB();
    $hits = [];
    foreach ($checks as $label => $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$id]);
            $cnt = intval($st->fetchColumn());
        } catch (Exception $e) {
            continue; // 表/字段不存在时跳过该项检查，不阻断其它检查
        }
        if ($cnt > 0) { $hits[] = $label . '(' . $cnt . ')'; }
    }
    if ($hits) {
        return ['ok' => false, 'msg' => '已被引用，不能删除：' . implode('、', $hits) . '。如不再使用，请改为「停用」。'];
    }
    return ['ok' => true, 'msg' => ''];
}

/**
 * ============ 应收账款统一口径（全系统唯一计算入口）============
 * 页面禁止另写应收 SQL，避免四处算出四个数。
 *
 *   应收余额 = 有效销售订单总额 - 已收金额 + 客户期初应收 - 已确认销售退货
 *   有效订单 = status NOT IN ('draft','cancelled')
 *   账龄按订单日期分桶；客户期初应收归入 90 天以上（最老一档）
 *
 * @param int   $customerId 0=全部客户
 * @param array $options    date_from / date_to（按订单日期区间对账）、user_id（仅统计指定业务员的单据）
 * @return array 每行：id,name,code,phone,order_total,received,initial,returns,balance,within30/60/90/over90,order_count
 */
function get_ar_by_customer($customerId = 0, $options = []) {
    $pdo = getDB();
    $customerId = intval($customerId);
    $dateFrom = trim($options['date_from'] ?? '');
    $dateTo   = trim($options['date_to'] ?? '');
    $userId   = intval($options['user_id'] ?? 0);

    // 1) 订单维度聚合（不含退货、期初）
    $w = "status NOT IN ('draft','cancelled')";
    $params = [];
    if ($customerId > 0) { $w .= " AND customer_id=?"; $params[] = $customerId; }
    if ($dateFrom !== '' && $dateTo !== '') { $w .= " AND order_date BETWEEN ? AND ?"; $params[] = $dateFrom; $params[] = $dateTo; }
    if ($userId > 0) { $w .= " AND user_id=?"; $params[] = $userId; }
    $st = $pdo->prepare("SELECT customer_id,
            COUNT(*) as order_count,
            COALESCE(SUM(total_amount), 0) as order_total,
            COALESCE(SUM(COALESCE(received_amount,0)), 0) as received,
            COALESCE(SUM(CASE WHEN order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN total_amount - COALESCE(received_amount,0) ELSE 0 END), 0) as within30,
            COALESCE(SUM(CASE WHEN order_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND order_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN total_amount - COALESCE(received_amount,0) ELSE 0 END), 0) as within60,
            COALESCE(SUM(CASE WHEN order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND order_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN total_amount - COALESCE(received_amount,0) ELSE 0 END), 0) as within90,
            COALESCE(SUM(CASE WHEN order_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) OR order_date IS NULL THEN total_amount - COALESCE(received_amount,0) ELSE 0 END), 0) as over90
        FROM sales_orders WHERE $w GROUP BY customer_id");
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = intval($r['customer_id']);
        $map[$cid] = [
            'id' => $cid, 'name' => '', 'code' => '', 'phone' => '',
            'order_count' => intval($r['order_count']),
            'order_total' => floatval($r['order_total']),
            'received'    => floatval($r['received']),
            'initial'     => 0,
            'returns'     => 0,
            'within30'    => floatval($r['within30']),
            'within60'    => floatval($r['within60']),
            'within90'    => floatval($r['within90']),
            'over90'      => floatval($r['over90']),
        ];
    }

    // 2) 客户主档（名称 + 期初应收）
    $cw = $customerId > 0 ? "WHERE id=?" : "";
    $st = $pdo->prepare("SELECT id, name, code, phone, COALESCE(initial_balance,0) as initial_balance FROM customers $cw");
    $st->execute($customerId > 0 ? [$customerId] : []);
    $cust = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) { $cust[intval($c['id'])] = $c; }
    foreach ($cust as $cid => $c) {
        if (!isset($map[$cid])) {
            // 无订单但有期初应收的客户也要出现在账上
            if (floatval($c['initial_balance']) == 0 && $customerId <= 0) continue;
            $map[$cid] = ['id'=>$cid,'name'=>'','code'=>'','phone'=>'','order_count'=>0,'order_total'=>0,'received'=>0,
                'initial'=>0,'returns'=>0,'within30'=>0,'within60'=>0,'within90'=>0,'over90'=>0];
        }
        $map[$cid]['name']   = $c['name'];
        $map[$cid]['code']   = $c['code'];
        $map[$cid]['phone']  = $c['phone'];
        $map[$cid]['initial'] = floatval($c['initial_balance']);
    }

    // 3) 已确认销售退货（冲减应收）
    $rw = "status='confirmed'";
    $rp = [];
    if ($customerId > 0) { $rw .= " AND customer_id=?"; $rp[] = $customerId; }
    if ($dateFrom !== '' && $dateTo !== '') { $rw .= " AND return_date BETWEEN ? AND ?"; $rp[] = $dateFrom; $rp[] = $dateTo; }
    if ($userId > 0) { $rw .= " AND user_id=?"; $rp[] = $userId; }
    $st = $pdo->prepare("SELECT customer_id, COALESCE(SUM(total_amount),0) as amt FROM sales_returns WHERE $rw GROUP BY customer_id");
    $st->execute($rp);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = intval($r['customer_id']);
        if (!isset($map[$cid])) {
            $map[$cid] = ['id'=>$cid,'name'=>'','code'=>'','phone'=>'','order_count'=>0,'order_total'=>0,'received'=>0,
                'initial'=>0,'returns'=>0,'within30'=>0,'within60'=>0,'within90'=>0,'over90'=>0];
        }
        $map[$cid]['returns'] = floatval($r['amt']);
    }

    // 4) 补齐名称 + 计算余额（期初归入最老账龄档）
    $rows = [];
    foreach ($map as $cid => $r) {
        if ($r['name'] === '' && isset($cust[$cid])) { $r['name'] = $cust[$cid]['name']; $r['code'] = $cust[$cid]['code']; $r['phone'] = $cust[$cid]['phone']; }
        if ($r['name'] === '') { $r['name'] = $cid > 0 ? '未知客户(#' . $cid . ')' : '未指定客户'; }
        $r['over90'] += $r['initial'];
        $r['balance'] = $r['order_total'] - $r['received'] + $r['initial'] - $r['returns'];
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return $b['balance'] <=> $a['balance']; });
    return $rows;
}

/**
 * 应收全局汇总（首页看板用），由 get_ar_by_customer 求和，保证与应收应付/账龄完全一致
 */
function get_ar_totals() {
    $t = ['order_total'=>0,'received'=>0,'initial'=>0,'returns'=>0,'balance'=>0,
          'within30'=>0,'within60'=>0,'within90'=>0,'over90'=>0,'order_count'=>0,'customer_count'=>0];
    foreach (get_ar_by_customer(0) as $r) {
        foreach (['order_total','received','initial','returns','balance','within30','within60','within90','over90'] as $k) {
            $t[$k] += $r[$k];
        }
        $t['order_count'] += $r['order_count'];
        $t['customer_count']++;
    }
    return $t;
}

/**
 * ============ 应付账款统一口径（与应收对称）============
 *   应付余额 = 有效采购订单总额 - 已付金额 - 已确认采购退货
 */
function get_ap_by_supplier($supplierId = 0) {
    $pdo = getDB();
    $supplierId = intval($supplierId);

    $w = "status NOT IN ('draft','cancelled')";
    $params = [];
    if ($supplierId > 0) { $w .= " AND supplier_id=?"; $params[] = $supplierId; }
    $st = $pdo->prepare("SELECT supplier_id,
            COUNT(*) as order_count,
            COALESCE(SUM(total_amount), 0) as order_total,
            COALESCE(SUM(COALESCE(paid_amount,0)), 0) as paid
        FROM purchase_orders WHERE $w GROUP BY supplier_id");
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['supplier_id']);
        $map[$sid] = ['id'=>$sid,'name'=>'','code'=>'','phone'=>'','order_count'=>intval($r['order_count']),
            'order_total'=>floatval($r['order_total']),'paid'=>floatval($r['paid']),'returns'=>0];
    }

    $sw = $supplierId > 0 ? "WHERE id=?" : "";
    // 用 SELECT * 兼容不同版本表结构（老库可能缺 phone 等列）
    $st = $pdo->prepare("SELECT * FROM suppliers $sw");
    $st->execute($supplierId > 0 ? [$supplierId] : []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sid = intval($s['id']);
        if (isset($map[$sid])) {
            $map[$sid]['name']  = $s['name'] ?? '';
            $map[$sid]['code']  = $s['code'] ?? '';
            $map[$sid]['phone'] = $s['phone'] ?? ($s['contact_phone'] ?? '');
        }
    }

    $rw = "status='confirmed'";
    $rp = [];
    if ($supplierId > 0) { $rw .= " AND supplier_id=?"; $rp[] = $supplierId; }
    $st = $pdo->prepare("SELECT supplier_id, COALESCE(SUM(total_amount),0) as amt FROM purchase_returns WHERE $rw GROUP BY supplier_id");
    $st->execute($rp);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['supplier_id']);
        if (!isset($map[$sid])) { $map[$sid] = ['id'=>$sid,'name'=>'','code'=>'','phone'=>'','order_count'=>0,'order_total'=>0,'paid'=>0,'returns'=>0]; }
        $map[$sid]['returns'] = floatval($r['amt']);
    }

    $rows = [];
    foreach ($map as $r) {
        if ($r['name'] === '') { $r['name'] = $r['id'] > 0 ? '未知供应商(#' . $r['id'] . ')' : '未指定供应商'; }
        $r['balance'] = $r['order_total'] - $r['paid'] - $r['returns'];
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return $b['balance'] <=> $a['balance']; });
    return $rows;
}

function get_ap_totals() {
    $t = ['order_total'=>0,'paid'=>0,'returns'=>0,'balance'=>0,'order_count'=>0,'supplier_count'=>0];
    foreach (get_ap_by_supplier(0) as $r) {
        foreach (['order_total','paid','returns','balance'] as $k) { $t[$k] += $r[$k]; }
        $t['order_count'] += $r['order_count'];
        $t['supplier_count']++;
    }
    return $t;
}

/**
 * ============ 收款核销 ============
 * 订单「已收金额」以核销明细为准重算，不再靠各页面手写的增量累加，
 * 这样收款流水（receipts）与订单余额（sales_orders）不可能各说各话。
 * 已作废的收款单因其 status='cancelled' 会被自动排除，作废即自动回冲。
 *
 * @return float|false 重算后的已收金额；订单不存在返回 false
 */
function sync_order_received($orderId) {
    $pdo = getDB();
    $orderId = intval($orderId);
    if ($orderId <= 0) return false;

    $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) return false;

    $st = $pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM receipt_allocations a
        JOIN receipts r ON r.id = a.receipt_id
        WHERE a.order_id=? AND r.status='confirmed'");
    $st->execute([$orderId]);
    $received = floatval($st->fetchColumn());
    if ($received < 0) { $received = 0; } // 红冲不允许把已收冲成负数

    $total = floatval($order['total_amount']);
    $oldStatus = $order['pay_status'] ?? 'unpaid';
    if ($total > 0 && $received >= $total - 0.01) {
        $newStatus = 'paid_full';
    } elseif ($received > 0) {
        // 已付全款不会因为部分红冲被降级，避免状态抖动
        $newStatus = ($oldStatus === 'paid_full') ? 'paid_full' : 'paid_deposit';
    } else {
        $newStatus = 'unpaid';
    }

    $pdo->prepare("UPDATE sales_orders SET received_amount=?, pay_status=? WHERE id=?")
        ->execute([$received, $newStatus, $orderId]);

    // 付款状态变化时同步已确认出库单，并写入变更日志
    if ($newStatus !== $oldStatus) {
        $now = date('Y-m-d H:i:s');
        $userName = get_user_name();
        $os = $pdo->prepare("SELECT id, pay_status FROM sales_outstocks WHERE order_id=? AND status='confirmed'");
        $os->execute([$orderId]);
        foreach ($os->fetchAll() as $o) {
            $from = $o['pay_status'] ?? 'unpaid';
            $pdo->prepare("UPDATE sales_outstocks SET pay_status=?, pay_remark=?, pay_updated_at=? WHERE id=?")
                ->execute([$newStatus, "收款核销自动同步（{$oldStatus} → {$newStatus}）", $now, $o['id']]);
            $pdo->prepare("INSERT INTO sales_outstock_paylogs (outstock_id, from_status, to_status, remark, user_name, created_at) VALUES (?,?,?,?,?,?)")
                ->execute([$o['id'], $from, $newStatus, "收款核销自动同步", $userName, $now]);
        }
    }
    return $received;
}

/**
 * 采购订单「已付金额」重算（与 sync_order_received 对称）
 */
function sync_purchase_order_paid($orderId) {
    $pdo = getDB();
    $orderId = intval($orderId);
    if ($orderId <= 0) return false;

    $st = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) return false;

    $st = $pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a
        JOIN payments p ON p.id = a.payment_id
        WHERE a.order_id=? AND p.status='confirmed'");
    $st->execute([$orderId]);
    $paid = floatval($st->fetchColumn());
    if ($paid < 0) { $paid = 0; }

    $total = floatval($order['total_amount']);
    $oldStatus = $order['pay_status'] ?? 'unpaid';
    if ($total > 0 && $paid >= $total - 0.01) {
        $newStatus = 'paid_full';
    } elseif ($paid > 0) {
        $newStatus = ($oldStatus === 'paid_full') ? 'paid_full' : 'paid_deposit';
    } else {
        $newStatus = 'unpaid';
    }

    $pdo->prepare("UPDATE purchase_orders SET paid_amount=?, pay_status=? WHERE id=?")
        ->execute([$paid, $newStatus, $orderId]);
    return $paid;
}

/**
 * 取某收款单的核销明细（列表/详情展示用）
 */
function get_receipt_allocations($receiptId) {
    $pdo = getDB();
    $st = $pdo->prepare("SELECT a.*, o.bill_no as order_bill_no FROM receipt_allocations a
        LEFT JOIN sales_orders o ON o.id=a.order_id
        WHERE a.receipt_id=? ORDER BY a.id");
    $st->execute([intval($receiptId)]);
    return $st->fetchAll();
}

/**
 * 取某客户未结清（有应收余额）的销售订单，用于收款核销下拉
 */
function get_customer_unpaid_orders($customerId) {
    $pdo = getDB();
    $st = $pdo->prepare("SELECT id, bill_no, total_amount, COALESCE(received_amount,0) as received_amount,
            (total_amount - COALESCE(received_amount,0)) as balance, order_date
        FROM sales_orders
        WHERE customer_id=? AND status NOT IN ('draft','cancelled')
        ORDER BY id DESC");
    $st->execute([intval($customerId)]);
    return $st->fetchAll();
}

/**
 * 取某供应商未结清的采购订单，用于付款核销下拉
 */
function get_supplier_unpaid_orders($supplierId) {
    $pdo = getDB();
    $st = $pdo->prepare("SELECT id, bill_no, total_amount, COALESCE(paid_amount,0) as paid_amount,
            (total_amount - COALESCE(paid_amount,0)) as balance, order_date
        FROM purchase_orders
        WHERE supplier_id=? AND status NOT IN ('draft','cancelled')
        ORDER BY id DESC");
    $st->execute([intval($supplierId)]);
    return $st->fetchAll();
}

/**
 * 打印模板表自愈：确保表存在，且 type 列足够宽
 * 老库 type 为 ENUM('sales_order','sales_outstock','purchase_order','purchase_instock')，
 * 插入 quote / product_catalog 等新类型会触发 1265 Data truncated（严格模式下直接抛 PDOException）
 */
function ensure_print_templates_table($pdo = null) {
    if (!$pdo) $pdo = getDB();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `print_templates` ("
            . " `id` INT AUTO_INCREMENT PRIMARY KEY,"
            . " `name` VARCHAR(100) NOT NULL,"
            . " `type` VARCHAR(30) NOT NULL DEFAULT 'sales_outstock',"
            . " `content` TEXT,"
            . " `is_default` TINYINT DEFAULT 0,"
            . " `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP"
            . " ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $col = $pdo->query("SHOW COLUMNS FROM print_templates LIKE 'type'")->fetch();
        $colType = $col['Type'] ?? '';
        $needAlter = true;
        if ($colType !== '' && preg_match('/^varchar\((\d+)\)/i', $colType, $m)) {
            $needAlter = (intval($m[1]) < 30); // 已是足够宽的 VARCHAR 则无需变更
        }
        if ($needAlter) {
            $pdo->exec("ALTER TABLE print_templates MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'sales_outstock'");
        }
    } catch (Exception $e) {
        error_log('ensure_print_templates_table alter failed: ' . $e->getMessage());
    }
}

/**
 * 商品列表的查询条件（唯一来源）
 * 列表页、xlsx 导出、产品目录 PDF 打印共用同一份条件，保证「查什么就导出什么」。
 *
 * @return array ['search'=>string, 'category_id'=>int, 'cond'=>string, 'params'=>array]
 *               cond 是不含 WHERE 关键字的条件片段（形如 " AND ..."），
 *               调用方自行决定基础条件：列表用 WHERE 1=1，导出用 WHERE p.status=1
 */
function build_product_filter() {
    $search = trim($_GET['search'] ?? '');
    $categoryId = intval($_GET['category_id'] ?? 0);
    $cond = '';
    $params = [];
    if ($search !== '') {
        $cond .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        $params = array_fill(0, 3, '%' . $search . '%');
    }
    if ($categoryId > 0) {
        $cond .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }
    return ['search' => $search, 'category_id' => $categoryId, 'cond' => $cond, 'params' => $params];
}
