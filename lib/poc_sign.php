<?php
// 錄影簽名雛形（POC）：社工簽收單＋手寫簽名＋錄影聲明。
// 熊哥 2026-10-29 看過電子簽章服務的展示後問「我們可以做到錄影簽名的部分嗎」，要先做雛形試用。
// 刻意不碰正式資料表：每筆存成一個資料夾（meta.json＋signature.png.enc＋video.*.enc），只放測試資料。
// 存證方式：簽名圖與錄影各算 SHA-256（對原始內容），再把整份 meta（含兩個雜湊）正規化後算一個「存證碼」，
// 事後檔案或內容動過任何一處，重算就對不上。
// 合規（依電子簽章法第 5、8 條與個資法，熊哥同日按「先補 1、2、3」）：
//   1. 一併保存 IP 位址（第 8 條明列可保存的驗證資料）
//   2. 每日把當天的存證碼清單寄到主機以外的信箱（poc_digest），管理者改了主機上的資料也對不上外部那份
//   3. 簽名與錄影以個資金鑰加密存放（libsodium secretbox，與身分證號同一把金鑰）

const POC_ROLES = ['region_worker', 'region_head', 'hq_worker', 'hq_head', 'admin'];
const POC_VIDEO_MAX = 10 * 1024 * 1024;   // 與 php-fpm upload_max_filesize 一致；前端以約 0.9 Mbps 錄 45 秒約 5 MB
const POC_SIG_MAX = 600 * 1024;
const POC_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
const POC_KEEP_DAYS = 30;   // 個資法：告知的保存期限要真的做到；poc_list() 每次執行時清掉過期的資料夾
const POC_DIGEST_TO = 'ituncle@ccra.org.tw';   // 存證碼清單寄送的外部信箱（資訊部，與主機分屬不同系統）

function poc_dir(): string {
    return getenv('RELIEF_POC_DIR') ?: STATE_DIR . '/poc-sign';
}

function poc_valid_id(mixed $id): bool {
    return is_string($id) && preg_match('/\A[0-9a-f]{16}\z/', $id) === 1;
}

// 正規化後的雜湊：鍵排序、不跳脫中文，確保同一份內容永遠算出同一個值
function poc_seal(array $meta): string {
    unset($meta['seal']);
    ksort($meta);
    return hash('sha256', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function poc_enc(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, pii_key());
}

function poc_dec(string $blob): ?string {
    if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), pii_key());
    return $p === false ? null : $p;
}

// 讀回原始內容：新資料是加密檔（*.enc），v0.3.0～0.3.4 試用期間的少數舊資料是明碼，兩種都讀得到
function poc_read(array $m, string $k): ?string {
    $dir = poc_dir() . '/' . $m['id'];
    $name = $k === 'sig' ? 'signature.png' : $m['video_file'];
    if (is_file("$dir/$name.enc")) return poc_dec((string)file_get_contents("$dir/$name.enc"));
    return is_file("$dir/$name") ? (string)file_get_contents("$dir/$name") : null;
}

function poc_client_ip(): string {
    // 經 Cloudflare 進來時，真實來源在 CF-Connecting-IP；直接連線才用 REMOTE_ADDR
    return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// 回傳 [id, null] 或 [null, 錯誤訊息]。$videoTmp 是上傳暫存檔路徑。
function poc_save(array $f, string $sigPng, string $videoTmp, array $u): array {
    $who = trim((string)($f['recipient'] ?? ''));
    $items = trim((string)($f['items'] ?? ''));
    if ($who === '' || mb_strlen($who) > 40) return [null, '請填寫簽收人代稱（40 字以內，雛形請勿填真實姓名）。'];
    if ($items === '' || mb_strlen($items) > 200) return [null, '請填寫物資品項與數量（200 字以內）。'];
    if (($f['consent'] ?? '') !== '1') return [null, '需勾選同意錄影，才能送出。'];
    if (strlen($sigPng) < 100 || strlen($sigPng) > POC_SIG_MAX || substr($sigPng, 0, 8) !== "\x89PNG\r\n\x1a\n") return [null, '沒有收到簽名，請在簽名框簽名後再送出。'];
    if (!is_file($videoTmp) || filesize($videoTmp) < 1000) return [null, '沒有收到錄影，請先錄一段聲明。'];
    if (filesize($videoTmp) > POC_VIDEO_MAX) return [null, '錄影檔太大（上限 10 MB），請錄短一點。'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($videoTmp);
    if (!isset(POC_VIDEO_TYPES[$mime])) return [null, '錄影格式不支援（' . $mime . '）。'];
    $video = (string)file_get_contents($videoTmp);

    $id = bin2hex(random_bytes(8));
    $dir = poc_dir() . "/$id";
    if (!is_dir(poc_dir()) && !mkdir(poc_dir(), 0770, true)) return [null, '存檔失敗（目錄）。'];
    if (!mkdir($dir, 0770)) return [null, '存檔失敗（目錄）。'];
    $vname = 'video.' . POC_VIDEO_TYPES[$mime];
    if (file_put_contents("$dir/$vname.enc", poc_enc($video)) === false || file_put_contents("$dir/signature.png.enc", poc_enc($sigPng)) === false) return [null, '存檔失敗。'];
    if (PHP_SAPI !== 'cli') @unlink($videoTmp);

    $meta = [
        'id' => $id,
        'created_at' => date('c'),   // 伺服器收到的時間（以主機時鐘為準）
        'signed_at_client' => substr(preg_replace('/[^0-9\/: ]/', '', (string)($f['signed_at_client'] ?? '')), 0, 19),   // 手機上按送出那一刻（蓋在簽名圖上的時間）
        'recipient' => $who,
        'items' => $items,
        'statement' => trim((string)($f['statement'] ?? '')),
        'worker' => $u['display_name'] ?? $u['username'] ?? '',
        'worker_id' => $u['id'] ?? null,
        'consent' => true,
        'composite' => ($f['composite'] ?? '') === '1',   // 簽名是否合成進錄影畫面（熊哥 10-29：簽名直接壓在錄影上）
        'signature_sha256' => hash('sha256', $sigPng),
        'video_file' => $vname,
        'video_mime' => $mime,
        'video_bytes' => strlen($video),
        'video_sha256' => hash('sha256', $video),
        'encrypted' => true,
        'ip' => poc_client_ip(),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ];
    $meta['seal'] = poc_seal($meta);
    file_put_contents("$dir/meta.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    audit('poc_sign_create', 'poc_sign', null, ['id' => $id]);
    return [$id, null];
}

function poc_load(string $id): ?array {
    if (!poc_valid_id($id)) return null;
    $p = poc_dir() . "/$id/meta.json";
    if (!is_file($p)) return null;
    $m = json_decode((string)file_get_contents($p), true);
    return is_array($m) ? $m : null;
}

// 重新驗證：解密後的內容雜湊與存證碼是否仍吻合
function poc_verify(array $m): array {
    $sig = poc_read($m, 'sig');
    $vid = poc_read($m, 'video');
    return [
        'signature' => $sig !== null && hash('sha256', $sig) === $m['signature_sha256'],
        'video' => $vid !== null && hash('sha256', $vid) === $m['video_sha256'],
        'seal' => poc_seal($m) === ($m['seal'] ?? ''),
    ];
}

function poc_purge_expired(): int {
    $n = 0;
    foreach (glob(poc_dir() . '/*/meta.json') ?: [] as $p) {
        $m = json_decode((string)file_get_contents($p), true);
        if (!is_array($m) || strtotime($m['created_at'] ?? 'now') > time() - POC_KEEP_DAYS * 86400) continue;
        $dir = dirname($p);
        foreach (glob("$dir/*") ?: [] as $f) unlink($f);
        rmdir($dir);
        audit('poc_sign_purge', 'poc_sign', null, ['id' => $m['id'] ?? '']);
        $n++;
    }
    return $n;
}

function poc_list(): array {
    poc_purge_expired();
    $out = [];
    foreach (glob(poc_dir() . '/*/meta.json') ?: [] as $p) {
        $m = json_decode((string)file_get_contents($p), true);
        if (is_array($m)) $out[] = $m;
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

// 每日存證碼清單：把某一天建立的每筆「編號＋時間＋存證碼」與整份清單的雜湊，排進通知佇列寄到外部信箱。
// 寄出去的那份不在這台主機上，事後若有人連 meta 一起改，跟信裡的存證碼就對不起來。
// 只放編號與雜湊，不放姓名與物資內容。同一天重跑不會重寄（dedupe_key）。回傳筆數。
function poc_digest(string $day): int {
    $rows = array_values(array_filter(poc_list(), fn($m) => substr(date('Y-m-d', strtotime($m['created_at'])), 0, 10) === $day));
    if (!$rows) return 0;
    usort($rows, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    $lines = array_map(fn($m) => $m['id'] . '  ' . date('Y-m-d H:i:s', strtotime($m['created_at'])) . '  ' . $m['seal'], $rows);
    $body = "錄影簽名雛形 {$day} 存證碼清單（" . count($rows) . " 筆）\n\n" . implode("\n", $lines)
          . "\n\n清單雜湊（SHA-256）：" . hash('sha256', implode("\n", $lines))
          . "\n\n用途：日後核對主機上的紀錄是否被改過。請保留本信，勿刪除。";
    notify('poc_digest', null, POC_DIGEST_TO, "【存證碼】錄影簽名雛形 {$day}（" . count($rows) . ' 筆）', $body, "poc_digest#$day");
    return count($rows);
}
