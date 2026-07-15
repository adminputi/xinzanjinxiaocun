<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('master_data');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));

// ---------- 保存操作 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sortOrder = intval($_POST['sort_order'] ?? 0);
        if (!$name) $error = '状态名称不能为空';
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE tracking_statuses SET name=?, sort_order=? WHERE id=?")->execute([$name, $sortOrder, $id]);
            } else {
                $pdo->prepare("INSERT INTO tracking_statuses (name, sort_order) VALUES (?,?)")->execute([$name, $sortOrder]);
            }
            redirect('statuses.php');
        }
    } elseif ($act === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM tracking_statuses WHERE id=?")->execute([$id]);
        }
        redirect('statuses.php');
    }
}

$list = $pdo->query("SELECT * FROM tracking_statuses ORDER BY sort_order ASC")->fetchAll();
$editItem = null;
if (($editId = intval($_GET['edit'] ?? 0)) > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tracking_statuses WHERE id=?");
    $stmt->execute([$editId]);
    $editItem = $stmt->fetch();
}
?>
<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-list-check"></i> 状态管理</h1>
    <button class="btn btn-primary" onclick="openModal('statusModal')"><i class="fa-solid fa-plus"></i> 新增状态</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>排序</th><th>状态名称</th><th>创建时间</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?=$item['sort_order']?></td>
    <td><span class="badge badge-info"><?=htmlspecialchars($item['name'])?></span></td>
    <td><?=$item['created_at']?></td>
    <td>
        <a href="statuses.php?edit=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-pen"></i></a>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除该状态？')">
            <?=csrf_field()?>
            <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>">
            <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-list-check"></i><p>暂无状态数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<!-- 新增/编辑弹窗 -->
<div class="modal-overlay" id="statusModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title"><?=$editItem?'编辑状态':'新增状态'?></h3><button class="modal-close" onclick="closeModal('statusModal')">&times;</button></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?=$editItem['id']??0?>">
<div class="modal-body">
    <div class="form-group"><label class="form-label">排序号</label><input type="number" name="sort_order" class="form-control" value="<?=$editItem['sort_order']??0?>"></div>
    <div class="form-group"><label class="form-label">状态名称 <span class="required">*</span></label><input type="text" name="name" class="form-control" value="<?=htmlspecialchars($editItem['name']??'')?>" required></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('statusModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>
<?php if ($editItem): ?><script>document.addEventListener('DOMContentLoaded',function(){openModal('statusModal');});</script><?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
