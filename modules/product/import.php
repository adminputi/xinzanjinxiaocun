<?php
/**
 * 数据导入 — 支持商品、客户、供应商批量导入
 */
// 先做认证，但不输出HTML（为了模板下载能正确输出headers）
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/xlsx_helper.php';

$pdo = getDB();
$type = $_GET['type'] ?? 'product';
$types = [
    'product'  => ['name' => '商品',  'table' => 'products',  'icon' => 'box'],
    'customer' => ['name' => '客户',  'table' => 'customers', 'icon' => 'users'],
    'supplier' => ['name' => '供应商', 'table' => 'suppliers', 'icon' => 'truck'],
];

if (!isset($types[$type])) $type = 'product';

// 下载模板 —— 在输出HTML之前处理（需要权限检查）
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    // 确保用户有主数据权限
    if (!function_exists('check_permission') || !check_permission('master_data')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => '无权限下载模板'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($type === 'product') {
        xlsx_export(
            ['SKU编码*', '商品名称*', '分类', '单位', '规格', '条码', '采购价', '销售价', '最低库存', '最高库存', '备注'],
            [['SP0001', '示例商品A', '', '个', '500ml', '', '10.00', '15.00', '0', '0', '']],
            $type . '_template.xlsx'
        );
    } elseif ($type === 'customer') {
        xlsx_export(
            ['客户编码', '客户名称*', '类型(company/individual)', '联系人', '电话', '邮箱', '地址', '期初应收', '备注'],
            [['KH0001', '示例客户', 'company', '张三', '13800000000', 'a@b.com', '', '0', '']],
            $type . '_template.xlsx'
        );
    } elseif ($type === 'supplier') {
        xlsx_export(
            ['供应商编码', '供应商名称*', '联系人', '电话', '邮箱', '地址', '开户行', '银行账号', '税号', '备注'],
            [['GYS0001', '示例供应商', '李四', '13900000000', '', '', '', '', '', '']],
            $type . '_template.xlsx'
        );
    }
}

// 正常页面渲染
require_once __DIR__ . '/../../includes/header.php';
require_permission('master_data');
$success = '';
$error = '';

// 处理上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    csrf_verify();
    try {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('文件上传失败，错误代码：' . $_FILES['file']['error']);
        }

        // 服务端校验：仅允许 .xlsx
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new Exception('仅支持 .xlsx 格式的Excel文件');
        }
        // 校验 MIME
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        $allowedMimes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes)) {
            throw new Exception('文件类型不合法，请上传正确的Excel文件');
        }
        // 文件大小限制 10MB
        if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            throw new Exception('文件大小不能超过10MB');
        }

        $data = xlsx_import($_FILES['file']['tmp_name']);
        if (empty($data['headers']) || empty($data['rows'])) {
            throw new Exception('文件中没有找到有效数据');
        }

        $pdo->beginTransaction();
        $imported = 0;
        $skipped = 0;

        if ($type === 'product') {
            $cats = get_options('product_categories', 'name', 'id');
            $units = get_options('units', 'name', 'id');
            foreach ($data['rows'] as $row) {
                $sku = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                if (empty($sku) || empty($name)) { $skipped++; continue; }

                $catId = !empty($row[2]) ? ($cats[trim($row[2])] ?? 0) : 0;
                $unitId = !empty($row[3]) ? ($units[trim($row[3])] ?? 0) : 0;

                // 检查SKU重复
                $exist = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $exist->execute([$sku]);
                if ($exist->fetch()) { $skipped++; continue; }

                $pdo->prepare("INSERT INTO products (sku,name,category_id,unit_id,spec,barcode,purchase_price,sale_price,min_stock,max_stock,remark,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)")
                    ->execute([$sku, $name, $catId, $unitId,
                        trim($row[4] ?? ''), trim($row[5] ?? ''),
                        floatval($row[6] ?? 0), floatval($row[7] ?? 0),
                        floatval($row[8] ?? 0), floatval($row[9] ?? 0),
                        trim($row[10] ?? ''), date('Y-m-d H:i:s')]);
                $imported++;
            }
        } elseif ($type === 'customer') {
            foreach ($data['rows'] as $row) {
                $code = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                if (empty($name)) { $skipped++; continue; }

                $ctype = strtolower(trim($row[2] ?? '')) === 'individual' ? 'individual' : 'company';
                $exist = $pdo->prepare("SELECT id FROM customers WHERE name=?");
                $exist->execute([$name]);
                if ($exist->fetch()) { $skipped++; continue; }

                $pdo->prepare("INSERT INTO customers (code,name,type,contact,phone,email,address,initial_balance,remark,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?)")
                    ->execute([$code, $name, $ctype,
                        trim($row[3] ?? ''), trim($row[4] ?? ''), trim($row[5] ?? ''),
                        trim($row[6] ?? ''), floatval($row[7] ?? 0),
                        trim($row[8] ?? ''), date('Y-m-d H:i:s')]);
                $imported++;
            }
        } elseif ($type === 'supplier') {
            foreach ($data['rows'] as $row) {
                $code = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                if (empty($name)) { $skipped++; continue; }

                $exist = $pdo->prepare("SELECT id FROM suppliers WHERE name=?");
                $exist->execute([$name]);
                if ($exist->fetch()) { $skipped++; continue; }

                $pdo->prepare("INSERT INTO suppliers (code,name,contact,phone,email,address,bank_name,bank_account,tax_no,remark,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)")
                    ->execute([$code, $name,
                        trim($row[2] ?? ''), trim($row[3] ?? ''), trim($row[4] ?? ''),
                        trim($row[5] ?? ''), trim($row[6] ?? ''), trim($row[7] ?? ''),
                        trim($row[8] ?? ''), trim($row[9] ?? ''), date('Y-m-d H:i:s')]);
                $imported++;
            }
        }

        $pdo->commit();
        add_log(get_user_id(), 'import', $type, "批量导入{$types[$type]['name']}: 成功{$imported}条, 跳过{$skipped}条");
        $success = "导入完成！成功导入 <b>{$imported}</b> 条" . ($skipped > 0 ? "，跳过 <b>{$skipped}</b> 条（重复或信息不完整）" : "");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Import error: ' . $e->getMessage());
        $error = '导入失败，请查看系统日志';
    }
}

$typeName = $types[$type]['name'];
$typeIcon = $types[$type]['icon'];
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-file-import"></i> 数据导入</h1>
    <div class="page-actions">
        <a href="?type=<?=$type?>&action=template" class="btn btn-outline"><i class="fa-solid fa-download"></i> 下载导入模板</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= $error ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3 class="card-title">选择导入类型</h3></div>
    <div class="card-body">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($types as $k => $v): ?>
            <a href="?type=<?=$k?>" class="btn <?=$type===$k?'btn-primary':'btn-outline'?>">
                <i class="fa-solid fa-<?=$v['icon']?>"></i> <?=$v['name']?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-<?=$typeIcon?>"></i> 导入<?=$typeName?></h3></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="importForm">
            <?= csrf_field() ?>
            <div class="alert-info" style="padding:12px;margin-bottom:16px;border-radius:8px;font-size:13px;">
                <strong><i class="fa-solid fa-info-circle"></i> 操作步骤：</strong><br>
                1. 点击右上角「下载导入模板」获取标准格式的Excel模板<br>
                2. 按照模板格式填写数据（标 <span style="color:#ef4444">*</span> 的为必填项）<br>
                3. 选择填写好的Excel文件上传导入<br>
                4. 已存在的SKU（商品编码）或名称重复的数据会自动跳过
            </div>

            <div class="form-group">
                <label class="form-label">选择Excel文件 (.xlsx)</label>
                <div style="border:2px dashed var(--gray-300);border-radius:8px;padding:30px;text-align:center;cursor:pointer;" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <i class="fa-solid fa-cloud-upload" style="font-size:36px;color:var(--gray-400);margin-bottom:10px;display:block;"></i>
                    <p style="color:var(--gray-500);margin:0;">点击选择文件或拖拽文件到此处</p>
                    <p style="color:var(--gray-400);font-size:12px;margin:4px 0 0;">仅支持 .xlsx 格式</p>
                    <input type="file" name="file" id="fileInput" accept=".xlsx" style="display:none;" onchange="handleFile(this)" required>
                    <div id="fileInfo" style="display:none;margin-top:10px;color:var(--primary);"></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                <i class="fa-solid fa-upload"></i> 开始导入
            </button>
        </form>
    </div>
</div>

<script>
function handleFile(input) {
    if (input.files.length > 0) {
        var f = input.files[0];
        document.getElementById('fileInfo').style.display = 'block';
        document.getElementById('fileInfo').innerHTML = '<i class="fa-solid fa-file-excel"></i> ' + f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)';
        document.getElementById('submitBtn').disabled = false;
        document.getElementById('dropZone').style.borderColor = 'var(--primary)';
        document.getElementById('dropZone').style.background = 'var(--primary-light)';
    }
}

// 拖拽支持
var dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.style.borderColor = 'var(--primary)'; });
dropZone.addEventListener('dragleave', function() { dropZone.style.borderColor = 'var(--gray-300)'; });
dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--gray-300)';
    var file = e.dataTransfer.files[0];
    if (file && file.name.endsWith('.xlsx')) {
        document.getElementById('fileInput').files = e.dataTransfer.files;
        handleFile(document.getElementById('fileInput'));
    } else { alert('请上传 .xlsx 格式文件'); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
