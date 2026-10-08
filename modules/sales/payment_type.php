<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_contract');
// 付款方式是合同的基础配置，条款模板一改会影响所有引用它的合同，
// 所以新增/编辑/删除只允许管理员。放在 POST 处理之前做页面级拦截，
// 下面 save / delete 两个分支一并覆盖，非管理员连表单都看不到
if (get_user_role() !== 'admin') {
    die('无权限：合同付款方式仅管理员可维护。<a href="contract.php">返回合同列表</a>');
}
$pdo = getDB();
run_migrations();

$err = '';

// ===== 新增 / 编辑 / 删除 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = intval($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $code        = trim($_POST['code'] ?? '');
        $template    = trim($_POST['template'] ?? '');
        $needDeposit = isset($_POST['need_deposit']) ? 1 : 0;
        $needDays    = isset($_POST['need_days']) ? 1 : 0;
        $sort        = intval($_POST['sort'] ?? 0);

        if ($name === '') $err = '请填写付款方式名称';
        elseif (!$id && $code === '') $err = '请填写方式代码';
        elseif ($code !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $code)) $err = '方式代码只能包含字母、数字、下划线';

        if (!$err) {
            try {
                if ($id) {
                    // code 是合同表的外联键，编辑时不允许改动，避免历史合同对不上
                    $pdo->prepare("UPDATE contract_payment_types SET name=?, template=?, need_deposit=?, need_days=?, sort=? WHERE id=?")
                        ->execute([$name, $template, $needDeposit, $needDays, $sort, $id]);
                    add_log(get_user_id(), 'update', 'contract_payment_type', "编辑付款方式: $name");
                } else {
                    // 代码重复则提示，避免 INSERT 直接抛异常
                    $chk = $pdo->prepare("SELECT id FROM contract_payment_types WHERE code=? LIMIT 1");
                    $chk->execute([$code]);
                    if ($chk->fetch()) {
                        $err = "方式代码「$code」已存在，请换一个";
                    } else {
                        $pdo->prepare("INSERT INTO contract_payment_types (name, code, template, need_deposit, need_days, sort, status) VALUES (?,?,?,?,?,?,1)")
                            ->execute([$name, $code, $template, $needDeposit, $needDays, $sort]);
                        add_log(get_user_id(), 'create', 'contract_payment_type', "新增付款方式: $name");
                    }
                }
                if (!$err) { flash_set('已保存', 'success'); redirect('payment_type.php'); }
            } catch (Exception $e) {
                error_log('Payment type save error: ' . $e->getMessage());
                $err = '保存失败：' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM contract_payment_types WHERE id=?");
        $st->execute([$id]);
        $pt = $st->fetch();
        if (!$pt) {
            $err = '付款方式不存在';
        } else {
            // 合同表存的是 code，删除后历史合同会找不到对应方式，故被引用时禁止删除
            $used = $pdo->prepare("SELECT COUNT(*) FROM sales_contracts WHERE payment_type=?");
            $used->execute([$pt['code']]);
            if (intval($used->fetchColumn()) > 0) {
                $err = "「{$pt['name']}」已被合同使用，不能删除；如需停用请先把相关合同改为其他付款方式";
            } else {
                $pdo->prepare("DELETE FROM contract_payment_types WHERE id=?")->execute([$id]);
                add_log(get_user_id(), 'delete', 'contract_payment_type', "删除付款方式: {$pt['name']}");
                flash_set('已删除', 'success');
                redirect('payment_type.php');
            }
        }
    }
}

$editId = intval($_GET['edit'] ?? 0);
$edit   = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM contract_payment_types WHERE id=?");
    $st->execute([$editId]);
    $edit = $st->fetch();
}
$list = $pdo->query("SELECT * FROM contract_payment_types ORDER BY sort, id")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-money-bill-wave"></i> 合同付款方式</h1>
    <a href="contract.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回合同列表</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card"><div class="card-header"><strong><?= $edit ? '编辑' : '新增' ?>付款方式</strong></div><div class="card-body">
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">名称 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" placeholder="如：全款发货">
        </div>
        <div class="form-group">
            <label class="form-label">方式代码</label>
            <?php if ($edit): ?>
            <div class="form-control" style="background:var(--gray-100);"><?= htmlspecialchars($edit['code']) ?></div>
            <small style="color:var(--gray-500);">已被合同引用，不支持修改</small>
            <?php else: ?>
            <input type="text" name="code" class="form-control" value="" placeholder="如：full / deposit" pattern="[a-zA-Z0-9_]+">
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label class="form-label">排序</label>
            <input type="number" name="sort" class="form-control" value="<?= intval($edit['sort'] ?? 0) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">需要的参数</label>
            <div style="padding-top:6px;">
                <label style="margin-right:14px;"><input type="checkbox" name="need_deposit" value="1" <?= !empty($edit['need_deposit']) ? 'checked' : '' ?>> 定金金额</label>
                <label><input type="checkbox" name="need_days" value="1" <?= !empty($edit['need_days']) ? 'checked' : '' ?>> 备货周期</label>
            </div>
            <small style="color:var(--gray-500);">勾选后，合同表单会显示对应输入框</small>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">条款模板 <span class="required">*</span></label>
        <textarea name="template" class="form-control" rows="3" required placeholder="甲方应支付合同全款 ¥{total_amount}（大写：{total_amount_cn}）。全款到达乙方指定账户后 {prep_days} 个工作日内备货完毕并安排发货。"><?= htmlspecialchars($edit['template'] ?? '') ?></textarea>
        <small style="color:var(--gray-500);">
            可用变量：<code>{total_amount}</code> <code>{total_amount_cn}</code> <code>{deposit_amount}</code> <code>{deposit_amount_cn}</code>
            <code>{balance_amount}</code> <code>{balance_amount_cn}</code> <code>{prep_days}</code>
        </small>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存</button>
    <?php if ($edit): ?><a href="payment_type.php" class="btn btn-outline">取消编辑</a><?php endif; ?>
</form>
</div></div>

<div class="card"><div class="card-header"><strong>已有付款方式</strong></div>
<div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>排序</th><th>名称</th><th>代码</th><th>参数</th><th>条款模板</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $pt): ?>
<tr>
    <td><?= intval($pt['sort']) ?></td>
    <td><strong><?= htmlspecialchars($pt['name']) ?></strong></td>
    <td><code><?= htmlspecialchars($pt['code']) ?></code></td>
    <td><?= ($pt['need_deposit'] ? '定金金额 ' : '') . ($pt['need_days'] ? '备货周期' : '') ?: '-' ?></td>
    <td style="text-align:left;max-width:420px;"><small><?= htmlspecialchars(mb_substr($pt['template'] ?: '', 0, 60)) ?><?= mb_strlen($pt['template'] ?: '') > 60 ? '…' : '' ?></small></td>
    <td>
        <div class="table-actions">
            <a href="payment_type.php?edit=<?=$pt['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定删除该付款方式吗？');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$pt['id']?>">
                <button type="submit" class="btn btn-sm btn-danger">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-money-bill-wave"></i><p>暂无付款方式</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
