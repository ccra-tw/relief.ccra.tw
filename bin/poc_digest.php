<?php
// 錄影簽名雛形：每日把前一天的存證碼清單排進通知佇列，寄到主機以外的信箱（說明見 lib/poc_sign.php 的 poc_digest）。
// 用法：php bin/poc_digest.php [YYYY-MM-DD]（預設昨天）
require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/poc_sign.php';
$day = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));
if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day)) { fwrite(STDERR, "日期格式要 YYYY-MM-DD\n"); exit(2); }
echo date('c') . " poc_digest {$day}：" . poc_digest($day) . " 筆\n";
