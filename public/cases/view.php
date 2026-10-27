<?php
// 檢視一份申請（#5）。身分證預設遮罩；審核角色按「顯示完整號碼」才解密並留稽核。教會端不顯示審核狀態。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['cases'][2]);
$a = app_load($u, (int)($_GET['id'] ?? 0));
if (!$a) { http_response_code(404); exit('找不到這份申請。'); }
audit('view', 'application', $a['id']);
$full = !empty($_GET['full']);
page_head('申請內容', $u);
echo '<h1>' . h($a['name']) . ' <small>' . h($a['case_no'] ?? '（未送審）') . '</small></h1>';
if (!empty($_GET['saved'])) echo '<p><b>已儲存。</b></p>';
// 退件原因給教會端看（熊哥 2026-10-27 TG 選「退件原因顯示在教會端」：教會帳號沒有 Email，收不到退件通知）。
// 其餘審核狀態仍不給教會端看（需求 0002）。
if (in_array($u['role'], APPLY_ROLES, true) && (app_row((int)$a['id'])['status'] ?? '') === 'returned') {
    $rs = rdb()->prepare("SELECT stage, opinion, at FROM review_step WHERE application_id = ? AND action = 'return' ORDER BY id DESC LIMIT 1");
    $rs->execute([(int)$a['id']]); $rt = $rs->fetch();
    $who = ['pastor' => '教會牧者', 'region' => '區辦社工', 'other_region' => '他區社工', 'hq' => '服管組'];
    echo '<div class="notice"><b>這份申請被退回，請依下列原因修改後，按最下方「修改完成，再次送審」。</b>';
    if ($rt) echo '<br>退件原因：' . nl2br(h($rt['opinion'])) . '<br><small>' . h(($who[$rt['stage']] ?? '') . '　' . date('Y-m-d', strtotime($rt['at']))) . '</small>';
    echo '</div>';
}
if (isset($a['status'])) echo '<p>狀態：<span class="badge s-' . h($a['status']) . '">' . h(STATUS_NAMES[$a['status']] ?? $a['status']) . '</span></p>';
echo '<p>身分證：<b>' . h(app_idno_display($u, $a, $full)) . '</b>';
if (!$full && in_array($u['role'], PII_FULL_ROLES, true)) echo ' <a href="?id=' . (int)$a['id'] . '&full=1">顯示完整號碼（會留紀錄）</a>';
echo '</p>';
echo '<table>';
foreach (FORM_SPEC as [$title, $fields]) {
    echo '<tr><th colspan=2>' . h($title) . '</th></tr>';
    foreach ($fields as $f) {
        $k = $f['key'];
        $v = $k === 'name' ? $a['name'] : ($k === 'amount_requested' ? $a['amount_requested'] : ($a['data'][$k] ?? null));
        if (is_array($v) && isset($v['sel'])) {
            $s = implode('、', $v['sel']);
            if (!empty($v['other'])) $s .= '（' . $v['other'] . '）';
            foreach ($v['sub'] ?? [] as $opt => $sv) $s .= "；{$opt}：" . (is_array($sv) ? implode('、', array_map(fn($x, $y) => is_int($x) ? $y : "$x $y", array_keys($sv), $sv)) : $sv);
            $v = $s;
        } elseif (is_array($v)) $v = implode('、', array_map(fn($x, $y) => "$x $y", array_keys($v), $v));
        elseif (is_bool($v)) $v = $v ? '是' : '否';
        echo '<tr><td>' . h($f['label']) . '</td><td>' . nl2br(h((string)$v)) . '</td></tr>';
    }
}
echo '</table><h2>同住家屬</h2><table><tr>';
foreach (HOUSEHOLD_FIELDS as $l) echo '<th>' . h($l) . '</th>';
echo '</tr>';
foreach ($a['household'] as $r) {
    echo '<tr>';
    foreach (HOUSEHOLD_FIELDS as $k => $l) echo '<td>' . h($k === 'idno' ? (isset($r['idno_last4']) ? pii_mask($r['idno_head'], $r['idno_last4']) : '') : ($k === 'micro_ins' ? (!empty($r[$k]) ? '是' : '') : ($r[$k] ?? ''))) . '</td>';
    echo '</tr>';
}
echo '</table><h2>附件</h2><ul>';
$at = rdb()->prepare('SELECT id, kind, orig_name, size FROM attachment WHERE application_id = ? AND deleted_at IS NULL ORDER BY id');
$at->execute([$a['id']]);
foreach ($at as $f) echo '<li>' . h(ATTACH_KINDS[$f['kind']]) . '：<a href="/cases/file.php?id=' . (int)$f['id'] . '">' . h($f['orig_name']) . '</a>（' . round($f['size'] / 1024) . ' KB）</li>';
echo '</ul>';
$raw = app_row((int)$a['id']);
if ($raw['kind'] === 'new' && in_array($raw['status'], ['approved', 'closed'], true)) echo '<p><a class=btn href="/cases/followup.php?id=' . (int)$a['id'] . '">服務紀錄、異動與結案</a></p>';
if ($raw['kind'] === 'change') echo '<p class=note>這是異動申請，原案：<a href="/cases/view.php?id=' . (int)$raw['parent_id'] . '">開啟原案</a></p>';
if (in_array($u['role'], APPLY_ROLES, true) && in_array($raw['status'], ['draft', 'returned'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'submit') {
        csrf_check();
        $e = app_submit($u, (int)$a['id']);
        echo $e ? '<p class=err>' . h($e) . '</p>' : '<p><b>已送審。</b></p>';
        if (!$e) { page_foot(); exit; }
    }
    echo '<p><a class=btn href="/cases/new.php?id=' . (int)$a['id'] . '">編輯</a></p>';
    echo '<form method=post><input type=hidden name=csrf value="' . h(csrf_token()) . '"><input type=hidden name=act value=submit><button>' . ($raw['status'] === 'returned' ? '修改完成，再次送審' : '送審') . '</button></form>';
}
page_foot();
