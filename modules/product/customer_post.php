<?php
/**
 * 客户资料的保存/删除处理
 * 由 customer.php 在输出任何 HTML 之前 require，既可返回 JSON（AJAX），也可 PRG 跳转
 * 依赖在引入前已准备好的：$pdo、$isAjax、$page、$search
 */

/**
 * @return array ['ok'=>bool,'msg'=>string,'type'=>string]
 */
function customer_handle_post($pdo, $ajax = false) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $msg = '';
    $msgType = 'success';

    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $cName = trim($_POST['name'] ?? '');
        $code  = trim($_POST['code'] ?? '');
        // 意向是 ENUM('高','中','低')，下拉「请选择」提交的是空串，
        // 直接入库在严格 SQL 模式下会报错，必须转成 NULL
        $intention   = trim($_POST['intention'] ?? '') ?: null;
        $sourceId    = intval($_POST['source_id'] ?? 0) ?: null;
        $developedAt = trim($_POST['developed_at'] ?? '') ?: null;

        if ($cName === '') {
            $msg = '客户名称不能为空';
            $msgType = 'danger';
        } else {
            // 编码查重：code 列没有 UNIQUE 约束（历史重复数据会让加约束直接失败），
            // 只能在应用层挡，否则两个入口各生成各的会撞号
            $dup = $pdo->prepare("SELECT id FROM customers WHERE code<>'' AND code=? AND id<>? LIMIT 1");
            $dup->execute([$code, $id]);
            if ($code !== '' && $dup->fetch()) {
                $msg = "客户编码「{$code}」已被其它客户使用，请换一个";
                $msgType = 'danger';
            } else {
                // 两边字段取并集后统一写全，CRM 那几个字段这里也要写，
                // 否则在 CRM 录的公司/来源/意向，用这边的表单改不动
                $common = [
                    $code, $cName, $_POST['type'] ?? 'company', $_POST['contact'] ?? '',
                    $_POST['phone'] ?? '', $_POST['email'] ?? '', $_POST['address'] ?? '',
                    floatval($_POST['initial_balance'] ?? 0), $_POST['remark'] ?? '',
                    $_POST['company'] ?? '', $_POST['wechat'] ?? '', $sourceId,
                    $intention, $_POST['intended_product'] ?? '', $developedAt,
                ];
                try {
                    if ($id > 0) {
                        $pdo->prepare("UPDATE customers SET code=?,name=?,type=?,contact=?,phone=?,email=?,address=?,initial_balance=?,remark=?,company=?,wechat=?,source_id=?,intention=?,intended_product=?,developed_at=? WHERE id=?")
                            ->execute(array_merge($common, [$id]));
                        add_log(get_user_id(), 'update', 'customer', "编辑客户: {$cName}(ID:$id)");
                        $msg = '客户信息已保存';
                    } else {
                        // 与 CRM 保持一致：新建时补 owner_id/in_pool/created_by/last_followed_at，
                        // 否则这批客户在 CRM 侧归属显示为空、来源和意向筛选全部落空
                        $userId = get_user_id();
                        $pdo->prepare("INSERT INTO customers (code,name,type,contact,phone,email,address,initial_balance,remark,company,wechat,source_id,intention,intended_product,developed_at,owner_id,in_pool,last_followed_at,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)")
                            ->execute(array_merge($common, [$userId, $userId, date('Y-m-d H:i:s')]));
                        add_log(get_user_id(), 'create', 'customer', "新建客户: {$cName}");
                        $msg = '客户已新增';
                    }
                } catch (Exception $e) {
                    error_log('Customer save error: ' . $e->getMessage());
                    $msg = '保存失败：' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        // 客户被单据引用即不可物理删除（删除会让该客户的应收从全系统统计中蒸发）
        $chk = check_refs($id, [
            '销售订单'   => "SELECT COUNT(*) FROM sales_orders WHERE customer_id=?",
            '销售出库单' => "SELECT COUNT(*) FROM sales_outstocks WHERE customer_id=?",
            '销售退货单' => "SELECT COUNT(*) FROM sales_returns WHERE customer_id=?",
            '销售报价'   => "SELECT COUNT(*) FROM sales_quotes WHERE customer_id=?",
            '收款单'     => "SELECT COUNT(*) FROM receipts WHERE customer_id=?",
            '跟进记录'   => "SELECT COUNT(*) FROM customer_followups WHERE customer_id=?",
        ]);
        if (!$chk['ok']) {
            $msg = $chk['msg'];
            $msgType = 'danger';
        } else {
            $st = $pdo->prepare("SELECT name FROM customers WHERE id=?");
            $st->execute([$id]);
            $cname = $st->fetchColumn();
            $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
            add_log(get_user_id(), 'delete', 'customer', "删除客户: {$cname}(ID:$id)");
            $msg = '客户已删除：' . $cname;
        }
    } else {
        $msg = '未知操作';
        $msgType = 'danger';
    }

    if ($ajax) {
        return ['ok' => $msgType === 'success', 'msg' => $msg, 'type' => $msgType];
    }
    flash_set($msg, $msgType);
    return ['ok' => $msgType === 'success', 'msg' => $msg, 'type' => $msgType];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 新增/修改/删除客户需要 customer_edit 权限（默认只有管理员拥有）
    // 注意：本文件在 customer.php 的页面级权限校验之前就被引入，因此这里必须自己校验，防止绕过按钮直接提交
    if (!check_permission('customer_edit')) {
        $denyMsg = '无权限：只有管理员或被授权的角色才能新增/修改/删除客户';
        if ($isAjax) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => $denyMsg, 'type' => 'danger'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set($denyMsg, 'danger');
        redirect('customer.php?page=' . max(1, intval($page)) . '&search=' . urlencode($search));
    }
    $result = null;
    try {
        $result = customer_handle_post($pdo, $isAjax);
    } catch (Exception $e) {
        error_log('Customer post error: ' . $e->getMessage());
        $result = ['ok' => false, 'msg' => '请求失败：' . $e->getMessage(), 'type' => 'danger'];
    }

    if ($isAjax) {
        // 丢弃此前可能产生的任何输出，确保返回纯 JSON
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    redirect('customer.php?page=' . max(1, intval($page)) . '&search=' . urlencode($search));
}
