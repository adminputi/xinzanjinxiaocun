<?php
require_once __DIR__ . '/auth.php';
set_security_headers();
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$requestDir = dirname($_SERVER['SCRIPT_NAME']);
$requestDir = str_replace('\\', '/', $requestDir);
$depth = $requestDir === '/' ? 0 : substr_count(ltrim($requestDir, '/'), '/') + 1;
// 根路径：用于CSS/JS引用，使用相对路径确保在任何部署环境可用
$basePath = $depth > 0 ? str_repeat('../', $depth) : '';
// 站点根目录路径（用于侧边栏链接，使用根相对路径防止AJAX导航404）
// 侧边栏URL均以 modules/ 开头，找到 SCRIPT_NAME 中 /modules/ 出现的位置，前缀即为站点根目录
$scriptNameNormalized = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$modulesPos = strpos($scriptNameNormalized, '/modules/');
$siteRoot = ($modulesPos !== false && $modulesPos > 0) ? substr($scriptNameNormalized, 0, $modulesPos) : '';
// AJAX 导航检测
$isAjaxNav = ($_SERVER['HTTP_X_NAV'] ?? '') === '1';

// 系统显示名称：授权客户使用系统设置中的名称，未授权显示默认 SITE_NAME
$siteDisplayName = SITE_NAME;
if (file_exists(__DIR__ . '/license.php')) {
    require_once __DIR__ . '/license.php';
    $licenseStatus = license_get_status();
    if ($licenseStatus['status'] === 'active') {
        try {
            $row = getDB()->query("SELECT setting_value FROM system_settings WHERE setting_key='site_name'")->fetch();
            if ($row && !empty(trim($row['setting_value']))) {
                $siteDisplayName = $row['setting_value'];
            }
        } catch (Exception $e) {}
    }
}

$pageTitle = get_page_name($_SERVER['SCRIPT_NAME']);
?>
<?php if (!$isAjaxNav): ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteDisplayName ?></title>
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= CDN_FONTAWESOME ?>">
</head>
<body>
<div class="app-container">
    <!-- 侧边栏 -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2><i class="fa-solid fa-boxes-stacked"></i> <?= $siteDisplayName ?></h2>
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
        </div>
        <nav class="sidebar-nav">
            <?php
            $menus = get_menu();
            foreach ($menus as $menu):
                // 带子菜单的菜单组：只要有任意子菜单有权限，就显示该组（不再依赖主菜单自身的权限）
                if (isset($menu['children'])) {
                    $visibleChildren = [];
                    foreach ($menu['children'] as $child) {
                        if (empty($child['perm']) || check_permission($child['perm'])) {
                            $visibleChildren[] = $child;
                        }
                    }
                    if (empty($visibleChildren)) continue;
                } else {
                    // 没有子菜单的菜单项：直接检查自身权限
                    if (!empty($menu['perm']) && !check_permission($menu['perm'])) continue;
                    $visibleChildren = [];
                }
            ?>
            <div class="nav-group">
                <?php if (isset($menu['children'])): ?>
                <div class="nav-group-title" onclick="toggleNavGroup(this)">
                    <i class="fa-solid fa-<?= $menu['icon'] ?>"></i>
                    <span><?= $menu['name'] ?></span>
                    <i class="fa-solid fa-chevron-down nav-arrow"></i>
                </div>
                <div class="nav-group-items">
                    <?php foreach ($visibleChildren as $child):
                        $isActive = strpos($_SERVER['PHP_SELF'], $child['url']) !== false;
                    ?>
                    <a href="<?= $siteRoot ?>/<?= $child['url'] ?>" class="nav-item <?= $isActive ? 'active' : '' ?>">
                        <i class="fa-solid fa-<?= $child['icon'] ?>"></i>
                        <span><?= $child['name'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <a href="<?= $siteRoot ?>/<?= $menu['url'] ?>" class="nav-item single <?= strpos($_SERVER['PHP_SELF'], $menu['url']) !== false ? 'active' : '' ?>">
                    <i class="fa-solid fa-<?= $menu['icon'] ?>"></i>
                    <span><?= $menu['name'] ?></span>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        </nav>
    </aside>

    <!-- 遮罩层（移动端） -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- 主内容区 -->
    <main class="main-content">
        <header class="top-header">
            <div class="top-header-left">
                <button class="menu-trigger" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="<?= $siteRoot ?>/dashboard.php">首页</a>
                    <?php if (!empty($currentPage) && $currentPage !== 'dashboard.php'): ?>
                    <span>/</span> <span><?= $pageTitle ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="top-header-right">
                <div class="header-actions">
                    <div class="header-user-dropdown">
                        <button class="header-user-btn" onclick="toggleUserMenu()">
                            <span class="header-user-avatar"><?= mb_substr(get_user_name(), 0, 1) ?></span>
                            <div class="header-user-text">
                                <span class="header-user-name"><?= get_user_name() ?></span>
                                <span class="header-user-role-tag"><?= get_user_role() ?></span>
                            </div>
                            <i class="fa-solid fa-chevron-down header-user-arrow"></i>
                        </button>
                        <div class="header-user-menu" id="userMenu">
                            <div class="user-menu-header">
                                <span class="user-menu-avatar"><?= mb_substr(get_user_name(), 0, 1) ?></span>
                                <div class="user-menu-header-info">
                                    <span class="user-menu-name"><?= get_user_name() ?></span>
                                    <span class="user-menu-role"><?= get_user_role() ?></span>
                                </div>
                            </div>
                            <div class="user-menu-divider"></div>
                            <div class="user-menu-item" onclick="openModal('profileModal')"><i class="fa-solid fa-user-pen"></i> 编辑资料</div>
                            <div class="user-menu-divider"></div>
                            <a href="<?= $siteRoot ?>/logout.php" class="user-menu-item user-menu-item-danger"><i class="fa-solid fa-right-from-bracket"></i> 退出登录</a>
                        </div>
                    </div>
                    <a href="<?= $siteRoot ?>/modules/system/settings.php" class="btn-icon" title="设置"><i class="fa-solid fa-gear"></i></a>
                </div>
            </div>
        </header>
        <div class="content-wrapper">
<?php
// 授权状态提醒横幅
$_licenseAlert = null;
if (file_exists(__DIR__ . '/license.php') && function_exists('license_get_alert')) {
    $_licenseAlert = license_get_alert();
}
if ($_licenseAlert):
    $alertIcons = ['danger'=>'circle-xmark', 'warning'=>'triangle-exclamation', 'info'=>'circle-info'];
    $alertIcon = $alertIcons[$_licenseAlert['type']] ?? 'circle-info';
    $bgColors = ['danger'=>'#fef2f2', 'warning'=>'#fffbeb', 'info'=>'#eff6ff'];
    $borderColors = ['danger'=>'#fecaca', 'warning'=>'#fde68a', 'info'=>'#bfdbfe'];
    $textColors = ['danger'=>'#991b1b', 'warning'=>'#92400e', 'info'=>'#1e40af'];
?>
<div style="margin-bottom:16px;padding:10px 16px;background:<?=$bgColors[$_licenseAlert['type']]?>;border:1px solid <?=$borderColors[$_licenseAlert['type']]?>;border-radius:var(--radius);font-size:13px;color:<?=$textColors[$_licenseAlert['type']]?>;display:flex;align-items:center;gap:8px;">
    <i class="fa-solid fa-<?=$alertIcon?>" style="font-size:16px;"></i>
    <span style="flex:1;"><?=$_licenseAlert['message']?></span>
    <a href="<?=$siteRoot?>/modules/system/license.php" style="color:inherit;font-weight:600;white-space:nowrap;">查看详情 →</a>
</div>
<?php endif; ?>
<!-- 编辑资料弹窗 -->
<div class="modal-overlay" id="profileModal">
    <div class="modal modal-md">
        <div class="modal-header"><h3 class="modal-title">编辑资料</h3><button class="modal-close" onclick="closeModal('profileModal')">&times;</button></div>
        <div class="modal-body">
            <div id="profileMsg" style="display:none;margin-bottom:12px;"></div>
            <h4 class="profile-section-title">基本信息</h4>
            <form id="profileForm" onsubmit="return saveProfile(event)">
            <?= csrf_field() ?>
            <div class="form-group"><label class="form-label">用户名</label><input type="text" class="form-control" value="<?= get_user_name() ?>" disabled></div>
            <div class="form-group"><label class="form-label">真实姓名</label><input type="text" name="real_name" class="form-control" id="profRealName"></div>
            <div class="form-group"><label class="form-label">邮箱</label><input type="email" name="email" class="form-control" id="profEmail"></div>
            <div class="form-group"><label class="form-label">手机号</label><input type="text" name="phone" class="form-control" id="profPhone"></div>
            <div class="form-group" style="text-align:right;margin-bottom:0;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> 保存基本信息</button>
            </div>
            </form>
            <hr class="profile-divider">
            <h4 class="profile-section-title">修改密码</h4>
            <div id="pwdMsg" style="display:none;margin-bottom:12px;"></div>
            <form id="passwordForm" onsubmit="return changePassword(event)">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label">原密码 <span class="required">*</span></label><input type="password" name="old_password" class="form-control" required></div>
                <div class="form-group"><label class="form-label">新密码 <span class="required">*</span></label><input type="password" name="new_password" class="form-control" required minlength="8" placeholder="至少8位，含大小写+数字"></div>
                <div class="form-group"><label class="form-label">确认新密码 <span class="required">*</span></label><input type="password" name="confirm_password" class="form-control" required minlength="8"></div>
            </div>
            <div class="form-group" style="text-align:right;margin-bottom:0;">
                <button type="submit" class="btn btn-outline"><i class="fa-solid fa-key"></i> 修改密码</button>
            </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('profileModal')">关闭</button>
        </div>
    </div>
</div>
<script>
function toggleUserMenu() {
    var menu = document.getElementById('userMenu');
    menu.classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.header-user-dropdown')) {
        document.getElementById('userMenu').classList.remove('show');
    }
});
// 加载用户信息到编辑资料弹窗
(function(){
    fetch('<?= $siteRoot ?>/api/profile.php?action=get', {credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success && resp.data) {
            document.getElementById('profRealName').value = resp.data.real_name || '';
            document.getElementById('profEmail').value = resp.data.email || '';
            document.getElementById('profPhone').value = resp.data.phone || '';
        }
    });
})();
function saveProfile(e) {
    e.preventDefault();
    var msg = document.getElementById('profileMsg');
    var fd = new FormData();
    fd.append('action', 'update_profile');
    fd.append('real_name', document.getElementById('profRealName').value.trim());
    fd.append('email', document.getElementById('profEmail').value.trim());
    fd.append('phone', document.getElementById('profPhone').value.trim());
    fetch('<?= $siteRoot ?>/api/profile.php', {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(resp){
        msg.style.display='block';
        if(resp.success) {
            msg.innerHTML='<div class=\"alert alert-success\">'+resp.message+'，即将刷新...</div>';
            setTimeout(function(){ location.reload(); }, 1000);
        } else {
            msg.innerHTML='<div class=\"alert alert-danger\">'+resp.message+'</div>';
        }
    });
    return false;
}
function changePassword(e) {
    e.preventDefault();
    var form = document.getElementById('passwordForm');
    var oldPwd = form.old_password.value.trim();
    var newPwd = form.new_password.value.trim();
    var confirmPwd = form.confirm_password.value.trim();
    var msg = document.getElementById('pwdMsg');
    if(!oldPwd || !newPwd || !confirmPwd) { msg.style.display='block'; msg.innerHTML='<div class=\"alert alert-danger\">请填写所有字段</div>'; return false; }
    if(newPwd !== confirmPwd) { msg.style.display='block'; msg.innerHTML='<div class=\"alert alert-danger\">两次输入的新密码不一致</div>'; return false; }
    if(newPwd.length < 8) { msg.style.display='block'; msg.innerHTML='<div class=\"alert alert-danger\">新密码至少8位</div>'; return false; }
    var fd = new FormData();
    fd.append('action', 'change_password');
    fd.append('old_password', oldPwd);
    fd.append('new_password', newPwd);
    fetch('<?= $siteRoot ?>/api/profile.php', {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(resp){
        msg.style.display='block';
        if(resp.success) {
            msg.innerHTML='<div class=\"alert alert-success\">'+resp.message+'</div>';
            setTimeout(function(){ msg.style.display='none'; form.reset(); }, 1500);
        } else {
            msg.innerHTML='<div class=\"alert alert-danger\">'+resp.message+'</div>';
        }
    })
    .catch(function(){ msg.style.display='block'; msg.innerHTML='<div class=\"alert alert-danger\">请求失败，请重试</div>'; });
    return false;
}
</script>
<?php else: ?>
<!-- AJAX partial content -->
<page-title-text style="display:none"><?= $pageTitle ?> - <?= $siteDisplayName ?></page-title-text>
<div class="breadcrumb">
    <a href="<?= $siteRoot ?>/dashboard.php">首页</a>
    <?php if (!empty($currentPage) && $currentPage !== 'dashboard.php'): ?>
    <span>/</span> <span><?= $pageTitle ?></span>
    <?php endif; ?>
</div>
<div class="content-wrapper">
<?php
// AJAX路径也加载授权提醒
if (file_exists(__DIR__ . '/license.php') && function_exists('license_get_alert')) {
    $_licenseAlertAjax = license_get_alert();
    if ($_licenseAlertAjax):
        $alertIconsAjax = ['danger'=>'circle-xmark', 'warning'=>'triangle-exclamation', 'info'=>'circle-info'];
        $alertIconAjax = $alertIconsAjax[$_licenseAlertAjax['type']] ?? 'circle-info';
        $bgColorsAjax = ['danger'=>'#fef2f2', 'warning'=>'#fffbeb', 'info'=>'#eff6ff'];
        $borderColorsAjax = ['danger'=>'#fecaca', 'warning'=>'#fde68a', 'info'=>'#bfdbfe'];
        $textColorsAjax = ['danger'=>'#991b1b', 'warning'=>'#92400e', 'info'=>'#1e40af'];
?>
<div style="margin-bottom:16px;padding:10px 16px;background:<?=$bgColorsAjax[$_licenseAlertAjax['type']]?>;border:1px solid <?=$borderColorsAjax[$_licenseAlertAjax['type']]?>;border-radius:var(--radius);font-size:13px;color:<?=$textColorsAjax[$_licenseAlertAjax['type']]?>;display:flex;align-items:center;gap:8px;">
    <i class="fa-solid fa-<?=$alertIconAjax?>" style="font-size:16px;"></i>
    <span style="flex:1;"><?=$_licenseAlertAjax['message']?></span>
    <a href="<?=$siteRoot?>/modules/system/license.php" style="color:inherit;font-weight:600;white-space:nowrap;">查看详情 →</a>
</div>
<?php
    endif;
}
?>
<?php endif; ?>
