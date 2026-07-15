<?php
require_once __DIR__ . '/../../includes/auth.php';
$pdo = getDB();
$last = $pdo->query("SELECT sku FROM products ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($last && preg_match('/^(.+?)(\d+)$/', $last, $m)) {
    echo $m[1] . str_pad(intval($m[2]) + 1, strlen($m[2]), '0', STR_PAD_LEFT);
} else {
    echo $last ? $last . '-1' : 'SP0001';
}
