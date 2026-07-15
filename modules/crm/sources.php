<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('crm_source_manage');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sort = intval($_POST['sort_order'] ?? 0);
        if ($name) {
            if ($id > 0) {
                $pdo->prepare("UPDATE customer_sources SET name=?,sort_order=? WHERE id=?")->execute([$name,$sort,$id]);
            } else {
                $pdo->prepare("INSERT INTO customer_sources (name,sort_order) VALUES (?,?)")->execute([$name,$sort]);
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM customer_sources WHERE id=?")->execute([intval($_POST['id']??0)]);
    } elseif ($action === 'toggle') {
        $id = intval($_POST['id']??0);
        $src = $pdo->prepare("SELECT status FROM customer_sources WHERE id=?");
        $src->execute([$id]);
        $s = $src->fetchColumn();
        $pdo->prepare("UPDATE customer_sources SET status=? WHERE id=?")->execute([$s?0:1,$id]);
    }
    redirect('sources.php');
}
$sources = $pdo->query("SELECT * FROM customer_sources ORDER BY sort_order, id")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-filter"></i> 客户来源管理</h1>
    <button class="btn btn-primary" onclick="openSourceModal()"><i class="fa-solid fa-plus"></i> 新增来源</button>
</div>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>来源名称</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($sources as $s): ?>
<tr>
    <td><?=$s['id']?></td>
    <td><?=htmlspecialchars($s['name'])?></td>
    <td><?=$s['sort_order']?></td>
    <td><span class="badge badge-<?=$s['status']?'success':'warning'?>"><?=$s['status']?'启用':'禁用'?></span></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="editSource(<?=$s['id']?>,'<?=htmlspecialchars(addslashes($s['name']))?>',<?=$s['sort_order']?>)"><i class="fa-solid fa-pen"></i></button>
        <form method="post" style="display:inline">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?=$s['id']?>">
            <button class="btn btn-sm btn-outline" style="color:var(--warning)"><?=$s['status']?'禁用':'启用'?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?=$s['id']?>">
            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($sources)): ?>
    <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-filter"></i><p>暂无客户来源</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<!-- 新增/编辑弹窗 -->
<div class="modal-overlay" id="sourceModal"><div class="modal modal-sm">
<div class="modal-header"><h3 class="modal-title" id="srcModalTitle">新增客户来源</h3><button class="modal-close" onclick="closeModal('sourceModal')">&times;</button></div>
<form method="post">
<?=csrf_field()?>
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="srcId" value="0">
<div class="modal-body">
    <div class="form-group"><label class="form-label">来源名称 <span class="required">*</span></label>
        <input type="text" name="name" id="srcName" class="form-control" required>
    </div>
    <div class="form-group"><label class="form-label">排序</label>
        <input type="number" name="sort_order" id="srcSort" class="form-control" value="0">
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline" onclick="closeModal('sourceModal')">取消</button>
    <button type="submit" class="btn btn-primary">保存</button>
</div>
</form>
</div></div>

<script>
function openSourceModal(){
    document.getElementById('srcId').value=0;
    document.getElementById('srcName').value='';
    document.getElementById('srcSort').value=0;
    document.getElementById('srcModalTitle').textContent='新增客户来源';
    openModal('sourceModal');
}
function editSource(id,name,sort){
    document.getElementById('srcId').value=id;
    document.getElementById('srcName').value=name;
    document.getElementById('srcSort').value=sort;
    document.getElementById('srcModalTitle').textContent='编辑客户来源';
    openModal('sourceModal');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
