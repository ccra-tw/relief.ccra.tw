<?php
// 登入（#2）。兩條路，對應兩種使用者：
//   內部同工：站前是 Cloudflare Access（協會 M365），來源端驗 JWT 簽章與 AUD 後，用 email 對到 app_user。
//   教會帳號：本地帳號密碼（服管組 2026-10-15 回覆「維持現在的帳號密碼」），password_hash 存放，連錯 5 次鎖 15 分鐘。
// 測試接縫：只在 test_seam()（命令列，或內建伺服器帶 RELIEF_TEST_DB=1）可用 RELIEF_TEST_USER 當替身，網頁（php-fpm）永遠不吃這個環境變數。
const ACCESS_TEAM = 'https://maryonacross.cloudflareaccess.com';
const ACCESS_AUD  = '87aa7a0efb138e1b5af9e0476650f274137e5ca3d5d43ae2df1464ab3e651017';
const STATE_DIR   = '/var/lib/relief';
const LOCK_AFTER  = 5;
const LOCK_MIN    = 15;

function b64url($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function access_certs(bool $force = false): ?array {
    $f = STATE_DIR . '/access_certs.json';
    if (!$force && is_file($f) && time() - filemtime($f) < 3600) return json_decode(file_get_contents($f), true);
    $j = @file_get_contents(ACCESS_TEAM . '/cdn-cgi/access/certs');
    if ($j === false) return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    @file_put_contents($f, $j);
    return json_decode($j, true);
}

// 驗過的 Access email；沒有 JWT 或驗不過回 null（教會端走本地登入時不會有 JWT）
function access_email(): ?string {
    $jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '';
    $p = explode('.', $jwt);
    if (count($p) !== 3) return null;
    $h = json_decode(b64url($p[0]), true);
    $c = json_decode(b64url($p[1]), true);
    if (!$h || !$c || ($h['alg'] ?? '') !== 'RS256') return null;
    $pem = null;
    foreach ([false, true] as $force) {
        foreach ((access_certs($force)['public_certs'] ?? []) as $k)
            if (($k['kid'] ?? '') === ($h['kid'] ?? '')) $pem = $k['cert'];
        if ($pem) break;
    }
    if (!$pem || openssl_verify("$p[0].$p[1]", b64url($p[2]), $pem, OPENSSL_ALGO_SHA256) !== 1) return null;
    if (!in_array(ACCESS_AUD, (array)($c['aud'] ?? []), true) || ($c['iss'] ?? '') !== ACCESS_TEAM || ($c['exp'] ?? 0) < time()) return null;
    return isset($c['email']) ? strtolower($c['email']) : null;
}

function session_boot(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    session_name('relief_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function load_user(string $col, string $val): ?array {
    if (!in_array($col, ['id', 'email', 'username'], true)) return null;
    $s = rdb()->prepare("SELECT * FROM app_user WHERE $col = ? AND active");
    $s->execute([$val]);
    return $s->fetch() ?: null;
}

function current_user_or_null(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (test_seam()) {
        $t = getenv('RELIEF_TEST_USER');
        return $u = $t ? (load_user(str_contains($t, '@') ? 'email' : 'username', strtolower($t))) : null;
    }
    session_boot();
    if (!empty($_SESSION['uid'])) return $u = load_user('id', (string)$_SESSION['uid']);
    if ($e = access_email()) {
        $found = load_user('email', $e);
        if ($found) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $found['id'];
            $u = $found;
            audit('login', 'user', $found['id'], ['via' => 'access']);
            return $u;
        }
    }
    return $u = null;
}

function require_login(): array {
    $u = current_user_or_null();
    if ($u) return $u;
    if (PHP_SAPI === 'cli') { echo "未登入\n"; exit(3); }
    header('Location: /login.php');
    exit;
}

// 教會帳號登入；回傳錯誤訊息或 null（成功）
function password_login(string $username, string $password): ?string {
    $username = strtolower(trim($username));
    $s = rdb()->prepare('SELECT * FROM app_user WHERE username = ? AND active');
    $s->execute([$username]);
    $row = $s->fetch();
    $generic = '帳號或密碼不正確。';
    if (!$row || !$row['pass_hash']) { audit('login_fail', 'user', null, ['actor' => $username, 'why' => 'no_user']); return $generic; }
    if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
        audit('login_fail', 'user', $row['id'], ['actor' => $username, 'why' => 'locked']);
        return '登入失敗次數過多，請 ' . LOCK_MIN . ' 分鐘後再試。';
    }
    if (!password_verify($password, $row['pass_hash'])) {
        $n = $row['failed_logins'] + 1;
        rdb()->prepare('UPDATE app_user SET failed_logins = ?, locked_until = ? WHERE id = ?')
            ->execute([$n >= LOCK_AFTER ? 0 : $n, $n >= LOCK_AFTER ? date('c', time() + LOCK_MIN * 60) : null, $row['id']]);
        audit('login_fail', 'user', $row['id'], ['actor' => $username, 'why' => 'password', 'count' => $n]);
        return $generic;
    }
    rdb()->prepare('UPDATE app_user SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$row['id']]);
    if (PHP_SAPI !== 'cli') { session_boot(); session_regenerate_id(true); $_SESSION['uid'] = $row['id']; }
    audit('login', 'user', $row['id'], ['actor' => $username, 'via' => 'password']);
    return null;
}

// 只看本瀏覽器 session 已登入的人，不用 Cloudflare Access 身分自動登入。
// 登入頁用這支：協會同工登出後要能改用教會帳號登入（走查時同一人要切換身分；繁英姊 10-27 回報進不去教會登入頁）。
function session_user_or_null(): ?array {
    if (test_seam()) return current_user_or_null();
    session_boot();
    return !empty($_SESSION['uid']) ? load_user('id', (string)$_SESSION['uid']) : null;
}

function logout(): void {
    $u = current_user_or_null();
    if ($u) audit('logout', 'user', $u['id']);
    if (PHP_SAPI !== 'cli') { session_boot(); $_SESSION = []; session_destroy(); }
}
