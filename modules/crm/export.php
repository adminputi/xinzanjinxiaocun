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
require_once __DIR__ . '/../../includes/migration.php';
// 搜索口径与列表页同一份（名称/电话/公司/意向产品/备注/地址），
// 否则会出现「列表里搜到 30 条、导出来只有 20 条」
require_once __DIR__ . '/search_hit.php';
run_migrations();
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
$flag = $_GET['flag'] ?? '';

// 与列表页同一套：置顶/标红取导出者本人的标记，搜索口径也保持一致，
// 否则列表里看到的和导出出来的不是同一批客户、同一个顺序
$flagJoin = customer_flag_join($userId);

$where = 'WHERE 1=1';
$params = [];
if ($search) {
    // 与列表页同一份口径：加字段只改 search_hit.php 一处
    $sc = crm_search_cond($search, 'c');
    $where .= " AND ({$sc['cond']})";
    $params = array_merge($params, $sc['params']);
}
if ($sourceId > 0) { $where .= " AND c.source_id=?"; $params[] = $sourceId; }
if ($ownerId > 0) { $where .= " AND c.owner_id=?"; $params[] = $ownerId; }
if ($intention) { $where .= " AND c.intention=?"; $params[] = $intention; }
if ($flag === 'pin')  { $where .= " AND IFNULL(f.is_pinned,0)=1"; }
if ($flag === 'mark') { $where .= " AND IFNULL(f.is_marked,0)=1"; }
$deal = $_GET['deal'] ?? '';
if ($deal === '1') { $where .= " AND (" . sql_customer_is_deal('c') . ")"; }
if ($deal === '0') { $where .= " AND NOT (" . sql_customer_is_deal('c') . ")"; }
$sort = $_GET['sort'] ?? '';
$baseOrder = 'c.id DESC';
if ($sort === 'deal_amount') { $baseOrder = 'deal_amount DESC'; }
elseif ($sort === 'last_deal') { $baseOrder = 'last_deal_date DESC'; }
elseif ($sort === 'last_follow') { $baseOrder = 'c.last_followed_at DESC'; }
$orderBy = customer_flag_order($baseOrder);
$showPool = intval($_GET['pool'] ?? 0);
if (!$showPool) { $where .= " AND c.in_pool=0"; }

$sql = "SELECT c.code, c.name, c.type, c.contact, c.phone, c.email, c.wechat, c.company, c.address,
    s.name as source_name, u.real_name as owner_name, c.intention, c.intended_product, c.remark,
    IFNULL(f.is_pinned,0) as is_pinned, IFNULL(f.is_marked,0) as is_marked,
    lf.created_at as last_follow_at, lf.follow_type as last_follow_type, lf.content as last_follow_content,
    (SELECT COUNT(*) FROM customer_followups WHERE customer_id=c.id) as followup_count,
    (SELECT COUNT(*) FROM sales_orders WHERE customer_id=c.id) as order_count,
    (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE customer_id=c.id AND status NOT IN('draft','cancelled')) as total_sales,
    " . sql_customer_deal_stats('c') . ",
    c.last_followed_at, c.created_at
    FROM customers c
    LEFT JOIN customer_sources s ON c.source_id=s.id
    LEFT JOIN users u ON c.owner_id=u.id
    LEFT JOIN customer_followups lf
           ON lf.id = (SELECT f3.id FROM customer_followups f3
                       WHERE f3.customer_id = c.id ORDER BY f3.created_at DESC, f3.id DESC LIMIT 1)
    $flagJoin
    $where $orderBy";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// 跟进记录同样是受控数据：导出要不要带内容，跟随列表页那套权限口径
$canExportFollowup = check_permission('crm_followup_view');

$headers = [
    '置顶', '标红',
    '客户编码', '客户名称', '客户类型', '联系人', '电话', '邮箱', '微信号', '公司名称', '地址',
    '来源', '归属经理', '意向程度', '意向产品', '备注',
    '跟进次数', '最近跟进时间', '最近跟进摘要', '订单数', '累计消费', '是否成交', '最后跟进时间', '创建时间'
];

$data = [];
foreach ($list as $row) {
    $data[] = [
        intval($row['is_pinned'] ?? 0) ? '是' : '',
        intval($row['is_marked'] ?? 0) ? '是' : '',
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
        // 与列表页同一口径：无「跟进查看」权限的不导出跟进内容
        $canExportFollowup ? ($row['last_follow_at'] ?? '') : '',
        $canExportFollowup ? ($row['last_follow_content'] ?? '') : '',
        $row['order_count'],
        $row['total_sales'],
        customer_is_deal($row) ? '是' : '否',
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
