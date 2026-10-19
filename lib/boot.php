<?php
// 每一頁的開頭：require __DIR__ . '/../lib/boot.php';
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/church.php';
require_once __DIR__ . '/application.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/followup.php';
require_once __DIR__ . '/batch.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/report.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    session_boot();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_check(): void {
    if (PHP_SAPI === 'cli') return;
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('表單已過期，請重新整理後再送出。'); }
}

// 搜尋條件存伺服器端，網址只帶一個隨機短代碼（2026-10-19 個資稽核，熊哥核可）。
// 為什麼：報表依「個案姓名」查詢、稽核紀錄依帳號搜尋時，條件若放網址（GET），姓名會留在
// nginx 存取紀錄、瀏覽器歷史與 Cloudflare 紀錄。需求 0004 要「匯出後按返回帶回條件」，
// 所以不能單純改 POST——改成 POST 存條件、轉址到 ?f=代碼，返回時用代碼取回。
const FILTER_KEEP = 20;   // 每個 session 最多留幾組條件，舊的自動丟掉，避免 session 無限長大
function filter_stash(array $f): string {
    session_boot();
    $k = bin2hex(random_bytes(6));
    $all = $_SESSION['filters'] ?? [];
    $all[$k] = $f;
    $_SESSION['filters'] = array_slice($all, -FILTER_KEEP, null, true);
    return $k;
}
function filter_load(?string $k): ?array {
    session_boot();
    if ($k === null || !preg_match('/^[0-9a-f]{12}$/', $k)) return null;
    return $_SESSION['filters'][$k] ?? null;
}

function page_head(string $title, ?array $u = null): void {
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '｜急難救助系統</title><link rel="stylesheet" href="/assets/site.css"></head><body>';
    echo '<header><a class="brand" href="/">急難救助系統<small>（實驗版）</small></a>';
    if ($u) {
        echo '<nav>';
        foreach (menu_for($u['role']) as $m) echo '<a href="' . h($m[1]) . '">' . h($m[0]) . '</a>';
        echo '</nav><span class="me">' . h($u['display_name']) . '・' . h(ROLE_NAMES[$u['role']] ?? $u['role']) . ' <a href="/logout.php">登出</a></span>';
    }
    echo '</header><main>';
}
function page_foot(): void { echo '</main></body></html>'; }
