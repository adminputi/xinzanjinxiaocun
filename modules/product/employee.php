<?php
/**
 * 员工管理已弃用，迁移到用户管理
 * 自动跳转到系统用户管理页面
 */
require_once __DIR__ . '/../../includes/header.php';
redirect($siteRoot . '/modules/system/users.php');
