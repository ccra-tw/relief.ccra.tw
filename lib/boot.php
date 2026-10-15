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

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    session_boot();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_check(): void {
    if (PHP_SAPI === 'cli') return;
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('表單已過期，請重新整理後再送出。'); }
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
