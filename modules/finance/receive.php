<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('finance_receive');
$pdo = getDB();

// 执行数据库迁移
run_migrations();

$page = max(1, intval($_GET['page'] ?? 1));

$perPage = ITEMS_PER_PAGE; $offset = ($page-1)*$perPage;
$total = $pdo->query("SELECT COUNT(*) FROM receipts")->fetchColumn();
$pages = ceil($total/$perPage);
$list = $pdo->query("SELECT r.*, c.name as customer_name FROM receipts r LEFT JOIN customers c ON r.customer_id=c.id ORDER BY r.id DESC LIMIT $offset,$perPage")->fetchAll();

$customers = get_options('customers','id','name','status=1');

// 收款状态标签
$recPayLabels = ['unpaid'=>'未付款','paid_deposit'=>'已付定金','paid_full'=>'已付全款'];
$recPayBadge = ['unpaid'=>'danger','paid_deposit'=>'warning','paid_full'=>'success'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    csrf_verify();
    $customerId = intval($_POST['customer_id']??0);
    $amount = floatval($_POST['amount']??0);
    $payMethod = $_POST['pay_method']??'bank';
    $receiptDate = $_POST['receipt_date']??date('Y-m-d');
    $relatedBill = $_POST['related_bill_no']??'';
    $orderId = intval($_POST['order_id']??0);
    $newPayStatus = $_POST['new_pay_status']??'';
    $remark = $_POST['remark']??'';

    if ($amount <= 0) { $error = '金额必须大于0'; }
    else {
        $pdo->beginTransaction();
        try {
            $billNo = generate_bill_no('SK');
            $pdo->prepare("INSERT INTO receipts (bill_no,customer_id,amount,pay_method,receipt_date,related_bill_no,remark,user_id,created_at,order_id) VALUES (?,?,?,?,?,?,?,?,NOW(),?)")->execute([$billNo,$customerId,$amount,$payMethod,$receiptDate,$relatedBill,$remark,get_user_id(),$orderId]);

            // 自动更新关联订单的已收金额和付款状态
            if ($orderId > 0) {
                $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
                $st->execute([$orderId]);
                $order = $st->fetch();
                if ($order) {
                    $balance = floatval($order['total_amount']) - floatval($order['received_amount']);
                    if ($amount > $balance) { throw new Exception("收款金额 ¥{$amount} 不能超过待收余额 ¥{$balance}"); }
                    $newReceived = floatval($order['received_amount']) + $amount;
                    $oldPayStatus = $order['pay_status'] ?? 'unpaid';
                    $updateFields = ['received_amount = ?'];
                    $updateParams = [$newReceived];
                    // 如果选择了付款状态，同步更新订单
                    if ($newPayStatus && in_array($newPayStatus, ['unpaid','paid_deposit','paid_full'])) {
                        $updateFields[] = 'pay_status = ?';
                        $updateParams[] = $newPayStatus;
                    }
                    $updateParams[] = $orderId;
                    $pdo->prepare("UPDATE sales_orders SET ".implode(', ',$updateFields)." WHERE id=?")->execute($updateParams);

                    // 同步更新关联的出库单收款状态
                    if ($newPayStatus && $newPayStatus !== $oldPayStatus) {
                        $now = date('Y-m-d H:i:s');
                        $userName = get_user_name();
                        $outstocks = $pdo->prepare("SELECT id, pay_status, bill_no FROM sales_outstocks WHERE order_id=? AND status='confirmed'");
                        $outstocks->execute([$orderId]);
                        while ($os = $outstocks->fetch()) {
                            $osOldStatus = $os['pay_status'] ?? 'unpaid';
                            $pdo->prepare("UPDATE sales_outstocks SET pay_status=?, pay_remark=?, pay_updated_at=? WHERE id=?")
                                ->execute([$newPayStatus, "收款自动同步：{$billNo} ¥{$amount}", $now, $os['id']]);
                            $pdo->prepare("INSERT INTO sales_outstock_paylogs (outstock_id, from_status, to_status, remark, user_name, created_at) VALUES (?,?,?,?,?,?)")
                                ->execute([$os['id'], $osOldStatus, $newPayStatus, "收款自动同步：{$billNo} ¥{$amount}", $userName, $now]);
                        }
                    }
                }
            }
            add_log(get_user_id(), 'create', 'receipt', "收款: $billNo ¥$amount");
            $pdo->commit();
            redirect('receive.php');
        } catch (Exception $e) { $pdo->rollBack(); error_log('Receive save error: '.$e->getMessage()); $error = '保存失败，请稍后重试'; }
    }
}


?>


<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-money-bill-wave"></i> 收款记录</h1>
    <button class="btn btn-primary" onclick="resetRecForm();openModal('recModal')"><i class="fa-solid fa-plus"></i> 新增收款</button>
</div>
<?php if (isset($error)): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>金额</th><th>方式</th><th>日期</th><th>关联单据</th><th>备注</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): ?>
<tr>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['customer_name']?:'-')?></td>
    <td style="color:var(--success);font-weight:bold;">¥<?=format_money($item['amount'])?></td>
    <td><?=['cash'=>'现金','bank'=>'银行','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'][$item['pay_method']]??$item['pay_method']?></td>
    <td><?=$item['receipt_date']?></td>
    <td><?php if ($item['order_id'] > 0 && $item['related_bill_no']): ?><a href="../sales/order_view.php?id=<?=$item['order_id']?>" style="color:var(--primary);"><?=htmlspecialchars($item['related_bill_no'])?></a><?php else: ?><?=$item['related_bill_no']?:'-'?><?php endif; ?></td>
    <td><?=htmlspecialchars(mb_substr($item['remark']?:'-',0,20))?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-money-bill-wave"></i><p>暂无收款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>

<div class="modal-overlay" id="recModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">新增收款</h3><button class="modal-close" onclick="closeModal('recModal')">&times;</button></div>
<form method="post" id="recForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="order_id" id="recOrderId" value="0">
<div class="modal-body">
    <div class="form-group"><label class="form-label">客户 <span class="required">*</span></label>
        <select name="customer_id" id="recCustId" class="form-control" required onchange="loadRecOrders()">
            <option value="">请选择客户</option>
            <?php foreach($customers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">关联订单</label>
        <select name="related_bill_no" id="recBillSel" class="form-control" onchange="onRecOrderSelect()">
            <option value="">无关联（手动输入）</option>
        </select>
        <div id="recOrderInfo" style="margin-top:4px;font-size:12px;color:var(--gray-600);"></div>
    </div>
    <div class="form-group"><label class="form-label">金额 <span class="required">*</span></label><input type="number" step="0.01" name="amount" id="recAmount" class="form-control" required></div>
    <div class="form-group" id="recPayStatusGroup" style="display:none;">
        <label class="form-label">更新订单付款状态</label>
        <select name="new_pay_status" id="recPayStatus" class="form-control">
            <option value="">不修改</option>
            <option value="unpaid">未付款</option>
            <option value="paid_deposit">已付定金</option>
            <option value="paid_full">已付全款</option>
        </select>
    </div>
    <div class="form-group"><label class="form-label">收款方式</label><select name="pay_method" class="form-control"><?php foreach(['bank'=>'银行转账','cash'=>'现金','wechat'=>'微信','alipay'=>'支付宝','other'=>'其他'] as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">收款日期</label><input type="date" name="receipt_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('recModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
</form></div></div>

<script>
function resetRecForm(){
    document.getElementById('recForm').reset();
    document.getElementById('recCustId').selectedIndex = 0;
    document.getElementById('recBillSel').innerHTML = '<option value="">无关联（手动输入）</option>';
    document.getElementById('recOrderId').value = '0';
    document.getElementById('recOrderInfo').textContent = '';
    document.getElementById('recPayStatusGroup').style.display = 'none';
}

function loadRecOrders(){
    var custId = document.getElementById('recCustId').value;
    var sel = document.getElementById('recBillSel');
    sel.innerHTML = '<option value="">无关联（手动输入）</option>';
    document.getElementById('recOrderId').value = '0';
    document.getElementById('recAmount').value = '';
    document.getElementById('recOrderInfo').textContent = '';
    document.getElementById('recPayStatusGroup').style.display = 'none';
    document.getElementById('recPayStatus').selectedIndex = 0;

    if (!custId) return;

    // AJAX 加载该客户的全部历史订单（含已收完的）
    fetch('/api/get_orders.php?type=sales&customer_id=' + custId)
    .then(function(r){
        if (!r.ok) throw new Error('服务器响应错误(' + r.status + ')');
        return r.json();
    })
    .then(function(data){
        if (!data || data.length === 0) {
            sel.innerHTML = '<option value="">该客户暂无订单</option>';
            return;
        }
        var hasOrder = false;
        data.forEach(function(o){
            var bal = parseFloat(o.total_amount) - parseFloat(o.received_amount);
            var opt = document.createElement('option');
            opt.value = o.bill_no;
            opt.setAttribute('data-oid', o.id);
            opt.setAttribute('data-total', parseFloat(o.total_amount).toFixed(2));
            opt.setAttribute('data-received', parseFloat(o.received_amount).toFixed(2));
            opt.setAttribute('data-balance', bal.toFixed(2));
            opt.setAttribute('data-paystatus', o.pay_status || 'unpaid');
            // 已收完的订单也显示，但标注"已收完"
            var suffix = bal <= 0 ? ' [已收完]' : ' [待收¥' + bal.toFixed(2) + ']';
            opt.textContent = o.bill_no + ' [¥' + parseFloat(o.total_amount).toFixed(2) + suffix;
            sel.appendChild(opt);
            hasOrder = true;
        });
        if (!hasOrder) {
            sel.innerHTML = '<option value="">该客户暂无订单</option>';
        }
    })
    .catch(function(e){
        console.error('加载订单失败:', e);
        sel.innerHTML = '<option value="">加载失败，请重试</option>';
    });
}

function onRecOrderSelect(){
    var sel = document.getElementById('recBillSel');
    var opt = sel.options[sel.selectedIndex];
    if (opt.value && opt.getAttribute('data-oid')) {
        var oid = opt.getAttribute('data-oid');
        var bal = opt.getAttribute('data-balance');
        var total = opt.getAttribute('data-total');
        var received = opt.getAttribute('data-received');
        var curPayStatus = opt.getAttribute('data-paystatus');
        document.getElementById('recOrderId').value = oid;
        document.getElementById('recAmount').value = bal;
        var statusLabel = {'unpaid':'未付款','paid_deposit':'已付定金','paid_full':'已付全款'}[curPayStatus] || curPayStatus;
        document.getElementById('recOrderInfo').innerHTML = '订单总额: ¥' + total + ' | 已收: ¥' + received + ' | 待收: ¥' + bal + ' | 当前状态: <span style="font-weight:bold;">' + statusLabel + '</span>';
        document.getElementById('recPayStatusGroup').style.display = 'block';
        // 默认选中当前订单的付款状态
        var payStatusMap = {'unpaid':1,'paid_deposit':2,'paid_full':3};
        document.getElementById('recPayStatus').selectedIndex = payStatusMap[curPayStatus] || 0;
    } else {
        document.getElementById('recOrderId').value = '0';
        document.getElementById('recAmount').value = '';
        document.getElementById('recOrderInfo').textContent = '';
        document.getElementById('recPayStatusGroup').style.display = 'none';
    }
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
