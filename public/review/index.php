<?php
// 待我審核（#6）
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['review'][2]);
$q = my_queue($u);
page_head('待我審核', $u);
echo '<h1>待我審核</h1>';
if (!$q) echo '<p class="empty">目前沒有等您處理的案件。</p>';
else {
    echo '<table><tr><th>案號</th><th>個案</th><th>關卡</th><th>送審</th><th></th></tr>';
    foreach ($q as $a) {
        $part = review_part($u, $a) === 'cosign' ? '（會簽）' : '';
        echo '<tr><td>' . h($a['case_no']) . '</td><td>' . h($a['name']) . '</td><td>' . h(STATUS_NAMES[$a['status']] . $part) . '</td><td>' . h(date('m-d', strtotime($a['submitted_at']))) . '</td>';
        echo '<td><a href="/review/act.php?id=' . (int)$a['id'] . '">審核</a></td></tr>';
    }
    echo '</table>';
}
page_foot();
