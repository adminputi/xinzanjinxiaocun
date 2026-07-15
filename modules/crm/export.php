<?php
/**
 * CRM Excel导出
 * 导出客户资料（含所有字段）为CSV（兼容Excel UTF-8 BOM）
 * 不加载header.php，避免HTML输出导致headers already sent
 */
ob_start();

require_once __DIR__ . '/../../config/database.php';
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

// 登录检查
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
$pdo = getDB();

// CRM权限检查
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$licenseFile = __DIR__ . '/../../includes/license.php';
if (file_exists($licenseFile)) { require_once $licenseFile; }
if (function_exists('license_has_feature')) {
    if (!license_has_feature('crm')) {
        die('该功能需要购买正式授权');
    }
}

$isAdmin = (get_user_role() === 'admin');
$userId = get_user_id();

// 权限检查：非管理员只能导出自己的客户
$search = $_GET['search'] ?? '';
$sourceId = intval($_GET['source_id'] ?? 0);
$ownerId = $isAdmin ? intval($_GET['owner_id'] ?? 0) : $userId;
$intention = $_GET['intention'] ?? '';

$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.company LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($sourceId > 0) { $where .= " AND c.source_id=?"; $params[] = $sourceId; }
if ($ownerId > 0) { $where .= " AND c.owner_id=?"; $params[] = $ownerId; }
if ($intention) { $where .= " AND c.intention=?"; $params[] = $intention; }
$showPool = intval($_GET['pool'] ?? 0);
if (!$showPool) { $where .= " AND c.in_pool=0"; }

$sql = "SELECT c.code, c.name, c.type, c.contact, c.phone, c.email, c.wechat, c.company, c.address,
    s.name as source_name, u.real_name as owner_name, c.intention, c.intended_product, c.remark,
    (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count,
    (SELECT COUNT(*) FROM sales_orders WHERE customer_id=c.id) as order_count,
    (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE customer_id=c.id AND status NOT IN('draft','cancelled')) as total_sales,
    c.last_followed_at, c.created_at
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    LEFT JOIN users u ON c.owner_id=u.id
    $where ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$headers = [
    '客户编码', '客户名称', '客户类型', '联系人', '电话', '邮箱', '微信号', '公司名称', '地址',
    '来源', '归属经理', '意向程度', '意向产品', '备注',
    '跟进次数', '订单数', '累计消费', '最后跟进时间', '创建时间'
];

$data = [];
foreach ($list as $row) {
    $data[] = [
        $row['code'],
        $row['name'],
        $row['type'] === 'company' ? '企业' : '个人',
        $row['contact'],
        $row['phone'],
        $row['email'],
        $row['wechat'],
        $row['company'],
        $row['address'],
        $row['source_name'],
        $row['owner_name'],
        $row['intention'],
        $row['intended_product'],
        $row['remark'],
        $row['followup_count'],
        $row['order_count'],
        $row['total_sales'],
        $row['last_followed_at'],
        $row['created_at'],
    ];
}

// 清除所有输出缓冲，确保header可发送
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="客户资料_' . date('YmdHis') . '.csv"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

// BOM for Excel UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($data as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
