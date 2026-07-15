<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('warehouse_view');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $data = [$_POST['name']??'', $_POST['code']??'', $_POST['address']??'', $_POST['manager']??'', $_POST['phone']??''];
        if (empty($data[0])) { $error = '仓库名称不能为空'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE warehouses SET name=?,code=?,address=?,manager=?,phone=? WHERE id=?")->execute(array_merge($data,[$id]));
                add_log(get_user_id(), 'update', 'warehouse', "修改仓库: {$data[0]}");
            } else {
                $pdo->prepare("INSERT INTO warehouses (name,code,address,manager,phone,created_at) VALUES (?,?,?,?,?,?)")->execute(array_merge($data, [date('Y-m-d H:i:s')]));
                add_log(get_user_id(), 'create', 'warehouse', "新增仓库: {$data[0]}");
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM warehouses WHERE id=?")->execute([intval($_POST['id']??0)]);
    }
    redirect('warehouse.php');
}

$list = $pdo->query("SELECT w.*, (SELECT COUNT(DISTINCT product_id) FROM inventory WHERE warehouse_id=w.id AND quantity>0) as product_count FROM warehouses w ORDER BY id")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-warehouse"></i> 仓库管理</h1>
    <button class="btn btn-primary" onclick="openModal('whModal')"><i class="fa-solid fa-plus"></i> 新增仓库</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>编码</th><th>仓库名称</th><th>地址</th><th>负责人</th><th>电话</th><th>商品数</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($list as $item): ?>
<tr>
    <td><?= $item['id'] ?></td>
    <td><?= htmlspecialchars($item['code']) ?></td>
    <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
    <td><?= htmlspecialchars($item['address']?:'-') ?></td>
    <td><?= htmlspecialchars($item['manager']?:'-') ?></td>
    <td><?= htmlspecialchars($item['phone']?:'-') ?></td>
    <td><span class="badge badge-info"><?= $item['product_count'] ?></span></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="editWh(<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div></div>

<div class="modal-overlay" id="whModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title" id="whTitle">新增仓库</h3><button class="modal-close" onclick="closeModal('whModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="whId" value="0">
<div class="modal-body">
    <div class="form-group"><label class="form-label">仓库名称 <span class="required">*</span></label><input type="text" name="name" id="whName" class="form-control" required></div>
    <div class="form-group"><label class="form-label">仓库编码</label><input type="text" name="code" id="whCode" class="form-control"></div>
    <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="whAddr" class="form-control"></div>
    <div class="form-row"><div class="form-group"><label class="form-label">负责人</label><input type="text" name="manager" id="whMgr" class="form-control"></div>
    <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="whPhone" class="form-control"></div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('whModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>function editWh(d){document.getElementById('whTitle').textContent='编辑仓库';document.getElementById('whId').value=d.id;document.getElementById('whName').value=d.name;document.getElementById('whCode').value=d.code||'';document.getElementById('whAddr').value=d.address||'';document.getElementById('whMgr').value=d.manager||'';document.getElementById('whPhone').value=d.phone||'';openModal('whModal');}</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
