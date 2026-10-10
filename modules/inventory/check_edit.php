<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('check_manage');
require_once __DIR__ . '/../../includes/migration.php';
$pdo = getDB();
run_migrations();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, w.name as warehouse_name FROM check_orders c LEFT JOIN warehouses w ON c.warehouse_id=w.id WHERE c.id=?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('单据不存在');
if ($order['status'] != 'draft') { redirect('check_view.php?id='.$id); }

$stmt = $pdo->prepare("SELECT ci.*, p.name as product_name, p.sku, p.spec FROM check_items ci JOIN products p ON ci.product_id=p.id WHERE ci.check_id=? ORDER BY p.name");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ids = $_POST['item_id'] ?? [];
    $actuals = $_POST['actual_qty'] ?? [];
    $remarks = $_POST['item_remark'] ?? [];

    // 不管是save还是confirm，都先保存盘点数据
    if (!empty($ids)) {
        $pdo->beginTransaction();
        try {
            // 实盘数留空 = 未盘点：actual_qty 存 NULL、差异记 0，确认时整行跳过、不动库存。
            // 以前建单就把所有商品的行生成好、实盘数默认填 0，只盘几个商品时其余行会被
            // 当成「实盘 0」把库存清零。改成留空后从根子上不会再误清。
            $bookMap = [];
            foreach ($items as $it) { $bookMap[intval($it['id'])] = floatval($it['book_qty']); }
            $updStmt = $pdo->prepare("UPDATE check_items SET actual_qty=?, diff_qty=?, remark=? WHERE id=?");
            foreach ($ids as $i => $iid) {
                $iid = intval($iid);
                $raw = trim((string)($actuals[$i] ?? ''));
                $remark = $remarks[$i] ?? '';
                if ($raw === '') {
                    $updStmt->execute([null, 0, $remark, $iid]);
                } else {
                    $actual = floatval($raw);
                    $updStmt->execute([$actual, $actual - ($bookMap[$iid] ?? 0), $remark, $iid]);
                }
            }
            $pdo->commit();
            $success = '盘点数据已保存';
            $st = $pdo->prepare("SELECT ci.*, p.name as product_name, p.sku, p.spec FROM check_items ci JOIN products p ON ci.product_id=p.id WHERE ci.check_id=? ORDER BY p.name");
            $st->execute([$id]);
            $items = $st->fetchAll();
        } catch (Exception $e) { $pdo->rollBack(); error_log('Check edit save error: ' . $e->getMessage()); $error = '保存失败，请查看系统日志'; }
    }

    // 确认盘点：统一走 includes/functions.php 的 confirm_check_order()，与 check.php 共用一份。
    // 原来两处各写一份，改一处就会漏另一处（差异重复叠加 / 未盘行被清零）。
    if (($_POST['action']??'') === 'confirm' && !isset($error)) {
        $res = confirm_check_order($pdo, $id);
        if (!$res['ok']) {
            $error = $res['msg'];
        } else {
            flash_set($res['msg'], 'success');
            redirect('check.php');
        }
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-clipboard-list"></i> 盘点 #<?=$order['bill_no']?></h1>
    <div class="page-actions">
        <?php if (isset($success)): ?><span class="badge badge-success"><?=$success?></span><?php endif; ?>
        <a href="check.php" class="btn btn-outline">返回</a>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<form method="post" id="checkForm">
<?= csrf_field() ?>
<input type="hidden" name="action" id="checkAction" value="save">
<div class="card">
    <div class="card-header"><span>仓库：<strong><?=htmlspecialchars($order['warehouse_name'])?></strong> | 日期：<?=$order['check_date']?></span>
        <span id="countStat" style="font-size:13px;color:var(--gray-500);"></span>
    </div>
    <div style="padding:8px 12px;background:var(--gray-50);font-size:13px;color:var(--gray-600);border-bottom:1px solid var(--gray-200);">
        实盘数量<strong>留空 = 未盘点</strong>，确认时会自动跳过、不动库存；确实盘出来是 0 的请填 <strong>0</strong>。只盘几个商品时，其余行保持留空即可。
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>商品</th><th>SKU</th><th>规格</th><th>账面库存</th><th>实盘数量</th><th>差异</th><th>备注</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item):
                    $isCounted = $item['actual_qty'] !== null;
                    $diff = $isCounted ? floatval($item['actual_qty']) - floatval($item['book_qty']) : 0;
                ?>
                <tr>
                    <td><strong><?=htmlspecialchars($item['product_name'])?></strong></td>
                    <td><?=$item['sku']?></td>
                    <td><?=$item['spec']?:'-'?></td>
                    <td><span class="badge badge-info book-cell"><?=$item['book_qty']?></span></td>
                    <td><input type="number" step="0.01" name="actual_qty[]" class="form-control actual-input" value="<?=$isCounted?floatval($item['actual_qty']):''?>" placeholder="未盘" style="width:120px;" onchange="markDiff(this)" oninput="markDiff(this)"></td>
                    <td class="diff-cell" style="font-weight:bold;">
                        <?php if (!$isCounted): ?>
                        <span style="color:var(--gray-400);font-weight:normal;">未盘</span>
                        <?php else: ?>
                        <span style="color:<?=$diff!=0?($diff>0?'var(--success)':'var(--danger)'):'var(--gray-500)'?>;"><?=$diff!=0?($diff>0?'+'.$diff:$diff):'0'?></span>
                        <?php endif; ?>
                    </td>
                    <td><input type="text" name="item_remark[]" class="form-control" value="<?=htmlspecialchars($item['remark'])?>" style="width:150px;" placeholder="差异原因"></td>
                    <input type="hidden" name="item_id[]" value="<?=$item['id']?>">
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="mt-2 flex-between">
    <span></span>
    <div class="form-row" style="gap:8px;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存盘点数据</button>
        <button type="button" class="btn btn-success" onclick="confirmCheck()"><i class="fa-solid fa-check"></i> 确认盘点</button>
    </div>
</div>
</form>

<script>
// 录入实盘数时实时算差异；留空显示「未盘」，确认时该行会被跳过
function markDiff(inp){
    var row = inp.closest('tr');
    var bookEl = row ? row.querySelector('.book-cell') : null;
    var cell = row ? row.querySelector('.diff-cell') : null;
    if (!bookEl || !cell) return;
    var book = parseFloat(bookEl.textContent) || 0;
    var v = inp.value.trim();
    if (v === '') {
        cell.innerHTML = '<span style="color:var(--gray-400);font-weight:normal;">未盘</span>';
    } else {
        var d = Math.round((parseFloat(v) - book) * 100) / 100;
        if (isNaN(d)) d = 0;
        var color = d > 0 ? 'var(--success)' : (d < 0 ? 'var(--danger)' : 'var(--gray-500)');
        cell.innerHTML = '<span style="color:' + color + ';">' + (d > 0 ? '+' + d : d) + '</span>';
    }
    updateStat();
}
function updateStat(){
    var total = 0, counted = 0, diffed = 0;
    var inputs = document.querySelectorAll('.actual-input');
    inputs.forEach(function(inp){
        total++;
        if (inp.value.trim() !== '') counted++;
        var row = inp.closest('tr');
        var cell = row ? row.querySelector('.diff-cell') : null;
        if (cell && cell.textContent.indexOf('未盘') === -1) {
            var n = parseFloat(cell.textContent.replace('+', ''));
            if (!isNaN(n) && n !== 0) diffed++;
        }
    });
    var el = document.getElementById('countStat');
    if (el) {
        el.textContent = '共 ' + total + ' 项｜已盘 ' + counted + ' 项｜未盘 ' + (total - counted) + ' 项｜有差异 ' + diffed + ' 项';
    }
}
function confirmCheck(){
    var total = 0, counted = 0;
    document.querySelectorAll('.actual-input').forEach(function(inp){
        total++;
        if (inp.value.trim() !== '') counted++;
    });
    if (counted === 0) { alert('还没有录入任何实盘数量，请先录入'); return; }
    var msg = '本次将按 ' + counted + ' 项实盘数量更新库存';
    if (counted < total) msg += '，其余 ' + (total - counted) + ' 项未盘点会跳过（不会把库存清零）';
    if (!confirm(msg + '。是否继续？')) return;
    document.getElementById('checkAction').value='confirm';
    document.getElementById('checkForm').submit();
}
document.addEventListener('DOMContentLoaded', function(){ updateStat(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
