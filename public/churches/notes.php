<?php
// 教會紀錄：綁定後的追蹤紀錄，每筆有日期、訪視／電訪與內容（2026-11-12 試用回饋加）、評估、備註、記錄人（熊哥 2026-11-10）。只增不改。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(CHURCH_ROLES);
$oid = (string)($_GET['oid'] ?? '');
if (!church_note_allowed($u, $oid)) { http_response_code(404); exit('找不到這間教會。'); }
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { csrf_check(); $msg = church_note_add($u, $oid, $_POST) ?? '已新增紀錄。'; }
$s = rdb()->prepare('SELECT c.*, u.display_name AS bound_name FROM church c LEFT JOIN app_user u ON u.id = c.bound_by WHERE c.oid = ?'); $s->execute([$oid]); $c = $s->fetch();
page_head('教會紀錄', $u);
echo '<h1>' . h($c['name']) . '：紀錄</h1>';
echo '<p class="note">綁定' . (!empty($c['bound_at']) ? '於 ' . h(date('Y-m-d', strtotime($c['bound_at']))) : '') . (!empty($c['bound_name']) ? '，綁定人 ' . h($c['bound_name']) : '') . '。紀錄只增不改，要更正請再記一筆並在備註說明。</p>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
echo '<h2>新增紀錄</h2><form method=post class=card><input type=hidden name=csrf value="' . h(csrf_token()) . '">';
echo '<label>日期<input type=date name=note_date required value="' . h($_POST['note_date'] ?? date('Y-m-d')) . '" max="' . date('Y-m-d') . '"></label>';
$kind = $msg && $msg !== '已新增紀錄。' ? ($_POST['contact_kind'] ?? '') : '';
echo '<fieldset><legend>訪視／電訪</legend>';
foreach (['訪視', '電訪'] as $kk) echo '<label style="display:inline-block;margin-right:16px"><input type=radio name=contact_kind value="' . $kk . '" required' . ($kind === $kk ? ' checked' : '') . '> ' . $kk . '</label>';
echo '</fieldset>';
echo '<label>訪視／電訪內容<textarea name=contact_content rows=5 maxlength=2000 required>' . h($msg && $msg !== '已新增紀錄。' ? ($_POST['contact_content'] ?? '') : '') . '</textarea></label>';
echo '<label>評估<textarea name=assessment rows=3 maxlength=500 required>' . h($msg && $msg !== '已新增紀錄。' ? ($_POST['assessment'] ?? '') : '') . '</textarea></label>';
echo '<label>備註（可空白）<textarea name=remark rows=3 maxlength=2000>' . h($msg && $msg !== '已新增紀錄。' ? ($_POST['remark'] ?? '') : '') . '</textarea></label>';
echo '<button>新增紀錄</button></form>';
$notes = church_notes($oid);
echo '<h2>歷次紀錄（' . count($notes) . ' 筆）</h2>';
if (!$notes) echo '<p class="note">還沒有紀錄。</p>';
else {
    echo '<table><tr><th>日期</th><th>訪視／電訪</th><th>內容</th><th>評估</th><th>備註</th><th>記錄人</th><th>記錄時間</th></tr>';
    foreach ($notes as $n)
        echo '<tr><td>' . h($n['note_date']) . '</td><td>' . h((string)$n['contact_kind']) . '</td><td>' . nl2br(h((string)$n['contact_content'])) . '</td><td>' . nl2br(h($n['assessment'])) . '</td><td>' . nl2br(h((string)$n['remark'])) . '</td><td>' . h($n['by_name']) . '</td><td>' . h(date('Y-m-d H:i', strtotime($n['created_at']))) . '</td></tr>';
    echo '</table>';
}
echo '<p><a href="/churches/">回教會與帳號</a></p>';
page_foot();
