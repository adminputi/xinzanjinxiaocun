<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_customer_view');
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$sourceId = intval($_GET['source_id'] ?? 0);
$ownerId = $isAdmin ? intval($_GET['owner_id'] ?? 0) : $userId;
$intention = $_GET['intention'] ?? '';
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.company LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($sourceId > 0) { $where .= " AND c.source_id=?"; $params[] = $sourceId; }
if ($ownerId > 0) { $where .= " AND c.owner_id=?"; $params[] = $ownerId; }
if ($intention) { $where .= " AND c.intention=?"; $params[] = $intention; }
// 默认排除公海
$showPool = intval($_GET['pool'] ?? 0);
if (!$showPool) { $where .= " AND c.in_pool=0"; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

$sql = "SELECT c.*, s.name as source_name, u.real_name as owner_name,
    (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count,
    IFNULL(c.intended_product,'') as intended_product
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    LEFT JOIN users u ON c.owner_id=u.id
    $where ORDER BY c.id DESC LIMIT $offset,$perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$sources = $pdo->query("SELECT * FROM customer_sources WHERE status=1 ORDER BY sort_order")->fetchAll();
$owners = $pdo->query("SELECT id, real_name FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-users"></i> 客户资料</h1>
    <button class="btn btn-primary" onclick="openCustomerForm()"><i class="fa-solid fa-plus"></i> 新增客户</button>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索名称/电话/公司..." value="<?=htmlspecialchars($search)?>"></div>
    <select name="source_id" class="form-control" style="width:120px;">
        <option value="0">全部来源</option>
        <?php foreach($sources as $s): ?><option value="<?=$s['id']?>" <?=$sourceId==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
    </select>
    <?php if ($isAdmin): ?>
    <select name="owner_id" class="form-control" style="width:120px;">
        <option value="0">全部经理</option>
        <?php foreach($owners as $o): ?><option value="<?=$o['id']?>" <?=$ownerId==$o['id']?'selected':''?>><?=htmlspecialchars($o['real_name'])?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="intention" class="form-control" style="width:100px;">
        <option value="">全部意向</option>
        <option value="高" <?=$intention=='高'?'selected':''?>>高</option>
        <option value="中" <?=$intention=='中'?'selected':''?>>中</option>
        <option value="低" <?=$intention=='低'?'selected':''?>>低</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="customers.php" class="btn btn-outline btn-sm">清除</a>
</form>

<div class="card"><div class="card-body" style="padding:0;">
<div style="padding:8px 16px;display:flex;align-items:center;gap:8px;">
    <button class="btn btn-sm btn-danger" onclick="batchDelete()"><i class="fa-solid fa-trash"></i> 批量删除</button>
    <a href="export.php?<?=http_build_query($_GET)?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-file-excel"></i> 导出Excel</a>
</div>
<div class="table-container">
<table>
<thead><tr>
    <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
    <th>客户名称</th><th>电话</th><th>公司</th><th>来源</th><th>意向</th><th>意向产品</th><th>归属</th><th>跟进</th><th>最后跟进</th><th>操作</th>
</tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><input type="checkbox" class="rowCheck" value="<?=$item['id']?>"></td>
    <td><a href="customer_detail.php?id=<?=$item['id']?>" style="color:var(--primary);font-weight:500;"><?=htmlspecialchars($item['name'])?></a></td>
    <td><?=htmlspecialchars($item['phone'])?:'-'?></td>
    <td><?=htmlspecialchars($item['company'])?:'-'?></td>
    <td><?=htmlspecialchars($item['source_name'])?:($item['source_id']?'-':'')?></td>
    <td><?php if($item['intention']): ?><span class="badge badge-<?=$item['intention']=='高'?'success':($item['intention']=='中'?'warning':'info')?>"><?=$item['intention']?></span><?php else: ?>-<?php endif; ?></td>
    <td><?php $prod=$item['intended_product']??''; if($prod): ?><span title="<?=htmlspecialchars($prod)?>" style="cursor:help;"><?=htmlspecialchars(mb_strlen($prod)>8?mb_substr($prod,0,8).'...':$prod)?></span><?php else: ?>-<?php endif; ?></td>
    <td><?=htmlspecialchars($item['owner_name']?:'-')?></td>
    <td><?=$item['followup_count']?>次</td>
    <td><?=$item['last_followed_at']?:'--'?></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="editCustomer(<?=$item['id']?>)" title="编辑"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-sm btn-primary" onclick="showFollowupModal(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="跟进"><i class="fa-solid fa-comment-dots"></i></button>
        <button class="btn btn-sm btn-outline" onclick="showTransferModal(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="转移"><i class="fa-solid fa-arrow-right-arrow-left"></i></button>
        <button class="btn btn-sm btn-outline" style="color:var(--warning);" onclick="toPool(<?=$item['id']?>,'<?=htmlspecialchars(addslashes($item['name']))?>')" title="归入公海"><i class="fa-solid fa-water"></i></button>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-users"></i><p>暂无客户数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&<?=http_build_query(array_filter(['search'=>$search,'source_id'=>$sourceId,'owner_id'=>$ownerId,'intention'=>$intention]))?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?>
</div><?php endif; ?>

<!-- 新增/编辑客户弹窗 -->
<div class="modal-overlay" id="customerModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="custModalTitle">新增客户</h3><button class="modal-close" onclick="closeModal('customerModal')">&times;</button></div>
<form id="customerForm" onsubmit="return saveCustomer(event)">
<?=csrf_field()?>
<input type="hidden" name="id" id="custId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户名称 <span class="required">*</span></label><input type="text" name="name" id="custName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">客户类型</label>
            <select name="type" id="custType" class="form-control">
                <option value="company">企业</option><option value="individual">个人</option>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">联系人</label><input type="text" name="contact" id="custContact" class="form-control"></div>
        <div class="form-group"><label class="form-label">电话 <span class="required">*</span></label><input type="text" name="phone" id="custPhone" class="form-control" required onblur="checkPhone()">
            <small id="phoneDup" style="color:var(--danger);display:none;"></small>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">公司名称</label><input type="text" name="company" id="custCompany" class="form-control"></div>
        <div class="form-group"><label class="form-label">微信号</label><input type="text" name="wechat" id="custWechat" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">邮箱</label><input type="text" name="email" id="custEmail" class="form-control"></div>
        <div class="form-group"><label class="form-label">来源</label>
            <select name="source_id" id="custSource" class="form-control">
                <option value="">请选择</option>
                <?php foreach($sources as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">意向程度</label>
            <select name="intention" id="custIntention" class="form-control">
                <option value="">请选择</option><option value="高">高</option><option value="中">中</option><option value="低">低</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">意向产品</label><input type="text" name="intended_product" id="custIntendedProduct" class="form-control" placeholder="例如：服务器/云服务/软件"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="custAddress" class="form-control"></div>
        <div class="form-group"></div>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" id="custRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('customerModal')">取消</button>
    <button type="submit" class="btn btn-primary">保存</button>
</div>
</form></div></div>

<!-- 跟进弹窗 -->
<div class="modal-overlay" id="followupModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">添加跟进 - <span id="fuCustomerName"></span></h3><button class="modal-close" onclick="closeModal('followupModal')">&times;</button></div>
<form id="followupForm" onsubmit="return saveFollowup(event)" enctype="multipart/form-data">
<?=csrf_field()?>
<input type="hidden" name="customer_id" id="fuCustomerId">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">跟进类型</label>
            <select name="follow_type" class="form-control">
                <option value="电话">电话</option><option value="微信">微信</option><option value="面谈">面谈</option><option value="拜访">拜访</option><option value="短信">短信</option><option value="邮件">邮件</option><option value="其他">其他</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">跟进结果</label>
            <select name="result" class="form-control">
                <option value="待跟进">待跟进</option><option value="有意向">有意向</option><option value="已成交">已成交</option><option value="无意向">无意向</option>
            </select>
        </div>
    </div>
    <div class="form-group"><label class="form-label">跟进内容 <span class="required">*</span></label>
        <textarea name="content" class="form-control" rows="4" required placeholder="记录跟进的具体内容..."></textarea>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">上传附件（合同等）</label>
            <input type="file" name="attachment" class="form-control">
            <small style="color:var(--gray-500);">支持 pdf/doc/docx/xlsx/jpg/png，最大10MB</small>
        </div>
        <div class="form-group"><label class="form-label">计划下次跟进</label>
            <input type="date" name="next_follow_at" class="form-control">
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('followupModal')">取消</button>
    <button type="submit" class="btn btn-primary">保存跟进</button>
</div>
</form></div></div>

<!-- 转移客户弹窗 -->
<div class="modal-overlay" id="transferModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">转移客户 - <span id="transferCustName"></span></h3><button class="modal-close" onclick="closeModal('transferModal')">&times;</button></div>
<form onsubmit="return doTransfer(event)">
<?=csrf_field()?>
<input type="hidden" id="transferCustId">
<div class="modal-body">
    <div class="form-group"><label class="form-label">转移给 <span class="required">*</span></label>
        <select id="transferToUser" class="form-control" required>
            <option value="">请选择业务经理</option>
            <?php foreach($owners as $u): if($u['id']==$userId) continue; ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['real_name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('transferModal')">取消</button>
    <button type="submit" class="btn btn-primary">确认转移</button>
</div>
</form></div></div>

<script>
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
var CURRENT_USER_ID = <?=$userId?>;
// 安全 fetch 封装：自动处理 CSRF 过期等问题
function safeFetch(url, options) {
    return fetch(url, options).then(function(r) {
        if (!r.ok) {
            return r.text().then(function(t) { throw new Error('请求失败 HTTP ' + r.status); });
        }
        return r.text().then(function(t) {
            try { return JSON.parse(t); }
            catch(e) { throw new Error('服务器返回异常，请刷新后重试'); }
        });
    });
}

function openCustomerForm(){
    document.getElementById('customerForm').reset();
    document.getElementById('custId').value=0;
    document.getElementById('custModalTitle').textContent='新增客户';
    document.getElementById('phoneDup').style.display='none';
    openModal('customerModal');
    document.getElementById('customerForm').action='customer_form_handler.php';
}
function editCustomer(id){
    safeFetch('ajax.php?action=get_customer&customer_id='+id)
    .then(function(resp){
        if(!resp.success){alert(resp.message);return;}
        var c=resp.data.customer;
        if(!c){alert('未找到客户数据');return;}
        document.getElementById('custId').value=c.id;
        document.getElementById('custName').value=c.name||'';
        document.getElementById('custType').value=c.type||'company';
        document.getElementById('custContact').value=c.contact||'';
        document.getElementById('custPhone').value=c.phone||'';
        document.getElementById('custCompany').value=c.company||'';
        document.getElementById('custWechat').value=c.wechat||'';
        document.getElementById('custEmail').value=c.email||'';
        document.getElementById('custSource').value=c.source_id||'';
        document.getElementById('custIntention').value=c.intention||'';
        document.getElementById('custIntendedProduct').value=c.intended_product||'';
        document.getElementById('custAddress').value=c.address||'';
        document.getElementById('custRemark').value=c.remark||'';
        document.getElementById('phoneDup').style.display='none';
        document.getElementById('custModalTitle').textContent='编辑客户';
        document.getElementById('customerForm').action='customer_form_handler.php';
        openModal('customerModal');
    }).catch(function(err){
        alert('加载客户数据失败：'+err.message);
    });
}
function checkPhone(){
    var phone=document.getElementById('custPhone').value.trim();
    var dup=document.getElementById('phoneDup');
    if(!phone){dup.style.display='none';return;}
    var id=document.getElementById('custId').value;
    safeFetch('ajax.php?action=check_phone&phone='+encodeURIComponent(phone))
    .then(function(resp){
        if(resp.success&&resp.data&&resp.data.length>0){
            var names=resp.data.map(function(d){return d.name+(d.owner_name?' (归属：'+d.owner_name+')':'')+(d.id==id?' \u2190当前':'');}).join(', ');
            dup.textContent='\u26a0 该电话已存在：'+names+'，确认仍要添加？';
            dup.style.display='block';
        }else{dup.style.display='none';}
    }).catch(function(err){console.error(err);});
}
function saveCustomer(e){
    e.preventDefault();
    var fd=new FormData(document.getElementById('customerForm'));
    var btn = document.querySelector('#customerModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='保存中...';}
    safeFetch('customer_form_handler.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){
            closeModal('customerModal');
            alert(resp.message||'保存成功');
            location.reload();
        } else {
            alert(resp.message);
        }
    }).catch(function(err){
        alert('保存失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='保存';}
    });
    return false;
}
function showFollowupModal(cid,cname){
    document.getElementById('fuCustomerId').value=cid;
    document.getElementById('fuCustomerName').textContent=cname;
    document.getElementById('followupForm').reset();
    openModal('followupModal');
}
function saveFollowup(e){
    e.preventDefault();
    var fd=new FormData(document.getElementById('followupForm'));
    fd.append('action','add_followup');
    var btn = document.querySelector('#followupModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='保存中...';}
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){
            closeModal('followupModal');
            alert(resp.message||'跟进已添加');
            location.reload();
        } else {
            alert(resp.message);
        }
    }).catch(function(err){
        alert('跟进保存失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='保存跟进';}
    });
    return false;
}
function toPool(id,name){
    if(!confirm('确定将客户【'+name+'】归入公海吗？\n归入公海后其他业务经理可以认领。')) return;
    var fd=new FormData();
    fd.append('action','to_pool');
    fd.append('customer_id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    }).catch(function(err){
        alert('操作失败：'+err.message);
    });
}
function toggleSelectAll(){
    var cb=document.getElementById('selectAll').checked;
    document.querySelectorAll('.rowCheck').forEach(function(c){c.checked=cb;});
}
function batchDelete(){
    var ids=[];
    document.querySelectorAll('.rowCheck:checked').forEach(function(c){ids.push(c.value);});
    if(!ids.length){alert('请选择要删除的客户');return;}
    if(!confirm('确定删除选中的'+ids.length+'个客户？')) return;
    var fd=new FormData();
    fd.append('action','batch_delete');
    fd.append('ids',ids.join(','));
    fd.append('_csrf_token',CSRF_TOKEN);
    safeFetch('ajax.php',{method:'POST',body:fd})
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    }).catch(function(err){
        alert('操作失败：'+err.message);
    });
}
function showTransferModal(id,name){
    document.getElementById('transferCustId').value=id;
    document.getElementById('transferCustName').textContent=name;
    document.getElementById('transferToUser').selectedIndex=0;
    openModal('transferModal');
}
function doTransfer(e){
    e.preventDefault();
    var sel = document.getElementById('transferToUser');
    var toUser = parseInt(sel.value);
    if(!toUser){alert('请选择目标业务经理');return false;}
    if(toUser===CURRENT_USER_ID){alert('不能转移给自己');return false;}
    var toUserName = sel.options[sel.selectedIndex].text;
    if(!confirm('确定将客户【'+document.getElementById('transferCustName').textContent+'】转移给【'+toUserName+'】？')) return false;
    var fd=new FormData();
    fd.append('action','transfer');
    fd.append('customer_id',document.getElementById('transferCustId').value);
    fd.append('to_user_id',toUser);
    fd.append('_csrf_token',CSRF_TOKEN);
    var btn=document.querySelector('#transferModal button[type=submit]');
    if(btn){btn.disabled=true;btn.textContent='转移中...';}
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok){
            return r.text().then(function(t){
                try{var resp=JSON.parse(t);alert(resp.message||'请求失败 HTTP '+r.status);}
                catch(e){alert('请求失败 HTTP '+r.status);}
            });
        }
        return r.text().then(function(t){
            try{
                var resp = JSON.parse(t);
                if(resp.success){
                    closeModal('transferModal');
                    location.reload();
                }else{
                    alert(resp.message||'转移失败');
                }
            }catch(e){
                // JSON解析失败但HTTP 200 — 可能转移已成功，刷新验证
                closeModal('transferModal');
                location.reload();
            }
        });
    }).catch(function(err){
        alert('转移失败：'+err.message);
    }).finally(function(){
        if(btn){btn.disabled=false;btn.textContent='确认转移';}
    });
    return false;
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
