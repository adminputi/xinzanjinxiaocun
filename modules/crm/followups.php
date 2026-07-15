<?php
/**
 * CRM 跟进记录
 * 功能：全部记录 / 今日待跟进 / 今日已跟进 三个Tab
 */
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_followup_view');
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

$tab = $_GET['tab'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;
$today = date('Y-m-d');

$where = '';
$params = [];

// 权限过滤：业务经理只看自己客户的跟进（管理员看全部）
if (!$isAdmin) {
    $where .= " AND c.owner_id=?";
    $params[] = $userId;
}

// Tab过滤
if ($tab === 'today_pending') {
    // 今日待跟进：计划今天跟进的
    $where .= " AND f.next_follow_at >= ? AND f.next_follow_at < ? AND f.result != '已成交'";
    $params[] = $today . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
} elseif ($tab === 'today_done') {
    // 今日已跟进：今天添加的
    $where .= " AND f.created_at >= ? AND f.created_at < ?";
    $params[] = $today . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
}

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR f.content LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

$sql = "SELECT f.*, c.name as customer_name, c.phone as customer_phone, c.in_pool, IFNULL(c.intended_product,'') as intended_product,
    u.real_name as user_name
    FROM customer_followups f
    LEFT JOIN customers c ON f.customer_id=c.id
    LEFT JOIN users u ON f.user_id=u.id
    WHERE 1=1 $where
    ORDER BY f.created_at DESC LIMIT $offset,$perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$resultLabels = ['待跟进'=>'warning','有意向'=>'primary','已成交'=>'success','无意向'=>'danger'];
$badgeColors = ['电话'=>'info','微信'=>'success','面谈'=>'primary','拜访'=>'warning','短信'=>'gray','邮件'=>'gray','其他'=>'gray'];

// 各Tab数量统计
$baseWhere = '';
$baseParams = [];
if (!$isAdmin) { $baseWhere .= " AND c.owner_id=?"; $baseParams[] = $userId; }

$countAll = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere");
$countAll->execute($baseParams);
$tabAllCount = $countAll->fetchColumn();

$countPending = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere AND f.next_follow_at >= ? AND f.next_follow_at < ? AND f.result != '已成交'");
$countPending->execute(array_merge($baseParams, [$today.' 00:00:00', date('Y-m-d',strtotime('+1 day')).' 00:00:00']));
$tabPendingCount = $countPending->fetchColumn();

$countDone = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere AND f.created_at >= ? AND f.created_at < ?");
$countDone->execute(array_merge($baseParams, [$today.' 00:00:00', date('Y-m-d',strtotime('+1 day')).' 00:00:00']));
$tabDoneCount = $countDone->fetchColumn();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-comments"></i> 跟进记录</h1>
    <a href="customers.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回客户列表</a>
</div>

<!-- Tab导航 -->
<style>
.tab-pills{display:flex;gap:4px;background:var(--gray-100);border-radius:var(--radius);padding:4px;margin-bottom:16px;width:fit-content;}
.tab-pills a{display:flex;align-items:center;gap:6px;padding:10px 20px;border-radius:calc(var(--radius) - 2px);text-decoration:none;font-size:14px;font-weight:500;color:var(--gray-600);transition:all .2s;white-space:nowrap;}
.tab-pills a:hover{color:var(--primary);background:var(--gray-200);}
.tab-pills a.active{background:#fff;color:var(--primary);font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.1);}
.tab-pills a .tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;font-size:12px;font-weight:600;background:var(--gray-200);color:var(--gray-600);}
.tab-pills a.active .tab-count{background:var(--primary);color:#fff;}
.tab-pills a .tab-icon{font-size:15px;}
</style>

<div class="tab-pills">
    <a href="?tab=all" class="<?=$tab==='all'?'active':''?>">
        <i class="fa-solid fa-list tab-icon"></i> 全部记录
        <span class="tab-count"><?=$tabAllCount?></span>
    </a>
    <a href="?tab=today_pending" class="<?=$tab==='today_pending'?'active':''?>">
        <i class="fa-solid fa-clock tab-icon"></i> 今日待跟进
        <span class="tab-count"><?=$tabPendingCount?></span>
    </a>
    <a href="?tab=today_done" class="<?=$tab==='today_done'?'active':''?>">
        <i class="fa-solid fa-circle-check tab-icon"></i> 今日已跟进
        <span class="tab-count"><?=$tabDoneCount?></span>
    </a>
</div>

<form class="filter-bar" method="get">
    <input type="hidden" name="tab" value="<?=$tab?>">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索客户/电话/内容..." value="<?=htmlspecialchars($search)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="?tab=<?=$tab?>" class="btn btn-outline btn-sm">清除</a>
</form>

<div class="card"><div class="card-body" style="padding:0;">
<div class="table-container">
<table>
<thead><tr>
    <th>客户</th><th>电话</th><th>意向产品</th><th>类型</th><th>内容</th><th>结果</th><th>跟进人</th><th>计划下次</th><th>附件</th><th>时间</th><th>操作</th>
</tr></thead>
<tbody>
<?php if ($list): foreach ($list as $f): ?>
<tr>
    <td><i class="fa-solid <?=$f['in_pool']?'fa-water':'fa-user'?>" style="color:<?=$f['in_pool']?'var(--warning)':'var(--gray-400)';?>;margin-right:4px;" title="<?=$f['in_pool']?'公海':'私有'?>"></i>
        <a href="customer_detail.php?id=<?=$f['customer_id']?>" style="color:var(--primary);font-weight:500;"><?=htmlspecialchars($f['customer_name'])?></a>
    </td>
    <td><?=htmlspecialchars($f['customer_phone'])?:'-'?></td>
    <td><?php $prod=$f['intended_product']??''; if($prod): ?><span title="<?=htmlspecialchars($prod)?>" style="cursor:help;"><?=htmlspecialchars(mb_strlen($prod)>8?mb_substr($prod,0,8).'...':$prod)?></span><?php else: ?>-<?php endif; ?></td>
    <td><span class="badge badge-<?=$badgeColors[$f['follow_type']]??'gray'?>"><?=htmlspecialchars($f['follow_type'])?></span></td>
    <td style="max-width:300px;word-break:break-all;"><?=htmlspecialchars(mb_substr($f['content']??'',0,80))?><?=mb_strlen($f['content']??'')>80?'...':''?></td>
    <td><span class="badge badge-<?=$resultLabels[$f['result']]??'gray'?>"><?=$f['result']?></span></td>
    <td><?=htmlspecialchars($f['user_name'])?:'-'?></td>
    <td><?=$f['next_follow_at']?date('Y-m-d',strtotime($f['next_follow_at'])):'-'?></td>
    <td><?php if ($f['attachment']): ?><a href="../../<?=htmlspecialchars($f['attachment'])?>" target="_blank" title="查看附件">📎</a><?php else: ?>-<?php endif; ?></td>
    <td><?=date('m-d H:i',strtotime($f['created_at']))?></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="viewFollowup(<?=$f['id']?>)" title="查看详情"><i class="fa-solid fa-eye"></i></button>
        <?php if ($isAdmin || intval($f['user_id']) === $userId): ?>
        <button class="btn btn-sm btn-danger" onclick="delFollowup(<?=$f['id']?>)" title="删除"><i class="fa-solid fa-trash"></i></button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-comments"></i><p>暂无跟进记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?tab=<?=$tab?>&page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?>
</div><?php endif; ?>

<!-- 查看跟进详情弹窗 -->
<div class="modal-overlay" id="viewFollowupModal"><div class="modal modal-md"><div class="modal-header"><h3 class="modal-title">跟进详情</h3><button class="modal-close" onclick="closeModal('viewFollowupModal')">&times;</button></div>
<div class="modal-body" id="fuDetailContent"></div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('viewFollowupModal')">关闭</button></div>
</div></div>

<script>
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
function viewFollowup(id){
    fetch('ajax.php?action=get_followup_detail&id='+id)
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('服务端返回异常');
        return r.json();
    })
    .then(function(resp){
        if(resp.success && resp.data){
            var f = resp.data;
            var html = '<table style="width:100%;font-size:14px;line-height:2;">';
            html += '<tr><td style="color:var(--gray-500);width:80px;">客户</td><td><strong>'+escHtml(f.customer_name||'')+'</strong></td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进类型</td><td>'+escHtml(f.follow_type||'')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进结果</td><td>'+escHtml(f.result||'')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进人</td><td>'+escHtml(f.user_name||'')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进时间</td><td>'+escHtml(f.created_at||'')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">计划下次</td><td>'+escHtml(f.next_follow_at||'无')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进内容</td><td style="white-space:pre-wrap;">'+escHtml(f.content||'')+'</td></tr>';
            if(f.attachment){html += '<tr><td style="color:var(--gray-500);">附件</td><td><a href="../../'+escHtml(f.attachment)+'" target="_blank">查看附件</a></td></tr>';}
            html += '</table>';
            document.getElementById('fuDetailContent').innerHTML=html;
        } else {
            document.getElementById('fuDetailContent').innerHTML='<p style="text-align:center;padding:20px;color:var(--gray-500);">'+(resp.message||'加载失败')+'</p>';
        }
        openModal('viewFollowupModal');
    })
    .catch(function(err){
        document.getElementById('fuDetailContent').innerHTML='<p style="text-align:center;padding:20px;color:var(--danger);">加载失败：'+err.message+'</p>';
        openModal('viewFollowupModal');
    });
}
function delFollowup(id){
    if(!confirm('确定删除此跟进记录？'))return;
    var fd=new FormData();
    fd.append('action','delete_followup');
    fd.append('id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('服务端返回异常');
        return r.json();
    })
    .then(function(resp){
        if(resp.success){alert(resp.message);location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){
        alert('删除失败：'+err.message);
    });
}
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
