<?php
// CCNDA 教會機構名錄 API 3.2（#4）。金鑰放主機 /etc/relief/directory.ini（key=...），不進 repo。
// 測試接縫：命令列且 RELIEF_TEST_DIR 指向一個 JSON 檔時，改讀假資料（{"churches":[{oid,ocname,...}]}），不碰真的 API。
const DIR_BASE = 'https://church.oursweb.net';
const DIR_INI  = '/etc/relief/directory.ini';

function dir_fixture(): ?array {
    $f = PHP_SAPI === 'cli' ? getenv('RELIEF_TEST_DIR') : false;
    return $f ? json_decode((string)file_get_contents($f), true) : null;
}

function dir_key(): string {
    $c = @parse_ini_file(DIR_INI);
    if (empty($c['key'])) throw new RuntimeException('名錄 API 金鑰未設定');
    return $c['key'];
}

function dir_http(string $path, ?array $post = null): array {
    $ctx = ['http' => ['timeout' => 15, 'ignore_errors' => true]];
    if ($post !== null) $ctx['http'] += ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($post)];
    $raw = @file_get_contents(DIR_BASE . '/api/' . rawurlencode(dir_key()) . $path, false, stream_context_create($ctx));
    if ($raw === false) throw new RuntimeException('名錄 API 連線失敗');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['raw' => mb_substr($raw, 0, 500)];
}

// 依名稱搜尋，回傳 [{oid, name, city, town, address}]
function dir_search(string $kw): array {
    $kw = trim($kw);
    if (mb_strlen($kw) < 2) return [];
    // 名錄 /search/ 回的是「以 oid 為鍵的物件」{"811500":{oid,ocname,...}, ...}，不是陣列（2026-10-15 實測）
    $r = ($fx = dir_fixture()) ? $fx['search'] : dir_http('/search/' . rawurlencode($kw));
    $rows = array_values(array_filter($r, fn($c) => is_array($c) && isset($c['oid']) && str_contains((string)($c['ocname'] ?? ''), $kw)));
    return array_map('dir_norm', array_slice($rows, 0, 50));
}

function dir_get(string $oid): ?array {
    if (!preg_match('/^\d{4,8}$/', $oid)) return null;
    // /oid/ 回單一物件 {oid, ocname, city, town, address, ...}；查無時沒有 oid 欄位
    $r = ($fx = dir_fixture()) ? ($fx['oid'][$oid] ?? []) : dir_http('/oid/' . $oid);
    return isset($r['oid']) ? dir_norm($r) : null;
}

// 送名錄新增申請；回傳 API 回應（記錄用）
function dir_add(array $f): array {
    if ($fx = dir_fixture()) return ['status' => 'fixture-ok'];
    return dir_http('/add_data', $f);
}

function dir_norm(array $c): array {
    return ['oid' => (string)($c['oid'] ?? ''), 'name' => $c['ocname'] ?? $c['oc'] ?? '', 'city' => $c['city'] ?? '', 'town' => $c['town'] ?? '', 'address' => $c['address'] ?? '',
            'tel' => $c['tel'] ?? '', 'email' => $c['email1'] ?? '', 'president' => trim(($c['president'] ?? '') . ' ' . ($c['master_title'] ?? ''))];
}
