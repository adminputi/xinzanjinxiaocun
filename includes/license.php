<?php
/**
 * 鑫瓒进销存 - 授权验证模块（生产版）
 *
 * 通过auth-server验证授权状态，控制功能权限。
 * 使用本地缓存减少API调用，缓存过期后自动刷新。
 */

require_once __DIR__ . '/license_client.php';

/**
 * 根据 expires_at 实时计算剩余天数（覆盖缓存中的静态值）
 */
function license_recalc_days(array $cache): array {
    if (($cache['license_type'] ?? '') === 'lifetime') {
        $cache['days_remaining'] = 99999;
        $cache['status'] = 'active';
    } elseif (!empty($cache['expires_at'])) {
        $expiresTs = strtotime($cache['expires_at']);
        $daysRemaining = max(0, (int)ceil(($expiresTs - time()) / 86400));
        $cache['days_remaining'] = $daysRemaining;
        if ($daysRemaining <= 0) {
            $cache['status'] = 'expired';
        }
    }
    return $cache;
}

/**
 * 获取当前授权状态
 *
 * @return array ['status'=>'unactivated|active|expired', 'license_type'=>'', 'days_remaining'=>0, 'expires_at'=>'', 'features'=>[]]
 */
function license_get_status(): array {
    // 检查是否已激活
    $config = license_get_config();
    if (empty($config['activation_id'])) {
        return [
            'status' => 'unactivated',
            'license_type' => '',
            'days_remaining' => 0,
            'expires_at' => '',
            'features' => [],
        ];
    }

    // 尝试从缓存读取
    $cache = license_read_cache();
    if ($cache !== null) {
        return license_recalc_days($cache);
    }

    // 缓存过期，调用远程验证
    $result = license_verify();
    if ($result['success'] && !empty($result['data'])) {
        $cache = license_read_cache();
        if ($cache !== null) {
            return license_recalc_days($cache);
        }
    }

    // 远程验证失败但有本地缓存时，使用过期缓存作为降级（额外延长1天）
    if ($cache === null) {
        $cache = license_read_expired_cache();
    }

    if ($cache !== null) {
        return license_recalc_days($cache);
    }

    // 完全无缓存，返回unactivated
    return [
        'status' => 'unactivated',
        'license_type' => '',
        'days_remaining' => 0,
        'expires_at' => '',
        'features' => [],
    ];
}

/**
 * 读取过期缓存（作为离线降级，过期后额外允许1天）
 */
function license_read_expired_cache(): ?array {
    if (!file_exists(LICENSE_CACHE_FILE)) {
        return null;
    }

    $content = @file_get_contents(LICENSE_CACHE_FILE);
    if (empty($content)) return null;

    $cache = json_decode($content, true);
    if (!$cache || !isset($cache['cached_at'])) {
        return null;
    }

    // 过期48小时内仍可使用
    $maxAge = 48 * 3600;
    if (time() - $cache['cached_at'] > $maxAge) {
        return null;
    }

    // 标记为降级状态
    $cache['degraded'] = true;
    return $cache;
}

/**
 * 检查功能权限
 *
 * @param string $feature 功能模块标识
 * @return bool
 */
function license_has_feature(string $feature): bool {
    static $cache = null;

    // 使用静态缓存避免同一请求重复查询
    if ($cache === null) {
        $cache = license_get_status();
    }

    // 未激活或已过期：所有功能不可用
    if ($cache['status'] === 'unactivated' || $cache['status'] === 'expired') {
        // 特殊处理：基本访问权限在过期后仍保留
        if ($feature === 'basic') {
            return true;
        }
        return false;
    }

    // 已激活：检查功能是否在列表中（features 为 module_key => module_name 的映射）
    if (array_key_exists($feature, $cache['features'] ?? [])) {
        return true;
    }

    // 所有功能默认可用（如auth-server未配置模块时）
    if (empty($cache['features'])) {
        return true;
    }

    return false;
}

/**
 * 获取授权过期提醒信息
 * 返回null表示无需提醒，返回数组包含提醒信息
 */
function license_get_alert(): ?array {
    $status = license_get_status();

    switch ($status['status']) {
        case 'unactivated':
            return [
                'type' => 'warning',
                'message' => '系统尚未激活，部分功能可能受限。请前往 <a href="modules/system/license.php">授权管理</a> 激活。',
            ];

        case 'expired':
            return [
                'type' => 'danger',
                'message' => '您的授权已过期！部分功能已受限，请联系代理商续费。',
            ];

        case 'active':
            $days = (int)($status['days_remaining'] ?? 99999);
            if ($days <= 7 && $status['license_type'] !== 'lifetime') {
                return [
                    'type' => 'warning',
                    'message' => "您的授权将于 {$days} 天后到期，请及时续费。",
                ];
            }
            break;
    }

    return null;
}
