<?php
// 自動測試（#2 骨架）。只動 schema test：RELIEF_TEST_DB=1 由本檔自己設，所有子程序也帶。
// 用法（主機上，要讀得到 db.ini 的身分）：php tests/run.php
putenv('RELIEF_TEST_DB=1');
putenv('RELIEF_TEST_DIR=' . __DIR__ . '/fixtures/directory.json');   // 名錄 API 一律用假資料
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

echo "隔離\n";
ok((int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn() === $prodBefore, '正式 schema 的 audit_log 沒有被測試寫入');
$leak = shell_exec('grep -rnE "password *= *[^ ]|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}" ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . ' ' . escapeshellarg("$ROOT/sql") . ' 2>/dev/null');
ok(trim((string)$leak) === '', 'lib／public／sql 裡沒有密碼字串或 IP 位址');

echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
