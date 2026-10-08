<?php
/**
 * 库存校验：用库存流水重算应有库存，与当前库存比对，发现历史脏数据并提供修正入口
 * 只读报告为默认；修正需逐条勾选后提交，且只把库存改为「流水累计值」
 *
 * 关键点：修正时补写的调整流水（bill_type='repair'）不计入「流水累计」。
 * 否则每修正一次，流水累计也同步增加同样的差异，导致「修完差异依旧不变、库存只减不增」。
 */
// 注意：auth.php 只做会话/鉴权，不输出任何内容。
// POST 处理必须放在 header.php 之前完成——header.php 会输出整页 HTML，
// 输出之后再 redirect 在部分主机环境下会失败，表现为点击按钮后一片空白。
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('check_manage');
$pdo = getDB();
run_migrations();

// ==================== 修正：把库存重置为流水累计值 ====================
// 共用：把指定 (商品,仓库) 的库存改为流水累计值，并补一条调整流水
function repair_apply(PDO $pdo, array $pairs, string $remark): int {
    $fixed = 0;
    $upsert = $pdo->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, created_at, updated_at)
            VALUES (?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE quantity=?, updated_at=NOW()");
    $addLog = $pdo->prepare("INSERT INTO inventory_logs (product_id, warehouse_id, change_quantity, current_quantity, type, bill_no, bill_type, user_id, remark, created_at)
            VALUES (?,?,?,?,'adjust',?,'repair',?,?,NOW())");
    $billNo = 'SYS-FIX-' . date('Ymd');
    $uid = get_user_id();
    foreach ($pairs as $p) {
        $pid = intval($p['pid'] ?? 0);
        $wid = intval($p['wid'] ?? 0);
        if ($pid <= 0 || $wid <= 0) continue;
        $logQty = floatval($p['log_qty'] ?? 0);
        $curQty = floatval($p['cur_qty'] ?? 0);
        $diff = $logQty - $curQty;
        if (abs($diff) < 0.000001) continue;
        $upsert->execute([$pid, $wid, $logQty, $logQty]);
        $addLog->execute([$pid, $wid, $diff, $logQty, $billNo, $uid, $remark]);
        $fixed++;
    }
    return $fixed;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['fix', 'fix_all'], true)) {
    // 自行校验 CSRF：失败时回到本页并提示，避免 csrf_verify() 直接 die 出一个没有导航的“空白页”
    if (!csrf_is_valid($_POST['_csrf_token'] ?? '')) {
        csrf_regenerate();
        flash_set('页面已过期（安全验证失败），数据未被修改，请重新操作一次', 'warning');
        redirect('repair.php');
    }

    if ($action === 'fix') {
        // ---- 按勾选修正 ----
        $keys = $_POST['fix_key'] ?? [];
        $fixed = 0;
        if (!empty($keys)) {
            $pdo->beginTransaction();
            try {
                $logStmt = $pdo->prepare("SELECT COALESCE(SUM(change_quantity),0) FROM inventory_logs WHERE product_id=? AND warehouse_id=? AND (bill_type IS NULL OR bill_type <> 'repair')");
                $curStmt = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id=? AND warehouse_id=?");
                $pairs = [];
                foreach ($keys as $key) {
                    $parts = explode(':', $key);
                    $pid = intval($parts[0] ?? 0);
                    $wid = intval($parts[1] ?? 0);
                    if ($pid <= 0 || $wid <= 0) continue;
                    $logStmt->execute([$pid, $wid]);
                    $curStmt->execute([$pid, $wid]);
                    $pairs[] = [
                        'pid' => $pid,
                        'wid' => $wid,
                        'log_qty' => floatval($logStmt->fetchColumn()),
                        'cur_qty' => floatval($curStmt->fetchColumn()),
                    ];
                }
                $fixed = repair_apply($pdo, $pairs, '库存校验修正：按流水重算');
                add_log(get_user_id(), 'fix', 'inventory', "库存校验修正 {$fixed} 条记录（按流水重算）");
                $pdo->commit();
                flash_set("已修正 {$fixed} 条库存记录（按流水累计值重算）", 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('inventory repair error: ' . $e->getMessage());
                flash_set('修正失败：' . $e->getMessage());
            }
        } else {
            flash_set('请先勾选需要修正的记录');
        }
        redirect('repair.php');
    }

    if ($action === 'fix_all') {
        // ---- 一键修正全部：全量重算，不受列表 LIMIT 限制 ----
        set_time_limit(0); // 数据量大时避免 30 秒超时导致白屏
        $fixed = 0;
        $noLog = 0;
        $pdo->beginTransaction();
        try {
            // 一次性取出所有「有流水」组合的流水累计值与当前库存（不再逐条查询，速度快很多）
            $rows = $pdo->query("SELECT t.pid, t.wid, t.log_qty, COALESCE(i.quantity, 0) as cur_qty
                FROM (SELECT l.product_id as pid, l.warehouse_id as wid, SUM(l.change_quantity) as log_qty
                      FROM inventory_logs l
                      WHERE l.bill_type IS NULL OR l.bill_type <> 'repair'
                      GROUP BY l.product_id, l.warehouse_id) t
                LEFT JOIN inventory i ON i.product_id=t.pid AND i.warehouse_id=t.wid")->fetchAll();
            $pairs = [];
            foreach ($rows as $r) {
                $pairs[] = [
                    'pid' => $r['pid'],
                    'wid' => $r['wid'],
                    'log_qty' => $r['log_qty'],
                    'cur_qty' => $r['cur_qty'],
                ];
            }
            // 统计「有库存但完全没有流水」的组合：这类不自动清零，避免把有效库存抹掉
            $noLog = intval($pdo->query("SELECT COUNT(*) FROM inventory i
                LEFT JOIN inventory_logs l ON l.product_id=i.product_id AND l.warehouse_id=i.warehouse_id
                    AND (l.bill_type IS NULL OR l.bill_type <> 'repair')
                WHERE l.product_id IS NULL")->fetchColumn());

            $fixed = repair_apply($pdo, $pairs, '库存校验一键修正：按流水重算');
            add_log(get_user_id(), 'fix_all', 'inventory', "库存校验一键修正 {$fixed} 条记录（按流水重算）");
            $pdo->commit();
            $msg = "一键修正完成：共修正 {$fixed} 条库存记录（按流水累计值重算）";
            if ($noLog > 0) {
                $msg .= "；另有 {$noLog} 条库存完全没有流水，已跳过（不会清零，请人工核查）";
            }
            flash_set($msg, $fixed > 0 ? 'success' : 'info');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('inventory repair all error: ' . $e->getMessage());
            flash_set('一键修正失败：' . $e->getMessage());
        }
        redirect('repair.php');
    }
}

// ==================== POST 处理完毕后才输出页面 ====================
require_once __DIR__ . '/../../includes/header.php';

// ==================== 1) 库存与流水累计不一致 ====================
$diffs = $pdo->query("SELECT i.product_id, i.warehouse_id, i.quantity as inv_qty,
        COALESCE(l.log_qty, 0) as log_qty,
        (i.quantity - COALESCE(l.log_qty, 0)) as diff_qty,
        p.name as product_name, p.sku, w.name as warehouse_name
    FROM inventory i
    LEFT JOIN (SELECT product_id, warehouse_id, SUM(change_quantity) as log_qty
               FROM inventory_logs
               WHERE bill_type IS NULL OR bill_type <> 'repair'
               GROUP BY product_id, warehouse_id) l
      ON l.product_id = i.product_id AND l.warehouse_id = i.warehouse_id
    LEFT JOIN products p ON p.id = i.product_id
    LEFT JOIN warehouses w ON w.id = i.warehouse_id
    WHERE ABS(i.quantity - COALESCE(l.log_qty, 0)) > 0.000001
    ORDER BY ABS(i.quantity - COALESCE(l.log_qty, 0)) DESC
    LIMIT 500")->fetchAll();

// ==================== 2) 有流水但没有库存记录 ====================
$missing = $pdo->query("SELECT l.product_id, l.warehouse_id, SUM(l.change_quantity) as log_qty,
        p.name as product_name, p.sku, w.name as warehouse_name
    FROM inventory_logs l
    LEFT JOIN inventory i ON i.product_id = l.product_id AND i.warehouse_id = l.warehouse_id
    LEFT JOIN products p ON p.id = l.product_id
    LEFT JOIN warehouses w ON w.id = l.warehouse_id
    WHERE i.product_id IS NULL AND (l.bill_type IS NULL OR l.bill_type <> 'repair')
    GROUP BY l.product_id, l.warehouse_id, p.name, p.sku, w.name
    LIMIT 200")->fetchAll();

// ==================== 3) 负库存 ====================
$negatives = $pdo->query("SELECT i.product_id, i.warehouse_id, i.quantity,
        p.name as product_name, p.sku, w.name as warehouse_name
    FROM inventory i
    LEFT JOIN products p ON p.id = i.product_id
    LEFT JOIN warehouses w ON w.id = i.warehouse_id
    WHERE i.quantity < -0.000001
    ORDER BY i.quantity ASC LIMIT 200")->fetchAll();

// ==================== 4) 疑似重复流水（同一单据同一商品同一仓库多条） ====================
$duplicates = $pdo->query("SELECT l.bill_no, l.bill_type, l.product_id, l.warehouse_id, COUNT(*) as cnt, SUM(l.change_quantity) as total_qty,
        p.name as product_name, p.sku
    FROM inventory_logs l
    LEFT JOIN products p ON p.id = l.product_id
    WHERE l.bill_no IS NOT NULL AND l.bill_no <> ''
    GROUP BY l.bill_no, l.bill_type, l.product_id, l.warehouse_id, p.name, p.sku
    HAVING cnt > 1
    ORDER BY cnt DESC LIMIT 200")->fetchAll();
?>
<?php flash_show(); ?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-stethoscope"></i> 库存校验</h1>
    <div class="page-actions">
        <a class="btn btn-outline" href="stock.php"><i class="fa-solid fa-boxes-stacked"></i> 库存查询</a>
        <a class="btn btn-outline" href="logs.php"><i class="fa-solid fa-clock-rotate-left"></i> 库存流水</a>
    </div>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    本页用「库存流水」重算应有库存，与当前库存比对，用于发现历史脏数据。
    <strong>修正只会把库存改为流水累计值</strong>，请确认流水完整后再执行；若流水本身缺失，请先补录单据或做盘点。
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:24px;font-weight:bold;color:<?=count($diffs)?'var(--danger)':'var(--success)'?>;"><?=count($diffs)?></div>
        <div style="font-size:13px;color:var(--gray-600);">库存与流水不符</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:24px;font-weight:bold;color:<?=count($missing)?'var(--danger)':'var(--success)'?>;"><?=count($missing)?></div>
        <div style="font-size:13px;color:var(--gray-600);">有流水无库存记录</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:24px;font-weight:bold;color:<?=count($negatives)?'var(--danger)':'var(--success)'?>;"><?=count($negatives)?></div>
        <div style="font-size:13px;color:var(--gray-600);">负库存</div>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;">
        <div style="font-size:24px;font-weight:bold;color:<?=count($duplicates)?'var(--warning)':'var(--success)'?>;"><?=count($duplicates)?></div>
        <div style="font-size:13px;color:var(--gray-600);">疑似重复流水</div>
    </div></div>
</div>

<form method="post" onsubmit='if(!confirm("确定按库存流水累计值修正勾选的记录吗？")) return false; var b=this.querySelector("button[type=submit]"); b.disabled=true; return true;'>
<?= csrf_field() ?><input type="hidden" name="action" value="fix">

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-not-equal"></i> 库存与流水不符（可修正）</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container"><table>
        <thead><tr><th style="width:40px;"><input type="checkbox" onclick="var on=this.checked;document.querySelectorAll('.fix-check').forEach(function(c){c.checked=on;});"></th>
            <th>商品</th><th>仓库</th><th>当前库存</th><th>流水累计</th><th>差异</th></tr></thead>
        <tbody>
        <?php if ($diffs): foreach ($diffs as $d): ?>
        <tr>
            <td><input type="checkbox" class="fix-check" name="fix_key[]" value="<?=$d['product_id']?>:<?=$d['warehouse_id']?>"></td>
            <td><?=htmlspecialchars($d['product_name'] ?: ('#' . $d['product_id']))?> <small style="color:var(--gray-500);"><?=htmlspecialchars($d['sku'] ?: '')?></small></td>
            <td><?=htmlspecialchars($d['warehouse_name'] ?: ('#' . $d['warehouse_id']))?></td>
            <td><?=floatval($d['inv_qty'])?></td>
            <td><?=floatval($d['log_qty'])?></td>
            <td style="color:var(--danger);font-weight:bold;"><?=floatval($d['diff_qty'])?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-circle-check"></i><p>库存与流水完全一致</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-box-open"></i> 有流水但无库存记录（可修正）</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container"><table>
        <thead><tr><th style="width:40px;"></th><th>商品</th><th>仓库</th><th>流水累计</th><th>处理建议</th></tr></thead>
        <tbody>
        <?php if ($missing): foreach ($missing as $m): ?>
        <tr>
            <td><input type="checkbox" class="fix-check" name="fix_key[]" value="<?=$m['product_id']?>:<?=$m['warehouse_id']?>"></td>
            <td><?=htmlspecialchars($m['product_name'] ?: ('#' . $m['product_id']))?> <small style="color:var(--gray-500);"><?=htmlspecialchars($m['sku'] ?: '')?></small></td>
            <td><?=htmlspecialchars($m['warehouse_name'] ?: ('#' . $m['warehouse_id']))?></td>
            <td><?=floatval($m['log_qty'])?></td>
            <td style="font-size:12px;color:var(--gray-600);">勾选后按流水累计值补建库存记录</td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-circle-check"></i><p>无异常</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<?php if ($diffs || $missing): ?>
<div class="card">
    <div class="card-body">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-wrench"></i> 修正勾选记录（按流水重算）</button>
        <span style="margin-left:8px;font-size:12px;color:var(--gray-600);">修正会补一条调整流水（该流水不计入流水累计），保证修正后差异归零。</span>
    </div>
</div>
<?php endif; ?>
</form>

<?php if ($diffs || $missing): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-bolt"></i> 一键修正全部（无需勾选）</h3></div>
    <div class="card-body">
        <form method="post" onsubmit='if(!confirm("即将按「库存流水累计值」重算并修正全部异常库存，包括列表未显示完的记录，并补一条调整流水。\n\n不会处理「有库存但完全没有流水」的记录（不会清零）。\n\n建议先备份数据库，确定继续吗？")) return false; var b=this.querySelector("button[type=submit]"); b.disabled=true; b.innerHTML="<i class=\"fa-solid fa-spinner fa-spin\"></i> 执行中，请勿关闭..."; return true;'>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="fix_all">
            <button type="submit" class="btn btn-danger">
                <i class="fa-solid fa-bolt"></i> 一键修正全部（按流水重算）
            </button>
            <span style="margin-left:8px;font-size:12px;color:var(--gray-600);">
                直接处理全部「库存与流水不符」和「有流水无库存」的记录，不受列表显示条数限制；数据量大时可能需要几十秒，期间请勿关闭页面。
            </span>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-triangle-exclamation"></i> 负库存</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container"><table>
        <thead><tr><th>商品</th><th>仓库</th><th>库存</th><th>处理建议</th></tr></thead>
        <tbody>
        <?php if ($negatives): foreach ($negatives as $n): ?>
        <tr>
            <td><?=htmlspecialchars($n['product_name'] ?: ('#' . $n['product_id']))?> <small style="color:var(--gray-500);"><?=htmlspecialchars($n['sku'] ?: '')?></small></td>
            <td><?=htmlspecialchars($n['warehouse_name'] ?: ('#' . $n['warehouse_id']))?></td>
            <td style="color:var(--danger);font-weight:bold;"><?=floatval($n['quantity'])?></td>
            <td style="font-size:12px;color:var(--gray-600);">核查是否漏录入库单；确认后做盘点调整</td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-circle-check"></i><p>无负库存</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-clone"></i> 疑似重复流水</h3></div>
    <div class="card-body" style="padding:0;"><div class="table-container"><table>
        <thead><tr><th>单据号</th><th>类型</th><th>商品</th><th>条数</th><th>合计变动</th><th>处理建议</th></tr></thead>
        <tbody>
        <?php if ($duplicates): foreach ($duplicates as $d): ?>
        <tr>
            <td><strong><?=htmlspecialchars($d['bill_no'])?></strong></td>
            <td><?=htmlspecialchars($d['bill_type'])?></td>
            <td><?=htmlspecialchars($d['product_name'] ?: ('#' . $d['product_id']))?> <small style="color:var(--gray-500);"><?=htmlspecialchars($d['sku'] ?: '')?></small></td>
            <td><span class="badge badge-warning"><?=$d['cnt']?></span></td>
            <td><?=floatval($d['total_qty'])?></td>
            <td style="font-size:12px;color:var(--gray-600);">确认单据是否被重复确认；如需冲正请撤销单据而非删除流水</td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-circle-check"></i><p>无重复流水</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
