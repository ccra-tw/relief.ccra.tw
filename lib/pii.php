<?php
// 身分證／居留證號碼的保護（#5）。
//   存：密文（libsodium secretbox，金鑰在主機 /etc/relief/secret.ini 的 pii_key）＋ HMAC（同一人查得到既有個案，但看不出號碼）＋末 4 碼（遮罩顯示用）
//   顯示：預設遮罩 A12****789；只有區辦以上的審核角色、在「檢視個案」時可以看完整號碼，且每次都寫 audit_log
// 測試接縫：命令列且 RELIEF_TEST_SECRET=1 時用固定的測試金鑰（只在 test schema 用，與正式金鑰無關）
const PII_INI = '/etc/relief/secret.ini';
const PII_FULL_ROLES = ['region_worker', 'region_head', 'hq_worker', 'hq_head', 'admin'];

function pii_key(): string {
    if (PHP_SAPI === 'cli' && getenv('RELIEF_TEST_SECRET') === '1') return str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    static $k = null;
    if ($k) return $k;
    $c = @parse_ini_file(PII_INI);
    $raw = base64_decode($c['pii_key'] ?? '', true);
    if (!$raw || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('個資金鑰未設定');
    return $k = $raw;
}

function idno_norm(string $s): string { return strtoupper(preg_replace('/[\s-]/', '', $s)); }

// 本國身分證（含檢查碼）或居留證（新式第二碼 8/9、舊式第二碼 A-D）
function idno_valid(string $s): bool {
    $s = idno_norm($s);
    if (!preg_match('/^[A-Z][12ABCD89]\d{8}$/', $s)) return false;
    $map = 'ABCDEFGHJKLMNPQRSTUVXYWZIO';
    $n = strpos($map, $s[0]) + 10;
    $second = ctype_digit($s[1]) ? (int)$s[1] : (strpos($map, $s[1]) + 10) % 10;
    $sum = intdiv($n, 10) + ($n % 10) * 9 + $second * 8;
    for ($i = 2; $i <= 8; $i++) $sum += (int)$s[$i] * (9 - $i);
    return ($sum + (int)$s[9]) % 10 === 0;
}

function pii_hash(string $idno): string { return hash_hmac('sha256', idno_norm($idno), pii_key()); }

function pii_encrypt(string $idno): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox(idno_norm($idno), $nonce, pii_key()));
}

function pii_decrypt(string $enc): ?string {
    $b = base64_decode($enc, true);
    if ($b === false || strlen($b) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), pii_key());
    return $p === false ? null : $p;
}

function pii_mask(string $first, string $last4): string { return $first . str_repeat('*', 5) . $last4; }
