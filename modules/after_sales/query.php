<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('master_data');
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-search"></i> 追踪码查询</h1>
    <div style="display:flex;gap:8px;">
        <a href="list.php" class="btn btn-outline"><i class="fa-solid fa-list"></i> 追踪码管理</a>
        <a href="create.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 生成追踪码</a>
    </div>
</div>

<div class="card">
    <div class="card-body" style="text-align:center;padding:32px;">
        <div style="font-size:48px;margin-bottom:16px;">🔍</div>
        <h3 style="margin-bottom:8px;">输入追踪码查询</h3>
        <p style="color:var(--gray-600);margin-bottom:20px;">输入追踪码后可查看完整的追踪信息</p>
        <div style="display:flex;gap:8px;max-width:500px;margin:0 auto;">
            <input type="text" id="queryCode" class="form-control" placeholder="请输入追踪码（如 ZS20260708XXXX）" style="text-align:center;font-size:16px;" onkeydown="if(event.key==='Enter')doQuery()">
            <button class="btn btn-primary" onclick="doQuery()">查询</button>
        </div>
        <div id="queryResult" style="margin-top:24px;text-align:left;max-height:70vh;overflow-y:auto;"></div>
    </div>
</div>

<!-- 图片放大预览弹窗 -->
<div class="modal-overlay" id="imgLightbox" onclick="closeModal('imgLightbox')" style="z-index:10000;">
<div style="max-width:90vw;max-height:90vh;display:flex;align-items:center;justify-content:center;">
    <img id="imgLightboxImg" style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.3);cursor:zoom-out;" onclick="event.stopPropagation();closeModal('imgLightbox')">
</div></div>

<script>
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
var fieldLabelMap={
    'bill_no':'出库单号','outstock_date':'出库日期','customer_name':'客户姓名','customer_phone':'客户电话',
    'customer_address':'客户地址','warehouse_name':'发货仓库','receiver_name':'接货人','receiver_phone':'接货人电话',
    'salesperson_name':'业务经理','salesperson_phone':'业务经理电话','order_bill_no':'销售订单号',
    'total_amount':'总金额','remark':'备注','outstock_remark':'出库备注',
    'production_date':'生产日期','delivery_time':'发货时间',
    'delivery_method':'发货方式','sender_name':'发货人','driver_name':'拉货司机姓名','driver_phone':'拉货司机电话'
};

function doQuery(){
    var code = document.getElementById('queryCode').value.trim();
    if(!code){alert('请输入追踪码');return;}
    var container = document.getElementById('queryResult');
    container.innerHTML='<div style="text-align:center;padding:20px;color:var(--gray-600);">查询中...</div>';

    fetch('ajax.php?action=get_tracking_public&code='+encodeURIComponent(code))
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){container.innerHTML='<div class="alert alert-danger">'+resp.message+'</div>';return;}
        var d=resp.data;
        var html='<div style="padding:12px;">';

        // 头部
        html+='<div style="background:linear-gradient(135deg,var(--primary),#7c3aed);color:#fff;border-radius:10px;padding:16px;margin-bottom:16px;text-align:center;">';
        html+='<div style="font-size:18px;font-weight:bold;">售后追踪</div>';
        html+='<div style="font-size:14px;opacity:.85;margin-top:4px;">追踪码：'+escHtml(d.tracking_no)+'</div>';
        html+='<div style="display:inline-block;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:13px;margin-top:8px;">'+(d.status_name||'未设置')+'</div>';
        html+='</div>';

        // 基本信息（展示字段）
        var td=d.tracking_data||{};
        if(Object.keys(td).length>0){
            html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
            html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">📋 详细信息</h4>';
            html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;">';
            for(var k in td){
                if(!td.hasOwnProperty(k)) continue;
                if(!td[k].value) continue;
                var label = td[k].label || fieldLabelMap[k] || k;
                html+='<div style="display:flex;"><span style="color:var(--gray-500);white-space:nowrap;margin-right:4px;">'+escHtml(label)+'：</span><span style="color:var(--gray-700);word-break:break-all;">'+escHtml(td[k].value||'')+'</span></div>';
            }
            html+='</div></div>';
        }

        // 产品
        if(d.items&&d.items.length>0){
            html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
            html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">📦 追踪产品</h4>';
            html+='<table style="width:100%;font-size:13px;border-collapse:collapse;"><thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:2px solid var(--gray-200);">商品</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid var(--gray-200);">SKU</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid var(--gray-200);">规格</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid var(--gray-200);">数量</th></tr></thead><tbody>';
            d.items.forEach(function(it){
                html+='<tr><td style="padding:6px 8px;border-bottom:1px solid var(--gray-100);">'+escHtml(it.product_name)+'</td><td style="padding:6px 8px;border-bottom:1px solid var(--gray-100);">'+escHtml(it.sku)+'</td><td style="padding:6px 8px;border-bottom:1px solid var(--gray-100);">'+escHtml(it.spec)+'</td><td style="padding:6px 8px;border-bottom:1px solid var(--gray-100);">'+it.quantity+'</td></tr>';
            });
            html+='</tbody></table></div>';
        }

        // 流程
        html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
        html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">🔄 流程信息</h4>';
        if(d.processes&&d.processes.length>0){
            html+='<div style="position:relative;padding-left:24px;">';
            html+='<div style="content:\'\';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:var(--gray-200);"></div>';
            d.processes.forEach(function(p){
                var isNew=p.is_newest==1;
                var dotColor = isNew?'var(--danger)':'var(--gray-300)';
                html+='<div style="position:relative;padding-bottom:16px;">';
                html+='<div style="position:absolute;left:-20px;top:4px;width:12px;height:12px;border-radius:50%;background:'+dotColor+';border:2px solid #fff;box-shadow:0 0 0 2px '+dotColor+';"></div>';
                html+='<div style="font-size:12px;color:var(--gray-500);">'+escHtml(p.created_at)+'</div>';
                html+='<div style="font-size:14px;color:'+(isNew?'var(--danger)':'var(--gray-700)')+';font-weight:'+(isNew?'bold':'normal')+';margin-top:2px;">'+(p.status_name?'【'+escHtml(p.status_name)+'】 ':'')+escHtml(p.content||'')+'</div>';
                if(p.images&&p.images.length>0){
                    p.images.forEach(function(img){
                        html+='<img src="../../'+escHtml(img)+'" style="max-width:100px;max-height:100px;margin-top:4px;border-radius:4px;border:1px solid var(--gray-200);cursor:pointer;" onclick="showImageLightbox(this.src)">';
                    });
                }
                html+='</div>';
            });
            html+='</div>';
        }else{html+='<p style="color:var(--gray-500);font-size:14px;">暂无流程记录</p>';}
        html+='</div>';

        // 售后人员
        if(d.after_sales_person){
            html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
            html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">👤 售后人员</h4>';
            html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;">';
            html+='<div><span style="color:var(--gray-500);">姓名：</span>'+escHtml(d.after_sales_person.name||'')+'</div>';
            html+='<div><span style="color:var(--gray-500);">电话：</span>'+escHtml(d.after_sales_person.phone||'')+'</div>';
            html+='</div></div>';
        }

        // 售后信息
        html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
        html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">🎧 售后信息</h4>';
        if(d.after_sales&&d.after_sales.length>0){
            d.after_sales.forEach(function(a){
                html+='<div style="padding:8px 0;border-left:2px solid var(--warning);padding-left:18px;margin-bottom:8px;">';
                html+='<div style="font-size:12px;color:var(--gray-500);">'+escHtml(a.created_at)+'</div>';
                html+='<div style="font-size:14px;color:var(--gray-700);margin-top:2px;">'+escHtml(a.content||'')+'</div>';
                if(a.images&&a.images.length>0){
                    a.images.forEach(function(img){
                        html+='<img src="../../'+escHtml(img)+'" style="max-width:100px;max-height:100px;margin-top:4px;border-radius:4px;border:1px solid var(--gray-200);cursor:pointer;" onclick="showImageLightbox(this.src)">';
                    });
                }
                html+='</div>';
            });
        }else{html+='<p style="color:var(--gray-500);font-size:14px;">暂无售后记录</p>';}
        html+='</div>';

        html+='</div>';
        container.innerHTML=html;
    }).catch(function(){container.innerHTML='<div class="alert alert-danger">查询失败，请检查网络连接</div>';});
}

function showImageLightbox(src){
    document.getElementById('imgLightboxImg').src = src;
    document.getElementById('imgLightbox').style.display = 'flex';
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
