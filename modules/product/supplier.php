<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('supplier_view');
$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$where = ''; $params = [];
if ($search) { $where = "WHERE name LIKE ? OR phone LIKE ? OR contact LIKE ?"; $params = array_fill(0,3,"%$search%"); }

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);
$stmt = $pdo->prepare("SELECT * FROM suppliers $where ORDER BY id DESC LIMIT $offset,$perPage"); $stmt->execute($params); $list = $stmt->fetchAll();

// 自动生成供应商编码
$lastSupCode = $pdo->query("SELECT code FROM suppliers WHERE code LIKE 'GYS%' AND code REGEXP '^GYS[0-9]+$' ORDER BY CAST(SUBSTRING(code,4) AS UNSIGNED) DESC LIMIT 1")->fetchColumn();
if ($lastSupCode) {
    $nextSupNum = intval(substr($lastSupCode, 3)) + 1;
} else {
    $nextSupNum = 1;
}
$nextSupCode = 'GYS' . str_pad($nextSupNum, 4, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $data = [$_POST['code']??'', $_POST['name']??'', $_POST['contact']??'', $_POST['phone']??'', $_POST['email']??'', $_POST['address']??'', $_POST['bank_name']??'', $_POST['bank_account']??'', $_POST['tax_no']??'', $_POST['remark']??''];
        if (empty($data[1])) { $error = '供应商名称不能为空'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE suppliers SET code=?,name=?,contact=?,phone=?,email=?,address=?,bank_name=?,bank_account=?,tax_no=?,remark=? WHERE id=?")->execute(array_merge($data,[$id]));
            } else {
                $pdo->prepare("INSERT INTO suppliers (code,name,contact,phone,email,address,bank_name,bank_account,tax_no,remark,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($data, [date('Y-m-d H:i:s')]));
            }
        }
    } elseif ($action === 'delete') { $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([intval($_POST['id']??0)]); }
    redirect("supplier.php?page=$page&search=".urlencode($search));
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-truck"></i> 供应商管理</h1>
    <div class="page-actions">
        <a href="import.php?type=supplier" class="btn btn-outline"><i class="fa-solid fa-upload"></i> 导入</a>
        <button class="btn btn-outline" onclick="exportSuppliers()"><i class="fa-solid fa-download"></i> 导出</button>
        <button class="btn btn-primary" onclick="openModal('supModal')"><i class="fa-solid fa-plus"></i> 新增供应商</button>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索供应商名称/电话/联系人..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="supplier.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>ID</th><th>编码</th><th>供应商名称</th><th>联系人</th><th>电话</th><th>地址</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><?= $item['id'] ?></td><td><?= htmlspecialchars($item['code']) ?></td>
    <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
    <td><?= htmlspecialchars($item['contact']?:'-') ?></td><td><?= htmlspecialchars($item['phone']?:'-') ?></td>
    <td><?= htmlspecialchars(mb_substr($item['address']?:'-',0,20)) ?></td>
    <td>
        <button class="btn btn-sm btn-outline" onclick="editSup(<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>)"><i class="fa-solid fa-pen"></i></button>
        <a href="supplier_detail.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline" title="详情"><i class="fa-solid fa-eye"></i></a>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="btn btn-sm btn-outline"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button></form>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-truck"></i><p>暂无供应商数据</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="supModal"><div class="modal"><div class="modal-header"><h3 class="modal-title" id="supTitle">新增供应商</h3><button class="modal-close" onclick="closeModal('supModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="supId" value="0">
<div class="modal-body">
    <div class="form-row">
        <div class="form-group"><label class="form-label">供应商名称 <span class="required">*</span></label><input type="text" name="name" id="supName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">供应商编码</label><input type="text" name="code" id="supCode" class="form-control" value="<?=$nextSupCode?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">联系人</label><input type="text" name="contact" id="supContact" class="form-control"></div>
        <div class="form-group"><label class="form-label">电话</label><input type="text" name="phone" id="supPhone" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">地址</label><input type="text" name="address" id="supAddr" class="form-control"></div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">开户行</label><input type="text" name="bank_name" id="supBank" class="form-control"></div>
        <div class="form-group"><label class="form-label">银行账号</label><input type="text" name="bank_account" id="supAccount" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label">税号</label><input type="text" name="tax_no" id="supTax" class="form-control"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" id="supRemark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('supModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>function editSup(d){document.getElementById('supTitle').textContent='编辑供应商';var ks=['id','code','name','contact','phone','email','address','bank_name','bank_account','tax_no','remark'];var vs=['supId','supCode','supName','supContact','supPhone','supEmail','supAddr','supBank','supAccount','supTax','supRemark'];for(var i=0;i<ks.length;i++){var el=document.getElementById(vs[i]);if(el)el.value=d[ks[i]]||(i==0?'0':'');}openModal('supModal');}
function exportSuppliers() {
    window.open('?export=csv' + window.location.search.replace(/export=csv&?/,''));
}
<?php if (isset($_GET['export']) && $_GET['export'] === 'csv'): 
    require_once __DIR__ . '/../../includes/xlsx_helper.php';
    $stmt = $pdo->prepare("SELECT * FROM suppliers $where ORDER BY id DESC"); $stmt->execute($params); $allSup = $stmt->fetchAll();
    $headers = ['编码','供应商名称','联系人','电话','邮箱','地址','开户行','银行账号','税号','备注'];
    $rows = array_map(function($r){ return [
        $r['code'],$r['name'],$r['contact'],$r['phone'],$r['email'],$r['address'],
        $r['bank_name'],$r['bank_account'],$r['tax_no'],$r['remark']
    ]; }, $allSup);
    xlsx_export($headers, $rows, 'suppliers_' . date('Ymd') . '.xlsx');
endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
