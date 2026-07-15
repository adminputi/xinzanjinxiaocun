<?php
/**
 * 售后追踪 AJAX 接口
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../includes/functions.php';
$pdo = getDB();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!$action) json_response(false, '缺少参数');

// ========== 无需登录的接口 ==========
if ($action === 'verify_password') {
    $code = trim($_GET['code'] ?? $_POST['code'] ?? '');
    $pwd = trim($_POST['pwd'] ?? '');
    if (!$code) json_response(false, '参数错误');

    // 频率限制
    $rateKey = 'pwd_attempts_' . md5($code . get_client_ip());
    $attempts = $_SESSION[$rateKey] ?? ['count' => 0, 'time' => 0];
    if ($attempts['count'] >= 5) {
        if (time() - $attempts['time'] < 900) {
            json_response(false, '尝试次数过多，请15分钟后再试');
        }
        unset($_SESSION[$rateKey]);
        $attempts = ['count' => 0, 'time' => 0];
    }

    $stmt = $pdo->prepare("SELECT id,password FROM tracking_codes WHERE tracking_no=?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, '追踪码不存在');
    if (!password_verify($pwd, $row['password'])) {
        $attempts['count']++;
        $attempts['time'] = time();
        $_SESSION[$rateKey] = $attempts;
        json_response(false, '密码错误');
    }
    unset($_SESSION[$rateKey]);
    json_response(true, '验证通过');
}

if ($action === 'get_tracking_public') {
    $code = trim($_GET['code'] ?? '');
    if (!$code) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT * FROM tracking_codes WHERE tracking_no=?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, '追踪码不存在');

    $row['tracking_data'] = !empty($row['tracking_data']) ? json_decode($row['tracking_data'], true) : [];

    // 流程记录
    $ps = $pdo->prepare("SELECT p.*, s.name as status_name FROM tracking_processes p LEFT JOIN tracking_statuses s ON p.status_id=s.id WHERE p.tracking_id=? ORDER BY p.created_at DESC");
    $ps->execute([$row['id']]);
    $processes = $ps->fetchAll();
    foreach ($processes as &$pp) { $pp['images'] = !empty($pp['images']) ? json_decode($pp['images'], true) : []; }
    $row['processes'] = $processes;

    // 售后记录
    $as = $pdo->prepare("SELECT * FROM tracking_after_sales WHERE tracking_id=? ORDER BY created_at DESC");
    $as->execute([$row['id']]);
    $afterSales = $as->fetchAll();
    foreach ($afterSales as &$aa) { $aa['images'] = !empty($aa['images']) ? json_decode($aa['images'], true) : []; }
    $row['after_sales'] = $afterSales;

    // 状态名称
    $ss = $pdo->prepare("SELECT name FROM tracking_statuses WHERE id=?");
    $ss->execute([$row['status_id']]);
    $row['status_name'] = $ss->fetchColumn() ?: '';

    // 售后负责人
    if ($row['after_sales_employee_id'] > 0) {
        $es = $pdo->prepare("SELECT real_name as name,phone FROM users WHERE id=?");
        $es->execute([$row['after_sales_employee_id']]);
        $row['after_sales_person'] = $es->fetch();
    } else {
        $row['after_sales_person'] = null;
    }

    // 追踪产品
    $its = $pdo->prepare("SELECT * FROM tracking_code_items WHERE tracking_id=?");
    $its->execute([$row['id']]);
    $row['items'] = $its->fetchAll();

    json_response(true, '', $row);
}

// ========== 以下需要登录 ==========
if (!isset($_SESSION['user_id'])) json_response(false, '请先登录');

// ---------- 出库单搜索 ----------
if ($action === 'search_outstocks') {
    $keyword = trim($_GET['keyword'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');

    $sql = "SELECT o.id, o.bill_no, o.outstock_date, o.total_amount,
                   c.name as customer_name, c.phone as customer_phone,
                   o.receiver_name, o.salesperson_name,
                   (SELECT COUNT(*) FROM tracking_codes tc WHERE tc.order_id = o.order_id AND tc.outstock_id = o.id) as has_tracking
            FROM sales_outstocks o
            LEFT JOIN customers c ON o.customer_id=c.id
            WHERE o.status = 'confirmed'";
    $params = [];

    if ($keyword) {
        $sql .= " AND (o.bill_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR o.receiver_name LIKE ?)";
        $kw = "%$keyword%";
        $params = [$kw, $kw, $kw, $kw];
    }
    if ($dateFrom) { $sql .= " AND o.outstock_date >= ?"; $params[] = $dateFrom; }
    if ($dateTo)   { $sql .= " AND o.outstock_date <= ?"; $params[] = $dateTo; }
    $sql .= " ORDER BY o.id DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    foreach ($list as &$item) { $item['has_tracking'] = intval($item['has_tracking']); }
    json_response(true, '', $list);
}

if ($action === 'get_outstock_info') {
    $outstockId = intval($_GET['outstock_id'] ?? 0);
    if (!$outstockId) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, w.name as warehouse_name FROM sales_outstocks o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN warehouses w ON o.warehouse_id=w.id WHERE o.id=?");
    $stmt->execute([$outstockId]);
    $outstock = $stmt->fetch();
    if (!$outstock) json_response(false, '出库单不存在');

    $stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_outstock_items oi LEFT JOIN products p ON oi.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE oi.outstock_id=?");
    $stmt->execute([$outstockId]);
    $outstock['items'] = $stmt->fetchAll();

    if ($outstock['order_id'] > 0) {
        $stmt = $pdo->prepare("SELECT bill_no, order_date, total_amount FROM sales_orders WHERE id=?");
        $stmt->execute([$outstock['order_id']]);
        $outstock['order'] = $stmt->fetch();
    } else {
        $outstock['order'] = null;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tracking_codes WHERE order_id = ? AND outstock_id = ?");
    $stmt->execute([$outstock['order_id'], $outstock['id']]);
    $outstock['has_tracking'] = intval($stmt->fetchColumn());

    json_response(true, '', $outstock);
}

// ---------- 状态管理 ----------
if ($action === 'list_statuses') {
    $list = $pdo->query("SELECT * FROM tracking_statuses ORDER BY sort_order ASC")->fetchAll();
    json_response(true, '', $list);
}

if ($action === 'save_status') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    if (!$name) json_response(false, '状态名称不能为空');

    if ($id > 0) {
        $pdo->prepare("UPDATE tracking_statuses SET name=?, sort_order=? WHERE id=?")->execute([$name, $sortOrder, $id]);
    } else {
        $pdo->prepare("INSERT INTO tracking_statuses (name, sort_order) VALUES (?,?)")->execute([$name, $sortOrder]);
    }
    json_response(true, '保存成功');
}

if ($action === 'delete_status') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM tracking_statuses WHERE id=?")->execute([$id]);
        json_response(true, '删除成功');
    }
    json_response(false, '参数错误');
}

// ---------- 追踪码 CRUD ----------
if ($action === 'save_tracking') {
    csrf_verify();
    $outstockId = intval($_POST['outstock_id'] ?? 0);
    $orderId = intval($_POST['order_id'] ?? 0);
    if (!$outstockId) json_response(false, '请选择出库单');

    $productsType = $_POST['products_type'] ?? 'all';
    $password = trim($_POST['password'] ?? '');
    if (!$password) $password = '888888';
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $statusId = intval($_POST['status_id'] ?? 0);
    $afterSalesEmpId = intval($_POST['after_sales_employee_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    $check = $pdo->prepare("SELECT COUNT(*) FROM tracking_codes WHERE order_id=? AND outstock_id=?");
    $check->execute([$orderId, $outstockId]);
    if ($check->fetchColumn() > 0) json_response(false, '该出库单已生成追踪码');

    $trackingData = [];
    $rawData = $_POST['display_fields'] ?? '';
    if ($rawData && is_string($rawData)) {
        $decoded = json_decode($rawData, true);
        if (is_array($decoded)) $trackingData = $decoded;
    }

    $trackingNo = generate_bill_no('ZS');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO tracking_codes (tracking_no,order_id,outstock_id,password,products_type,tracking_data,status_id,after_sales_employee_id,remark,user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$trackingNo, $orderId, $outstockId, $hashedPassword, $productsType, json_encode($trackingData, JSON_UNESCAPED_UNICODE), $statusId, $afterSalesEmpId, $remark, get_user_id()]);
        $trackingId = $pdo->lastInsertId();

        $itemIds = $_POST['item_ids'] ?? [];
        if ($productsType === 'selected' && !empty($itemIds)) {
            if (!is_array($itemIds) && is_string($itemIds)) $itemIds = json_decode($itemIds, true);
            $itemStmt = $pdo->prepare("INSERT INTO tracking_code_items (tracking_id,order_item_id,product_id,product_name,sku,spec,unit_name,quantity,price,amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $infoStmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_outstock_items oi LEFT JOIN products p ON oi.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE oi.id=?");
            foreach ($itemIds as $itemId) {
                $infoStmt->execute([intval($itemId)]);
                $item = $infoStmt->fetch();
                if ($item) {
                    $itemStmt->execute([$trackingId, $item['id'], $item['product_id'], $item['product_name'], $item['sku'], $item['spec'], $item['unit_name'], $item['quantity'], $item['price'], $item['amount']]);
                }
            }
        } else {
            $itemStmt = $pdo->prepare("INSERT INTO tracking_code_items (tracking_id,order_item_id,product_id,product_name,sku,spec,unit_name,quantity,price,amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $allItems = $pdo->prepare("SELECT oi.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_outstock_items oi LEFT JOIN products p ON oi.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE oi.outstock_id=?");
            $allItems->execute([$outstockId]);
            foreach ($allItems->fetchAll() as $item) {
                $itemStmt->execute([$trackingId, $item['id'], $item['product_id'], $item['product_name'], $item['sku'], $item['spec'], $item['unit_name'], $item['quantity'], $item['price'], $item['amount']]);
            }
        }

        $pdo->commit();
        add_log(get_user_id(), 'create', 'tracking', "生成追踪码: $trackingNo");
        json_response(true, '生成成功', ['tracking_no' => $trackingNo, 'id' => $trackingId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response(false, '保存失败');
    }
}

if ($action === 'update_tracking') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');
    $password = trim($_POST['password'] ?? '');
    $statusId = intval($_POST['status_id'] ?? 0);
    $afterSalesEmpId = intval($_POST['after_sales_employee_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    $trackingData = [];
    $rawData = $_POST['display_fields'] ?? '';
    if ($rawData && is_string($rawData)) {
        $decoded = json_decode($rawData, true);
        if (is_array($decoded)) $trackingData = $decoded;
    }

    $pdo->beginTransaction();
    try {
        if (!empty($password)) {
            $pdo->prepare("UPDATE tracking_codes SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        $pdo->prepare("UPDATE tracking_codes SET tracking_data=?, status_id=?, after_sales_employee_id=?, remark=?, updated_at=NOW() WHERE id=?")
            ->execute([json_encode($trackingData, JSON_UNESCAPED_UNICODE), $statusId, $afterSalesEmpId, $remark, $id]);
        $pdo->commit();
        add_log(get_user_id(), 'update', 'tracking', "编辑追踪码 ID:$id");
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response(false, '更新失败');
    }
    json_response(true, '更新成功');
}

if ($action === 'generate_qrcode') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT tracking_no FROM tracking_codes WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, '追踪码不存在');

    $qrcodePath = generate_tracking_qrcode($row['tracking_no']);
    if ($qrcodePath) {
        $pdo->prepare("UPDATE tracking_codes SET qrcode_path=? WHERE id=?")->execute([$qrcodePath, $id]);
        json_response(true, '生成成功', ['qrcode_path' => $qrcodePath]);
    }
    json_response(false, '二维码生成失败');
}

if ($action === 'save_qrcode_image') {
    $id = intval($_GET['id'] ?? 0);
    $qrUrl = $_GET['qr_url'] ?? '';
    if (!$id || !$qrUrl) json_response(false, '参数错误');

    $allowedHosts = ['api.qrserver.com', 'quickchart.io'];
    $parsedUrl = parse_url($qrUrl);
    if (!$parsedUrl || empty($parsedUrl['host'])) json_response(false, 'URL格式无效');
    $host = strtolower($parsedUrl['host']);
    $allowed = false;
    foreach ($allowedHosts as $ah) {
        if ($host === $ah || (strlen($host) > strlen($ah) && substr($host, -(strlen($ah) + 1)) === '.' . $ah)) {
            $allowed = true; break;
        }
    }
    if (!$allowed) json_response(false, '不允许的URL来源');

    $stmt = $pdo->prepare("SELECT tracking_no FROM tracking_codes WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, '追踪码不存在');

    $filename = 'qrcode_' . $row['tracking_no'] . '.png';
    $dir = __DIR__ . '/../../uploads/qrcodes/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $savePath = $dir . $filename;

    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0'],
    ]);
    $imageData = @file_get_contents($qrUrl, false, $ctx);
    if ($imageData && strlen($imageData) > 100 && file_put_contents($savePath, $imageData)) {
        $qrcodePath = 'uploads/qrcodes/' . $filename;
        $pdo->prepare("UPDATE tracking_codes SET qrcode_path=? WHERE id=?")->execute([$qrcodePath, $id]);
        json_response(true, '保存成功', ['qrcode_path' => $qrcodePath]);
    }
    json_response(false, '保存失败');
}

if ($action === 'get_tracking') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT tc.id,tc.tracking_no,tc.order_id,tc.outstock_id,tc.products_type,tc.tracking_data,tc.status_id,tc.after_sales_employee_id,tc.remark,tc.qrcode_path,tc.user_id,tc.created_at,tc.updated_at, s.name as status_name, u.real_name as after_sales_name, u.phone as after_sales_phone FROM tracking_codes tc LEFT JOIN tracking_statuses s ON tc.status_id=s.id LEFT JOIN users u ON tc.after_sales_employee_id=u.id WHERE tc.id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, '追踪码不存在');

    $row['tracking_data'] = !empty($row['tracking_data']) ? json_decode($row['tracking_data'], true) : [];
    if (is_string($row['tracking_data'])) {
        $again = json_decode($row['tracking_data'], true);
        $row['tracking_data'] = is_array($again) ? $again : [];
    }

    // 出库单信息
    $os = $pdo->prepare("SELECT o.bill_no, o.outstock_date, o.receiver_name, o.receiver_phone, o.salesperson_name, o.salesperson_phone, o.remark as outstock_remark, c.name as customer_name, c.phone as customer_phone, w.name as warehouse_name FROM sales_outstocks o LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN warehouses w ON o.warehouse_id=w.id WHERE o.id=?");
    $os->execute([$row['outstock_id']]);
    $row['outstock'] = $os->fetch();

    // 订单号
    if ($row['order_id'] > 0) {
        $so = $pdo->prepare("SELECT bill_no FROM sales_orders WHERE id=?");
        $so->execute([$row['order_id']]);
        $row['order_bill_no'] = $so->fetchColumn() ?: '-';
    } else {
        $row['order_bill_no'] = '-';
    }

    // 流程记录
    $ps = $pdo->prepare("SELECT p.*, s.name as status_name FROM tracking_processes p LEFT JOIN tracking_statuses s ON p.status_id=s.id WHERE p.tracking_id=? ORDER BY p.created_at DESC");
    $ps->execute([$id]);
    $processes = $ps->fetchAll();
    foreach ($processes as &$pp) { $pp['images'] = !empty($pp['images']) ? json_decode($pp['images'], true) : []; }
    $row['processes'] = $processes;

    // 售后记录
    $as = $pdo->prepare("SELECT * FROM tracking_after_sales WHERE tracking_id=? ORDER BY created_at DESC");
    $as->execute([$id]);
    $afterSales = $as->fetchAll();
    foreach ($afterSales as &$aa) { $aa['images'] = !empty($aa['images']) ? json_decode($aa['images'], true) : []; }
    $row['after_sales'] = $afterSales;

    // 产品项
    $its = $pdo->prepare("SELECT * FROM tracking_code_items WHERE tracking_id=?");
    $its->execute([$id]);
    $row['items'] = $its->fetchAll();

    json_response(true, '', $row);
}

if ($action === 'delete_tracking') {
    csrf_verify();
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT tracking_no, qrcode_path FROM tracking_codes WHERE id=?");
    $stmt->execute([$id]);
    $info = $stmt->fetch();
    if (!$info) json_response(false, '追踪码不存在');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tracking_code_items WHERE tracking_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM tracking_processes WHERE tracking_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM tracking_after_sales WHERE tracking_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM tracking_codes WHERE id=?")->execute([$id]);
        $pdo->commit();

        if ($info['qrcode_path'] && file_exists(__DIR__ . '/../../' . $info['qrcode_path'])) {
            @unlink(__DIR__ . '/../../' . $info['qrcode_path']);
        }
        add_log(get_user_id(), 'delete', 'tracking', "删除追踪码: " . $info['tracking_no']);
        json_response(true, '删除成功');
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response(false, '删除失败');
    }
}

// ---------- 流程 & 售后 ----------
if ($action === 'add_process') {
    csrf_verify();
    $trackingId = intval($_POST['tracking_id'] ?? 0);
    if (!$trackingId) json_response(false, '参数错误');
    $statusId = intval($_POST['status_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $images = $_POST['images'] ?? '[]';

    $pdo->prepare("UPDATE tracking_processes SET is_newest=0 WHERE tracking_id=? AND is_newest=1")->execute([$trackingId]);
    $pdo->prepare("INSERT INTO tracking_processes (tracking_id,status_id,content,images,is_newest,user_id,created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute([$trackingId, $statusId, $content, $images, get_user_id()]);

    if ($statusId > 0) {
        $pdo->prepare("UPDATE tracking_codes SET status_id=? WHERE id=?")->execute([$statusId, $trackingId]);
    }
    add_log(get_user_id(), 'create', 'tracking', "添加追踪流程");
    json_response(true, '添加成功');
}

if ($action === 'add_after_sales') {
    csrf_verify();
    $trackingId = intval($_POST['tracking_id'] ?? 0);
    if (!$trackingId) json_response(false, '参数错误');
    $content = trim($_POST['content'] ?? '');
    $images = $_POST['images'] ?? '[]';

    $pdo->prepare("INSERT INTO tracking_after_sales (tracking_id,content,images,user_id,created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$trackingId, $content, $images, get_user_id()]);
    add_log(get_user_id(), 'create', 'tracking', "添加售后记录");
    json_response(true, '添加成功');
}

// ---------- 编辑/删除流程 & 售后（仅管理员） ----------
if (in_array($action, ['edit_process','delete_process','edit_after_sales','delete_after_sales'])) {
    if ($_SESSION['user_role'] !== 'admin') json_response(false, '仅管理员可操作');
    // csrf_verify 失败会 die(HTML)，对 AJAX 不友好，自己校验返回 JSON
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            json_response(false, '安全验证失败，请刷新页面后重试');
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

if ($action === 'edit_process') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');
    $content = trim($_POST['content'] ?? '');
    $images = $_POST['images'] ?? '';
    $statusId = intval($_POST['status_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM tracking_processes WHERE id=?");
    $stmt->execute([$id]);
    $proc = $stmt->fetch();
    if (!$proc) json_response(false, '流程记录不存在');

    $updateFields = ['content = ?'];
    $updateParams = [$content];
    // 校验 images 路径：只允许合法的相对路径格式
    if ($images !== '') {
        $decoded = json_decode($images, true);
        if (!is_array($decoded)) json_response(false, '图片格式错误');
        foreach ($decoded as $img) {
            if (!is_string($img) || preg_match('/\.\./', $img) || !preg_match('#^uploads/#', $img)) {
                json_response(false, '图片路径不合法');
            }
        }
        $updateFields[] = 'images = ?';
        $updateParams[] = $images;
    }
    if ($statusId > 0) {
        // 验证状态是否存在
        $stCheck = $pdo->prepare("SELECT id FROM tracking_statuses WHERE id=?");
        $stCheck->execute([$statusId]);
        if (!$stCheck->fetch()) json_response(false, '无效的状态');
        $updateFields[] = 'status_id = ?';
        $updateParams[] = $statusId;
        // 同步更新追踪码状态
        $pdo->prepare("UPDATE tracking_codes SET status_id=? WHERE id=?")->execute([$statusId, $proc['tracking_id']]);
    }
    $updateParams[] = $id;
    $pdo->prepare("UPDATE tracking_processes SET ".implode(', ',$updateFields)." WHERE id=?")->execute($updateParams);
    add_log(get_user_id(), 'update', 'tracking', "编辑流程记录 ID:$id");
    json_response(true, '更新成功');
}

if ($action === 'delete_process') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT * FROM tracking_processes WHERE id=?");
    $stmt->execute([$id]);
    $proc = $stmt->fetch();
    if (!$proc) json_response(false, '流程记录不存在');

    $pdo->prepare("DELETE FROM tracking_processes WHERE id=?")->execute([$id]);
    // 如果删除的是最新记录，重新标记最新的
    if ($proc['is_newest'] == 1) {
        $latest = $pdo->prepare("SELECT id FROM tracking_processes WHERE tracking_id=? ORDER BY created_at DESC LIMIT 1");
        $latest->execute([$proc['tracking_id']]);
        $newLatest = $latest->fetch();
        if ($newLatest) {
            $pdo->prepare("UPDATE tracking_processes SET is_newest=1 WHERE id=?")->execute([$newLatest['id']]);
        }
    }
    add_log(get_user_id(), 'delete', 'tracking', "删除流程记录 ID:$id");
    json_response(true, '删除成功');
}

if ($action === 'edit_after_sales') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');
    $content = trim($_POST['content'] ?? '');
    $images = $_POST['images'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM tracking_after_sales WHERE id=?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_response(false, '售后记录不存在');

    $updateFields = ['content = ?'];
    $updateParams = [$content];
    if ($images !== '') {
        $decoded = json_decode($images, true);
        if (!is_array($decoded)) json_response(false, '图片格式错误');
        foreach ($decoded as $img) {
            if (!is_string($img) || preg_match('/\.\./', $img) || !preg_match('#^uploads/#', $img)) {
                json_response(false, '图片路径不合法');
            }
        }
        $updateFields[] = 'images = ?';
        $updateParams[] = $images;
    }
    $updateParams[] = $id;
    $pdo->prepare("UPDATE tracking_after_sales SET ".implode(', ',$updateFields)." WHERE id=?")->execute($updateParams);
    add_log(get_user_id(), 'update', 'tracking', "编辑售后记录 ID:$id");
    json_response(true, '更新成功');
}

if ($action === 'delete_after_sales') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) json_response(false, '参数错误');

    $stmt = $pdo->prepare("SELECT * FROM tracking_after_sales WHERE id=?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_response(false, '售后记录不存在');

    $pdo->prepare("DELETE FROM tracking_after_sales WHERE id=?")->execute([$id]);
    add_log(get_user_id(), 'delete', 'tracking', "删除售后记录 ID:$id");
    json_response(true, '删除成功');
}

// ---------- 图片上传 ----------
if ($action === 'upload_image') {
    $token = $_POST['_upload_token'] ?? '';
    if (empty($_SESSION['upload_token']) || empty($token) || !hash_equals($_SESSION['upload_token'], $token)) {
        json_response(false, '安全验证失败');
    }
    $result = upload_file('file', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        json_response(true, '', ['path' => $result['path']]);
    }
    json_response(false, $result['message']);
}

json_response(false, '未知操作');
