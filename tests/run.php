<?php
// 自動測試（#2 骨架）。只動 schema test：RELIEF_TEST_DB=1 由本檔自己設，所有子程序也帶。
// 用法（主機上，要讀得到 db.ini 的身分）：php tests/run.php
putenv('RELIEF_TEST_DB=1');
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

echo "隔離\n";
ok((int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn() === $prodBefore, '正式 schema 的 audit_log 沒有被測試寫入');
$leak = shell_exec('grep -rnE "password *= *[^ ]|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}" ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . ' ' . escapeshellarg("$ROOT/sql") . ' 2>/dev/null');
ok(trim((string)$leak) === '', 'lib／public／sql 裡沒有密碼字串或 IP 位址');

echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
