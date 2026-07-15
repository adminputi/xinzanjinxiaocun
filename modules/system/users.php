<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('system_users');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id=r.id ORDER BY u.id LIMIT $offset,$perPage")->fetchAll();
$roles = get_options('roles','id','name');
// 加载角色描述
$roleDescs = [];
$rStmt = $pdo->query("SELECT id, description FROM roles");
while ($r = $rStmt->fetch()) { $roleDescs[$r['id']] = $r['description']; }

// 冻结/解冻改为POST操作（安全）
$getAction = $_POST['action'] ?? '';
$getUid = intval($_POST['id'] ?? 0);
if ($getUid > 0 && $getAction === 'freeze_unfreeze') {
    if ($getUid != get_user_id()) {
        csrf_verify();
        $status = $_POST['new_status'] ?? '';
        $newStatus = $status === '0' ? 0 : 1;
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$newStatus, $getUid]);
        add_log(get_user_id(), 'update', 'users', ($newStatus?'解冻':'冻结')."用户ID: $getUid");
    }
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $realName = $_POST['real_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $roleId = intval($_POST['role_id'] ?? 0);
        $status = intval($_POST['status'] ?? 1);

        if (empty($username)) { $error = '用户名不能为空'; }
        else {
            // 密码复杂度校验
            $pwdError = function($pwd) {
                if (strlen($pwd) < 8) return '密码至少需要8个字符';
                if (!preg_match('/[a-z]/', $pwd)) return '密码需要包含小写字母';
                if (!preg_match('/[A-Z]/', $pwd)) return '密码需要包含大写字母';
                if (!preg_match('/[0-9]/', $pwd)) return '密码需要包含数字';
                return null;
            };
            if ($id > 0) {
                $sql = "UPDATE users SET username=?,real_name=?,email=?,phone=?,role_id=?,status=?";
                $params = [$username,$realName,$email,$phone,$roleId,$status];
                if ($password) {
                    $e = $pwdError($password);
                    if ($e) { $error = $e; }
                    else { $sql .= ",password=?"; $params[]=password_hash($password,PASSWORD_DEFAULT); }
                }
                if (!isset($error)) {
                    $pdo->prepare($sql . " WHERE id=?")->execute(array_merge($params, [$id]));
                    add_log(get_user_id(), 'update', 'users', "修改用户: $username");
                }
            } else {
                if (empty($password)) { $error = '密码不能为空'; }
                else if ($e = $pwdError($password)) { $error = $e; }
                else {
                    $pdo->prepare("INSERT INTO users (username,password,real_name,email,phone,role_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$username,password_hash($password,PASSWORD_DEFAULT),$realName,$email,$phone,$roleId,$status,date('Y-m-d H:i:s')]);
                    add_log(get_user_id(), 'create', 'users', "新增用户: $username");
                }
            }
        }
        if (!isset($error)) redirect('users.php');
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id != get_user_id()) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            add_log(get_user_id(), 'delete', 'users', "删除用户ID: $id");
        }
        redirect('users.php');
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-users-gear"></i> 用户管理</h1>
    <button class="btn btn-primary" onclick="resetUserForm();openModal('userModal')"><i class="fa-solid fa-plus"></i> 新增用户</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>用户名</th><th>姓名</th><th>角色</th><th>邮箱</th><th>最后登录</th><th>状态</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $u): ?>
<tr>
    <td><?=$u['id']?></td><td><strong><?=htmlspecialchars($u['username'])?></strong></td>
    <td><?=htmlspecialchars($u['real_name'])?></td><td><?=htmlspecialchars($u['role_name']?:'-')?></td>
    <td><?=htmlspecialchars($u['email']?:'-')?></td><td><?=$u['last_login']?:'-'?></td>
    <td><?=$u['status']?'<span class="badge badge-success">启用</span>':'<span class="badge badge-gray">禁用</span>'?></td>
    <td>
        <button class="btn btn-sm btn-outline" data-user="<?=htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>" onclick="editUser(JSON.parse(this.getAttribute('data-user')))"><i class="fa-solid fa-pen"></i></button>
        <?php if($u['id']!=get_user_id()): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('<?=$u['status']==1?'确定冻结该用户？冻结后无法登录':'确定解冻该用户？'?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="freeze_unfreeze">
            <input type="hidden" name="id" value="<?=$u['id']?>">
            <input type="hidden" name="new_status" value="<?=$u['status']==1?'0':'1'?>">
            <button class="btn btn-sm btn-outline" title="<?=$u['status']==1?'冻结':'解冻'?>"><i class="fa-solid <?=$u['status']==1?'fa-snowflake':'fa-sun'?>" style="color:<?=$u['status']==1?'var(--warning)':'var(--success)'?>"></i></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除该用户？此操作不可恢复')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-users-gear"></i><p>暂无用户</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="userModal"><div class="modal"><div class="modal-header"><h3 class="modal-title" id="uTitle">新增用户</h3><button class="modal-close" onclick="closeModal('userModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="uid" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">用户名 <span class="required">*</span></label><input type="text" name="username" id="uUsername" class="form-control" required></div>
        <div class="form-group"><label class="form-label">密码 <span class="required" id="pwdReq">*</span></label><input type="password" name="password" id="uPassword" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">真实姓名</label><input type="text" name="real_name" id="uRealName" class="form-control"></div>
        <div class="form-group"><label class="form-label">角色</label><select name="role_id" id="uRole" class="form-control"><?php foreach($roles as $k=>$v): $desc = $roleDescs[$k] ?? ''; ?><option value="<?=$k?>"><?=$v?><?=$desc?' — '.htmlspecialchars($desc):''?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">邮箱</label><input type="email" name="email" id="uEmail" class="form-control"></div>
        <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="uPhone" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">状态</label><select name="status" id="uStatus" class="form-control"><option value="1">启用</option><option value="0">禁用</option></select></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('userModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
function resetUserForm(){
    document.getElementById('uTitle').textContent='新增用户';
    document.getElementById('uid').value='0';
    document.getElementById('uUsername').value='';
    document.getElementById('uPassword').value=''; 
    document.getElementById('uPassword').required=true;
    document.getElementById('pwdReq').style.display='inline';
    document.getElementById('uRealName').value='';
    document.getElementById('uRole').selectedIndex=0;
    document.getElementById('uEmail').value='';
    document.getElementById('uPhone').value='';
    document.getElementById('uStatus').value='1';
}
function editUser(d){
    document.getElementById('uTitle').textContent='编辑用户';
    document.getElementById('uid').value=d.id;
    document.getElementById('uUsername').value=d.username;
    document.getElementById('uPassword').value=''; document.getElementById('uPassword').required=false;
    document.getElementById('pwdReq').style.display='none';
    document.getElementById('uRealName').value=d.real_name||'';
    document.getElementById('uRole').value=d.role_id;
    document.getElementById('uEmail').value=d.email||'';
    document.getElementById('uPhone').value=d.phone||'';
    document.getElementById('uStatus').value=d.status;
    openModal('userModal');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
