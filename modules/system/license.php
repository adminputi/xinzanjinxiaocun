<?php
/**
 * 授权激活管理页面
 */
require_once __DIR__ . '/../../includes/header.php';
require_permission('system_settings');
require_once __DIR__ . '/../../includes/license.php';

$pdo = getDB();
$config = license_get_config();
$status = license_get_status();

// 授权类型标签
$licenseTypeLabels = ['yearly' => '按年授权', 'lifetime' => '终身授权', 'trial' => '试用授权'];

// 处理表单提交
$msg = ['type' => '', 'text' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'activate') {
        $licenseKey = trim($_POST['license_key'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $customerCompany = trim($_POST['customer_company'] ?? '');
        $domain = license_get_domain();

        if (empty($licenseKey)) {
            $msg = ['type' => 'danger', 'text' => '请输入授权码'];
        } elseif (empty($config['server_url']) || empty($config['api_key']) || empty($config['api_secret'])) {
            $msg = ['type' => 'danger', 'text' => '请先在系统设置中配置授权服务器信息'];
        } else {
            $result = license_activate($licenseKey, $domain, $customerName, $customerPhone, $customerCompany);
            if ($result['success']) {
                $msg = ['type' => 'success', 'text' => '激活成功！授权类型：' . ($licenseTypeLabels[$result['data']['license_type']] ?? $result['data']['license_type'])];
                $status = license_get_status();
                $config = license_get_config();
            } else {
                $msg = ['type' => 'danger', 'text' => '激活失败：' . $result['message']];
                if (!empty($result['_debug'])) {
                    $msg['text'] .= '<br><small style="opacity:0.7">调试信息：' . htmlspecialchars($result['_debug']) . '</small>';
                }
            }
        }
    } elseif ($action === 'deactivate') {
        $result = license_deactivate();
        if ($result['success']) {
            $msg = ['type' => 'success', 'text' => '授权已解绑'];
            $status = license_get_status();
            $config = license_get_config();
        } else {
            $msg = ['type' => 'danger', 'text' => '解绑失败：' . $result['message']];
        }
    } elseif ($action === 'refresh') {
        license_clear_cache();
        $result = license_verify();
        if ($result['success']) {
            $msg = ['type' => 'success', 'text' => '状态已刷新'];
        } else {
            $msg = ['type' => 'warning', 'text' => '刷新失败：' . $result['message'] . '（继续使用本地缓存）'];
        }
        $status = license_get_status();
    }
}

// 格式化到期信息
$expiresText = '';
if ($status['status'] === 'active') {
    if ($status['license_type'] === 'lifetime') {
        $expiresText = '永久有效';
    } else {
        $expiresText = ($status['expires_at'] ?? '-') . '（剩余 ' . ($status['days_remaining'] ?? 0) . ' 天）';
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-key"></i> 授权管理</h1>
</div>

<?php if ($msg['type']): ?>
<div class="alert alert-<?= $msg['type'] ?>"><i class="fa-solid fa-<?= $msg['type'] === 'success' ? 'check-circle' : ($msg['type'] === 'danger' ? 'circle-xmark' : 'triangle-exclamation') ?>"></i> <?= htmlspecialchars($msg['text']) ?></div>
<?php endif; ?>

<!-- 授权状态卡片 -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> 当前授权状态</h3>
    </div>
    <div class="card-body">
        <?php if ($status['status'] === 'unactivated'): ?>
        <div style="text-align:center;padding:40px 20px;">
            <div style="font-size:48px;color:var(--gray-400);margin-bottom:16px;"><i class="fa-solid fa-lock"></i></div>
            <h3 style="color:var(--gray-700);margin-bottom:8px;">系统未激活</h3>
            <p style="color:var(--gray-500);font-size:14px;">请输入授权码完成激活，否则部分高级功能将不可用</p>
            <?php if (empty($config['server_url']) || empty($config['api_key']) || empty($config['api_secret'])): ?>
            <p style="color:var(--warning);font-size:13px;margin-top:8px;">
                <i class="fa-solid fa-triangle-exclamation"></i> 
                请先在 <a href="settings.php">系统设置</a> 中配置授权服务器信息（服务器地址、API Key、API Secret）
            </p>
            <?php endif; ?>
        </div>
        <?php elseif ($status['status'] === 'expired'): ?>
        <div style="text-align:center;padding:40px 20px;">
            <div style="font-size:48px;color:var(--danger);margin-bottom:16px;"><i class="fa-solid fa-circle-exclamation"></i></div>
            <h3 style="color:var(--danger);margin-bottom:8px;">授权已过期</h3>
            <p style="color:var(--gray-600);font-size:14px;">
                到期时间：<?= htmlspecialchars($status['expires_at'] ?? '-') ?><br>
                授权类型：<?= $licenseTypeLabels[$status['license_type']] ?? $status['license_type'] ?>
            </p>
            <p style="color:var(--gray-500);font-size:13px;margin-top:8px;">部分功能已受限，请联系代理商续费获取新的授权码</p>
        </div>
        <?php else: ?>
        <div style="padding:8px 0;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;background:#d1fae5;border-radius:50%;">
                    <i class="fa-solid fa-check" style="color:var(--success);font-size:22px;"></i>
                </span>
                <div>
                    <span class="badge badge-success" style="font-size:14px;padding:4px 12px;">已激活</span>
                    <?php if (!empty($status['degraded'])): ?>
                    <span class="badge badge-warning" style="font-size:12px;">离线模式</span>
                    <?php endif; ?>
                </div>
            </div>
            <table style="width:100%;max-width:500px;">
                <tr><td style="padding:6px 12px 6px 0;color:var(--gray-500);width:100px;">授权类型</td><td style="padding:6px 0;font-weight:600;"><?= $licenseTypeLabels[$status['license_type']] ?? $status['license_type'] ?></td></tr>
                <tr><td style="padding:6px 12px 6px 0;color:var(--gray-500);">到期时间</td><td style="padding:6px 0;font-weight:600;"><?= htmlspecialchars($expiresText) ?></td></tr>
                <tr><td style="padding:6px 12px 6px 0;color:var(--gray-500);">绑定域名</td><td style="padding:6px 0;font-weight:600;"><?= htmlspecialchars(license_get_domain()) ?></td></tr>
                <tr><td style="padding:6px 12px 6px 0;color:var(--gray-500);">激活ID</td><td style="padding:6px 0;font-weight:600;font-family:monospace;"><?= htmlspecialchars($config['activation_id']) ?></td></tr>
                <tr><td style="padding:6px 12px 6px 0;color:var(--gray-500);">授权功能</td>
                    <td style="padding:6px 0;">
                        <?php if (!empty($status['features'])): ?>
                        <?php foreach ($status['features'] as $key => $name): ?>
                        <span class="badge badge-primary" style="margin:2px;"><?= htmlspecialchars($name) ?></span>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <span style="color:var(--gray-500);">全部功能</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <div style="margin-top:16px;display:flex;gap:8px;">
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh">
                    <button type="submit" class="btn btn-outline"><i class="fa-solid fa-rotate"></i> 刷新状态</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('确定要解绑此授权吗？解绑后需要新的授权码才能重新激活。')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deactivate">
                    <button type="submit" class="btn btn-outline" style="color:var(--danger);border-color:var(--danger);"><i class="fa-solid fa-link-slash"></i> 解绑授权</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 激活表单（未激活或过期时显示） -->
<?php if ($status['status'] === 'unactivated' || $status['status'] === 'expired'): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fa-solid fa-key"></i> <?= $status['status'] === 'expired' ? '重新激活' : '激活授权' ?></h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="activate">
            <div class="form-group">
                <label class="form-label">授权码 <span class="required">*</span></label>
                <input type="text" name="license_key" class="form-control" placeholder="XXXXX-XXXXX-XXXXX-XXXXX" required style="font-family:monospace;font-size:16px;letter-spacing:2px;text-transform:uppercase;max-width:400px;">
                <small style="color:var(--gray-500);">请输入从代理商处获取的授权码</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">客户姓名</label>
                    <input type="text" name="customer_name" class="form-control" placeholder="请输入您的姓名" maxlength="50">
                </div>
                <div class="form-group">
                    <label class="form-label">手机号</label>
                    <input type="text" name="customer_phone" class="form-control" placeholder="请输入您的手机号" maxlength="20">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">公司名称</label>
                <input type="text" name="customer_company" class="form-control" placeholder="请输入公司名称（选填）" maxlength="100">
            </div>
            <div class="form-group" style="padding:10px 14px;background:var(--gray-100);border-radius:var(--radius);max-width:400px;">
                <label class="form-label" style="margin-bottom:4px;">当前域名</label>
                <code style="font-size:14px;"><?= htmlspecialchars(license_get_domain()) ?></code>
                <small style="display:block;color:var(--gray-500);">授权将绑定到此域名</small>
            </div>
            <div style="margin-top:16px;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-unlock"></i> 激活授权</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 授权服务器配置提示 -->
<?php if (empty($config['server_url']) || empty($config['api_key'])): ?>
<div class="card" style="margin-top:20px;border-color:var(--warning);">
    <div class="card-header" style="background:#fffbeb;">
        <h3 class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warning)"></i> 需要配置</h3>
    </div>
    <div class="card-body">
        <p style="margin-bottom:12px;">激活授权前，需要先在系统设置中配置授权服务器的连接信息：</p>
        <ol style="padding-left:20px;color:var(--gray-600);line-height:2;">
            <li>从代理商处获取 <strong>授权服务器地址</strong>、<strong>API Key</strong> 和 <strong>API Secret</strong></li>
            <li>前往 <a href="settings.php">系统设置</a> 页面填写上述信息并保存</li>
            <li>返回本页面进行授权激活</li>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
