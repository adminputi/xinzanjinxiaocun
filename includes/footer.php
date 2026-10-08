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

<?php $mainJs = __DIR__ . '/../assets/js/main.js'; ?>
<?php $printImageJs = __DIR__ . '/../assets/js/print-image.js'; ?>
<script src="<?= $basePath ?? '' ?>assets/js/print-image.js?v=<?= file_exists($printImageJs) ? filemtime($printImageJs) : time() ?>"></script>
<script src="<?= $basePath ?? '' ?>assets/js/main.js?v=<?= file_exists($mainJs) ? filemtime($mainJs) : time() ?>"></script>
<script src="<?= $basePath ?? '' ?>assets/js/chart.js/chart.umd.min.js"></script>
<script>
// 页面内联脚本位于本文件之前，浏览器尚未执行；main.js 已就绪，这里立即执行它们
// （并初始化可搜索下拉/AJAX导航/表单重绑），避免首屏页面功能未定义
(function(){
    if (window.__bootApp) { window.__bootApp(); }
})();
</script>
<?php if (isset($_SESSION['user_id'])): ?>
<script>
// ========== 跟进提醒轮询 ==========
(function(){
    var dismissed = false;
    var lastCount = 0;

    function checkReminders() {
        if (dismissed) return;
        fetch('<?= $basePath ?? '' ?>modules/crm/ajax.php?action=check_reminders&_=' + Date.now())
        .then(function(r){return r.json();})
        .then(function(resp){
            if (!resp.success || !resp.data || !resp.data.length) {
                // 没有待跟进客户时，移除之前的弹窗
                var old = document.getElementById('followupReminder');
                if (old) old.remove();
                lastCount = 0;
                return;
            }
            if (resp.data.length === lastCount) return; // 没变化不刷新
            lastCount = resp.data.length;
            showPopup(resp.data);
        })
        .catch(function(){});
    }

    function showPopup(customers) {
        var old = document.getElementById('followupReminder');
        if (old) old.remove();

        var count = customers.length;
        var itemsHtml = '';
        // 最多显示5条
        var showList = customers.slice(0, 5);
        for (var i = 0; i < showList.length; i++) {
            var c = showList[i];
            itemsHtml += '<div style="font-size:12px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.15);">'
                + '<a href="<?= $basePath ?? '' ?>modules/crm/customer_detail.php?id=' + c.id + '" style="color:#fff;text-decoration:underline;" target="_blank">'
                + escHtml(c.name) + '</a>'
                + ' <span style="opacity:.7;">计划 ' + (c.next_follow_at || '') + '</span>'
                + '</div>';
        }
        var moreHint = count > 5 ? '<div style="font-size:11px;opacity:.7;text-align:center;margin-top:4px;">+还有' + (count - 5) + '个客户...</div>' : '';

        var html = '<div id="followupReminder" style="position:fixed;bottom:24px;right:24px;z-index:99999;background:#c0392b;color:#fff;border-radius:10px;padding:14px 18px;max-width:360px;min-width:280px;box-shadow:0 6px 24px rgba(192,57,43,.45);animation:frmSlideIn .3s ease;">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'
            + '<strong style="font-size:14px;">⚠ 跟进提醒</strong>'
            + '<button onclick="var e=document.getElementById(\'followupReminder\');if(e)e.remove();dismissed=true;" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;opacity:.7;">×</button>'
            + '</div>'
            + '<div style="font-size:12px;margin-bottom:8px;opacity:.9;">您有 <b>' + count + '</b> 位客户今日需要跟进：</div>'
            + itemsHtml
            + moreHint
            + '<div style="margin-top:10px;text-align:center;">'
            + '<a href="<?= $basePath ?? '' ?>modules/crm/customers.php?follow_to=' + todayStr() + '" style="color:#ffeaa7;font-size:12px;">查看全部待跟进 →</a>'
            + '</div>'
            + '</div>';
        document.body.insertAdjacentHTML('beforeend', html);
    }

    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    // 动画
    var style = document.createElement('style');
    style.textContent = '@keyframes frmSlideIn{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}';
    document.head.appendChild(style);

    // 页面加载 2 秒后首次检查
    setTimeout(checkReminders, 2000);
    // 每 5 分钟检查一次
    setInterval(checkReminders, 5 * 60 * 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
<?php endif; ?>
