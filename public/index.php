<?php
require __DIR__ . '/../lib/boot.php';
$u = require_login();
page_head('首頁', $u);
echo '<h1>您好，' . h($u['display_name']) . '</h1>';
echo '<p class="note">這是資訊部的實驗性開發版本，資料皆為測試用，正式作業請繼續使用現行系統。</p>';
$m = menu_for($u['role']);
if (!$m) echo '<p>您的角色目前沒有可用功能。</p>';
else {
    // 每格一句說明：第一次使用的人看得出該點哪一格
    $desc = ['cases' => '新增申請、查看個案與進度', 'review' => '輪到您審核的案件', 'churches' => '教會資料與教會端帳號',
             'batches' => '核定後的撥款作業', 'reports' => '統計報表與資料匯出', 'admin' => '使用者、區域與稽核紀錄'];
    echo '<ul class="tiles">';
    foreach ($m as $k => $x) echo '<li><a href="' . h($x[1]) . '"><b>' . h($x[0]) . '</b><span>' . h($desc[$k] ?? '') . '</span></a></li>';
    echo '</ul>';
}
page_foot();
