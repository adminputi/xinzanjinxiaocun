<?php
/**
 * API: 获取订单列表（用于收款/付款关联单据）
 * ?type=sales&customer_id=X  → 客户订单
 * ?type=purchase&supplier_id=X → 供应商订单
 */
// API 不能引入 header.php（header.php 会输出完整 HTML 页面破坏 JSON 响应）
// 改为直接引入 auth.php（处理认证 + 提供 getDB()）
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$type = $_GET['type'] ?? '';
$customerId = intval($_GET['customer_id'] ?? 0);
$supplierId = intval($_GET['supplier_id'] ?? 0);

$orders = [];

// 自动检测 pay_status 列是否存在，避免 ALTER TABLE 失败导致查询崩溃
function column_exists($pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        try {
            $pdo->query("SELECT $column FROM $table LIMIT 0");
            $cache[$key] = true;
        } catch (Exception $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

if ($type === 'sales' && $customerId > 0) {
    $payStatusExpr = column_exists($pdo, 'sales_orders', 'pay_status')
        ? "COALESCE(pay_status,'unpaid') as pay_status"
        : "'unpaid' as pay_status";
    $stmt = $pdo->prepare("SELECT id, bill_no, customer_id, total_amount, COALESCE(received_amount,0) as received_amount, order_date, status, $payStatusExpr FROM sales_orders WHERE customer_id=? AND status NOT IN('draft','cancelled') ORDER BY id DESC");
    $stmt->execute([$customerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($type === 'purchase' && $supplierId > 0) {
    $payStatusExpr = column_exists($pdo, 'purchase_orders', 'pay_status')
        ? "COALESCE(pay_status,'unpaid') as pay_status"
        : "'unpaid' as pay_status";
    $stmt = $pdo->prepare("SELECT id, bill_no, supplier_id, total_amount, COALESCE(paid_amount,0) as paid_amount, order_date, status, $payStatusExpr FROM purchase_orders WHERE supplier_id=? AND status NOT IN('draft','cancelled') ORDER BY id DESC");
    $stmt->execute([$supplierId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 确保数值字段为数字类型
foreach ($orders as &$o) {
    $o['id'] = intval($o['id']);
    $o['total_amount'] = floatval($o['total_amount']);
    if (isset($o['received_amount'])) $o['received_amount'] = floatval($o['received_amount']);
    if (isset($o['paid_amount'])) $o['paid_amount'] = floatval($o['paid_amount']);
}
unset($o);

echo json_encode($orders, JSON_UNESCAPED_UNICODE);
