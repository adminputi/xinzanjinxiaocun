<?php
/**
 * 公共函数库
 */
require_once __DIR__ . '/../config/database.php';
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

/**
 * JavaScript 字符串安全转义（PHP 值嵌入到 <script> 标签内 JS 单引号字符串时使用）
 * 在 addslashes 基础上额外将 </ 替换为 <\/，防止 </script> 标签闭合攻击
 */
function js_escape($str) {
    return str_replace('</', '<\/', addslashes((string)$str));
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
}
csrf_init();

/**
 * 获取 CSRF Token 值
 */
function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
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
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        // 重新生成 token 防止重放
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        die('<div style="text-align:center;margin-top:100px;"><h3>安全验证失败</h3><p>表单已过期或非法提交，请返回重试。</p><a href="javascript:history.back()">返回上一页</a></div>');
    }
    // 验证通过后重新生成 token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    // 清除之前的所有输出（Notice/Warning等），确保返回纯JSON
    if (ob_get_level() > 0) {
        ob_clean();
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
    if (!headers_sent()) {
        header("Location: $url");
        exit;
    }
    // headers已发送时，使用JS跳转（已安全转义）
    echo '<script>location.href=' . json_encode($url, JSON_UNESCAPED_SLASHES) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
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
 * 权限检查中间件
 */
function require_permission($permission) {
    if (!isset($_SESSION['user_id'])) {
        redirect('../index.php');
    }
    if (!check_permission($permission)) {
        die('<div style="text-align:center;margin-top:100px;"><h3>无权限访问</h3><p>您没有访问此页面的权限，请联系管理员。</p><a href="../index.php">返回首页</a></div>');
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
function update_inventory($productId, $warehouseId, $quantity, $type, $billNo, $billType, $userId, $remark = '') {
    $pdo = getDB();
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
 * 上传文件
 */
function upload_file($fieldName, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']) {
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

        $mimeMap = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls'  => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
            'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
        ];

        $allowedMimes = $mimeMap[$ext] ?? [];
        if (!empty($allowedMimes) && !in_array($mimeType, $allowedMimes)) {
            return ['success' => false, 'message' => '文件类型与扩展名不匹配'];
        }
    }

    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => '文件大小超过限制'];
    }

    // 使用纯随机文件名，不暴露时间信息
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = UPLOAD_DIR . $newName;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $newName, 'path' => 'uploads/' . $newName];
    }
    return ['success' => false, 'message' => '文件保存失败'];
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
        ]],
        // 7. 售后追踪（紧接库存管理之后）
        ['name' => '售后追踪', 'icon' => 'qrcode', 'perm' => 'master_data', 'children' => [
            ['name' => '生成追踪码', 'url' => 'modules/after_sales/create.php', 'icon' => 'plus-circle', 'perm' => 'master_data'],
            ['name' => '追踪码管理', 'url' => 'modules/after_sales/list.php', 'icon' => 'list', 'perm' => 'master_data'],
            ['name' => '追踪码查询', 'url' => 'modules/after_sales/query.php', 'icon' => 'search', 'perm' => 'master_data'],
            ['name' => '状态管理', 'url' => 'modules/after_sales/statuses.php', 'icon' => 'list-check', 'perm' => 'master_data'],
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
    static $map = null;
    if ($map !== null) return $map;

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

    // 补充：不在菜单中的附属页面（详情、编辑等）
    $secondary = [
        'modules/purchase/instock_view.php' => '采购入库详情',
        'modules/purchase/order_form.php'   => '采购订单编辑',
        'modules/purchase/order_view.php'   => '采购订单详情',
        'modules/sales/outstock_view.php'   => '销售出库详情',
        'modules/sales/order_form.php'      => '销售订单编辑',
        'modules/sales/order_view.php'      => '销售订单详情',
        'modules/product/form.php'          => '商品编辑',
        'modules/inventory/check_edit.php'  => '盘点编辑',
        'modules/inventory/check_view.php'  => '盘点详情',
    ];

    return array_merge($secondary, $map); // $map 优先级更高
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
