<?php
// 附件下載（#5）：先確認看得到那份申請，再從網站目錄外讀檔；每次下載寫稽核。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(MENU['cases'][2]);
$s = rdb()->prepare('SELECT * FROM attachment WHERE id = ? AND deleted_at IS NULL');
$s->execute([(int)($_GET['id'] ?? 0)]);
$f = $s->fetch();
if (!$f || !app_load($u, (int)$f['application_id'])) { http_response_code(404); exit('找不到檔案。'); }
$path = upload_dir() . '/' . basename($f['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('檔案不存在。'); }
audit('export', 'attachment', $f['id'], ['application_id' => $f['application_id']]);
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($f['orig_name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
