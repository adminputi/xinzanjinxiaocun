<?php
/**
 * CRM 公海客户池
 * 功能：展示公海客户，允许业务经理认领
 * 管理员可手动将公海客户分配给指定业务经理
 */
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_pool_claim');
$pdo = getDB();
$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

// 页面加载时自动检查回收规则
$checkResult = '';
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='crm_pool_days'");
$stmt->execute();
$poolDays = intval($stmt->fetchColumn() ?: 30);

// 认领后回收：优先读取小时设置，兼容旧版天数
$stmt2 = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='crm_claim_hours'");
$stmt2->execute();
$claimHours = intval($stmt2->fetchColumn() ?: 0);
if ($claimHours <= 0) {
    $stmtOld = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='crm_claim_days'");
    $stmtOld->execute();
    $oldDays = intval($stmtOld->fetchColumn() ?: 0);
    $claimHours = $oldDays > 0 ? $oldDays * 24 : 72;
}

$lastCheck = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='crm_pool_last_check'");
$lastCheck->execute();
$lastCheckStr = $lastCheck->fetchColumn();

$shouldCheck = true;
if ($lastCheckStr) {
    $lastCheckTime = strtotime($lastCheckStr);
    if (time() - $lastCheckTime < 3600) $shouldCheck = false; // 1小时内不重复检查
}

if ($shouldCheck) {
    $totalRecycled = 0;
    $messages = [];

    // 1. 常规回收：有跟进记录但超过N天未跟进
    if ($poolDays > 0) {
        $stmt = $pdo->prepare("UPDATE customers SET owner_id=NULL, in_pool=1, pooled_at=NOW() 
            WHERE in_pool=0 AND owner_id IS NOT NULL 
            AND last_followed_at IS NOT NULL 
            AND last_followed_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$poolDays]);
        $n = $stmt->rowCount();
        if ($n > 0) {
            $totalRecycled += $n;
            $messages[] = "{$n} 个超过{$poolDays}天未跟进";
        }
    }

    // 2. 认领后未跟进回收：认领后超过claimHours小时仍无任何跟进记录
    if ($claimHours > 0) {
        // 查出最近一次是"认领"动作、且认领时间超过claimHours小时、且认领后无跟进的客户
        $stmt = $pdo->prepare("UPDATE customers c SET c.owner_id=NULL, c.in_pool=1, c.pooled_at=NOW() 
            WHERE c.in_pool=0 AND c.owner_id IS NOT NULL 
            AND (
                c.last_followed_at IS NULL 
                OR c.last_followed_at <= (
                    SELECT MAX(t.created_at) FROM customer_transfer_logs t 
                    WHERE t.customer_id=c.id AND t.action='claim'
                )
            )
            AND EXISTS (
                SELECT 1 FROM customer_transfer_logs t2
                WHERE t2.customer_id=c.id AND t2.action='claim'
                AND t2.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            )");
        $stmt->execute([$claimHours]);
        $n = $stmt->rowCount();
        if ($n > 0) {
            $totalRecycled += $n;
            $messages[] = "{$n} 个认领后{$claimHours}小时内未跟进";
        }
    }

    $pdo->prepare("UPDATE system_settings SET setting_value=NOW() WHERE setting_key='crm_pool_last_check'")->execute();
    if ($totalRecycled > 0) {
        $checkResult = "系统自动回收了 <strong>" . implode('、', $messages) . "</strong> 的客户到公海。";
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$sourceId = intval($_GET['source_id'] ?? 0);
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$where = 'WHERE c.in_pool=1 AND c.status=1';
$params = [];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.company LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($sourceId > 0) { $where .= " AND c.source_id=?"; $params[] = $sourceId; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

$sql = "SELECT c.*, s.name as source_name,
    (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count,
    (SELECT real_name FROM users WHERE id=c.owner_id LIMIT 1) as old_owner_name,
    IFNULL(c.intended_product,'') as intended_product
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    $where ORDER BY c.pooled_at DESC, c.id DESC LIMIT $offset,$perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$sources = $pdo->query("SELECT * FROM customer_sources WHERE status=1 ORDER BY sort_order")->fetchAll();
$users = $pdo->query("SELECT id,real_name FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-water"></i> 客户公海</h1>
    <a href="customers.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回客户列表</a>
</div>

<?php if ($checkResult): ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);padding:10px 16px;margin-bottom:16px;font-size:13px;color:#1e40af;">
    <i class="fa-solid fa-rotate"></i> <?=$checkResult?>
</div>
<?php endif; ?>

<?php if ($poolDays > 0): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);padding:8px 16px;margin-bottom:16px;font-size:13px;color:#92400e;">
    <i class="fa-solid fa-circle-info"></i> 常规回收：超过 <strong><?=$poolDays?></strong> 天未跟进 → 自动归入公海。
    <?php if ($claimHours > 0): ?>认领回收：认领后 <strong><?=$claimHours?></strong> 小时内未跟进 → 强制放回公海。<?php endif; ?>
    <?php if ($isAdmin): ?><a href="settings.php">修改设置 →</a><?php endif; ?>
</div>
<?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索名称/电话/公司..." value="<?=htmlspecialchars($search)?>"></div>
    <select name="source_id" class="form-control" style="width:120px;">
        <option value="0">全部来源</option>
        <?php foreach($sources as $s): ?><option value="<?=$s['id']?>" <?=$sourceId==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="pool.php" class="btn btn-outline btn-sm">清除</a>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr>
    <th>客户名称</th><th>电话</th><th>公司</th><th>来源</th><th>意向</th><th>意向产品</th><th>原归属</th><th>跟进</th><th>归池时间</th><th>操作</th>
</tr></thead>
<tbody>
<?php if ($list): foreach ($list as $c): ?>
<tr>
    <td><a href="customer_detail.php?id=<?=$c['id']?>" style="color:var(--primary);font-weight:500;"><?=htmlspecialchars($c['name'])?></a></td>
    <td><?=htmlspecialchars($c['phone'])?:'-'?></td>
    <td><?=htmlspecialchars($c['company'])?:'-'?></td>
    <td><?=htmlspecialchars($c['source_name'])?:($c['source_id']?'-':'')?></td>
    <td><?php if($c['intention']): ?><span class="badge badge-<?=$c['intention']=='高'?'success':($c['intention']=='中'?'warning':'info')?>"><?=$c['intention']?></span><?php else: ?>-<?php endif; ?></td>
    <td><?php $prod=$c['intended_product']??''; if($prod): ?><span title="<?=htmlspecialchars($prod)?>" style="cursor:help;"><?=htmlspecialchars(mb_strlen($prod)>8?mb_substr($prod,0,8).'...':$prod)?></span><?php else: ?>-<?php endif; ?></td>
    <td><?=htmlspecialchars($c['old_owner_name']?:'-')?></td>
    <td><?=$c['followup_count']?>次</td>
    <td><?=$c['pooled_at']?date('m-d H:i',strtotime($c['pooled_at'])):'-'?></td>
    <td>
        <button class="btn btn-sm btn-success" onclick="claimCustomer(<?=$c['id']?>,'<?=htmlspecialchars(addslashes($c['name']))?>')" title="认领"><i class="fa-solid fa-hand"></i> 认领</button>
        <?php if ($isAdmin): ?>
        <button class="btn btn-sm btn-primary" onclick="showPoolAssignModal(<?=$c['id']?>,'<?=htmlspecialchars(addslashes($c['name']))?>')" title="分配给"><i class="fa-solid fa-user-plus"></i></button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-water"></i><p>公海暂无客户</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&<?=http_build_query(array_filter(['search'=>$search,'source_id'=>$sourceId]))?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?>
</div><?php endif; ?>

<!-- 分配弹窗 -->
<div class="modal-overlay" id="poolAssignModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">分配客户 - <span id="poolAssignName"></span></h3><button class="modal-close" onclick="closeModal('poolAssignModal')">&times;</button></div>
<form onsubmit="return doPoolAssign(event)">
<?=csrf_field()?>
<input type="hidden" id="poolAssignCid">
<div class="modal-body">
    <div class="form-group"><label class="form-label">分配给 <span class="required">*</span></label>
        <select id="poolAssignUser" class="form-control" required>
            <option value="">请选择业务经理</option>
            <?php foreach($users as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['real_name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('poolAssignModal')">取消</button>
    <button type="submit" class="btn btn-primary">确认分配</button>
</div>
</form></div></div>

<script>
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
function claimCustomer(id,name){
    if(!confirm('确定认领客户【'+name+'】吗？\n认领后此客户将归您管理。'))return;
    var btn = event.target.closest('button');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> 认领中...';}
    var fd=new FormData();
    fd.append('action','claim');
    fd.append('customer_id',id);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        var ct = r.headers.get('content-type')||'';
        if(ct.indexOf('application/json')===-1) throw new Error('服务端异常');
        return r.json();
    })
    .then(function(resp){
        if(resp.success){
            alert(resp.message);
            location.reload();
        } else {
            alert(resp.message);
        }
    })
    .catch(function(err){
        alert('操作失败：'+err.message);
    })
    .finally(function(){
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-hand"></i> 认领';}
    });
}
function showPoolAssignModal(id,name){
    document.getElementById('poolAssignCid').value=id;
    document.getElementById('poolAssignName').textContent=name;
    document.getElementById('poolAssignUser').selectedIndex=0;
    openModal('poolAssignModal');
}
function doPoolAssign(e){
    e.preventDefault();
    var fd=new FormData();
    fd.append('action','assign');
    fd.append('customer_id',document.getElementById('poolAssignCid').value);
    fd.append('to_user_id',document.getElementById('poolAssignUser').value);
    fd.append('_csrf_token',CSRF_TOKEN);
    fetch('ajax.php',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
    })
    .then(function(resp){
        if(resp.success){closeModal('poolAssignModal');alert(resp.message);location.reload();}
        else alert(resp.message);
    })
    .catch(function(err){
        alert('操作失败：'+err.message);
    });
    return false;
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
