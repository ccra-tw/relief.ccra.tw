<?php
// 新申請／編輯草稿（#5）。第一步輸入身分證號分流；第二步填表。
require __DIR__ . '/../../lib/boot.php';
require_once __DIR__ . '/../../lib/form_render.php';
$u = require_role(APPLY_ROLES);
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$err = []; $data = []; $hh = []; $phone = '';
$a = $id ? app_load($u, $id) : null;
if ($id && !$a) { http_response_code(404); exit('找不到這份申請。'); }
if ($a) { $data = $a['data'] + ['name' => $a['name'], 'amount_requested' => $a['amount_requested']]; $hh = $a['household']; $phone = $a['applicant_phone']; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (($_POST['step'] ?? '') === 'idno') {
        $r = intake_route((string)($_POST['idno'] ?? ''));
        if (isset($r['error'])) $err['idno'] = $r['error'];
        elseif ($r['route'] === 'change') $err['idno'] = "此個案已有案號 {$r['case_no']} 且開案未滿一年，請改走異動申請。";
        else { $_SESSION['intake_idno'] = idno_norm((string)$_POST['idno']); }
    } else {
        $in = $_POST;
        if (!$id) $in['idno'] = PHP_SAPI === 'cli' ? ($_POST['idno'] ?? '') : ($_SESSION['intake_idno'] ?? '');
        [$newId, $err] = app_save($u, $in, $id);
        if (!$err) {
            foreach (ATTACH_KINDS as $kind => $label) {
                if ($m = upload_err_msg((int)($_FILES[$kind]['error'] ?? UPLOAD_ERR_NO_FILE))) { $err[$kind] = "{$label}：$m"; continue; }
                if (!empty($_FILES[$kind]['tmp_name']) && is_uploaded_file($_FILES[$kind]['tmp_name']))
                    if ($e = attach_add($u, $newId, $kind, $_FILES[$kind]['tmp_name'], $_FILES[$kind]['name'])) $err[$kind] = "{$label}：$e";
            }
            unset($_SESSION['intake_idno']);
            if (!$err) { header('Location: /cases/view.php?id=' . $newId . '&saved=1'); echo "SAVED $newId"; exit; }
            $id = $newId;
        }
        [$data] = form_validate($_POST); $data['name'] = $_POST['name'] ?? ''; $data['amount_requested'] = $_POST['amount_requested'] ?? '';
        $hh = array_values(array_filter((array)($_POST['household'] ?? []), fn($r) => ($r['name'] ?? '') !== ''));
        $phone = $_POST['applicant_phone'] ?? '';
    }
}
page_head($id ? '編輯申請' : '新的急難救助申請', $u);
echo '<h1>' . ($id ? '編輯申請（草稿）' : '新的急難救助申請') . '</h1>';
if (isset($err['_'])) echo '<p class=err>' . h($err['_']) . '</p>';
// 表單送出但沒存成功時，頁首要講清楚；否則錯誤只散在各欄位旁，使用者會以為已經存了
$fieldErr = array_diff_key($err, ['_' => 1, 'idno' => 1]);
if ($fieldErr && ($_POST['step'] ?? '') !== 'idno') {
    echo '<div class="card err-summary" role=alert><p class=err>尚未儲存：有 ' . count($fieldErr) . ' 個欄位要補填或修正（下方紅字處）。</p><ul>';
    foreach (array_slice($fieldErr, 0, 15) as $m) echo '<li>' . h($m) . '</li>';
    if (count($fieldErr) > 15) echo '<li>…另有 ' . (count($fieldErr) - 15) . ' 項</li>';
    echo '</ul></div>';
}
$tok = h(csrf_token());
if (!$id && empty($_SESSION['intake_idno']) && PHP_SAPI !== 'cli') {
    echo "<form method=post class=card><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=step value=idno>";
    echo '<label>個案身分證字號或居留證號<input name=idno required autocomplete=off></label>';
    if (isset($err['idno'])) echo '<div class=err>' . h($err['idno']) . '</div>';
    echo '<p class=note>系統會先確認這位個案是否已有開案未滿一年的案件；有的話要走異動申請。</p><button>下一步</button></form>';
    page_foot(); exit;
}
if (!$id) echo '<p>個案身分證：<b>' . h(pii_mask(($_SESSION['intake_idno'] ?? 'X')[0], substr($_SESSION['intake_idno'] ?? '0000', -4))) . '</b></p>';
if (isset($err['idno'])) echo '<p class=err>' . h($err['idno']) . '</p>';
echo "<form method=post enctype=multipart/form-data><input type=hidden name=csrf value=\"$tok\">";
echo '<fieldset><legend>申請人</legend><label><span>申請人連絡電話 <span class=req>*</span></span><input name=applicant_phone value="' . h($phone) . '"></label>' . (isset($err['applicant_phone']) ? '<div class=err>' . h($err['applicant_phone']) . '</div>' : '') . '</fieldset>';
echo fr_form($data, $err);
echo fr_household($hh, $err);
echo '<fieldset><legend>檢附資料（PDF、JPG、PNG、Word；每檔 10MB 內）</legend>';
foreach (ATTACH_KINDS as $kind => $label) echo "<label>$label<input type=file name=$kind accept=\".pdf,.jpg,.jpeg,.png,.docx\"></label>" . (isset($err[$kind]) ? '<div class=err>' . h($err[$kind]) . '</div>' : '');
echo '</fieldset><button>儲存草稿</button></form><script src="/assets/form.js"></script>';
page_foot();
