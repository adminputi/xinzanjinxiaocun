<?php
/**
 * 数据库升级脚本 - 使用统一迁移系统
 * 需管理员登录后访问
 * 
 * ⚠️ 生产环境部署前请删除此文件
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/migration.php';

// 安全检查：只有已登录的管理员才能执行升级
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    die('<h3 style="color:red">无权访问，仅限管理员执行数据库升级。</h3>');
}

try {
    ob_start();
    run_migrations();
    $output = ob_get_clean();
    
    echo '<h2 style="color:green;">数据库升级完成</h2>';
    echo '<p>所有待处理的数据库结构变更已执行完毕。</p>';
    echo '<p style="color:red;font-weight:bold;">⚠️ 安全提示：升级完成后请立即删除此文件和 install/ 目录！</p>';
    
    // 自删除功能
    if (isset($_GET['delete']) && $_GET['delete'] === '1') {
        if (unlink(__FILE__)) {
            echo '<p style="color:green;">✅ 升级脚本已安全删除。</p>';
        } else {
            echo '<p style="color:red;">❌ 自动删除失败，请手动删除文件：install/upgrade.php</p>';
        }
    } else {
        echo '<p><a href="?delete=1" onclick="return confirm(\'确定删除此升级脚本？\')" style="color:#ef4444;">点击此处删除本升级脚本</a></p>';
    }
    
} catch (Exception $e) {
    error_log('Upgrade error: ' . $e->getMessage());
    die('<h3 style="color:red">升级失败，请查看错误日志。</h3>');
}
