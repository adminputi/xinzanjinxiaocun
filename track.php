<?php
/**
 * 售后追踪公开页面（扫码查看）
 * 无需登录，密码保护
 */
require_once __DIR__ . '/config/database.php';
$code = trim($_GET['code'] ?? '');
$pdo = getDB();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>售后追踪查询</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:#f5f7fa;color:#333;min-height:100vh;}
        :root{--primary:#4f46e5;--primary-light:#818cf8;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-300:#d1d5db;--gray-500:#6b7280;--gray-700:#374151;}

        /* 密码验证页 */
        .pwd-container{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
        .pwd-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);padding:32px 24px;width:100%;max-width:380px;text-align:center;}
        .pwd-card .icon{font-size:48px;margin-bottom:16px;}
        .pwd-card h2{font-size:20px;margin-bottom:8px;color:var(--gray-700);}
        .pwd-card p{font-size:14px;color:var(--gray-500);margin-bottom:24px;}
        .pwd-card input{width:100%;padding:10px 14px;border:2px solid var(--gray-200);border-radius:8px;font-size:16px;text-align:center;letter-spacing:4px;outline:none;transition:border-color .2s;}
        .pwd-card input:focus{border-color:var(--primary);}
        .pwd-card button{width:100%;padding:10px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;margin-top:16px;transition:background .2s;}
        .pwd-card button:hover{background:var(--primary-light);}
        .pwd-card .error{color:var(--danger);font-size:13px;margin-top:12px;display:none;}

        /* 内容页 */
        .track-page{max-width:720px;margin:0 auto;padding:16px;}
        .track-header{background:linear-gradient(135deg,var(--primary),#7c3aed);color:#fff;border-radius:12px;padding:20px;margin-bottom:16px;text-align:center;}
        .track-header h1{font-size:18px;font-weight:600;}
        .track-header .tracking-no{font-size:13px;opacity:.85;margin-top:4px;}
        .track-header .status-badge{display:inline-block;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:13px;margin-top:8px;}

        /* 信息区块 */
        .info-section{background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);}
        .info-section h3{font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);display:flex;align-items:center;gap:6px;}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;}
        .info-grid .item{display:flex;}
        .info-grid .label{color:var(--gray-500);white-space:nowrap;margin-right:4px;}
        .info-grid .value{color:var(--gray-700);word-break:break-all;}

        /* 产品列表 */
        .product-table{width:100%;font-size:13px;border-collapse:collapse;}
        .product-table th{text-align:left;padding:6px 8px;border-bottom:2px solid var(--gray-200);color:var(--gray-500);font-weight:500;}
        .product-table td{padding:6px 8px;border-bottom:1px solid var(--gray-100);}

        /* 时间线 */
        .timeline{position:relative;padding-left:24px;}
        .timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:var(--gray-200);}
        .timeline-item{position:relative;padding-bottom:16px;}
        .timeline-item::before{content:'';position:absolute;left:-20px;top:4px;width:12px;height:12px;border-radius:50%;background:var(--gray-300);border:2px solid #fff;box-shadow:0 0 0 2px var(--gray-300);}
        .timeline-item.newest::before{background:var(--danger);box-shadow:0 0 0 2px var(--danger);}
        .timeline-item .time{font-size:11px;color:var(--gray-500);}
        .timeline-item .content{font-size:14px;color:var(--gray-700);margin-top:2px;}
        .timeline-item.newest .content{color:var(--danger);font-weight:bold;}
        .timeline-item img{max-width:100px;max-height:100px;margin-top:4px;border-radius:4px;border:1px solid var(--gray-200);}

        /* 售后时间线 */
        .as-timeline-item{position:relative;padding-bottom:16px;padding-left:18px;border-left:2px solid var(--warning);margin-bottom:8px;}
        .as-timeline-item .time{font-size:11px;color:var(--gray-500);}
        .as-timeline-item .content{font-size:14px;color:var(--gray-700);margin-top:2px;}
        .as-timeline-item img{max-width:100px;max-height:100px;margin-top:4px;border-radius:4px;border:1px solid var(--gray-200);}

        /* 响应式 */
        @media(max-width:480px){
            .info-grid{grid-template-columns:1fr;}
            .track-page{padding:12px;}
        }

        /* 图片放大预览 */
        .img-lightbox{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:10000;align-items:center;justify-content:center;}
        .img-lightbox.show{display:flex;}
        .img-lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);cursor:zoom-out;}

        /* 密码错误动画 */
        @keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
        .shake{animation:shake .3s ease-in-out;}
    </style>
</head>
<body>

<?php if (!$code): ?>
<!-- 无查询码 -->
<div class="pwd-container">
    <div class="pwd-card">
        <div class="icon">🔍</div>
        <h2>售后追踪查询</h2>
        <p>请输入追踪码进行查询</p>
        <input type="text" id="codeInput" placeholder="输入追踪码" maxlength="50" style="letter-spacing:0;">
        <button onclick="location.href='?code='+encodeURIComponent(document.getElementById('codeInput').value.trim())">查询</button>
    </div>
</div>

<?php else: ?>
<!-- 密码验证 -->
<div id="passwordPage" class="pwd-container">
    <div class="pwd-card">
        <div class="icon">🔒</div>
        <h2>验证密码</h2>
        <p>追踪码：<strong><?=htmlspecialchars($code)?></strong></p>
        <input type="password" id="pwdInput" placeholder="请输入查询密码" maxlength="20" onkeydown="if(event.key==='Enter')verifyPwd()">
        <button onclick="verifyPwd()">验证</button>
        <div class="error" id="pwdError">密码错误，请重试</div>
    </div>
</div>

<!-- 追踪详情（初始隐藏） -->
<div id="detailPage" style="display:none;">
    <div class="track-page">
        <div class="track-header">
            <h1 id="dStatusName">--</h1>
            <div class="tracking-no" id="dTrackNo">--</div>
            <div class="status-badge" id="dStatusBadge">--</div>
        </div>
        <div id="dFieldsSection" class="info-section" style="display:none;">
            <h3>📋 基本信息</h3>
            <div class="info-grid" id="dFieldsGrid"></div>
        </div>
        <div class="info-section">
            <h3>📦 追踪产品</h3>
            <table class="product-table"><thead><tr><th>商品</th><th>SKU</th><th>规格</th><th>数量</th></tr></thead><tbody id="dItemsBody"></tbody></table>
        </div>
        <div class="info-section">
            <h3>🔄 流程信息</h3>
            <div id="dProcesses"></div>
        </div>
        <?php if (!empty($code)): // after-sales person will be hidden if empty ?>
        <div class="info-section" id="dAfterSalesPersonSection" style="display:none;">
            <h3>👤 售后人员</h3>
            <div id="dAfterSalesPerson"></div>
        </div>
        <?php endif; ?>
        <div class="info-section" id="dAfterSalesSection" style="display:none;">
            <h3>🎧 售后信息</h3>
            <div id="dAfterSales"></div>
        </div>
    </div>
</div>

<!-- 图片放大预览 -->
<div class="img-lightbox" id="imgLightbox" onclick="this.classList.remove('show')">
    <img id="imgLightboxImg" onclick="event.stopPropagation()">
</div>
<?php endif; ?>

<script>
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function showImageLightbox(src){
    document.getElementById('imgLightboxImg').src = src;
    document.getElementById('imgLightbox').classList.add('show');
}
<?php if ($code): ?>
var trackingCode = <?=json_encode($code)?>;

function verifyPwd(){
    var pwd = document.getElementById('pwdInput').value.trim();
    if(!pwd){document.getElementById('pwdError').style.display='block';return;}
    fetch('modules/after_sales/ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=verify_password&code='+encodeURIComponent(trackingCode)+'&pwd='+encodeURIComponent(pwd)})
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){
            document.getElementById('passwordPage').style.display='none';
            loadDetail();
        }else{
            var err=document.getElementById('pwdError');
            err.style.display='block';
            var card=document.querySelector('.pwd-card');
            card.classList.add('shake');
            setTimeout(function(){card.classList.remove('shake');},300);
        }
    }).catch(function(){alert('请求失败，请稍后重试');});
}

function loadDetail(){
    fetch('modules/after_sales/ajax.php?action=get_tracking_public&code='+encodeURIComponent(trackingCode))
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var d=resp.data;
        document.getElementById('detailPage').style.display='block';
        document.getElementById('dTrackNo').textContent='追踪码：'+d.tracking_no;
        document.getElementById('dStatusName').textContent='售后追踪';
        document.getElementById('dStatusBadge').textContent=d.status_name||'--';

        // 基本信息
        var td=d.tracking_data||{};
        if(Object.keys(td).length>0){
            document.getElementById('dFieldsSection').style.display='block';
            var grid=document.getElementById('dFieldsGrid');
            grid.innerHTML='';
            for(var k in td){
                if(!td[k].value) continue;
                grid.innerHTML+='<div class="item"><span class="label">'+escHtml(td[k].label||k)+'：</span><span class="value">'+escHtml(td[k].value||'')+'</span></div>';
            }
        }

        // 产品
        var itemsHtml='';
        if(d.items&&d.items.length>0){
            d.items.forEach(function(it){
                itemsHtml+='<tr><td>'+escHtml(it.product_name)+'</td><td>'+escHtml(it.sku)+'</td><td>'+escHtml(it.spec)+'</td><td>'+it.quantity+'</td></tr>';
            });
        }else{
            itemsHtml='<tr><td colspan="4" style="color:var(--gray-500);text-align:center;padding:12px;">暂无产品数据</td></tr>';
        }
        document.getElementById('dItemsBody').innerHTML=itemsHtml;

        // 流程
        var procHtml='';
        if(d.processes&&d.processes.length>0){
            procHtml+='<div class="timeline">';
            d.processes.forEach(function(p){
                var isNew=p.is_newest==1;
                procHtml+='<div class="timeline-item'+(isNew?' newest':'')+'">';
                procHtml+='<div class="time">'+p.created_at+'</div>';
                procHtml+='<div class="content">'+(p.status_name?'【'+p.status_name+'】':'')+(p.content||'')+'</div>';
                if(p.images&&p.images.length>0){
                    p.images.forEach(function(img){
                        procHtml+='<img src="'+escHtml(img)+'" onclick="showImageLightbox(this.src)" style="cursor:pointer;">';
                    });
                }
                procHtml+='</div>';
            });
            procHtml+='</div>';
        }else{
            procHtml='<p style="color:var(--gray-500);font-size:14px;">暂无流程记录</p>';
        }
        document.getElementById('dProcesses').innerHTML=procHtml;

        // 售后人员
        if(d.after_sales_person){
            document.getElementById('dAfterSalesPersonSection').style.display='block';
            document.getElementById('dAfterSalesPerson').innerHTML='<div class="info-grid"><div class="item"><span class="label">姓名：</span><span class="value">'+escHtml(d.after_sales_person.name||'')+'</span></div><div class="item"><span class="label">电话：</span><span class="value">'+escHtml(d.after_sales_person.phone||'')+'</span></div></div>';
        }

        // 售后信息
        if(d.after_sales&&d.after_sales.length>0){
            document.getElementById('dAfterSalesSection').style.display='block';
            var asHtml='';
            d.after_sales.forEach(function(a){
                asHtml+='<div class="as-timeline-item">';
                asHtml+='<div class="time">'+a.created_at+'</div>';
                asHtml+='<div class="content">'+(a.content||'')+'</div>';
                if(a.images&&a.images.length>0){
                    a.images.forEach(function(img){
                        asHtml+='<img src="'+escHtml(img)+'" onclick="showImageLightbox(this.src)" style="cursor:pointer;">';
                    });
                }
                asHtml+='</div>';
            });
            document.getElementById('dAfterSales').innerHTML=asHtml;
        }
    }).catch(function(){alert('加载失败');});
}
<?php endif; ?>
</script>
</body>
</html>
