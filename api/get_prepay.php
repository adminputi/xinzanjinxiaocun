<?php
/**
 * API: 查询客户的可用预收款余额
 * GET ?customer_id=X → {"available": 12345.67}
 *
 * 预收款 = 该客户所有未核销（order_id=0）的收款核销明细之和，已作废收款单自动排除。
 * 与 get_orders.php 一样不引入 header.php，避免输出 HTML 破坏 JSON 响应。
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$available = 0.00;
$customerId = intval($_GET['customer_id'] ?? 0);
if ($customerId > 0) {
    $available = get_customer_prepay($customerId);
}

echo json_encode(['available' => round($available, 2)], JSON_UNESCAPED_UNICODE);
