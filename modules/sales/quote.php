<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

$isAjax = (($_POST['_ajax'] ?? '') === '1' || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_quote');
$pdo = getDB();
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$isAdmin = (get_user_role() === 'admin');

$where = ''; $params = [];
$conditions = [];
if ($search) {
    $conditions[] = "(q.bill_no LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
// 非admin用户只看自己创建的报价单
if (!$isAdmin) {
    $conditions[] = "q.user_id = ?";
    $params[] = get_user_id();
}
if ($conditions) {
    $where = "WHERE " . implode(" AND ", $conditions);
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id $where"); $stmt->execute($params); $total = $stmt->fetchColumn();
$pages = ceil($total/$perPage);

$stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, u.real_name as employee_name, so.status as order_status, so.bill_no as order_bill_no, ct.id as contract_id, ct.contract_no FROM sales_quotes q LEFT JOIN customers c ON q.customer_id=c.id LEFT JOIN users u ON q.employee_id=u.id LEFT JOIN sales_orders so ON q.order_id=so.id LEFT JOIN sales_contracts ct ON q.contract_id=ct.id $where ORDER BY q.id DESC LIMIT $offset,$perPage");
$stmt->execute($params); $list = $stmt->fetchAll();

$statusLabels = ['draft'=>'可编辑','quoted'=>'已转订单','contracted'=>'已转合同','withdrawn'=>'已撤回'];
$statusBadges = ['draft'=>'warning','quoted'=>'info','contracted'=>'primary','withdrawn'=>'gray'];

// ===== 删除 / 转订单 / 撤回：统一处理，AJAX 返回 JSON，否则 PRG 跳转 =====
function quote_action($pdo, $action, $qid, $isAdmin) {
    $stmt = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=?");
    $stmt->execute([$qid]);
    $quote = $stmt->fetch();
    if (!$quote) return ['ok' => false, 'msg' => '报价单不存在'];
    if (!$isAdmin && ($quote['user_id'] ?? 0) != get_user_id()) return ['ok' => false, 'msg' => '无权限'];

    if ($action === 'delete') {
        // draft（未转订单）与 withdrawn（已撤回，关联订单已删除）可删
        if (!in_array($quote['status'], ['draft', 'withdrawn'], true)) return ['ok' => false, 'msg' => '该报价单已转订单，无法删除，请先撤回'];
        // 已关联合同的报价单不能删：合同明细取自报价单，删了会让合同失去来源。
        // 删除订单回退后的报价单正是「draft 但仍带 contract_id」，靠这条拦住
        if (!empty($quote['contract_id'])) return ['ok' => false, 'msg' => '该报价单已生成销售合同，无法删除，请先删除对应合同'];
        // 兜底：已撤回的单据 order_id 应已置空，若仍关联订单则拒绝删除，避免留下无主订单
        if (!empty($quote['order_id'])) {
            $stOrder = $pdo->prepare("SELECT id FROM sales_orders WHERE id=? LIMIT 1");
            $stOrder->execute([$quote['order_id']]);
            if ($stOrder->fetch()) return ['ok' => false, 'msg' => '该报价单仍关联销售订单，无法删除，请先撤回'];
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM sales_quotes WHERE id=?")->execute([$qid]);
            add_log(get_user_id(), 'delete', 'sales_quote', "删除报价单: {$quote['bill_no']}");
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Quote delete error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '删除失败：' . $e->getMessage()];
        }
        return ['ok' => true, 'msg' => '报价单已删除'];
    }

    if ($action === 'convert') {
        if (!in_array($quote['status'], ['draft', 'withdrawn'], true)) return ['ok' => false, 'msg' => '当前状态不可转订单'];
        // 回退后的报价单状态回到 draft 但 contract_id 还在。此时直接转单会绕过合同
        // 另起一条履约链，两边对不上——履约必须走合同转订单
        if (!empty($quote['contract_id'])) return ['ok' => false, 'msg' => '该报价单已生成销售合同，请通过合同生成销售订单'];

        $pdo->beginTransaction();
        try {
            $stmt2 = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=?");
            $stmt2->execute([$qid]);
            $items = $stmt2->fetchAll();
            if (empty($items)) { $pdo->rollBack(); return ['ok' => false, 'msg' => '报价单没有明细']; }

            $wh = $pdo->query("SELECT id FROM warehouses WHERE status=1 LIMIT 1")->fetch();
            $warehouseId = $wh ? $wh['id'] : 0;
            if (!$warehouseId) { $pdo->rollBack(); return ['ok' => false, 'msg' => '系统未设置仓库，请先在仓库管理中添加仓库']; }

            $orderBillNo = generate_bill_no('XS');
            $pdo->prepare("INSERT INTO sales_orders (bill_no,customer_id,warehouse_id,total_amount,status,order_date,employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orderBillNo, $quote['customer_id'], $warehouseId, $quote['total_amount'], 'draft', $quote['quote_date'], $quote['employee_id'], $quote['remark'], get_user_id(), date('Y-m-d H:i:s')]);
            $orderId = $pdo->lastInsertId();

            $insStmt = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_id,quantity,price,amount,remark) VALUES (?,?,?,?,?,?)");
            foreach ($items as $it) {
                $insStmt->execute([$orderId, $it['product_id'], $it['quantity'], $it['price'], $it['amount'], $it['remark']]);
            }

            $pdo->prepare("UPDATE sales_quotes SET status='quoted', order_id=? WHERE id=?")->execute([$orderId, $qid]);
            add_log(get_user_id(), 'create', 'sales_quote', "报价单转订单: {$quote['bill_no']} → {$orderBillNo}");
            $pdo->commit();
            return ['ok' => true, 'msg' => "已转为销售订单 {$orderBillNo}"];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Quote convert error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '转换失败：' . $e->getMessage()];
        }
    }

    if ($action === 'withdraw') {
        if ($quote['status'] !== 'quoted') return ['ok' => false, 'msg' => '当前状态不可撤回'];
        if (!$quote['order_id']) return ['ok' => false, 'msg' => '未关联订单'];

        $stmt2 = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
        $stmt2->execute([$quote['order_id']]);
        $order = $stmt2->fetch();
        if (!$order) {
            // 订单已被手动删除，直接恢复报价单
            $pdo->prepare("UPDATE sales_quotes SET status='withdrawn', order_id=NULL WHERE id=?")->execute([$qid]);
            add_log(get_user_id(), 'update', 'sales_quote', "撤回报价单（订单已不存在）: {$quote['bill_no']}");
            return ['ok' => true, 'msg' => '已撤回（关联订单已不存在）'];
        }
        if ($order['status'] === 'shipped') return ['ok' => false, 'msg' => '关联的销售订单已出库，无法撤回'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$quote['order_id']]);
            $pdo->prepare("DELETE FROM sales_orders WHERE id=?")->execute([$quote['order_id']]);
            $pdo->prepare("UPDATE sales_quotes SET status='withdrawn', order_id=NULL WHERE id=?")->execute([$qid]);
            add_log(get_user_id(), 'update', 'sales_quote', "撤回报价单: {$quote['bill_no']}，删除关联订单: {$order['bill_no']}");
            $pdo->commit();
            return ['ok' => true, 'msg' => "已撤回，关联订单 {$order['bill_no']} 已删除"];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Quote withdraw error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '撤回失败：' . $e->getMessage()];
        }
    }

    // 报价转合同：生成一份草稿合同并跳到合同表单补全条款
    // 合同不复制明细——明细始终取报价单，打印时作为附件，避免两处数据不一致
    if ($action === 'to_contract') {
        if (!in_array($quote['status'], ['draft', 'withdrawn'], true)) {
            return ['ok' => false, 'msg' => '当前状态不可转合同'];
        }
        // 一个报价单只允许生成一份合同
        $stc = $pdo->prepare("SELECT id, contract_no FROM sales_contracts WHERE quote_id=? LIMIT 1");
        $stc->execute([$qid]);
        $exist = $stc->fetch();
        if ($exist) {
            return ['ok' => true, 'msg' => '该报价单已生成合同 ' . $exist['contract_no'] . '，已为您打开', 'contract_id' => $exist['id']];
        }

        $pdo->beginTransaction();
        try {
            $contractNo = generate_bill_no('HT');
            $pdo->prepare("INSERT INTO sales_contracts (contract_no, customer_id, quote_id, total_amount, payment_type, prep_days, sign_date, employee_id, remark, user_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $contractNo, $quote['customer_id'], $qid, $quote['total_amount'], 'full', 7,
                    $quote['quote_date'] ?: date('Y-m-d'), $quote['employee_id'], $quote['remark'], get_user_id()
                ]);
            $contractId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE sales_quotes SET status='contracted', contract_id=? WHERE id=?")->execute([$contractId, $qid]);
            add_log(get_user_id(), 'create', 'sales_contract', "报价单转合同: {$quote['bill_no']} → {$contractNo}");
            $pdo->commit();
            return ['ok' => true, 'msg' => "已生成合同 {$contractNo}，请补全合同条款", 'contract_id' => $contractId];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Quote to_contract error: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '转合同失败：' . $e->getMessage()];
        }
    }

    return ['ok' => false, 'msg' => '未知操作'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qAction = $_POST['action'] ?? '';
    if (in_array($qAction, ['delete', 'convert', 'withdraw', 'to_contract'], true)) {
        $qr = null;
        try {
            csrf_verify();
            $qr = quote_action($pdo, $qAction, intval($_POST['id'] ?? 0), $isAdmin);
        } catch (Exception $e) {
            error_log('Quote action error: ' . $e->getMessage());
            $qr = ['ok' => false, 'msg' => '操作失败：' . $e->getMessage()];
        }

        if ($isAjax) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($qr, JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 转合同后跳到合同表单继续补全条款，而不是回到报价列表
        if ($qAction === 'to_contract' && $qr['ok'] && !empty($qr['contract_id'])) {
            flash_set($qr['msg'], 'success');
            redirect('contract_form.php?id=' . intval($qr['contract_id']));
        }
        flash_set($qr['msg'], $qr['ok'] ? 'success' : 'danger');
        redirect("quote.php?page=$page");
    }
}

if (!$isAjax) {
    require_once __DIR__ . '/../../includes/header.php';
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> 销售报价</h1>
    <a href="quote_form.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> 新增销售报价</a>
</div>

<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="search" class="form-control" placeholder="搜索单号/客户..." value="<?= htmlspecialchars($search) ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if($search): ?><a href="quote.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>金额</th><th>业务员</th><th>报价日期</th><th>关联订单</th><th>状态</th><th>操作</th></tr></thead>
<tbody id="quoteTbody">
<?php if ($list): foreach ($list as $item): 
    // 判断关联订单是否已出库
    $orderShipped = ($item['order_status'] === 'shipped');
?>
<tr>
    <td><a href="quote_view.php?id=<?=$item['id']?>"><strong><?= htmlspecialchars($item['bill_no']) ?></strong></a></td>
    <td><?= htmlspecialchars($item['customer_name']?:'-') ?></td>
    <td><strong>¥<?= format_money($item['total_amount']) ?></strong></td>
    <td><?= htmlspecialchars($item['employee_name']?:'-') ?></td>
    <td><?= $item['quote_date'] ?></td>
    <td><?php if ($item['order_bill_no']): ?><a href="order_view.php?id=<?=$item['order_id']?>"><?= htmlspecialchars($item['order_bill_no']) ?></a><?php elseif ($item['contract_no']): ?><a href="contract_form.php?id=<?=intval($item['contract_id'])?>"><?= htmlspecialchars($item['contract_no']) ?></a><?php else: ?>-<?php endif; ?></td>
    <td><span class="badge badge-<?= $statusBadges[$item['status']]??'gray' ?>"><?= $statusLabels[$item['status']]??$item['status'] ?></span></td>
    <td>
                                    <div class="table-actions">
                                        <!-- 复制：生成内容相同的副本（新单号），所有状态均可复制 -->
                                        <a href="quote_form.php?id=<?=$item['id']?>&copy=1" class="btn btn-sm btn-outline" title="复制该报价单，生成一份新单号的报价单"><i class="fa-solid fa-copy"></i> 复制</a>
                                        <?php if ($orderShipped): ?>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'draft' && !empty($item['contract_id'])): ?>
            <!-- 删除订单后回退回来的报价单：仍关联着合同，只开放改明细。
                 转单/转合同/删除都归合同模块管，避免出现两条互不相干的履约链 -->
            <a href="quote_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'draft'): ?>
            <a href="quote_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <button type="button" class="btn btn-sm btn-success" onclick="quoteAction(<?=$item['id']?>,'convert','确定将该报价单转为销售订单吗？转换后报价单将被锁定。')">转销售订单</button>
            <button type="button" class="btn btn-sm btn-primary" onclick="quoteAction(<?=$item['id']?>,'to_contract','确定将该报价单转为销售合同吗？将生成一份草稿合同，明细沿用本报价单。')">转合同</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="quoteAction(<?=$item['id']?>,'delete','确定删除该报价单吗？删除后不可恢复。')">删除</button>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'quoted'): ?>
            <button type="button" class="btn btn-sm btn-warning" onclick="quoteAction(<?=$item['id']?>,'withdraw','确定撤回该报价吗？关联的销售订单将被删除，报价单可再次转订单。')">撤回重新报价</button>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'contracted'): ?>
            <!-- 已转合同：此后由合同模块接管，撤回/删除请在合同列表操作 -->
            <a href="contract_form.php?id=<?=intval($item['contract_id'])?>" class="btn btn-sm btn-primary">查看合同</a>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php elseif ($item['status'] === 'withdrawn'): ?>
            <a href="quote_form.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <button type="button" class="btn btn-sm btn-success" onclick="quoteAction(<?=$item['id']?>,'convert','确定重新转为销售订单吗？')">转销售订单</button>
            <button type="button" class="btn btn-sm btn-primary" onclick="quoteAction(<?=$item['id']?>,'to_contract','确定将该报价单转为销售合同吗？将生成一份草稿合同，明细沿用本报价单。')">转合同</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="quoteAction(<?=$item['id']?>,'delete','确定删除该报价单吗？删除后不可恢复。')">删除</button>
            <a href="quote_view.php?id=<?=$item['id']?>" class="btn btn-sm btn-outline">详情</a>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>暂无销售报价单</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination" id="quotePagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span>
<?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?page=<?=$i?>&search=<?=urlencode($search)?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<script>
// 删除/转订单/撤回：AJAX 提交，成功后就地刷新列表，不再整页跳转（避免空白页）
function quoteAction(id, action, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    doQuoteAction(id, action, 0);
}
// retried：令牌失效后自动重试一次（新令牌会写回页面隐藏域）
function doQuoteAction(id, action, retried) {
    var fd = new URLSearchParams();
    var tok = document.querySelector('input[name="_csrf_token"]');
    fd.append('_csrf_token', tok ? tok.value : '');
    fd.append('action', action);
    fd.append('id', id);
    fd.append('_ajax', '1');
    fetch('quote.php', {
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
              doQuoteAction(id, action, 1);
              return;
          }
          var qmsg = res.msg || res.message || '';
          if (res && res.csrf_expired && res.csrf_debug) console.warn('CSRF 诊断：', res.csrf_debug);
          if (window.showToast) window.showToast(qmsg, res.ok ? 'success' : 'error');
          else alert(qmsg);
          if (res.ok) refreshQuoteList();
      })
      .catch(function(e){
          if (window.showToast) window.showToast('请求失败：' + e.message, 'error');
          else alert('请求失败：' + e.message);
      });
}

function refreshQuoteList() {
    fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(html){
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var nt = doc.querySelector('#quoteTbody');
          var np = doc.querySelector('#quotePagination');
          var ct = document.querySelector('#quoteTbody');
          var cp = document.querySelector('#quotePagination');
          if (nt && ct) ct.innerHTML = nt.innerHTML;
          if (np && cp) cp.innerHTML = np.innerHTML;
      })
      .catch(function(){ location.href = window.location.href; });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
