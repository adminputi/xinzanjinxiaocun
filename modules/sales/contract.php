<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

$isAjax = (($_POST['_ajax'] ?? '') === '1' || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_contract');
$pdo = getDB();
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$isAdmin = (get_user_role() === 'admin');

$where = ''; $params = [];
$conditions = [];
if ($search) {
    $conditions[] = "(ct.contract_no LIKE ? OR c.name LIKE ? OR q.bill_no LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
// 非admin用户只看自己创建的合同
if (!$isAdmin) {
    $conditions[] = "ct.user_id = ?";
    $params[] = get_user_id();
}
if ($conditions) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_contracts ct LEFT JOIN customers c ON ct.customer_id=c.id LEFT JOIN sales_quotes q ON ct.quote_id=q.id $where");
$stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT ct.*, c.name as customer_name, u.real_name as employee_name, q.bill_no as quote_no
    FROM sales_contracts ct
    LEFT JOIN customers c ON ct.customer_id=c.id
    LEFT JOIN users u ON ct.employee_id=u.id
    LEFT JOIN sales_quotes q ON ct.quote_id=q.id
    $where ORDER BY ct.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

// 各合同已转订单金额（用于显示履约进度）
$orderedMap = [];
if ($list) {
    $ids = array_column($list, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT contract_id, SUM(total_amount) amt FROM sales_orders WHERE contract_id IN ($ph) AND status<>'cancelled' GROUP BY contract_id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) { $orderedMap[$r['contract_id']] = floatval($r['amt']); }
}

$statusLabels = ['draft'=>'草稿','confirmed'=>'已生效','executing'=>'执行中','completed'=>'已完成','terminated'=>'已终止'];
$statusBadges = ['draft'=>'warning','confirmed'=>'info','executing'=>'primary','completed'=>'success','terminated'=>'gray'];

// ===== 删除 / 确认生效 / 终止：统一处理，AJAX 返回 JSON，否则 PRG 跳转 =====
function contract_action($pdo, $action, $cid, $isAdmin) {
    $stmt = $pdo->prepare("SELECT * FROM sales_contracts WHERE id=?");
    $stmt->execute([$cid]);
    $contract = $stmt->fetch();
    if (!$contract) return ['ok' => false, 'msg' => '合同不存在'];
    if (!$isAdmin && ($contract['user_id'] ?? 0) != get_user_id()) return ['ok' => false, 'msg' => '无权限'];

    if ($action === 'delete') {
        // 放行 confirmed：删掉订单后合同会回退到「已生效」，不给删就永远退不回报价单。
        // 有没有订单由下面的检查把关，所以放宽这里不会误删已履约的合同
        if (!in_array($contract['status'], ['draft', 'confirmed', 'terminated'], true)) {
            return ['ok' => false, 'msg' => '仅草稿、已生效或已终止的合同可删除'];
        }
        // 已生成订单的合同不允许删，避免留下无主订单（已取消的订单不算履约）
        $st = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE contract_id=? AND status<>'cancelled'");
        $st->execute([$cid]);
        if (intval($st->fetchColumn()) > 0) return ['ok' => false, 'msg' => '该合同已生成销售订单，无法删除'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM sales_contracts WHERE id=?")->execute([$cid]);
            // 解绑来源报价单，使其可再次转合同或转订单
            $pdo->prepare("UPDATE sales_quotes SET status='draft', contract_id=NULL WHERE id=? AND contract_id=?")
                ->execute([$contract['quote_id'], $cid]);
            add_log(get_user_id(), 'delete', 'sales_contract', "删除销售合同: {$contract['contract_no']}");
            $pdo->commit();
            return ['ok' => true, 'msg' => '合同已删除'];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Contract delete error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    if ($action === 'confirm') {
        if ($contract['status'] !== 'draft') return ['ok' => false, 'msg' => '仅草稿状态可确认生效'];
        $pdo->prepare("UPDATE sales_contracts SET status='confirmed', effective_date=COALESCE(effective_date, sign_date, CURDATE()) WHERE id=?")
            ->execute([$cid]);
        add_log(get_user_id(), 'update', 'sales_contract', "合同确认生效: {$contract['contract_no']}");
        return ['ok' => true, 'msg' => '合同已生效'];
    }

    if ($action === 'terminate') {
        if (!in_array($contract['status'], ['confirmed', 'executing'], true)) {
            return ['ok' => false, 'msg' => '当前状态不可终止'];
        }
        $pdo->prepare("UPDATE sales_contracts SET status='terminated' WHERE id=?")->execute([$cid]);
        add_log(get_user_id(), 'update', 'sales_contract', "合同终止: {$contract['contract_no']}");
        return ['ok' => true, 'msg' => '合同已终止'];
    }

    return ['ok' => false, 'msg' => '未知操作'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cAction = $_POST['action'] ?? '';
    if (in_array($cAction, ['delete', 'confirm', 'terminate'], true)) {
        $cr = null;
        try {
            csrf_verify();
            $cr = contract_action($pdo, $cAction, intval($_POST['id'] ?? 0), $isAdmin);
        } catch (Exception $e) {
            error_log('Contract action error: ' . $e->getMessage());
            $cr = ['ok' => false, 'msg' => '操作失败：' . $e->getMessage()];
        }

        if ($isAjax) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($cr, JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set($cr['msg'], $cr['ok'] ? 'success' : 'danger');
        redirect("contract.php?page=$page");
    }
}

if (!$isAjax) {
    require_once __DIR__ . '/../../includes/header.php';
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-signature"></i> 销售合同</h1>
    <div style="display:flex;gap:8px;">
        <?php if ($isAdmin): ?>
        <a href="payment_type.php" class="btn btn-outline"><i class="fa-solid fa-money-bill-wave"></i> 付款方式</a>
        <?php endif; ?>
        <a href="contract_form.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 新增销售合同</a>
    </div>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索合同号/客户/报价单号..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="contract.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>合同号</th><th>客户</th><th>来源报价单</th><th>合同金额</th><th>已下单</th><th>业务员</th><th>签约日期</th><th>状态</th><th>操作</th></tr></thead>
<tbody id="contractTbody">
<?php if ($list): foreach ($list as $item):
    $ordered = $orderedMap[$item['id']] ?? 0;
    $remain  = floatval($item['total_amount']) - $ordered;
?>
<tr>
    <td><a href="contract_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['contract_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['customer_name']?:'-') ?></td>
    <td><a href="quote_view.php?id=<?=$item['quote_id']?>"><?= htmlspecialchars($item['quote_no']?:'-') ?></a></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td>¥<?= format_money($ordered) ?><?php if ($remain > 0.009): ?><small style="color:var(--gray-500);"> 剩<?= format_money($remain) ?></small><?php endif; ?></td>
    <td><?= htmlspecialchars($item['employee_name']?:'-') ?></td>
    <td><?= htmlspecialchars($item['sign_date']?:'-') ?></td>
    <td><span class="badge badge-<?= $statusBadges[$item['status']]??'gray' ?>"><?= $statusLabels[$item['status']]??$item['status'] ?></span></td>
    <td>
        <div class="table-actions">
            <?php
            // 编辑权限：草稿随时可改；已生效也放行——删掉订单后合同会回退到这个状态，
            // 不让它改的话「转订单后想改合同」就彻底卡死。
            // 有订单在履约时状态是 executing，本来就进不到这个分支
            $editable = in_array($item['status'], ['draft', 'confirmed'], true);
            ?>
            <?php if ($editable): ?>
            <a href="contract_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <?php endif; ?>
            <?php if ($item['status'] === 'draft'): ?>
            <button type="button" class="btn btn-sm btn-success" onclick="contractAction(<?=$item['id']?>,'confirm','确定将该合同确认生效吗？生效后可生成销售订单。')">确认生效</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="contractAction(<?=$item['id']?>,'delete','确定删除该合同吗？删除后不可恢复。')">删除</button>
            <?php elseif ($item['status'] === 'confirmed'): ?>
            <a href="contract_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-primary">转订单/详情</a>
            <button type="button" class="btn btn-sm btn-danger" onclick="contractAction(<?=$item['id']?>,'delete','确定删除该合同吗？删除后来源报价单将恢复可编辑，可重新转合同。')">删除</button>
            <button type="button" class="btn btn-sm btn-warning" onclick="contractAction(<?=$item['id']?>,'terminate','确定终止该合同吗？')">终止</button>
            <?php elseif ($item['status'] === 'executing'): ?>
            <a href="contract_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-primary">转订单/详情</a>
            <button type="button" class="btn btn-sm btn-warning" onclick="contractAction(<?=$item['id']?>,'terminate','确定终止该合同吗？已生成的销售订单不受影响。')">终止</button>
            <?php else: ?>
            <a href="contract_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-file-signature"></i><p>暂无销售合同</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination" id="contractPagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<script>
// 删除/确认生效/终止：AJAX 提交，成功后就地刷新列表，不再整页跳转（避免空白页）
function contractAction(id, action, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    doContractAction(id, action, 0);
}
// retried：令牌失效后自动重试一次（新令牌会写回页面隐藏域）
function doContractAction(id, action, retried) {
    var fd = new URLSearchParams();
    var tok = document.querySelector('input[name="_csrf_token"]');
    fd.append('_csrf_token', tok ? tok.value : '');
    fd.append('action', action);
    fd.append('id', id);
    fd.append('_ajax', '1');
    fetch('contract.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin',
        body: fd.toString()
    }).then(function(r){ return r.json(); })
      .then(function(res){
          if (res && res.csrf_expired && !retried) {
              if (res.csrf_token) {
                  document.querySelectorAll('input[name="_csrf_token"]').forEach(function(inp){ inp.value = res.csrf_token; });
              }
              doContractAction(id, action, 1);
              return;
          }
          var cmsg = res.msg || res.message || '';
          if (window.showToast) window.showToast(cmsg, res.ok ? 'success' : 'error');
          else alert(cmsg);
          if (res.ok) refreshContractList();
      })
      .catch(function(e){
          if (window.showToast) window.showToast('请求失败：' + e.message, 'error');
          else alert('请求失败：' + e.message);
      });
}

function refreshContractList() {
    fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(html){
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var nt = doc.querySelector('#contractTbody');
          var np = doc.querySelector('#contractPagination');
          var ct = document.querySelector('#contractTbody');
          var cp = document.querySelector('#contractPagination');
          if (nt && ct) ct.innerHTML = nt.innerHTML;
          if (np && cp) cp.innerHTML = np.innerHTML;
      })
      .catch(function(){ location.href = window.location.href; });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
