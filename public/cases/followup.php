<?php
// 原案的後續作業（#7）：異動申請、服務紀錄、結案報告與問卷。
require __DIR__ . '/../../lib/boot.php';
require_once __DIR__ . '/../../lib/form_render.php';
$u = require_role(MENU['cases'][2]);
$a = app_load($u, (int)($_GET['id'] ?? 0));
if (!$a) { http_response_code(404); exit('找不到這個個案。'); }
$raw = app_row((int)$a['id']);
$church = church_owns($u, $raw);
$err = []; $msg = '';
if ($church && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    switch ($_POST['form'] ?? '') {
        case 'change':  [$cid, $err] = change_create($u, (int)$a['id'], $_POST); if (!$err) { header('Location: /cases/view.php?id=' . $cid); exit; } break;
        case 'service': $err = service_add($u, (int)$a['id'], $_POST); $msg = $err ? '' : '服務紀錄已新增。'; break;
        case 'closure': $err = closure_submit($u, (int)$a['id'], $_POST); $msg = $err ? '' : '結案報告已送出。'; break;
    }
    $raw = app_row((int)$a['id']);
}
$tok = h(csrf_token());
page_head('後續作業', $u);
echo '<h1>' . h($a['name']) . ' <small>' . h($a['case_no']) . '</small></h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
if (isset($err['_'])) echo '<p class=err>' . h($err['_']) . '</p>';
if (is_closed($raw)) echo '<p class=note>本案已於 ' . h($raw['closed_at']) . ' 結案，資料唯讀。</p>';
if (($d = service_days_since($raw)) !== null) echo '<p class=note>距上次撥款或服務紀錄 ' . $d . ' 天' . ($d >= 10 ? '，請填寫服務紀錄。' : '。') . '</p>';

$sr = rdb()->prepare('SELECT s.served_at, s.data, u.display_name FROM service_record s JOIN app_user u ON u.id = s.user_id WHERE application_id = ? ORDER BY served_at DESC');
$sr->execute([$a['id']]);
echo '<h2>服務紀錄</h2><table><tr><th>服務時間</th><th>志工</th><th>服務方式</th></tr>';
foreach ($sr as $r) { $dd = json_decode($r['data'], true); echo '<tr><td>' . h(date('Y-m-d H:i', strtotime($r['served_at']))) . '</td><td>' . h($r['display_name']) . '</td><td>' . h(implode('、', $dd['mode']['sel'] ?? [])) . '</td></tr>'; }
echo '</table>';
if ($church && !is_closed($raw) && $raw['status'] === 'approved') {
    echo "<details><summary>新增服務紀錄（志工：" . h($u['display_name']) . "）</summary><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=form value=service>" . fr_form([], ($_POST['form'] ?? '') === 'service' ? $err : [], SERVICE_SPEC) . '<button>新增</button></form></details>';
    echo "<details><summary>提出異動申請</summary><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=form value=change>" . fr_form([], ($_POST['form'] ?? '') === 'change' ? $err : [], CHANGE_SPEC) . '<button>建立異動申請（草稿）</button></form></details>';
    echo "<details><summary>結案報告與結案問卷</summary><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=form value=closure>" . fr_form([], ($_POST['form'] ?? '') === 'closure' ? $err : [], CLOSURE_SPEC) . '<p class=note>「案家情況改善」「案主死亡」的提早結案要經區辦審核；其他類型送出即結案。</p><button>送出結案報告</button></form></details>';
}
echo '<script src="/assets/form.js"></script>';
page_foot();
