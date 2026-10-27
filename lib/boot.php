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
require_once __DIR__ . '/version.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    session_boot();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_check(): void {
    if (PHP_SAPI === 'cli') return;
    session_boot();   // 不依賴前面有人先開過 session（正式站是登入那一步開的，內建伺服器測試時沒有）
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
// 取不到（沒給、格式不對、過期被淘汰、別的瀏覽器的代碼）一律回 null；
// 呼叫端要分清「網址沒有 f」與「有 f 但取不到」——後者不可退回空條件，否則匯出會變成全部資料（小鹿 10-19 互審）。
function filter_load(mixed $k): ?array {
    session_boot();
    if (!is_string($k) || !preg_match('/\A[0-9a-f]{12}\z/', $k)) return null;
    return $_SESSION['filters'][$k] ?? null;
}
const FILTER_EXPIRED = '搜尋條件已過期（或是在別的瀏覽器開的），請重新搜尋。';

function page_head(string $title, ?array $u = null): void {
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '｜急難救助系統｜中華基督教救助協會</title><link rel="icon" href="/assets/ccra-logo.png">';
    echo '<link rel="stylesheet" href="/assets/site.css?v=' . RELIEF_VERSION . '"></head><body>';
    // 協會 CI：標誌＋協會全名，標準色見 site.css 的 --brand（取自協會官網）
    echo '<header class="top"><a class="brand" href="/"><img src="/assets/ccra-logo.png" alt="中華基督教救助協會標誌" width="40" height="40">'
       . '<span><b>急難救助系統</b><small>中華基督教救助協會</small></span></a><span class="tag">實驗版</span>';
    if ($u) {
        $here = $_SERVER['SCRIPT_NAME'] ?? '/';
        echo '<nav>';
        foreach (menu_for($u['role']) as $m) {
            $on = str_starts_with($here, $m[1]);
            echo '<a href="' . h($m[1]) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . h($m[0]) . '</a>';
        }
        echo '</nav><span class="me"><span>' . h($u['display_name']) . '<small>' . h(ROLE_NAMES[$u['role']] ?? $u['role']) . '</small></span>'
           . '<a class="out" href="/logout.php">登出</a></span>';
    }
    echo '</header><main>';
}
function page_foot(): void {
    echo '</main><footer class="foot"><p>急難救助系統 v' . h(RELIEF_VERSION) . '（' . h(RELIEF_RELEASED) . '）・資訊部維護</p>'
       . '<p>© ' . date('Y') . ' 社團法人中華基督教救助協會 Chinese Christian Relief Association. 版權所有。</p></footer></body></html>';
}
