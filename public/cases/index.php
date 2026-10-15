<?php
// 個案與申請清單（#5）。範圍在查詢層限縮；教會端不顯示審核狀態（需求 0002）。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['cases'][2]);
[$w, $p] = scope_sql($u, 'a.region_id', 'a.church_oid');
$s = rdb()->prepare("SELECT a.id, a.case_no, a.name, a.status, a.kind, a.created_at, a.submitted_at, c.name AS church, p.idno_head, p.idno_last4
    FROM application a JOIN church c ON c.oid = a.church_oid JOIN person p ON p.id = a.person_id
    WHERE ($w OR a.id IN (SELECT application_id FROM assignment WHERE user_id = ?)) ORDER BY a.id DESC LIMIT 300");
$s->execute(array_merge($p, [$u['id']]));
$church = in_array($u['role'], APPLY_ROLES, true);
page_head('個案與申請', $u);
echo '<h1>個案與申請</h1>';
if ($church) echo '<p><a class=btn href="/cases/new.php">＋ 新的急難救助申請</a></p>';
echo '<table><tr><th>案號</th><th>個案</th><th>身分證</th><th>教會</th>' . ($church ? '<th>送出</th>' : '<th>狀態</th>') . '<th></th></tr>';
foreach ($s as $r) {
    echo '<tr><td>' . h($r['case_no'] ?? '（未送審）') . '</td><td>' . h($r['name']) . '</td><td>' . h(pii_mask($r['idno_head'], $r['idno_last4'])) . '</td><td>' . h($r['church']) . '</td>';
    echo '<td>' . ($church ? h($r['submitted_at'] ? date('Y-m-d', strtotime($r['submitted_at'])) : '草稿') : h($r['status'])) . '</td>';
    echo '<td><a href="/cases/view.php?id=' . (int)$r['id'] . '">開啟</a></td></tr>';
}
echo '</table>';
page_foot();
