<?php
/**
 * 客户资料导出（独立入口，必须在输出任何 HTML 之前生成文件流）
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_permission('customer_view');

$pdo = getDB();
$search = $_GET['search'] ?? '';

$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE name LIKE ? OR phone LIKE ? OR contact LIKE ?";
    $params = array_fill(0, 3, "%$search%");
}

$st = $pdo->prepare("SELECT * FROM customers {$where} ORDER BY id DESC");
$st->execute($params);
$rows = array_map(function ($r) {
    return [
        $r['code'] ?? '',
        $r['name'] ?? '',
        ($r['type'] ?? '') === 'individual' ? '个人' : '企业',
        $r['contact'] ?? '',
        $r['phone'] ?? '',
        $r['email'] ?? '',
        $r['address'] ?? '',
        $r['initial_balance'] ?? 0,
        $r['remark'] ?? '',
    ];
}, $st->fetchAll());

require_once __DIR__ . '/../../includes/xlsx_helper.php';
xlsx_export(
    ['编码', '客户名称', '类型', '联系人', '电话', '邮箱', '地址', '期初应收', '备注'],
    $rows,
    'customers_' . date('Ymd') . '.xlsx'
);
