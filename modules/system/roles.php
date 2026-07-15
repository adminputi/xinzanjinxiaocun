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

// 所有权限定义
$allPerms = [
    'dashboard' => '首页看板',
    'master_data' => '主数据模块',
    'product_view' => '商品查看', 'product_category' => '商品分类管理', 'warehouse_view' => '仓库查看',
    'customer_view' => '客户查看', 'supplier_view' => '供应商查看',
    'purchase_order' => '采购订单', 'purchase_instock' => '采购入库', 'purchase_return' => '采购退货', 'purchase_reconcile' => '采购对账',
    'sales_order' => '销售订单', 'sales_outstock' => '销售出库', 'sales_return' => '销售退货', 'sales_reconcile' => '客户对账', 'print_template' => '打印模板',
    'inventory_view' => '库存查看', 'inventory_log' => '库存变动', 'transfer_manage' => '调拨管理', 'check_manage' => '盘点管理', 'loss_manage' => '报损报溢',
    'finance_arpay' => '应收应付', 'finance_receive' => '收款记录', 'finance_payment' => '付款记录', 'finance_aging' => '账龄分析',
    'report_sales' => '销售报表', 'report_purchase' => '采购报表', 'report_inventory' => '库存报表', 'report_performance' => '业绩报表', 'report_io' => '出入库汇总',
    'system_users' => '用户管理', 'system_roles' => '角色管理', 'system_logs' => '操作日志', 'system_settings' => '系统设置',
    'crm_customer_view' => 'CRM客户查看', 'crm_customer_edit' => 'CRM客户编辑', 'crm_pool_claim' => 'CRM公海认领',
    'crm_pool_manage' => 'CRM公海管理', 'crm_followup_view' => 'CRM跟进查看', 'crm_followup_add' => 'CRM跟进添加',
    'crm_source_manage' => 'CRM来源管理', 'crm_report' => 'CRM报表', 'crm_setting' => 'CRM公海设置',
];
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
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;max-height:400px;overflow-y:auto;padding:12px;border:1px solid var(--gray-200);border-radius:var(--radius);">
            <?php foreach ($allPerms as $pk => $pv): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;"><input type="checkbox" name="permissions[]" value="<?=$pk?>" class="perm-check"> <?=$pv?></label>
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
