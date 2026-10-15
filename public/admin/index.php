<?php
require __DIR__ . '/../../lib/boot.php';
$u = require_role(['admin']);
page_head('系統管理', $u);
echo '<h1>系統管理</h1><ul class="tiles">';
echo '<li><a href="/admin/regions.php">區辦與區域</a></li>';
echo '<li><a href="/admin/users.php">使用者與會簽</a></li>';
echo '<li><a href="/admin/audit.php">稽核紀錄</a></li>';
echo '</ul>';
page_foot();
