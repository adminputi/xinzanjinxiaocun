<?php
/**
 * CRM 跟进记录
 * 功能：全部记录 / 今日待跟进 / 今日已跟进 三个Tab
 */
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_followup_view');
$pdo = getDB();
require_once __DIR__ . '/../../includes/migration.php';
run_migrations();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();
// 是否有编辑跟进记录的权限（管理员恒为 true，其他角色需在「系统管理-角色权限」中勾选 crm_followup_edit）
$canEditFollowup = check_permission('crm_followup_edit');

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

// 待跟进（今日计划 + 逾期未跟进）的统一条件，列表与各Tab计数共用，避免两处口径漂移
// 口径：有计划时间 → 计划时间在明天 0 点之前（今天全天 + 已逾期）→ 排除已成交
//      → 且该计划时间点之后客户没有再跟进过（补过跟进的不再算待跟进）
$tomorrowStart = follow_tomorrow_start();
$pendingCond = sql_follow_pending();
// 每个客户只显示最后一条跟进记录（记录多了列表会乱），完整历史在客户详情里看
$latestCond = sql_follow_latest();

// Tab过滤
if ($tab === 'today_pending') {
    $where .= $pendingCond;
    $params[] = $tomorrowStart;
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

// 所有 Tab 都按客户去重：一个客户一行，显示其最新一条跟进
$where .= $latestCond;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

// 今日待跟进按计划时间升序（逾期的排最前），其余按记录时间倒序
$orderBy = ($tab === 'today_pending') ? 'f.next_follow_at ASC' : 'f.created_at DESC';

// done_after_at：本条记录的「计划跟进时间」之后，该客户最早的跟进时间
// 有值 = 计划逾期后补过跟进（不再标红）；NULL = 一次都没跟进（真逾期）
$sql = "SELECT f.*, c.name as customer_name, c.phone as customer_phone, c.in_pool, IFNULL(c.intended_product,'') as intended_product,
    u.real_name as user_name, ue.real_name as updated_by_name,
    (SELECT MIN(f2.created_at) FROM customer_followups f2
      WHERE f2.customer_id = f.customer_id AND f2.created_at > f.next_follow_at) AS done_after_at,
    (SELECT COUNT(*) FROM customer_followups f4 WHERE f4.customer_id = f.customer_id) AS follow_cnt
    FROM customer_followups f
    LEFT JOIN customers c ON f.customer_id=c.id
    LEFT JOIN users u ON f.user_id=u.id
    LEFT JOIN users ue ON f.updated_by=ue.id
    WHERE 1=1 $where
    ORDER BY $orderBy LIMIT $offset,$perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$resultLabels = ['待跟进'=>'warning','有意向'=>'primary','已成交'=>'success','无意向'=>'danger'];
$badgeColors = ['电话'=>'info','微信'=>'success','面谈'=>'primary','拜访'=>'warning','短信'=>'gray','邮件'=>'gray','其他'=>'gray'];

// 各Tab数量统计
$baseWhere = '';
$baseParams = [];
if (!$isAdmin) { $baseWhere .= " AND c.owner_id=?"; $baseParams[] = $userId; }

$countAll = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere $latestCond");
$countAll->execute($baseParams);
$tabAllCount = $countAll->fetchColumn();

$countPending = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere $pendingCond $latestCond");
$countPending->execute(array_merge($baseParams, [$tomorrowStart]));
$tabPendingCount = $countPending->fetchColumn();

// 待跟进里「已逾期」（计划时间已过且没跟进）的条数，用于提示行拆分显示
$countOverdue = $pdo->prepare("SELECT COUNT(*) FROM customer_followups f LEFT JOIN customers c ON f.customer_id=c.id WHERE 1=1 $baseWhere $pendingCond $latestCond AND f.next_follow_at <= ?");
$countOverdue->execute(array_merge($baseParams, [$tomorrowStart, date('Y-m-d H:i:s')]));
$tabOverdueCount = (int)$countOverdue->fetchColumn();

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
    <a href="?tab=all" class="<?=$tab==='all'?'active':''?>" title="每个客户只显示最新一条跟进记录，完整历史在客户详情里看">
        <i class="fa-solid fa-list tab-icon"></i> 全部客户
        <span class="tab-count"><?=$tabAllCount?></span>
    </a>
    <a href="?tab=today_pending" class="<?=$tab==='today_pending'?'active':''?>" title="今日计划跟进 + 逾期未跟进（补过跟进的不再计入）">
        <i class="fa-solid fa-clock tab-icon"></i> 待跟进
        <span class="tab-count"><?=$tabPendingCount?></span>
    </a>
    <a href="?tab=today_done" class="<?=$tab==='today_done'?'active':''?>" title="今天新增的跟进记录，同样每个客户只显示最新一条">
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
<?php if ($tab === 'today_pending'): ?>
<div style="padding:10px 16px;font-size:13px;color:var(--gray-600);border-bottom:1px solid var(--gray-200);">
    <?php if (!$total): ?>
    暂无待跟进记录 —— 今日计划与逾期未跟进的都会出现在这里
    <?php elseif ($search): ?>
    匹配到 <b><?=$total?></b> 条待跟进（按计划时间升序，逾期排最前）
    <?php else: ?>
    共 <b><?=$total?></b> 条待跟进：其中 <b style="color:var(--danger);">逾期未跟进 <?=$tabOverdueCount?> 条</b>，今日计划 <?=$total - $tabOverdueCount?> 条（按计划时间升序，逾期排最前）
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="table-container">
<table>
<thead><tr>
    <th>客户</th><th>电话</th><th>意向产品</th><th>类型</th><th>内容</th><th>结果</th><th>跟进人</th><th>计划下次</th><th>附件</th><th>时间</th><th>操作</th>
</tr></thead>
<tbody>
<?php if ($list): foreach ($list as $f): ?>
<?php $planSt = follow_plan_status($f['next_follow_at'], $f['result'], $f['done_after_at'] ?? null); ?>
<tr<?=$planSt['state']==='overdue'?' style="background:rgba(220,53,69,.07);"':''?>>
    <td><i class="fa-solid <?=$f['in_pool']?'fa-water':'fa-user'?>" style="color:<?=$f['in_pool']?'var(--warning)':'var(--gray-400)';?>;margin-right:4px;" title="<?=$f['in_pool']?'公海':'私有'?>"></i>
        <a href="customer_detail.php?id=<?=$f['customer_id']?>" style="color:var(--primary);font-weight:500;"><?=htmlspecialchars($f['customer_name'])?></a>
        <?php if (intval($f['follow_cnt'] ?? 1) > 1): ?>
        <div style="font-size:11px;color:var(--gray-400);">共<?=intval($f['follow_cnt'])?>条跟进，此处显示最新一条</div>
        <?php endif; ?>
    </td>
    <td><?=htmlspecialchars($f['customer_phone'])?:'-'?></td>
    <td><?php $prod=$f['intended_product']??''; if($prod): ?><span title="<?=htmlspecialchars($prod)?>" style="cursor:help;"><?=htmlspecialchars(mb_strlen($prod)>8?mb_substr($prod,0,8).'...':$prod)?></span><?php else: ?>-<?php endif; ?></td>
    <td><span class="badge badge-<?=$badgeColors[$f['follow_type']]??'gray'?>"><?=htmlspecialchars($f['follow_type'])?></span></td>
    <td style="max-width:300px;word-break:break-all;" title="<?=htmlspecialchars($f['content']??'')?>"><?=htmlspecialchars(mb_substr($f['content']??'',0,26))?><?=mb_strlen($f['content']??'')>26?'...':''?>
    <?php if (!empty($f['updated_by'])): ?>
    <div style="font-size:11px;color:var(--gray-400);margin-top:2px;"><i class="fa-solid fa-pen"></i> <?=htmlspecialchars($f['updated_by_name'] ?: ('用户#'.$f['updated_by']))?> 修改于 <?=date('m-d H:i',strtotime($f['updated_at']))?></div>
    <?php endif; ?>
    </td>
    <td><span class="badge badge-<?=$resultLabels[$f['result']]??'gray'?>"><?=$f['result']?></span></td>
    <td><?=htmlspecialchars($f['user_name'])?:'-'?></td>
    <td><?php if ($f['next_follow_at']): ?><?=format_datetime_short($f['next_follow_at'])?>
        <?php if ($planSt['state'] === 'overdue'): ?>
        <div style="font-size:11px;color:var(--danger);font-weight:600;">已逾期<?=$planSt['days']>0?$planSt['days'].'天':''?></div>
        <?php elseif ($planSt['state'] === 'late_done'): ?>
        <div style="font-size:11px;color:var(--gray-500);" title="实际跟进：<?=htmlspecialchars($planSt['done_at'])?>"><i class="fa-solid fa-circle-check" style="margin-right:2px;"></i><?=$planSt['days']>0?'逾期'.$planSt['days'].'天后跟进':'当天已跟进'?></div>
        <?php endif; ?>
    <?php else: ?>-<?php endif; ?></td>
    <td><?php if ($f['attachment']): $an = $f['attachment_name'] ?? ''; ?><a href="../../<?=htmlspecialchars($f['attachment'])?>" target="_blank" download="<?=htmlspecialchars($an)?>" title="<?=htmlspecialchars($an ?: '查看附件')?>">📎 <?=htmlspecialchars($an ? (mb_strlen($an) > 12 ? mb_substr($an, 0, 12) . '...' : $an) : '附件')?></a><?php else: ?>-<?php endif; ?></td>
    <td><?=date('m-d H:i',strtotime($f['created_at']))?></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="viewFollowup(<?=$f['id']?>)" title="查看详情"><i class="fa-solid fa-eye"></i></button>
        <?php if ($canEditFollowup): ?>
        <button class="btn btn-sm btn-primary" onclick="editFollowup(<?=$f['id']?>)" title="编辑"><i class="fa-solid fa-pen"></i></button>
        <?php endif; ?>
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
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('viewFollowupModal')">关闭</button>
    <?php if ($canEditFollowup): ?>
    <button type="button" class="btn btn-primary" id="fuDetailEditBtn" onclick="editFollowup(document.getElementById('fuDetailId').value)"><i class="fa-solid fa-pen"></i> 编辑</button>
    <?php endif; ?>
</div>
<input type="hidden" id="fuDetailId" value="">
</div></div>

<!-- 编辑跟进弹窗（需 crm_followup_edit 权限） -->
<?php if ($canEditFollowup): ?>
<div class="modal-overlay" id="editFollowupModal">
<div class="modal modal-md">
<form id="editFollowupForm" onsubmit="return saveFollowupEdit(event)" enctype="multipart/form-data">
<?= csrf_field() ?>
<input type="hidden" name="id" id="efId">
    <div class="modal-header">
        <h3 class="modal-title">编辑跟进 - <span id="efCustomerName"></span></h3>
        <button type="button" class="modal-close" onclick="closeModal('editFollowupModal')">&times;</button>
    </div>
    <div class="modal-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">跟进类型</label>
                <select name="follow_type" id="efType" class="form-control">
                <?php foreach (['电话','微信','面谈','拜访','短信','邮件','其他'] as $t): ?>
                    <option value="<?=$t?>"><?=$t?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">跟进结果</label>
                <select name="result" id="efResult" class="form-control">
                <?php foreach (['待跟进','有意向','已成交','无意向'] as $r): ?>
                    <option value="<?=$r?>"><?=$r?></option>
                <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">跟进内容 <span style="color:var(--danger)">*</span></label>
            <textarea name="content" id="efContent" class="form-control" rows="4" required placeholder="请输入跟进内容"></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">计划下次跟进</label>
                <input type="datetime-local" name="next_follow_at" id="efNext" class="form-control">
                <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick('[name=next_follow_at]',1)">明天 09:00</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick('[name=next_follow_at]',7)">一周后 09:00</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="setNextFollowQuick('[name=next_follow_at]',null)">清除</button>
                </div>
                <small style="color:var(--gray-500);">留空表示暂不计划下次跟进</small>
            </div>
            <div class="form-group">
                <label class="form-label">附件（留空则不替换）</label>
                <input type="file" name="attachment" id="efAttachment" class="form-control">
                <div id="efCurrentAttach" style="font-size:12px;color:var(--gray-500);margin-top:4px;"></div>
                <label id="efRemoveWrap" style="display:none;font-size:12px;margin-top:4px;cursor:pointer;color:var(--danger);">
                    <input type="checkbox" id="efRemove"> 删除当前附件
                </label>
            </div>
        </div>
        <div style="font-size:12px;color:var(--gray-400);">提示：跟进人与记录时间不可修改，改动会记录最后修改人。</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('editFollowupModal')">取消</button>
        <button type="submit" class="btn btn-primary" id="efSaveBtn"><i class="fa-solid fa-check"></i> 保存修改</button>
    </div>
</form>
</div>
</div>
<?php endif; ?>

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
            html += '<tr><td style="color:var(--gray-500);">计划下次</td><td>'+escHtml(f.next_follow_at?fmtPlanFollow(f.next_follow_at):'无')+'</td></tr>';
            html += '<tr><td style="color:var(--gray-500);">跟进内容</td><td style="white-space:pre-wrap;">'+escHtml(f.content||'')+'</td></tr>';
            if(f.attachment){html += '<tr><td style="color:var(--gray-500);">附件</td><td><a href="../../'+escHtml(f.attachment)+'" target="_blank" download="'+escHtml(f.attachment_name||'')+'" title="'+escHtml(f.attachment_name||'')+'">📎 '+escHtml(f.attachment_name||'查看附件')+'</a></td></tr>';}
            html += '</table>';
            document.getElementById('fuDetailContent').innerHTML=html;
            var hid = document.getElementById('fuDetailId');
            if(hid) hid.value = f.id;
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
// ===== 编辑跟进记录 =====
function fetchFollowup(id){
    return fetch('ajax.php?action=get_followup_detail&id='+id)
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('服务端返回异常');
        return r.json();
    });
}
function editFollowup(id){
    if(!id) return;
    fetchFollowup(id).then(function(resp){
        if(!resp.success || !resp.data){ alert(resp.message||'加载失败'); return; }
        var f = resp.data;
        document.getElementById('efId').value = f.id;
        document.getElementById('efCustomerName').textContent = f.customer_name || '';
        document.getElementById('efType').value = f.follow_type || '电话';
        document.getElementById('efResult').value = f.result || '待跟进';
        document.getElementById('efContent').value = f.content || '';
        document.getElementById('efNext').value = toDatetimeLocal(f.next_follow_at);
        document.getElementById('efAttachment').value = '';
        var rm = document.getElementById('efRemove'); rm.checked = false;
        var rmWrap = document.getElementById('efRemoveWrap');
        var cur = document.getElementById('efCurrentAttach');
        if(f.attachment){
            var nm = f.attachment_name || '查看附件';
            cur.innerHTML = '当前：<a href="../../'+escHtml(f.attachment)+'" target="_blank" download="'+escHtml(f.attachment_name||'')+'">📎 '+escHtml(nm)+'</a>';
            rmWrap.style.display = 'block';
        } else {
            cur.innerHTML = '当前：无附件';
            rmWrap.style.display = 'none';
        }
        closeModal('viewFollowupModal');
        openModal('editFollowupModal');
    }).catch(function(err){ alert('加载失败：'+err.message); });
}
function saveFollowupEdit(e){
    e.preventDefault();
    var btn = document.getElementById('efSaveBtn');
    var old = btn ? btn.innerHTML : '';
    if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...'; }
    var fd = new FormData(document.getElementById('editFollowupForm'));
    fd.append('action','update_followup');
    fd.append('_csrf_token', CSRF_TOKEN);
    if(document.getElementById('efRemove').checked) fd.append('remove_attachment','1');
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('服务端返回异常');
        return r.json();
    })
    .then(function(resp){
        if(btn){ btn.disabled = false; btn.innerHTML = old; }
        if(resp.success){ alert(resp.message||'已保存'); location.reload(); }
        else alert(resp.message||'保存失败');
    })
    .catch(function(err){
        if(btn){ btn.disabled = false; btn.innerHTML = old; }
        alert('保存失败：'+err.message);
    });
    return false;
}
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
