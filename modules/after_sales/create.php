<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('master_data');
$pdo = getDB();

$statuses = $pdo->query("SELECT * FROM tracking_statuses WHERE status=1 ORDER BY sort_order ASC")->fetchAll();
$employees = get_options('users','id','real_name','status=1');
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-qrcode"></i> 生成追踪码</h1>
</div>

<div class="card">
    <div class="card-header">搜索出库单</div>
    <div class="card-body">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="flex:1;min-width:200px;">
                <label class="form-label">搜索关键词</label>
                <input type="text" id="searchKw" class="form-control" placeholder="出库单号/客户姓名/电话..." onkeydown="if(event.key==='Enter')searchOutstocks()">
            </div>
            <div class="form-group" style="width:140px;">
                <label class="form-label">日期从</label>
                <input type="date" id="searchDateFrom" class="form-control">
            </div>
            <div class="form-group" style="width:140px;">
                <label class="form-label">日期至</label>
                <input type="date" id="searchDateTo" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary" onclick="searchOutstocks()"><i class="fa-solid fa-search"></i> 搜索</button>
            </div>
        </div>
        <div id="searchResult" style="margin-top:8px;max-height:300px;overflow-y:auto;"></div>
    </div>
</div>

<div id="outstockDetail" style="display:none;"></div>

<div class="card" id="trackingConfigCard" style="display:none;">
    <div class="card-header">追踪码配置</div>
    <div class="card-body">
        <form id="trackingForm" onsubmit="return saveTracking(event)">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="outstock_id" id="tOutstockId">
        <input type="hidden" name="order_id" id="tOrderId">

        <div class="form-group">
            <label class="form-label">追踪产品</label>
            <select name="products_type" id="tProductsType" class="form-control" onchange="toggleProducts()">
                <option value="all">全部出库商品</option>
                <option value="selected">选择商品（可多选）</option>
            </select>
        </div>
        <div id="productSelectArea" style="display:none;margin-bottom:12px;max-height:200px;overflow-y:auto;border:1px solid var(--gray-300);border-radius:6px;padding:8px;"></div>

        <div class="form-row">
            <div class="form-group"><label class="form-label">查询密码</label><input type="text" name="password" id="tPassword" class="form-control" placeholder="默认：业务员手机后6位或888888"></div>
            <div class="form-group"><label class="form-label">当前状态</label>
                <select name="status_id" class="form-control">
                    <option value="">请选择</option>
                    <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">售后人员</label>
                <select name="after_sales_employee_id" class="form-control">
                    <option value="">请选择</option>
                    <?php foreach($employees as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">备注</label><input type="text" name="remark" class="form-control"></div>
        </div>

        <h4 style="margin-bottom:8px;color:var(--gray-800);">展示字段设置</h4>
        <p style="font-size:12px;color:var(--gray-600);margin-bottom:8px;">以下字段均非必填，可取消勾选或修改默认值</p>
        <div id="displayFieldsArea"></div>

        <div style="margin-top:12px;text-align:right;">
            <button type="button" class="btn btn-outline" onclick="addCustomField()"><i class="fa-solid fa-plus"></i> 添加自定义字段</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-qrcode"></i> 生成追踪码</button>
        </div>
        </form>
    </div>
</div>

<script>
var outstockData = null;
var customFieldIdx = 0;

// 出库单字段到展示字段的映射
var fieldMapping = [
    {key:'customer_name', label:'客户姓名', source:'customer_name'},
    {key:'customer_phone', label:'客户电话', source:'customer_phone'},
    {key:'outstock_date', label:'出库日期', source:'outstock_date'},
    {key:'warehouse_name', label:'发货仓库', source:'warehouse_name'},
    {key:'receiver_name', label:'接货人', source:'receiver_name'},
    {key:'receiver_phone', label:'接货人电话', source:'receiver_phone'},
    {key:'salesperson_name', label:'业务经理', source:'salesperson_name'},
    {key:'salesperson_phone', label:'业务经理电话', source:'salesperson_phone'},
];

function searchOutstocks(){
    var kw = document.getElementById('searchKw').value.trim();
    var df = document.getElementById('searchDateFrom').value;
    var dt = document.getElementById('searchDateTo').value;
    var container = document.getElementById('searchResult');
    container.innerHTML = '<div style="padding:8px;color:var(--gray-600);">搜索中...</div>';

    var params = new URLSearchParams();
    params.append('action','search_outstocks');
    if(kw) params.append('keyword', kw);
    if(df) params.append('date_from', df);
    if(dt) params.append('date_to', dt);

    fetch('ajax.php?'+params.toString())
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success||!resp.data||resp.data.length===0){
            container.innerHTML='<div style="padding:12px;color:var(--gray-600);text-align:center;">未找到相关出库单</div>';
            return;
        }
        var html = '<table style="width:100%;font-size:13px;"><thead><tr><th>出库单号</th><th>客户</th><th>客户电话</th><th>日期</th><th>金额</th><th>业务员</th><th>追踪状态</th><th></th></tr></thead><tbody>';
        resp.data.forEach(function(o){
            var hasTracking = (o.has_tracking > 0);
            var statusHtml = hasTracking
                ? '<span style="color:var(--success);">✓ 已生成</span>'
                : '<span style="color:var(--gray-500);">未生成</span>';
            var actionHtml = hasTracking
                ? '<span style="color:var(--gray-500);font-size:12px;">已生成</span>'
                : '<a href="javascript:selectOutstock('+o.id+')" class="btn btn-sm btn-primary">选择</a>';
            html+='<tr style="'+(hasTracking?'opacity:.6;':'')+'">';
            html+='<td><strong>'+escHtml(o.bill_no)+'</strong></td>';
            html+='<td>'+escHtml(o.customer_name||'-')+'</td>';
            html+='<td>'+escHtml(o.customer_phone||'-')+'</td>';
            html+='<td>'+escHtml(o.outstock_date||'-')+'</td>';
            html+='<td>¥'+parseFloat(o.total_amount||0).toFixed(2)+'</td>';
            html+='<td>'+escHtml(o.salesperson_name||'-')+'</td>';
            html+='<td>'+statusHtml+'</td>';
            html+='<td>'+actionHtml+'</td>';
            html+='</tr>';
        });
        html+='</tbody></table>';
        container.innerHTML=html;
    }).catch(function(){
        container.innerHTML='<div style="padding:8px;color:var(--danger);">搜索失败</div>';
    });
}

function selectOutstock(outstockId){
    fetch('ajax.php?action=get_outstock_info&outstock_id='+outstockId)
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        if(resp.data.has_tracking>0){alert('该出库单已生成追踪码，无法重复生成');return;}
        outstockData = resp.data;
        document.getElementById('tOutstockId').value = outstockData.id;
        document.getElementById('tOrderId').value = outstockData.order_id||0;

        var infoHtml = '<div class="card" style="margin-top:12px;"><div class="card-header">已选出库单</div><div class="card-body">';
        infoHtml+='<div style="background:var(--gray-100);padding:12px;border-radius:6px;">';
        infoHtml+='<strong>出库单号：</strong>'+escHtml(outstockData.bill_no);
        infoHtml+=' | <strong>客户：</strong>'+escHtml(outstockData.customer_name||'-');
        infoHtml+=' | <strong>金额：</strong>¥'+parseFloat(outstockData.total_amount||0).toFixed(2);
        infoHtml+='<br><strong>出库日期：</strong>'+escHtml(outstockData.outstock_date||'-');
        infoHtml+=' | <strong>业务员：</strong>'+escHtml(outstockData.salesperson_name||'-');
        infoHtml+=' | <strong>客户电话：</strong>'+escHtml(outstockData.customer_phone||'-');
        if(outstockData.order&&outstockData.order.bill_no){
            infoHtml+='<br><strong>关联订单：</strong>'+escHtml(outstockData.order.bill_no);
        }
        infoHtml+='</div></div></div>';
        document.getElementById('outstockDetail').innerHTML=infoHtml;
        document.getElementById('outstockDetail').style.display='block';
        document.getElementById('trackingConfigCard').style.display='block';

        toggleProducts();
        buildDisplayFields();
        setDefaultPassword();

        // 滚动到配置区域
        document.getElementById('trackingConfigCard').scrollIntoView({behavior:'smooth'});
    }).catch(function(){alert('加载出库单信息失败');});
}

function toggleProducts(){
    var type = document.getElementById('tProductsType').value;
    var area = document.getElementById('productSelectArea');
    if(type==='selected'&&outstockData&&outstockData.items){
        var html='';
        outstockData.items.forEach(function(item,idx){
            html+='<label style="display:block;padding:4px 8px;cursor:pointer;">';
            html+='<input type="checkbox" name="item_ids[]" value="'+item.id+'"> ';
            html+=escHtml(item.product_name)+' ('+escHtml(item.spec)+') ×'+item.quantity+' ¥'+parseFloat(item.price||0).toFixed(2);
            html+='</label>';
        });
        area.innerHTML=html;
        area.style.display='block';
    }else{
        area.style.display='none';
    }
}

function buildDisplayFields(){
    var area = document.getElementById('displayFieldsArea');
    // 构建展示字段：系统字段 + 手动字段 + 自定义字段区域
    var fields = [];

    // 系统已有的字段（从出库单自动读取）
    fieldMapping.forEach(function(f){
        fields.push({key:f.key, label:f.label, value: outstockData[f.source]||'', isSystem:true});
    });

    // 手动输入的额外字段
    var manualFields = [
        {key:'production_date', label:'生产日期', value:'', inputType:'date'},
        {key:'delivery_time', label:'发货时间', value:(function(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+'T'+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');})(), inputType:'datetime-local'},
        {key:'delivery_method', label:'发货方式', value:''},
        {key:'sender_name', label:'发货人', value:''},
        {key:'driver_name', label:'拉货司机姓名', value:''},
        {key:'driver_phone', label:'拉货司机电话', value:''},
        {key:'order_bill_no', label:'销售订单号', value:(outstockData.order?outstockData.order.bill_no:'')},
    ];
    manualFields.forEach(function(f){
        fields.push({key:f.key, label:f.label, value:f.value, inputType:f.inputType, isSystem:true});
    });

    customFieldIdx = fields.length;
    var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
    fields.forEach(function(f){
        var inputType = f.inputType || 'text';
        html+='<label style="display:flex;align-items:center;gap:6px;">';
        html+='<input type="checkbox" name="display_field_enable['+f.key+']" value="1" checked onchange="toggleFieldVal(this,\''+f.key+'\')">';
        html+='<span style="white-space:nowrap;">'+escHtml(f.label)+'：</span>';
        html+='<input type="'+inputType+'" name="display_fields['+f.key+']" id="field_'+f.key+'" class="form-control" style="flex:1;padding:2px 6px;" value="'+escHtml(f.value)+'">';
        html+='<input type="hidden" name="display_field_labels['+f.key+']" value="'+escHtml(f.label)+'">';
        html+='</label>';
    });
    html+='<div id="customFieldsArea" style="grid-column:1/-1;"></div>';
    html+='</div>';
    area.innerHTML=html;
}

function toggleFieldVal(cb,key){
    var el=document.getElementById('field_'+key);
    if(el) el.disabled = !cb.checked;
}

function addCustomField(){
    customFieldIdx++;
    var key = 'custom_'+customFieldIdx;
    var label = prompt('请输入字段名称（如：发货备注）：');
    if(!label)return;
    var val = prompt('请输入字段值（可为空）：')||'';
    var area = document.getElementById('customFieldsArea');
    var div = document.createElement('div');
    div.id = 'cf_'+key;
    div.style.cssText = 'display:flex;align-items:center;gap:6px;padding:4px 0;';
    div.innerHTML = '<label style="display:flex;align-items:center;gap:6px;width:100%;">'+
        '<input type="checkbox" name="display_field_enable['+key+']" value="1" checked onchange="toggleFieldVal(this,\''+key+'\')">'+
        '<span style="white-space:nowrap;">'+escHtml(label)+'：</span>'+
        '<input type="text" name="display_fields['+key+']" id="field_'+key+'" class="form-control" style="flex:1;padding:2px 6px;" value="'+escHtml(val)+'">'+
        '<input type="hidden" name="display_field_labels['+key+']" value="'+escHtml(label)+'">'+
        '</label>';
    area.appendChild(div);
}

function setDefaultPassword(){
    var phone = outstockData.salesperson_phone||'';
    var pwd = phone.length>=6 ? phone.slice(-6) : '888888';
    document.getElementById('tPassword').placeholder = '默认：'+pwd;
    document.getElementById('tPassword').value = pwd;
}

function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function saveTracking(e){
    e.preventDefault();
    var form = document.getElementById('trackingForm');
    var fd = new FormData(form);
    fd.append('action','save_tracking');

    // 收集 display_fields 完整数据（含label）
    var displayFields={};
    var labels={};
    // 先收集labels
    document.querySelectorAll('[name^="display_field_labels"]').forEach(function(el){
        var key = el.name.match(/\[(.+)\]/)[1];
        labels[key] = el.value;
    });
    var enables = document.querySelectorAll('[name^="display_field_enable"]');
    enables.forEach(function(cb){
        var key = cb.name.match(/\[(.+)\]/)[1];
        if(!cb.checked)return;
        var val = document.querySelector('[name="display_fields['+key+']"]');
        if(!val)return;
        displayFields[key] = {
            label: labels[key] || key,
            value: val.value
        };
    });

    fd.set('display_fields', JSON.stringify(displayFields));

    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){
            alert('追踪码生成成功！\n\n追踪码：'+resp.data.tracking_no);
            location.href='list.php';
        }else{
            alert(resp.message);
        }
    }).catch(function(){alert('请求失败');});
    return false;
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
