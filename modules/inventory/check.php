<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('check_manage');
require_once __DIR__ . '/../../includes/migration.php';
$pdo = getDB();
run_migrations();
$page = max(1, intval($_GET['page'] ?? 1));

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM check_orders")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT c.*, w.name as warehouse_name FROM check_orders c LEFT JOIN warehouses w ON c.warehouse_id=w.id ORDER BY c.id DESC LIMIT $offset,$perPage")->fetchAll();

$warehouses = get_options('warehouses','id','name','status=1');
$categories = get_options('product_categories','id','name','status=1');
$allProducts = $pdo->query("SELECT id, sku, name FROM products WHERE status=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create') {
    csrf_verify();
    $warehouseId = intval($_POST['warehouse_id']??0);
    $checkDate = $_POST['check_date']??date('Y-m-d');
    $remark = $_POST['remark']??'';
    $scope = trim($_POST['scope'] ?? 'all');
    $categoryId = intval($_POST['category_id'] ?? 0);
    $customIds = array_values(array_filter(array_map('intval', (array)($_POST['custom_products'] ?? [])), function($v){ return $v > 0; }));

    $pdo->beginTransaction();
    try {
        // 盘点范围决定生成哪些行：只生成范围内的，范围外的根本不进明细，
        // 从源头避免「只盘几个商品，其余被当成实盘 0 而清零」
        $where = "p.status=1";
        $wp = []; // WHERE 参数（JOIN 里的仓库参数在最前）
        switch ($scope) {
            case 'category':
                if ($categoryId <= 0) throw new Exception('请选择商品分类');
                $where .= " AND p.category_id=?"; $wp[] = $categoryId;
                break;
            case 'in_stock':
                $where .= " AND COALESCE(i.quantity,0) > 0";
                break;
            case 'zero_stock':
                $where .= " AND COALESCE(i.quantity,0) = 0";
                break;
            case 'low_stock':
                $where .= " AND p.min_stock > 0 AND COALESCE(i.quantity,0) <= p.min_stock";
                break;
            case 'custom':
                if (empty($customIds)) throw new Exception('请至少勾选一个商品');
                $where .= " AND p.id IN (" . implode(',', array_fill(0, count($customIds), '?')) . ")";
                $wp = array_merge($wp, $customIds);
                break;
            default:
                $scope = 'all';
        }

        $billNo = generate_bill_no('PD');
        $pdo->prepare("INSERT INTO check_orders (bill_no,warehouse_id,status,check_date,remark,user_id,created_at) VALUES (?,?,'draft',?,?,?,?)")->execute([$billNo,$warehouseId,$checkDate,$remark,get_user_id(),date('Y-m-d H:i:s')]);
        $checkId = $pdo->lastInsertId();

        $st = $pdo->prepare("SELECT p.id as product_id, COALESCE(i.quantity, 0) as quantity
            FROM products p LEFT JOIN inventory i ON i.product_id=p.id AND i.warehouse_id=? WHERE $where");
        $st->execute(array_merge([$warehouseId], $wp));
        $stockItems = $st->fetchAll();
        if (empty($stockItems)) throw new Exception('该范围内没有可盘点的商品，请换一个范围');

        // 实盘数留 NULL 表示「还没盘」——确认时会跳过，绝不会当成 0 去清库存
        $insStmt = $pdo->prepare("INSERT INTO check_items (check_id,product_id,book_qty,actual_qty,diff_qty) VALUES (?,?,?,NULL,0)");
        foreach ($stockItems as $si) { $insStmt->execute([$checkId,$si['product_id'],$si['quantity']]); }
        add_log(get_user_id(), 'create', 'check_order', "创建盘点单: $billNo（范围：{$scope}，" . count($stockItems) . " 项）");
        $pdo->commit();
        redirect("check_edit.php?id=$checkId");
    } catch (Exception $e) { $pdo->rollBack(); error_log('Check create error: ' . $e->getMessage()); $error = '创建失败：' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'confirm') {
    csrf_verify();
    $checkId = intval($_POST['id']??0);
    $st = $pdo->prepare("SELECT * FROM check_orders WHERE id=?");
    $st->execute([$checkId]);
    $checkOrder = $st->fetch();

    // 状态守卫：已确认的盘点单不可重复确认，否则差异会被重复叠加进库存
    if (!$checkOrder) {
        flash_set('盘点单不存在');
        redirect('check.php');
    }
    if ($checkOrder['status'] === 'confirmed') {
        flash_set("盘点单 {$checkOrder['bill_no']} 已确认，不能重复确认（重复确认会把差异再次计入库存）。");
        redirect('check.php');
    }
    if ($checkOrder['status'] === 'cancelled') {
        flash_set("盘点单 {$checkOrder['bill_no']} 已作废，不能确认。");
        redirect('check.php');
    }

    // 确认逻辑抽到 includes/functions.php 的 confirm_check_order()，与 check_edit.php 共用一份。
    // 关键规则在里面：实盘数留空=未盘点，跳过不动库存（以前会把库存清零）
    $res = confirm_check_order($pdo, $checkId);
    if (!$res['ok']) flash_set($res['msg'], 'danger');
    else flash_set($res['msg'], 'success');
    redirect('check.php');
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clipboard-check"></i> 盘点管理</h1>
    <button class="btn btn-primary" onclick="openModal('checkModal')"><i class="fa-solid fa-plus"></i> 新建盘点单</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<?php flash_show(); ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>仓库</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['warehouse_name']?:'-')?></td>
    <td><?=$item['check_date']?></td>
    <td><span class="badge badge-<?=$item['status']=='confirmed'?'success':'warning'?>"><?=$item['status']=='confirmed'?'已完成':'待盘点'?></span></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'','0','20'))?></td>
    <td>
        <?php if($item['status']=='draft'): ?>
        <a href="check_edit.php?id=<?=$item['id']?>" class="btn btn-sm btn-primary">盘点</a>
        <?php endif; ?>
        <a href="check_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline"><i class="fa-solid fa-eye"></i></a>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-clipboard-check"></i><p>暂无盘点记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="checkModal"><div class="modal"><div class="modal-header"><h3 class="modal-title">新建盘点单</h3><button class="modal-close" onclick="closeModal('checkModal')">&times;</button></div>
<form method="post" id="checkCreateForm"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="modal-body">
    <div class="form-group"><label class="form-label">盘点仓库 <span class="required">*</span></label><select name="warehouse_id" class="form-control" required><option value="">选择仓库</option><?php foreach($warehouses as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">盘点日期</label><input type="date" name="check_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group">
        <label class="form-label">盘点范围</label>
        <select name="scope" id="checkScope" class="form-control" onchange="onScopeChange()">
            <option value="all">全部商品</option>
            <option value="category">按分类</option>
            <option value="in_stock">只有库存的商品（库存 &gt; 0）</option>
            <option value="zero_stock">0 库存的商品</option>
            <option value="low_stock">低库存预警的商品</option>
            <option value="custom">自己勾选几个</option>
        </select>
        <div style="font-size:12px;color:var(--gray-500);margin-top:4px;">只生成范围内的商品行，范围外的商品不参与本次盘点，<strong>不会被改动</strong>。没填实盘数量的行确认时会自动跳过。</div>
    </div>
    <div class="form-group" id="catWrap" style="display:none;">
        <label class="form-label">商品分类</label>
        <select name="category_id" id="checkCategory" class="form-control"><option value="">选择分类</option><?php foreach($categories as $k=>$v): ?><option value="<?=$k?>"><?=htmlspecialchars($v)?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group" id="custWrap" style="display:none;">
        <label class="form-label">勾选商品（按住 Ctrl / Cmd 可多选）</label>
        <input type="text" id="custSearch" class="form-control" placeholder="输入关键词过滤" oninput="filterCust()" style="margin-bottom:6px;">
        <select name="custom_products[]" id="custProducts" class="form-control" multiple size="8"><?php foreach($allProducts as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'].' ['.$p['sku'].']')?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('checkModal')">取消</button><button type="submit" class="btn btn-primary">创建盘点单</button></div>
</form></div></div>

<script>
function onScopeChange(){
    var s = document.getElementById('checkScope').value;
    document.getElementById('catWrap').style.display  = (s === 'category') ? '' : 'none';
    document.getElementById('custWrap').style.display = (s === 'custom')   ? '' : 'none';
}
function filterCust(){
    var kw = document.getElementById('custSearch').value.trim().toLowerCase();
    var opts = document.getElementById('custProducts').options;
    for (var i = 0; i < opts.length; i++) {
        opts[i].hidden = kw !== '' && opts[i].textContent.toLowerCase().indexOf(kw) === -1;
    }
}
document.getElementById('checkCreateForm').addEventListener('submit', function(){
    var s = document.getElementById('checkScope').value;
    if (s === 'category' && !document.getElementById('checkCategory').value) {
        alert('请选择商品分类'); return false;
    }
    if (s === 'custom') {
        var sel = document.getElementById('custProducts');
        var n = 0;
        for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].selected) n++; }
        if (n === 0) { alert('请至少勾选一个商品'); return false; }
    }
    return true;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
