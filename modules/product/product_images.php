<?php
/**
 * 商品图片上传处理（纯JSON接口，不输出HTML）
 */
require_once __DIR__ . '/../../includes/auth.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'未登录']); exit; }

$pdo = getDB();

// 确保图片表存在
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `product_images` (`id` INT AUTO_INCREMENT PRIMARY KEY, `product_id` INT NOT NULL, `image_url` VARCHAR(500) NOT NULL, `sort_order` INT DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX `idx_product` (`product_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// 上传图片
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && isset($_POST['product_id'])) {
    upload_verify();
    $productId = intval($_POST['product_id']);
    $uploadDir = __DIR__ . '/../../uploads/products/' . $productId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $response = ['success' => false, 'message' => '', 'images' => []];

    try {
        foreach ($_FILES['image']['tmp_name'] as $i => $tmpName) {
            if ($_FILES['image']['error'][$i] !== UPLOAD_ERR_OK) continue;

            // 文件大小限制 5MB
            if ($_FILES['image']['size'][$i] > 5 * 1024 * 1024) continue;

            $ext = strtolower(pathinfo($_FILES['image']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;

            // 验证文件 MIME 类型和 Magic Bytes，防止伪装文件上传
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mime, $allowedMimes)) continue;

            // 二次确认：验证 Magic Bytes
            $header = file_get_contents($tmpName, false, null, 0, 8);
            $validMagic = false;
            if (substr($header, 0, 3) === "\xFF\xD8\xFF") $validMagic = true;       // JPEG
            elseif (substr($header, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") $validMagic = true; // PNG
            elseif (substr($header, 0, 4) === 'GIF8') $validMagic = true;           // GIF
            elseif (substr($header, 0, 4) === 'RIFF') $validMagic = true;           // WEBP
            if (!$validMagic) continue;

            $newName = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $uploadDir . $newName;

            if (move_uploaded_file($tmpName, $dest)) {
                $url = 'uploads/products/' . $productId . '/' . $newName;
                // 如果是第一张图片，同时更新products主表的image字段
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id=?");
                $stmt->execute([$productId]);
                $existing = $stmt->fetchColumn();
                if ($existing == 0) {
                    $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$url, $productId]);
                }
                $pdo->prepare("INSERT INTO product_images (product_id, image_url, created_at) VALUES (?,?,?)")
                    ->execute([$productId, $url, date('Y-m-d H:i:s')]);
                $imgId = $pdo->lastInsertId();
                $response['images'][] = ['id' => $imgId, 'url' => $url];
            }
        }

        if (empty($response['images'])) {
            $response['message'] = '未选择有效图片（支持 jpg/png/gif/webp）';
        } else {
            $response['success'] = true;
            $response['message'] = '成功上传 ' . count($response['images']) . ' 张图片';
        }
        $response['upload_token'] = upload_token();
    } catch (Exception $e) {
        error_log('Product image upload error: '.$e->getMessage());
        $response['message'] = '上传失败，请稍后重试';
    }

    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 删除图片
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    upload_verify();
    $imgId = intval($_POST['img_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id=?");
    $stmt->execute([$imgId]);
    $img = $stmt->fetch();
    if ($img) {
        $filePath = __DIR__ . '/../../' . $img['image_url'];
        if (file_exists($filePath)) @unlink($filePath);
        $pdo->prepare("DELETE FROM product_images WHERE id=?")->execute([$imgId]);
        // 如果删除的是主图，改为第一张剩余图片
        $stmt = $pdo->prepare("SELECT image_url FROM product_images WHERE product_id=? ORDER BY id LIMIT 1");
        $stmt->execute([$img['product_id']]);
        $remaining = $stmt->fetchColumn();
        $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$remaining ?: '', $img['product_id']]);
    }
    echo json_encode(['success' => true, 'upload_token' => upload_token()]);
    exit;
}

// 获取图片列表
if (isset($_GET['action']) && $_GET['action'] === 'list' && isset($_GET['product_id'])) {
    $productId = intval($_GET['product_id']);
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id=? ORDER BY sort_order, id");
    $stmt->execute([$productId]);
    $images = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($images, JSON_UNESCAPED_UNICODE);
    exit;
}
