<?php
/**
 * 鑫瓒进销存 - 授权验证模块（开发版 — 备份）
 *
 * 本文件为开发环境使用，默认授予所有功能权限。
 * 发布时需替换为混淆后的正式版。
 *
 * 开发用法：复制本文件到 includes/license.php
 * 生产用法：发布脚本混淆后覆盖 includes/license.php
 */

/**
 * 检查功能权限（开发版：始终返回 true）
 * @param string $feature 功能模块标识
 * @return bool
 */
function license_has_feature(string $feature): bool {
    // 开发环境无条件授权所有功能
    return true;
}
