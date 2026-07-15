<?php
/**
 * API 轻量初始化
 * 仅加载：Session、数据库、安全头、辅助函数、鉴权
 * 不输出任何 HTML，适用于返回 JSON 的 API/Ajax 端点
 *
 * 使用方式：require_once __DIR__ . '/../../includes/api_init.php';
 */
// 开启输出缓冲，防止任何意外输出污染 JSON 响应
ob_start();
require_once __DIR__ . '/functions.php';
set_security_headers();

// 登录检查
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'message' => '未登录或登录已过期'], JSON_UNESCAPED_UNICODE));
}

// ============================================
// 授权许可检查（与 functions.php 中逻辑一致）
// ============================================
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
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'message' => '该功能需要购买正式授权，请在系统设置中激活'], JSON_UNESCAPED_UNICODE));
    }
}
