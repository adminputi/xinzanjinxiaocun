<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('customer_view');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$where = '';
$params = [];
if ($search) { $where = "WHERE name LIKE ? OR phone LIKE ? OR contact LIKE ?"; $params = array_fill(0,3,"%$search%"); }

$perPage = ITEMS_PER_PAGE;
$offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT * FROM customers $where ORDER BY id DESC LIMIT $offset,$perPage"); $stmt->execute($params);
$list = $stmt->fetchAll();

// 自动生成客户编码
$lastCode = $pdo->query("SELECT code FROM customers WHERE code LIKE 'KH%' AND code REGEXP '^KH[0-9]+$' ORDER BY CAST(SUBSTRING(code,3) AS UNSIGNED) DESC LIMIT 1")->fetchColumn();
if ($lastCode) {
    $nextNum = intval(substr($lastCode, 2)) + 1;
} else {
    $nextNum = 1;
}
$nextCode = 'KH' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $data = [$_POST['code']??'', $_POST['name']??'', $_POST['type']??'company', $_POST['contact']??'', $_POST['phone']??'', $_POST['email']??'', $_POST['address']??'', floatval($_POST['initial_balance']??0), $_POST['remark']??''];
        if (empty($data[1])) { $error = '客户名称不能为空'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE customers SET code=?,name=?,type=?,contact=?,phone=?,email=?,address=?,initial_balance=?,remark=? WHERE id=?")->execute(array_merge($data,[$id]));
            } else {
                $pdo->prepare("INSERT INTO customers (code,name,type,contact,phone,email,address,initial_balance,remark,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($data, [date('Y-m-d H:i:s')]));
            }
        }
    } elseif ($action === 'delete') { $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([intval($_POST['id']??0)]); }
    redirect("customer.php?page=$page&search=".urlencode($search));
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-users"></i> 客户管理</h1>
    <div class="page-actions">
        <a href="import.php?type=customer" class="btn btn-outline"><i class="fa-solid fa-upload"></i> 导入</a>
        <button class="btn btn-outline" onclick="exportCustomers()"><i class="fa-solid fa-download"></i> 导出</button>
        <button class="btn btn-primary" onclick="openModal('custModal')"><i class="fa-solid fa-plus"></i> 新增客户</button>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索客户名称/电话/联系人..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="customer.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>编码</th><th>客户名称</th><th>类型</th><th>联系人</th><th>电话</th><th>期初应收</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?= $item['id'] ?></td><td><?= htmlspecialchars($item['code']) ?></td>
    <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
    <td><?= $item['type']=='individual'?'个人':'企业' ?></td>
    <td><?= htmlspecialchars($item['contact']?:'-') ?></td><td><?= htmlspecialchars($item['phone']?:'-') ?></td>
    <td>¥<?= format_money($item['initial_balance']) ?></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="editCust(<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
        <a href="customer_detail.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="详情"><i class="fa-solid fa-eye"></i></a>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-users"></i><p>暂无客户数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?>
<div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
<a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="custModal"><div class="modal"><div class="modal-header"><h3 class="modal-title" id="custTitle">新增客户</h3><button class="modal-close" onclick="closeModal('custModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="custId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">客户名称 <span class="required">*</span></label><input type="text" name="name" id="custName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">客户编码</label><input type="text" name="code" id="custCode" class="form-control" value="<?=$nextCode?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">类型</label><select name="type" id="custType" class="form-control"><option value="company">企业</option><option value="individual">个人</option></select></div>
        <div class="form-group"><label class="form-label">联系人</label><input type="text" name="contact" id="custContact" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="custPhone" class="form-control"></div>
        <div class="form-group"><label class="form-label">邮箱</label><input type="email" name="email" id="custEmail" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="custAddr" class="form-control"></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">期初应收</label><input type="number" step="0.01" name="initial_balance" id="custBal" class="form-control" value="0"></div>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" id="custRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('custModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>function editCust(d){document.getElementById('custTitle').textContent='编辑客户';['Id','Name','Code','Type','Contact','Phone','Email','Addr','Bal','Remark'].forEach(function(f,i){var el=document.getElementById('cust'+f);if(!el)return;var keys=['id','name','code','type','contact','phone','email','address','initial_balance','remark'];el.value=d[keys[i]]||(i===0||i===8?'0':'');});openModal('custModal');}
function exportCustomers() {
    window.open('?export=csv' + window.location.search.replace(/export=csv&?/,''));
}
<?php if (isset($_GET['export']) && $_GET['export'] === 'csv'): 
    require_once __DIR__ . '/../../includes/xlsx_helper.php';
    $stmt = $pdo->prepare("SELECT * FROM customers $where ORDER BY id DESC"); $stmt->execute($params); $allCust = $stmt->fetchAll();
    $headers = ['编码','客户名称','类型','联系人','电话','邮箱','地址','期初应收','备注'];
    $rows = array_map(function($r){ return [
        $r['code'],$r['name'],$r['type']=='individual'?'个人':'企业',
        $r['contact'],$r['phone'],$r['email'],$r['address'],
        $r['initial_balance'],$r['remark']
    ]; }, $allCust);
    xlsx_export($headers, $rows, 'customers_' . date('Ymd') . '.xlsx');
endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
