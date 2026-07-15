<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('finance_payment');
$pdo = getDB();

// 执行数据库迁移
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT p.*, s.name as supplier_name FROM payments p LEFT JOIN suppliers s ON p.supplier_id=s.id ORDER BY p.id DESC LIMIT $offset,$perPage")->fetchAll();

$suppliers = get_options('suppliers','id','name','status=1');

// 付款状态标签
$payPayLabels = ['unpaid'=>'未付款','paid_deposit'=>'已付定金','paid_full'=>'已付全款'];
$payPayBadge = ['unpaid'=>'danger','paid_deposit'=>'warning','paid_full'=>'success'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    $supplierId = intval($_POST['supplier_id']??0);
    $amount = floatval($_POST['amount']??0);
    $payMethod = $_POST['pay_method']??'bank';
    $paymentDate = $_POST['payment_date']??date('Y-m-d');
    $relatedBill = $_POST['related_bill_no']??'';
    $orderId = intval($_POST['order_id']??0);
    $newPayStatus = $_POST['new_pay_status']??'';
    $remark = $_POST['remark']??'';

    if ($amount <= 0) { $error = '金额必须大于0'; }
    else {
        $pdo->beginTransaction();
        try {
            $billNo = generate_bill_no('FK');
            $pdo->prepare("INSERT INTO payments (bill_no,supplier_id,amount,pay_method,payment_date,related_bill_no,remark,user_id,created_at,order_id) VALUES (?,?,?,?,?,?,?,?,NOW(),?)")->execute([$billNo,$supplierId,$amount,$payMethod,$paymentDate,$relatedBill,$remark,get_user_id(),$orderId]);

            // 自动更新关联订单的已付金额和付款状态
            if ($orderId > 0) {
                $st = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
                $st->execute([$orderId]);
                $order = $st->fetch();
                if ($order) {
                    $balance = floatval($order['total_amount']) - floatval($order['paid_amount']);
                    if ($amount > $balance) { throw new Exception("付款金额 ¥{$amount} 不能超过待付余额 ¥{$balance}"); }
                    $newPaid = floatval($order['paid_amount']) + $amount;
                    $updateFields = ['paid_amount = ?'];
                    $updateParams = [$newPaid];
                    if ($newPayStatus && in_array($newPayStatus, ['unpaid','paid_deposit','paid_full'])) {
                        $updateFields[] = 'pay_status = ?';
                        $updateParams[] = $newPayStatus;
                    }
                    $updateParams[] = $orderId;
                    $pdo->prepare("UPDATE purchase_orders SET ".implode(', ',$updateFields)." WHERE id=?")->execute($updateParams);
                }
            }
            add_log(get_user_id(), 'create', 'payment', "付款: $billNo ¥$amount");
            $pdo->commit();
            redirect('payment.php');
        } catch (Exception $e) { $pdo->rollBack(); error_log('Payment save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
    }
}


?>


<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-credit-card"></i> 付款记录</h1>
    <button class="btn btn-primary" onclick="resetPayForm();openModal('payModal')"><i class="fa-solid fa-plus"></i> 新增付款</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>供应商</th><th>金额</th><th>方式</th><th>日期</th><th>关联单据</th><th>备注</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['supplier_name']?:'-')?></td>
    <td style="color:var(--danger);font-weight:bold;">¥<?=format_money($item['amount'])?></td>
    <td><?=['cash'=>'现金','bank'=>'银行','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'][$item['pay_method']]??$item['pay_method']?></td>
    <td><?=$item['payment_date']?></td>
    <td><?=$item['related_bill_no']?:'-'?></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,20))?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-credit-card"></i><p>暂无付款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="payModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">新增付款</h3><button class="modal-close" onclick="closeModal('payModal')">&times;</button></div>
<form method="post" id="payForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="order_id" id="payOrderId" value="0">
<div class="modal-body">
    <div class="form-group"><label class="form-label">供应商 <span class="required">*</span></label>
        <select name="supplier_id" id="paySupId" class="form-control" required onchange="loadPayOrders()">
            <option value="">请选择供应商</option>
            <?php foreach($suppliers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">关联订单</label>
        <select name="related_bill_no" id="payBillSel" class="form-control" onchange="onPayOrderSelect()">
            <option value="">无关联（手动输入）</option>
        </select>
        <div id="payOrderInfo" style="margin-top:4px;font-size:12px;color:var(--gray-600);"></div>
    </div>
    <div class="form-group"><label class="form-label">金额 <span class="required">*</span></label><input type="number" step="0.01" name="amount" id="payAmount" class="form-control" required></div>
    <div class="form-group" id="payPayStatusGroup" style="display:none;">
        <label class="form-label">更新订单付款状态</label>
        <select name="new_pay_status" id="payPayStatus" class="form-control">
            <option value="">不修改</option>
            <option value="unpaid">未付款</option>
            <option value="paid_deposit">已付定金</option>
            <option value="paid_full">已付全款</option>
        </select>
    </div>
    <div class="form-group"><label class="form-label">付款方式</label><select name="pay_method" class="form-control"><?php foreach(['bank'=>'银行转账','cash'=>'现金','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'] as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">付款日期</label><input type="date" name="payment_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('payModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
function resetPayForm(){
    document.getElementById('payForm').reset();
    document.getElementById('paySupId').selectedIndex = 0;
    document.getElementById('payBillSel').innerHTML = '<option value="">无关联（手动输入）</option>';
    document.getElementById('payOrderId').value = '0';
    document.getElementById('payOrderInfo').textContent = '';
    document.getElementById('payPayStatusGroup').style.display = 'none';
}

function loadPayOrders(){
    var supId = document.getElementById('paySupId').value;
    var sel = document.getElementById('payBillSel');
    sel.innerHTML = '<option value="">无关联（手动输入）</option>';
    document.getElementById('payOrderId').value = '0';
    document.getElementById('payAmount').value = '';
    document.getElementById('payOrderInfo').textContent = '';
    document.getElementById('payPayStatusGroup').style.display = 'none';
    document.getElementById('payPayStatus').selectedIndex = 0;

    if (!supId) return;

    // AJAX 加载该供应商的全部历史订单（含已付完的）
    fetch('/api/get_orders.php?type=purchase&supplier_id=' + supId)
    .then(function(r){
        if (!r.ok) throw new Error('服务器响应错误(' + r.status + ')');
        return r.json();
    })
    .then(function(data){
        if (!data || data.length === 0) {
            sel.innerHTML = '<option value="">该供应商暂无订单</option>';
            return;
        }
        data.forEach(function(o){
            var bal = parseFloat(o.total_amount) - parseFloat(o.paid_amount);
            var opt = document.createElement('option');
            opt.value = o.bill_no;
            opt.setAttribute('data-oid', o.id);
            opt.setAttribute('data-total', parseFloat(o.total_amount).toFixed(2));
            opt.setAttribute('data-paid', parseFloat(o.paid_amount).toFixed(2));
            opt.setAttribute('data-balance', bal.toFixed(2));
            opt.setAttribute('data-paystatus', o.pay_status || 'unpaid');
            // 已付完的订单也显示，但标注"已付完"
            var suffix = bal <= 0 ? ' [已付完]' : ' [待付¥' + bal.toFixed(2) + ']';
            opt.textContent = o.bill_no + ' [¥' + parseFloat(o.total_amount).toFixed(2) + suffix;
            sel.appendChild(opt);
        });
    })
    .catch(function(e){
        console.error('加载订单失败:', e);
        sel.innerHTML = '<option value="">加载失败，请重试</option>';
    });
}

function onPayOrderSelect(){
    var sel = document.getElementById('payBillSel');
    var opt = sel.options[sel.selectedIndex];
    if (opt.value && opt.getAttribute('data-oid')) {
        var oid = opt.getAttribute('data-oid');
        var bal = opt.getAttribute('data-balance');
        var total = opt.getAttribute('data-total');
        var paid = opt.getAttribute('data-paid');
        var curPayStatus = opt.getAttribute('data-paystatus');
        document.getElementById('payOrderId').value = oid;
        document.getElementById('payAmount').value = bal;
        var statusLabel = {'unpaid':'未付款','paid_deposit':'已付定金','paid_full':'已付全款'}[curPayStatus] || curPayStatus;
        document.getElementById('payOrderInfo').innerHTML = '订单总额: ¥' + total + ' | 已付: ¥' + paid + ' | 待付: ¥' + bal + ' | 当前状态: <span style="font-weight:bold;">' + statusLabel + '</span>';
        document.getElementById('payPayStatusGroup').style.display = 'block';
        // 默认选中当前订单的付款状态
        var payStatusMap = {'unpaid':1,'paid_deposit':2,'paid_full':3};
        document.getElementById('payPayStatus').selectedIndex = payStatusMap[curPayStatus] || 0;
    } else {
        document.getElementById('payOrderId').value = '0';
        document.getElementById('payAmount').value = '';
        document.getElementById('payOrderInfo').textContent = '';
        document.getElementById('payPayStatusGroup').style.display = 'none';
    }
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
