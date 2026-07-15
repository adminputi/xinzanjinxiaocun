<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('product_category');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$where = ''; $params = [];
if ($search) { $where = "WHERE name LIKE ?"; $params[] = "%$search%"; }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM units $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);
$stmt = $pdo->prepare("SELECT * FROM units $where ORDER BY id ASC LIMIT $offset,$perPage"); $stmt->execute($params);
$list = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) { $error = '单位名称不能为空'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE units SET name=?,status=? WHERE id=?")->execute([$name, intval($_POST['status']??1), $id]);
                add_log(get_user_id(), 'update', 'unit', "修改单位: $name");
            } else {
                $pdo->prepare("INSERT INTO units (name,status) VALUES (?,?)")->execute([$name, 1]);
                add_log(get_user_id(), 'create', 'unit', "新增单位: $name");
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM units WHERE id=?")->execute([intval($_POST['id']??0)]);
        add_log(get_user_id(), 'delete', 'unit', "删除单位ID: " . intval($_POST['id']??0));
    }
    redirect("unit.php?page=$page&search=".urlencode($search));
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-weight-scale"></i> 商品单位</h1>
    <button class="btn btn-primary" onclick="openUnitModal()"><i class="fa-solid fa-plus"></i> 新增单位</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单位名称..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="unit.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>单位名称</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?= $item['id'] ?></td>
    <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
    <td><?= $item['status'] ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-gray">禁用</span>' ?></td>
    <td>
        <div class="table-actions">
            <button class="btn btn-sm btn-outline" onclick="editUnit(<?= $item['id'] ?>,'<?= addslashes($item['name']) ?>',<?= $item['status'] ?>)"><i class="fa-solid fa-pen"></i></button>
            <form method="post" style="display:inline" onsubmit="return confirm('确定删除该单位？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-weight-scale"></i><p>暂无单位数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?>
<div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
<a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a>
<?php endfor; ?></div>
<?php endif; ?>

<div class="modal-overlay" id="unitModal"><div class="modal"><div class="modal-header"><h3 class="modal-title" id="unitTitle">新增单位</h3><button class="modal-close" onclick="closeModal('unitModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="unitId" value="0">
<div class="modal-body">
    <div class="form-group"><label class="form-label">单位名称 <span class="required">*</span></label><input type="text" name="name" id="unitName" class="form-control" required placeholder="如：个、条、件..."></div>
    <div class="form-group"><label class="form-label">状态</label><select name="status" id="unitStatus" class="form-control"><option value="1">启用</option><option value="0">禁用</option></select></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('unitModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
function openUnitModal(){document.getElementById('unitTitle').textContent='新增单位';document.getElementById('unitId').value=0;document.getElementById('unitName').value='';document.getElementById('unitStatus').value='1';openModal('unitModal');}
function editUnit(id,name,status){document.getElementById('unitTitle').textContent='编辑单位';document.getElementById('unitId').value=id;document.getElementById('unitName').value=name;document.getElementById('unitStatus').value=status;openModal('unitModal');}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
