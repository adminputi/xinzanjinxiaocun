<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('master_data');
$pdo = getDB();

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;

$total = $pdo->query("SELECT COUNT(*) FROM tracking_codes")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT tc.*, s.name as status_name FROM tracking_codes tc LEFT JOIN tracking_statuses s ON tc.status_id=s.id ORDER BY tc.id DESC LIMIT $offset,$perPage")->fetchAll();

// 获取每条追踪码的出库单和客户信息
foreach ($list as &$item) {
    $os = $pdo->prepare("SELECT o.bill_no as outstock_bill_no, o.customer_id, c.name as customer_name FROM sales_outstocks o LEFT JOIN customers c ON o.customer_id=c.id WHERE o.id=?");
    $os->execute([$item['outstock_id']]);
    $osInfo = $os->fetch();
    $item['outstock_bill_no'] = $osInfo['outstock_bill_no'] ?? '-';
    $item['customer_name'] = $osInfo['customer_name'] ?? '-';

    // 获取追踪产品名称列表
    $products = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT(product_name,' ×',quantity) SEPARATOR ', ') as product_list FROM tracking_code_items WHERE tracking_id=?");
    $products->execute([$item['id']]);
    $item['product_list'] = $products->fetchColumn() ?: ($item['products_type']==='all'?'全部商品':'选中商品');
}
unset($item);

$statuses = $pdo->query("SELECT * FROM tracking_statuses WHERE status=1 ORDER BY sort_order ASC")->fetchAll();
$employees = get_options('users','id','real_name','status=1');
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-qrcode"></i> 追踪码管理</h1>
    <div style="display:flex;gap:8px;">
        <a href="query.php" class="btn btn-outline"><i class="fa-solid fa-search"></i> 查询追踪码</a>
        <a href="create.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 生成追踪码</a>
    </div>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr>
    <th>序号</th><th>追踪码</th><th>出库单号</th><th>追踪产品</th><th>客户</th><th>当前状态</th><th>生成时间</th><th>操作</th>
</tr></thead>
<tbody>
<?php if ($list): $idx = $offset + 1; foreach ($list as $item): ?>
<tr>
    <td><?=$idx++?></td>
    <td><strong><?=htmlspecialchars($item['tracking_no'])?></strong></td>
    <td><a href="../sales/outstock_view.php?id=<?=$item['outstock_id']?>" target="_blank" style="color:var(--primary);text-decoration:underline;"><?=htmlspecialchars($item['outstock_bill_no'])?></a></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($item['product_list'])?>"><?=htmlspecialchars($item['product_list'])?></td>
    <td><?=htmlspecialchars($item['customer_name'])?></td>
    <td><span class="badge badge-info"><?=htmlspecialchars($item['status_name']?:'未设置')?></span></td>
    <td><?=$item['created_at']?></td>
    <td>
        <a href="javascript:void(0)" onclick="viewQrcode(<?=$item['id']?>)" class="btn btn-sm btn-outline" title="查看/下载二维码"><i class="fa-solid fa-qrcode"></i></a>
        <a href="javascript:void(0)" onclick="viewInfo(<?=$item['id']?>)" class="btn btn-sm btn-outline" title="查看追踪信息"><i class="fa-solid fa-eye"></i></a>
        <a href="javascript:void(0)" onclick="editTracking(<?=$item['id']?>)" class="btn btn-sm btn-outline" title="编辑"><i class="fa-solid fa-pen"></i></a>
        <a href="javascript:void(0)" onclick="addProcess(<?=$item['id']?>)" class="btn btn-sm btn-primary" title="添加流程"><i class="fa-solid fa-timeline"></i></a>
        <a href="javascript:void(0)" onclick="addAfterSales(<?=$item['id']?>)" class="btn btn-sm btn-warning" title="添加售后"><i class="fa-solid fa-headset"></i></a>
        <a href="javascript:void(0)" onclick="deleteTracking(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['tracking_no']))?>')" class="btn btn-sm btn-danger" title="删除"><i class="fa-solid fa-trash"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>暂无追踪码，请先生成</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<!-- 二维码弹窗 -->
<div class="modal-overlay" id="qrModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">追踪码二维码</h3><button class="modal-close" onclick="closeModal('qrModal')">&times;</button></div>
<div class="modal-body" style="text-align:center;">
    <p id="qrCodeText" style="font-size:18px;font-weight:bold;margin-bottom:16px;"></p>
    <div id="qrImageContainer" style="min-height:280px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;"></div>
    <div id="qrActions" style="display:flex;gap:8px;justify-content:center;"></div>
</div></div></div>

<!-- 查看信息弹窗 -->
<div class="modal-overlay" id="infoModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title">追踪信息详情</h3><button class="modal-close" onclick="closeModal('infoModal')">&times;</button></div>
<div class="modal-body" id="infoModalBody" style="max-height:70vh;overflow-y:auto;"></div></div></div>

<!-- 添加流程弹窗 -->
<div class="modal-overlay" id="processModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">添加流程</h3><button class="modal-close" onclick="closeModal('processModal')">&times;</button></div>
<form id="processForm" onsubmit="return saveProcess(event)">
<?=csrf_field()?>
<input type="hidden" name="tracking_id" id="pTrackingId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">状态</label>
        <select name="status_id" id="pStatusId" class="form-control">
            <option value="">不修改状态</option>
            <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">流程说明</label><textarea name="content" class="form-control" rows="3"></textarea></div>
    <div class="form-group">
        <label class="form-label">上传图片</label>
        <input type="file" id="pImages" class="form-control" multiple accept="image/*" onchange="previewImages(this,'pImgPreview','pImagesJson',pPendingImages)">
        <div id="pImgPreview" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;"></div>
        <input type="hidden" name="images" id="pImagesJson" value="[]">
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('processModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 添加售后弹窗 -->
<div class="modal-overlay" id="afterSalesModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">添加售后信息</h3><button class="modal-close" onclick="closeModal('afterSalesModal')">&times;</button></div>
<form id="afterSalesForm" onsubmit="return saveAfterSales(event)">
<?=csrf_field()?>
<input type="hidden" name="tracking_id" id="asTrackingId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">售后信息</label><textarea name="content" class="form-control" rows="4" placeholder="请详细描述售后处理情况..."></textarea></div>
    <div class="form-group">
        <label class="form-label">上传图片</label>
        <input type="file" id="asImages" class="form-control" multiple accept="image/*" onchange="previewImages(this,'asImgPreview','asImagesJson',asPendingImages)">
        <div id="asImgPreview" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;"></div>
        <input type="hidden" name="images" id="asImagesJson" value="[]">
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('afterSalesModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 编辑流程弹窗 -->
<div class="modal-overlay" id="editProcessModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">编辑流程</h3><button class="modal-close" onclick="closeModal('editProcessModal')">&times;</button></div>
<form id="editProcessForm" onsubmit="return saveEditProcess(event)">
<?=csrf_field()?>
<input type="hidden" name="id" id="epId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">状态</label>
        <select name="status_id" id="epStatusId" class="form-control">
            <option value="">不修改状态</option>
            <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">流程说明</label><textarea name="content" id="epContent" class="form-control" rows="3"></textarea></div>
    <div class="form-group" id="epExistingImages" style="display:none;">
        <label class="form-label">现有图片</label>
        <div id="epExistImgPreview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;"></div>
    </div>
    <div class="form-group">
        <label class="form-label">上传新图片（选择后将替换现有图片）</label>
        <input type="file" id="epImages" class="form-control" multiple accept="image/*" onchange="previewImages(this,'epImgPreview','epImagesJson',epPendingImages)">
        <div id="epImgPreview" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;"></div>
        <input type="hidden" name="images" id="epImagesJson" value="">
        <small style="color:var(--gray-500);">不选择文件则保留现有图片</small>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editProcessModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 编辑售后弹窗 -->
<div class="modal-overlay" id="editAfterSalesModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">编辑售后信息</h3><button class="modal-close" onclick="closeModal('editAfterSalesModal')">&times;</button></div>
<form id="editAfterSalesForm" onsubmit="return saveEditAfterSales(event)">
<?=csrf_field()?>
<input type="hidden" name="id" id="eaId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">售后信息</label><textarea name="content" id="eaContent" class="form-control" rows="4" placeholder="请详细描述售后处理情况..."></textarea></div>
    <div class="form-group" id="eaExistingImages" style="display:none;">
        <label class="form-label">现有图片</label>
        <div id="eaExistImgPreview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;"></div>
    </div>
    <div class="form-group">
        <label class="form-label">上传新图片（选择后将替换现有图片）</label>
        <input type="file" id="eaImages" class="form-control" multiple accept="image/*" onchange="previewImages(this,'eaImgPreview','eaImagesJson',eaPendingImages)">
        <div id="eaImgPreview" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;"></div>
        <input type="hidden" name="images" id="eaImagesJson" value="">
        <small style="color:var(--gray-500);">不选择文件则保留现有图片</small>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editAfterSalesModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 编辑追踪码弹窗（完整版，包含展示字段编辑） -->
<div class="modal-overlay" id="editModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title">编辑追踪码</h3><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
<form id="editForm" onsubmit="return saveEdit(event)">
<?=csrf_field()?>
<input type="hidden" name="id" id="eId">
<div class="modal-body" style="max-height:65vh;overflow-y:auto;">
    <div class="form-row">
        <div class="form-group"><label class="form-label">查询密码</label><input type="text" name="password" id="ePassword" class="form-control" placeholder="留空则不修改密码"></div>
        <div class="form-group"><label class="form-label">当前状态</label>
            <select name="status_id" id="eStatusId" class="form-control">
                <option value="">请选择</option>
                <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">售后人员</label>
            <select name="after_sales_employee_id" id="eEmployeeId" class="form-control">
                <option value="">请选择</option>
                <?php foreach($employees as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">备注</label><input type="text" name="remark" id="eRemark" class="form-control"></div>
    </div>

    <h4 style="margin-bottom:8px;color:var(--gray-800);margin-top:12px;">展示字段设置</h4>
    <p style="font-size:12px;color:var(--gray-600);margin-bottom:8px;">可修改字段名称和显示值，或添加自定义信息</p>
    <div id="eDisplayFieldsArea"></div>
    <div style="margin-top:8px;">
        <button type="button" class="btn btn-sm btn-outline" onclick="editAddCustomField()"><i class="fa-solid fa-plus"></i> 添加自定义字段</button>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<!-- 图片放大预览弹窗 -->
<div class="modal-overlay" id="imgLightbox" onclick="closeModal('imgLightbox')" style="z-index:10000;">
<div style="max-width:90vw;max-height:90vh;display:flex;align-items:center;justify-content:center;">
    <img id="imgLightboxImg" style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.3);cursor:zoom-out;" onclick="event.stopPropagation();closeModal('imgLightbox')">
</div></div>

<script>
// ========== 通用函数 ==========
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
var pPendingImages=[], asPendingImages=[], epPendingImages=[], eaPendingImages=[], editCustomIdx=0, editFieldData={};
var uploadToken = <?=json_encode($_SESSION['upload_token'] ?? '')?>;
var currentTrackingData = null; // 缓存当前查看的追踪数据
var isAdmin = <?=$_SESSION['user_role']==='admin'?'true':'false'?>;

// ========== 二维码 ==========
function viewQrcode(id){
    var container = document.getElementById('qrImageContainer');
    container.innerHTML = '<p style="color:var(--gray-600);">加载中...</p>';
    openModal('qrModal');

    fetch('ajax.php?action=get_tracking&id='+id)
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var d=resp.data;
        document.getElementById('qrCodeText').textContent = d.tracking_no;
        currentQrId = d.id;
        currentQrNo = d.tracking_no;

        if(d.qrcode_path){
            showQrImage('../../'+d.qrcode_path);
        }else{
            // 没有二维码则实时显示并触发生成
            showQrFromApi(d.tracking_no, d.id);
        }
    }).catch(function(){container.innerHTML='<p style="color:var(--danger);">加载失败</p>';});
}

function getQrApiUrl(trackingNo){
    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='+encodeURIComponent((siteUrl||'')+'/track.php?code='+encodeURIComponent(trackingNo));
}

function showQrFromApi(trackingNo, trackingId){
    var container = document.getElementById('qrImageContainer');
    var qrUrl = getQrApiUrl(trackingNo);
    var img = new Image();
    img.style.maxWidth = '280px';
    img.onload = function(){
        container.innerHTML = '';
        container.appendChild(img);
        var cleanNo = trackingNo.replace(/[^a-zA-Z0-9]/g,'');
        document.getElementById('qrActions').innerHTML = '<button class="btn btn-primary btn-sm" onclick="saveQrToServer('+trackingId+',\''+cleanNo+'\')"><i class="fa-solid fa-save"></i> 保存/下载</button>';
        saveQrToServer(trackingId, cleanNo);
    };
    img.onerror = function(){
        container.innerHTML = '<p style="color:var(--danger);">二维码加载失败</p>';
        document.getElementById('qrActions').innerHTML = '<button class="btn btn-primary btn-sm" onclick="genQr('+trackingId+')"><i class="fa-solid fa-redo"></i> 重试</button>';
    };
    img.src = qrUrl;
}

function showQrImage(path){
    var img = new Image();
    img.onload = function(){
        document.getElementById('qrImageContainer').innerHTML = '';
        document.getElementById('qrImageContainer').appendChild(img);
        img.style.maxWidth = '280px';
        document.getElementById('qrActions').innerHTML = '<a href="'+path+'" download class="btn btn-primary btn-sm"><i class="fa-solid fa-download"></i> 下载二维码</a>';
    };
    img.onerror = function(){
        document.getElementById('qrImageContainer').innerHTML = '<p style="color:var(--danger);">二维码图片不存在</p>';
        document.getElementById('qrActions').innerHTML = '<button class="btn btn-primary btn-sm" onclick="genQr('+currentQrId+')"><i class="fa-solid fa-redo"></i> 重试</button>';
    };
    img.src = path;
}

var currentQrId = 0, currentQrNo = '', siteUrl = '';
// 尝试自动检测 siteUrl
try{ siteUrl = window.location.protocol+'//'+window.location.host+window.location.pathname.replace(/\/modules\/after_sales\/.*$/,''); }catch(e){}

function genQr(trackingId){
    currentQrId = trackingId;
    var container = document.getElementById('qrImageContainer');
    container.innerHTML = '<p style="color:var(--gray-600);">生成中...</p>';
    // 先尝试服务端生成
    fetch('ajax.php?action=generate_qrcode&id='+trackingId)
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){
            showQrImage('../../'+resp.data.qrcode_path);
        }else{
            // 服务端失败，用API直显
            fetch('ajax.php?action=get_tracking&id='+trackingId)
            .then(function(r2){return r2.json();})
            .then(function(resp2){
                if(resp2.success) showQrFromApi(resp2.data.tracking_no, trackingId);
                else container.innerHTML = '<p style="color:var(--danger);">生成失败</p>';
            }).catch(function(){container.innerHTML = '<p style="color:var(--danger);">生成失败</p>';});
        }
    }).catch(function(){
        // 网络失败直接尝试API显示
        fetch('ajax.php?action=get_tracking&id='+trackingId)
        .then(function(r2){return r2.json();})
        .then(function(resp2){
            if(resp2.success) showQrFromApi(resp2.data.tracking_no, trackingId);
            else container.innerHTML = '<p style="color:var(--danger);">生成失败</p>';
        }).catch(function(){container.innerHTML = '<p style="color:var(--danger);">生成失败，请检查网络</p>';});
    });
}

// 将二维码图片保存到服务器
function saveQrToServer(trackingId, trackingNo){
    var qrUrl = getQrApiUrl(trackingNo);
    // 通过服务端代理下载并保存
    fetch('ajax.php?action=save_qrcode_image&id='+trackingId+'&qr_url='+encodeURIComponent(qrUrl))
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){
            document.getElementById('qrActions').innerHTML = '<a href="../../'+resp.data.qrcode_path+'" download class="btn btn-primary btn-sm"><i class="fa-solid fa-download"></i> 下载二维码</a>';
        }
    }).catch(function(){});
}

// ========== 查看信息 ==========
// 字段名到中文的映射（覆盖所有可能的展示字段key）
var fieldLabelMap={
    'bill_no':'出库单号','outstock_date':'出库日期','customer_name':'客户姓名','customer_phone':'客户电话',
    'customer_address':'客户地址','warehouse_name':'发货仓库','receiver_name':'接货人','receiver_phone':'接货人电话',
    'salesperson_name':'业务经理','salesperson_phone':'业务经理电话','order_bill_no':'销售订单号',
    'total_amount':'总金额','remark':'备注','outstock_remark':'出库备注',
    'production_date':'生产日期','delivery_time':'发货时间',
    'delivery_method':'发货方式','sender_name':'发货人','driver_name':'拉货司机姓名','driver_phone':'拉货司机电话'
};

function viewInfo(id){
    fetch('ajax.php?action=get_tracking&id='+id)
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var d = resp.data;
        currentTrackingData = d; // 缓存数据供编辑/删除使用
        var html = '<div style="padding:12px;">';

        // 头部卡片
        html+='<div style="background:linear-gradient(135deg,var(--primary),#7c3aed);color:#fff;border-radius:10px;padding:16px;margin-bottom:12px;text-align:center;">';
        html+='<div style="font-size:18px;font-weight:bold;">售后追踪详情</div>';
        html+='<div style="font-size:14px;opacity:.85;margin-top:4px;">追踪码：'+escHtml(d.tracking_no)+'</div>';
        html+='<div style="display:inline-block;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:13px;margin-top:8px;">'+(d.status_name||'未设置')+'</div>';
        html+='</div>';

        // 基本信息：仅显示 4 个核心字段
        html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
        html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">📋 基本信息</h4>';
        html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;">';
        html+='<div><span style="color:var(--gray-500);">出库单号：</span><span style="color:var(--gray-700);">'+escHtml((d.outstock&&d.outstock.bill_no)||'-')+'</span></div>';
        html+='<div><span style="color:var(--gray-500);">订单号：</span><span style="color:var(--gray-700);">'+escHtml(d.order_bill_no||'-')+'</span></div>';
        html+='<div><span style="color:var(--gray-500);">出库日期：</span><span style="color:var(--gray-700);">'+escHtml((d.outstock&&d.outstock.outstock_date)||'-')+'</span></div>';
        html+='<div><span style="color:var(--gray-500);">当前状态：</span><span style="color:var(--gray-700);">'+escHtml(d.status_name||'未设置')+'</span></div>';
        html+='<div><span style="color:var(--gray-500);">发货仓库：</span><span style="color:var(--gray-700);">'+escHtml((d.outstock&&d.outstock.warehouse_name)||'-')+'</span></div>';
        html+='</div></div>';

        // 详细信息：展示 tracking_data 中除基本信息已覆盖字段外的所有字段
        var td = d.tracking_data||{};
        // 基本信息已展示的字段 key，详细信息中不再重复
        var basicInfoKeys = {'bill_no':1,'order_bill_no':1,'outstock_date':1,'warehouse_name':1};
        var detailCount = 0;
        for(var dk in td){if(td.hasOwnProperty(dk)&&td[dk].value&&!basicInfoKeys[dk]) detailCount++;}
        if(detailCount>0){
            html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
            html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">📝 详细信息</h4>';
            html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;">';
            for(var k in td){
                if(!td.hasOwnProperty(k)) continue;
                if(!td[k].value) continue;
                if(basicInfoKeys[k]) continue; // 已在基本信息中展示，跳过
                var label = td[k].label || fieldLabelMap[k] || k;
                html+='<div><span style="color:var(--gray-500);">'+escHtml(label)+'：</span><span style="color:var(--gray-700);word-break:break-all;">'+escHtml(td[k].value||'')+'</span></div>';
            }
            html+='</div></div>';
        }

        // 售后人员（独立卡片）
        if(d.after_sales_name||d.after_sales_phone){
            html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
            html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">👤 售后人员</h4>';
            html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:14px;">';
            html+='<div><span style="color:var(--gray-500);">姓名：</span><span style="color:var(--gray-700);">'+escHtml(d.after_sales_name||'未指定')+'</span></div>';
            if(d.after_sales_phone) html+='<div><span style="color:var(--gray-500);">电话：</span><span style="color:var(--gray-700);">'+escHtml(d.after_sales_phone)+'</span></div>';
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
            d.processes.forEach(function(p, pIdx){
                var isNew=p.is_newest==1;
                var dotColor = isNew?'var(--danger)':'var(--gray-300)';
                html+='<div style="position:relative;padding-bottom:14px;margin-bottom:4px;border-bottom:1px solid var(--gray-100);">';
                html+='<div style="position:absolute;left:-20px;top:7px;width:12px;height:12px;border-radius:50%;background:'+dotColor+';border:2px solid #fff;box-shadow:0 0 0 2px '+dotColor+';"></div>';
                // 第一行：时间 + 操作按钮
                html+='<div style="display:flex;justify-content:space-between;align-items:center;min-height:22px;">';
                html+='<div style="font-size:12px;color:var(--gray-500);">'+escHtml(p.created_at)+'</div>';
                if(isAdmin){
                    html+='<div style="display:flex;gap:4px;flex-shrink:0;">';
                    html+='<button class="btn btn-sm btn-outline" onclick="event.stopPropagation();editProcess('+p.id+')" title="编辑流程"><i class="fa-solid fa-pen"></i></button>';
                    html+='<button class="btn btn-sm btn-danger" onclick="event.stopPropagation();deleteProcess('+p.id+')" title="删除流程"><i class="fa-solid fa-trash"></i></button>';
                    html+='</div>';
                }
                html+='</div>';
                // 第二行：内容
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

        // 售后信息
        html+='<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">';
        html+='<h4 style="font-size:16px;color:var(--primary);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--gray-200);">🎧 售后信息</h4>';
        if(d.after_sales&&d.after_sales.length>0){
            d.after_sales.forEach(function(a){
                html+='<div style="padding:8px 0;border-left:2px solid var(--warning);padding-left:18px;margin-bottom:8px;border-bottom:1px solid var(--gray-100);">';
                // 第一行：时间 + 操作按钮
                html+='<div style="display:flex;justify-content:space-between;align-items:center;min-height:22px;">';
                html+='<div style="font-size:12px;color:var(--gray-500);">'+escHtml(a.created_at)+'</div>';
                if(isAdmin){
                    html+='<div style="display:flex;gap:4px;flex-shrink:0;">';
                    html+='<button class="btn btn-sm btn-outline" onclick="event.stopPropagation();editAfterSales('+a.id+')" title="编辑售后"><i class="fa-solid fa-pen"></i></button>';
                    html+='<button class="btn btn-sm btn-danger" onclick="event.stopPropagation();deleteAfterSales('+a.id+')" title="删除售后"><i class="fa-solid fa-trash"></i></button>';
                    html+='</div>';
                }
                html+='</div>';
                // 第二行：内容
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
        document.getElementById('infoModalBody').innerHTML=html;
        openModal('infoModal');
    }).catch(function(){alert('加载失败');});
}

// ========== 编辑 ==========
function editTracking(id){
    fetch('ajax.php?action=get_tracking&id='+id)
    .then(function(r){return r.json();})
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var d = resp.data;
        document.getElementById('eId').value = d.id;
        document.getElementById('ePassword').value = '';
        document.getElementById('eStatusId').value = d.status_id||'';
        document.getElementById('eEmployeeId').value = d.after_sales_employee_id||'';
        document.getElementById('eRemark').value = d.remark||'';

        // 构建展示字段编辑区域
        editFieldData = d.tracking_data||{};
        var area = document.getElementById('eDisplayFieldsArea');
        var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        var idx = 0;
        for(var k in editFieldData){
            if(!editFieldData.hasOwnProperty(k)) continue;
            var f = editFieldData[k];
            idx++;
            var fid = 'efield_'+idx;
            html+='<div style="display:flex;align-items:center;gap:6px;" id="erow_'+fid+'">';
            html+='<input type="text" name="edit_field_labels['+k+']" value="'+escHtml(f.label||k)+'" style="width:100px;padding:2px 4px;font-size:12px;">';
            html+='<span>：</span>';
            html+='<input type="text" name="edit_field_values['+k+']" id="'+fid+'" value="'+escHtml(f.value||'')+'" class="form-control" style="flex:1;padding:2px 6px;font-size:13px;">';
            html+='</div>';
        }
        html+='<div id="editCustomFieldsArea" style="grid-column:1/-1;"></div>';
        html+='</div>';
        area.innerHTML = html;
        editCustomIdx = idx + 100;
        openModal('editModal');
    }).catch(function(){alert('加载失败');});
}

function editAddCustomField(){
    editCustomIdx++;
    var key = 'custom_'+editCustomIdx;
    var label = prompt('请输入字段名称（如：补充说明）：');
    if(!label)return;
    var val = prompt('请输入字段值（可为空）：')||'';
    var area = document.getElementById('editCustomFieldsArea');
    var div = document.createElement('div');
    div.style.cssText = 'display:flex;align-items:center;gap:6px;padding:4px 0;';
    div.innerHTML = '<input type="text" name="edit_field_labels['+key+']" value="'+escHtml(label)+'" style="width:100px;padding:2px 4px;font-size:12px;">'+
        '<span>：</span>'+
        '<input type="text" name="edit_field_values['+key+']" value="'+escHtml(val)+'" class="form-control" style="flex:1;padding:2px 6px;font-size:13px;">';
    area.appendChild(div);
}

function saveEdit(e){
    e.preventDefault();
    var fd = new FormData(document.getElementById('editForm'));
    fd.append('action','update_tracking');

    // 收集展示字段（所有字段均保存）
    var displayFields = {};
    var labels = document.querySelectorAll('[name^="edit_field_labels"]');

    labels.forEach(function(el){
        var key = el.name.match(/\[(.+)\]/)[1];
        var valEl = document.querySelector('[name="edit_field_values['+key+']"]');
        if(!valEl) return;
        displayFields[key] = {
            label: el.value || key,
            value: valEl.value || ''
        };
    });

    fd.set('display_fields', JSON.stringify(displayFields));

    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){closeModal('editModal');location.reload();}
        else alert(resp.message);
    }).catch(function(){alert('请求失败');});
    return false;
}

// ========== 删除 ==========
function deleteTracking(id, trackingNo){
    if(!confirm('确定要删除追踪码 【'+trackingNo+'】 吗？\n\n删除后：\n- 该出库单可重新生成追踪码\n- 所有流程和售后记录将一并删除\n- 此操作不可恢复')) return;
    var fd = new FormData();
    fd.append('action','delete_tracking');
    fd.append('id', id);
    fd.append('_csrf_token', document.querySelector('[name="_csrf_token"]').value);

    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(resp){
        if(resp.success){location.reload();}
        else alert(resp.message);
    }).catch(function(){alert('请求失败');});
}

// ========== 添加流程 ==========
function addProcess(trackingId){
    document.getElementById('processForm').reset();
    document.getElementById('pTrackingId').value = trackingId;
    document.getElementById('pImgPreview').innerHTML = '';
    document.getElementById('pImagesJson').value = '[]';
    pPendingImages = [];
    openModal('processModal');
}

function saveProcess(e){
    e.preventDefault();
    console.log('[saveProcess] pPendingImages.length=', pPendingImages.length, 'uploadToken=', typeof uploadToken, uploadToken ? 'set' : 'empty');
    var fd = new FormData(document.getElementById('processForm'));
    fd.append('action','add_process');
    if(pPendingImages.length>0){
        uploadImages(pPendingImages, function(paths){
            if(paths.length===0){
                alert('图片上传失败，流程记录将不包含图片。\n请打开F12查看Console错误详情。');
            }
            fd.set('images',JSON.stringify(paths));
            doSave(fd,'processModal');
        });
    }else{
        console.log('[saveProcess] 无图片，直接保存');
        doSave(fd,'processModal');
    }
    return false;
}

// ========== 添加售后 ==========
function addAfterSales(trackingId){
    document.getElementById('afterSalesForm').reset();
    document.getElementById('asTrackingId').value = trackingId;
    document.getElementById('asImgPreview').innerHTML = '';
    document.getElementById('asImagesJson').value = '[]';
    asPendingImages = [];
    openModal('afterSalesModal');
}

function saveAfterSales(e){
    e.preventDefault();
    console.log('[saveAfterSales] asPendingImages.length=', asPendingImages.length);
    var fd = new FormData(document.getElementById('afterSalesForm'));
    fd.append('action','add_after_sales');
    if(asPendingImages.length>0){
        uploadImages(asPendingImages, function(paths){
            if(paths.length===0){
                alert('图片上传失败，售后记录将不包含图片。\n请打开F12查看Console错误详情。');
            }
            fd.set('images',JSON.stringify(paths));
            doSave(fd,'afterSalesModal');
        });
    }else{
        console.log('[saveAfterSales] 无图片，直接保存');
        doSave(fd,'afterSalesModal');
    }
    return false;
}

// ========== 编辑/删除流程 ==========
function editProcess(id) {
    if (!currentTrackingData || !currentTrackingData.processes) return;
    var proc = null;
    for (var i = 0; i < currentTrackingData.processes.length; i++) {
        if (currentTrackingData.processes[i].id == id) { proc = currentTrackingData.processes[i]; break; }
    }
    if (!proc) return;

    document.getElementById('editProcessForm').reset();
    document.getElementById('epId').value = proc.id;
    document.getElementById('epStatusId').value = proc.status_id || '';
    document.getElementById('epContent').value = proc.content || '';
    document.getElementById('epImgPreview').innerHTML = '';
    document.getElementById('epImagesJson').value = '';
    epPendingImages = [];

    // 显示现有图片
    var existDiv = document.getElementById('epExistingImages');
    var existPreview = document.getElementById('epExistImgPreview');
    if (proc.images && proc.images.length > 0) {
        existDiv.style.display = 'block';
        existPreview.innerHTML = '';
        proc.images.forEach(function(img){
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:relative;display:inline-block;';
            var imgEl = document.createElement('img');
            imgEl.src = '../../'+img;
            imgEl.style.cssText = 'max-width:80px;max-height:80px;border-radius:4px;border:1px solid var(--gray-300);';
            wrapper.appendChild(imgEl);
            existPreview.appendChild(wrapper);
        });
    } else {
        existDiv.style.display = 'none';
    }

    openModal('editProcessModal');
}

function deleteProcess(id) {
    if (!confirm('确定要删除这条流程记录吗？此操作不可恢复')) return;
    var fd = new FormData();
    fd.append('action', 'delete_process');
    fd.append('id', id);
    fd.append('_csrf_token', document.querySelector('[name="_csrf_token"]').value);
    fetch('ajax.php', {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success) { closeModal('infoModal'); location.reload(); }
            else alert(resp.message);
        }).catch(function() { alert('请求失败'); });
}

function saveEditProcess(e) {
    e.preventDefault();
    var fd = new FormData(document.getElementById('editProcessForm'));
    fd.append('action', 'edit_process');
    if (epPendingImages.length > 0) {
        uploadImages(epPendingImages, function(paths) {
            fd.set('images', JSON.stringify(paths));
            doSave(fd, 'editProcessModal');
        });
    } else {
        doSave(fd, 'editProcessModal');
    }
    return false;
}

// ========== 编辑/删除售后 ==========
function editAfterSales(id) {
    if (!currentTrackingData || !currentTrackingData.after_sales) return;
    var as = null;
    for (var i = 0; i < currentTrackingData.after_sales.length; i++) {
        if (currentTrackingData.after_sales[i].id == id) { as = currentTrackingData.after_sales[i]; break; }
    }
    if (!as) return;

    document.getElementById('editAfterSalesForm').reset();
    document.getElementById('eaId').value = as.id;
    document.getElementById('eaContent').value = as.content || '';
    document.getElementById('eaImgPreview').innerHTML = '';
    document.getElementById('eaImagesJson').value = '';
    eaPendingImages = [];

    // 显示现有图片
    var existDiv = document.getElementById('eaExistingImages');
    var existPreview = document.getElementById('eaExistImgPreview');
    if (as.images && as.images.length > 0) {
        existDiv.style.display = 'block';
        existPreview.innerHTML = '';
        as.images.forEach(function(img){
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:relative;display:inline-block;';
            var imgEl = document.createElement('img');
            imgEl.src = '../../'+img;
            imgEl.style.cssText = 'max-width:80px;max-height:80px;border-radius:4px;border:1px solid var(--gray-300);';
            wrapper.appendChild(imgEl);
            existPreview.appendChild(wrapper);
        });
    } else {
        existDiv.style.display = 'none';
    }

    openModal('editAfterSalesModal');
}

function deleteAfterSales(id) {
    if (!confirm('确定要删除这条售后记录吗？此操作不可恢复')) return;
    var fd = new FormData();
    fd.append('action', 'delete_after_sales');
    fd.append('id', id);
    fd.append('_csrf_token', document.querySelector('[name="_csrf_token"]').value);
    fetch('ajax.php', {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success) { closeModal('infoModal'); location.reload(); }
            else alert(resp.message);
        }).catch(function() { alert('请求失败'); });
}

function saveEditAfterSales(e) {
    e.preventDefault();
    var fd = new FormData(document.getElementById('editAfterSalesForm'));
    fd.append('action', 'edit_after_sales');
    if (eaPendingImages.length > 0) {
        uploadImages(eaPendingImages, function(paths) {
            fd.set('images', JSON.stringify(paths));
            doSave(fd, 'editAfterSalesModal');
        });
    } else {
        doSave(fd, 'editAfterSalesModal');
    }
    return false;
}

// ========== 图片上传公用 ==========
function previewImages(input, previewId, jsonId, pendingArr){
    console.log('[previewImages] 选中文件数:', input.files.length);
    var preview = document.getElementById(previewId);
    for(var i=0;i<input.files.length;i++){
        var f=input.files[i];
        console.log('[previewImages] 文件:', f.name, f.size, f.type);
        var reader=new FileReader();
        reader.onload=(function(file){
            return function(e){
                var img=document.createElement('img');
                img.src=e.target.result;
                img.style.cssText='max-width:80px;max-height:80px;border-radius:4px;border:1px solid var(--gray-300);';
                preview.appendChild(img);
            };
        })(f);
        reader.readAsDataURL(f);
        pendingArr.push(f);
    }
    console.log('[previewImages] pendingArr 现在有', pendingArr.length, '个文件');
}

function uploadImages(files, callback){
    console.log('[uploadImages] 开始上传', files.length, '个文件');
    var paths=[], cnt=0, failCnt=0;
    if(files.length===0){callback(paths);return;}
    for(var i=0;i<files.length;i++){
        (function(file, idx){
            console.log('[uploadImages] 上传第', (idx+1), '个:', file.name);
            var fd=new FormData();
            fd.append('file',file);
            fd.append('action','upload_image');
            fd.append('_upload_token', uploadToken);
            fetch('ajax.php',{method:'POST',body:fd})
            .then(function(r){
                console.log('[uploadImages] 第', (idx+1), '个 HTTP状态:', r.status, r.statusText);
                if(!r.ok){
                    return r.text().then(function(txt){
                        throw new Error('HTTP ' + r.status + ' ' + r.statusText + ': ' + txt.substring(0,500));
                    });
                }
                return r.json();
            })
            .then(function(resp){
                cnt++;
                console.log('[uploadImages] 第', (idx+1), '个上传响应:', resp);
                if(resp.success){
                    paths.push(resp.data.path);
                    console.log('[uploadImages] 成功，路径:', resp.data.path);
                }else{
                    failCnt++;
                    console.error('[uploadImages] 失败:', resp.message);
                }
                if(cnt>=files.length){
                    console.log('[uploadImages] 全部完成，成功:', paths.length, '失败:', failCnt);
                    callback(paths);
                }
            }).catch(function(err){
                cnt++; failCnt++;
                console.error('[uploadImages] 第', (idx+1), '个错误:', err.message || err);
                if(cnt>=files.length){
                    console.log('[uploadImages] 全部完成，成功:', paths.length, '失败:', failCnt);
                    callback(paths);
                }
            });
        })(files[i], i);
    }
}

function doSave(fd, modalId){
    console.log('[doSave] 提交保存, images=', fd.get('images'));
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        console.log('[doSave] HTTP状态:', r.status, r.statusText);
        if(!r.ok){
            return r.text().then(function(txt){
                throw new Error('HTTP ' + r.status + ' ' + r.statusText + ': ' + txt.substring(0,500));
            });
        }
        return r.json();
    })
    .then(function(resp){
        console.log('[doSave] 响应:', resp);
        if(resp.success){
            closeModal(modalId);
            location.reload();
        }else{
            alert(resp.message);
        }
    }).catch(function(err){
        console.error('[doSave] 请求失败:', err.message || err);
        alert('请求失败: ' + (err.message || err));
    });
}

// ========== 弹窗辅助 ==========
function openModal(id){
    document.getElementById(id).style.display = 'flex';
}
function closeModal(id){
    document.getElementById(id).style.display = 'none';
}
// 点击遮罩关闭
document.addEventListener('click', function(e){
    if(e.target.classList.contains('modal-overlay')){
        e.target.style.display = 'none';
    }
});

// 图片放大预览
function showImageLightbox(src){
    document.getElementById('imgLightboxImg').src = src;
    openModal('imgLightbox');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
