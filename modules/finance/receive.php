<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
// 两种入口：
//   1) finance_receive（收款记录）：完整功能 —— 列表 + 登记 + 作废
//   2) sales_receive（销售收款登记）：受限模式 —— 只能登记收款，看不到收款列表、不能作废
if (!isset($_SESSION['user_id'])) { redirect(site_url('index.php')); }
$fullFinance = check_permission('finance_receive');
$canSalesReceive = check_permission('sales_receive');
if (!$fullFinance && !$canSalesReceive) {
    $home = htmlspecialchars(site_url('index.php'));
    die('<div style="text-align:center;margin-top:100px;"><h3>无权限访问</h3><p>您没有访问收款功能的权限，请联系管理员在「角色权限」中勾选「收款记录」或「销售收款登记」。</p><a href="' . $home . '">返回首页</a></div>');
}
$limitedMode = !$fullFinance;   // 仅持 sales_receive 的角色
$pdo = getDB();

// 执行数据库迁移（含核销表与历史数据回填）
run_migrations();

// 登记完成后返回来源页（由订单/出库单详情传入；只接受站内相对路径，防开放重定向）
$backParam = trim($_GET['back'] ?? '');
$backUrl = '';
if ($backParam !== '' && !preg_match('#^(https?:)?//#i', $backParam) && strpos($backParam, '..') === false) {
    $backUrl = ltrim($backParam, '/');
}

$page = max(1, intval($_GET['page'] ?? 1));

// 支持从订单/出库单详情跳转过来直接登记收款：receive.php?order_id=X
$prefillOrderId = intval($_GET['order_id'] ?? 0);
$prefill = null;
if ($prefillOrderId > 0) {
    $st = $pdo->prepare("SELECT o.id, o.bill_no, o.customer_id, o.total_amount, COALESCE(o.received_amount,0) as received_amount, c.name as customer_name
        FROM sales_orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?");
    $st->execute([$prefillOrderId]);
    $prefill = $st->fetch();
    if ($prefill) {
        $prefill['balance'] = floatval($prefill['total_amount']) - floatval($prefill['received_amount']);
    }
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = 0; $pages = 1; $list = [];
// 受限模式不查询收款列表，避免把全部客户的收款数据暴露给销售角色
if (!$limitedMode) {
    $total = $pdo->query("SELECT COUNT(*) FROM receipts")->fetchColumn();
    $pages = ceil($total/$perPage);
    $list = $pdo->query("SELECT r.*, c.name as customer_name,
        (SELECT COALESCE(GROUP_CONCAT(CONCAT(COALESCE(o.bill_no,'预收款'), ':', FORMAT(a.amount,2)) SEPARATOR '、'), '')
            FROM receipt_allocations a LEFT JOIN sales_orders o ON o.id=a.order_id WHERE a.receipt_id=r.id) as alloc_text
        FROM receipts r LEFT JOIN customers c ON r.customer_id=c.id
        ORDER BY r.id DESC LIMIT $offset,$perPage")->fetchAll();
}

// 受限模式只能给「归属自己」的客户登记收款
$customers = $limitedMode
    ? get_options('customers','id','name',[['status','=',1],['owner_id','=',get_user_id()]])
    : get_options('customers','id','name','status=1');

$payMethods = ['bank'=>'银行转账','cash'=>'现金','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'];

// ==================== 新增收款（按核销明细入账） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    $customerId = intval($_POST['customer_id']??0);
    $amount = floatval($_POST['amount']??0);
    $payMethod = $_POST['pay_method']??'bank';
    if (!isset($payMethods[$payMethod])) { $payMethod = 'bank'; }
    $receiptDate = $_POST['receipt_date']??date('Y-m-d');
    $remark = trim($_POST['remark']??'');

    // 核销明细：alloc_order_id[] / alloc_amount[]，order_id=0 表示预收款
    $allocOrderIds = $_POST['alloc_order_id'] ?? [];
    $allocAmounts  = $_POST['alloc_amount'] ?? [];
    $allocs = [];
    if (is_array($allocOrderIds)) {
        foreach ($allocOrderIds as $i => $oid) {
            $oid = intval($oid);
            $amt = floatval($allocAmounts[$i] ?? 0);
            if (abs($amt) <= 0.000001) continue;
            $allocs[] = ['order_id' => $oid, 'amount' => $amt];
        }
    }

    // 受限模式：校验客户归属，防止构造请求给他人客户登记收款
    $custOwnerOk = true;
    if ($limitedMode && $customerId > 0) {
        $st = $pdo->prepare("SELECT owner_id FROM customers WHERE id=?");
        $st->execute([$customerId]);
        $custOwnerOk = intval($st->fetchColumn()) === intval(get_user_id());
    }
    if ($customerId <= 0) { $error = '请选择客户'; }
    elseif ($limitedMode && !$custOwnerOk) { $error = '无权限：该客户不属于您，无法登记收款'; }
    elseif ($amount <= 0) { $error = '收款金额必须大于0'; }
    else {
        $allocTotal = 0;
        foreach ($allocs as $a) { $allocTotal += $a['amount']; }
        if ($allocTotal - $amount > 0.01) {
            $error = '核销金额合计 ¥' . format_money($allocTotal) . ' 超过收款金额 ¥' . format_money($amount);
        } else {
            $pdo->beginTransaction();
            try {
                $billNo = generate_bill_no('SK');
                // 未分配的差额记为预收款（order_id=0）
                $rest = round($amount - $allocTotal, 2);
                if ($rest > 0.01) { $allocs[] = ['order_id' => 0, 'amount' => $rest]; }

                // 校验每个核销订单归属与余额
                $relatedBillNo = '';
                foreach ($allocs as $a) {
                    if ($a['order_id'] <= 0) continue;
                    $st = $pdo->prepare("SELECT id, bill_no, customer_id, total_amount, COALESCE(received_amount,0) as received_amount FROM sales_orders WHERE id=?");
                    $st->execute([$a['order_id']]);
                    $order = $st->fetch();
                    if (!$order) { throw new Exception('核销的销售订单不存在'); }
                    if (intval($order['customer_id']) !== $customerId) { throw new Exception('订单 ' . $order['bill_no'] . ' 不属于该客户'); }
                    $balance = floatval($order['total_amount']) - floatval($order['received_amount']);
                    if ($a['amount'] - $balance > 0.01) {
                        throw new Exception('订单 ' . $order['bill_no'] . ' 核销金额 ¥' . format_money($a['amount']) . ' 超过待收余额 ¥' . format_money($balance));
                    }
                    if ($relatedBillNo === '') { $relatedBillNo = $order['bill_no']; }
                }

                $st = $pdo->prepare("INSERT INTO receipts (bill_no,customer_id,amount,pay_method,receipt_date,related_bill_no,remark,user_id,created_at,order_id,status)
                    VALUES (?,?,?,?,?,?,?,?,NOW(),?,'confirmed')");
                $st->execute([$billNo,$customerId,$amount,$payMethod,$receiptDate,$relatedBillNo,$remark,get_user_id(), ($allocs[0]['order_id'] ?? 0)]);
                $receiptId = $pdo->lastInsertId();

                foreach ($allocs as $a) {
                    $pdo->prepare("INSERT INTO receipt_allocations (receipt_id,customer_id,order_id,amount,remark,user_id,created_at) VALUES (?,?,?,?,?,?,NOW())")
                        ->execute([$receiptId,$customerId,$a['order_id'],$a['amount'],$remark,get_user_id()]);
                    if ($a['order_id'] > 0) { sync_order_received($a['order_id']); }
                }

                add_log(get_user_id(), 'create', 'receipt', "收款: $billNo ¥$amount（核销 " . count($allocs) . " 项）");
                $pdo->commit();
                flash_set('收款已登记：' . $billNo, 'success');
                // 受限模式登记完直接回来源页（订单/出库单详情）
                if ($limitedMode && $backUrl !== '') { redirect(site_url($backUrl)); }
                redirect('receive.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Receive save error: '.$e->getMessage());
                $error = $e->getMessage() ?: '保存失败，请稍后重试';
            }
        }
    }
}

// ==================== 收款单作废（不物理删除，自动回冲订单已收金额） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'cancel') {
    csrf_verify();
    if ($limitedMode) {
        flash_set('无权限：收款单作废仅具备「收款记录」权限的人员可操作');
        redirect($backUrl !== '' ? site_url($backUrl) : site_url('index.php'));
    }
    $receiptId = intval($_POST['id']??0);
    $reason = trim($_POST['cancel_reason']??'');
    if ($receiptId <= 0) {
        flash_set('收款单不存在');
    } elseif ($reason === '') {
        flash_set('作废必须填写原因');
    } else {
        $st = $pdo->prepare("SELECT * FROM receipts WHERE id=?");
        $st->execute([$receiptId]);
        $receipt = $st->fetch();
        if (!$receipt) {
            flash_set('收款单不存在');
        } elseif (($receipt['status'] ?? 'confirmed') === 'cancelled') {
            flash_set('该收款单已作废，无需重复操作');
        } else {
            $pdo->beginTransaction();
            try {
                // 先记录要回冲的订单，再置为作废，最后按核销明细重算已收金额
                $st = $pdo->prepare("SELECT DISTINCT order_id FROM receipt_allocations WHERE receipt_id=? AND order_id>0");
                $st->execute([$receiptId]);
                $orderIds = $st->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE receipts SET status='cancelled', cancel_reason=? WHERE id=?")->execute([$reason, $receiptId]);
                foreach ($orderIds as $oid) { sync_order_received(intval($oid)); }

                add_log(get_user_id(), 'cancel', 'receipt', "作废收款单: {$receipt['bill_no']} 原因: $reason");
                $pdo->commit();
                flash_set('收款单已作废，关联订单已收金额已回冲：' . $receipt['bill_no'], 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Receive cancel error: '.$e->getMessage());
                flash_set('作废失败：' . $e->getMessage());
            }
        }
    }
    redirect('receive.php?page=' . $page);
}
?>
<?php flash_show(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-money-bill-wave"></i> <?= $limitedMode ? '登记收款' : '收款记录' ?></h1>
    <div style="display:flex;gap:8px;">
        <?php if ($limitedMode && $backUrl !== ''): ?>
        <a class="btn btn-outline" href="<?=htmlspecialchars(site_url($backUrl))?>"><i class="fa-solid fa-arrow-left"></i> 返回</a>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="resetRecForm();openModal('recModal')"><i class="fa-solid fa-plus"></i> 新增收款</button>
    </div>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>

<?php if ($limitedMode): ?>
<div class="card"><div class="card-body" style="font-size:13px;color:var(--gray-600);">
    您当前为<strong>销售收款登记</strong>模式：可为自己负责的客户登记收款，款项核销到对应销售订单；收款记录列表与作废由「收款记录」权限管理。
</div></div>
<?php else: ?>
<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>金额</th><th>核销明细</th><th>方式</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): $isCancel = (($item['status'] ?? 'confirmed') === 'cancelled'); ?>
<tr<?= $isCancel ? ' style="opacity:.6;text-decoration:line-through;"' : '' ?>>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['customer_name']?:'-')?></td>
    <td style="color:var(--success);font-weight:bold;">¥<?=format_money($item['amount'])?></td>
    <td style="font-size:12px;"><?=htmlspecialchars($item['alloc_text']?:'-')?></td>
    <td><?=$payMethods[$item['pay_method']]??$item['pay_method']?></td>
    <td><?=$item['receipt_date']?></td>
    <td><?php if ($isCancel): ?><span class="badge badge-gray">已作废</span><?php else: ?><span class="badge badge-success">有效</span><?php endif; ?></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,20))?><?php if ($isCancel && $item['cancel_reason']): ?><br><small style="color:var(--danger)">作废：<?=htmlspecialchars(mb_substr($item['cancel_reason'],0,20))?></small><?php endif; ?></td>
    <td>
        <?php if (!$isCancel): ?>
        <button class="btn btn-sm btn-outline" onclick="openCancelModal(<?=$item['id']?>,'<?=$item['bill_no']?>')" title="作废"><i class="fa-solid fa-ban" style="color:var(--danger)"></i></button>
        <?php else: ?>
        <span style="color:var(--gray-500);font-size:12px;">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-money-bill-wave"></i><p>暂无收款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
<?php endif; // end 非受限模式 ?>

<!-- 新增收款 -->
<div class="modal-overlay" id="recModal"><div class="modal"><div class="modal-header"><h3 class="modal-title">新增收款</h3><button class="modal-close" onclick="closeModal('recModal')">&times;</button></div>
<form method="post" id="recForm"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="modal-body">
    <div class="form-group"><label class="form-label">客户 <span class="required">*</span></label>
        <select name="customer_id" id="recCustId" class="form-control searchable" required onchange="loadRecOrders()">
            <option value="">请选择客户</option>
            <?php foreach($customers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">收款金额 <span class="required">*</span></label>
        <input type="number" step="0.01" name="amount" id="recAmount" class="form-control" required oninput="refreshAllocSummary()">
    </div>
    <div class="form-group">
        <label class="form-label">核销明细</label>
        <div style="font-size:12px;color:var(--gray-600);margin-bottom:6px;">指定这笔钱分别冲抵哪些订单；未分配的金额自动记为<strong>预收款</strong>，可在后续订单中核销。</div>
        <table id="allocTable" style="width:100%;font-size:13px;">
            <thead><tr><th style="text-align:left;">销售订单</th><th style="width:130px;text-align:left;">核销金额</th><th style="width:60px;"></th></tr></thead>
            <tbody id="allocBody"></tbody>
        </table>
        <button type="button" class="btn btn-outline btn-sm" onclick="addAllocRow()" style="margin-top:6px;"><i class="fa-solid fa-plus"></i> 添加核销订单</button>
        <div id="allocSummary" style="margin-top:8px;font-size:13px;"></div>
    </div>
    <div class="form-group"><label class="form-label">收款方式</label>
        <select name="pay_method" class="form-control"><?php foreach($payMethods as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group"><label class="form-label">收款日期</label><input type="date" name="receipt_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('recModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<?php if (!$limitedMode): ?>
<!-- 收款单作废 -->
<div class="modal-overlay" id="cancelModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">作废收款单</h3><button class="modal-close" onclick="closeModal('cancelModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" id="cancelId" value="0">
<div class="modal-body">
    <p style="margin-bottom:8px;">单号：<strong id="cancelBillNo"></strong></p>
    <div class="form-group"><label class="form-label">作废原因 <span class="required">*</span></label>
        <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="如：录入错误、款项退回"></textarea>
    </div>
    <div style="font-size:12px;color:var(--gray-600);">作废后该笔金额将从关联订单的已收金额中回冲，收款单保留留痕，不做物理删除。</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">取消</button><button type="submit" class="btn btn-danger">确认作废</button></div>
</form></div></div>
<?php endif; // end 非受限模式（作废弹窗） ?>

<script>
var recOrders = <?= json_encode($prefill ? [['id'=>$prefill['id'],'bill_no'=>$prefill['bill_no'],'total_amount'=>$prefill['total_amount'],'received_amount'=>$prefill['received_amount'],'balance'=>$prefill['balance']]] : [], JSON_UNESCAPED_UNICODE) ?>;
var recPrefill = <?= json_encode($prefill ? ['customer_id'=>intval($prefill['customer_id']),'order_id'=>intval($prefill['id']),'amount'=>max(0, floatval($prefill['balance']))] : null, JSON_UNESCAPED_UNICODE) ?>;

function resetRecForm(){
    document.getElementById('recForm').reset();
    document.getElementById('recCustId').selectedIndex = 0;
    document.getElementById('allocBody').innerHTML = '';
    recOrders = [];
    refreshAllocSummary();
}

function loadRecOrders(callback){
    var custId = document.getElementById('recCustId').value;
    document.getElementById('allocBody').innerHTML = '';
    recOrders = [];
    if (!custId) { refreshAllocSummary(); if (typeof callback === 'function') callback(); return; }
    fetch('/api/get_orders.php?type=sales&customer_id=' + custId)
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(data){
        recOrders = (data||[]).map(function(o){
            return {id:o.id, bill_no:o.bill_no, total_amount:o.total_amount, received_amount:o.received_amount||0,
                    balance:(parseFloat(o.total_amount)-(parseFloat(o.received_amount)||0))};
        });
        if (typeof initSearchableSelects === 'function') { initSearchableSelects(); }
        refreshAllocSummary();
        if (typeof callback === 'function') callback();
    })
    .catch(function(e){ console.error('加载订单失败:', e); if (typeof callback === 'function') callback(); });
}

function addAllocRow(orderId, amount){
    var tbody = document.getElementById('allocBody');
    var tr = document.createElement('tr');
    var tdSel = document.createElement('td');
    var sel = document.createElement('select');
    sel.name = 'alloc_order_id[]';
    sel.className = 'form-control';
    var opt0 = document.createElement('option');
    opt0.value = '0'; opt0.textContent = '不指定（记为预收款）';
    sel.appendChild(opt0);
    recOrders.forEach(function(o){
        var opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = o.bill_no + ' [待收¥' + parseFloat(o.balance).toFixed(2) + ']';
        sel.appendChild(opt);
    });
    if (orderId) { sel.value = String(orderId); }
    tdSel.appendChild(sel);

    var tdAmt = document.createElement('td');
    var inp = document.createElement('input');
    inp.type = 'number'; inp.step = '0.01'; inp.name = 'alloc_amount[]'; inp.className = 'form-control';
    inp.value = amount ? amount.toFixed(2) : '';
    inp.oninput = refreshAllocSummary;
    tdAmt.appendChild(inp);

    var tdDel = document.createElement('td');
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-sm btn-outline'; btn.innerHTML = '<i class="fa-solid fa-trash" style="color:var(--danger)"></i>';
    btn.onclick = function(){ tr.remove(); refreshAllocSummary(); };
    tdDel.appendChild(btn);

    tr.appendChild(tdSel); tr.appendChild(tdAmt); tr.appendChild(tdDel);
    tbody.appendChild(tr);
    sel.onchange = function(){
        var o = recOrders.filter(function(x){ return x.id === parseInt(sel.value,10); })[0];
        if (o) { inp.value = Math.max(0, o.balance).toFixed(2); refreshAllocSummary(); }
    };
    refreshAllocSummary();
}

function refreshAllocSummary(){
    var amount = parseFloat(document.getElementById('recAmount').value) || 0;
    var allocTotal = 0;
    document.querySelectorAll('#allocBody input[name="alloc_amount[]"]').forEach(function(i){
        allocTotal += parseFloat(i.value) || 0;
    });
    var rest = amount - allocTotal;
    var el = document.getElementById('allocSummary');
    var html = '收款 ¥' + amount.toFixed(2) + ' ｜ 已分配 <strong>¥' + allocTotal.toFixed(2) + '</strong>';
    html += rest > 0.005 ? ' ｜ 剩余 <strong style="color:var(--warning)">¥' + rest.toFixed(2) + '</strong>（记为预收款）'
                         : (rest < -0.005 ? ' ｜ <strong style="color:var(--danger)">已分配超过收款金额 ¥' + Math.abs(rest).toFixed(2) + '</strong>' : '');
    el.innerHTML = html;
}

function openCancelModal(id, billNo){
    document.getElementById('cancelId').value = id;
    document.getElementById('cancelBillNo').textContent = billNo;
    openModal('cancelModal');
}

// 从订单/出库单详情跳转过来：自动带出客户与待收金额
(function(){
    if (!recPrefill) return;
    var sel = document.getElementById('recCustId');
    sel.value = String(recPrefill.customer_id);
    loadRecOrders(function(){
        document.getElementById('recAmount').value = recPrefill.amount.toFixed(2);
        addAllocRow(recPrefill.order_id, recPrefill.amount);
        openModal('recModal');
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
