<?php
// 提早結案審核（#7）：該區辦社工審核「2-3 選項」的提早結案。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(['region_worker']);
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $msg = closure_review($u, (int)$_POST['id'], (string)$_POST['action'], (string)($_POST['opinion'] ?? '')) ?? '已處理。';
}
$s = rdb()->prepare("SELECT c.*, a.case_no, a.name FROM closure c JOIN application a ON a.id = c.application_id WHERE c.status = 'pending_review' AND a.region_id = ? ORDER BY c.id");
$s->execute([(int)$u['region_id']]);
$tok = h(csrf_token());
page_head('提早結案審核', $u);
echo '<h1>提早結案審核</h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
foreach ($s as $c) {
    echo '<div class=card style=max-width:none><b>' . h($c['case_no'] . ' ' . $c['name']) . '</b>：' . h($c['close_type']) . '（' . h(date('Y-m-d', strtotime($c['submitted_at']))) . ' 送出）';
    echo " <a href=\"/cases/view.php?id={$c['application_id']}\">看個案</a>";
    echo "<form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=id value=\"{$c['id']}\"><label>審核意見（必填）<textarea name=opinion rows=2></textarea></label>";
    echo '<button name=action value=approve>通過</button> <button name=action value=return>退件</button> <button name=action value=reject>不通過</button></form></div>';
}
page_foot();
