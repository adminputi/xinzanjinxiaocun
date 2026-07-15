<?php
/**
 * 数据库备份与恢复
 */

// ===== 下载和删除操作必须在HTML输出之前处理 =====
require_once __DIR__ . '/../../includes/auth.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../../index.php'); exit; }

$pdo = getDB();
$backupDir = __DIR__ . '/../../backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

// 下载备份 —— 必须在输出HTML前（改为POST + CSRF验证）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    csrf_verify();
    $dlFile = $_POST['file'] ?? '';
    // 防止路径穿越攻击：只允许合法文件名
    $dlFile = basename($dlFile);
    if ($dlFile && preg_match('/^backup_.*\.sql$/', $dlFile) && file_exists($backupDir . $dlFile)) {
        while (ob_get_level()) @ob_end_clean();
        @ob_start();
        $fileSize = filesize($backupDir . $dlFile);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $dlFile . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache');
        readfile($backupDir . $dlFile);
        @ob_end_flush();
        exit;
    }
    die('文件不存在或非法请求');
}

// 删除备份 —— 必须在输出HTML前（改为POST + CSRF验证）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $delFile = $_POST['file'] ?? '';
    $delFile = basename($delFile);
    if ($delFile && preg_match('/^backup_.*\.sql$/', $delFile) && file_exists($backupDir . $delFile)) {
        unlink($backupDir . $delFile);
        add_log($_SESSION['user_id'], 'delete', 'system', "删除备份文件: $delFile");
    }
    redirect('backup.php');
}

// ===== 页面渲染（从此处开始输出HTML） =====
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/functions.php';
require_permission('system');

$dbName = DB_NAME;
$success = '';
$error = '';

// 获取已有备份列表
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . 'backup_*.sql');
    if ($files) {
        rsort($files);
        foreach ($files as $f) {
            $backups[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'time' => filemtime($f),
            ];
        }
    }
}

// 手动备份
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    csrf_verify();
    try {
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $filePath = $backupDir . $filename;

        $out = "-- 进销存系统数据库备份\n";
        $out .= "-- 备份时间: " . date('Y-m-d H:i:s') . "\n";
        $out .= "-- 数据库: {$dbName}\n\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        // 获取所有表
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // 表结构
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $out .= "-- 表结构: {$table}\n";
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $out .= $create[1] . ";\n\n";

            // 表数据 - 分批导出
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($count > 0) {
                $out .= "-- 数据: {$table} ({$count} 条)\n";
                $offset = 0;
                $batchSize = 500;
                while ($offset < $count) {
                    $rows = $pdo->query("SELECT * FROM `$table` LIMIT $offset, $batchSize")->fetchAll(PDO::FETCH_ASSOC);
                    if ($rows) {
                        foreach ($rows as $row) {
                            $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote($v); }, array_values($row));
                            $cols = '`' . implode('`,`', array_keys($row)) . '`';
                            $out .= "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(',', $vals) . ");\n";
                        }
                    }
                    $offset += $batchSize;
                }
                $out .= "\n";
            }
        }

        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        file_put_contents($filePath, $out);
        add_log(get_user_id(), 'backup', 'system', "数据库手动备份: $filename (" . round(filesize($filePath) / 1024, 1) . " KB)");
        $success = "备份成功！文件：{$filename}（" . round(filesize($filePath) / 1024, 1) . " KB）";
    } catch (Exception $e) {
        error_log('Backup error: '.$e->getMessage());
        $error = '备份失败，请稍后重试';
    }
}

// 恢复
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    csrf_verify();
    $restoreFile = $_POST['restore_file'] ?? '';
    $restoreFile = basename($restoreFile);
    if (empty($restoreFile) || !preg_match('/^backup_.*\.sql$/', $restoreFile) || !file_exists($backupDir . $restoreFile)) {
        $error = '备份文件不存在';
    } else {
        try {
            $sql = file_get_contents($backupDir . $restoreFile);
            if (empty($sql)) throw new Exception('备份文件为空');

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->beginTransaction();

            // 统一SQL分割逻辑：按 ; + 换行拆分
            $sql = str_replace("\r\n", "\n", $sql);
            $queries = array_filter(array_map('trim', explode(";\n", $sql)), function($q) {
                $q = trim($q);
                return !empty($q) && strpos($q, '--') !== 0;
            });

            $tableCount = 0;
            foreach ($queries as $q) {
                if (!empty(trim($q))) {
                    try {
                        $pdo->exec($q);
                        if (stripos($q, 'CREATE TABLE') !== false || stripos($q, 'INSERT INTO') !== false) {
                            $tableCount++;
                        }
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'already exists') === false) throw $e;
                    }
                }
            }

            $pdo->commit();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            add_log(get_user_id(), 'restore', 'system', "数据库恢复: $restoreFile");
            $success = "数据库恢复成功！（文件：{$restoreFile}）";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $e2) {}
            error_log('Restore error: '.$e->getMessage());
            $error = '恢复失败，请稍后重试';
        }
    }
}

// 上传文件恢复
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_restore' && isset($_FILES['sql_file'])) {
    csrf_verify();
    try {
        if ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) throw new Exception('文件上传失败');
        $tmpName = $_FILES['sql_file']['tmp_name'];
        $origName = $_FILES['sql_file']['name'];
        // 验证上传文件扩展名
        $upExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($upExt !== 'sql') throw new Exception('仅支持 .sql 文件');
        // 验证上传文件 MIME
        $upMime = mime_content_type($tmpName);
        if (!in_array($upMime, ['text/plain', 'text/x-sql', 'application/octet-stream', 'application/sql'])) {
            throw new Exception('文件类型不合法');
        }
        $destName = 'uploaded_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        move_uploaded_file($tmpName, $backupDir . $destName);

        $sql = file_get_contents($backupDir . $destName);
        if (empty($sql)) throw new Exception('文件为空');

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->beginTransaction();

        // 统一SQL分割逻辑：按 ; + 换行拆分
        $sql = str_replace("\r\n", "\n", $sql);
        $queries = array_filter(array_map('trim', explode(";\n", $sql)), function($q) {
            $q = trim($q);
            return !empty($q) && strpos($q, '--') !== 0;
        });
        foreach ($queries as $q) {
            try { $pdo->exec($q); } catch (Exception $e) {
                if (strpos($e->getMessage(), 'already exists') === false) throw $e;
            }
        }

        $pdo->commit();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        add_log(get_user_id(), 'restore', 'system', "数据库恢复(上传): $origName");
        $success = "数据库恢复成功！";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $e2) {}
        error_log('Upload restore error: '.$e->getMessage());
        $error = '恢复失败，请稍后重试';
    }
}

// 获取表统计
$tableStats = [];
$stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024,1) as size_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME");
$stmt->execute([$dbName]);
$tables = $stmt->fetchAll();
foreach ($tables as $t) {
    $tableStats[] = ['table' => $t['TABLE_NAME'], 'rows' => $t['TABLE_ROWS'], 'size' => $t['size_kb']];
}
?>

<div class="page-header">
    <h1 class="page-title"><i class="fa-solid fa-database"></i> 数据库备份</h1>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= $error ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- 左侧：数据库概况 + 手动备份 -->
    <div>
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h3 class="card-title"><i class="fa-solid fa-info-circle"></i> 数据库概况</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-container">
                    <table>
                        <thead><tr><th>表名</th><th>记录数</th><th>大小(KB)</th></tr></thead>
                        <tbody>
                            <?php foreach ($tableStats as $t): ?>
                            <tr><td><code><?=$t['table']?></code></td><td><?=number_format($t['rows'])?></td><td><?=$t['size']?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-floppy-disk"></i> 手动备份</h3></div>
            <div class="card-body">
                <p style="color:var(--gray-500);font-size:13px;margin-bottom:16px;">
                    将当前数据库完整导出为SQL文件，保存到服务器 <code>backups/</code> 目录。
                </p>
                <form method="post" onsubmit="return confirm('确定要备份数据库吗？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-download"></i> 立即备份</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-upload"></i> 上传SQL文件恢复</h3></div>
            <div class="card-body">
                <p style="color:var(--gray-500);font-size:13px;margin-bottom:16px;">
                    从本地上传之前备份的SQL文件进行恢复。<b style="color:var(--danger)">恢复操作会覆盖现有数据，请谨慎操作！</b>
                </p>
                <form method="post" enctype="multipart/form-data" onsubmit="return confirm('⚠️ 恢复操作将覆盖现有数据，确定继续吗？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_restore">
                    <div class="form-group">
                        <input type="file" name="sql_file" accept=".sql" required style="margin-bottom:12px;">
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-rotate-left"></i> 上传并恢复</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：备份历史 -->
    <div>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> 备份历史</h3></div>
            <div class="card-body" style="padding:0;">
                <?php if ($backups): ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>文件名</th><th>大小</th><th>备份时间</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><code><?=htmlspecialchars($b['name'])?></code></td>
                                <td><?= round($b['size']/1024, 1) ?> KB</td>
                                <td><?= date('Y-m-d H:i:s', $b['time']) ?></td>
                                <td>
                                    <div class="table-actions">
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="download">
                                            <input type="hidden" name="file" value="<?=htmlspecialchars($b['name'])?>">
                                            <button class="btn btn-sm btn-outline" title="下载"><i class="fa-solid fa-download"></i></button>
                                        </form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('⚠️ 确定从此备份恢复吗？当前数据将被覆盖！')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="restore_file" value="<?=htmlspecialchars($b['name'])?>">
                                            <button class="btn btn-sm btn-outline" title="恢复" style="color:var(--warning)"><i class="fa-solid fa-rotate-left"></i></button>
                                        </form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除此备份文件？')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="file" value="<?=htmlspecialchars($b['name'])?>">
                                            <button class="btn btn-sm btn-outline" title="删除"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>暂无备份记录</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
