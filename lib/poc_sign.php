<?php
// 錄影簽名雛形（POC）：社工簽收單＋手寫簽名＋錄影聲明。
// 熊哥 2026-10-29 看過電子簽章服務的展示後問「我們可以做到錄影簽名的部分嗎」，要先做雛形試用。
// 刻意不碰正式資料表：每筆存成一個資料夾（meta.json＋signature.png＋video.*），只放測試資料。
// 存證方式：簽名圖與錄影各算 SHA-256，再把整份 meta（含兩個雜湊）正規化後算一個「存證碼」，
// 事後檔案或內容動過任何一處，重算就對不上。

const POC_ROLES = ['region_worker', 'region_head', 'hq_worker', 'hq_head', 'admin'];
const POC_VIDEO_MAX = 10 * 1024 * 1024;   // 與 php-fpm upload_max_filesize 一致；前端以約 0.8 Mbps 錄 30 秒約 3 MB
const POC_SIG_MAX = 600 * 1024;
const POC_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
const POC_KEEP_DAYS = 30;   // 個資法：告知的保存期限要真的做到；poc_list() 每次執行時清掉過期的資料夾

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

    $id = bin2hex(random_bytes(8));
    $dir = poc_dir() . "/$id";
    if (!is_dir(poc_dir()) && !mkdir(poc_dir(), 0770, true)) return [null, '存檔失敗（目錄）。'];
    if (!mkdir($dir, 0770)) return [null, '存檔失敗（目錄）。'];
    $vname = 'video.' . POC_VIDEO_TYPES[$mime];
    $moved = PHP_SAPI === 'cli' ? copy($videoTmp, "$dir/$vname") : move_uploaded_file($videoTmp, "$dir/$vname");
    if (!$moved || file_put_contents("$dir/signature.png", $sigPng) === false) return [null, '存檔失敗。'];

    $meta = [
        'id' => $id,
        'created_at' => date('c'),
        'recipient' => $who,
        'items' => $items,
        'statement' => trim((string)($f['statement'] ?? '')),
        'worker' => $u['display_name'] ?? $u['username'] ?? '',
        'worker_id' => $u['id'] ?? null,
        'consent' => true,
        'composite' => ($f['composite'] ?? '') === '1',   // 簽名是否合成進錄影畫面（熊哥 10-29：簽名直接壓在錄影上）
        'signature_sha256' => hash_file('sha256', "$dir/signature.png"),
        'video_file' => $vname,
        'video_mime' => $mime,
        'video_bytes' => filesize("$dir/$vname"),
        'video_sha256' => hash_file('sha256', "$dir/$vname"),
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

// 重新驗證：檔案雜湊與存證碼是否仍吻合
function poc_verify(array $m): array {
    $dir = poc_dir() . '/' . $m['id'];
    return [
        'signature' => is_file("$dir/signature.png") && hash_file('sha256', "$dir/signature.png") === $m['signature_sha256'],
        'video' => is_file("$dir/" . $m['video_file']) && hash_file('sha256', "$dir/" . $m['video_file']) === $m['video_sha256'],
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
