<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('system_roles');
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $permissions = $_POST['permissions'] ?? [];
        if (!is_array($permissions)) $permissions = [];
        // 只接受已知权限码，防止伪造提交
        $permissions = array_values(array_intersect($permissions, array_keys(perm_code_list())));
        // 未获得商业授权的功能（如售后追踪、CRM）不允许通过表单改动：一律沿用数据库中的原值。
        // 这样授权到期时不会误清空已有权限，授权恢复后自动生效。
        if ($id > 0) {
            $oldStmt = $pdo->prepare("SELECT permissions FROM roles WHERE id=?");
            $oldStmt->execute([$id]);
            $oldPerms = json_decode($oldStmt->fetchColumn() ?: '[]', true);
            if (is_array($oldPerms)) {
                foreach ($oldPerms as $op) {
                    if (!perm_is_licensed($op) && !in_array($op, $permissions, true)) {
                        $permissions[] = $op;
                    }
                }
            }
        } else {
            // 新增角色：未授权的功能一律不赋权
            $permissions = array_values(array_filter($permissions, function ($p) { return perm_is_licensed($p); }));
        }
        if (empty($name)) { $error = '角色名称不能为空'; }
        else {
            $permJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
            error_log("[roles.php] 保存角色: id=$id name=$name permissions=$permJson");
            if ($id > 0) {
                $pdo->prepare("UPDATE roles SET name=?,description=?,permissions=? WHERE id=?")->execute([$name,$description,$permJson,$id]);
            } else {
                $pdo->prepare("INSERT INTO roles (name,description,permissions,created_at) VALUES (?,?,?,?)")->execute([$name,$description,$permJson,date('Y-m-d H:i:s')]);
            }
            add_log(get_user_id(), 'save', 'roles', "角色: $name");
            redirect('roles.php');
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 1) { $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$id]); }
        redirect('roles.php');
    }
}

$roles = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id=r.id) as user_count FROM roles r ORDER BY id")->fetchAll();

// 所有权限定义（取自 includes/functions.php 的 perm_code_list()，保证全局唯一来源）
$allPerms = perm_code_list();

// 统计当前未获授权的功能（对应权限项在界面上置灰不可选）
$lockedPermCount = 0;
$lockedFeatures = [];
foreach (array_keys($allPerms) as $pk) {
    if (perm_is_licensed($pk)) continue;
    $lockedPermCount++;
    $feature = perm_requires_feature($pk);
    if ($feature !== null && !isset($lockedFeatures[$feature])) {
        $lockedFeatures[$feature] = ['tracking' => '售后追踪', 'crm' => 'CRM'][$feature] ?? $feature;
    }
}
$lockedFeatureNames = array_values($lockedFeatures);
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-shield-halved"></i> 角色权限</h1>
    <button class="btn btn-primary" onclick="openModal('roleModal');document.getElementById('rTitle').textContent='新增角色';document.getElementById('rid').value=0;document.getElementById('rname').value='';document.getElementById('rdesc').value='';document.querySelectorAll('.perm-check').forEach(c=>c.checked=false);"><i class="fa-solid fa-plus"></i> 新增角色</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>角色名称</th><th>描述</th><th>用户数</th><th>权限数</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($roles as $r): $perms = json_decode($r['permissions']?:'[]',true); ?>
<tr>
    <td><?=$r['id']?></td><td><strong><?=htmlspecialchars($r['name'])?></strong></td>
    <td><?=htmlspecialchars($r['description']?:'-')?></td>
    <td><span class="badge badge-info"><?=$r['user_count']?></span></td>
    <td><span class="badge badge-primary"><?=count($perms)?></span></td>
    <td>
        <button class="btn btn-sm btn-outline" data-role="<?=htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>" onclick="editRole(JSON.parse(this.getAttribute('data-role')))"><i class="fa-solid fa-pen"></i></button>
        <?php if($r['id']>1 && $r['user_count']==0): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div></div>

<div class="modal-overlay" id="roleModal"><div class="modal modal-lg"><div class="modal-header"><h3 class="modal-title" id="rTitle">新增角色</h3><button class="modal-close" onclick="closeModal('roleModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="rid" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">角色名称 <span class="required">*</span></label><input type="text" name="name" id="rname" class="form-control" required></div>
        <div class="form-group"><label class="form-label">描述</label><input type="text" name="description" id="rdesc" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">权限设置</label>
        <?php if ($lockedPermCount > 0): ?>
        <div style="margin-bottom:8px;padding:8px 10px;background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);font-size:12px;color:#92400e;">
            <i class="fa-solid fa-triangle-exclamation"></i> 有 <?=$lockedPermCount?> 项功能（<?=htmlspecialchars(implode('、', $lockedFeatureNames))?>）当前未获得授权，已置为<strong>灰色不可选</strong>；如需赋权请先完成功能授权。
        </div>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;max-height:400px;overflow-y:auto;padding:12px;border:1px solid var(--gray-200);border-radius:var(--radius);">
            <?php foreach ($allPerms as $pk => $pv): $licensed = perm_is_licensed($pk); ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:<?=$licensed?'pointer':'not-allowed'?>;" title="<?= $licensed ? htmlspecialchars($pk) : htmlspecialchars($pv . '：该功能未授权，无法赋权') ?>">
                <input type="checkbox" name="permissions[]" value="<?=$pk?>" class="perm-check" <?= $licensed ? '' : 'disabled' ?>>
                <span style="<?= $licensed ? '' : 'color:var(--gray-400);' ?>"><?=$pv?><?= $licensed ? '' : ' <small style="color:#b45309;">（未授权）</small>' ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('roleModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
function editRole(d){
    document.getElementById('rTitle').textContent='编辑角色';
    document.getElementById('rid').value=d.id;
    document.getElementById('rname').value=d.name;
    document.getElementById('rdesc').value=d.description||'';
    var perms=JSON.parse(d.permissions||'[]');
    document.querySelectorAll('.perm-check').forEach(function(c){c.checked=perms.includes(c.value);});
    openModal('roleModal');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
