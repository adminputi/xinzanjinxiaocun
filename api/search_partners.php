<?php
/**
 * API: 客户/供应商关键字搜索（供可搜索下拉选择器使用）
 * ?type=customer|supplier&q=关键字
 * 返回 [{id, name, sub}]，最多 30 条
 */
// 与 get_orders.php 一致：不引入 header.php，避免输出 HTML 破坏 JSON
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$type = $_GET['type'] ?? 'customer';
$q = trim($_GET['q'] ?? '');
$limit = intval($_GET['limit'] ?? 30);
$limit = max(5, min(50, $limit));

if ($type === 'supplier') {
    $table = 'suppliers';
} else {
    $table = 'customers';
    $type = 'customer';
}

$sql = "SELECT id, name, code, phone FROM $table WHERE status=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (name LIKE ? OR code LIKE ? OR phone LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY name LIMIT $limit";

$rows = [];
try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sub = trim(($r['code'] ? '[' . $r['code'] . '] ' : '') . ($r['phone'] ?: ''));
        $rows[] = ['id' => intval($r['id']), 'name' => $r['name'], 'sub' => $sub];
    }
} catch (Exception $e) {
    error_log('search_partners error: ' . $e->getMessage());
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
