<?php
require __DIR__ . '/../lib/boot.php';
$u = require_login();
page_head('首頁', $u);
echo '<h1>您好，' . h($u['display_name']) . '</h1>';
echo '<p class="note">這是資訊部的實驗性開發版本，資料皆為測試用，正式作業請繼續使用現行系統。</p>';
$m = menu_for($u['role']);
if (!$m) echo '<p>您的角色目前沒有可用功能。</p>';
else { echo '<ul class="tiles">'; foreach ($m as $x) echo '<li><a href="' . h($x[1]) . '">' . h($x[0]) . '</a></li>'; echo '</ul>'; }
page_foot();
