<?php
// 報表與匯出（#10）。匯出後按「返回」要帶回原本的搜尋條件（需求 0004）。
// 2026-10-19 起條件不放網址：搜尋用 POST 存進伺服器端，再轉址到 ?r=種類&f=代碼（見 lib/boot.php filter_stash），
// 個案姓名因此不會出現在網址、伺服器紀錄與瀏覽器歷史。網址上舊式的 name 參數一律忽略。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['reports'][2]);
$kind = isset(REPORTS[$_GET['r'] ?? '']) ? $_GET['r'] : 'apply';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $k = filter_stash(report_filters($_POST));
    if (PHP_SAPI !== 'cli') { header('Location: ?' . http_build_query(['r' => $kind, 'f' => $k]), true, 303); exit; }
    $_GET['f'] = $k;   // 測試時沒有轉址，直接用同一組代碼往下跑
}
$fIn = $_GET['f'] ?? null;
$loaded = filter_load($fIn);
$expired = $fIn !== null && $loaded === null;   // 有代碼卻取不到：不可當成「沒有條件」
if ($expired && isset($_GET['csv'])) { http_response_code(409); exit(FILTER_EXPIRED); }
$f = $loaded ?? ($expired ? [] : report_filters(array_diff_key($_GET, ['name' => 1])));
if (isset($_GET['csv'])) {
    [$csv, $e] = report_csv($u, $kind, $f);
    if ($e) { http_response_code(400); exit($e); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $kind . '-' . date('Ymd') . '.csv"');
    exit($csv);
}
[$head, $rows] = $expired ? [[], []] : report_rows($u, $kind, $f);
$regions = rdb()->query('SELECT id, name FROM region WHERE active ORDER BY id')->fetchAll();
$areas = rdb()->query('SELECT id, region_id, name FROM area WHERE active ORDER BY region_id, id')->fetchAll();
$dateLabel = ['apply' => '申請日期', 'household' => '申請日期', 'church' => null, 'service' => '紀錄日期', 'closure' => '結案日期'][$kind];
$v = fn($k) => h($f[$k] ?? '');
page_head('報表與匯出', $u);
echo '<h1>報表與匯出</h1><p>';
foreach (REPORTS as $k => $name) echo $k === $kind ? '<b>' . h($name) . '</b>　' : '<a href="?r=' . h($k) . '">' . h($name) . '</a>　';
echo '</p><form method=post action="?r=' . h($kind) . '" class=filters><input type=hidden name=csrf value="' . h(csrf_token()) . '">';
echo '<label>大區 <select name=region><option value="">全部</option>';
foreach ($regions as $r) echo '<option value="' . (int)$r['id'] . '"' . (($f['region'] ?? null) === (int)$r['id'] ? ' selected' : '') . '>' . h($r['name']) . '</option>';
echo '</select></label> <label>小區 <select name=area><option value="">全部</option>';
foreach ($areas as $a) echo '<option value="' . (int)$a['id'] . '"' . (($f['area'] ?? null) === (int)$a['id'] ? ' selected' : '') . '>' . h($a['name']) . '</option>';
echo '</select></label> <label>教會名稱 <input name=church value="' . $v('church') . '"></label>';
if (in_array($kind, ['apply', 'household'], true)) echo ' <label>個案姓名 <input name=name value="' . $v('name') . '"></label>';
if ($dateLabel) echo " <label>{$dateLabel} <input type=date name=d1 value=\"" . $v('d1') . '"> ～ <input type=date name=d2 value="' . $v('d2') . '"></label>';
if ($kind !== 'church') echo ' <label>開案日期 <input type=date name=o1 value="' . $v('o1') . '"> ～ <input type=date name=o2 value="' . $v('o2') . '"></label>';
echo ' <button>搜尋</button></form>';
// 匯出連結只帶代碼，不帶條件本身；已有有效代碼就沿用，不每次另存一組
if ($expired) { echo '<p class=note>' . h(FILTER_EXPIRED) . '</p>'; page_foot(); exit; }
$fk = $loaded !== null ? $fIn : ($f ? filter_stash($f) : null);
$q = http_build_query(['r' => $kind, 'f' => $fk, 'csv' => 1]);
echo '<p>共 ' . count($rows) . ' 筆　<a href="?' . h($q) . '">匯出 CSV</a>（匯出會記錄在稽核紀錄）</p>';
echo '<div class=scroll><table><tr>';
foreach ($head as $c) echo '<th>' . h($c) . '</th>';
echo '</tr>';
foreach (array_slice($rows, 0, 200) as $r) { echo '<tr>'; foreach ($r as $c) echo '<td>' . h(mb_strimwidth((string)$c, 0, 60, '…')) . '</td>'; echo '</tr>'; }
echo '</table></div>';
if (count($rows) > 200) echo '<p class=note>畫面只列前 200 筆，完整資料請匯出。</p>';
page_foot();
