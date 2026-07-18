<?php
// 导出请求在输出HTML之前处理
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    @ob_start(); // 在最开始就开启缓冲，防止一切意外输出
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/xlsx_helper.php';
    $pdo = getDB();
    $allProducts = $pdo->query("SELECT p.sku, p.name, c.name as category, p.spec, u.name as unit, p.purchase_price, p.sale_price, COALESCE(inv.qty,0) as stock FROM products p LEFT JOIN product_categories c ON p.category_id=c.id LEFT JOIN units u ON p.unit_id=u.id LEFT JOIN (SELECT product_id, SUM(quantity) as qty FROM inventory GROUP BY product_id) inv ON p.id=inv.product_id WHERE p.status=1 ORDER BY p.id")->fetchAll();
    $headers = ['SKU编码','商品名称','分类','规格','单位','采购价','销售价','库存'];
    $rows = array_map(function($r){ return [$r['sku'],$r['name'],$r['category']?:'',$r['spec'],$r['unit']?:'',$r['purchase_price'],$r['sale_price'],$r['stock']]; }, $allProducts);
    xlsx_export($headers, $rows, 'products_' . date('Ymd') . '.xlsx');
}

require_once __DIR__ . '/../../includes/header.php';
require_permission('product_view');

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$categoryId = intval($_GET['category_id'] ?? 0);

$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}
if ($categoryId > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $categoryId;
}

$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// 总数
$countSql = "SELECT COUNT(*) FROM products p $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pages = ceil($total / $perPage);

// 列表数据 - 带库存信息
$sql = "SELECT p.*, c.name as category_name, u.name as unit_name,
    COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id=p.id),0) as stock_qty
    FROM products p
    LEFT JOIN product_categories c ON p.category_id=c.id
    LEFT JOIN units u ON p.unit_id=u.id
    $where ORDER BY p.id DESC LIMIT $offset, $perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$categories = get_options('product_categories', 'id', 'name', 'status=1');
$units = get_options('units', 'id', 'name', 'status=1');

// 获取最新SKU用于自动递增
$lastSku = $pdo->query("SELECT sku FROM products ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextSku = '';
if ($lastSku) {
    if (preg_match('/^(.+?)(\d+)$/', $lastSku, $m)) {
        $nextSku = $m[1] . str_pad(intval($m[2]) + 1, strlen($m[2]), '0', STR_PAD_LEFT);
    } else {
        $nextSku = $lastSku . '-1';
    }
} else {
    $nextSku = 'SP0001';
}

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $data = [
            'sku' => $_POST['sku'] ?? '',
            'name' => $_POST['name'] ?? '',
            'category_id' => intval($_POST['category_id'] ?? 0),
            'unit_id' => intval($_POST['unit_id'] ?? 0),
            'spec' => $_POST['spec'] ?? '',
            'barcode' => $_POST['barcode'] ?? '',
            'purchase_price' => floatval($_POST['purchase_price'] ?? 0),
            'sale_price' => floatval($_POST['sale_price'] ?? 0),
            'min_stock' => floatval($_POST['min_stock'] ?? 0),
            'max_stock' => floatval($_POST['max_stock'] ?? 0),
            'remark' => $_POST['remark'] ?? '',
            'description' => $_POST['description'] ?? '',
        ];

        if (empty($data['name']) || empty($data['sku'])) {
            $error = '商品名称和SKU编码不能为空';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE products SET sku=?,name=?,category_id=?,unit_id=?,spec=?,barcode=?,purchase_price=?,sale_price=?,min_stock=?,max_stock=?,remark=?,description=? WHERE id=?");
                $stmt->execute([$data['sku'],$data['name'],$data['category_id'],$data['unit_id'],$data['spec'],$data['barcode'],$data['purchase_price'],$data['sale_price'],$data['min_stock'],$data['max_stock'],$data['remark'],$data['description'],$id]);
                add_log(get_user_id(), 'update', 'product', "修改商品: {$data['name']}");
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (sku,name,category_id,unit_id,spec,barcode,purchase_price,sale_price,min_stock,max_stock,remark,description,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$data['sku'],$data['name'],$data['category_id'],$data['unit_id'],$data['spec'],$data['barcode'],$data['purchase_price'],$data['sale_price'],$data['min_stock'],$data['max_stock'],$data['remark'],$data['description'],date('Y-m-d H:i:s')]);
                add_log(get_user_id(), 'create', 'product', "新增商品: {$data['name']}");
            }
            redirect("list.php?page=$page&search=$search&category_id=$categoryId");
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0 && check_permission('product_edit')) {
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            add_log(get_user_id(), 'delete', 'product', "删除商品ID: $id");
        }
        redirect("list.php?page=$page");
    }
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-box"></i> 商品管理</h1>
    <div class="page-actions">
        <a href="import.php?type=product" class="btn btn-outline"><i class="fa-solid fa-upload"></i> 导入</a>
        <button class="btn btn-outline" onclick="exportProducts()"><i class="fa-solid fa-download"></i> 导出</button>
        <button class="btn btn-primary" onclick="openNewProduct()"><i class="fa-solid fa-plus"></i> 新增商品</button>
    </div>
</div>

<?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<!-- 搜索筛选 -->
<form class="filter-bar" method="get">
    <div class="search-box">
        <i class="fa-solid fa-search"></i>
        <input type="text" name="search" class="form-control" placeholder="搜索商品名称/SKU/条码..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <select name="category_id" class="form-control" style="min-width:150px;">
        <option value="0">全部分类</option>
        <?php foreach ($categories as $cid => $cname): ?>
        <option value="<?= $cid ?>" <?= $categoryId==$cid?'selected':'' ?>><?= $cname ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> 查询</button>
    <?php if ($search || $categoryId): ?>
    <a href="list.php" class="btn btn-outline btn-sm">清除</a>
    <?php endif; ?>
</form>

<!-- 数据表格 -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>图片</th><th>#</th><th>SKU编码</th><th>商品名称</th><th>分类</th><th>规格</th><th>单位</th>
                        <th>采购价</th><th>销售价</th><th>库存</th><th>状态</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list): foreach ($list as $item): ?>
                    <tr>
                        <td style="width:48px;">
                            <?php if (!empty($item['image'])): ?>
                            <img src="../../<?= htmlspecialchars($item['image']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" onclick="previewImage('../../<?= htmlspecialchars($item['image']) ?>')" title="点击放大">
                            <?php else: ?>
                            <span style="display:inline-block;width:40px;height:40px;background:var(--gray-100);border-radius:4px;text-align:center;line-height:40px;color:var(--gray-400);font-size:16px;"><i class="fa-solid fa-box"></i></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $item['id'] ?></td>
                        <td><?= htmlspecialchars($item['sku']) ?></td>
                        <td><strong><a href="javascript:void(0)" onclick="viewProductDetail(<?= $item['id'] ?>)" style="color:var(--primary);text-decoration:none;"><?= htmlspecialchars($item['name']) ?></a></strong></td>
                        <td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($item['spec'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($item['unit_name'] ?? '-') ?></td>
                        <td>¥<?= format_money($item['purchase_price']) ?></td>
                        <td>¥<?= format_money($item['sale_price']) ?></td>
                        <td>
                            <?php $stock = floatval($item['stock_qty']); ?>
                            <?php if ($item['min_stock'] > 0 && $stock <= $item['min_stock']): ?>
                            <span class="badge badge-danger"><?= $stock ?></span>
                            <?php elseif ($stock == 0): ?>
                            <span class="badge badge-warning"><?= $stock ?></span>
                            <?php else: ?>
                            <span class="badge badge-success"><?= $stock ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['status']): ?>
                            <span class="badge badge-success">启用</span>
                            <?php else: ?>
                            <span class="badge badge-gray">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-outline" onclick="viewProductDetail(<?= $item['id'] ?>)" title="查看详情"><i class="fa-solid fa-eye"></i></button>
                                <button class="btn btn-sm btn-outline btn-edit-product" data-product="<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-pen"></i></button>
                                <?php if (check_permission('product_view')): ?>
                                <button class="btn btn-sm btn-outline" onclick="deleteProduct(<?= $item['id'] ?>)"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="13"><div class="empty-state"><i class="fa-solid fa-box-open"></i><p>暂无商品数据</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 分页 -->
<?php if ($pages > 1): ?>
<div class="pagination">
    <span class="info">共 <?= $total ?> 条 / <?= $pages ?> 页</span>
    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&category_id=<?= $categoryId ?>">上一页</a><?php endif; ?>
    <?php for ($i = max(1, $page-2); $i <= min($pages, $page+2); $i++): ?>
    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category_id=<?= $categoryId ?>" class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&category_id=<?= $categoryId ?>">下一页</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- 新增/编辑弹窗 -->
<div class="modal-overlay" id="productModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">新增商品</h3>
            <button class="modal-close" onclick="closeModal('productModal')">&times;</button>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="pid" value="0">
            <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">SKU编码 <span class="required">*</span></label>
                        <input type="text" name="sku" id="psku" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">条码</label>
                        <input type="text" name="barcode" id="pbarcode" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">商品名称 <span class="required">*</span></label>
                    <input type="text" name="name" id="pname" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">商品分类</label>
                        <select name="category_id" id="pcategory" class="form-control">
                            <option value="0">未分类</option>
                            <?php foreach ($categories as $cid => $cname): ?>
                            <option value="<?= $cid ?>"><?= $cname ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">单位</label>
                        <select name="unit_id" id="punit" class="form-control">
                            <?php foreach ($units as $uid => $uname): ?>
                            <option value="<?= $uid ?>"><?= $uname ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">规格</label>
                        <input type="text" name="spec" id="pspec" class="form-control" placeholder="如：500ml/瓶">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">采购价(¥)</label>
                        <input type="number" step="0.01" name="purchase_price" id="pprice" class="form-control number-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">销售价(¥)</label>
                        <input type="number" step="0.01" name="sale_price" id="sprice" class="form-control number-input">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">最低库存预警</label>
                        <input type="number" step="0.01" name="min_stock" id="pmin" class="form-control" placeholder="低于此数量预警">
                    </div>
                    <div class="form-group">
                        <label class="form-label">最高库存</label>
                        <input type="number" step="0.01" name="max_stock" id="pmax" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">商品描述 <span style="color:var(--gray-400);font-weight:normal;font-size:12px;">（用于产品项目方案单/报价单打印，可填写详细规格参数、特性介绍等）</span></label>
                    <textarea name="description" id="pdescription" class="form-control" rows="4" placeholder="例如：&#10;外形尺寸：长2600mm 宽2100mm 高2200mm&#10;造雪机喷嘴数量：216个&#10;出雪量：6.22-64.48m³/h&#10;电压值：7.5 kw/h&#10;喷射半径：65m-60m&#10;保修时间：3年"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">备注</label>
                    <textarea name="remark" id="premark" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">商品图片（仅编辑时可上传）</label>
                    <div id="imageUploadArea" style="display:none;">
                        <div id="existingImages" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;"></div>
                        <div style="border:1px dashed var(--gray-300);border-radius:6px;padding:12px;text-align:center;">
                            <input type="file" id="imageFileInput" accept="image/*" multiple style="display:none;" onchange="uploadImages(this)">
                            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('imageFileInput').click()"><i class="fa-solid fa-image"></i> 选择图片</button>
                            <span style="font-size:12px;color:var(--gray-400);margin-left:8px;">支持 jpg/png/gif/webp，可多选</span>
                        </div>
                    </div>
                    <span id="imageUploadHint" style="font-size:12px;color:var(--gray-400);">保存商品后可上传图片</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('productModal')">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 商品详情弹窗 -->
<div class="modal-overlay" id="productDetailModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-circle-info"></i> 商品详情</h3>
            <button class="modal-close" onclick="closeModal('productDetailModal')">&times;</button>
        </div>
        <div class="modal-body" style="max-height:70vh;overflow-y:auto;" id="productDetailBody">
            <div style="text-align:center;padding:40px;color:var(--gray-400);"><i class="fa-solid fa-spinner fa-spin"></i> 加载中...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('productDetailModal')">关闭</button>
        </div>
    </div>
</div>

<!-- 图片预览弹窗 -->
<div class="modal-overlay" id="imagePreviewModal" onclick="closeModal('imagePreviewModal')">
    <div style="max-width:90vw;max-height:90vh;position:relative;top:50%;left:50%;transform:translate(-50%,-50%);">
        <img id="previewImage" src="" style="max-width:90vw;max-height:85vh;border-radius:8px;object-fit:contain;">
        <button onclick="closeModal('imagePreviewModal')" style="position:absolute;top:-35px;right:0;background:none;border:none;color:#fff;font-size:24px;cursor:pointer;">&times;</button>
    </div>
</div>

<script>
var nextSku = '<?= addslashes($nextSku) ?>';
var currentEditProductId = 0;
var csrfToken = '<?= csrf_token() ?>';
var uploadToken = '<?= upload_token() ?>';

function deleteProduct(id) {
    if (!confirm('确定删除该商品？')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="_csrf_token" value="' + csrfToken + '"><input name="action" value="delete"><input name="id" value="' + id + '">';
    document.body.appendChild(f);
    f.submit();
}

// 使用事件代理处理编辑按钮（替代不安全的 inline JSON onclick）
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-edit-product');
    if (btn) {
        var data = JSON.parse(btn.getAttribute('data-product'));
        editProduct(data);
    }
});

function previewImage(url) {
    document.getElementById('previewImage').src = url;
    openModal('imagePreviewModal');
}
function openNewProduct() {
    document.getElementById('modalTitle').textContent = '新增商品';
    document.getElementById('pid').value = 0;
    document.getElementById('psku').value = nextSku;
    document.getElementById('pbarcode').value = '';
    document.getElementById('pname').value = '';
    document.getElementById('pspec').value = '';
    document.getElementById('pprice').value = '';
    document.getElementById('sprice').value = '';
    document.getElementById('pmin').value = '';
    document.getElementById('pmax').value = '';
    document.getElementById('premark').value = '';
    document.getElementById('pdescription').value = '';
    currentEditProductId = 0;
    document.getElementById('imageUploadArea').style.display = 'none';
    document.getElementById('imageUploadHint').style.display = 'inline';
    document.getElementById('existingImages').innerHTML = '';
    fetchNextSku();
    openModal('productModal');
}
function fetchNextSku() {
    fetch('ajax_sku.php')
    .then(function(r){return r.text();}).then(function(sku){if(sku&&sku!==''){document.getElementById('psku').value=sku;nextSku=sku;}})
    .catch(function(){});
}
function editProduct(data) {
    document.getElementById('modalTitle').textContent = '编辑商品';
    document.getElementById('pid').value = data.id;
    document.getElementById('psku').value = data.sku;
    document.getElementById('pbarcode').value = data.barcode || '';
    document.getElementById('pname').value = data.name;
    document.getElementById('pcategory').value = data.category_id;
    document.getElementById('punit').value = data.unit_id;
    document.getElementById('pspec').value = data.spec || '';
    document.getElementById('pprice').value = data.purchase_price;
    document.getElementById('sprice').value = data.sale_price;
    document.getElementById('pmin').value = data.min_stock;
    document.getElementById('pmax').value = data.max_stock;
    document.getElementById('premark').value = data.remark || '';
    document.getElementById('pdescription').value = data.description || '';
    currentEditProductId = data.id;
    loadProductImages(data.id);
    document.getElementById('imageUploadArea').style.display = 'block';
    document.getElementById('imageUploadHint').style.display = 'none';
    openModal('productModal');
}

function loadProductImages(productId) {
    fetch('product_images.php?action=list&product_id=' + productId)
    .then(function(r){return r.json();})
    .then(function(images){
        var container = document.getElementById('existingImages');
        container.innerHTML = '';
        images.forEach(function(img){
            var div = document.createElement('div');
            div.style.cssText = 'position:relative;display:inline-block;';
            div.innerHTML = '<img src="../../' + img.image_url + '" style="width:64px;height:64px;object-fit:cover;border-radius:4px;border:1px solid var(--gray-200);">' +
                '<button type="button" onclick="deleteImage(' + img.id + ',' + productId + ')" style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:10px;cursor:pointer;line-height:18px;">×</button>';
            container.appendChild(div);
        });
    });
}

function uploadImages(input) {
    if (!input.files.length) return;
    var formData = new FormData();
    formData.append('_upload_token', uploadToken);
    formData.append('product_id', currentEditProductId);
    for (var i = 0; i < input.files.length; i++) {
        formData.append('image[]', input.files[i]);
    }
    fetch('product_images.php', {method:'POST',body:formData})
    .then(function(r){return r.json();})
    .then(function(resp){
        if (resp.upload_token) uploadToken = resp.upload_token;
        if (resp.success) { loadProductImages(currentEditProductId); }
        else { alert(resp.message); }
    });
    input.value = '';
}

function deleteImage(imgId, productId) {
    if (!confirm('确定删除此图片？')) return;
    var formData = new FormData();
    formData.append('_upload_token', uploadToken);
    formData.append('action', 'delete_image');
    formData.append('img_id', imgId);
    fetch('product_images.php', {method:'POST',body:formData})
    .then(function(r){return r.json();})
    .then(function(resp){
        if (resp.upload_token) uploadToken = resp.upload_token;
        if (resp.success) loadProductImages(productId);
        else alert(resp.message);
    });
}

function viewProductDetail(productId) {
    openModal('productDetailModal');
    document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);"><i class="fa-solid fa-spinner fa-spin"></i> 加载中...</div>';
    fetch('detail.php?id=' + productId)
    .then(function(r){return r.json();})
    .then(function(resp){
        if (!resp.success) {
            document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger);">' + (resp.message || '加载失败') + '</div>';
            return;
        }
        var p = resp.product;
        var imgs = resp.images || [];
        var inv = resp.recent_inventory || [];
        // 构建图片轮播
        var imgHtml = '';
        if (imgs.length > 0) {
            imgHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
            imgs.forEach(function(img){
                imgHtml += '<img src="../../' + img.image_url + '" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);cursor:pointer;" onclick="previewImage(\'../../' + img.image_url + '\')" title="点击放大">';
            });
            imgHtml += '</div>';
        } else if (p.image) {
            imgHtml = '<div style="margin-bottom:16px;"><img src="../../' + p.image + '" style="max-width:200px;max-height:200px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);cursor:pointer;" onclick="previewImage(\'../../' + p.image + '\')" title="点击放大"></div>';
        } else {
            imgHtml = '<div style="margin-bottom:16px;color:var(--gray-400);font-size:13px;">暂无商品图片</div>';
        }

        // 构建基本信息表格
        var html = imgHtml;
        html += '<table style="width:100%;border-collapse:collapse;"><tbody>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);width:100px;font-size:13px;">SKU编码</td><td style="padding:6px 12px;font-weight:500;">' + escHtml(p.sku) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">商品名称</td><td style="padding:6px 12px;font-weight:500;">' + escHtml(p.name) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">商品分类</td><td style="padding:6px 12px;">' + escHtml(p.category_name || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">单位</td><td style="padding:6px 12px;">' + escHtml(p.unit_name || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">规格</td><td style="padding:6px 12px;">' + escHtml(p.spec || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">条码</td><td style="padding:6px 12px;">' + escHtml(p.barcode || '-') + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">采购价</td><td style="padding:6px 12px;color:var(--warning);font-weight:500;">&yen;' + Number(p.purchase_price).toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">销售价</td><td style="padding:6px 12px;color:var(--danger);font-weight:500;">&yen;' + Number(p.sale_price).toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">当前库存</td><td style="padding:6px 12px;font-weight:500;">';
        var stock = Number(p.stock_qty);
        if (p.min_stock > 0 && stock <= Number(p.min_stock)) {
            html += '<span class="badge badge-danger">' + stock + '</span>';
        } else if (stock == 0) {
            html += '<span class="badge badge-warning">' + stock + '</span>';
        } else {
            html += '<span class="badge badge-success">' + stock + '</span>';
        }
        html += '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">库存预警</td><td style="padding:6px 12px;">最低 ' + Number(p.min_stock).toFixed(0) + ' / 最高 ' + Number(p.max_stock).toFixed(0) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">库存金额</td><td style="padding:6px 12px;color:var(--primary);font-weight:500;">&yen;' + Number(p.stock_value || 0).toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;">状态</td><td style="padding:6px 12px;">' + (p.status == 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-gray">禁用</span>') + '</td></tr>';
        if (p.description) {
            html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;vertical-align:top;">商品描述</td><td style="padding:6px 12px;word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap;max-width:400px;">' + escHtml(p.description) + '</td></tr>';
        }
        if (p.remark) {
            html += '<tr><td style="padding:6px 12px;color:var(--gray-500);font-size:13px;vertical-align:top;">备注</td><td style="padding:6px 12px;word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap;max-width:400px;">' + escHtml(p.remark) + '</td></tr>';
        }
        html += '</tbody></table>';

        // 最近出入库记录
        if (inv.length > 0) {
            html += '<hr style="margin:16px 0;border-color:var(--gray-200);">';
            html += '<h4 style="margin-bottom:8px;font-size:14px;"><i class="fa-solid fa-clock-rotate-left"></i> 最近10条出入库记录</h4>';
            html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
            html += '<thead><tr style="background:var(--gray-100);"><th style="padding:6px 8px;text-align:left;">时间</th><th style="padding:6px 8px;">类型</th><th style="padding:6px 8px;text-align:right;">数量</th><th style="padding:6px 8px;">备注</th></tr></thead><tbody>';
            inv.forEach(function(row){
                var rowBg = '';
                if (row.type === 'in') rowBg = 'background:rgba(16,185,129,0.05);';
                else if (row.type === 'out') rowBg = 'background:rgba(239,68,68,0.05);';
                html += '<tr style="' + rowBg + '">';
                html += '<td style="padding:4px 8px;">' + (row.created_at || '-') + '</td>';
                html += '<td style="padding:4px 8px;text-align:center;">' + escHtml(row.type_name) + '</td>';
                html += '<td style="padding:4px 8px;text-align:right;font-weight:500;">' + Number(row.change_quantity).toFixed(2) + '</td>';
                html += '<td style="padding:4px 8px;color:var(--gray-500);word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap;max-width:200px;">' + escHtml(row.remark || '-') + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
        }

        document.getElementById('productDetailBody').innerHTML = html;
    }).catch(function(){
        document.getElementById('productDetailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger);">网络错误，请重试</div>';
    });
}

// HTML 转义（防止XSS）
function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function exportProducts() {
    window.open('list.php?export=xlsx&t=' + Date.now());
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
