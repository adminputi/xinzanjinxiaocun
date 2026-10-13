<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) { ob_start(); }

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration.php';
require_permission('sales_contract');
// 与「付款方式」一致：合同的基础配置，改了会影响所有引用它的合同，只管理员能维护。
// 放在 POST 处理之前做页面级拦截，下面 save / delete 两个分支一并覆盖
if (get_user_role() !== 'admin') {
    die('无权限：乙方签章预设仅管理员可维护。<a href="contract.php">返回合同列表</a>');
}
$pdo = getDB();
run_migrations();

$err = '';

// ===== 新增 / 编辑 / 删除 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = intval($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $content   = trim($_POST['content'] ?? '');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $sort      = intval($_POST['sort'] ?? 0);

        if ($name === '')     $err = '请填写预设名称（下拉里显示用）';
        elseif ($content === '') $err = '请填写签章文本';

        if (!$err) {
            try {
                if ($id) {
                    $pdo->prepare("UPDATE contract_sign_presets SET name=?, content=?, is_default=?, sort=? WHERE id=?")
                        ->execute([$name, $content, $isDefault, $sort, $id]);
                    add_log(get_user_id(), 'update', 'contract_sign_preset', "编辑乙方签章预设: $name");
                } else {
                    $pdo->prepare("INSERT INTO contract_sign_presets (name, content, is_default, sort, status) VALUES (?,?,?,?,1)")
                        ->execute([$name, $content, $isDefault, $sort]);
                    add_log(get_user_id(), 'create', 'contract_sign_preset', "新增乙方签章预设: $name");
                }
                // 默认预设全表只能有一个：勾了这条就把其他条的标记清掉
                if ($isDefault) {
                    $curId = $id ?: intval($pdo->lastInsertId());
                    $pdo->prepare("UPDATE contract_sign_presets SET is_default=0 WHERE id<>?")->execute([$curId]);
                } else {
                    // 取消勾选后若全表已无默认，也不用补——没有默认就只是新建合同不自动带出而已
                }
                flash_set('已保存', 'success');
                redirect('sign_preset.php');
            } catch (Exception $e) {
                error_log('Sign preset save error: ' . $e->getMessage());
                $err = '保存失败：' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM contract_sign_presets WHERE id=?");
        $st->execute([$id]);
        $sp = $st->fetch();
        if (!$sp) {
            $err = '预设不存在';
        } else {
            // 预设内容是「复制」进合同的（合同表存的是文本快照，不是外键），
            // 所以删掉不影响历史合同，直接删即可
            $pdo->prepare("DELETE FROM contract_sign_presets WHERE id=?")->execute([$id]);
            add_log(get_user_id(), 'delete', 'contract_sign_preset', "删除乙方签章预设: {$sp['name']}");
            flash_set('已删除', 'success');
            redirect('sign_preset.php');
        }
    }
}

$editId = intval($_GET['edit'] ?? 0);
$edit   = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM contract_sign_presets WHERE id=?");
    $st->execute([$editId]);
    $edit = $st->fetch();
}
$list = $pdo->query("SELECT * FROM contract_sign_presets ORDER BY sort, id")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-stamp"></i> 乙方签章预设</h1>
    <a href="contract.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> 返回合同列表</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card"><div class="card-header"><strong><?= $edit ? '编辑' : '新增' ?>签章预设</strong></div><div class="card-body">
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">预设名称 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" placeholder="如：工行对公账户">
            <small style="color:var(--gray-500);">只在合同表单的下拉里显示，不进合同正文</small>
        </div>
        <div class="form-group">
            <label class="form-label">排序</label>
            <input type="number" name="sort" class="form-control" value="<?= intval($edit['sort'] ?? 0) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">设为默认</label>
            <div style="padding-top:6px;">
                <label><input type="checkbox" name="is_default" value="1" <?= !empty($edit['is_default']) ? 'checked' : '' ?>> 新建合同时自动带出</label>
            </div>
            <small style="color:var(--gray-500);">全表只能有一个默认，勾这条会自动取消其他条</small>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">签章文本 <span class="required">*</span></label>
        <textarea name="content" class="form-control" rows="5" required placeholder="乙方（签章）：
账号号码：xxxx xxxx xxxx
开户行：xxx银行xx支行
户名：xxx
联系电话：xxx"><?= htmlspecialchars($edit['content'] ?? '') ?></textarea>
        <small style="color:var(--gray-500);">整块文本，支持换行。在合同编辑页选了之后会整体填进「签章 · 乙方」，填完仍可手工修改。</small>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> 保存</button>
    <?php if ($edit): ?><a href="sign_preset.php" class="btn btn-outline">取消编辑</a><?php endif; ?>
</form>
</div></div>

<div class="card"><div class="card-header"><strong>已有预设</strong></div>
<div class="card-body" style="padding:0;"><div class="table-container">
<table>
<thead><tr><th>排序</th><th>名称</th><th>签章文本</th><th>默认</th><th>操作</th></tr></thead>
<tbody>
<?php if ($list): foreach ($list as $sp): ?>
<tr>
    <td><?= intval($sp['sort']) ?></td>
    <td><strong><?= htmlspecialchars($sp['name']) ?></strong></td>
    <td style="text-align:left;max-width:460px;white-space:pre-wrap;"><small><?= htmlspecialchars(mb_substr($sp['content'] ?: '', 0, 80)) ?><?= mb_strlen($sp['content'] ?: '') > 80 ? '…' : '' ?></small></td>
    <td><?= $sp['is_default'] ? '<span class="badge badge-success">默认</span>' : '-' ?></td>
    <td>
        <div class="table-actions">
            <a href="sign_preset.php?edit=<?=$sp['id']?>" class="btn btn-sm btn-outline">编辑</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定删除该签章预设吗？已保存的合同不受影响。');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$sp['id']?>">
                <button type="submit" class="btn btn-sm btn-danger">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-stamp"></i><p>暂无签章预设，新增后可在合同表单下拉选用</p></div></td></tr>
<?php endif; ?>
</tbody>
</table></div></div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
