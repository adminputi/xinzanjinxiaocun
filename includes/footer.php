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
    var lastSig = '';

    function countDue(list) {
        var n = 0;
        for (var i = 0; i < list.length; i++) { if (list[i].due) n++; }
        return n;
    }

    // 计划时间显示：历史数据只有日期（00:00:00），此时只显示日期，避免看起来像凌晨跟进
    function fmtPlan(s) {
        return window.fmtPlanFollow ? window.fmtPlanFollow(s) : String(s || '');
    }

    function checkReminders() {
        if (dismissed) return;
        // 用绝对路径（site_url），不能用 ../ 这种相对路径：
        // 弹窗在首屏渲染时就生成了，之后 AJAX 导航会把地址栏改成 /modules/crm/xxx.php，
        // 相对路径会再拼一层，变成 /modules/crm/modules/crm/... （后退回首页又正常）
        fetch('<?= site_url('modules/crm/ajax.php') ?>?action=check_reminders&_=' + Date.now())
        .then(function(r){return r.json();})
        .then(function(resp){
            if (!resp.success || !resp.data || !resp.data.length) {
                // 没有待跟进客户时，移除之前的弹窗
                var old = document.getElementById('followupReminder');
                if (old) old.remove();
                lastSig = '';
                return;
            }
            // 条数 + 到点条数一起作为签名：到点状态变化时要重新渲染（绿框变红框）
            var sig = resp.data.length + ':' + countDue(resp.data);
            if (sig === lastSig) return; // 没变化不刷新
            lastSig = sig;
            showPopup(resp.data);
        })
        .catch(function(){});
    }

    function showPopup(customers) {
        var old = document.getElementById('followupReminder');
        if (old) old.remove();

        var count = customers.length;
        var dueCount = countDue(customers);   // 已到计划时间（含逾期）的条数
        var isDue = dueCount > 0;
        var itemsHtml = '';
        // 最多显示5条
        var showList = customers.slice(0, 5);
        for (var i = 0; i < showList.length; i++) {
            var c = showList[i];
            itemsHtml += '<div style="font-size:12px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.15);">'
                + '<a href="<?= site_url('modules/crm/customer_detail.php') ?>?id=' + c.id + '" style="color:#fff;text-decoration:underline;" target="_blank">'
                + escHtml(c.name) + '</a>'
                + ' <span style="opacity:.7;">计划 ' + fmtPlan(c.next_follow_at) + '</span>'
                + (c.due ? ' <span style="background:rgba(255,255,255,.25);border-radius:4px;padding:0 4px;font-size:11px;">已到点</span>' : '')
                + '</div>';
        }
        var moreHint = count > 5 ? '<div style="font-size:11px;opacity:.7;text-align:center;margin-top:4px;">+还有' + (count - 5) + '个客户...</div>' : '';

        // 没到点的显示为绿色（今日计划），有到点/逾期的变红色（需要马上处理）
        var bg = isDue ? '#c0392b' : '#27ae60';
        var shadow = isDue ? 'rgba(192,57,43,.45)' : 'rgba(39,174,96,.45)';
        var title = isDue ? '⚠ 跟进提醒' : '📅 今日跟进计划';
        var summary = isDue
            ? '您有 <b>' + dueCount + '</b> 位客户已到跟进时间' + (count > dueCount ? '，另有 <b>' + (count - dueCount) + '</b> 位今日待跟进' : '') + '：'
            : '您有 <b>' + count + '</b> 位客户今日需要跟进：';

        var html = '<div id="followupReminder" style="position:fixed;bottom:24px;right:24px;z-index:99999;background:' + bg + ';color:#fff;border-radius:10px;padding:14px 18px;max-width:360px;min-width:280px;box-shadow:0 6px 24px ' + shadow + ';animation:frmSlideIn .3s ease;">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'
            + '<strong style="font-size:14px;">' + title + '</strong>'
            + '<button onclick="var e=document.getElementById(\'followupReminder\');if(e)e.remove();dismissed=true;" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;opacity:.7;">×</button>'
            + '</div>'
            + '<div style="font-size:12px;margin-bottom:8px;opacity:.9;">' + summary + '</div>'
            + itemsHtml
            + moreHint
            + '<div style="margin-top:10px;text-align:center;">'
            // 「待跟进」= 计划跟进时间，跳跟进记录页的「今日待跟进」Tab；
            // customers.php 的 follow_to 过滤的是「最后跟进日期」，语义对不上
            + '<a href="<?= site_url('modules/crm/followups.php') ?>?tab=today_pending" style="color:#ffeaa7;font-size:12px;">查看全部待跟进 →</a>'
            + '</div>'
            + '</div>';
        document.body.insertAdjacentHTML('beforeend', html);
    }

    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
