<?php
// 教會收款帳戶（#8）
require __DIR__ . '/../../lib/boot.php';
$u = require_role(CHURCH_ROLES);
$oid = (string)($_GET['oid'] ?? '');
if (!can_edit_church($u, $oid)) { http_response_code(404); exit('找不到這間教會。'); }
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { csrf_check(); $msg = church_account_set($u, $oid, $_POST) ?? '已儲存。'; }
$s = rdb()->prepare('SELECT * FROM church WHERE oid = ?'); $s->execute([$oid]); $c = $s->fetch();
page_head('收款帳戶', $u);
echo '<h1>' . h($c['name']) . '：收款帳戶</h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
echo '<form method=post class=card><input type=hidden name=csrf value="' . h(csrf_token()) . '">';
foreach (['bank_name' => '銀行名稱', 'bank_code' => '銀行總行代號', 'branch_code' => '分行代號', 'account_no' => '帳號（只填數字，空白會自動去掉）', 'account_name' => '戶名'] as $k => $l)
    echo "<label>$l<input name=$k value=\"" . h($c[$k] ?? '') . '"></label>';
echo '<button>儲存</button></form>';
page_foot();
