<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_setting');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $poolDays = intval($_POST['crm_pool_days'] ?? 30);
    $claimHours = intval($_POST['crm_claim_hours'] ?? 72);
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('crm_pool_days', ?, '公海自动回收天数') ON DUPLICATE KEY UPDATE setting_value=?");
    $stmt->execute([$poolDays, $poolDays]);
    $stmt2 = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('crm_claim_hours', ?, '认领后未跟进强制回收小时数') ON DUPLICATE KEY UPDATE setting_value=?");
    $stmt2->execute([$claimHours, $claimHours]);
    $success = '设置已保存';
}

$poolDays = intval($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='crm_pool_days'")->fetchColumn() ?: 30);
// 优先读取小时设置，兼容旧的 crm_claim_days（天数x24）
$claimHours = intval($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='crm_claim_hours'")->fetchColumn() ?: 0);
if ($claimHours <= 0) {
    $oldDays = intval($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='crm_claim_days'")->fetchColumn() ?: 0);
    $claimHours = $oldDays > 0 ? $oldDays * 24 : 72;
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-gear"></i> 公海设置</h1>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?=$success?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h3 class="card-title">自动回收规则</h3></div>
    <div class="card-body">
        <form method="post">
            <?=csrf_field()?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">常规自动回收天数</label>
                    <input type="number" name="crm_pool_days" class="form-control" value="<?=$poolDays?>" min="1" style="width:200px;">
                    <small style="color:var(--gray-500);">已有跟进记录的客户，超过此天数未跟进将自动归入公海。设为0关闭。</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">认领后未跟进强制回收小时数</label>
                    <input type="number" name="crm_claim_hours" class="form-control" value="<?=$claimHours?>" min="0" style="width:200px;">
                    <small style="color:var(--gray-500);">认领客户后在此小时数内没有写跟进内容，强制放回公海。设0表示认领后不写跟进也会被常规回收规则处理。默认72小时（3天）。</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">保存设置</button>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h3 class="card-title">说明</h3></div>
    <div class="card-body">
        <ul style="line-height:2;">
            <li><strong>自动回收</strong>：每次有人访问公海页面时会检查，超过设定天数未跟进的客户会自动归入公海</li>
            <li><strong>主动放弃</strong>：业务经理可在客户列表点击"公海"按钮主动放弃客户</li>
            <li><strong>管理员强制</strong>：管理员可在客户详情中强制将客户归入公海或分配给其他业务经理</li>
            <li><strong>公海认领</strong>：公海中的客户，任何业务经理都可以认领</li>
            <li><strong>认领后回收</strong>：认领后如果未在设定小时数内写跟进，系统会将该客户强制放回公海，防止占着不跟</li>
            <li><strong>跟进记录保留</strong>：归入公海后，原业务经理的跟进记录全部保留</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
