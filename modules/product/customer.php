<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
// 输出缓冲：保证 AJAX 提交时返回的是干净 JSON，且 POST 后仍能发送 Location 头
if (!ob_get_level()) { ob_start(); }

$isAjax = (($_POST['_ajax'] ?? '') === '1' || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
$pdo = getDB();
// POST 处理必须在输出任何 HTML 之前，JS 里 fetch('customer.php') 才能拿到 JSON
require_once __DIR__ . '/customer_post.php';

if (!$isAjax) {
    require_once __DIR__ . '/../../includes/header.php';
    require_permission('customer_view');
}

$where = '';
$params = [];
if ($search) { $where = "WHERE name LIKE ? OR phone LIKE ? OR contact LIKE ?"; $params = array_fill(0,3,"%$search%"); }

$perPage = ITEMS_PER_PAGE;
$offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT * FROM customers $where ORDER BY id DESC LIMIT $offset,$perPage"); $stmt->execute($params);
$list = $stmt->fetchAll();

// 自动生成客户编码
$lastCode = $pdo->query("SELECT code FROM customers WHERE code LIKE 'KH%' AND code REGEXP '^KH[0-9]+$' ORDER BY CAST(SUBSTRING(code,3) AS UNSIGNED) DESC LIMIT 1")->fetchColumn();
if ($lastCode) {
    $nextNum = intval(substr($lastCode, 2)) + 1;
} else {
    $nextNum = 1;
}
$nextCode = 'KH' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

// 来源字典：与 CRM 客户表单取自同一张表，保证两个入口的「来源」选项完全一致。
// 这张表归 CRM 模块，用 try 兜底——万一库里还没建，只是来源选不了，不该让整个客户页打不开
$sources = [];
try {
    $sources = $pdo->query("SELECT id, name FROM customer_sources ORDER BY id")->fetchAll();
} catch (Exception $e) {
    error_log('customer_sources load failed: ' . $e->getMessage());
}

?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-users"></i> 客户管理</h1>
    <div class="page-actions">
        <?php if (check_permission('customer_edit')): ?>
        <a href="import.php?type=customer" class="btn btn-outline"><i class="fa-solid fa-upload"></i> 导入</a>
        <button class="btn btn-primary" onclick="addCust()"><i class="fa-solid fa-plus"></i> 新增客户</button>
        <?php endif; ?>
        <button class="btn btn-outline" onclick="exportCustomers()"><i class="fa-solid fa-download"></i> 导出</button>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<?php flash_show(); ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索客户名称/电话/联系人..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="customer.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container" id="custTableContainer">
<table>
<thead><tr><th>ID</th><th>编码</th><th>客户名称</th><th>类型</th><th>联系人</th><th>电话</th><th>期初应收</th><th>操作</th></tr></thead>
<tbody id="custTbody">
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?= $item['id'] ?></td><td><?= htmlspecialchars($item['code']) ?></td>
    <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
    <td><?= $item['type']=='individual'?'个人':'企业' ?></td>
    <td><?= htmlspecialchars($item['contact']?:'-') ?></td><td><?= htmlspecialchars($item['phone']?:'-') ?></td>
    <td>¥<?= format_money($item['initial_balance']) ?></td>
    <td>
        <a href="customer_detail.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="详情"><i class="fa-solid fa-eye"></i></a>
        <?php if (check_permission('customer_edit')): ?>
        <button class="btn btn-sm btn-outline" onclick="editCust(<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
        <button type="button" class="btn btn-sm btn-outline" onclick="delCust(<?= $item['id'] ?>)"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-users"></i><p>暂无客户数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?>
<div class="pagination" id="custPagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
<a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="custModal"><div class="modal"><div class="modal-header"><h3 class="modal-title" id="custTitle">新增客户</h3><button class="modal-close" onclick="closeModal('custModal')">&times;</button></div>
<form id="custForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="custId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户名称 <span class="required">*</span></label><input type="text" name="name" id="custName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">客户编码</label><input type="text" name="code" id="custCode" class="form-control" value="<?=$nextCode?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">类型</label><select name="type" id="custType" class="form-control"><option value="company">企业</option><option value="individual">个人</option></select></div>
        <div class="form-group"><label class="form-label">联系人</label><input type="text" name="contact" id="custContact" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="custPhone" class="form-control"></div>
        <div class="form-group"><label class="form-label">邮箱</label><input type="email" name="email" id="custEmail" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="custAddr" class="form-control"></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">期初应收</label><input type="number" step="0.01" name="initial_balance" id="custBal" class="form-control" value="0"></div>
    </div>
    <!-- 销售信息：原本只在 CRM 客户表单里有，补到这里是为了两个入口口径一致。
         默认收起，避免在主数据这边录个客户被迫看一堆销售字段 -->
    <div class="form-group" style="border-top:1px dashed var(--gray-200);padding-top:10px;margin-top:4px;">
        <label class="form-label" style="cursor:pointer;" onclick="toggleCrmFields()">
            <i class="fa-solid fa-chevron-right" id="crmFieldsArrow"></i> 销售信息（CRM）
            <small style="color:var(--gray-500);font-weight:normal;">点击展开/收起</small>
        </label>
        <div id="crmFields" style="display:none;">
            <div class="form-row">
                <div class="form-group"><label class="form-label">公司名称</label><input type="text" name="company" id="custCompany" class="form-control"></div>
                <div class="form-group"><label class="form-label">微信号</label><input type="text" name="wechat" id="custWechat" class="form-control"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">来源</label>
                    <select name="source_id" id="custSource" class="form-control">
                        <option value="">请选择</option>
                        <?php foreach($sources as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">意向程度</label>
                    <select name="intention" id="custIntention" class="form-control">
                        <option value="">请选择</option><option value="高">高</option><option value="中">中</option><option value="低">低</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">意向产品</label><input type="text" name="intended_product" id="custIntendedProduct" class="form-control"></div>
                <div class="form-group"><label class="form-label">开发日期</label><input type="date" name="developed_at" id="custDevelopedAt" class="form-control"></div>
            </div>
        </div>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" id="custRemark" class="form-control" rows="2"></textarea></div>
    <div id="custFormMsg"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('custModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
// 注意：本脚本由 main.js 的 __bootApp() 在 footer 中执行，此时 jQuery 可能尚未加载，
// 因此不要使用 $ / jQuery，也不要依赖 DOMContentLoaded（那时本段已执行完）
// 销售信息（CRM 字段）默认收起，点标题栏展开/收起。
// show 传布尔值时强制设为对应状态，不传则取反——编辑客户时用它做自动展开
function toggleCrmFields(show){
    var box=document.getElementById('crmFields');
    var arrow=document.getElementById('crmFieldsArrow');
    if(!box) return;
    if(typeof show!=='boolean') show = (box.style.display==='none');
    box.style.display = show ? '' : 'none';
    if(arrow) arrow.className = show ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right';
}

// 新增前清空表单：原来直接 openModal，会把上一次编辑的值带到新客户上
function addCust(){
    var form=document.getElementById('custForm');
    if(form) form.reset();
    document.getElementById('custId').value='0';
    document.getElementById('custCode').value='<?=$nextCode?>';
    document.getElementById('custBal').value='0';
    toggleCrmFields(false);
    document.getElementById('custTitle').textContent='新增客户';
    document.getElementById('custFormMsg').innerHTML='';
    openModal('custModal');
}

function editCust(d){
    document.getElementById('custTitle').textContent='编辑客户';
    ['Id','Name','Code','Type','Contact','Phone','Email','Addr','Bal','Remark',
     'Company','Wechat','Source','Intention','IntendedProduct','DevelopedAt'].forEach(function(f,i){
        var el=document.getElementById('cust'+f); if(!el) return;
        var keys=['id','name','code','type','contact','phone','email','address','initial_balance','remark',
                  'company','wechat','source_id','intention','intended_product','developed_at'];
        el.value=d[keys[i]]||(i===0||i===8?'0':'');
    });
    // 客户在 CRM 侧已经录过这些信息时自动展开，免得用户以为字段丢了
    toggleCrmFields(!!(d.company||d.wechat||d.source_id||d.intention||d.intended_product||d.developed_at));
    document.getElementById('custFormMsg').innerHTML='';
    openModal('custModal');
}

// 读取指定 cookie（CSRF token 与表单一致，AJAX 提交需要）
function getCookie(name){
    var m=document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g,'\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
}

// 用 fetch 提交表单并返回 JSON，避免整页跳转造成的空白页
function postForm(form, cb){
    var fd = new FormData(form);
    fd.append('_ajax','1');
    var body = new URLSearchParams(fd).toString();
    fetch('customer.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
        credentials:'same-origin',
        body:body
    }).then(function(r){ return r.json(); }).then(cb)
      .catch(function(e){ cb({ok:false,msg:'请求失败：'+e.message}); });
}

// 局部刷新列表与分页（不整页刷新），地址栏保持当前分页
function refreshCustList(){
    fetch(window.location.href, {headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
    .then(function(r){ return r.text(); })
    .then(function(html){
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newTbody = doc.querySelector('#custTbody');
        var newPage  = doc.querySelector('#custPagination');
        var curTbody = document.querySelector('#custTbody');
        var curPage  = document.querySelector('#custPagination');
        if (newTbody && curTbody) curTbody.innerHTML = newTbody.innerHTML;
        if (curPage) { curPage.innerHTML = newPage ? newPage.innerHTML : ''; }
    }).catch(function(){ location.href = window.location.href; });
}

function showCustMsg(type, msg){
    var box = document.getElementById('custFormMsg');
    if (box) box.innerHTML = '<div class="alert alert-' + (type === 'success' ? 'success' : 'danger') + '">' +
        String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
}

function bindCustForm(){
    var form = document.getElementById('custForm');
    if (form && !form.__bound) {
        form.__bound = true;
        form.addEventListener('submit', function(e){
            e.preventDefault();
            postForm(form, function(res){
                if (res.ok) {
                    if (window.showToast) window.showToast(res.msg, 'success');
                    closeModal('custModal');
                    refreshCustList();
                } else {
                    showCustMsg(res.type || 'danger', res.msg || '保存失败');
                }
            });
            return false;
        });
    }
}
bindCustForm();
document.addEventListener('DOMContentLoaded', bindCustForm);
window.rebindPageForms = bindCustForm;

function delCust(id){
    if (!confirm('确定删除？')) return;
    var fd = new FormData();
    // 直接用表单里的 CSRF 字段，避免 cookie 名与后端不一致
    var tok = document.querySelector('#custForm input[name=_csrf_token], input[name=_csrf_token]');
    fd.append('_csrf_token', tok ? tok.value : getCookie('csrf_token'));
    fd.append('action','delete');
    fd.append('id', id);
    fd.append('_ajax','1');
    fetch('customer.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
        credentials:'same-origin',
        body:new URLSearchParams(fd).toString()
    }).then(function(r){ return r.json(); }).then(function(res){
        if (window.showToast) window.showToast(res.msg, res.ok ? 'success' : 'error');
        else alert(res.msg);
        if (res.ok) refreshCustList();
    }).catch(function(e){ alert('请求失败：' + e.message); });
}

function exportCustomers() {
    var q = window.location.search.replace(/^[?]/, '');
    window.location.href = 'customer_export.php' + (q ? '?' + q : '');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
