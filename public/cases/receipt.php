<?php
// 個案領款收據（#8）：匯款成功後才開放列印；列印寫稽核。版面待依服管組「個案領款收據-新範本」細修。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['cases'][2]);
$s = rdb()->prepare('SELECT * FROM installment WHERE id = ?'); $s->execute([(int)($_GET['id'] ?? 0)]);
$it = $s->fetch();
$a = $it ? app_load($u, (int)$it['application_id']) : null;
if (!$a || !$it['paid_on']) { http_response_code(404); exit('這一期尚未匯款，還不能列印領據。'); }
$caseNo = $a['case_no'] ?: (app_row((int)$a['parent_id'])['case_no'] ?? '');
$ch = rdb()->prepare('SELECT name FROM church WHERE oid = ?'); $ch->execute([$a['church_oid']]);
audit('export', 'receipt', $it['id'], ['application_id' => $a['id']]);
?><!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>領款收據 <?= h($caseNo) ?></title>
<style>body{font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;max-width:720px;margin:24px auto;padding:0 16px;color:#111}h1{text-align:center;font-size:22px}
table{border-collapse:collapse;width:100%}td{border:1px solid #333;padding:10px}.sig{height:70px}@media print{button{display:none}}</style></head><body>
<h1>社團法人中華基督教救助協會<br>急難救助金領款收據</h1>
<table>
<tr><td>案號</td><td><?= h($caseNo) ?></td><td>期數</td><td>第 <?= (int)$it['seq'] ?> 期</td></tr>
<tr><td>受助人</td><td><?= h($a['name']) ?></td><td>教會</td><td><?= h($ch->fetchColumn()) ?></td></tr>
<tr><td>金額</td><td>新臺幣 <?= number_format((int)$it['amount']) ?> 元整</td><td>匯款日</td><td><?= h($it['paid_on']) ?></td></tr>
<tr><td>受助人簽名</td><td class=sig colspan=3></td></tr>
<tr><td>教會經手人簽名</td><td class=sig colspan=3></td></tr>
</table>
<p>請於匯款後 10 天內簽回。</p><button onclick="print()">列印</button>
</body></html>
