<?php
// 撥款批次（#8）：服管組產出與送出、區辦確認或退出、會計出納匯出與標記匯款、領據簽回登記。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['batches'][2]);
$r = $u['role'];
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    switch ($_POST['act'] ?? '') {
        case 'create':   [$bid, $e] = batch_create($u); $msg = $e ?? '已產出暫存批次，並通知各區辦確認。'; break;
        case 'confirm':  case 'withdraw': $msg = batch_region_act($u, (int)$_POST['id'], $_POST['act']) ?? ($_POST['act'] === 'confirm' ? '已確認。' : '已退出批次，回到未產出批次。'); break;
        case 'send':     $msg = batch_send($u, (int)$_POST['bid']) ?? '已送會計。'; break;
        case 'paid':     $msg = batch_paid($u, (int)$_POST['bid'], (string)$_POST['date']) ?? '已標記匯款成功，並通知教會與區辦。'; break;
        case 'receipt':  $msg = receipt_back($u, (int)$_POST['id'], (string)$_POST['date']) ?? '已登記領據簽回。'; break;
    }
}
if (isset($_GET['csv'])) {
    [$csv, $e] = batch_export_csv($u, (int)$_GET['csv']);
    if ($e) { http_response_code(403); exit($e); }
    $b = batch_row((int)$_GET['csv']);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"remit-{$b['year']}-{$b['no']}.csv\"");
    exit($csv);
}
$tok = h(csrf_token());
$f = fn($act, $extra, $label) => "<form method=post style=display:inline><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=$act>$extra<button>$label</button></form>";
page_head('撥款批次', $u);
echo '<h1>撥款批次</h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
if (in_array($r, HQ_ROLES, true)) {
    $un = unbatched();
    echo '<h2>未產出批次（' . count($un) . ' 筆，' . number_format(array_sum(array_column($un, 'amount'))) . ' 元）</h2>';
    if ($un) echo $f('create', '', '產出暫存批次');
}
$batches = rdb()->query('SELECT * FROM batch ORDER BY id DESC LIMIT 20')->fetchAll();
foreach ($batches as $b) {
    $mine = in_array($r, ['region_worker', 'region_head'], true) ? (int)$u['region_id'] : null;
    $items = batch_items((int)$b['id'], $mine);
    if ($mine && !$items) continue;
    $t = batch_totals((int)$b['id']);
    echo '<section class=card style=max-width:none><h2>批次 ' . h("{$b['year']}-{$b['no']}") . '　' . h(['confirming' => '區辦確認中', 'sent' => '已送會計', 'paid' => '已匯款 ' . $b['paid_on']][$b['status']]) . '</h2>';
    // 區辦只看得到自己區的列，小計也只顯示本區，不露出他區金額
    if ($mine) echo '<p>本區小計：' . number_format(array_sum(array_column($items, 'amount'))) . ' 元（' . count($items) . ' 筆）</p>';
    else echo '<p>各區小計：' . h(implode('、', array_map(fn($k, $v) => "$k " . number_format($v), array_keys($t['regions']), $t['regions']))) . '｜合計 ' . number_format($t['total']) . ' 元（' . $t['count'] . ' 筆）</p>';
    echo '<table><tr><th>區辦</th><th>案號</th><th>個案</th><th>教會</th><th>期數</th><th>金額</th><th>帳戶</th><th></th></tr>';
    foreach ($items as $it) {
        echo '<tr><td>' . h($it['rcode']) . '</td><td>' . h($it['case_no'] ?? '異動') . '</td><td>' . h($it['name']) . '</td><td>' . h($it['church']) . '</td><td>' . h("{$it['total_seq']}-{$it['seq']}") . '</td><td>' . number_format($it['amount']) . '</td>';
        echo '<td>' . h($it['account_no'] ? "{$it['bank_name']} {$it['account_no']}" : '未設定') . '</td><td>';
        if ($b['status'] === 'confirming' && in_array($r, ['region_worker', 'region_head'], true))
            echo $it['region_confirmed'] ? '已確認 ' : $f('confirm', "<input type=hidden name=id value={$it['id']}>", '確認') . ' ', $f('withdraw', "<input type=hidden name=id value={$it['id']}>", '退出批次');
        elseif ($b['status'] === 'confirming') echo $it['region_confirmed'] ? '區辦已確認' : '等區辦確認';
        if ($b['status'] === 'paid' && !in_array($r, FIN_ROLES, true))
            echo $it['receipt_back_on'] ? '領據已回 ' . h($it['receipt_back_on']) : $f('receipt', "<input type=hidden name=id value={$it['id']}><input type=date name=date value=\"" . date('Y-m-d') . '">', '登記領據簽回');
        echo '</td></tr>';
    }
    echo '</table>';
    if ($b['status'] === 'confirming' && in_array($r, HQ_ROLES, true)) echo $f('send', "<input type=hidden name=bid value={$b['id']}>", '送會計');
    if (in_array($r, array_merge(HQ_ROLES, FIN_ROLES), true)) echo ' <a class=btn href="?csv=' . (int)$b['id'] . '">匯出匯款清單</a>';
    if ($b['status'] === 'sent' && $r === 'cashier') echo ' ' . $f('paid', "<input type=hidden name=bid value={$b['id']}><input type=date name=date value=\"" . date('Y-m-d') . '">', '標記匯款成功');
    echo '</section>';
}
page_foot();
