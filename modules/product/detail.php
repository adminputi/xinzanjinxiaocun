<?php
/**
 * 商品详情 AJAX 接口
 */
// 直接引入 functions.php（而非 auth.php），避免 session 过期时 auth.php 输出 HTML 重定向
// 本文件是 AJAX 接口，必须始终返回 JSON
require_once __DIR__ . '/../../includes/functions.php';
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '登录已过期，请重新登录'], JSON_UNESCAPED_UNICODE);
    exit;
}
// AJAX 接口：已登录即可查看商品基本信息（库存等模块均需调用）
// 不限制 product_view 权限，因为查看商品详情是基础功能

$productId = intval($_GET['id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();

// 商品基本信息 + 分类 + 单位 + 库存
$stmt = $pdo->prepare("
    SELECT p.*, c.name as category_name, u.name as unit_name,
        COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id=p.id), 0) as stock_qty,
        COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id=p.id), 0) * p.purchase_price as stock_value
    FROM products p
    LEFT JOIN product_categories c ON p.category_id=c.id
    LEFT JOIN units u ON p.unit_id=u.id
    WHERE p.id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['success' => false, 'message' => '商品不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 商品图片
$stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id=? ORDER BY sort_order, id");
$stmt->execute([$productId]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 最近出入库记录（从 inventory_logs 表获取，该表有 type/remark 等字段）
$stmt = $pdo->prepare("
    SELECT il.*, 
        CASE il.type 
            WHEN 'in' THEN '入库' 
            WHEN 'out' THEN '出库' 
            WHEN 'transfer_in' THEN '调拨入库' 
            WHEN 'transfer_out' THEN '调拨出库' 
            WHEN 'check' THEN '盘点' 
            WHEN 'loss' THEN '报损报溢' 
            ELSE il.type 
        END as type_name
    FROM inventory_logs il 
    WHERE il.product_id = ? 
    ORDER BY il.id DESC 
    LIMIT 10
");
$stmt->execute([$productId]);
$recentInventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'product' => $product,
    'images' => $images,
    'recent_inventory' => $recentInventory,
], JSON_UNESCAPED_UNICODE);
