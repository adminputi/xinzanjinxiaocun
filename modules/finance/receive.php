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
$total = 0; $pages = 1; $list = []; $sumAmount = 0.0;

// 收款用途：单独字段，避免拿自由文本的备注去做「定金/尾款」统计
$purposes = ['deposit'=>'定金','balance'=>'尾款','progress'=>'进度款','other'=>'其他'];

// 查询条件（关键词可命中单号/客户/备注/关联订单）
$kw = trim($_GET['kw'] ?? '');
$filterCust = intval($_GET['customer_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$filterPurpose = trim($_GET['purpose'] ?? '');
$where = []; $params = [];
if ($kw !== '') {
    $where[] = "(r.bill_no LIKE ? OR c.name LIKE ? OR r.remark LIKE ? OR r.related_bill_no LIKE ?)";
    $like = '%' . $kw . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterCust > 0)     { $where[] = "r.customer_id = ?";  $params[] = $filterCust; }
if ($dateFrom !== '')    { $where[] = "r.receipt_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '')      { $where[] = "r.receipt_date <= ?"; $params[] = $dateTo; }
if ($filterPurpose !== '' && isset($purposes[$filterPurpose])) { $where[] = "r.purpose = ?"; $params[] = $filterPurpose; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// 受限模式不查询收款列表，避免把全部客户的收款数据暴露给销售角色
if (!$limitedMode) {
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN r.status='confirmed' THEN r.amount ELSE 0 END),0)
        FROM receipts r LEFT JOIN customers c ON r.customer_id=c.id $whereSql");
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $total = intval($row[0]); $sumAmount = floatval($row[1]);
    $pages = max(1, ceil($total/$perPage));
    $st = $pdo->prepare("SELECT r.*, c.name as customer_name, u.real_name as user_name,
        (SELECT COALESCE(GROUP_CONCAT(CONCAT(COALESCE(o.bill_no,'预收款'), ':', FORMAT(a.amount,2), IF(a.amount<0,'(退款)','')) SEPARATOR '、'), '')
            FROM receipt_allocations a LEFT JOIN sales_orders o ON o.id=a.order_id WHERE a.receipt_id=r.id) as alloc_text
        FROM receipts r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN users u ON u.id=r.user_id
        $whereSql ORDER BY r.id DESC LIMIT $offset,$perPage");
    $st->execute($params);
    $list = $st->fetchAll();

    // 明细弹窗用的数据（一次查好塞进前端，避免每次点「查看」再发一次请求）
    $receiptDetails = [];
    foreach ($list as $item) {
        $isCancel = (($item['status'] ?? 'confirmed') === 'cancelled');
        $p = $item['purpose'] ?? '';
        $receiptDetails[intval($item['id'])] = [
            'bill_no'   => $item['bill_no'],
            'customer'  => $item['customer_name'] ?: '-',
            'amount'    => format_money($item['amount']),
            'purpose'   => $purposes[$p] ?? '',
            'pay_method'=> $payMethods[$item['pay_method']] ?? $item['pay_method'],
            'date'      => $item['receipt_date'],
            'status'    => $isCancel ? '已作废' : '有效',
            'alloc'     => $item['alloc_text'] ?: '-',
            'remark'    => $item['remark'] ?: '',
            'cancel_reason' => $item['cancel_reason'] ?? '',
            'user'      => $item['user_name'] ?: '-',
            'created'   => $item['created_at'] ?? '',
        ];
    }

    // 退款弹窗用的数据源：每张有效收款单下还有哪些钱可以退
    $refundMap = [];
    $stRef = $pdo->prepare("SELECT a.id, a.order_id, a.amount, o.bill_no AS order_no
        FROM receipt_allocations a
        LEFT JOIN sales_orders o ON o.id=a.order_id
        WHERE a.receipt_id=? AND a.amount>0 AND a.refund_of=0
        ORDER BY a.id ASC");
    foreach ($list as $item) {
        if (($item['status'] ?? 'confirmed') === 'cancelled') continue;
        $stRef->execute([intval($item['id'])]);
        $rows = [];
        foreach ($stRef->fetchAll() as $a) {
            $refundable = get_allocation_refundable(intval($a['id']));
            if ($refundable <= 0.005) continue; // 已全额退过的不再出现在选项里
            $rows[] = [
                'id'         => intval($a['id']),
                'refundable' => round($refundable, 2),
                'text'       => ($a['order_no'] ?: '预收款（未核销）') . '　¥' . format_money($a['amount'])
                                . '　可退 ¥' . format_money($refundable),
            ];
        }
        if ($rows) { $refundMap[intval($item['id'])] = $rows; }
    }
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
    $purpose = trim($_POST['purpose']??'');
    if (!isset($purposes[$purpose])) { $purpose = ''; }

    // 两种录入模式：
    //   simple（默认）—— 只做「把钱收进某一张订单」这一件事，金额默认带出该订单待收余额；
    //                    收多了的部分自动转成客户预收款，用户不用理解「核销」这个词。
    //   advanced      —— 高级选项里展开的多订单核销表格，沿用 alloc_* 明细数组。
    $mode = (($_POST['mode'] ?? 'simple') === 'advanced') ? 'advanced' : 'simple';
    $overflowToPrepay = 0.0;
    $allocs = [];

    if ($mode === 'simple') {
        $mainOrderId = intval($_POST['main_order_id'] ?? 0);
        $mainSource  = (($mainOrderId > 0) && (($_POST['main_source'] ?? '') === 'prepay')) ? 'prepay' : 'cash';
        // 本次落到订单上的金额：现金收款时等于收款金额；预收款抵扣时收款金额为 0，
        // 抵扣额由 main_amount 单独携带（此时不新增收款流水，只改挂预收明细）
        $mainAmount  = floatval($_POST['main_amount'] ?? 0);
        if ($mainSource === 'prepay') {
            if ($mainOrderId > 0 && $mainAmount > 0.005) {
                $allocs[] = ['order_id' => $mainOrderId, 'amount' => round($mainAmount, 2), 'source' => 'prepay'];
            }
        } elseif ($mainAmount > 0.005) {
            if ($mainOrderId > 0) {
                $st = $pdo->prepare("SELECT id, total_amount, COALESCE(received_amount,0) as received_amount FROM sales_orders WHERE id=?");
                $st->execute([$mainOrderId]);
                $o = $st->fetch();
                $balance = $o ? max(0.0, floatval($o['total_amount']) - floatval($o['received_amount'])) : 0.0;
                if ($mainAmount - $balance > 0.01) {
                    // 收超了：先把订单收满，多出来的自动进客户预收池，不用手工拆分
                    if ($balance > 0.005) { $allocs[] = ['order_id' => $mainOrderId, 'amount' => round($balance, 2), 'source' => 'cash']; }
                    $overflowToPrepay = round($mainAmount - $balance, 2);
                    $allocs[] = ['order_id' => 0, 'amount' => $overflowToPrepay, 'source' => 'cash'];
                } else {
                    $allocs[] = ['order_id' => $mainOrderId, 'amount' => round($mainAmount, 2), 'source' => 'cash'];
                }
            } else {
                // 不指定订单：整笔记为该客户的预收款
                $allocs[] = ['order_id' => 0, 'amount' => round($mainAmount, 2), 'source' => 'cash'];
            }
        }
    } else {
        // 核销明细：alloc_order_id[] / alloc_amount[] / alloc_source[]
        // order_id=0 表示预收款；source=cash 用本次收款的钱，source=prepay 用客户名下的预收款抵扣
        $allocOrderIds = $_POST['alloc_order_id'] ?? [];
        $allocAmounts  = $_POST['alloc_amount'] ?? [];
        $allocSources  = $_POST['alloc_source'] ?? [];
        if (is_array($allocOrderIds)) {
            foreach ($allocOrderIds as $i => $oid) {
                $oid = intval($oid);
                $amt = floatval($allocAmounts[$i] ?? 0);
                if (abs($amt) <= 0.000001) continue;
                $src = (($allocSources[$i] ?? '') === 'prepay') ? 'prepay' : 'cash';
                // 预收款抵扣必须落到具体订单上（order_id=0 的核销行本来就是预收池本身）
                if ($src === 'prepay' && $oid <= 0) { continue; }
                $allocs[] = ['order_id' => $oid, 'amount' => $amt, 'source' => $src];
            }
        }
    }

    // 受限模式：校验客户归属，防止构造请求给他人客户登记收款
    $custOwnerOk = true;
    if ($limitedMode && $customerId > 0) {
        $st = $pdo->prepare("SELECT owner_id FROM customers WHERE id=?");
        $st->execute([$customerId]);
        $custOwnerOk = intval($st->fetchColumn()) === intval(get_user_id());
    }
    // 按资金来源分账：cash=本次收到的钱，prepay=客户名下的预收款
    $cashAllocs = []; $prepayAllocs = [];
    foreach ($allocs as $a) {
        if (($a['source'] ?? 'cash') === 'prepay') { $prepayAllocs[] = $a; }
        else { $cashAllocs[] = $a; }
    }
    $cashTotal = 0.0; $prepayTotal = 0.0;
    foreach ($cashAllocs   as $a) { $cashTotal   += $a['amount']; }
    foreach ($prepayAllocs as $a) { $prepayTotal += $a['amount']; }
    $prepayAvailable = get_customer_prepay($customerId);

    if ($customerId <= 0) { $error = '请选择客户'; }
    elseif ($limitedMode && !$custOwnerOk) { $error = '无权限：该客户不属于您，无法登记收款'; }
    elseif ($amount < 0) { $error = '收款金额不能为负数'; }
    // 一笔钱都没有的新业务不允许提交；但「本次不收款、只用预收款抵扣订单」是合法场景
    elseif ($amount <= 0 && $prepayTotal <= 0.01) { $error = '请填写收款金额，或选择用预收款抵扣订单'; }
    elseif ($cashTotal - $amount > 0.01) {
        $error = '本次收款核销合计 ¥' . format_money($cashTotal) . ' 超过收款金额 ¥' . format_money($amount);
    } elseif ($prepayTotal - $prepayAvailable > 0.01) {
        $error = '预收款抵扣合计 ¥' . format_money($prepayTotal) . ' 超过该客户可用预收款 ¥' . format_money($prepayAvailable);
    } else {
        $pdo->beginTransaction();
        try {
            // 同一张订单可能被拆成多行核销，先按订单汇总再校验余额，防止拆行绕过超额检查
            $perOrder = [];
            foreach ($allocs as $a) {
                if ($a['order_id'] <= 0) continue;
                $perOrder[$a['order_id']] = ($perOrder[$a['order_id']] ?? 0) + $a['amount'];
            }
            $relatedBillNo = '';
            foreach ($perOrder as $oid => $oidAmount) {
                $st = $pdo->prepare("SELECT id, bill_no, customer_id, total_amount, COALESCE(received_amount,0) as received_amount FROM sales_orders WHERE id=?");
                $st->execute([$oid]);
                $order = $st->fetch();
                if (!$order) { throw new Exception('核销的销售订单不存在'); }
                if (intval($order['customer_id']) !== $customerId) { throw new Exception('订单 ' . $order['bill_no'] . ' 不属于该客户'); }
                $balance = floatval($order['total_amount']) - floatval($order['received_amount']);
                if ($oidAmount - $balance > 0.01) {
                    throw new Exception('订单 ' . $order['bill_no'] . ' 核销合计 ¥' . format_money($oidAmount) . ' 超过待收余额 ¥' . format_money($balance));
                }
                if ($relatedBillNo === '') { $relatedBillNo = $order['bill_no']; }
            }

            $billNo = '';
            if ($amount > 0) {
                $billNo = generate_bill_no('SK');
                // 本次收款中未分配的差额记为预收款（order_id=0）
                $rest = round($amount - $cashTotal, 2);
                if ($rest > 0.01) { $cashAllocs[] = ['order_id' => 0, 'amount' => $rest]; }

                $firstOrderId = 0;
                foreach ($cashAllocs as $a) { if ($a['order_id'] > 0 && $firstOrderId === 0) { $firstOrderId = $a['order_id']; } }

                $st = $pdo->prepare("INSERT INTO receipts (bill_no,customer_id,amount,pay_method,receipt_date,related_bill_no,purpose,remark,user_id,created_at,order_id,status)
                    VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,'confirmed')");
                $st->execute([$billNo,$customerId,$amount,$payMethod,$receiptDate,$relatedBillNo,$purpose,$remark,get_user_id(), $firstOrderId]);
                $receiptId = $pdo->lastInsertId();

                foreach ($cashAllocs as $a) {
                    $pdo->prepare("INSERT INTO receipt_allocations (receipt_id,customer_id,order_id,amount,remark,user_id,created_at) VALUES (?,?,?,?,?,?,NOW())")
                        ->execute([$receiptId,$customerId,$a['order_id'],$a['amount'],$remark,get_user_id()]);
                    if ($a['order_id'] > 0) { sync_order_received($a['order_id']); }
                }
            }

            // 预收款抵扣：钱早就收进账了，这里不新增收款流水，只把预收明细改挂到订单上
            foreach ($prepayAllocs as $a) {
                transfer_prepay_to_order($customerId, $a['order_id'], $a['amount']);
            }

            $logParts = [];
            if ($billNo !== '') { $logParts[] = "收款: $billNo ¥$amount（核销 " . count($cashAllocs) . " 项）"; }
            if ($prepayTotal > 0.01) { $logParts[] = '预收款核销订单 ¥' . round($prepayTotal, 2); }
            add_log(get_user_id(), 'create', 'receipt', implode('；', $logParts));
            $pdo->commit();

            $msgParts = [];
            if ($billNo !== '') { $msgParts[] = '收款已登记：' . $billNo; }
            if ($prepayTotal > 0.01) { $msgParts[] = '预收款核销订单 ¥' . format_money($prepayTotal); }
            if ($overflowToPrepay > 0.01) { $msgParts[] = '超出订单待收的 ¥' . format_money($overflowToPrepay) . ' 已自动记为该客户的预收款'; }
            flash_set(implode('；', $msgParts), 'success');
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

// ==================== 退款红冲（钱真的退回客户时走这里，区别于「作废」用于录入错误）====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'refund') {
    csrf_verify();
    if ($limitedMode) {
        flash_set('无权限：退款仅具备「收款记录」权限的人员可操作');
        redirect(site_url($backUrl !== '' ? $backUrl : 'index.php'));
    }
    $allocId = intval($_POST['alloc_id'] ?? 0);
    $rfAmount = floatval($_POST['refund_amount'] ?? 0);
    $rfReason = trim($_POST['refund_reason'] ?? '');
    if ($allocId <= 0) {
        flash_set('请选择要退款的那笔收款');
    } elseif ($rfReason === '') {
        flash_set('退款必须填写原因');
    } else {
        $pdo->beginTransaction();
        try {
            $billNo = refund_receipt_allocation($allocId, $rfAmount, $rfReason);
            $pdo->commit();
            flash_set('退款已登记：' . $billNo . '，对应订单已收/客户预收款已冲减', 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('退款失败：' . $e->getMessage());
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
<form class="filter-bar" method="get">
    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" name="kw" class="form-control" placeholder="单号/客户/备注/订单号" value="<?=htmlspecialchars($kw)?>"></div>
    <select name="customer_id" class="form-control" style="min-width:150px;">
        <option value="0">全部客户</option>
        <?php foreach($customers as $k=>$v): ?><option value="<?=$k?>" <?=$filterCust==$k?'selected':''?>><?=htmlspecialchars($v)?></option><?php endforeach; ?>
    </select>
    <select name="purpose" class="form-control" style="min-width:110px;">
        <option value="">全部用途</option>
        <?php foreach($purposes as $k=>$v): ?><option value="<?=$k?>" <?=$filterPurpose===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
    </select>
    <input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($dateFrom)?>" style="min-width:130px;">
    <span>至</span>
    <input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($dateTo)?>" style="min-width:130px;">
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <?php if ($kw||$filterCust||$filterPurpose||$dateFrom||$dateTo): ?><a href="receive.php" class="btn btn-outline btn-sm">清除</a><?php endif; ?>
</form>

<div class="card"><div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>单号</th><th>客户</th><th>金额</th><th>用途</th><th>核销明细</th><th>方式</th><th>日期</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $item): $isCancel = (($item['status'] ?? 'confirmed') === 'cancelled'); ?>
<tr<?= $isCancel ? ' style="opacity:.6;text-decoration:line-through;"' : '' ?>>
    <td><strong><?=$item['bill_no']?></strong></td>
    <td><?=htmlspecialchars($item['customer_name']?:'-')?></td>
    <td style="color:var(--success);font-weight:bold;">¥<?=format_money($item['amount'])?></td>
    <td><?php $p = $item['purpose'] ?? ''; if ($p !== '' && isset($purposes[$p])): ?><span class="badge badge-<?= $p==='deposit'?'warning':($p==='balance'?'info':'gray') ?>"><?=$purposes[$p]?></span><?php else: ?><span style="color:var(--gray-500);">—</span><?php endif; ?></td>
    <td style="font-size:12px;"><?=htmlspecialchars($item['alloc_text']?:'-')?></td>
    <td><?=$payMethods[$item['pay_method']]??$item['pay_method']?></td>
    <td><?=$item['receipt_date']?></td>
    <td><?php if ($isCancel): ?><span class="badge badge-gray">已作废</span><?php else: ?><span class="badge badge-success">有效</span><?php endif; ?></td>
    <td style="max-width:180px;"><div style="white-space:pre-line;word-break:break-all;"><?=htmlspecialchars($item['remark']?:'-')?></div><?php if ($isCancel && $item['cancel_reason']): ?><br><small style="color:var(--danger)">作废：<?=htmlspecialchars($item['cancel_reason'])?></small><?php endif; ?></td>
    <td style="white-space:nowrap;">
        <button class="btn btn-sm btn-outline" onclick="openRecDetail(<?=$item['id']?>)" title="查看明细"><i class="fa-solid fa-circle-info"></i></button>
        <?php if (!$isCancel): ?>
        <?php if (isset($refundMap[$item['id']])): ?>
        <button class="btn btn-sm btn-outline" onclick="openRefundModal(<?=$item['id']?>)" title="退款（钱已退回客户）"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--warning)"></i></button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline" onclick="openCancelModal(<?=$item['id']?>,'<?=$item['bill_no']?>')" title="作废（录入错误时使用）"><i class="fa-solid fa-ban" style="color:var(--danger)"></i></button>
        <?php else: ?>
        <span style="color:var(--gray-500);font-size:12px;">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-money-bill-wave"></i><p>暂无收款记录</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<div style="font-size:13px;color:var(--gray-600);margin-top:8px;">共 <?=$total?> 条记录，其中有效收款合计 <strong style="color:var(--success)">¥<?=format_money($sumAmount)?></strong></div>

<?php if($pages>1): ?><div class="pagination"><span class="info">共<?=$total?>条/<?=$pages?>页</span><?php for($i=1;$i<=$pages;$i++): ?><a href="?page=<?=$i?>&<?=http_build_query(array_filter(['kw'=>$kw,'customer_id'=>$filterCust?:'','purpose'=>$filterPurpose,'date_from'=>$dateFrom,'date_to'=>$dateTo]))?>" class="<?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
<?php endif; // end 非受限模式 ?>

<!-- 收款明细 -->
<div class="modal-overlay" id="recDetailModal"><div class="modal"><div class="modal-header"><h3 class="modal-title">收款明细</h3><button class="modal-close" onclick="closeModal('recDetailModal')">&times;</button></div>
<div class="modal-body" id="recDetailBody"></div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('recDetailModal')">关闭</button></div>
</div></div>

<!-- 新增收款 -->
<div class="modal-overlay" id="recModal"><div class="modal"><div class="modal-header"><h3 class="modal-title">新增收款</h3><button class="modal-close" onclick="closeModal('recModal')">&times;</button></div>
<form method="post" id="recForm"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<input type="hidden" name="mode" id="recMode" value="simple">
<input type="hidden" name="main_order_id" id="recMainOrderId" value="0">
<input type="hidden" name="main_amount" id="recMainAmount" value="0">
<input type="hidden" name="main_source" id="recMainSource" value="cash">
<div class="modal-body">
    <div class="form-group"><label class="form-label">客户 <span class="required">*</span></label>
        <select name="customer_id" id="recCustId" class="form-control searchable" required onchange="loadRecOrders()">
            <option value="">请选择客户</option>
            <?php foreach($customers as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" id="recOrderWrap"><label class="form-label">收款订单 <span class="required">*</span></label>
        <select id="recOrderSel" class="form-control" onchange="onRecOrderChange()"><option value="">请先选择客户</option></select>
        <div id="recOrderInfo" style="font-size:12px;color:var(--gray-600);margin-top:4px;">选定订单后金额会自动带出该订单的待收余额，可按实际收款金额修改。</div>
    </div>
    <div class="form-group"><label class="form-label">本次收款金额（元）</label>
        <input type="number" step="0.01" min="0" name="amount" id="recAmount" class="form-control" oninput="refreshRecSummary()" placeholder="0.00">
        <div id="recAmountHint" style="font-size:12px;color:var(--gray-600);margin-top:4px;"></div>
    </div>
    <div id="prepayHint" style="font-size:12px;color:var(--gray-600);margin:0 0 10px;"></div>
    <div class="form-group"><label class="form-label">收款用途</label>
        <select name="purpose" class="form-control">
            <option value="">不指定</option>
            <?php foreach($purposes as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">收款方式</label>
        <select name="pay_method" class="form-control"><?php foreach($payMethods as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group"><label class="form-label">收款日期</label><input type="date" name="receipt_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">备注</label><textarea name="remark" class="form-control" rows="2" placeholder="如：定金、第二次付款、尾款等"></textarea></div>

    <details id="recAdvanced" style="margin-top:6px;border-top:1px solid var(--gray-200);padding-top:8px;">
        <summary style="cursor:pointer;font-size:13px;color:var(--gray-600);">高级选项（记预收款 / 拆分到多张订单）</summary>
        <div style="margin-top:10px;">
            <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" id="recAllPrepay" onchange="toggleAllPrepay()"> 整笔记为预收款（不指定订单，钱先挂在客户名下）
            </label>
            <div style="margin-top:10px;">
                <div style="font-size:12px;color:var(--gray-600);margin-bottom:6px;">把这一笔收款拆分到多张订单：下面的合计不超过「本次收款金额」，未分配部分自动记为预收款。</div>
                <table id="allocTable" style="width:100%;font-size:13px;">
                    <thead><tr><th style="text-align:left;">销售订单</th><th style="width:110px;text-align:left;">核销金额</th><th style="width:150px;text-align:left;">资金来源</th><th style="width:60px;"></th></tr></thead>
                    <tbody id="allocBody"></tbody>
                </table>
                <button type="button" class="btn btn-outline btn-sm" onclick="addAllocRow()" style="margin-top:6px;"><i class="fa-solid fa-plus"></i> 添加核销订单</button>
                <div id="allocSummary" style="margin-top:8px;font-size:13px;"></div>
            </div>
        </div>
    </details>

    <div id="recSummary" style="margin-top:10px;font-size:13px;padding:8px 10px;background:var(--gray-100);border-radius:var(--radius);"></div>
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
        <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="如：录入错误、金额写错、单据开错"></textarea>
    </div>
    <div style="font-size:12px;color:var(--gray-600);">作废后该笔金额将从关联订单的已收金额中回冲，收款单保留留痕，不做物理删除。<br>
    <strong>作废只用于「录错了」</strong>；钱真的退给客户请点列表里的退款按钮，系统要留下对应的退款凭证。</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">取消</button><button type="submit" class="btn btn-danger">确认作废</button></div>
</form></div></div>

<!-- 退款（钱真的退回客户） -->
<div class="modal-overlay" id="refundModal"><div class="modal modal-sm"><div class="modal-header"><h3 class="modal-title">登记退款</h3><button class="modal-close" onclick="closeModal('refundModal')">&times;</button></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refund">
<div class="modal-body">
    <div class="form-group"><label class="form-label">要退的那笔收款 <span class="required">*</span></label>
        <select name="alloc_id" id="refundAllocSel" class="form-control" required onchange="onRefundAllocChange()"></select>
    </div>
    <div class="form-group"><label class="form-label">退款金额（元）</label>
        <input type="number" step="0.01" min="0.01" name="refund_amount" id="refundAmount" class="form-control" required>
        <div id="refundAmountHint" style="font-size:12px;color:var(--gray-600);margin-top:4px;"></div>
    </div>
    <div class="form-group"><label class="form-label">退款原因 <span class="required">*</span></label>
        <textarea name="refund_reason" class="form-control" rows="3" required placeholder="如：客户取消订单，定金已全额退回"></textarea>
    </div>
    <div style="font-size:12px;color:var(--gray-600);">退款后：挂在订单上的会冲减该订单已收（付款状态同步回退）；挂在客户名下的会减少其可用预收款。<br>
    系统会自动生成退款单号并留痕，同一笔可以分多次退。</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('refundModal')">取消</button><button type="submit" class="btn btn-primary">确认退款</button></div>
</form></div></div>
<?php endif; // end 非受限模式（作废/退款弹窗） ?>

<script>
var recOrders = <?= json_encode($prefill ? [['id'=>$prefill['id'],'bill_no'=>$prefill['bill_no'],'total_amount'=>$prefill['total_amount'],'received_amount'=>$prefill['received_amount'],'balance'=>$prefill['balance']]] : [], JSON_UNESCAPED_UNICODE) ?>;
var recPrefill = <?= json_encode($prefill ? ['customer_id'=>intval($prefill['customer_id']),'order_id'=>intval($prefill['id']),'amount'=>max(0, floatval($prefill['balance']))] : null, JSON_UNESCAPED_UNICODE) ?>;

// 该客户名下的可用预收款余额（由 api/get_prepay.php 加载）
var recPrepay = 0;

// 收款明细弹窗的数据源（服务端一次渲染好，点「查看明细」时不再发请求）
var recDetails = <?= json_encode($receiptDetails ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// 退款弹窗的数据源：收款单ID → [可退明细]
var recRefundMap = <?= json_encode($refundMap ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function resetRecForm(){
    document.getElementById('recForm').reset();
    document.getElementById('recCustId').selectedIndex = 0;
    document.getElementById('allocBody').innerHTML = '';
    document.getElementById('recOrderSel').innerHTML = '<option value="">请先选择客户</option>';
    document.getElementById('recMainOrderId').value = '0';
    document.getElementById('recMainAmount').value = '0';
    document.getElementById('recMainSource').value = 'cash';
    document.getElementById('recMode').value = 'simple';
    document.getElementById('recAllPrepay').checked = false;
    document.getElementById('recOrderWrap').style.display = '';
    recOrders = [];
    recPrepay = 0;
    renderPrepayHint();
    refreshRecSummary();
}

// 预收款提示条：有余额才出现，并直接给出「一键抵扣」按钮，
// 不让用户自己去核销明细里找「资金来源=预收款抵扣」
function renderPrepayHint(){
    var hint = document.getElementById('prepayHint');
    if (!hint) return;
    if (recPrepay > 0.005) {
        hint.innerHTML = '该客户名下有预收款 <strong style="color:var(--success)">¥' + recPrepay.toFixed(2)
            + '</strong>　<button type="button" class="btn btn-sm btn-outline" onclick="usePrepayForOrder()">用预收款抵扣所选订单</button>';
    } else {
        hint.innerHTML = '';
    }
}

// 用预收款冲抵所选订单：钱早就收过了，这里不新增收款流水，所以「本次收款金额」保持 0
function usePrepayForOrder(){
    var sel = document.getElementById('recOrderSel');
    var oid = parseInt(sel.value, 10) || 0;
    if (!oid) { alert('请先选择要用预收款抵扣的订单'); return; }
    var o = recOrders.filter(function(x){ return x.id === oid; })[0];
    var bal = o ? Math.max(0, parseFloat(o.balance) || 0) : 0;
    if (bal <= 0.005) { alert('该订单已收清，无需再抵扣'); return; }
    var amt = Math.min(bal, recPrepay);
    document.getElementById('recMainSource').value = 'prepay';
    document.getElementById('recMainAmount').value = amt.toFixed(2);
    document.getElementById('recAmount').value = '0';
    refreshRecSummary();
}

// 高级选项：整笔记为预收款时不选订单
function toggleAllPrepay(){
    var on = document.getElementById('recAllPrepay').checked;
    document.getElementById('recOrderWrap').style.display = on ? 'none' : '';
    refreshRecSummary();
}

// 同步多单核销行里的「资金来源」选项：没有预收款时禁用，避免选了却提交不过
function refreshSourceOptions(){
    document.querySelectorAll('#allocBody select[name="alloc_source[]"]').forEach(function(s){
        var opt = s.querySelector('option[value="prepay"]');
        if (!opt) return;
        opt.textContent = '预收款抵扣（可用¥' + recPrepay.toFixed(2) + '）';
        opt.disabled = recPrepay <= 0.005;
        if (opt.disabled && s.value === 'prepay') { s.value = 'cash'; }
    });
}

function loadRecOrders(callback){
    var custId = document.getElementById('recCustId').value;
    var done = false;
    function fin(){ if (!done) { done = true; if (typeof callback === 'function') callback(); } }
    document.getElementById('allocBody').innerHTML = '';
    recOrders = [];
    recPrepay = 0;
    renderRecOrderOptions();
    if (!custId) { refreshRecSummary(); fin(); return; }
    fetch('/api/get_orders.php?type=sales&customer_id=' + custId)
    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function(data){
        recOrders = (data||[]).map(function(o){
            return {id:o.id, bill_no:o.bill_no, total_amount:o.total_amount, received_amount:o.received_amount||0,
                    balance:(parseFloat(o.total_amount)-(parseFloat(o.received_amount)||0))};
        });
        if (typeof initSearchableSelects === 'function') { initSearchableSelects(); }
        renderRecOrderOptions();
        refreshSourceOptions();
        refreshRecSummary();
        fin();
        // 预收款余额单独加载，不阻塞订单下拉的渲染
        return fetch('/api/get_prepay.php?customer_id=' + custId);
    })
    .then(function(r){ return r.ok ? r.json() : {available:0}; })
    .then(function(d){
        recPrepay = parseFloat((d||{}).available) || 0;
        refreshSourceOptions();
        renderPrepayHint();
        refreshRecSummary();
    })
    .catch(function(e){ console.error('加载订单/预收款失败:', e); fin(); });
}

// 收款订单下拉：只列还没收清的订单，选项上直接带出待收金额
function renderRecOrderOptions(){
    var sel = document.getElementById('recOrderSel');
    var keep = sel.value;
    sel.innerHTML = '';
    var unpaid = recOrders.filter(function(o){ return (parseFloat(o.balance)||0) > 0.005; });
    if (!recOrders.length) {
        sel.innerHTML = '<option value="">请先选择客户</option>';
    } else if (!unpaid.length) {
        sel.innerHTML = '<option value="">该客户的订单都已收清</option>';
    } else {
        var ph = document.createElement('option');
        ph.value = ''; ph.textContent = '请选择订单';
        sel.appendChild(ph);
        unpaid.forEach(function(o){
            var opt = document.createElement('option');
            opt.value = o.id;
            opt.textContent = o.bill_no + '　[待收 ¥' + (parseFloat(o.balance)||0).toFixed(2) + ']';
            sel.appendChild(opt);
        });
    }
    if (keep && Array.prototype.some.call(sel.options, function(op){ return op.value === keep; })) { sel.value = keep; }
}

// 选中订单后：金额自动带出待收余额（可改成实际收到的钱，支持分次收款）
function onRecOrderChange(){
    var sel = document.getElementById('recOrderSel');
    var oid = parseInt(sel.value, 10) || 0;
    var o = recOrders.filter(function(x){ return x.id === oid; })[0];
    var info = document.getElementById('recOrderInfo');
    var amtEl = document.getElementById('recAmount');
    var hintEl = document.getElementById('recAmountHint');
    if (o) {
        var bal = Math.max(0, parseFloat(o.balance) || 0);
        info.innerHTML = '订单金额 ¥' + (parseFloat(o.total_amount)||0).toFixed(2)
            + ' ｜ 已收 ¥' + (parseFloat(o.received_amount)||0).toFixed(2)
            + ' ｜ 待收 <strong style="color:var(--warning)">¥' + bal.toFixed(2) + '</strong>';
        if (!amtEl.value || parseFloat(amtEl.value) <= 0) { amtEl.value = bal.toFixed(2); }
        hintEl.textContent = '默认带出待收余额 ¥' + bal.toFixed(2) + '，按实际到账金额改即可（可分多次收）。';
    } else {
        info.textContent = '选定订单后金额会自动带出该订单的待收余额，可按实际收款金额修改。';
        hintEl.textContent = '';
    }
    refreshRecSummary();
}

// 高级选项里的多订单核销：一旦加了核销行就切成 advanced 模式，服务端改按明细数组入账
function addAllocRow(orderId, amount, source){
    var tbody = document.getElementById('allocBody');
    document.getElementById('recMode').value = 'advanced';
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
    inp.oninput = refreshRecSummary;
    tdAmt.appendChild(inp);

    // 资金来源：cash=本次收到的钱；prepay=客户账上已有的预收款（不新增收款流水）
    var tdSrc = document.createElement('td');
    var src = document.createElement('select');
    src.name = 'alloc_source[]';
    src.className = 'form-control';
    var srcCash = document.createElement('option');
    srcCash.value = 'cash'; srcCash.textContent = '本次收款';
    var srcPre = document.createElement('option');
    srcPre.value = 'prepay'; srcPre.textContent = '预收款抵扣';
    src.appendChild(srcCash); src.appendChild(srcPre);
    src.onchange = refreshRecSummary;
    tdSrc.appendChild(src);

    var tdDel = document.createElement('td');
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-sm btn-outline'; btn.innerHTML = '<i class="fa-solid fa-trash" style="color:var(--danger)"></i>';
    btn.onclick = function(){ tr.remove(); refreshRecSummary(); };
    tdDel.appendChild(btn);

    tr.appendChild(tdSel); tr.appendChild(tdAmt); tr.appendChild(tdSrc); tr.appendChild(tdDel);
    tbody.appendChild(tr);
    sel.onchange = function(){
        var o = recOrders.filter(function(x){ return x.id === parseInt(sel.value,10); })[0];
        if (o) { inp.value = Math.max(0, o.balance).toFixed(2); refreshRecSummary(); }
    };
    if (source === 'prepay' && orderId) { src.value = 'prepay'; }
    refreshSourceOptions();
    refreshRecSummary();
}

function refreshAllocSummary(){
    var amount = parseFloat(document.getElementById('recAmount').value) || 0;
    var cashTotal = 0, prepayTotal = 0;
    document.querySelectorAll('#allocBody tr').forEach(function(tr){
        var inp = tr.querySelector('input[name="alloc_amount[]"]');
        var src = tr.querySelector('select[name="alloc_source[]"]');
        var ord = tr.querySelector('select[name="alloc_order_id[]"]');
        if (!inp) return;
        var v = parseFloat(inp.value) || 0;
        if (src && src.value === 'prepay' && ord && parseInt(ord.value,10) > 0) { prepayTotal += v; }
        else { cashTotal += v; }
    });
    var rest = amount - cashTotal;
    var el = document.getElementById('allocSummary');
    var html = '收款 ¥' + amount.toFixed(2);
    if (prepayTotal > 0.005) { html += ' ＋ 预收款抵扣 <strong style="color:var(--success)">¥' + prepayTotal.toFixed(2) + '</strong>'; }
    html += ' ｜ 本次已分配 <strong>¥' + cashTotal.toFixed(2) + '</strong>';
    html += rest > 0.005 ? ' ｜ 剩余 <strong style="color:var(--warning)">¥' + rest.toFixed(2) + '</strong>（记为预收款）'
                         : (rest < -0.005 ? ' ｜ <strong style="color:var(--danger)">已分配超过收款金额 ¥' + Math.abs(rest).toFixed(2) + '</strong>' : '');
    if (prepayTotal - recPrepay > 0.005) {
        html += ' ｜ <strong style="color:var(--danger)">预收款不足，还差 ¥' + (prepayTotal - recPrepay).toFixed(2) + '</strong>';
    }
    el.innerHTML = html;
}

// 主流程的一句话说明 + 隐藏字段同步：保存前就能看到「收完这笔还剩多少」
function refreshRecSummary(){
    var sel = document.getElementById('recOrderSel');
    var oid = parseInt(sel.value, 10) || 0;
    var amount = parseFloat(document.getElementById('recAmount').value) || 0;
    var srcEl = document.getElementById('recMainSource');
    var src = srcEl.value;
    // 预收款抵扣时本次不收钱；一旦手填了金额就按现金收款处理
    if (src === 'prepay' && amount > 0.005) { src = 'cash'; srcEl.value = 'cash'; }
    var allPre = document.getElementById('recAllPrepay').checked;
    var mainOrderEl = document.getElementById('recMainOrderId');
    var mainAmtEl = document.getElementById('recMainAmount');
    var advanced = document.querySelectorAll('#allocBody tr').length > 0;
    document.getElementById('recMode').value = advanced ? 'advanced' : 'simple';
    mainOrderEl.value = allPre ? '0' : String(oid);

    var o = recOrders.filter(function(x){ return x.id === oid; })[0];
    var sum = document.getElementById('recSummary');
    var html = '';

    if (advanced) {
        refreshAllocSummary();
        mainAmtEl.value = '0';
        html = '已启用多订单核销：请在上面的明细表里分配本次收款 ¥' + amount.toFixed(2) + '。';
    } else if (src === 'prepay') {
        var pa = parseFloat(mainAmtEl.value) || 0;
        html = '将用该客户的预收款 <strong style="color:var(--success)">¥' + pa.toFixed(2) + '</strong> 抵扣订单 '
             + (o ? o.bill_no : '') + '：钱已经收过，本次收款金额保持 0，不新增收款流水。';
    } else if (allPre) {
        mainAmtEl.value = amount.toFixed(2);
        html = '本次收款 <strong>¥' + amount.toFixed(2) + '</strong> 全部记为该客户的预收款，不冲抵任何订单。';
    } else if (o) {
        mainAmtEl.value = amount.toFixed(2);
        var bal = Math.max(0, parseFloat(o.balance) || 0);
        if (amount <= 0.005) {
            html = '订单 ' + o.bill_no + '：订单金额 ¥' + (parseFloat(o.total_amount)||0).toFixed(2)
                 + ' ｜ 已收 ¥' + (parseFloat(o.received_amount)||0).toFixed(2)
                 + ' ｜ 待收 <strong style="color:var(--warning)">¥' + bal.toFixed(2) + '</strong>。';
        } else if (amount - bal > 0.01) {
            html = '订单 ' + o.bill_no + ' 待收 ¥' + bal.toFixed(2) + '，本次收 <strong>¥' + amount.toFixed(2) + '</strong>：'
                 + '其中 ¥' + bal.toFixed(2) + ' 收满订单，超出 <strong style="color:var(--warning)">¥' + (amount - bal).toFixed(2) + '</strong> 自动记为预收款。';
        } else {
            html = '订单 ' + o.bill_no + '：本次收 <strong>¥' + amount.toFixed(2) + '</strong>，'
                 + '收款后仍待收 <strong style="color:var(--warning)">¥' + Math.max(0, bal - amount).toFixed(2) + '</strong>。';
        }
    } else {
        mainAmtEl.value = amount.toFixed(2);
        html = '请选择客户与订单；收进去的钱会自动更新订单的已收金额与待收金额。';
    }
    if (!advanced) { document.getElementById('allocSummary').innerHTML = ''; }
    sum.innerHTML = html;
}

function openCancelModal(id, billNo){
    document.getElementById('cancelId').value = id;
    document.getElementById('cancelBillNo').textContent = billNo;
    openModal('cancelModal');
}

// 退款：把这张收款单下还能退的明细列出来，选一笔后金额默认带出剩余可退额
function openRefundModal(receiptId){
    var rows = recRefundMap[receiptId] || [];
    if (!rows.length) { alert('该收款单已没有可退金额'); return; }
    var sel = document.getElementById('refundAllocSel');
    sel.innerHTML = '';
    rows.forEach(function(r){
        var op = document.createElement('option');
        op.value = r.id; op.textContent = r.text; op.dataset.refundable = r.refundable;
        sel.appendChild(op);
    });
    onRefundAllocChange();
    openModal('refundModal');
}

function onRefundAllocChange(){
    var sel = document.getElementById('refundAllocSel');
    var op = sel.options[sel.selectedIndex];
    var max = op ? parseFloat(op.dataset.refundable) || 0 : 0;
    var amt = document.getElementById('refundAmount');
    amt.max = max.toFixed(2);
    amt.value = max.toFixed(2);
    document.getElementById('refundAmountHint').textContent = '最多可退 ¥' + max.toFixed(2) + '（同一笔可分多次退）';
}

// 收款记录明细弹窗：把这一笔的用途、核销去向、备注一次展示清楚
function recEsc(s){ return String(s === null || s === undefined ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function openRecDetail(id){
    var d = recDetails[id];
    if (!d) { alert('未找到该收款记录'); return; }
    var rows = [
        ['收款单号', d.bill_no], ['客户', d.customer], ['收款金额', '¥' + d.amount],
        ['用途', d.purpose || '—'], ['收款方式', d.pay_method], ['收款日期', d.date],
        ['状态', d.status], ['核销去向', d.alloc], ['备注', d.remark || '—'],
        ['登记人', d.user], ['登记时间', d.created]
    ];
    var html = '<table style="width:100%;font-size:13px;">';
    rows.forEach(function(r){
        html += '<tr><td style="width:88px;color:var(--gray-600);padding:4px 0;">' + r[0] + '</td>'
             +  '<td style="padding:4px 0;">' + recEsc(r[1]) + '</td></tr>';
    });
    html += '</table>';
    if (d.cancel_reason) {
        html += '<div style="margin-top:8px;font-size:13px;color:var(--danger);">作废原因：' + recEsc(d.cancel_reason) + '</div>';
    }
    document.getElementById('recDetailBody').innerHTML = html;
    openModal('recDetailModal');
}

// 从订单/出库单详情跳转过来：自动带出客户、订单与待收金额
// 幂等保护：main.js 的 executeScripts() 会把内容区的 <script> 重建后再执行一次
// （see assets/js/main.js:334），不设栅栏就会重复触发（曾导致「一进来两行」）
(function(){
    if (!recPrefill) return;
    var custSel = document.getElementById('recCustId');
    if (custSel.getAttribute('data-prefill-done') === '1') return;
    custSel.setAttribute('data-prefill-done', '1');
    custSel.value = String(recPrefill.customer_id);
    loadRecOrders(function(){
        var oSel = document.getElementById('recOrderSel');
        if (recPrefill.order_id) { oSel.value = String(recPrefill.order_id); }
        onRecOrderChange();
        if (recPrefill.amount > 0.005) { document.getElementById('recAmount').value = recPrefill.amount.toFixed(2); }
        refreshRecSummary();
        openModal('recModal');
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
