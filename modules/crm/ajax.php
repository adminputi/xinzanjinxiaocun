<?php
/**
 * CRM Ajax 接口
 * 处理：查重、快速跟进、认领、归池、关联订单、附件上传
 */
require_once __DIR__ . '/../../includes/api_init.php';
require_once __DIR__ . '/../../includes/migration.php';

$pdo = getDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// CRM 跟进附件允许的扩展名（图片 / 文档 / 演示文稿 / 压缩包）
const CRM_ATTACHMENT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'zip', 'rar'];
// CRM 跟进附件大小上限：50MB
const CRM_ATTACHMENT_MAX_SIZE = 52428800;

header('Content-Type: application/json; charset=utf-8');

// JSON 友好的 CSRF 验证（替代 csrf_verify()，失败返回 JSON 而非 HTML）
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['_csrf_token'] ?? '';
    if (!csrf_is_valid($token)) {
        csrf_regenerate();
        // 下发新令牌，前端刷新后可重试（页面脚本里的 CSRF_TOKEN 常量由 main.js 自动更新）
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => '页面已过期（安全验证失败），请刷新页面后重试',
            'csrf_expired' => true,
            'csrf_token' => $_SESSION['csrf_token'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 校验通过后不再轮换令牌：轮换会让同一会话其它页面（尤其是把令牌写死在 JS 常量里的页面）
    // 的令牌立刻失效，导致后续所有提交都报“安全验证失败”
}

// ========== 电话查重 ==========
if ($action === 'check_phone') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) json_response(false, '请输入电话');
    $stmt = $pdo->prepare("SELECT c.id, c.name, u.real_name as owner_name FROM customers c LEFT JOIN users u ON c.owner_id=u.id WHERE c.phone=? AND c.status=1 LIMIT 5");
    $stmt->execute([$phone]);
    $dups = $stmt->fetchAll();
    json_response(true, '', $dups);
}

// ========== 快速添加跟进 ==========
if ($action === 'add_followup') {
    csrf_check();
    run_migrations(); // 确保 attachment_name 字段已存在
    $customerId = intval($_POST['customer_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $followType = $_POST['follow_type'] ?? '电话';
    $result = $_POST['result'] ?? '待跟进';
    $nextFollow = normalize_datetime_input($_POST['next_follow_at'] ?? '');
    if ($nextFollow === false) json_response(false, '计划下次跟进时间格式不正确');
    $userId = get_user_id();
    if (!$customerId) json_response(false, '缺少客户ID');
    if (!$content) json_response(false, '请填写跟进内容');

    // 处理附件：任何失败都要把原因告知用户，不能静默丢弃
    $attachment = '';
    $attachmentName = '';
    $attachError = '';
    if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $errCode = intval($_FILES['attachment']['error']);
        if ($errCode !== UPLOAD_ERR_OK) {
            $attachError = '文件上传出错（错误码 ' . $errCode . '），通常是文件超过服务器 upload_max_filesize 限制';
        } else {
            $res = upload_file('attachment', CRM_ATTACHMENT_EXT, CRM_ATTACHMENT_MAX_SIZE);
            if ($res['success']) {
                $attachment = $res['path'];
                $attachmentName = $res['original_name'] ?? '';
            } else {
                $attachError = $res['message'];
            }
        }
    }

    $pdo->prepare("INSERT INTO customer_followups (customer_id,user_id,follow_type,content,attachment,attachment_name,next_follow_at,result,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([$customerId, $userId, $followType, $content, $attachment, $attachmentName, $nextFollow ?: null, $result]);
    $pdo->prepare("UPDATE customers SET last_followed_at=NOW() WHERE id=?")->execute([$customerId]);
    add_log($userId, 'followup', 'crm', "添加跟进: customer_id={$customerId}");

    if ($attachError) {
        json_response(true, '跟进已添加，但附件上传失败：' . $attachError);
    }
    json_response(true, '跟进已添加');
}

// ========== 修改跟进记录（需 crm_followup_edit 权限，如管理员）==========
if ($action === 'update_followup') {
    csrf_check();
    run_migrations(); // 确保 updated_at / updated_by 字段已存在
    if (!check_permission('crm_followup_edit')) json_response(false, '无权限：仅管理员或被授权角色可修改跟进记录');

    $id = intval($_POST['id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $followType = $_POST['follow_type'] ?? '电话';
    $result = $_POST['result'] ?? '待跟进';
    $nextFollow = normalize_datetime_input($_POST['next_follow_at'] ?? '');
    if ($nextFollow === false) json_response(false, '计划下次跟进时间格式不正确');
    $removeAttachment = intval($_POST['remove_attachment'] ?? 0) === 1;
    $userId = get_user_id();

    if (!$id) json_response(false, '缺少记录ID');
    if (!$content) json_response(false, '请填写跟进内容');

    $stmt = $pdo->prepare("SELECT * FROM customer_followups WHERE id=?");
    $stmt->execute([$id]);
    $fw = $stmt->fetch();
    if (!$fw) json_response(false, '记录不存在');

    // 白名单校验：follow_type / result 是 ENUM，写入非法值会被截断，这里回退为原值
    $allowTypes = ['电话', '微信', '面谈', '拜访', '短信', '邮件', '其他'];
    $allowResults = ['待跟进', '有意向', '已成交', '无意向'];
    if (!in_array($followType, $allowTypes, true)) $followType = $fw['follow_type'];
    if (!in_array($result, $allowResults, true)) $result = $fw['result'];

    $attachment = $fw['attachment'] ?: '';
    $attachmentName = $fw['attachment_name'] ?: '';
    $attachError = '';

    // 勾选了“删除当前附件”
    if ($removeAttachment && $attachment) {
        @unlink(__DIR__ . '/../../' . $attachment);
        $attachment = '';
        $attachmentName = '';
    }

    // 上传新附件（覆盖旧的）
    if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $errCode = intval($_FILES['attachment']['error']);
        if ($errCode !== UPLOAD_ERR_OK) {
            $attachError = '文件上传出错（错误码 ' . $errCode . '），通常是文件超过服务器 upload_max_filesize 限制';
        } else {
            $res = upload_file('attachment', CRM_ATTACHMENT_EXT, CRM_ATTACHMENT_MAX_SIZE);
            if ($res['success']) {
                if ($attachment) @unlink(__DIR__ . '/../../' . $attachment);
                $attachment = $res['path'];
                $attachmentName = $res['original_name'] ?? '';
            } else {
                $attachError = $res['message'];
            }
        }
    }

    // 不改 user_id / created_at：保留原始跟进人与记录时间，避免归属和统计混乱
    $pdo->prepare("UPDATE customer_followups SET follow_type=?, content=?, attachment=?, attachment_name=?, next_follow_at=?, result=?, updated_at=NOW(), updated_by=? WHERE id=?")
        ->execute([$followType, $content, $attachment, $attachmentName, $nextFollow ?: null, $result, $userId, $id]);

    add_log($userId, 'edit_followup', 'crm', "修改跟进: id={$id}, customer_id={$fw['customer_id']}");

    if ($attachError) {
        json_response(true, '跟进已更新，但附件上传失败：' . $attachError);
    }
    json_response(true, '跟进已更新');
}

// ========== 认领客户 ==========
if ($action === 'claim') {
    csrf_check();
    $customerId = intval($_POST['customer_id'] ?? 0);
    $userId = get_user_id();
    if (!$customerId) json_response(false, '无效客户');

    // 行级锁防并发认领
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=? AND in_pool=1 FOR UPDATE");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch();
    if (!$cust) json_response(false, '该客户不在公海中或已被认领');

    $oldOwner = $cust['owner_id'];
    // 认领时设置last_followed_at=NOW()，避免被公海自动回收规则立即回收
    $affected = $pdo->prepare("UPDATE customers SET owner_id=?, in_pool=0, pooled_at=NULL, last_followed_at=NOW() WHERE id=? AND in_pool=1")
        ->execute([$userId, $customerId]);
    if (!$affected) json_response(false, '认领失败，请刷新重试');
    $pdo->prepare("INSERT INTO customer_transfer_logs (customer_id,from_user_id,to_user_id,action,operator_id,created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$customerId, $oldOwner, $userId, 'claim', $userId]);
    add_log($userId, 'claim', 'crm', "认领客户: customer_id={$customerId}");
    json_response(true, '认领成功');
}

// ========== 归入公海 ==========
if ($action === 'to_pool') {
    csrf_check();
    $customerId = intval($_POST['customer_id'] ?? 0);
    $userId = get_user_id();
    $isAdmin = (get_user_role() === 'admin');
    if (!$customerId) json_response(false, '无效客户');

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=? AND in_pool=0");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch();
    if (!$cust) json_response(false, '客户不存在或已在公海');

    // 业务经理只能放弃自己的客户
    if (!$isAdmin && intval($cust['owner_id']) !== $userId) json_response(false, '无权限');

    $oldOwner = $cust['owner_id'];
    $pdo->prepare("UPDATE customers SET owner_id=NULL, in_pool=1, pooled_at=NOW() WHERE id=?")->execute([$customerId]);
    $pdo->prepare("INSERT INTO customer_transfer_logs (customer_id,from_user_id,to_user_id,action,operator_id,created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$customerId, $oldOwner, null, 'to_pool', $userId]);
    add_log($userId, 'to_pool', 'crm', "归入公海: customer_id={$customerId}");
    json_response(true, '已归入公海');
}

// ========== 管理员分配 ==========
if ($action === 'assign') {
    csrf_check();
    if (get_user_role() !== 'admin') json_response(false, '无权限');
    $customerId = intval($_POST['customer_id'] ?? 0);
    $toUserId = intval($_POST['to_user_id'] ?? 0);
    if (!$customerId || !$toUserId) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch();
    if (!$cust) json_response(false, '客户不存在');

    $oldOwner = $cust['owner_id'];
    $pdo->prepare("UPDATE customers SET owner_id=?, in_pool=0, pooled_at=NULL WHERE id=?")->execute([$toUserId, $customerId]);
    $pdo->prepare("INSERT INTO customer_transfer_logs (customer_id,from_user_id,to_user_id,action,operator_id,created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$customerId, $oldOwner, $toUserId, 'assign', get_user_id()]);

    // 同步更新进行中的报价单业务员（draft/quoted 转移，withdrawn 不转）
    $stmtQ = $pdo->prepare("UPDATE sales_quotes SET employee_id=? WHERE customer_id=? AND status IN ('draft','quoted','contracted')");
    $stmtQ->execute([$toUserId, $customerId]);
    $quotesCount = $stmtQ->rowCount();

    // 同步更新该客户未终结的销售合同业务员（已完成/已终止的合同不参与转移）
    $stmtC = $pdo->prepare("UPDATE sales_contracts SET employee_id=? WHERE customer_id=? AND status IN ('draft','confirmed','executing')");
    $stmtC->execute([$toUserId, $customerId]);
    $contractsCount = $stmtC->rowCount();

    // 同步更新进行中的销售订单业务员（draft/confirmed 转移，已完结不转）
    $stmtO = $pdo->prepare("UPDATE sales_orders SET employee_id=? WHERE customer_id=? AND status IN ('draft','confirmed')");
    $stmtO->execute([$toUserId, $customerId]);
    $ordersCount = $stmtO->rowCount();

    $extra = '';
    if ($quotesCount > 0 || $ordersCount > 0) {
        $parts = [];
        if ($quotesCount > 0) $parts[] = "{$quotesCount}笔报价单";
        if ($ordersCount > 0) $parts[] = "{$ordersCount}笔订单";
        $extra = '，同步更新' . implode('、', $parts) . '业务员';
    }

    add_log(get_user_id(), 'assign', 'crm', "分配客户: customer_id={$customerId}, to_user={$toUserId}{$extra}");
    json_response(true, '分配成功' . $extra . '（跟进记录为历史数据，保留原跟进人不变）');
}

// ========== 销售经理转移客户给其他经理 ==========
if ($action === 'transfer') {
    csrf_check();
    $customerId = intval($_POST['customer_id'] ?? 0);
    $toUserId = intval($_POST['to_user_id'] ?? 0);
    $userId = get_user_id();
    $isAdmin = (get_user_role() === 'admin');
    if (!$customerId || !$toUserId) json_response(false, '参数错误');
    if ($toUserId === $userId) json_response(false, '不能转移给自己');

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=? AND in_pool=0");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch();
    if (!$cust) json_response(false, '客户不存在或已在公海');

    // 非管理员只能转移自己的客户
    if (!$isAdmin && intval($cust['owner_id']) !== $userId) json_response(false, '无权限，只能转移自己的客户');

    $oldOwner = $cust['owner_id'];
    $pdo->prepare("UPDATE customers SET owner_id=?, in_pool=0, pooled_at=NULL WHERE id=?")->execute([$toUserId, $customerId]);
    $pdo->prepare("INSERT INTO customer_transfer_logs (customer_id,from_user_id,to_user_id,action,operator_id,created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$customerId, $oldOwner, $toUserId, 'transfer', $userId]);

    // 同步更新进行中的报价单业务员（draft/quotated 转移，withdrawn 不转）
    $stmtQ = $pdo->prepare("UPDATE sales_quotes SET employee_id=? WHERE customer_id=? AND status IN ('draft','quoted','contracted')");
    $stmtQ->execute([$toUserId, $customerId]);
    $quotesCount = $stmtQ->rowCount();

    // 同步更新该客户未终结的销售合同业务员（已完成/已终止的合同不参与转移）
    $stmtC = $pdo->prepare("UPDATE sales_contracts SET employee_id=? WHERE customer_id=? AND status IN ('draft','confirmed','executing')");
    $stmtC->execute([$toUserId, $customerId]);
    $contractsCount = $stmtC->rowCount();

    // 同步更新进行中的销售订单业务员（draft/confirmed 转移，已完结不转）
    $stmtO = $pdo->prepare("UPDATE sales_orders SET employee_id=? WHERE customer_id=? AND status IN ('draft','confirmed')");
    $stmtO->execute([$toUserId, $customerId]);
    $ordersCount = $stmtO->rowCount();

    $extra = '';
    if ($quotesCount > 0 || $ordersCount > 0) {
        $parts = [];
        if ($quotesCount > 0) $parts[] = "{$quotesCount}笔报价单";
        if ($ordersCount > 0) $parts[] = "{$ordersCount}笔订单";
        $extra = '，同步更新' . implode('、', $parts) . '业务员';
    }

    add_log($userId, 'transfer', 'crm', "转移客户: customer_id={$customerId}, to_user={$toUserId}{$extra}");
    json_response(true, '转移成功' . $extra . '（跟进记录为历史数据，保留原跟进人不变）');
}

// ========== 关联已有销售订单 ==========
if ($action === 'link_order') {
    csrf_check();
    if (get_user_role() !== 'admin') json_response(false, '无权限，仅管理员可关联订单');
    $customerId = intval($_POST['customer_id'] ?? 0);
    $orderId = intval($_POST['order_id'] ?? 0);
    if (!$customerId || !$orderId) json_response(false, '参数错误');
    $pdo->prepare("UPDATE sales_orders SET customer_id=? WHERE id=?")->execute([$customerId, $orderId]);
    add_log(get_user_id(), 'link_order', 'crm', "关联订单: customer_id={$customerId}, order_id={$orderId}");
    json_response(true, '订单已关联');
}

// ========== 搜索待关联订单 ==========
if ($action === 'search_orders') {
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT id,bill_no,order_date,total_amount,status FROM sales_orders WHERE 1=1";
    $params = [];
    if ($search) { $sql .= " AND (bill_no LIKE ? OR id=?)"; $params[] = "%$search%"; $params[] = is_numeric($search)?intval($search):0; }
    $sql .= " ORDER BY id DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(true, '', $stmt->fetchAll());
}

// ========== 获取客户详情 ==========
if ($action === 'get_customer') {
    $customerId = intval($_GET['customer_id'] ?? 0);
    if (!$customerId) json_response(false, '无效客户');

    $stmt = $pdo->prepare("SELECT c.*, s.name as source_name, u.real_name as owner_name,
        (SELECT SUM(total_amount) FROM sales_orders WHERE customer_id=c.id AND status NOT IN('draft','cancelled')) as total_sales,
        (SELECT COUNT(*) FROM sales_orders WHERE customer_id=c.id) as order_count,
        (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count
        FROM customers c
        LEFT JOIN customer_sources s ON c.source_id=s.id
        LEFT JOIN users u ON c.owner_id=u.id
        WHERE c.id=?");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch();
    if (!$cust) json_response(false, '客户不存在');

    // 跟进记录
    $followups = $pdo->prepare("SELECT f.*, u.real_name as user_name FROM customer_followups f LEFT JOIN users u ON f.user_id=u.id WHERE f.customer_id=? ORDER BY f.created_at DESC LIMIT 50");
    $followups->execute([$customerId]);

    // 销售订单
    $orders = $pdo->prepare("SELECT id,bill_no,order_date,total_amount,status FROM sales_orders WHERE customer_id=? ORDER BY id DESC LIMIT 20");
    $orders->execute([$customerId]);

    // 转移记录
    $transfers = $pdo->prepare("SELECT t.*, fu.real_name as from_name, tu.real_name as to_name, o.real_name as operator_name FROM customer_transfer_logs t LEFT JOIN users fu ON t.from_user_id=fu.id LEFT JOIN users tu ON t.to_user_id=tu.id LEFT JOIN users o ON t.operator_id=o.id WHERE t.customer_id=? ORDER BY t.created_at DESC");
    $transfers->execute([$customerId]);

    json_response(true, '', [
        'customer' => $cust,
        'followups' => $followups->fetchAll(),
        'orders' => $orders->fetchAll(),
        'transfers' => $transfers->fetchAll(),
    ]);
}

// ========== 删除跟进记录 ==========
if ($action === 'delete_followup') {
    csrf_check();
    $id = intval($_POST['id'] ?? 0);
    $userId = get_user_id();
    $isAdmin = (get_user_role() === 'admin');
    $f = $pdo->prepare("SELECT * FROM customer_followups WHERE id=?");
    $f->execute([$id]);
    $fw = $f->fetch();
    if (!$fw) json_response(false, '记录不存在');
    if (!$isAdmin && intval($fw['user_id']) !== $userId) json_response(false, '无权限');
    $pdo->prepare("DELETE FROM customer_followups WHERE id=?")->execute([$id]);
    json_response(true, '已删除');
}

// ========== 批量删除客户 ==========
// 删除的是客户主数据。原先只认主数据的 customer_edit，与同页新增/编辑用的 crm_customer_edit
// 不是一套，统一为「两者有其一即可」——既对齐语义，也不会让原本有权限的人突然删不动
if ($action === 'batch_delete') {
    csrf_check();
    if (!check_permission('crm_customer_edit') && !check_permission('customer_edit')) json_response(false, '无权限：仅管理员或被授权角色可删除客户');
    $ids = $_POST['ids'] ?? '';
    if (!$ids) json_response(false, '请选择客户');
    $idArr = array_map('intval', explode(',', $ids));
    $placeholders = implode(',', array_fill(0, count($idArr), '?'));
    $pdo->prepare("DELETE FROM customers WHERE id IN ($placeholders)")->execute($idArr);
    json_response(true, '已删除');
}

// ========== 批量删除跟进 ==========
if ($action === 'batch_delete_followup') {
    csrf_check();
    $ids = $_POST['ids'] ?? '';
    if (!$ids) json_response(false, '请选择记录');
    $idArr = array_map('intval', explode(',', $ids));
    $placeholders = implode(',', array_fill(0, count($idArr), '?'));
    $pdo->prepare("DELETE FROM customer_followups WHERE id IN ($placeholders)")->execute($idArr);
    json_response(true, '已删除');
}

// ========== 附件上传 ==========
if ($action === 'upload_attachment') {
    csrf_check();
    $res = upload_file('file', CRM_ATTACHMENT_EXT, CRM_ATTACHMENT_MAX_SIZE);
    if ($res['success']) {
        json_response(true, '', [
            'path' => $res['path'],
            'filename' => $res['filename'],
            'original_name' => $res['original_name'] ?? ''
        ]);
    }
    json_response(false, $res['message']);
}

// ========== 获取单条跟进记录详情 ==========
if ($action === 'get_followup_detail') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) json_response(false, '缺少ID');
    $stmt = $pdo->prepare("SELECT f.*, c.name as customer_name, u.real_name as user_name, ue.real_name as updated_by_name
        FROM customer_followups f 
        LEFT JOIN customers c ON f.customer_id=c.id 
        LEFT JOIN users u ON f.user_id=u.id 
        LEFT JOIN users ue ON f.updated_by=ue.id 
        WHERE f.id=?");
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) json_response(false, '记录不存在');
    json_response(true, '', $f);
}

// ========== 获取业务经理列表 ==========
if ($action === 'get_users') {
    $users = $pdo->query("SELECT id, real_name as name, phone FROM users WHERE status=1 ORDER BY real_name")->fetchAll();
    json_response(true, '', $users);
}

// ========== 跟进提醒（检查当前用户今日需跟进的客户）==========
if ($action === 'check_reminders') {
    $userId = get_user_id();
    // 提醒范围：最新一条跟进的「计划下次跟进」在今天之内或已逾期（到明天 0 点之前）
    // 已成交的不再提醒。到点的（due=1）排在前面，弹窗据此变红。
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, nf.next_follow_at
        FROM customers c
        JOIN customer_followups nf ON nf.id = (
            SELECT id FROM customer_followups WHERE customer_id = c.id ORDER BY created_at DESC LIMIT 1
        )
        WHERE c.owner_id = ? AND c.in_pool = 0 AND c.status = 1
          AND nf.next_follow_at IS NOT NULL
          AND nf.next_follow_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND nf.result != '已成交'
        ORDER BY nf.next_follow_at ASC
    ");
    $stmt->execute([$userId]);
    $data = $stmt->fetchAll();
    foreach ($data as &$row) {
        $row['due'] = is_follow_due($row['next_follow_at']) ? 1 : 0;
    }
    unset($row);
    json_response(true, '', $data);
}

json_response(false, '未知操作');
