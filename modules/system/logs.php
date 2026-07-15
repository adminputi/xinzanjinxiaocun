<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('system_logs');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$module = $_GET['module'] ?? '';
$search = $_GET['search'] ?? '';

// 处理删除和清除操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $did = intval($_POST['id'] ?? 0);
        if ($did > 0) { $pdo->prepare("DELETE FROM operation_logs WHERE id=?")->execute([$did]); }
        redirect("logs.php?page=$page&date_from=$dateFrom&date_to=$dateTo&module=$module&search=".urlencode($search));
    } elseif ($action === 'clear_all') {
        $clearWhere = "WHERE created_at BETWEEN ? AND ?";
        $clearParams = [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'];
        if ($module) { $clearWhere .= " AND module=?"; $clearParams[]=$module; }
        if ($search) { $clearWhere .= " AND content LIKE ?"; $clearParams[]="%$search%"; }
        $pdo->prepare("DELETE FROM operation_logs $clearWhere")->execute($clearParams);
        redirect("logs.php?page=1&date_from=$dateFrom&date_to=$dateTo&module=$module&search=".urlencode($search));
    }
}

$where = "WHERE l.created_at BETWEEN ? AND ?";
$params = [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'];
if ($module) { $where .= " AND l.module=?"; $params[]=$module; }
if ($search) { $where .= " AND (u.real_name LIKE ? OR l.content LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM operation_logs l LEFT JOIN users u ON l.user_id=u.id $where");
$stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT l.*, u.real_name as user_name FROM operation_logs l LEFT JOIN users u ON l.user_id=u.id $where ORDER BY l.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

// 模块列表
$modules = $pdo->query("SELECT DISTINCT module FROM operation_logs WHERE module!='' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clipboard-list"></i> 操作日志</h1>
</div>

<form class="filter-bar" method="get">
    <input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=$dateTo?>" style="min-width:130px;">
    <select name="module" class="form-control" style="min-width:130px;"><option value="">全部模块</option><?php foreach($modules as $m): ?><option value="<?=$m?>" <?=$module==$m?'selected':''?>><?=$m?></option><?php endforeach; ?></select>
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索..." value="<?=htmlspecialchars($search)?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search||$module||$dateFrom!=date('Y-m-01')||$dateTo!=date('Y-m-d')): ?><a href="logs.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>
<?php if ($total > 0): ?>
<form method="post" class="filter-bar" style="justify-content:flex-end;padding-top:0;" onsubmit="return confirm('确定清除当前筛选条件下的所有日志吗？此操作不可恢复！')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_all">
    <input type="hidden" name="date_from" value="<?=$dateFrom?>"><input type="hidden" name="date_to" value="<?=$dateTo?>">
    <input type="hidden" name="module" value="<?=$module?>"><input type="hidden" name="search" value="<?=htmlspecialchars($search)?>">
    <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> 清除全部日志</button>
</form>
<?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>模块</th><th>内容</th><th>IP</th><th style="width:60px">操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $l): ?>
<tr>
    <td><?=$l['created_at']?></td>
    <td><strong><?=htmlspecialchars($l['user_name']?:'系统')?></strong></td>
    <td><span class="badge badge-<?=['login'=>'success','logout'=>'warning','create'=>'info','update'=>'primary','delete'=>'danger','confirm'=>'success'][$l['action']]??'gray'?>"><?=htmlspecialchars($l['action'])?></span></td>
    <td><?=htmlspecialchars($l['module'])?></td>
    <td><?=htmlspecialchars(mb_substr($l['content']?:'','0','60'))?></td>
    <td><?=$l['ip_address']?></td>
    <td>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?=$l['id']?>">
            <button class="btn btn-sm btn-outline" style="color:var(--danger)"><i class="fa-solid fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-clipboard-list"></i><p>暂无日志</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>&module=<?=$module?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
