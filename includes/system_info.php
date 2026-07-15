<div class="sysinfo-grid">
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-circle-info"></i> 系统信息</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 3fr;gap:8px;font-size:13px;">
            <div style="color:var(--gray-500);">系统版本：</div><div>V1.2</div>
            <div style="color:var(--gray-500);">PHP版本：</div><div><?=phpversion()?></div>
            <div style="color:var(--gray-500);">数据库版本：</div><div><?=getDB()->query("SELECT VERSION()")->fetchColumn()?></div>
            <div style="color:var(--gray-500);">服务器时间：</div><div><?=date('Y-m-d H:i:s')?></div>
            <div style="color:var(--gray-500);">上传目录：</div><div><?=UPLOAD_DIR?></div>
            <div style="color:var(--gray-500);">安装时间：</div><div><?=file_exists(__DIR__.'/../config/installed.lock')?file_get_contents(__DIR__.'/../config/installed.lock'):'--'?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-code"></i> 开发信息</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 3fr;gap:8px;font-size:13px;">
            <div style="color:var(--gray-500);">系统名称：</div><div><strong>鑫瓒进销存管理系统 1.2</strong></div>
            <div style="color:var(--gray-500);">官方网站：</div><div><a href="https://www.92q.net" target="_blank" style="color:var(--primary);">www.92q.net</a></div>
            <div style="color:var(--gray-500);">系统开发：</div><div>菩提</div>
            <div style="color:var(--gray-500);">技术交流：</div><div>QQ：<a href="tencent://message/?uin=33305222" style="color:var(--primary);">33305222</a></div>
        </div>
    </div>
</div>
</div>
