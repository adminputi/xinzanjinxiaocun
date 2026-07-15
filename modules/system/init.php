<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('system');

$pdo = getDB();
$success = '';
$error = '';

// 初始化分类定义
$categories = [
    'products'       => ['name' => '商品初始化',        'icon' => 'box',           'tables' => ['products', 'product_categories', 'units'], 'reinsert' => true],
    'warehouses'     => ['name' => '仓库初始化',        'icon' => 'warehouse',      'tables' => ['warehouses'], 'reinsert' => true],
    'inventory'      => ['name' => '仓库数据初始化',    'icon' => 'database',       'tables' => ['inventory', 'inventory_logs']],
    // 员工表已废除，改用 users 表管理所有业务人员
    'suppliers'      => ['name' => '供应商初始化',      'icon' => 'truck',          'tables' => ['suppliers']],
    'customers'      => ['name' => '客户初始化',        'icon' => 'users',          'tables' => ['customers']],
    'purchase'       => ['name' => '采购记录清空',      'icon' => 'shopping-cart',  'tables' => ['purchase_orders', 'purchase_order_items', 'purchase_instocks', 'purchase_instock_items', 'purchase_returns', 'purchase_return_items']],
    'sales'          => ['name' => '销售记录清空',      'icon' => 'shopping-bag',   'tables' => ['sales_orders', 'sales_order_items', 'sales_outstocks', 'sales_outstock_items', 'sales_returns', 'sales_return_items']],
    'transfer'       => ['name' => '库存调拨清空',      'icon' => 'refresh-cw',     'tables' => ['transfers', 'transfer_items']],
    'check'          => ['name' => '盘点记录清空',      'icon' => 'clipboard',      'tables' => ['check_orders', 'check_items']],
    'loss'           => ['name' => '报损报溢清空',      'icon' => 'alert-triangle', 'tables' => ['loss_orders', 'loss_items']],
    'finance'        => ['name' => '财务记录清空',      'icon' => 'dollar-sign',    'tables' => ['receipts', 'payments']],
    'logs'           => ['name' => '操作日志清空',      'icon' => 'file-text',      'tables' => ['operation_logs']],
];

// 重新插入默认数据
function reinsert_defaults($pdo, $cat) {
    if ($cat === 'products') {
        // 重新插入默认单位
        try {
            $pdo->exec("INSERT INTO `units` (`name`) VALUES ('个'),('箱'),('件'),('套'),('千克'),('克'),('吨'),('米'),('升'),('包'),('瓶'),('桶')");
        } catch(Exception $e) {}
    } elseif ($cat === 'warehouses') {
        try {
            $pdo->exec("INSERT INTO `warehouses` (`name`, `code`) VALUES ('主仓库', 'MAIN')");
        } catch(Exception $e) {}
    }
}

// 执行初始化
csrf_verify();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $items = $_POST['items'] ?? [];

    if ($action === 'all') {
        // 一键初始化：选中所有分类
        $items = array_keys($categories);
    }

    if (empty($items)) {
        $error = '请至少选择一个初始化项目';
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $cleared = [];

        foreach ($items as $key) {
            if (!isset($categories[$key])) continue;
            $cat = $categories[$key];
            // 注意：TRUNCATE 在 MySQL 中隐式提交事务，无法回滚
            foreach ($cat['tables'] as $table) {
                try {
                    $pdo->exec("TRUNCATE TABLE `$table`");
                } catch (Exception $e) {
                    error_log('Init truncate error: ' . $e->getMessage());
                    $error = "清空表 $table 失败，请查看系统日志";
                    break 2;
                }
            }
            // 重新插入默认数据（幂等操作）
            if (!empty($cat['reinsert'])) {
                try {
                    reinsert_defaults($pdo, $key);
                } catch (Exception $e) {
                    error_log('Init reinsert error: ' . $e->getMessage());
                    $error = "重新插入默认数据失败，请查看系统日志";
                    break;
                }
            }
            $cleared[] = $cat['name'];
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        if (empty($error)) {
            $success = '已成功初始化：' . implode('、', $cleared);
            add_log(get_user_id(), 'init', 'system', '系统初始化：' . implode('、', $cleared));
        } else {
            // 初始化失败时确保外键检查恢复
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}

// 查询各分类的数据记录数
$counts = [];
foreach ($categories as $key => $cat) {
    $counts[$key] = $pdo->query("SELECT COUNT(*) FROM `{$cat['tables'][0]}`")->fetchColumn();
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-rotate-left"></i> 系统初始化</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= $error ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fa-solid fa-list-check"></i> 选择初始化项目</h3>
    </div>
    <div class="card-body">
        <form id="initForm" method="post" onsubmit="return confirmInit()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="initAction" value="selected">

            <div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(true)"><i class="fa-solid fa-square-check"></i> 全选</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(false)"><i class="fa-solid fa-square"></i> 取消全选</button>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:12px;">
                <?php foreach ($categories as $key => $cat): ?>
                <label class="init-item">
                    <input type="checkbox" name="items[]" value="<?= $key ?>" class="init-checkbox">
                    <span class="init-item-icon" style="background:var(--primary-light,#dbeafe);">
                        <i class="fa-solid fa-<?= $cat['icon'] ?>"></i>
                    </span>
                    <span class="init-item-info">
                        <strong><?= $cat['name'] ?></strong>
                        <small>当前 <?= $counts[$key] ?> 条记录</small>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--gray-200);display:flex;gap:12px;flex-wrap:wrap;">
                <button type="button" class="btn btn-danger" onclick="initAll()">
                    <i class="fa-solid fa-triangle-exclamation"></i> 一键初始化全部数据
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-rotate-left"></i> 初始化选中项目
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-circle-info"></i> 注意事项</h3></div>
    <div class="card-body" style="color:var(--gray-600);font-size:13px;line-height:1.8;">
        <p><i class="fa-solid fa-warning" style="color:var(--warning);"></i> <strong>初始化操作不可逆！</strong>清空的数据无法恢复，请谨慎操作。</p>
        <p><i class="fa-solid fa-info-circle" style="color:var(--info);"></i> 系统初始化不会影响以下数据：<strong>用户账号、角色权限、系统设置</strong></p>
        <p><i class="fa-solid fa-info-circle" style="color:var(--info);"></i> 商品初始化和仓库初始化后会自动重建默认数据（单位、主仓库）</p>
        <p><i class="fa-solid fa-info-circle" style="color:var(--info);"></i> 仓库数据初始化仅清空库存数量和变动记录，不影响仓库和商品本身</p>
        <p><i class="fa-solid fa-info-circle" style="color:var(--info);"></i> 建议操作前备份数据库</p>
    </div>
</div>

<script>
function selectAll(checked) {
    document.querySelectorAll('.init-checkbox').forEach(function(cb) {
        cb.checked = checked;
    });
}

function initAll() {
    if (!confirm('⚠️ 确定要一键初始化全部数据吗？\n\n此操作将清空除用户/角色/设置外的所有数据，且不可恢复！\n\n建议先备份数据库。')) {
        return false;
    }
    if (!confirm('再次确认：所有商品、仓库、客户、供应商、采购、销售、库存、财务等数据将被永久删除！\n\n是否继续？')) {
        return false;
    }
    document.getElementById('initAction').value = 'all';
    document.getElementById('initForm').submit();
    return true;
}

function confirmInit() {
    var checked = document.querySelectorAll('.init-checkbox:checked');
    if (checked.length === 0) {
        alert('请至少选择一个初始化项目');
        return false;
    }
    var names = [];
    checked.forEach(function(cb) { names.push(cb.parentElement.querySelector('strong').textContent); });
    document.getElementById('initAction').value = 'selected';
    return confirm('确定要初始化以下项目吗？\n\n' + names.join('\n') + '\n\n此操作不可恢复！');
}
</script>

<style>
.init-item {
    display:flex;align-items:center;gap:12px;
    padding:12px 16px;
    border:1px solid var(--gray-200);border-radius:8px;
    cursor:pointer;transition:all 0.2s;
    background:#fff;
}
.init-item:hover { border-color:var(--primary); background:var(--primary-light,#f0f7ff); }
.init-item .init-item-icon {
    width:40px;height:40px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;color:var(--primary);
    flex-shrink:0;
}
.init-item .init-item-info { display:flex;flex-direction:column;gap:2px; }
.init-item .init-item-info small { color:var(--gray-500);font-size:12px; }
.init-item input[type="checkbox"] { width:18px;height:18px;cursor:pointer;flex-shrink:0; }
.alert-error { background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
