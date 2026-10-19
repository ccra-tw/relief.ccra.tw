<?php
// 真的走 HTTP 驗命令列測試會跳過的流程：303 轉址、session 保存、CSRF、cookie 隔離。
// 用法：先跑 tests/run.php（建好 test schema 的假資料），再 php tests/http_test.php。
// 做法：開兩個 PHP 內建伺服器（只聽本機），分別以服管組與管理者替身登入；
// 內建伺服器要明確帶 RELIEF_TEST_DB=1 才打開測試接縫（見 lib/db.php test_seam），資料一律在 test schema。
$ROOT = dirname(__DIR__);
$fail = 0;
function ok(bool $c, string $m): void { global $fail; echo ($c ? '  ✅ ' : '  ❌ ') . $m . "\n"; if (!$c) $fail++; }

function serve(string $user, int $port) {
    global $ROOT;
    $env = ['RELIEF_TEST_DB' => '1', 'RELIEF_TEST_SECRET' => '1', 'RELIEF_TEST_USER' => $user, 'PATH' => getenv('PATH')];
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$ROOT/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $ROOT, $env);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    return $p;
}

// 不自動跟隨轉址、自己管 cookie（session cookie 是 secure，這裡是本機 http）
function req(int $port, string $path, ?string &$cookie, ?array $post = null): array {
    $h = $cookie ? "Cookie: $cookie\r\n" : '';
    $o = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'header' => $h];
    if ($post !== null) { $o['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"; $o['content'] = http_build_query($post); }
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => $o]));
    $hdr = $http_response_header ?? [];
    preg_match('/ (\d{3})/', $hdr[0] ?? '', $m);
    $loc = null;
    foreach ($hdr as $l) {
        if (preg_match('/^Set-Cookie: (relief_sid=[^;]+)/i', $l, $c)) $cookie = $c[1];
        if (preg_match('/^Location: (.+)$/i', $l, $c)) $loc = trim($c[1]);
    }
    return [(int)($m[1] ?? 0), $body, $loc];
}
function csrf(string $html): string { preg_match('/name=csrf value="([0-9a-f]+)"/', $html, $m); return $m[1] ?? ''; }

$P1 = 18611; $P2 = 18612;
$s1 = serve('hq@example.test', $P1);
$s2 = serve('admin@example.test', $P2);

echo "報表（服管組）\n";
$ck = null;
[$c, $b] = req($P1, '/reports/?r=apply', $ck);
ok($c === 200 && $ck !== null && csrf($b) !== '', '第一次進來 200、拿到 session cookie 與 csrf');
$all = substr_count($b, '<tr>') - 1;
[$c, , $loc] = req($P1, '/reports/?r=apply', $ck, ['csrf' => csrf($b), 'church' => '平安']);
ok($c === 303 && (bool)preg_match('/^\?r=apply&f=[0-9a-f]{12}$/', (string)$loc), '有效 csrf 的 POST 回 303，Location 只有 r 與 f（' . $loc . '）');
[$c, $b] = req($P1, '/reports/' . $loc, $ck);
$hit = substr_count($b, '<tr>') - 1;
ok($c === 200 && str_contains($b, 'value="平安"') && $hit > 0 && $hit < $all, "同一個 cookie 跟著轉址，條件還原、筆數縮小（{$hit}／{$all}）");
preg_match('/href="(\?r=apply&amp;f=[0-9a-f]{12}&amp;csv=1)"/', $b, $m);
$csvLink = html_entity_decode($m[1] ?? '');
ok($csvLink !== '' && !str_contains($b, 'church=') , '匯出連結只帶代碼');
[$c, $csv] = req($P1, '/reports/' . $csvLink, $ck);
$lines = array_slice(array_filter(explode("\n", trim($csv))), 1);
ok($c === 200 && str_starts_with($csv, "\xEF\xBB\xBF") && count($lines) === $hit && !array_filter($lines, fn($l) => !str_contains($l, '平安')), 'CSV 筆數與畫面相同、每列都符合篩選');
[$c, $b] = req($P1, '/reports/' . $loc, $ck);
ok($c === 200 && str_contains($b, 'value="平安"'), '匯出後返回原頁，條件仍在（需求 0004）');

$other = null;
[$c, $b] = req($P1, '/reports/' . $loc, $other);
ok(str_contains($b, '已過期') && !str_contains($b, '匯出 CSV'), '換一個瀏覽器（新 cookie）讀不到別人的代碼');
[$c, $b] = req($P1, '/reports/' . $csvLink, $other);
ok($c === 409 && !str_starts_with($b, "\xEF\xBB\xBF"), '新 cookie 拿舊匯出連結 → 409，不產出 CSV');
[$c] = req($P1, '/reports/?r=apply', $ck, ['csrf' => 'bad', 'church' => '平安']);
ok($c === 400, '錯的 csrf 被拒（400）');
[$c, $b] = req($P1, '/reports/?r=apply&name=' . rawurlencode('不存在的人'), $ck);
ok($c === 200 && substr_count($b, '<tr>') - 1 === $all, '網址上舊式的 name 參數被忽略');

[$c, $b] = req($P1, '/reports/?r=apply', $ck);
$t = csrf($b);
for ($i = 0; $i < 21; $i++) req($P1, '/reports/?r=apply', $ck, ['csrf' => $t, 'church' => "第{$i}次"]);
[$c, $b] = req($P1, '/reports/' . $csvLink, $ck);
ok($c === 409 && str_contains($b, '已過期'), '同一個 session 再搜 21 次、舊代碼被淘汰後，舊匯出連結 409、不會匯出全部');

echo "稽核紀錄（管理者）\n";
$ca = null;
[$c, $b] = req($P2, '/admin/audit.php', $ca);
[$c, , $loc] = req($P2, '/admin/audit.php', $ca, ['csrf' => csrf($b), 'q' => 'login']);
ok($c === 303 && (bool)preg_match('/^\?f=[0-9a-f]{12}$/', (string)$loc), '稽核搜尋 303，Location 只有 f');
[$c, $b] = req($P2, '/admin/audit.php' . $loc, $ca);
ok($c === 200 && str_contains($b, 'value="login"'), '同一 cookie 還原搜尋字');
$x = null;
[$c, $b] = req($P2, '/admin/audit.php' . $loc, $x);
ok(str_contains($b, '已過期') && str_contains($b, 'value=""'), '新 cookie 讀不到，提示重新搜尋');

proc_terminate($s1); proc_terminate($s2);
echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
