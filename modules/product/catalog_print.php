<?php
require_once __DIR__ . '/../../includes/header.php';
require_permission('product_view');
$pdo = getDB();

// 查询商品（含图片和描述）：跟随从列表页传来的筛选条件，没传条件时导出全部启用商品
$filter = build_product_filter();
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, u.name as unit_name
    FROM products p
    LEFT JOIN product_categories c ON p.category_id=c.id
    LEFT JOIN units u ON p.unit_id=u.id
    WHERE p.status=1" . $filter['cond'] . " ORDER BY p.id");
$stmt->execute($filter['params']);
$products = $stmt->fetchAll();

// 当前筛选条件的文字描述，打印页顶部显示，避免导出后不知道导的是哪一批
$filterDesc = [];
if ($filter['search'] !== '') $filterDesc[] = '关键词“' . $filter['search'] . '”';
if ($filter['category_id'] > 0) {
    $cStmt = $pdo->prepare("SELECT name FROM product_categories WHERE id=?");
    $cStmt->execute([$filter['category_id']]);
    $cName = $cStmt->fetchColumn();
    $filterDesc[] = '分类“' . ($cName ?: ('#' . $filter['category_id'])) . '”';
}
$hasFilter = !empty($filterDesc);

// 确保打印模板表存在
ensure_print_templates_table($pdo);

// 从数据库加载"产品目录"模板，首次访问时自动初始化
$catalogTplName = '产品目录（含图片+描述）';
$tplStmt = $pdo->prepare("SELECT * FROM print_templates WHERE name=? LIMIT 1");
$tplStmt->execute([$catalogTplName]);
$tpl = $tplStmt->fetch();
if (!$tpl) {
    // 首次访问，自动创建默认模板
    $defaultCatalogTpl = '<div style="font-family:SimSun,Arial;padding:10px;max-width:900px;margin:0 auto;color:#000;">'
    . '<div style="background:#2c3e50;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-radius:4px 4px 0 0;">'
    . '<h1 style="margin:0;font-size:28px;font-weight:bold;letter-spacing:4px;">产品目录</h1>'
    . '<div style="text-align:right;line-height:1.7;">'
    . '<div style="font-size:18px;font-weight:bold;">{company_name}</div>'
    . '<div style="font-size:13px;opacity:0.9;">{company_address}</div>'
    . '</div></div>'
    . '<div style="padding:10px 0 4px 0;font-size:14px;line-height:1.9;">'
    . '<div style="display:flex;flex-wrap:wrap;gap:20px;">'
    . '<span><strong>日期：</strong>{print_date}</span>'
    . '<span><strong>电话：</strong>{company_phone}</span>'
    . '</div></div>'
    . '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:12px;border:1px solid #2c3e50;table-layout:fixed;">'
    . '<thead><tr style="background:#2c3e50;color:#fff;font-size:13px;font-weight:bold;">'
    . '<th style="width:55px;">编码</th>'
    . '<th style="width:180px;">产品图片</th>'
    . '<th style="width:75px;">产品名称</th>'
    . '<th style="width:50%;">描述</th>'
    . '</tr></thead>'
    . '<tbody>{items}</tbody>'
    . '</table>'
    . '<div style="padding:14px 16px;font-size:13px;line-height:1.9;background:#f8f9fa;border:1px solid #2c3e50;border-top:1px dashed #2c3e50;margin-top:0;">'
    . '<strong>备注：</strong><br>{remark}'
    . '</div>'
    . '</div>';
    try {
        $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,1)")
            ->execute([$catalogTplName, 'product_catalog', $defaultCatalogTpl]);
    } catch (Exception $e) {
        error_log('print_templates catalog init failed: ' . $e->getMessage());
    }
    $tplContent = $defaultCatalogTpl;
} else {
    $tplContent = $tpl['content'];
}

// 公司信息
$companyName   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';

// —— PHP 端构建表格行（分析模板 thead 的列，对应填充数据）——
function parseTemplateColumns($tplContent) {
    if (preg_match('/<thead>([\s\S]*?)<\/thead>/', $tplContent, $m)) {
        preg_match_all('/<th[^>]*>(.*?)<\/th>/', $m[1], $thMatches);
        $cols = array_map('trim', $thMatches[1]);
        return array_filter($cols, function($c) { return $c !== ''; });
    }
    return ['编码','产品图片','产品名称','描述']; // fallback
}

$columns = parseTemplateColumns($tplContent);

$colMap = [
    '序号'     => '__idx__',
    'SKU'     => 'sku',
    '编码'     => 'sku',
    '商品名称'  => 'name',
    '产品名称'  => 'name',
    '规格'     => 'spec',
    '单位'     => 'unit_name',
    '产品图片'  => '__image__',
    '描述'     => '__description__',
];

// 替换模板中除 {items} 以外的变量，然后按 {items} 拆分为前后两部分（流式输出）
// 注意：必须先按 {items} 拆分，再用 preg_replace 清理其他变量，否则会误伤 {items}
$tplParts = explode('{items}', $tplContent);
$tplBefore = $tplParts[0] ?? '';
$tplAfter  = $tplParts[1] ?? '';

// 获取当前登录用户的手机号
$userPhone = $pdo->prepare("SELECT phone FROM users WHERE id=? LIMIT 1");
$userPhone->execute([get_user_id()]);
$userPhoneVal = $userPhone->fetchColumn() ?: '';

$tplVars = [
    '{company_name}'    => htmlspecialchars($companyName),
    '{company_address}'  => htmlspecialchars($companyAddress),
    '{company_phone}'   => htmlspecialchars($companyPhone),
    '{print_date}'      => date('Y-m-d'),
    '{remark}'          => '',
    '{user_name}'       => htmlspecialchars(get_user_name()),
    '{user_phone}'      => htmlspecialchars($userPhoneVal),
];
$tplBefore = str_replace(array_keys($tplVars), array_values($tplVars), $tplBefore);
$tplAfter  = str_replace(array_keys($tplVars), array_values($tplVars), $tplAfter);
$tplBefore = preg_replace('/\{[a-z_]+\}/i', '', $tplBefore);
$tplAfter  = preg_replace('/\{[a-z_]+\}/i', '', $tplAfter);

// 产品行渲染函数（每行立即 echo，不累积内存）
function echoProductRow($idx, $prod, $columns, $colMap) {
    echo '<tr>';
    foreach ($columns as $col) {
        $field = $colMap[$col] ?? '';
        if ($field === '__idx__') {
            echo '<td>' . ($idx + 1) . '</td>';
        } elseif ($field === '__image__') {
            if (!empty($prod['image'])) {
                $imgPath = preg_replace('#^(\.\./)+#', '', $prod['image']);
                echo '<td><img src="' . htmlspecialchars($imgPath) . '" style="max-width:160px;max-height:110px;object-fit:contain;" alt=""></td>';
            } else {
                echo '<td style="color:#999;">-</td>';
            }
        } elseif ($field === '__description__') {
            $desc = nl2br(htmlspecialchars($prod['description'] ?? ''));
            echo '<td style="text-align:left;vertical-align:top;line-height:1.5;font-size:11px;word-break:break-all;overflow-wrap:break-word;">' . $desc . '</td>';
        } elseif ($field && isset($prod[$field])) {
            $val = htmlspecialchars((string)$prod[$field]);
            $tdStyle = '';
            if ($col === '产品名称' || $col === '商品名称') {
                $tdStyle = ' style="text-align:left;word-break:break-all;"';
            }
            echo '<td' . $tdStyle . '>' . $val . '</td>';
        } else {
            echo '<td></td>';
        }
    }
    echo '</tr>';
}
?>
<div class="d-flex-between mb-3" style="flex-wrap:wrap;gap:10px;">
    <div>
        <h3 style="margin:0;"><i class="fa-solid fa-book"></i> 产品目录</h3>
        <?php if ($hasFilter): ?>
        <div style="font-size:13px;color:#666;margin-top:6px;">
            已按 <?= htmlspecialchars(implode('、', $filterDesc)) ?> 筛选，仅含启用商品 ·
            <a href="catalog_print.php">导出全部</a> · <a href="list.php">回列表</a>
        </div>
        <?php else: ?>
        <div style="font-size:13px;color:#666;margin-top:6px;">全部启用商品 · <a href="list.php">回列表筛选后再导出</a></div>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <span style="font-size:14px;color:#666;">共 <strong><?= count($products) ?></strong> 个产品</span>
        <button class="btn btn-primary" onclick="printCatalog()"><i class="fa-solid fa-print"></i> 打印产品目录</button>
    </div>
</div>

<div id="catalogPreview" style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;overflow:auto;">
    <?= $tplBefore ?>
    <?php if (empty($products)): ?>
    <tr><td colspan="<?= count($columns) ?>">暂无数据</td></tr>
    <?php else: foreach ($products as $idx => $prod): echoProductRow($idx, $prod, $columns, $colMap); endforeach; endif; ?>
    <?= $tplAfter ?>
</div>

<style>
#catalogPreview{font-family:SimSun,Arial;color:#000;}
#catalogPreview table{border-collapse:collapse;width:100%;table-layout:fixed;}
#catalogPreview table th,#catalogPreview table td{border:1px solid #2c3e50;padding:6px;text-align:center;font-size:12px;vertical-align:middle;}
#catalogPreview table th{font-weight:bold;}
</style>

<script>
function printCatalog() {
    var win = window.open('', '_blank', 'width=900,height=600');
    win.document.write('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>产品目录打印</title>');
    win.document.write('<style>');
    win.document.write('body{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}');
    win.document.write('*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}');
    win.document.write('table{border-collapse:collapse;width:100%;table-layout:fixed;}');
    win.document.write('table th,table td{border:1px solid #2c3e50;padding:6px;text-align:center;font-size:12px;vertical-align:middle;}');
    win.document.write('table th{font-weight:bold;}');
    win.document.write('@media print{body{padding:0;margin:0;}}');
    win.document.write('</style>');
    win.document.write('<base href="<?= htmlspecialchars(($basePath ?? '') . '/') ?>">');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('catalogPreview').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){ win.print(); }, 500);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
