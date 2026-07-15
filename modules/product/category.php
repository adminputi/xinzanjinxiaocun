<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('product_category');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $parentId = intval($_POST['parent_id'] ?? 0);
        $sortOrder = intval($_POST['sort_order'] ?? 0);
        if ($name) {
            if ($id > 0) {
                $pdo->prepare("UPDATE product_categories SET name=?,parent_id=?,sort_order=? WHERE id=?")->execute([$name,$parentId,$sortOrder,$id]);
                add_log(get_user_id(), 'update', 'category', "修改分类: $name");
            } else {
                $pdo->prepare("INSERT INTO product_categories (name,parent_id,sort_order,created_at) VALUES (?,?,?,?)")->execute([$name,$parentId,$sortOrder,date('Y-m-d H:i:s')]);
                add_log(get_user_id(), 'create', 'category', "新增分类: $name");
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM product_categories WHERE id=?")->execute([$id]);
        add_log(get_user_id(), 'delete', 'category', "删除分类ID: $id");
    }
    redirect('category.php');
}

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id=c.id) as product_count FROM product_categories c ORDER BY sort_order, id")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-tags"></i> 商品分类</h1>
    <button class="btn btn-primary" onclick="openModal('categoryModal')"><i class="fa-solid fa-plus"></i> 新增分类</button>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>分类名称</th><th>排序</th><th>商品数</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                    <?php if ($categories): foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= $cat['id'] ?></td>
                        <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                        <td><?= $cat['sort_order'] ?></td>
                        <td><span class="badge badge-info"><?= $cat['product_count'] ?></span></td>
                        <td><?= $cat['status'] ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-gray">禁用</span>' ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline" onclick="editCat(<?= htmlspecialchars(json_encode($cat, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
                            <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cat['id'] ?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-tags"></i><p>暂无分类数据</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="categoryModal">
    <div class="modal modal-sm">
        <div class="modal-header"><h3 class="modal-title" id="catTitle">新增分类</h3><button class="modal-close" onclick="closeModal('categoryModal')">&times;</button></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="catId" value="0">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">分类名称 <span class="required">*</span></label><input type="text" name="name" id="catName" class="form-control" required></div>
                <div class="form-group"><label class="form-label">排序</label><input type="number" name="sort_order" id="catSort" class="form-control" value="0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('categoryModal')">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCat(data) { document.getElementById('catTitle').textContent='编辑分类'; document.getElementById('catId').value=data.id; document.getElementById('catName').value=data.name; document.getElementById('catSort').value=data.sort_order; openModal('categoryModal'); }
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
