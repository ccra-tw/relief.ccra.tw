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
function page(string $path, ?string $as = null, array $post = []): array {
    global $ROOT;
    $env = 'RELIEF_TEST_DB=1 ' . ($as ? 'RELIEF_TEST_USER=' . escapeshellarg($as) . ' ' : '');
    $req = escapeshellarg(json_encode(['page' => "$ROOT/public/$path", 'method' => $post ? 'POST' : 'GET', 'post' => $post]));
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
foreach ([$tmpPng, $tmpExe, $tmpBig] as $t) @unlink($t);
array_map('unlink', glob("$UP/*") ?: []); @rmdir($UP);

echo "隔離\n";
ok((int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn() === $prodBefore, '正式 schema 的 audit_log 沒有被測試寫入');
$leak = shell_exec('grep -rnE "password *= *[^ ]|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}" ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . ' ' . escapeshellarg("$ROOT/sql") . ' 2>/dev/null');
ok(trim((string)$leak) === '', 'lib／public／sql 裡沒有密碼字串或 IP 位址');
// "$變數：" 這種寫法 PHP 會把全形字元當成變數名稱的一部分（2026-10-15 踩到），一律要寫成 {$變數}
$interp = shell_exec('grep -rnP ' . escapeshellarg('\$[A-Za-z_][A-Za-z0-9_]*[^\x00-\x7F]') . ' --include=*.php ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . " | grep -vP '\\{\\\$[A-Za-z_][^}]*\\}'");
ok(trim((string)$interp) === '', '字串裡的變數後面緊接中文時都有用 {} 包起來');

echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
