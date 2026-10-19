<?php
// 稽核紀錄檢視（管理者）。檢視本身也留一筆，避免有人翻紀錄卻不留痕。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(['admin']);
// 2026-10-19 起搜尋字不放網址（可能是人名或帳號）：POST 存進伺服器端、轉址到 ?f=代碼，見 lib/boot.php filter_stash。
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $k = filter_stash(['q' => mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100)]);
    if (PHP_SAPI !== 'cli') { header('Location: ?f=' . $k, true, 303); exit; }
    $_GET['f'] = $k;
}
$q = (string)((filter_load($_GET['f'] ?? null) ?? [])['q'] ?? '');
audit('view', 'audit_log', null, $q !== '' ? ['q' => $q] : []);
$sql = 'SELECT at, actor, action, target_type, target_id, detail FROM audit_log';
$p = [];
if ($q !== '') { $sql .= ' WHERE actor ILIKE ? OR action = ? OR target_type = ?'; $p = ["%$q%", $q, $q]; }
$s = rdb()->prepare($sql . ' ORDER BY id DESC LIMIT 200');
$s->execute($p);
page_head('稽核紀錄', $u);
echo '<h1>稽核紀錄</h1><form method=post><input type=hidden name=csrf value="' . h(csrf_token()) . '"><input name=q value="' . h($q) . '" placeholder="帳號、動作或對象"><button>搜尋</button></form>';
echo '<table><tr><th>時間</th><th>誰</th><th>動作</th><th>對象</th><th>內容</th></tr>';
foreach ($s as $r) echo '<tr><td>' . h(date('m-d H:i', strtotime($r['at']))) . '</td><td>' . h($r['actor']) . '</td><td>' . h($r['action']) . '</td><td>' . h(trim($r['target_type'] . ' ' . $r['target_id'])) . '</td><td><small>' . h($r['detail']) . '</small></td></tr>';
echo '</table>';
page_foot();
