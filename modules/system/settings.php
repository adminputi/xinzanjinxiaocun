<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('system_settings');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $allowedKeys = ['site_name', 'company_name', 'company_address', 'company_phone', 'low_stock_days', 'items_per_page', 'captcha_enabled', 'print_tracking_code', 'auth_server_url', 'auth_api_key', 'auth_api_secret'];
    foreach ($_POST as $key => $val) {
        if (in_array($key, $allowedKeys)) {
            // 使用预处理语句安全查询
            $stmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key=?");
            $stmt->execute([$key]);
            $exists = $stmt->fetchColumn();
            if ($exists) {
                $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$val, $key]);
            } else {
                $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)")->execute([$key, $val]);
            }
        }
    }
    add_log(get_user_id(), 'save', 'settings', '修改系统设置');
    $success = '设置已保存';
}

// 获取当前设置
$settings = [];
$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-sliders"></i> 系统设置</h1>
</div>
<?php if (isset($success)): ?><div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?=$success?></div><?php endif; ?>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">系统名称</label>
                    <input type="text" name="site_name" class="form-control" value="<?=htmlspecialchars($settings['site_name']??SITE_NAME)?>">
                </div>
                <div class="form-group">
                    <label class="form-label">公司名称</label>
                    <input type="text" name="company_name" class="form-control" value="<?=htmlspecialchars($settings['company_name']??'')?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">公司地址</label>
                    <input type="text" name="company_address" class="form-control" value="<?=htmlspecialchars($settings['company_address']??'')?>">
                </div>
                <div class="form-group">
                    <label class="form-label">公司电话</label>
                    <input type="text" name="company_phone" class="form-control" value="<?=htmlspecialchars($settings['company_phone']??'')?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">安全库存天数</label>
                    <input type="number" name="low_stock_days" class="form-control" value="<?=$settings['low_stock_days']??LOW_STOCK_DAYS?>">
                    <small style="color:var(--gray-500);">低于此天数销量的商品将预警</small>
                </div>
                <div class="form-group">
                    <label class="form-label">每页显示条数</label>
                    <input type="number" name="items_per_page" class="form-control" value="<?=$settings['items_per_page']??ITEMS_PER_PAGE?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">登录验证码</label>
                    <select name="captcha_enabled" class="form-control">
                        <option value="0" <?= ($settings['captcha_enabled']??'0')==='0'?'selected':'' ?>>关闭</option>
                        <option value="1" <?= ($settings['captcha_enabled']??'0')==='1'?'selected':'' ?>>开启</option>
                    </select>
                    <small style="color:var(--gray-500);">开启后登录需要输入数学题验证码</small>
                </div>
                <div class="form-group">
                    <label class="form-label">打印出库单追踪码</label>
                    <select name="print_tracking_code" class="form-control">
                        <option value="0" <?= ($settings['print_tracking_code']??'1')==='0'?'selected':'' ?>>关闭</option>
                        <option value="1" <?= ($settings['print_tracking_code']??'1')!=='0'?'selected':'' ?>>开启</option>
                    </select>
                    <small style="color:var(--gray-500);">开启后打印出库单/销售单时显示售后追踪码及二维码</small>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:16px 20px;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存设置</button>
        </div>
    </form>
</div>

<!-- 授权服务器配置 -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fa-solid fa-server"></i> 授权服务器配置</h3>
        <span style="font-size:12px;color:var(--gray-500);">配置后请前往 <a href="license.php">授权管理</a> 激活</span>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">授权服务器地址</label>
                <input type="url" name="auth_server_url" class="form-control" value="<?=htmlspecialchars($settings['auth_server_url'] ?: 'http://auth.92q.net/')?>" placeholder="https://auth.example.com">
                <small style="color:var(--gray-500);">从代理商处获取的授权服务器URL</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">API Key</label>
                    <input type="text" name="auth_api_key" class="form-control" value="<?=htmlspecialchars($settings['auth_api_key'] ?: '')?>" placeholder="ak_xxxxxxxxxxxx" style="font-family:monospace;">
                </div>
                <div class="form-group">
                    <label class="form-label">API Secret</label>
                    <input type="password" name="auth_api_secret" class="form-control" value="<?=htmlspecialchars($settings['auth_api_secret'] ?: '')?>" placeholder="输入API Secret">
                </div>
            </div>
            <?php if (!empty($settings['auth_server_url']) && !empty($settings['auth_api_key'])): ?>
            <div style="padding:8px 12px;background:#f0fdf4;border-radius:var(--radius);font-size:13px;color:var(--success);">
                <i class="fa-solid fa-check-circle"></i> 授权服务器已配置，前往 <a href="license.php">授权管理</a> 激活系统
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:16px 20px;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存配置</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/system_info.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
