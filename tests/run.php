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

echo "隔離\n";
ok((int)$db->query("SELECT count(*) FROM app.audit_log")->fetchColumn() === $prodBefore, '正式 schema 的 audit_log 沒有被測試寫入');
$leak = shell_exec('grep -rnE "password *= *[^ ]|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}" ' . escapeshellarg("$ROOT/lib") . ' ' . escapeshellarg("$ROOT/public") . ' ' . escapeshellarg("$ROOT/sql") . ' 2>/dev/null');
ok(trim((string)$leak) === '', 'lib／public／sql 裡沒有密碼字串或 IP 位址');

echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
