<?php
// 錄影簽名雛形：紀錄列表。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
page_head('錄影簽名（雛形）', $u);
echo '<h1>錄影簽名 <small class="tag">雛形</small></h1>';
echo '<p class="note">試做「物資簽收＋手寫簽名＋錄影聲明」。只放測試資料，請勿使用真實姓名或個資。</p>';
echo '<p><a class="btn primary" href="/poc/sign.php">新增一筆簽收</a></p>';
$rows = poc_list();
if (!$rows) echo '<p>目前沒有紀錄。</p>';
else {
    echo '<table><thead><tr><th>時間</th><th>簽收人（代稱）</th><th>物資</th><th>經手</th><th></th></tr></thead><tbody>';
    foreach ($rows as $m) {
        echo '<tr><td>' . h(date('m/d H:i', strtotime($m['created_at']))) . '</td><td>' . h($m['recipient']) . '</td><td>' . h(mb_strimwidth($m['items'], 0, 40, '…')) . '</td><td>' . h($m['worker']) . '</td>'
           . '<td><a href="/poc/view.php?id=' . h($m['id']) . '">收執</a></td></tr>';
    }
    echo '</tbody></table>';
}
page_foot();
