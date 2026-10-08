<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('finance_payment');
$pdo = getDB();

// 执行数据库迁移（含付款核销表与历史数据回填）
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));

// 支持从采购订单详情跳转过来直接登记付款：payment.php?order_id=X
$prefillOrderId = intval($_GET['order_id'] ?? 0);
$prefill = null;
if ($prefillOrderId > 0) {
    $st = $pdo->prepare("SELECT o.id, o.bill_no, o.supplier_id, o.total_amount, COALESCE(o.paid_amount,0) as paid_amount, s.name as supplier_name
        FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.id=?");
    $st->execute([$prefillOrderId]);
    $prefill = $st->fetch();
    if ($prefill) {
        $prefill['balance'] = floatval($prefill['total_amount']) - floatval($prefill['paid_amount']);
    }
}

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT p.*, s.name as supplier_name,
    (SELECT COALESCE(GROUP_CONCAT(CONCAT(COALESCE(o.bill_no,'预付款'), ':', FORMAT(a.amount,2)) SEPARATOR '、'), '')
        FROM payment_allocations a LEFT JOIN purchase_orders o ON o.id=a.order_id WHERE a.payment_id=p.id) as alloc_text
    FROM payments p LEFT JOIN suppliers s ON p.supplier_id=s.id ORDER BY p.id DESC LIMIT $offset,$perPage")->fetchAll();

$suppliers = get_options('suppliers','id','name','status=1');

$payMethods = ['bank'=>'银行转账','cash'=>'现金','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'];

// ==================== 新增付款（按核销明细入账） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    $supplierId = intval($_POST['supplier_id']??0);
    $amount = floatval($_POST['amount']??0);
    $payMethod = $_POST['pay_method']??'bank';
    if (!isset($payMethods[$payMethod])) { $payMethod = 'bank'; }
    $paymentDate = $_POST['payment_date']??date('Y-m-d');
    $remark = trim($_POST['remark']??'');

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

    if ($supplierId <= 0) { $error = '请选择供应商'; }
    elseif ($amount <= 0) { $error = '付款金额必须大于0'; }
    else {
        $allocTotal = 0;
        foreach ($allocs as $a) { $allocTotal += $a['amount']; }
        if ($allocTotal - $amount > 0.01) {
            $error = '核销金额合计 ¥' . format_money($allocTotal) . ' 超过付款金额 ¥' . format_money($amount);
        } else {
            $pdo->beginTransaction();
            try {
                $billNo = generate_bill_no('FK');
                $rest = round($amount - $allocTotal, 2);
                if ($rest > 0.01) { $allocs[] = ['order_id' => 0, 'amount' => $rest]; }

                $relatedBillNo = '';
                foreach ($allocs as $a) {
                    if ($a['order_id'] <= 0) continue;
                    $st = $pdo->prepare("SELECT id, bill_no, supplier_id, total_amount, COALESCE(paid_amount,0) as paid_amount FROM purchase_orders WHERE id=?");
                    $st->execute([$a['order_id']]);
                    $order = $st->fetch();
                    if (!$order) { throw new Exception('核销的采购订单不存在'); }
                    if (intval($order['supplier_id']) !== $supplierId) { throw new Exception('订单 ' . $order['bill_no'] . ' 不属于该供应商'); }
                    $balance = floatval($order['total_amount']) - floatval($order['paid_amount']);
                    if ($a['amount'] - $balance > 0.01) {
                        throw new Exception('订单 ' . $order['bill_no'] . ' 核销金额 ¥' . format_money($a['amount']) . ' 超过待付余额 ¥' . format_money($balance));
                    }
                    if ($relatedBillNo === '') { $relatedBillNo = $order['bill_no']; }
                }

                $st = $pdo->prepare("INSERT INTO payments (bill_no,supplier_id,amount,pay_method,payment_date,related_bill_no,remark,user_id,created_at,order_id,status)
                    VALUES (?,?,?,?,?,?,?,?,NOW(),?,'confirmed')");
                $st->execute([$billNo,$supplierId,$amount,$payMethod,$paymentDate,$relatedBillNo,$remark,get_user_id(), ($allocs[0]['order_id'] ?? 0)]);
                $paymentId = $pdo->lastInsertId();

                foreach ($allocs as $a) {
                    $pdo->prepare("INSERT INTO payment_allocations (payment_id,supplier_id,order_id,amount,remark,user_id,created_at) VALUES (?,?,?,?,?,?,NOW())")
                        ->execute([$paymentId,$supplierId,$a['order_id'],$a['amount'],$remark,get_user_id()]);
                    if ($a['order_id'] > 0) { sync_purchase_order_paid($a['order_id']); }
                }

                add_log(get_user_id(), 'create', 'payment', "付款: $billNo ¥$amount（核销 " . count($allocs) . " 项）");
                $pdo->commit();
                flash_set('付款已登记：' . $billNo, 'success');
                redirect('payment.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Payment save error: '.$e->getMessage());
                $error = $e->getMessage() ?: '保存失败，请稍后重试';
            }
        }
    }
}

// ==================== 付款单作废（回冲采购订单已付金额） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'cancel') {
    csrf_verify();
    $paymentId = intval($_POST['id']??0);
    $reason = trim($_POST['cancel_reason']??'');
    if ($paymentId <= 0) {
        flash_set('付款单不存在');
    } elseif ($reason === '') {
        flash_set('作废必须填写原因');
    } else {
        $st = $pdo->prepare("SELECT * FROM payments WHERE id=?");
        $st->execute([$paymentId]);
        $payment = $st->fetch();
        if (!$payment) {
            flash_set('付款单不存在');
        } elseif (($payment['status'] ?? 'confirmed') === 'cancelled') {
            flash_set('该付款单已作废，无需重复操作');
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT DISTINCT order_id FROM payment_allocations WHERE payment_id=? AND order_id>0");
                $st->execute([$paymentId]);
                $orderIds = $st->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE payments SET status='cancelled', cancel_reason=? WHERE id=?")->execute([$reason, $paymentId]);
                foreach ($orderIds as $oid) { sync_purchase_order_paid(intval($oid)); }

                add_log(get_user_id(), 'cancel', 'payment', "作废付款单: {$payment['bill_no']} 原因: $reason");
                $pdo->commit();
                flash_set('付款单已作废，关联订单已付金额已回冲：' . $payment['bill_no'], 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Payment cancel error: '.$e->getMessage());
                flash_set('作废失败：' . $e->getMessage());
            }
        }
    }
    redirect('payment.php?page=' . $page);
}
?>
<?php flash_show(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-credit-card"></i> 付款记录</h1>
    <button class="btn btn-primary" onclick="resetPayForm();openModal('payModal')"><i class="fa-solid fa-plus"></i> 新增付款</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>供应商</th><th>金额</th><th>核销明细</th><th>方式</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): $isCancel = (($item['status'] ?? 'confirmed') === 'cancelled'); ?>
<tr<?= $isCancel ? ' style="opacity:.6;text-decoration:line-through;"' : '' ?>>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['supplier_name']?:'-')?></td>
    <td style="color:var(--danger);font-weight:bold;">¥<?=format_money($item['amount'])?></td>
    <td style="font-size:12px;"><?=htmlspecialchars($item['alloc_text']?:'-')?></td>
    <td><?=$payMethods[$item['pay_method']]??$item['pay_method']?></td>
    <td><?=$item['payment_date']?></td>
    <td><?php if ($isCancel): ?><span class="badge badge-gray">已作废</span><?php else: ?><span class="badge badge-success">有效</span><?php endif; ?></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,20))?><?php if ($isCancel && $item['cancel_reason']): ?><br><small style="color:var(--danger)">作废：<?=htmlspecialchars(mb_substr($item['cancel_reason'],0,20))?></small><?php endif; ?></td>
    <td>
        <?php if (!$isCancel): ?>
        <button class="btn btn-sm btn-outline" onclick="openPayCancelModal(<?=$item['id']?>,'<?=$item['bill_no']?>')" title="作废"><i class="fa-solid fa-ban" style="color:var(--danger)"></i></button>
        <?php else: ?>
        <span style="color:var(--gray-500);font-size:12px;">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-credit-card"></i><p>暂无付款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="payModal"><div class="modal"><div class="modal-header"><h3 class="modal-title">新增付款</h3><button class="modal-close" onclick="closeModal('payModal')">&times;</button></div>
<form method="post" id="payForm"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="modal-body">
    <div class="form-group"><label class="form-label">供应商 <span class="required">*</span></label>
        <select name="supplier_id" id="paySupId" class="form-control searchable" required onchange="loadPayOrders()">
            <option value="">请选择供应商</option>
            <?php foreach($suppliers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">付款金额 <span class="required">*</span></label>
        <input type="number" step="0.01" name="amount" id="payAmount" class="form-control" required oninput="refreshPayAllocSummary()">
    </div>
    <div class="form-group">
        <label class="form-label">核销明细</label>
        <div style="font-size:12px;color:var(--gray-600);margin-bottom:6px;">指定这笔钱分别冲抵哪些采购订单；未分配的金额自动记为<strong>预付款</strong>。</div>
        <table id="payAllocTable" style="width:100%;font-size:13px;">
            <thead><tr><th style="text-align:left;">采购订单</th><th style="width:130px;text-align:left;">核销金额</th><th style="width:60px;"></th></tr></thead>
            <tbody id="payAllocBody"></tbody>
        </table>
        <button type="button" class="btn btn-outline btn-sm" onclick="addPayAllocRow()" style="margin-top:6px;"><i class="fa-solid fa-plus"></i> 添加核销订单</button>
        <div id="payAllocSummary" style="margin-top:8px;font-size:13px;"></div>
    </div>
    <div class="form-group"><label class="form-label">付款方式</label>
        <select name="pay_method" class="form-control"><?php foreach($payMethods as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group"><label class="form-label">付款日期</label><input type="date" name="payment_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('payModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<div class="modal-overlay" id="payCancelModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">作废付款单</h3><button class="modal-close" onclick="closeModal('payCancelModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" id="payCancelId" value="0">
<div class="modal-body">
    <p style="margin-bottom:8px;">单号：<strong id="payCancelBillNo"></strong></p>
    <div class="form-group"><label class="form-label">作废原因 <span class="required">*</span></label>
        <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="如：录入错误、款项退回"></textarea>
    </div>
    <div style="font-size:12px;color:var(--gray-600);">作废后该笔金额将从关联订单的已付金额中回冲，付款单保留留痕，不做物理删除。</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('payCancelModal')">取消</button><button type="submit" class="btn btn-danger">确认作废</button></div>
</form></div></div>

<script>
var payOrders = <?= json_encode($prefill ? [['id'=>$prefill['id'],'bill_no'=>$prefill['bill_no'],'total_amount'=>$prefill['total_amount'],'paid_amount'=>$prefill['paid_amount'],'balance'=>$prefill['balance']]] : [], JSON_UNESCAPED_UNICODE) ?>;
var payPrefill = <?= json_encode($prefill ? ['supplier_id'=>intval($prefill['supplier_id']),'order_id'=>intval($prefill['id']),'amount'=>max(0, floatval($prefill['balance']))] : null, JSON_UNESCAPED_UNICODE) ?>;

function resetPayForm(){
    document.getElementById('payForm').reset();
    document.getElementById('paySupId').selectedIndex = 0;
    document.getElementById('payAllocBody').innerHTML = '';
    payOrders = [];
    refreshPayAllocSummary();
}

function loadPayOrders(callback){
    var supId = document.getElementById('paySupId').value;
    document.getElementById('payAllocBody').innerHTML = '';
    payOrders = [];
    if (!supId) { refreshPayAllocSummary(); if (typeof callback === 'function') callback(); return; }
    fetch('/api/get_orders.php?type=purchase&supplier_id=' + supId)
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(data){
        payOrders = (data||[]).map(function(o){
            return {id:o.id, bill_no:o.bill_no, total_amount:o.total_amount, paid_amount:o.paid_amount||0,
                    balance:(parseFloat(o.total_amount)-(parseFloat(o.paid_amount)||0))};
        });
        if (typeof initSearchableSelects === 'function') { initSearchableSelects(); }
        refreshPayAllocSummary();
        if (typeof callback === 'function') callback();
    })
    .catch(function(e){ console.error('加载订单失败:', e); if (typeof callback === 'function') callback(); });
}

function addPayAllocRow(orderId, amount){
    var tbody = document.getElementById('payAllocBody');
    var tr = document.createElement('tr');
    var tdSel = document.createElement('td');
    var sel = document.createElement('select');
    sel.name = 'alloc_order_id[]';
    sel.className = 'form-control';
    var opt0 = document.createElement('option');
    opt0.value = '0'; opt0.textContent = '不指定（记为预付款）';
    sel.appendChild(opt0);
    payOrders.forEach(function(o){
        var opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = o.bill_no + ' [待付¥' + parseFloat(o.balance).toFixed(2) + ']';
        sel.appendChild(opt);
    });
    if (orderId) { sel.value = String(orderId); }
    tdSel.appendChild(sel);

    var tdAmt = document.createElement('td');
    var inp = document.createElement('input');
    inp.type = 'number'; inp.step = '0.01'; inp.name = 'alloc_amount[]'; inp.className = 'form-control';
    inp.value = amount ? amount.toFixed(2) : '';
    inp.oninput = refreshPayAllocSummary;
    tdAmt.appendChild(inp);

    var tdDel = document.createElement('td');
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-sm btn-outline'; btn.innerHTML = '<i class="fa-solid fa-trash" style="color:var(--danger)"></i>';
    btn.onclick = function(){ tr.remove(); refreshPayAllocSummary(); };
    tdDel.appendChild(btn);

    tr.appendChild(tdSel); tr.appendChild(tdAmt); tr.appendChild(tdDel);
    tbody.appendChild(tr);
    sel.onchange = function(){
        var o = payOrders.filter(function(x){ return x.id === parseInt(sel.value,10); })[0];
        if (o) { inp.value = Math.max(0, o.balance).toFixed(2); refreshPayAllocSummary(); }
    };
    refreshPayAllocSummary();
}

function refreshPayAllocSummary(){
    var amount = parseFloat(document.getElementById('payAmount').value) || 0;
    var allocTotal = 0;
    document.querySelectorAll('#payAllocBody input[name="alloc_amount[]"]').forEach(function(i){
        allocTotal += parseFloat(i.value) || 0;
    });
    var rest = amount - allocTotal;
    var el = document.getElementById('payAllocSummary');
    var html = '付款 ¥' + amount.toFixed(2) + ' ｜ 已分配 <strong>¥' + allocTotal.toFixed(2) + '</strong>';
    html += rest > 0.005 ? ' ｜ 剩余 <strong style="color:var(--warning)">¥' + rest.toFixed(2) + '</strong>（记为预付款）'
                         : (rest < -0.005 ? ' ｜ <strong style="color:var(--danger)">已分配超过付款金额 ¥' + Math.abs(rest).toFixed(2) + '</strong>' : '');
    el.innerHTML = html;
}

function openPayCancelModal(id, billNo){
    document.getElementById('payCancelId').value = id;
    document.getElementById('payCancelBillNo').textContent = billNo;
    openModal('payCancelModal');
}

(function(){
    if (!payPrefill) return;
    var sel = document.getElementById('paySupId');
    sel.value = String(payPrefill.supplier_id);
    loadPayOrders(function(){
        document.getElementById('payAmount').value = payPrefill.amount.toFixed(2);
        addPayAllocRow(payPrefill.order_id, payPrefill.amount);
        openModal('payModal');
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
