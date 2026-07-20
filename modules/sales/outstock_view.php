<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_outstock');
$pdo = getDB();
run_migrations();
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT so.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.contact as customer_contact, w.name as warehouse_name, u.real_name as employee_name, u.phone as employee_phone FROM sales_outstocks so LEFT JOIN customers c ON so.customer_id=c.id LEFT JOIN warehouses w ON so.warehouse_id=w.id LEFT JOIN users u ON so.employee_id=u.id WHERE so.id=?");
$stmt->execute([$id]);
$outstock = $stmt->fetch();
if (!$outstock) { die('出库单不存在'); }
// 非admin用户只能查看自己的记录
if ($_SESSION['user_role'] !== 'admin' && ($outstock['user_id'] ?? 0) != get_user_id()) { die('无权查看此记录'); }

// 查询追踪码及打印开关
$printTracking = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='print_tracking_code'")->fetchColumn();
$printTracking = ($printTracking === false) ? '1' : $printTracking; // 默认开启
$trackingCode = null;
if ($printTracking === '1') {
    $stmtTracking = $pdo->prepare("SELECT tracking_no, qrcode_path FROM tracking_codes WHERE outstock_id=? LIMIT 1");
    $stmtTracking->execute([$id]);
    $trackingCode = $stmtTracking->fetch();
}
$trackingNoHtml = '';
if ($trackingCode) {
    $qrImg = '';
    if (!empty($trackingCode['qrcode_path']) && file_exists(__DIR__ . '/../../' . $trackingCode['qrcode_path'])) {
        $fullPath = __DIR__ . '/../../' . $trackingCode['qrcode_path'];
        $imgData = @file_get_contents($fullPath);
        if ($imgData !== false && strlen($imgData) > 100) {
            $base64 = base64_encode($imgData);
            $src = 'data:image/png;base64,' . $base64;
            $qrImg = '<img src="' . htmlspecialchars($src) . '" style="width:80px;height:80px;" alt="追踪码二维码"><br>';
        }
    }
    $trackingNoHtml = '<div style="text-align:center;font-size:12px;">' . $qrImg . '追踪码：' . htmlspecialchars($trackingCode['tracking_no']) . '</div>';
}

$stmt2 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, p.image as product_image, p.description as product_description, u.name as unit_name FROM sales_outstock_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.outstock_id=?");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();
// 将商品图片转为 base64 嵌入（避免打印时路径失效）
foreach ($items as &$it) {
    $it['image_base64'] = '';
    if (!empty($it['product_image'])) {
        $imgPath = __DIR__ . '/../../' . $it['product_image'];
        if (file_exists($imgPath)) {
            $data = @file_get_contents($imgPath);
            if ($data !== false) {
                $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg','jpeg']) ? 'jpeg' : ($ext === 'svg' ? 'svg+xml' : $ext);
                $it['image_base64'] = 'data:image/' . $mime . ';base64,' . base64_encode($data);
            }
        }
    }
}
unset($it);

// 查询关联销售订单信息
$linkedOrder = null; $linkedOrderItems = [];
if ($outstock['order_id'] > 0) {
    $stmt3 = $pdo->prepare("SELECT o.*, c.name as customer_name FROM sales_orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE o.id=?");
    $stmt3->execute([$outstock['order_id']]);
    $linkedOrder = $stmt3->fetch();
    if ($linkedOrder) {
        $stmt4 = $pdo->prepare("SELECT i.*, p.name as product_name, p.sku, p.spec, u.name as unit_name FROM sales_order_items i JOIN products p ON i.product_id=p.id LEFT JOIN units u ON p.unit_id=u.id WHERE i.order_id=?");
        $stmt4->execute([$outstock['order_id']]);
        $linkedOrderItems = $stmt4->fetchAll();
    }
}

$payLabels = ['paid_full'=>'已付全款','paid_deposit'=>'已付定金','unpaid'=>'未付款'];
$payBadge = ['paid_full'=>'success','paid_deposit'=>'warning','unpaid'=>'danger'];

// 确保打印模板表存在且模板已更新到最新版本
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `print_templates` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `type` VARCHAR(30) NOT NULL DEFAULT 'sales_outstock', `content` TEXT, `is_default` TINYINT DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
// 仅删除过时的默认模板（按名称精确匹配，不触碰用户自定义模板）
$pdo->exec("DELETE FROM print_templates WHERE type='sales_outstock' AND name IN ('默认销售出库单（含单价金额）','默认销售出库单（不含单价金额）') AND (content NOT LIKE '%<thead>%' OR content NOT LIKE '%{tracking_no}%')");
$existA = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_outstock' AND name='默认销售出库单（含单价金额）'")->fetchColumn();
$existB = $pdo->query("SELECT COUNT(*) FROM print_templates WHERE type='sales_outstock' AND name='默认销售出库单（不含单价金额）'")->fetchColumn();
if ($existA == 0) {
    $defaultTplA = '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;"><div style="flex:1;text-align:center;"><h2>{company_name}</h2><h3>销售出库单</h3></div><div style="flex-shrink:0;">{tracking_no}</div></div><div style="margin-bottom:12px;"><span>单号：{bill_no}</span><span style="margin-left:24px;">日期：{bill_date}</span></div><div style="margin-bottom:12px;"><span>客户：{customer_name}</span><span style="margin-left:24px;">电话：{customer_phone}</span></div><div style="margin-bottom:12px;"><span>地址：{customer_address}</span><span style="margin-left:24px;">仓库：{warehouse_name}</span></div><table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;"><thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead><tbody>{items}</tbody><tfoot><tr><td colspan="7" align="right"><strong>合计金额：</strong></td><td>{total_amount}</td></tr></tfoot></table><div style="margin-top:16px;display:flex;justify-content:space-between;"><span>制单人：{user_name}</span><span>打印时间：' . date('Y-m-d H:i:s') . '</span></div>';
    $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,1)")->execute(['默认销售出库单（含单价金额）','sales_outstock',$defaultTplA]);
}
if ($existB == 0) {
    $defaultTplB = '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;"><div style="flex:1;text-align:center;"><h2>{company_name}</h2><h3>销售出库单（送货单）</h3></div><div style="flex-shrink:0;">{tracking_no}</div></div><div style="margin-bottom:12px;"><span>单号：{bill_no}</span><span style="margin-left:24px;">日期：{bill_date}</span></div><div style="margin-bottom:12px;"><span>客户：{customer_name}</span><span style="margin-left:24px;">电话：{customer_phone}</span></div><div style="margin-bottom:12px;"><span>地址：{customer_address}</span><span style="margin-left:24px;">仓库：{warehouse_name}</span></div><table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;"><thead><tr><th>序号</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>备注</th></tr></thead><tbody>{items}</tbody></table><div style="margin-top:40px;display:flex;justify-content:space-between;flex-wrap:wrap;"><span>制单人：{user_name}</span><span>审核人：___________</span><span>客户签字：___________</span><span>业务签字：___________</span></div>';
    $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,0)")->execute(['默认销售出库单（不含单价金额）','sales_outstock',$defaultTplB]);
}

// 获取所有打印模板（不限类型，用户可在详情页选择任意模板打印）
$templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC, id ASC")->fetchAll();
$selectedTplId = intval($_GET['tpl_id'] ?? 0);
if ($selectedTplId > 0) {
    $stmt5 = $pdo->prepare("SELECT * FROM print_templates WHERE id=?");
    $stmt5->execute([$selectedTplId]);
    $tpl = $stmt5->fetch();
} else {
    // 优先选默认且匹配出库单类型的模板
    $tpl = $pdo->query("SELECT * FROM print_templates WHERE is_default=1 AND type IN('sales_outstock','sales_order') LIMIT 1")->fetch();
    if (!$tpl) $tpl = $pdo->query("SELECT * FROM print_templates WHERE is_default=1 LIMIT 1")->fetch();
}
if (!$tpl && $templates) $tpl = $templates[0];

// 自动初始化"产品项目方案单"模板（type=quote）
$existQuoteOut = $pdo->prepare("SELECT COUNT(*) FROM print_templates WHERE name='产品项目方案单（含图片+描述）'");
$existQuoteOut->execute();
if ($existQuoteOut->fetchColumn() == 0) {
    $quoteTplContentOut = '<div style="font-family:SimSun,Arial;padding:10px;max-width:900px;margin:0 auto;color:#000;">'
    . '<div style="background:#000;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-radius:4px 4px 0 0;">'
    . '<h1 style="margin:0;font-size:28px;font-weight:bold;letter-spacing:4px;">产品项目方案单</h1>'
    . '<div style="text-align:right;line-height:1.7;">'
    . '<div style="font-size:18px;font-weight:bold;">{company_name}</div>'
    . '<div style="font-size:13px;opacity:0.9;">{company_address}</div>'
    . '</div></div>'
    . '<div style="padding:10px 0 4px 0;font-size:14px;line-height:1.9;">'
    . '<div><strong>TO：</strong>{customer_name}</div>'
    . '<div style="display:flex;flex-wrap:wrap;gap:20px;">'
    . '<span><strong>报价日期：</strong>{bill_date}</span>'
    . '<span><strong>电话：</strong>{customer_phone}</span>'
    . '<span><strong>联系人：</strong>{customer_contact}</span>'
    . '</div></div>'
    . '<div style="background:#ffc000;padding:8px 16px;font-weight:bold;margin:8px 0 0 0;font-size:14px;border:1px solid #000;border-bottom:none;">感谢您对本公司的支持与信赖，贵公司所需产品报价如下：</div>'
    . '<table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse;width:100%;font-size:12px;border:1px solid #000;table-layout:fixed;">'
    . '<thead><tr style="background:#1e6bb8;color:#fff;font-size:14px;font-weight:bold;">'
    . '<th style="width:55px;">编码</th>'
    . '<th style="width:100px;">产品图片</th>'
    . '<th style="width:75px;">产品名称</th>'
    . '<th>描述</th>'
    . '<th style="width:75px;">价格</th>'
    . '<th style="width:50px;">数量</th>'
    . '<th style="width:80px;">金额</th>'
    . '</tr></thead>'
    . '<tbody>{items}</tbody>'
    . '</table>'
    . '<div style="background:#ffc000;padding:10px 16px;display:flex;justify-content:space-between;font-weight:bold;font-size:14px;border:1px solid #000;border-top:none;">'
    . '<span>小写合计（人民币）：{total_amount}</span>'
    . '<span>大写合计（人民币）：{total_amount_cn}</span>'
    . '</div>'
    . '<div style="background:#ffc000;padding:10px 16px;font-size:13px;line-height:1.9;border:1px solid #000;border-top:1px dashed #000;">'
    . '<strong>备注：</strong><br>{remark}'
    . '</div>'
    . '</div>';
    $pdo->prepare("INSERT INTO print_templates (name,type,content,is_default) VALUES (?,?,?,0)")
        ->execute(['产品项目方案单（含图片+描述）', 'quote', $quoteTplContentOut]);
    $templates = $pdo->query("SELECT * FROM print_templates ORDER BY type, is_default DESC, id ASC")->fetchAll();
}

// 收款状态变更记录
$stmt6 = $pdo->prepare("SELECT * FROM sales_outstock_paylogs WHERE outstock_id=? ORDER BY id DESC");
$stmt6->execute([$id]);
$payLogs = $stmt6->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-eye"></i> 销售出库单详情</h1>
    <div class="page-actions">
        <button class="btn btn-outline" onclick="showPayModal()"><i class="fa-solid fa-credit-card"></i> 收款状态</button>
        <div style="display:flex;align-items:center;gap:4px;">
            <select id="tplSelect" class="form-control" style="width:auto;display:inline-block;" onchange="selectTpl(this.value)">
                <?php 
                $typeLabels = ['sales_order'=>'销售单','sales_outstock'=>'销售出库单','purchase_order'=>'采购单','purchase_instock'=>'采购入库单'];
                foreach ($templates as $tp): ?>
                <option value="<?=$tp['id']?>" <?=($tpl && $tpl['id']==$tp['id'])?'selected':''?>><?=htmlspecialchars($tp['name'])?> [<?=$typeLabels[$tp['type']]??$tp['type']?>]<?=$tp['is_default']?' ★':''?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline" onclick="printOutstock()"><i class="fa-solid fa-print"></i> 打印出库单</button>
        </div>
        <a href="outstock.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">销售出库单 #<?= $outstock['bill_no'] ?></h3>
        <span class="badge badge-<?= $outstock['status']=='confirmed' ? 'success' : 'warning' ?>"><?= $outstock['status']=='confirmed'?'已出库':'草稿' ?></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
            <div><strong>客户：</strong><?= htmlspecialchars($outstock['customer_name']?:'-') ?></div>
            <div><strong>仓库：</strong><?= htmlspecialchars($outstock['warehouse_name']?:'-') ?></div>
            <div><strong>业务员：</strong><?= htmlspecialchars($outstock['salesperson_name']?:$outstock['employee_name']?:'-') ?></div>
            <div><strong>出库日期：</strong><?= $outstock['outstock_date'] ?></div>
            <div><strong>关联订单：</strong><?= $outstock['order_id']>0 ? '#'.$outstock['order_id'] : '-' ?></div>
            <div><strong>收款状态：</strong><span class="badge badge-<?=$payBadge[$outstock['pay_status']??'unpaid']?>"><?=$payLabels[$outstock['pay_status']??'unpaid']?></span></div>
            <?php if ($outstock['receiver_name']): ?><div><strong>接货人：</strong><?= htmlspecialchars($outstock['receiver_name']) ?><?= $outstock['receiver_phone'] ? ' ('.$outstock['receiver_phone'].')' : '' ?></div><?php endif; ?>
            <?php if ($outstock['pay_updated_at']): ?><div><strong>收款变更时间：</strong><?= $outstock['pay_updated_at'] ?></div><?php endif; ?>
            <div><strong>创建时间：</strong><?= $outstock['created_at'] ?></div>
        </div>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>单位</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($items as $item): ?>
                <tr><td><?=$i++?></td><td><?=$item['sku']?></td><td><?=htmlspecialchars($item['product_name'])?></td><td><?=$item['spec']?:'-'?></td><td><?=$item['unit_name']?:'-'?></td><td><?=$item['quantity']?></td><td>¥<?=format_money($item['price'])?></td><td>¥<?=format_money($item['amount'])?></td><td><?=htmlspecialchars($item['remark']??'')?:'-'?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="9"><div class="empty-state"><p>暂无明细数据</p></div></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot><tr><td colspan="8" class="text-right"><strong>合计：</strong></td><td><strong>¥<?= format_money($outstock['total_amount']) ?></strong></td></tr></tfoot>
        </table></div>
        <?php if ($outstock['remark'] || $outstock['pay_remark'] || $outstock['cancel_reason']): ?>
        <div class="mt-2">
            <?php if ($outstock['remark']): ?><div><strong>备注：</strong><?= nl2br(htmlspecialchars($outstock['remark'])) ?></div><?php endif; ?>
            <?php if ($outstock['pay_remark']): ?><div><strong>收款备注：</strong><?= nl2br(htmlspecialchars($outstock['pay_remark'])) ?></div><?php endif; ?>
            <?php if ($outstock['cancel_reason']): ?><div style="color:var(--danger)"><strong>撤销原因：</strong><?= nl2br(htmlspecialchars($outstock['cancel_reason'])) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 关联销售订单信息 -->
<?php if ($linkedOrder): ?>
<div class="card mt-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-link"></i> 关联销售订单 #<?= htmlspecialchars($linkedOrder['bill_no']) ?></h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
            <div><strong>客户：</strong><?= htmlspecialchars($linkedOrder['customer_name']??'-') ?></div>
            <div><strong>订单日期：</strong><?= $linkedOrder['order_date'] ?></div>
            <div><strong>订单金额：</strong>¥<?= format_money($linkedOrder['total_amount']) ?></div>
        </div>
        <?php if ($linkedOrderItems): ?>
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>SKU</th><th>商品名称</th><th>规格</th><th>数量</th><th>单价</th><th>备注</th></tr></thead>
            <tbody>
                <?php $k=1; foreach($linkedOrderItems as $li): ?>
                <tr><td><?=$k++?></td><td><?=$li['sku']?></td><td><?=htmlspecialchars($li['product_name'])?></td><td><?=$li['spec']?:'-'?></td><td><?=$li['quantity']?></td><td>¥<?=format_money($li['price'])?></td><td><?=htmlspecialchars($li['remark']??'')?:'-'?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <div class="mt-2">
            <?php if ($linkedOrder['remark']): ?><div><strong>订单总备注：</strong><?= nl2br(htmlspecialchars($linkedOrder['remark'])) ?></div><?php endif; ?>
            <?php if ($linkedOrder['cancel_reason']): ?><div style="color:var(--danger)"><strong>取消原因：</strong><?= nl2br(htmlspecialchars($linkedOrder['cancel_reason'])) ?></div><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 收款状态变更记录 -->
<?php if ($payLogs): ?>
<div class="card mt-3">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> 收款状态变更记录</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container"><table>
            <thead><tr><th>#</th><th>原状态</th><th>新状态</th><th>备注</th><th>操作人</th><th>变更时间</th></tr></thead>
            <tbody>
                <?php $j=1; foreach($payLogs as $log): ?>
                <tr>
                    <td><?=$j++?></td>
                    <td><span class="badge badge-<?=$payBadge[$log['from_status']??'unpaid']?>"><?=$payLabels[$log['from_status']??'unpaid']?></span></td>
                    <td><span class="badge badge-<?=$payBadge[$log['to_status']??'unpaid']?>"><?=$payLabels[$log['to_status']??'unpaid']?></span></td>
                    <td><?= htmlspecialchars($log['remark']?:'-') ?></td>
                    <td><?= htmlspecialchars($log['user_name']??'-') ?></td>
                    <td><?= $log['created_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<!-- 收款状态修改弹窗 -->
<div class="modal-overlay" id="payModal">
    <div class="modal modal-sm">
        <div class="modal-header"><h3 class="modal-title">修改收款状态</h3><button class="modal-close" onclick="closeModal('payModal')">&times;</button></div>
        <form method="post" action="outstock_view.php?id=<?=$id?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_pay">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">当前状态</label>
                    <span class="badge badge-<?=$payBadge[$outstock['pay_status']??'unpaid']?>"><?=$payLabels[$outstock['pay_status']??'unpaid']?></span>
                </div>
                <div class="form-group">
                    <label class="form-label">新状态 <span class="required">*</span></label>
                    <select name="new_pay_status" class="form-control" required>
                        <option value="">请选择</option>
                        <option value="unpaid" <?=$outstock['pay_status']=='unpaid'?'disabled':''?>>未付款</option>
                        <option value="paid_deposit" <?=$outstock['pay_status']=='paid_deposit'?'disabled':''?>>已付定金</option>
                        <option value="paid_full" <?=$outstock['pay_status']=='paid_full'?'disabled':''?>>已付全款</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">备注</label>
                    <textarea name="pay_remark" class="form-control" rows="3" placeholder="请输入变更原因或备注"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('payModal')">取消</button>
                <button type="submit" class="btn btn-primary">确认修改</button>
            </div>
        </form>
    </div>
</div>

<div id="printContent" style="display:none;"></div>

<?php $companyName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn() ?: SITE_NAME;
$companyAddress = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_address'")->fetchColumn() ?: '';
$companyPhone = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_phone'")->fetchColumn() ?: '';
?>
<script>
function showPayModal(){ openModal('payModal'); }

// 数字转中文大写金额（用于 {total_amount_cn} 变量）
function numToCny(num) {
    if (num === null || num === undefined || isNaN(num)) return '';
    num = Math.abs(Number(num));
    if (num === 0) return '零元整';
    var upper = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    var unit = ['', '拾', '佰', '仟'];
    var bigUnit = ['', '万', '亿', '万亿'];
    var s = num.toFixed(2);
    var parts = s.split('.');
    var intPart = parts[0];
    var decPart = parts[1];
    var intStr = '';
    var len = intPart.length;
    for (var i = 0; i < len; i++) {
        var n = parseInt(intPart.charAt(i), 10);
        var posInGroup = (len - 1 - i) % 4;
        var groupIdx = Math.floor((len - 1 - i) / 4);
        var u = unit[posInGroup];
        var bu = bigUnit[groupIdx];
        if (n !== 0) {
            // 单位：每段内为拾/佰/仟，每段末尾（即 u 为空时）追加大单位 万/亿
            intStr += upper[n] + u + (u === '' ? bu : '');
        } else {
            // 补零：仅在每段中间位置补，且不与前一个零连续
            if (intStr.length > 0 && intStr.slice(-1) !== '零' && posInGroup !== 0) {
                intStr += '零';
            }
        }
    }
    intStr = intStr.replace(/零+$/, '').replace(/零+/g, '零');
    var result = intStr + '元';
    var jiao = parseInt(decPart.charAt(0), 10);
    var fen = parseInt(decPart.charAt(1), 10);
    if (jiao === 0 && fen === 0) {
        result += '整';
    } else {
        if (jiao > 0) result += upper[jiao] + '角';
        else if (fen > 0) result += '零';
        if (fen > 0) result += upper[fen] + '分';
    }
    return result;
}

// 商品明细数据（供打印模板使用）
var printItems = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

// 智能生成商品明细行：根据模板<thead>列名自动匹配数据字段
function buildItemsHtml(items, templateHtml) {
    if (!items || !items.length) return '<tr><td colspan="10">暂无明细数据</td></tr>';
    // 列名 → 数据字段 映射（支持产品项目方案单：产品图片/描述/编码/产品名称/价格）
    var colMap = {
        '序号':'__idx__','SKU':'sku','编码':'sku','商品名称':'product_name','产品名称':'product_name',
        '规格':'spec','单位':'unit_name','数量':'quantity','单价':'price','价格':'price',
        '金额':'amount','备注':'__remark__',
        '产品图片':'__image__','描述':'__description__'
    };
    // 从模板中提取列名（支持带 style 属性的 <th>）
    var theadMatch = templateHtml.match(/<thead>([\s\S]*?)<\/thead>/);
    var columns = [];
    if (theadMatch) {
        var thRe = /<th[^>]*>(.*?)<\/th>/g, m;
        while ((m = thRe.exec(theadMatch[1])) !== null) {
            var col = m[1].trim();
            if (col) columns.push(col);
        }
    }
    if (!columns.length) columns = ['序号','商品名称','规格','单位','数量','单价','金额','备注'];
    // 生成行
    var rows = '';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        rows += '<tr>';
        for (var c = 0; c < columns.length; c++) {
            var field = colMap[columns[c]] || '';
            if (field === '__idx__') {
                rows += '<td>' + (i + 1) + '</td>';
            } else if (field === '__remark__') {
                rows += '<td>' + (item.remark || '') + '</td>';
            } else if (field === '__image__') {
                if (item.image_base64) {
                    rows += '<td><img src="' + item.image_base64 + '" style="max-width:100px;max-height:75px;object-fit:contain;" alt=""></td>';
                } else if (item.product_image) {
                    rows += '<td><img src="../../' + item.product_image + '" style="max-width:100px;max-height:75px;object-fit:contain;" alt=""></td>';
                } else {
                    rows += '<td style="color:#999;">-</td>';
                }
            } else if (field === '__description__') {
                var desc = (item.product_description || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                rows += '<td style="text-align:left;vertical-align:top;line-height:1.5;white-space:pre-line;">' + desc + '</td>';
            } else if (field && item[field] !== undefined && item[field] !== null) {
                var val = String(item[field]);
                if (field === 'price' || field === 'amount') val = '¥' + Number(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                var tdStyle = '';
                if (columns[c] === '产品名称' || columns[c] === '商品名称') {
                    tdStyle = ' style="text-align:left;word-break:break-all;"';
                }
                rows += '<td' + tdStyle + '>' + val + '</td>';
            } else {
                rows += '<td></td>';
            }
        }
        rows += '</tr>';
    }
    return rows;
}

var allTemplates = <?= json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var currentTpl = <?= $tpl ? json_encode($tpl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : 'null' ?>;
var printData = {
    bill_no: '<?= js_escape($outstock['bill_no']) ?>',
    bill_date: '<?= js_escape($outstock['outstock_date']) ?>',
    customer_name: '<?= js_escape($outstock['customer_name']??'') ?>',
    customer_phone: '<?= js_escape($outstock['customer_phone']??'') ?>',
    customer_address: '<?= js_escape($outstock['customer_address']??'') ?>',
    customer_contact: '<?= js_escape($outstock['customer_contact']??'') ?>',
    warehouse_name: '<?= js_escape($outstock['warehouse_name']??'') ?>',
    employee_name: '<?= js_escape($outstock['employee_name']??'') ?>',
    employee_phone: '<?= js_escape($outstock['employee_phone']??'') ?>',
    total_amount: '¥<?= format_money($outstock['total_amount']) ?>',
    total_amount_cn: '',
    remark: '<?= js_escape($outstock['remark']??'') ?>',
    user_name: '<?= js_escape(get_user_name()) ?>',
    company_name: '<?= js_escape($companyName ?? SITE_NAME) ?>',
    company_address: '<?= js_escape($companyAddress) ?>',
    company_phone: '<?= js_escape($companyPhone) ?>',
    tracking_no: '<?= js_escape($trackingNoHtml) ?>'
};

function selectTpl(id) {
    for (var i = 0; i < allTemplates.length; i++) {
        if (allTemplates[i].id == id) { currentTpl = allTemplates[i]; break; }
    }
    renderPrintContent();
}
function renderPrintContent() {
    if (!currentTpl) return;
    // 自动从 total_amount 提取数字并转为中文大写（用于 {total_amount_cn}）
    var rawAmount = parseFloat(String(printData.total_amount).replace(/[¥,\s]/g, ''));
    if (!isNaN(rawAmount)) {
        printData.total_amount_cn = numToCny(rawAmount);
    } else {
        printData.total_amount_cn = '';
    }
    var html = currentTpl.content;
    for (var k in printData) {
        html = html.replace(new RegExp('\\{'+k+'\\}', 'g'), printData[k]);
    }
    html = html.replace(/\{items\}/g, buildItemsHtml(printItems, currentTpl.content));
    document.getElementById('printContent').innerHTML = html;
}
if (currentTpl) renderPrintContent();

<?php if (isset($_GET['print']) && $_GET['print']=='1'): ?>
// 来自打印按钮，高亮提示用户可以模版选择和打印
setTimeout(function(){ renderPrintContent(); }, 300);
<?php endif; ?>
function printOutstock() {
    if (!currentTpl) return;
    renderPrintContent();
    var win = window.open('', '_blank', 'width=900,height=600');
    win.document.write('<html><head><title>销售出库单打印</title>');
    win.document.write('<style>body{font-family:SimSun,Arial;padding:10px;color:#000;background:#fff;margin:0;}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;}table{border-collapse:collapse;width:100%;}table th,table td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;vertical-align:middle;}table th{font-weight:bold;}@media print{body{padding:0;margin:0;}.noprint{display:none;}}</style>');
    win.document.write('</head><body>');
    win.document.write(document.getElementById('printContent').innerHTML);
    win.document.write('</body></html>');
    win.document.close();
    setTimeout(function(){win.print();}, 500);
}
</script>

<?php
// 处理收款状态变更
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'change_pay') {
    csrf_verify();
    $newStatus = $_POST['new_pay_status'] ?? '';
    $payRemark = trim($_POST['pay_remark'] ?? '');
    $oldStatus = $outstock['pay_status'] ?? 'unpaid';

    if ($newStatus && $newStatus !== $oldStatus) {
        $now = date('Y-m-d H:i:s');
        $userName = get_user_name();
        try {
            $pdo->prepare("UPDATE sales_outstocks SET pay_status=?, pay_remark=?, pay_updated_at=? WHERE id=?")->execute([$newStatus, $payRemark, $now, $id]);
            $pdo->prepare("INSERT INTO sales_outstock_paylogs (outstock_id, from_status, to_status, remark, user_name, created_at) VALUES (?,?,?,?,?,?)")->execute([$id, $oldStatus, $newStatus, $payRemark, $userName, $now]);
            add_log(get_user_id(), 'update', 'sales_outstock', "变更收款状态: {$outstock['bill_no']} {$oldStatus} -> {$newStatus}");
            redirect("outstock_view.php?id=$id");
        } catch (Exception $e) {
            error_log('Outstock paylog error: ' . $e->getMessage());
            echo '<div class="alert alert-danger">保存失败，请查看系统日志</div>';
        }
    }
}

require_once __DIR__ . '/../../includes/footer.php';
?>
