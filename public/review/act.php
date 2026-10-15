<?php
// 審核一案（#6）：意見必填；區辦以後的關卡可配置期數與金額（第一期必填）。按鈕文字一律「完成審核」（需求 0004）。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['review'][2]);
$id = (int)($_GET['id'] ?? 0);
$a = app_row($id);
$part = $a ? review_part($u, $a) : null;
if (!$part) { http_response_code(404); exit('這一案目前不在您的審核關卡。'); }
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $inst = isset($_POST['inst']) ? (array)$_POST['inst'] : null;
    $msg = review_act($u, $id, (string)($_POST['action'] ?? ''), (string)($_POST['opinion'] ?? ''), $inst) ?? '';
    if ($msg === '') { header('Location: /review/?done=' . $id); exit; }
}
$stage = STAGES[$a['status']];
$inst = rdb()->prepare('SELECT seq, amount FROM installment WHERE application_id = ? ORDER BY seq'); $inst->execute([$id]);
$rows = $inst->fetchAll(PDO::FETCH_KEY_PAIR);
$steps = rdb()->prepare('SELECT s.*, u.display_name FROM review_step s JOIN app_user u ON u.id = s.user_id WHERE application_id = ? ORDER BY s.id'); $steps->execute([$id]);
page_head('審核', $u);
echo '<h1>審核：' . h($a['case_no']) . ' ' . h($a['name']) . '</h1>';
echo '<p>' . h(STATUS_NAMES[$a['status']]) . ($part === 'cosign' ? '（您是會簽者）' : '') . '・教會申請金額 ' . number_format((int)$a['amount_requested']) . ' 元・<a href="/cases/view.php?id=' . $id . '" target=_blank>看完整申請內容</a></p>';
if ($msg) echo '<p class=err>' . h($msg) . '</p>';
if ($a['kind'] === 'change' && ($p = app_row((int)$a['parent_id']))) {
    // 異動審核：畫面下方顯示原始申請內容（需求 0003）
    $cd = json_decode($a['data'], true);
    echo '<div class=card style=max-width:none><b>異動申請</b>（原案 ' . h($p['case_no']) . '，開案 ' . h($p['opened_at']) . '）<br>異動項目：' . h(implode('、', $cd['change_items']['sel'] ?? []))
       . '<br>異動原因：' . h(implode('、', $cd['change_reason']['sel'] ?? [])) . h(isset($cd['change_reason']['other']) ? '（' . $cd['change_reason']['other'] . '）' : '')
       . '<br>描述：' . nl2br(h($cd['change_desc'] ?? '')) . '<br><a href="/cases/view.php?id=' . (int)$p['id'] . '" target=_blank>原始申請內容</a></div>';
}
echo '<h2>審核紀錄</h2><table><tr><th>時間</th><th>關卡</th><th>誰</th><th>動作</th><th>意見</th></tr>';
$an = ['submit' => '送審', 'approve' => '完成審核', 'return' => '退件', 'reject' => '不通過'];
foreach ($steps as $s) echo '<tr><td>' . h(date('m-d H:i', strtotime($s['at']))) . '</td><td>' . h($s['stage'] . ($s['part'] === 'cosign' ? '（會簽）' : '')) . '</td><td>' . h($s['display_name']) . '</td><td>' . h($an[$s['action']]) . '</td><td>' . h($s['opinion']) . '</td></tr>';
echo '</table>';
$tok = h(csrf_token());
echo "<form method=post class=card style=max-width:none><input type=hidden name=csrf value=\"$tok\">";
if ($stage !== 'pastor') {
    echo '<fieldset><legend>期數與核發金額（第一期必填，可多期）</legend><div class=grid>';
    $n = max(6, count($rows) + 1);
    for ($i = 1; $i <= $n; $i++) echo "<label>第 $i 期<input name=\"inst[]\" inputmode=numeric value=\"" . h($rows[$i] ?? '') . '"></label>';
    echo '</div></fieldset>';
}
echo '<label>審核意見／退件或不通過原因（必填）<textarea name=opinion rows=4 required></textarea></label>';
echo '<div><button name=action value=approve>完成審核</button> <button name=action value=return style="background:#8a6d1f">退件</button> <button name=action value=reject style="background:#9b2c2c">不通過</button></div></form>';
page_foot();
