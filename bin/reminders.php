<?php
// 每日提醒排程（#9）：服務紀錄未回、領據未回、結案提醒。重跑不會重寄（notification.dedupe_key）。
// 用法：php bin/reminders.php [YYYY-MM-DD]（不給日期＝今天）
require __DIR__ . '/../lib/boot.php';
$t = $argv[1] ?? null;
if ($t !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) { fwrite(STDERR, "日期格式要 YYYY-MM-DD\n"); exit(2); }
$c = run_reminders($t);
echo "提醒：服務紀錄 {$c['service']}、領據 {$c['receipt']}、結案 {$c['closure']}\n";
