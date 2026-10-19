<?php
// 自動測試（#2 骨架）。只動 schema test：RELIEF_TEST_DB=1 由本檔自己設，所有子程序也帶。
// 用法（主機上，要讀得到 db.ini 的身分）：php tests/run.php
putenv('RELIEF_TEST_DB=1');
putenv('RELIEF_TEST_DIR=' . __DIR__ . '/fixtures/directory.json');   // 名錄 API 一律用假資料
putenv('RELIEF_TEST_SECRET=1');                                       // 個資加密用測試金鑰
$UP = sys_get_temp_dir() . '/relief-test-uploads-' . getmypid();
putenv("RELIEF_UPLOAD_DIR=$UP");
require __DIR__ . '/../lib/boot.php';
$ROOT = realpath(__DIR__ . '/..');
$fail = 0;
function ok(bool $c, string $m) { global $fail; echo ($c ? "  ✅ " : "  ❌ ") . $m . "\n"; if (!$c) $fail++; }

// 子程序跑一頁：回傳 [exit code, 輸出]
function page(string $path, ?string $as = null, array $post = [], array $get = []): array {
    global $ROOT;
    $env = 'RELIEF_TEST_DB=1 ' . ($as ? 'RELIEF_TEST_USER=' . escapeshellarg($as) . ' ' : '');
    $req = escapeshellarg(json_encode(['page' => "$ROOT/public/$path", 'method' => $post ? 'POST' : 'GET', 'post' => $post, 'get' => $get]));
    exec("$env RELIEF_REQ=$req php " . escapeshellarg("$ROOT/tests/req.php") . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

$db = rdb();
// 正式 schema 的稽核筆數（測試前後要一樣，證明測試沒寫進正式表）
$prodBefore = (int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn();

// 測試 schema 重建
$db->exec('DROP SCHEMA IF EXISTS test CASCADE; CREATE SCHEMA test; SET search_path TO test');
exec("RELIEF_TEST_DB=1 php " . escapeshellarg("$ROOT/bin/migrate.php"), $mo, $mc);
ok($mc === 0, 'migration 在 test schema 套用成功');

// 假資料（全部虛構）
$db->exec("INSERT INTO region (code, name) VALUES ('T1','測試北區'), ('T2','測試南區')");
$ins = $db->prepare('INSERT INTO app_user (email, username, pass_hash, display_name, role, region_id) VALUES (?,?,?,?,?,?)');
$ins->execute(['admin@example.test', null, null, '測試管理者', 'admin', null]);
$ins->execute([null, 'church-demo', password_hash('Demo-pass-123', PASSWORD_DEFAULT), '測試教會同工', 'church_staff', null]);
$ins->execute(['worker@example.test', null, null, '測試區辦社工', 'region_worker', 1]);

echo "未登入\n";
[$c, $o] = page('index.php');
ok($c !== 0 && str_contains($o, '未登入'), '未登入看不到首頁');

echo "角色選單\n";
[$c, $o] = page('index.php', 'admin@example.test');
ok($c === 0 && str_contains($o, '系統管理') && str_contains($o, '報表與匯出'), '管理者看得到系統管理與報表');
[$c, $o] = page('index.php', 'church-demo');
ok($c === 0 && str_contains($o, '個案與申請') && !str_contains($o, '系統管理') && !str_contains($o, '撥款批次'), '教會同工只看得到個案，看不到管理與撥款');
[$c, $o] = page('index.php', 'worker@example.test');
ok(str_contains($o, '待我審核') && !str_contains($o, '系統管理'), '區辦社工看得到待我審核，看不到系統管理');

echo "教會帳號登入與稽核\n";
$n0 = (int)$db->query("SELECT count(*) FROM audit_log")->fetchColumn();
ok(password_login('church-demo', 'wrong') !== null, '錯的密碼登入失敗');
ok(password_login('church-demo', 'Demo-pass-123') === null, '對的密碼登入成功');
$acts = $db->query("SELECT action FROM audit_log ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
ok(array_slice($acts, -2) === ['login_fail', 'login'], '失敗與成功各寫一筆 audit_log');
ok((int)$db->query("SELECT count(*) FROM audit_log")->fetchColumn() === $n0 + 2, '每個動作恰好一筆，不多不少');
for ($i = 0; $i < 5; $i++) password_login('church-demo', 'wrong');
ok(str_contains((string)password_login('church-demo', 'Demo-pass-123'), '次數過多'), '連錯 5 次後即使密碼正確也被鎖住');
ok(password_login('nobody', 'x') === '帳號或密碼不正確。', '不存在的帳號與錯密碼回同一句話（不洩漏帳號是否存在）');

echo "權限檢查不靠藏選單\n";
file_put_contents("$ROOT/public/_t_admin.php", "<?php require __DIR__.'/../lib/boot.php'; require_role(['admin']); echo 'ADMIN-OK';");
[$c, $o] = page('_t_admin.php', 'church-demo');
ok(!str_contains($o, 'ADMIN-OK') && str_contains($o, '沒有這個功能的權限'), '教會同工直接打管理頁網址被擋');
ok((int)$db->query("SELECT count(*) FROM audit_log WHERE action='deny'")->fetchColumn() === 1, '被擋也留 audit_log');
[$c, $o] = page('_t_admin.php', 'admin@example.test');
ok(str_contains($o, 'ADMIN-OK'), '管理者進得去');
unlink("$ROOT/public/_t_admin.php");

echo "區域主檔（#3）\n";
$n0 = (int)$db->query("SELECT count(*) FROM audit_log WHERE action='create' AND target_type='region'")->fetchColumn();
[$c, $o] = page('admin/regions.php', 'admin@example.test', ['act' => 'region_add', 'code' => 't3', 'name' => '測試東區']);
ok(str_contains($o, '已新增區辦 T3') && (int)$db->query("SELECT count(*) FROM region WHERE code='T3'")->fetchColumn() === 1, '管理者新增區辦（代碼自動轉大寫）');
ok((int)$db->query("SELECT count(*) FROM audit_log WHERE action='create' AND target_type='region'")->fetchColumn() === $n0 + 1, '新增區辦寫 audit_log');
[$c, $o] = page('admin/regions.php', 'admin@example.test', ['act' => 'region_add', 'code' => 'T3', 'name' => '重複']);
ok(str_contains($o, '已存在'), '區辦碼重複會被擋');
[$c, $o] = page('admin/regions.php', 'admin@example.test', ['act' => 'area_add', 'region_id' => '1', 'code' => 'A', 'name' => '測試甲區']);
page('admin/regions.php', 'admin@example.test', ['act' => 'area_add', 'region_id' => '2', 'code' => 'B', 'name' => '測試乙區']);
ok((int)$db->query("SELECT count(*) FROM area")->fetchColumn() === 2, '兩個區辦各新增一個區域');
[$c, $o] = page('admin/regions.php', 'church-demo', ['act' => 'region_add', 'code' => 'X9', 'name' => '不該成功']);
ok((int)$db->query("SELECT count(*) FROM region WHERE code='X9'")->fetchColumn() === 0, '非管理者送表單也改不了主檔');

echo "資料範圍在查詢層（#3）\n";
$ins->execute(['assist@example.test', null, null, '測試社工助理', 'assistant', 1]);
$ins->execute(['assist2@example.test', null, null, '未設區辦的助理', 'assistant', null]);
$ins->execute(['hq@example.test', null, null, '測試服管組社工', 'hq_worker', null]);
$areasFor = function (string $who) use ($db) {
    $uu = load_user('email', $who);
    [$w, $p] = scope_sql($uu, 'region_id');
    $s = $db->prepare("SELECT code FROM area WHERE $w ORDER BY code"); $s->execute($p);
    return $s->fetchAll(PDO::FETCH_COLUMN);
};
ok($areasFor('assist@example.test') === ['A'], '社工助理只查得到自己區辦的資料');
ok($areasFor('assist2@example.test') === [], '沒設區辦的助理一筆都查不到（不會退回全部）');
ok($areasFor('hq@example.test') === ['A', 'B'], '服管組查得到全部');
[$w] = scope_sql(load_user('username', 'church-demo'), 'region_id', 'church_oid');
ok($w === 'FALSE', '沒綁教會的教會帳號一筆都看不到');

echo "會簽設定（#3）\n";
$hqHead = $ins->execute(['head@example.test', null, null, '測試服管組主任', 'hq_head', null]);
$headId = (int)$db->query("SELECT id FROM app_user WHERE email='head@example.test'")->fetchColumn();
$wId = (int)$db->query("SELECT id FROM app_user WHERE email='worker@example.test'")->fetchColumn();
$asId = (int)$db->query("SELECT id FROM app_user WHERE email='assist@example.test'")->fetchColumn();
$base = ['act' => 'edit', 'id' => (string)$wId, 'display_name' => '測試區辦社工', 'role' => 'region_worker', 'region_id' => '1', 'active' => '1'];
[$c, $o] = page('admin/users.php', 'admin@example.test', $base + ['needs_cosign' => '1']);
ok(str_contains($o, '就要指定會簽者'), '勾須會簽卻沒指定會簽者會被擋');
[$c, $o] = page('admin/users.php', 'admin@example.test', $base + ['needs_cosign' => '1', 'cosigner_id' => (string)$asId]);
ok(str_contains($o, '會簽者只能是'), '社工助理不能當會簽者');
[$c, $o] = page('admin/users.php', 'admin@example.test', $base + ['needs_cosign' => '1', 'cosigner_id' => (string)$headId]);
$row = $db->query("SELECT needs_cosign, cosigner_id FROM app_user WHERE id=$wId")->fetch();
ok($row['needs_cosign'] === true && (int)$row['cosigner_id'] === $headId, '指定服管組主任為固定會簽者成功');
[$c, $o] = page('admin/users.php', 'admin@example.test', ['act' => 'add', 'display_name' => '測試新教會', 'username' => 'church-new', 'role' => 'church_staff']);
ok(preg_match('/初始密碼（只顯示這一次）：(\S{12})/u', $o, $mm) === 1 && password_login('church-new', $mm[1]) === null, '新增教會帳號產生 12 碼初始密碼，且能用它登入');

echo "教會主檔（#4）\n";
$ins->execute(['head1@example.test', null, null, '測試北區主任', 'region_head', 1]);
$ins->execute(['head2@example.test', null, null, '測試南區主任', 'region_head', 2]);
$W = load_user('email', 'worker@example.test'); $H1 = load_user('email', 'head1@example.test'); $H2 = load_user('email', 'head2@example.test');
$aA = (int)$db->query("SELECT id FROM area WHERE code='A'")->fetchColumn(); $aB = (int)$db->query("SELECT id FROM area WHERE code='B'")->fetchColumn();
ok(count(dir_search('測試')) === 3 && dir_search('恩典')[0]['oid'] === '900001', '名錄搜尋（假資料）');
ok(church_bind($W, '900001', $aA) === null, '區辦社工把名錄教會綁到自己區辦的區域');
ok(str_contains((string)church_bind($W, '900002', $aB), '所屬區辦'), '不能綁到別的區辦的區域');
ok(str_contains((string)church_bind($W, '900001', $aA), '已經綁定'), '同一個 oid 不能綁兩次（oid 為唯一鍵）');
ok(str_contains((string)church_bind($W, '999999', $aA), '查無'), '名錄沒有的 oid 綁不了');
ok(church_can_apply('900001') && !church_can_apply('900003'), '只有已綁定的教會可以提出急難申請');
ok(church_add_request($W, ['name' => '測試未登錄教會', 'tel' => '00-0000000', 'city' => '測試市', 'address' => '測試路9號', 'area_id' => $aA]) === null, '不在名錄的教會送出新增申請');
$addId = (int)$db->query("SELECT id FROM church_add_request ORDER BY id DESC LIMIT 1")->fetchColumn();
ok(!church_can_apply('900003'), '名錄核可前仍不能申請');
ok(church_add_approved($W, $addId, '900003') === null && church_can_apply('900003'), '名錄核可並填回 oid 後可以申請');

echo "教會帳號申請（#4）\n";
ok(str_contains((string)account_request_create($H1, ['church_oid' => '900001', 'username' => 'grace01', 'display_name' => '測試同工', 'role' => 'church_staff']), '區辦社工提出'), '只有區辦社工能提出帳號申請');
ok(account_request_create($W, ['church_oid' => '900001', 'username' => 'grace01', 'display_name' => '測試同工', 'role' => 'church_staff', 'email' => 'church@example.test']) === null, '區辦社工提出帳號申請');
ok(str_contains((string)account_request_create($W, ['church_oid' => '900001', 'username' => 'grace01', 'display_name' => 'x', 'role' => 'church_staff']), '已有人使用或正在申請'), '同一帳號不能重複申請');
$rq = (int)$db->query("SELECT id FROM account_request WHERE username='grace01'")->fetchColumn();
[$e] = account_request_decide($H2, $rq, true);
ok(str_contains((string)$e, '該區辦主任'), '別區的主任不能核可');
[$e] = account_request_decide($H1, $rq, false, '');
ok(str_contains((string)$e, '原因'), '退回一定要寫原因');
$nNoti = (int)$db->query("SELECT count(*) FROM notification")->fetchColumn();
[$e, $pw0] = account_request_decide($H1, $rq, true);
ok($e === null && strlen((string)$pw0) === 12 && password_login('grace01', $pw0) === null, '本區主任核可 → 開通帳號，初始密碼能登入');
$gu = load_user('username', 'grace01');
ok($gu['role'] === 'church_staff' && $gu['church_oid'] === '900001', '新帳號綁在該教會、角色正確');
$notes = $db->query("SELECT to_user_id, to_email, body FROM notification ORDER BY id DESC LIMIT 2")->fetchAll();
ok((int)$db->query("SELECT count(*) FROM notification")->fetchColumn() === $nNoti + 2, '核可寫兩筆通知（申請的社工＋教會信箱）');
ok(!str_contains($notes[0]['body'] . $notes[1]['body'], $pw0), '初始密碼不寫進任何通知信');
[$w] = scope_sql($gu, 'region_id', 'church_oid');
ok($w === 'church_oid = ?', '教會帳號的可見範圍限縮到自己教會');

echo "申請表欄位驗證（#5）\n";
// 依檢查碼產生虛構但格式正確的身分證號
function fake_id(string $head): string {
    $map = 'ABCDEFGHJKLMNPQRSTUVXYWZIO';
    for ($d = 0; $d <= 9; $d++) { $s = $head . $d; if (idno_valid($s)) return $s; }
    throw new Exception('no check digit');
}
$ID1 = fake_id('F12345678'); $ID2 = fake_id('F22233344'); $IDR = fake_id('A80000001');
ok(idno_valid($ID1) && idno_valid($IDR) && !idno_valid('F123456789' === $ID1 ? 'F123456788' : 'F123456789'), '身分證與新式居留證檢查碼驗證');
$good = ['category' => '一般急難', 'name' => '測試個案甲', 'nationality' => '中華民國', 'gender' => '女', 'birthday' => '1980-01-01', 'mobile' => '0900 000 000',
    'marital' => '已婚', 'address' => '測試市測試路1號', 'housing' => '租屋', 'source' => '教會發掘', 'religion' => '基督教', 'religion__5' => '本會會友',
    'support' => '否', 'debt' => ['銀行債'], 'debt__2' => ['30,000', '', '', ''], 'employment' => '全職', 'subsidy' => ['無'], 'emergency' => ['意外'],
    'before' => '測試原本情況', 'event' => '測試急難事件', 'plan' => '測試未來計畫', 'income_before' => ['20000', '0'], 'income_now' => ['0', '0'],
    'spend_before' => array_fill(0, 10, '0'), 'spend_after' => array_fill(0, 10, '0'), 'church_service' => ['禱告扶持'], 'usage' => ['基本生活費'], 'amount_requested' => '12,000'];
[$d, $e] = form_validate($good);
ok($e === [] && $d['amount_requested'] === 12000 && $d['debt']['sub']['銀行債']['卡債'] === 30000 && $d['mobile'] === '0900000000', '完整填寫通過；金額去逗號、電話去空白、銀行債次選項金額');
[$d, $e] = form_validate(['name' => ''] + $good);
ok(isset($e['name']), '必填欄位空白會被擋');
[$d, $e] = form_validate(['housing' => '其他'] + $good);
ok(isset($e['housing_other']), '選「其他」沒填說明會被擋');
[$d, $e] = form_validate(['housing' => '其他', 'housing_other' => '借住親友家'] + $good);
ok(!isset($e['housing_other']) && $d['housing']['other'] === '借住親友家', '選「其他」有填說明就通過');
[$d, $e] = form_validate(['religion' => '基督教', 'religion__5' => ''] + $good);
ok(isset($e['religion__5']), '基督教沒選本會／他會會被擋（次選項必填）');
[$d, $e] = form_validate(['debt' => ['無', '銀行債']] + $good);
ok(isset($e['debt']), '債務選「無」又選別項會被擋');
[$d, $e] = form_validate(['housing' => '城堡'] + $good);
ok(isset($e['housing']), '不在選項裡的值會被丟掉並視為未選');
[$d, $e] = form_validate(['amount_requested' => '一萬'] + $good);
ok(isset($e['amount_requested']), '金額只能填數字');
ok(norm_account(' 0123 4567 89 ') === '0123456789' && norm_account('123-456') === null, '帳戶欄位自動去空白、只允許數字（需求 0004）');

echo "建立申請與個資保護（#5）\n";
$ins->execute([null, 'grace-staff', password_hash('x', PASSWORD_DEFAULT), '測試教會同工二', 'church_staff', null]);
$db->exec("UPDATE app_user SET church_oid='900001' WHERE username IN ('grace-staff')");
$CS = load_user('username', 'grace-staff');
[$aid, $e] = app_save($CS, $good + ['idno' => $ID1, 'applicant_phone' => '02-0000000',
    'household' => [['name' => '測試家屬一', 'idno' => $ID2, 'relation' => '子'], ['name' => '', 'idno' => '']]]);
ok($aid && $e === [], '教會同工建立申請（空白家屬列略過）');
$raw = json_encode($db->query("SELECT * FROM person")->fetchAll()) . json_encode($db->query("SELECT * FROM application")->fetchAll());
ok(!str_contains($raw, $ID1) && !str_contains($raw, $ID2), '資料庫裡找不到個案與家屬身分證號明文');
$hhRow = json_decode($db->query("SELECT household FROM application WHERE id=$aid")->fetchColumn(), true);
ok(count($hhRow) === 1 && pii_decrypt($hhRow[0]['idno_enc']) === $ID2, '家屬身分證號是密文、可用金鑰解回');
[$x, $e] = app_save($CS, $good + ['idno' => 'A123456780', 'applicant_phone' => '1']);
ok(isset($e['idno']), '檢查碼錯的身分證號擋下');
$WK = load_user('email', 'worker@example.test');   // 區辦社工（區辦 1，與 900001 同區）
ok(!array_key_exists('status', app_load($CS, $aid)) && array_key_exists('status', app_load($WK, $aid)), '教會端看不到審核狀態，區辦看得到（需求 0002）');
$CSa = app_load($CS, $aid);
ok(app_idno_display($CS, $CSa, true) === pii_mask($ID1[0], substr($ID1, -4)), '教會端即使要求也只看到遮罩號碼');
$nView = (int)$db->query("SELECT count(*) FROM audit_log WHERE action='view' AND target_type='idno'")->fetchColumn();
ok(app_idno_display($WK, app_load($WK, $aid), true) === $ID1 && (int)$db->query("SELECT count(*) FROM audit_log WHERE action='view' AND target_type='idno'")->fetchColumn() === $nView + 1, '區辦社工可看完整號碼，且留一筆稽核');
$ins->execute(['worker2@example.test', null, null, '測試南區社工', 'region_worker', 2]);
$W2 = load_user('email', 'worker2@example.test');
ok(app_load($W2, $aid) === null, '別區社工看不到這份申請');
$db->prepare("INSERT INTO assignment (application_id, stage, user_id) VALUES (?, 'other_region', ?)")->execute([$aid, $W2['id']]);
ok(app_load($W2, $aid) !== null, '被派案的他區社工看得到');

echo "分流（#5）\n";
ok(intake_route($ID2)['route'] === 'new', '沒有開案紀錄的人走新申請');
$db->exec("UPDATE application SET case_no='T1-A-2026-001', opened_at=current_date - 30 WHERE id=$aid");
$r = intake_route($ID1);
ok($r['route'] === 'change' && $r['case_no'] === 'T1-A-2026-001', '開案未滿一年 → 走異動');
[$x, $e] = app_save($CS, $good + ['idno' => $ID1, 'applicant_phone' => '1']);
ok(isset($e['idno']) && str_contains($e['idno'], '異動'), '這種情況下新申請會被擋並提示走異動');
$db->exec("UPDATE application SET opened_at=current_date - 400 WHERE id=$aid");
ok(intake_route($ID1)['route'] === 'new', '開案超過一年 → 可以新申請');

echo "附件（#5）\n";
$tmpPng = tempnam(sys_get_temp_dir(), 'rt'); file_put_contents($tmpPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
ok(attach_add($CS, $aid, 'proof', $tmpPng, '證明.png') === null, 'PNG 上傳成功');
$st = $db->query("SELECT stored_name, sha256 FROM attachment ORDER BY id DESC LIMIT 1")->fetch();
ok(is_file("$UP/{$st['stored_name']}") && hash_file('sha256', "$UP/{$st['stored_name']}") === $st['sha256'], '檔案存在主機目錄，資料庫只有中繼資料與雜湊');
$tmpExe = tempnam(sys_get_temp_dir(), 'rt'); file_put_contents($tmpExe, "MZ\x90\x00 fake exe");
ok(str_contains((string)attach_add($CS, $aid, 'proof', $tmpExe, '偽裝.pdf'), '只接受'), '副檔名改成 .pdf 的執行檔照樣擋下（以內容判斷）');
$tmpBig = tempnam(sys_get_temp_dir(), 'rt'); $fh = fopen($tmpBig, 'w'); fwrite($fh, "%PDF-1.4\n"); fseek($fh, ATTACH_MAX + 10); fwrite($fh, 'x'); fclose($fh);
ok(str_contains((string)attach_add($CS, $aid, 'proof', $tmpBig, 'big.pdf'), '10MB'), '超過 10MB 擋下');
ok(attach_add($W2, $aid, 'bogus', $tmpPng, 'x.png') !== null, '附件類別不在清單內擋下');
ok(upload_err_msg(UPLOAD_ERR_NO_FILE) === null && upload_err_msg(UPLOAD_ERR_OK) === null, '沒選檔案不算錯');
ok(str_contains((string)upload_err_msg(UPLOAD_ERR_INI_SIZE), '10MB') && str_contains((string)upload_err_msg(UPLOAD_ERR_PARTIAL), '完整'), '超過主機上限或傳一半會顯示原因，不再靜默丟掉');
$ini = parse_ini_file(dirname(__DIR__) . '/public/.user.ini');
ok($ini['upload_max_filesize'] === '10M' && (int)$ini['post_max_size'] >= 2 * 10 + 2, '主機上傳上限與 ATTACH_MAX 一致，兩個附件加表單放得下');
foreach ([$tmpPng, $tmpExe, $tmpBig] as $t) @unlink($t);
array_map('unlink', glob("$UP/*") ?: []); @rmdir($UP);

echo "審核四關＋會簽＋派案（#6）\n";
$ins->execute([null, 'grace-pastor', password_hash('x', PASSWORD_DEFAULT), '測試牧者', 'church_pastor', null]);
$db->exec("UPDATE app_user SET church_oid='900001' WHERE username='grace-pastor'");
$PA = load_user('username', 'grace-pastor');
$WK = load_user('email', 'worker@example.test');           // 區辦 1，須會簽（前面設定會簽者＝服管組主任）
$HEAD = load_user('email', 'head@example.test');           // 服管組主任（會簽者）
$W2 = load_user('email', 'worker2@example.test');          // 區辦 2（他區）
$ID3 = fake_id('F11122233');
[$app, $e] = app_save($CS, $good + ['idno' => $ID3, 'applicant_phone' => '02-1']);
ok($app && !$e, '建立第二份申請');
ok(app_submit($CS, $app) === null, '教會同工送審');
$A = app_row($app);
ok($A['status'] === 'pastor_review' && preg_match('/^T1-A-' . date('Y') . '-\d{4}$/', $A['case_no']), '送審即編案號 ZZ-X-YYYY-NNNN，進到教會牧者關');
$no1 = $A['case_no'];
ok(str_contains((string)review_act($WK, $app, 'approve', '好'), '不在您的審核關卡'), '區辦社工不能審牧者那一關');
ok(str_contains((string)review_act($PA, $app, 'approve', ''), '意見'), '審核意見必填');
ok(review_act($PA, $app, 'approve', '牧者同意') === null && app_row($app)['status'] === 'region_review', '牧者完成審核 → 區辦');
ok(str_contains((string)review_act($WK, $app, 'approve', '同意'), '第一期必填'), '區辦沒配期數金額不能過（需求 0003）');
ok(str_contains((string)review_act($WK, $app, 'approve', '同意', ['', '6000']), '第一期'), '第一期空白、只填第二期也不行');
ok(review_act($WK, $app, 'approve', '區辦同意', ['6,000', '6000']) === null, '區辦配置兩期金額並完成審核');
$A = app_row($app);
ok($A['status'] === 'region_review' && (int)$A['cosign_user_id'] === (int)$HEAD['id'], '區辦社工須會簽 → 停在區辦關，等固定會簽者');
ok(review_act($W2, $app, 'approve', 'x') !== null && review_act($WK, $app, 'approve', '再按一次') !== null, '會簽中，其他人（含原審核者）都不能動');
ok(review_act($HEAD, $app, 'approve', '會簽同意') === null && app_row($app)['status'] === 'other_review', '會簽完成 → 他區');
$asg = (int)$db->query("SELECT user_id FROM assignment WHERE application_id=$app AND stage='other_region'")->fetchColumn();
ok($asg === (int)$W2['id'], '他區隨機派案，排除本區（只派到區辦 2 的社工）');
ok(review_act($W2, $app, 'return', '') !== null, '退件原因必填');
$nNoti = (int)$db->query("SELECT count(*) FROM notification WHERE kind='church_result'")->fetchColumn();
$nRet = (int)$db->query("SELECT count(*) FROM notification WHERE kind='review_returned'")->fetchColumn();
ok(review_act($W2, $app, 'return', '缺證明文件') === null && app_row($app)['status'] === 'region_review' && !app_row($app)['cosign_user_id'], '他區社工退件 → 退回上一關（區辦社工），不是會簽者（服管組 10/16）');
ok((int)$db->query("SELECT count(*) FROM notification WHERE kind='church_result'")->fetchColumn() === $nNoti
   && (int)$db->query("SELECT count(*) FROM notification WHERE kind='review_returned'")->fetchColumn() > $nRet, '退回區辦時通知區辦社工，不通知教會');
ok(str_contains((string)review_act($HEAD, $app, 'approve', '會簽'), '不在您的審核關卡'), '退回後輪到區辦社工，會簽者不能先動');
// 不靠機率：暫時把原審核者改成本案同區，讓他「不可能被隨機抽中」——這時他還被派到，只能是因為沿用原派案
$ins->execute(['worker3@example.test', null, null, '測試南區社工二', 'region_worker', 2]);
$db->exec("UPDATE app_user SET region_id = 1 WHERE id = {$W2['id']}");
review_act($WK, $app, 'approve', '區辦再審', ['6000', '6000']);
ok((int)app_row($app)['cosign_user_id'] === (int)$HEAD['id'], '區辦社工再審後照樣要會簽');
review_act($HEAD, $app, 'approve', '會簽');
$db->exec("UPDATE app_user SET region_id = 2 WHERE id = {$W2['id']}");
$asg2 = $db->query("SELECT user_id, count(*) OVER () AS n FROM assignment WHERE application_id=$app AND stage='other_region'")->fetch();
ok((int)$asg2['user_id'] === (int)$W2['id'] && (int)$asg2['n'] === 1, '被退回後再送審，回到原本那位他區社工（需求 0001）');
ok(review_act($W2, $app, 'approve', '他區同意') === null && app_row($app)['status'] === 'hq_review', '他區（不須會簽）完成 → 服管組');
$hqId = (int)$db->query("SELECT user_id FROM assignment WHERE application_id=$app AND stage='hq'")->fetchColumn();
$HQ = load_user('id', (string)$hqId);
ok(in_array($HQ['role'], ['hq_worker', 'hq_head'], true), '服管組關卡隨機派給服管組社工或主任');
ok(review_act($HQ, $app, 'approve', '總會同意', ['6000', '6000', '6000']) === null, '服管組完成審核（可調整期數）');
$A = app_row($app);
ok($A['status'] === 'approved' && $A['opened_at'] === date('Y-m-d'), '申請通過，開案日＝通過日');
ok((int)$db->query("SELECT count(*) FROM installment WHERE application_id=$app")->fetchColumn() === 3, '期數以最後一關配置為準（三期）');
ok((int)$db->query("SELECT count(*) FROM review_step WHERE application_id=$app")->fetchColumn() === 9, '每個成功的動作恰好一筆 review_step（送審 1、牧者 1、區辦 2、會簽 2、他區退件與通過 2、服管組 1）');

echo "牧者自己送出、不通過（#6）\n";
$ID4 = fake_id('F15151515');
[$app2] = app_save($PA, $good + ['idno' => $ID4, 'applicant_phone' => '02-2']);
ok(app_submit($PA, $app2) === null && app_row($app2)['status'] === 'region_review', '牧者自己送出 → 直接到區辦（牧者關視為完成）');
ok(review_act($WK, $app2, 'reject', '不符資格') === null && app_row($app2)['status'] === 'rejected', '區辦不通過 → 結束');
ok(app_submit($PA, $app2) !== null, '不通過的案子不能再送審');

echo "審核修改表（#6）\n";
$ins->execute(['rhead1@example.test', null, null, '測試區辦主任', 'region_head', 1]);
$RH = load_user('email', 'rhead1@example.test'); $ADM = load_user('email', 'admin@example.test');
$expect = [   // [狀態, 使用者, 期望]
    ['draft', $CS, 'all'], ['draft', $PA, 'all'], ['draft', $WK, 'all'], ['draft', $HQ['role'] === 'hq_worker' ? $HQ : load_user('email', 'hq@example.test'), 'all'], ['draft', $RH, null],
    ['pastor_review', $CS, 'all'], ['region_review', $CS, null], ['region_review', $WK, 'all'], ['region_review', $W2, null],
    ['other_review', $W2, 'all'], ['other_review', load_user('email', 'worker3@example.test'), null], ['other_review', $CS, null],
    ['hq_review', $WK, 'all'], ['hq_review', $W2, 'all'],
    ['approved', $CS, 'basic'], ['approved', $PA, 'basic'], ['approved', $WK, 'basic'], ['approved', load_user('email', 'hq@example.test'), null], ['approved', $ADM, 'all'],
    ['rejected', $WK, null], ['rejected', $ADM, 'all'],
];
$bad = [];
foreach ($expect as [$st, $who, $want]) { $row = app_row($app); $row['status'] = $st; if (edit_level($who, $row) !== $want) $bad[] = "$st/{$who['display_name']}"; }
ok($bad === [], '狀態 × 角色的修改權限與審核修改表一致（' . count($expect) . ' 格）' . ($bad ? '：' . implode('、', $bad) : ''));
$e = app_update($CS, $app, ['name' => '改名後', 'mobile' => '0911 111 111', 'address' => '新地址', 'event' => '偷改內容']);
$A = app_row($app); $D = json_decode($A['data'], true);
ok($e === [] && $A['name'] === '改名後' && $D['mobile'] === '0911111111' && $D['event'] === '測試急難事件', '通過後教會只能改基本資料，其他欄位不會被改動');
ok(isset(app_update(load_user('email', 'hq@example.test'), $app, $good)['_']), '通過後服管組社工不能改（審核修改表空白）');
$actSrc = file_get_contents("$ROOT/public/review/act.php");
ok(str_contains($actSrc, '>通過<') && str_contains($actSrc, '>不通過<') && !str_contains($actSrc, '核准') && !str_contains($actSrc, '>完成審核<'), '審核按鈕是「通過／退件／不通過」（服管組 10/16 改），不用「核准」');

// 把一案從送審一路審到通過（測試用）
function approve_all(int $id, array $by): void {
    global $PA, $WK, $HEAD, $db;
    app_submit($by, $id);
    if (app_row($id)['status'] === 'pastor_review') review_act($PA, $id, 'approve', '牧者');
    review_act($WK, $id, 'approve', '區辦', ['5000']);
    review_act($HEAD, $id, 'approve', '會簽');
    foreach (['other_review' => 'other_region', 'hq_review' => 'hq'] as $st => $stage) {
        $uid = (int)$db->query("SELECT user_id FROM assignment WHERE application_id=$id AND stage='$stage'")->fetchColumn();
        review_act(load_user('id', (string)$uid), $id, 'approve', $stage);
    }
}
$chg = ['category' => '一般急難', 'change_items' => ['金額'], 'change_reason' => '案家仍需要金援', 'change_desc' => '測試：第一期已核發，仍需支援', 'amount_requested' => '8000'];

echo "異動申請（#7）\n";
$p0 = app_row($app);
[$c1, $e] = change_create($CS, $app, $chg);
ok($c1 && !$e && app_row($c1)['kind'] === 'change' && (int)app_row($c1)['parent_id'] === $app, '已通過的原案可建立異動申請');
[$x, $e] = change_create($CS, $app, $chg);
ok(isset($e['_']) && str_contains($e['_'], '重疊'), '前一筆異動還在處理中，不能重疊申請');
ok(app_submit($CS, $c1) === null && app_row($c1)['case_no'] === null && app_row($c1)['status'] === 'pastor_review', '異動送審走同一套審核引擎，不另編案號');
$C1 = app_row($c1);
ok(edit_level($CS, $C1) === null && edit_level($PA, $C1) === 'all', '「教會牧者異動審核中」教會同工不能改、牧者可改（審核修改表）');
approve_all($c1, $CS);
ok(app_row($c1)['status'] === 'approved' && app_row($c1)['opened_at'] === null && app_row($app)['opened_at'] === $p0['opened_at'], '異動通過；原案開案日不變，異動不另算開案');
ok(app_row($app)['status'] === 'approved', '原案狀態不受異動影響（狀態不混淆）');
[$x, $e] = change_create($CS, $app, $chg);
ok(isset($e['_']) && str_contains($e['_'], '最多申請 2 次'), '12 個月內原案＋1 次異動後，不能再異動');
$db->exec("UPDATE application SET opened_at = current_date - interval '13 months' WHERE id=$app");
[$x, $e] = change_create($CS, $app, $chg);
ok(isset($e['_']) && str_contains($e['_'], '超過開案後 12 個月'), '超過開案月份起算 12 個月的區間，不能異動');
$db->exec("UPDATE application SET opened_at = '{$p0['opened_at']}' WHERE id=$app");
[$w0, $w1] = change_window(['opened_at' => '2026-09-12']);
ok($w0 === '2026-09-01' && $w1 === '2027-08-31', '區間計算與服管組範例一致（9/12 開案 → 到 2027/8/31）');

echo "服務紀錄（#7）\n";
$svc = ['volunteers' => '2', 'mode' => '家訪', 'served_at' => date('Y-m-d') . 'T10:30', 'issues' => ['債務'], 'content' => ['禱告扶持'],
        'imp_emergency' => '稍有改善', 'imp_resource' => '尚未連結其他資源', 'imp_mood' => '維持原狀', 'faith' => '是', 'faith__0' => '一週1-2次'];
ok(service_add($CS, $app, $svc) === [], '教會新增服務紀錄');
ok(isset(service_add($CS, $app, ['faith' => '是'] + array_diff_key($svc, ['faith__0' => 1]))['faith__0']), '信仰狀況選「是」沒選細項會被擋');
ok(isset(service_add($WK, $app, $svc)['_']), '服務紀錄由教會填，區辦不能代填');
$db->exec("UPDATE service_record SET served_at = now() - interval '12 days' WHERE application_id=$app");
$db->exec("UPDATE application SET opened_at = current_date - 20 WHERE id=$app");
ok(service_days_since(app_row($app)) === 12, '距上次服務紀錄 12 天（≥10 天要提醒）');
$db->exec("UPDATE application SET opened_at = '{$p0['opened_at']}' WHERE id=$app");

echo "結案（#7）\n";
$cls = ['close_type' => '正常結案'] + $svc + ['followup' => '是', 'followup__0' => '開始進入教會牧養'];
ok(isset(closure_submit($CS, $app, ['close_type' => '正常結案'] + $svc)['followup']), '結案問卷（後續服務）必填');
ok(closure_submit($CS, $app, $cls) === [], '正常結案送出');
$A = app_row($app);
ok($A['status'] === 'closed' && $A['closed_at'] === date('Y-m-d'), '正常結案不需審核，結案日＝送出日');
ok(edit_level($ADM, $A) === null && edit_level($CS, $A) === null, '結案後唯讀，管理者也不能改');
ok(isset(service_add($CS, $app, $svc)['_']) && isset(change_create($CS, $app, $chg)[1]['_']), '結案後不能再加服務紀錄或異動');
ok(attach_add($CS, $app, 'proof', __FILE__, 'x.txt') !== null, '結案後不能再上傳附件');

$ID5 = fake_id('F19191919');
[$app5] = app_save($CS, $good + ['idno' => $ID5, 'applicant_phone' => '02-5']);
approve_all($app5, $CS);
ok(app_row($app5)['status'] === 'approved', '再建一案並審核通過（給提早結案用）');
ok(closure_submit($CS, $app5, ['close_type' => '案主死亡，提早結案'] + $cls) === [], '提早結案（2-3 選項）送出');
ok(app_row($app5)['status'] === 'approved' && (int)$db->query("SELECT count(*) FROM closure WHERE application_id=$app5 AND status='pending_review'")->fetchColumn() === 1, '要等區辦審核，個案還沒結');
ok(isset(closure_submit($CS, $app5, $cls)['_']), '已有一份待審，不能再送');
$cid = (int)$db->query("SELECT id FROM closure WHERE application_id=$app5")->fetchColumn();
$db->exec("UPDATE closure SET submitted_at = now() - interval '3 days' WHERE id=$cid");
ok(str_contains((string)closure_review($W2, $cid, 'approve', '好'), '區辦社工審核'), '別區社工不能審');
ok(closure_review($WK, $cid, 'approve', '') !== null, '審核意見必填');
ok(closure_review($WK, $cid, 'approve', '同意結案') === null, '本區社工審核通過');
ok(app_row($app5)['status'] === 'closed' && app_row($app5)['closed_at'] === date('Y-m-d', strtotime('-3 days')), '結案日＝教會送出結案報告那天（不是審核那天）');

echo "撥款批次（#8）\n";
// 直接造兩個已通過的案子（審核流程前面已測過）：甲在區辦 1（兩期）、乙在區辦 2（一期）
ok(church_bind($W2, '900002', $aB) === null, '區辦 2 綁定第二間教會');
$ins->execute([null, 'peace-staff', password_hash('x', PASSWORD_DEFAULT), '測試平安同工', 'church_staff', null]);
$db->exec("UPDATE app_user SET church_oid='900002' WHERE username='peace-staff'");
$PS = load_user('username', 'peace-staff');
[$pa] = app_save($CS, $good + ['idno' => fake_id('F20202020'), 'applicant_phone' => '1']);
[$pb] = app_save($PS, $good + ['idno' => fake_id('F21212121'), 'applicant_phone' => '1']);
$db->exec("UPDATE application SET status='approved', opened_at=current_date, case_no='T1-A-2026-0901' WHERE id=$pa");
$db->exec("UPDATE application SET status='approved', opened_at=current_date, case_no='T2-B-2026-0902' WHERE id=$pb");
$db->exec("INSERT INTO installment (application_id, seq, amount, set_stage, set_by) VALUES ($pa,1,6000,'hq',{$WK['id']}),($pa,2,4000,'hq',{$WK['id']}),($pb,1,7000,'hq',{$W2['id']})");
ok(str_contains((string)church_account_set($WK, '900001', ['bank_name' => '測試銀行', 'bank_code' => '013', 'branch_code' => '0001', 'account_no' => '12-34', 'account_name' => '測試恩典教會']), '只能是數字'), '帳號含非數字會被擋（需求 0004）');
ok(church_account_set($WK, '900001', ['bank_name' => '測試銀行', 'bank_code' => '013', 'branch_code' => '0001', 'account_no' => ' 1234 5678 ', 'account_name' => '測試恩典教會']) === null
   && $db->query("SELECT account_no FROM church WHERE oid='900001'")->fetchColumn() === '12345678', '帳號自動去空白後存純數字');
ok(church_account_set($WK, '900002', ['bank_name' => 'x', 'bank_code' => '1', 'branch_code' => '1', 'account_no' => '1', 'account_name' => 'x']) !== null, '區辦不能設定別區教會的帳戶');
church_account_set($W2, '900002', ['bank_name' => '測試銀行', 'bank_code' => '013', 'branch_code' => '0002', 'account_no' => '87654321', 'account_name' => '測試平安教會']);
$ub = array_column(array_filter(unbatched(), fn($x) => in_array((int)$x['application_id'], [$pa, $pb], true)), 'seq', 'application_id');
ok($ub === [$pa => 1, $pb => 1], '未產出批次：每案只放下一期（甲第 1 期、乙第 1 期）');
ok(batch_create($WK)[1] !== null, '區辦不能產出批次');
$HQW = load_user('email', 'hq@example.test');
[$bid, $e] = batch_create($HQW);
ok($bid && !$e, '服管組產出暫存批次');
$t = batch_totals($bid);
$sumItems = (int)$db->query("SELECT sum(amount) FROM installment WHERE batch_id=$bid")->fetchColumn();
ok($t['total'] === $sumItems && array_sum($t['regions']) === $t['total'], '批次合計＝明細加總＝各區小計加總（' . $t['total'] . ' 元）');
$itA = (int)$db->query("SELECT id FROM installment WHERE application_id=$pa AND seq=1")->fetchColumn();
$itB = (int)$db->query("SELECT id FROM installment WHERE application_id=$pb AND seq=1")->fetchColumn();
ok(batch_region_act($WK, $itB, 'confirm') !== null, '區辦不能確認別區的項目');
ok(batch_region_act($W2, $itB, 'withdraw') === null && in_array($itB, array_column(unbatched(), 'id'), true), '區辦退出批次 → 回到未產出批次');
ok(str_contains((string)batch_send($HQW, $bid), '尚未確認'), '區辦還沒確認不能送會計');
foreach (batch_items($bid) as $bi) batch_region_act((int)$bi['region_id'] === 1 ? $WK : $W2, (int)$bi['id'], 'confirm');   // 前面測試留下的其他已通過案也在批次裡
ok((bool)$db->query("SELECT region_confirmed FROM installment WHERE id=$itA")->fetchColumn() && batch_send($HQW, $bid) === null, '各區確認後服管組送會計');
ok(batch_region_act($WK, $itA, 'withdraw') !== null, '送出後區辦不能再退出');
ok(batch_export_csv($CS, $bid)[1] !== null, '教會不能匯出匯款清單');
$ins->execute([null, 'cashier1', password_hash('x', PASSWORD_DEFAULT), '測試出納', 'cashier', null]);
$ins->execute([null, 'acct1', password_hash('x', PASSWORD_DEFAULT), '測試會計', 'accountant', null]);
$CA = load_user('username', 'cashier1'); $AC = load_user('username', 'acct1');
$nExp = (int)$db->query("SELECT count(*) FROM audit_log WHERE action='export' AND target_type='batch'")->fetchColumn();
[$csv] = batch_export_csv($CA, $bid);
ok(str_contains($csv, '匯款清單') && str_contains($csv, '12345678') && str_contains($csv, '6000') && !str_contains($csv, '87654321'), '匯款清單含帳號金額，退出的項目不在內');
ok((int)$db->query("SELECT count(*) FROM audit_log WHERE action='export' AND target_type='batch'")->fetchColumn() === $nExp + 1, '匯出寫 audit_log');
ok(batch_paid($AC, $bid, date('Y-m-d')) !== null, '會計不能標記匯款成功（由出納）');
$nN = (int)$db->query("SELECT count(*) FROM notification")->fetchColumn();
ok(batch_paid($CA, $bid, date('Y-m-d')) === null, '出納標記匯款成功');
ok($db->query("SELECT paid_on FROM installment WHERE id=$itA")->fetchColumn() === date('Y-m-d') && app_row($pa)['last_paid_at'] === date('Y-m-d'), '寫入撥款日，個案記下最後撥款日');
ok((int)$db->query("SELECT count(*) FROM notification")->fetchColumn() > $nN, '通知教會與區辦');
$ub2 = array_column(array_filter(unbatched(), fn($x) => in_array((int)$x['application_id'], [$pa, $pb], true)), 'seq', 'application_id');
ok($ub2 === [$pa => 2, $pb => 1], '下一輪：甲第 2 期、乙第 1 期進未產出批次');

echo "領據（#8）\n";
ok(count(array_filter(receipts_outstanding(), fn($r) => (int)$r['id'] === $itA)) === 1, '匯款後領據列為未回');
ok(receipt_back($W2, $itA, date('Y-m-d')) !== null, '別區不能登記這一期的領據');
ok(receipt_back($WK, $itB, date('Y-m-d')) !== null, '還沒匯款的期數不能登記領據');
ok(receipt_back($WK, $itA, date('Y-m-d')) === null && !in_array($itA, array_column(receipts_outstanding(), 'id'), true), '區辦登記簽回後不再列為未回');

echo "通知信與提醒（#9）\n";
$last = fn() => (int)$db->query("SELECT coalesce(max(id),0) FROM notification")->fetchColumn();
$since = function (int $from, string $kind) use ($db) {
    $s = $db->prepare("SELECT to_user_id FROM notification WHERE id > ? AND kind = ? ORDER BY to_user_id"); $s->execute([$from, $kind]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$sorted = function (array $x) { $x = array_values(array_unique($x)); sort($x); return $x; };
[$n1] = app_save($CS, $good + ['idno' => fake_id('F23232323'), 'applicant_phone' => '1']);
$a1 = app_row($n1);
ok(count(rcpt_pastor($a1)) > 0 && count(rcpt_region($a1)) > 0 && count(rcpt_region_head($a1)) > 0, '測試資料備齊：該教會有牧者、該區有社工與主任');
$m = $last(); app_submit($CS, $n1);
ok($since($m, 'review_assigned') === $sorted(rcpt_pastor($a1)), '教會同工送審 → 寄給該教會牧者（3-1-0）');
$m = $last(); review_act($PA, $n1, 'approve', '牧者');
ok($since($m, 'review_assigned') === $sorted(rcpt_region($a1)), '牧者通過 → 寄給區辦一社工（3-2-0）');
$m = $last(); review_act($WK, $n1, 'return', '請補附件', ['5000']);
ok(app_row($n1)['status'] === 'pastor_review' && $since($m, 'review_returned') === $sorted(rcpt_pastor($a1)) && $since($m, 'church_result') === [], '區辦退件 → 退回牧者關、寄牧者（服管組 10/16：退回上一關）');
$cn = app_row($n1)['case_no'];
$m = $last(); review_act($PA, $n1, 'return', '資料再確認');
ok(app_row($n1)['status'] === 'returned' && count($since($m, 'church_result')) > 0, '牧者退件 → 退回教會修改並通知教會');
ok(app_load($CS, $n1) !== null && !array_key_exists('status', app_load($CS, $n1)), '教會看得到被退的案子但看不到狀態欄');
ok(app_submit($CS, $n1) === null && app_row($n1)['case_no'] === $cn && app_row($n1)['status'] === 'pastor_review', '教會修改後再送審，案號沿用、從牧者關重新開始');
ok(return_recipients($a1, 'region_review') === rcpt_region($a1) && return_recipients($a1, 'returned') === [], '退回區辦寄區辦社工；退回教會不走同工通知');
$m = $last(); approve_all($n1, $CS);
ok(app_row($n1)['status'] === 'approved' && $since($m, 'review_approved') === $sorted(array_merge(rcpt_region($a1), rcpt_region_head($a1))), '第四關通過 → 區辦一社工與主任＋教會（3-8）');
$bodies = implode("\n", $db->query("SELECT subject || body FROM notification")->fetchAll(PDO::FETCH_COLUMN));
ok(!str_contains($bodies, $good['name']) && !preg_match('/[A-Z][12]\d{8}/', $bodies), '所有通知信都不含受助人姓名與身分證字號');

$today = date('Y-m-d'); $d = fn(int $n) => date('Y-m-d', strtotime("$today +$n day"));
$db->exec("UPDATE installment SET paid_on = '$today'::date - 15, receipt_back_on = NULL WHERE application_id = $pa AND seq = 2");
$c = run_reminders($d(12));
ok($c['service'] > 0 && $c['receipt'] > 0, '滿 10 天：服務紀錄與領據各發提醒（' . json_encode($c) . '）');
ok($c['closure'] > 0, '各期都已匯款且沒送結案報告 → 結案提醒');
$c2 = run_reminders($d(12));
ok($c2 === ['service' => 0, 'receipt' => 0, 'closure' => 0], '同一天重跑不會重寄');
$c3 = run_reminders($d(13));
ok($c3['service'] === 0 && $c3['closure'] === 0, '隔天（還在同一個 10 天區間）也不重寄');
$c4 = run_reminders($d(22));
ok($c4['service'] > 0 && $c4['receipt'] > 0 && $c4['closure'] === 0, '再過 10 天再提醒一次；結案提醒只寄一次');
ok(count(array_filter(rcpt_church(app_row($pa)), fn($u) => true)) > 0 && !str_contains($bodies, '凍結'), '沒有凍結（服管組 10/15 刪除此規則）');

exec('RELIEF_TEST_DB=1 php ' . escapeshellarg("$ROOT/bin/notify_queue.php") . ' claim 5', $o1, $q1);
$j1 = json_decode(implode('', $o1), true);
exec('RELIEF_TEST_DB=1 php ' . escapeshellarg("$ROOT/bin/notify_queue.php") . ' claim 5', $o2, $q2);
$j2 = json_decode(implode('', $o2), true);
ok($q1 === 0 && count($j1) === 5 && !array_intersect(array_column($j1, 'id'), array_column($j2, 'id')), '寄送端取件：取過的不會再被取（先記後寄）');
exec('RELIEF_TEST_DB=1 php ' . escapeshellarg("$ROOT/bin/notify_queue.php") . ' done ' . (int)$j1[0]['id']);
ok($db->query("SELECT sent_at IS NOT NULL FROM notification WHERE id=" . (int)$j1[0]['id'])->fetchColumn() === true, '寄成功回報後記下 sent_at');

echo "報表與匯出（#10）\n";
$AS = load_user('email', 'assist@example.test');
[$hA, $rA] = report_rows($HQW, 'apply', []);
$cases = array_column($rA, 3);
ok(in_array('T1-A-2026-0901', $cases, true) && in_array('T2-B-2026-0902', $cases, true), '服管組看得到全部區的申請主表');
ok(in_array('身分證字號', $hA, true) && in_array('就業情況', $hA, true) && in_array('審核核發金額', $hA, true), '主表含身分證字號、就業情況、核發金額（需求 0004）');
$rowA = $rA[array_search('T1-A-2026-0901', $cases, true)];
ok((bool)preg_match('/^[A-Z][12]\d{8}$/', $rowA[5]), '服管組匯出的身分證字號是完整號碼');
[, $rW2] = report_rows($W2, 'apply', []);
ok(!in_array('T1-A-2026-0901', array_column($rW2, 3), true) && in_array('T2-B-2026-0902', array_column($rW2, 3), true), '區辦只拿得到自己區的資料（查詢層限縮）');
[, $rAS] = report_rows($AS, 'apply', []);
ok($rAS === [] || !preg_match('/^[A-Z][12]\d{8}$/', (string)$rAS[0][5]), '社工助理拿到的身分證字號是遮罩');
[, $rF] = report_rows($HQW, 'apply', ['church' => '平安']);
ok($rF && !array_filter($rF, fn($r) => !str_contains($r[6], '平安')), '教會名稱篩選有效');
[, $rN] = report_rows($HQW, 'apply', ['d1' => '2000-01-01', 'd2' => '2000-01-02']);
ok($rN === [], '日期區間外沒有資料');
[$hS, $rS] = report_rows($HQW, 'service', []);
ok(count($rS) > 0 && in_array('服務方式', $hS, true) && $rS[0][count($hS) - 1] !== '', '服務紀錄表有資料且信仰狀況有值');
ok(fmt_value(spec_field(FORM_SPEC, 'employment'), ['sel' => ['無'], 'sub' => ['無' => ['其他']], 'sub_other' => ['無' => '照顧家人']]) === '無（其他、照顧家人）', '就業情況細項與「其他」說明寫進同一格');
[, $rC] = report_rows($W2, 'church', []);
ok($rC && !array_filter($rC, fn($r) => $r[4] === '900001'), '教會基本資料也依區域限縮');
[$nS, $nM] = church_sync();
[$hC, $rC2] = report_rows($HQW, 'church', ['church' => '恩典']);
ok($nS > 0 && $rC2 && $rC2[0][9] === '02-0000-0001' && $rC2[0][11] === '測試牧者 牧師', '名錄同步後教會基本資料帶出電話與負責人（' . $nS . ' 間）');
$nE = (int)$db->query("SELECT count(*) FROM audit_log WHERE action='export' AND target_type='report'")->fetchColumn();
[$csv] = report_csv($HQW, 'household', []);
ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '同住') === false && str_contains($csv, '關係'), '同住家屬表可匯出（需求 0003）');
ok((int)$db->query("SELECT count(*) FROM audit_log WHERE action='export' AND target_type='report'")->fetchColumn() === $nE + 1, '匯出寫 audit_log');
[, $out] = page('reports/index.php', 'grace-staff');
ok(str_contains($out, '沒有這個功能的權限'), '教會帳號打報表網址被擋');
[, $out] = page('reports/index.php', 'hq@example.test');
ok(str_contains($out, '急難救助申請主表') && str_contains($out, 'T1-A-2026-0901'), '服管組看得到報表頁');

// 2026-10-19 個資稽核：搜尋條件存伺服器端，網址只帶代碼（姓名不進網址與紀錄）
$k1 = filter_stash(['name' => '測試甲', 'region' => 1]);
ok(preg_match('/^[0-9a-f]{12}$/', $k1) === 1 && filter_load($k1) === ['name' => '測試甲', 'region' => 1], '條件存進去、用代碼取回一致');
ok(filter_load('zzzzzzzzzzzz') === null && filter_load(null) === null && filter_load('../etc') === null, '不存在或格式不對的代碼取不到東西');
for ($i = 0; $i < FILTER_KEEP + 5; $i++) filter_stash(['n' => $i]);
ok(count($_SESSION['filters']) === FILTER_KEEP && filter_load($k1) === null, '每個 session 最多留 ' . FILTER_KEEP . ' 組，最舊的自動丟掉');
[, $out] = page('reports/index.php', 'hq@example.test', ['name' => '測試甲', 'csrf' => 'x']);
ok(str_contains($out, 'method=post') && !preg_match('/href="[^"]*name=/', $out) && preg_match('/href="\?r=apply&amp;f=[0-9a-f]{12}&amp;csv=1"/', $out) === 1,
   '報表用 POST 搜尋，匯出連結只帶代碼、不帶姓名');
ok(str_contains($out, 'value="測試甲"'), '搜尋後畫面仍帶回剛才的條件（需求 0004 的前提）');
[, $out] = page('admin/audit.php', 'admin@example.test', ['q' => 'login', 'csrf' => 'x']);
ok(str_contains($out, 'method=post') && str_contains($out, 'value="login"') && !preg_match('/href="[^"]*q=/', $out), '稽核紀錄改 POST 搜尋，搜尋字不進網址');
// 小鹿 10-19 互審：代碼過期（被淘汰、或別的瀏覽器的代碼）時不可退回空條件，否則舊頁的匯出連結會匯出全部
ok(filter_load(['x']) === null && filter_load("0123456789ab\n") === null && filter_load('0123456789AB') === null, 'f[]=x、尾端換行、大寫都不認，也不丟型別錯誤');
[, $out] = page('reports/index.php', 'hq@example.test', [], ['r' => 'apply', 'f' => '0123456789ab', 'csv' => '1']);
ok(str_contains($out, '已過期') && !str_contains($out, 'T1-A-2026-0901') && !str_starts_with($out, "\xEF\xBB\xBF"), '過期代碼的匯出連結不產出 CSV，提示重新搜尋');
[, $out] = page('reports/index.php', 'hq@example.test', [], ['r' => 'apply', 'f' => '0123456789ab']);
ok(str_contains($out, '已過期') && !str_contains($out, 'T1-A-2026-0901') && !str_contains($out, '匯出 CSV'), '過期代碼的報表頁不列資料、不給匯出連結');
[, $out] = page('reports/index.php', 'hq@example.test', [], ['r' => 'apply', 'f' => ['x']]);
ok(str_contains($out, '已過期') && !str_contains($out, 'TypeError'), '網址 f 是陣列時當成過期處理');
[, $out] = page('reports/index.php', 'hq@example.test', [], ['r' => 'apply']);
ok(!str_contains($out, '已過期') && str_contains($out, 'T1-A-2026-0901'), '沒有代碼（第一次進來）照常列出');
[, $out] = page('admin/audit.php', 'admin@example.test', [], ['f' => '0123456789ab']);
ok(str_contains($out, '已過期') && str_contains($out, 'value=""'), '稽核紀錄的過期代碼提示重新搜尋、搜尋框清空');

echo "隔離\n";
ok((int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn() === $prodBefore, '正式 schema 的 audit_log 沒有被測試寫入');
$leak = shell_exec('grep -rnE "password *= *[^ ]|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}" ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . ' ' . escapeshellarg("$ROOT/sql") . ' 2>/dev/null');
ok(trim((string)$leak) === '', 'lib／public／sql 裡沒有密碼字串或 IP 位址');
// "$變數：" 這種寫法 PHP 會把全形字元當成變數名稱的一部分（2026-10-15 踩到），一律要寫成 {$變數}
$interp = shell_exec('grep -rnP ' . escapeshellarg('\$[A-Za-z_][A-Za-z0-9_]*[^\x00-\x7F]') . ' --include=*.php ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . " | grep -vP '\\{\\\$[A-Za-z_][^}]*\\}'");
ok(trim((string)$interp) === '', '字串裡的變數後面緊接中文時都有用 {} 包起來');

echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
