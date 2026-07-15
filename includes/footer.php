        </div><!-- /content-wrapper -->
<?php if (!isset($isAjaxNav) || !$isAjaxNav): ?>
<?php
// 调试面板：URL 带 ?debug=1 时显示当前权限诊断信息
if (isset($_SESSION['user_id']) && !empty($_GET['debug'])) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, name, permissions FROM roles WHERE id = ?");
        $stmt->execute([$_SESSION['role_id'] ?? 0]);
        $dbRole = $stmt->fetch();
    } catch (Exception $e) {
        $dbRole = false;
    }
    $dbPerms = $dbRole ? json_decode($dbRole['permissions'] ?: '[]', true) : [];
    $menuDebug = [];
    foreach (get_menu() as $menu) {
        if (!empty($menu['perm'])) {
            $menuDebug[$menu['name']] = check_permission($menu['perm']);
        }
        if (isset($menu['children'])) {
            foreach ($menu['children'] as $child) {
                if (!empty($child['perm'])) {
                    $menuDebug[$child['name']] = check_permission($child['perm']);
                }
            }
        }
    }
?>
<div style="margin-top:40px;padding:16px;background:#1a1a2e;color:#eee;border-radius:8px;font-family:monospace;font-size:12px;line-height:1.6;">
    <h4 style="margin-top:0;color:#4cc9f0;">🔍 权限调试信息</h4>
    <div>user_id: <?= isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'N/A' ?></div>
    <div>user_name: <?= isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'N/A' ?></div>
    <div>user_role: <?= isset($_SESSION['user_role']) ? htmlspecialchars($_SESSION['user_role']) : 'N/A' ?></div>
    <div>role_id: <?= isset($_SESSION['role_id']) ? $_SESSION['role_id'] : 'N/A' ?></div>
    <div>session_perms: <?= htmlspecialchars(json_encode($_SESSION['permissions'] ?? [])) ?></div>
    <div>db_role_id: <?= $dbRole ? $dbRole['id'] : '未找到' ?></div>
    <div>db_role_name: <?= $dbRole ? htmlspecialchars($dbRole['name']) : '未找到' ?></div>
    <div>db_perms: <?= htmlspecialchars(json_encode($dbPerms)) ?></div>
    <hr style="border-color:#444;margin:12px 0;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
        <?php foreach ($menuDebug as $name => $allowed): ?>
        <div><span style="color:<?= $allowed ? '#2ecc71' : '#e74c3c' ?>"><?= $allowed ? '✓' : '✗' ?></span> <?= htmlspecialchars($name) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php } ?>
    </main><!-- /main-content -->
</div><!-- /app-container -->

<script src="<?= $basePath ?? '' ?>assets/js/main.js"></script>
<script src="<?= defined('CDN_CHARTJS') ? CDN_CHARTJS : 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js' ?>"></script>
</body>
</html>
<?php endif; ?>
