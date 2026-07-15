<?php
/**
 * 鑫瓒进销存 - 授权客户端库
 *
 * 负责与授权服务器（auth-server）通信，处理激活、验证、心跳、解绑等操作。
 * 本文件不含业务判断，仅提供 API 调用和本地缓存读写。
 */

define('LICENSE_CACHE_DIR', __DIR__ . '/../uploads/license/');
define('LICENSE_CACHE_FILE', LICENSE_CACHE_DIR . 'cache.json');
define('LICENSE_CACHE_TTL_DEFAULT', 86400); // 默认缓存24小时

// 确保缓存目录存在
if (!is_dir(LICENSE_CACHE_DIR)) {
    @mkdir(LICENSE_CACHE_DIR, 0755, true);
}

/**
 * 生成API签名（与auth-server的generate_api_signature完全一致）
 */
function license_generate_signature(array $params, string $secret): string {
    ksort($params);
    $signStr = '';
    foreach ($params as $k => $v) {
        // 跳过空值
        if ($v === null || $v === '') {
            continue;
        }
        // 数组转为JSON字符串以参与签名（与auth-server保持一致）
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $signStr .= $k . '=' . $v . '&';
    }
    $signStr = rtrim($signStr, '&');
    return hash_hmac('sha256', $signStr, $secret);
}

/**
 * 从system_settings表读取授权配置
 */
function license_get_config(): array {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auth_server_url', 'auth_api_key', 'auth_api_secret', 'activation_id')");
    $config = [
        'server_url' => '',
        'api_key' => '',
        'api_secret' => '',
        'activation_id' => '',
    ];
    while ($row = $stmt->fetch()) {
        switch ($row['setting_key']) {
            case 'auth_server_url': $config['server_url'] = $row['setting_value']; break;
            case 'auth_api_key': $config['api_key'] = $row['setting_value']; break;
            case 'auth_api_secret': $config['api_secret'] = $row['setting_value']; break;
            case 'activation_id': $config['activation_id'] = $row['setting_value']; break;
        }
    }
    return $config;
}

/**
 * 调用auth-server API（通用方法）
 *
 * @param string $action API动作（activate/verify/heartbeat/deactivate）
 * @param array  $data   请求数据（不含api_key/timestamp/signature）
 * @return array ['success'=>bool, 'code'=>int, 'message'=>string, 'data'=>array|null]
 */
function license_call_api(string $action, array $data): array {
    $config = license_get_config();

    if (empty($config['server_url']) || empty($config['api_key']) || empty($config['api_secret'])) {
        return ['success' => false, 'code' => -1, 'message' => '授权服务器未配置，请先在系统设置中配置授权服务器信息', 'data' => null];
    }

    $serverUrl = rtrim($config['server_url'], '/');
    $url = $serverUrl . '/api/v1/?action=' . urlencode($action);

    // 构建签名参数
    $signData = array_merge([
        'api_key' => $config['api_key'],
        'timestamp' => (string)time(),
    ], $data);

    // 过滤空值（与auth-server保持一致：空字符串不参与签名）
    $signParams = [];
    foreach ($signData as $k => $v) {
        $signParams[$k] = $v;
    }
    $signature = license_generate_signature($signParams, $config['api_secret']);
    $signData['signature'] = $signature;

    // 发送请求
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($signData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("license_call_api [$action] curl error: $curlError");
        return ['success' => false, 'code' => -2, 'message' => '连接授权服务器失败：' . $curlError, 'data' => null];
    }

    if ($httpCode !== 200) {
        error_log("license_call_api [$action] HTTP $httpCode: $response");
        return ['success' => false, 'code' => -3, 'message' => "授权服务器返回HTTP $httpCode", 'data' => null];
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['code'])) {
        $jsonError = json_last_error_msg();
        $debugInfo = '[HTTP ' . $httpCode . '] ' . ($jsonError !== 'No error' ? 'JSON解析错误: ' . $jsonError . ' ' : '') . '原始响应: ' . substr($response, 0, 500);
        error_log("license_call_api [$action] 无效返回: $debugInfo");
        return ['success' => false, 'code' => -4, 'message' => '授权服务器返回无效数据', 'data' => null, '_debug' => $debugInfo];
    }

    return [
        'success' => $result['code'] === 0,
        'code' => $result['code'],
        'message' => $result['message'] ?? '',
        'data' => $result['data'] ?? null,
    ];
}

/**
 * 激活授权码
 *
 * @param string $licenseKey      授权码
 * @param string $domain          绑定域名
 * @param string $customerName    客户姓名
 * @param string $customerPhone   客户手机号
 * @param string $customerCompany 客户公司
 * @return array
 */
function license_activate(string $licenseKey, string $domain, string $customerName = '', string $customerPhone = '', string $customerCompany = ''): array {
    // 收集服务器信息
    $serverInfo = [
        'ip' => get_client_ip(),
        'hostname' => function_exists('gethostname') ? gethostname() : ($_SERVER['SERVER_NAME'] ?? ''),
        'os' => PHP_OS,
        'php_version' => PHP_VERSION,
        'mysql_version' => '',
    ];

    // 尝试获取MySQL版本
    try {
        $pdo = getDB();
        $serverInfo['mysql_version'] = $pdo->query("SELECT VERSION()")->fetchColumn();
    } catch (Exception) {
        $serverInfo['mysql_version'] = 'unknown';
    }

    $data = [
        'license_key' => $licenseKey,
        'domain' => $domain,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_company' => $customerCompany,
        'server_info' => $serverInfo,
    ];

    $result = license_call_api('activate', $data);

    if ($result['success'] && !empty($result['data']['activation_id'])) {
        // 保存activation_id到数据库
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key='activation_id'");
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='activation_id'")->execute([$result['data']['activation_id']]);
        } else {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('activation_id', ?)")->execute([$result['data']['activation_id']]);
        }

        // 写入本地缓存
        license_write_cache($result['data']);

        add_log(get_user_id(), 'activate', 'license', '激活授权码: ' . $licenseKey);
    }

    return $result;
}

/**
 * 验证授权状态
 *
 * @param string $activationId 激活ID（留空则从数据库读取）
 * @param string $domain       域名（留空则自动取当前域名）
 * @return array
 */
function license_verify(string $activationId = '', string $domain = ''): array {
    if (empty($activationId)) {
        $config = license_get_config();
        $activationId = $config['activation_id'];
    }
    if (empty($activationId)) {
        return ['success' => false, 'code' => -5, 'message' => '系统未激活', 'data' => null];
    }

    if (empty($domain)) {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    }

    $result = license_call_api('verify', [
        'activation_id' => (int)$activationId,
        'domain' => $domain,
    ]);

    if ($result['success']) {
        license_write_cache($result['data']);
    }

    return $result;
}

/**
 * 发送心跳
 *
 * @param string $activationId 激活ID
 * @param string $domain       域名
 * @return array
 */
function license_heartbeat(string $activationId = '', string $domain = ''): array {
    if (empty($activationId)) {
        $config = license_get_config();
        $activationId = $config['activation_id'];
    }
    if (empty($activationId)) {
        return ['success' => false, 'code' => -5, 'message' => '系统未激活', 'data' => null];
    }

    if (empty($domain)) {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    }

    return license_call_api('heartbeat', [
        'activation_id' => (int)$activationId,
        'domain' => $domain,
    ]);
}

/**
 * 解绑授权
 *
 * @param string $activationId 激活ID
 * @param string $domain       域名
 * @return array
 */
function license_deactivate(string $activationId = '', string $domain = ''): array {
    if (empty($activationId)) {
        $config = license_get_config();
        $activationId = $config['activation_id'];
    }
    if (empty($activationId)) {
        return ['success' => false, 'code' => -5, 'message' => '系统未激活', 'data' => null];
    }

    if (empty($domain)) {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    }

    $result = license_call_api('deactivate', [
        'activation_id' => (int)$activationId,
        'domain' => $domain,
    ]);

    if ($result['success']) {
        // 清除本地数据
        $pdo = getDB();
        $pdo->exec("DELETE FROM system_settings WHERE setting_key='activation_id'");
        license_clear_cache();
        add_log(get_user_id(), 'deactivate', 'license', '解绑授权');
    }

    return $result;
}

/**
 * 写入本地验证缓存
 */
function license_write_cache(array $data): void {
    $cache = [
        'license_type' => $data['license_type'] ?? '',
        'days_remaining' => $data['days_remaining'] ?? 0,
        'expires_at' => $data['expires_at'] ?? '',
        'features' => $data['features'] ?? [],
        'status' => $data['status'] ?? 'active',
        'signature' => $data['signature'] ?? '',
        'cache_ttl' => $data['cache_ttl'] ?? LICENSE_CACHE_TTL_DEFAULT,
        'cached_at' => time(),
        'domain' => license_get_domain(),
    ];

    @file_put_contents(LICENSE_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 读取本地验证缓存
 *
 * @return array|null 有效缓存返回数组，过期或无效返回null
 */
function license_read_cache(): ?array {
    if (!file_exists(LICENSE_CACHE_FILE)) {
        return null;
    }

    $content = @file_get_contents(LICENSE_CACHE_FILE);
    if (empty($content)) return null;

    $cache = json_decode($content, true);
    if (!$cache || !isset($cache['cached_at']) || !isset($cache['cache_ttl'])) {
        return null;
    }

    $ttl = (int)$cache['cache_ttl'];
    if ($ttl <= 0) $ttl = LICENSE_CACHE_TTL_DEFAULT;

    if (time() - $cache['cached_at'] > $ttl) {
        return null; // 过期
    }

    // 域名变更检测：缓存中的域名与当前域名不匹配则视为无效，强制重新验证
    $currentDomain = license_get_domain();
    $cachedDomain = $cache['domain'] ?? '';
    if ($cachedDomain !== '' && $cachedDomain !== $currentDomain) {
        return null;
    }

    return $cache;
}

/**
 * 清除本地缓存
 */
function license_clear_cache(): void {
    if (file_exists(LICENSE_CACHE_FILE)) {
        @unlink(LICENSE_CACHE_FILE);
    }
}

/**
 * 获取当前域名
 */
function license_get_domain(): string {
    return $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
}
