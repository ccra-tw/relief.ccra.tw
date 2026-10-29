<?php
// 錄影簽名雛形：串流簽名圖或錄影（需登入、限同工角色）。支援 Range，手機播放影片才拖得動。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
require_role(POC_ROLES);
$m = poc_load((string)($_GET['id'] ?? ''));
$k = $_GET['k'] ?? '';
if (!$m || !in_array($k, ['sig', 'video'], true)) { http_response_code(404); exit('找不到'); }
$path = poc_dir() . '/' . $m['id'] . '/' . ($k === 'sig' ? 'signature.png' : $m['video_file']);
$type = $k === 'sig' ? 'image/png' : $m['video_mime'];
$size = filesize($path); $start = 0; $end = $size - 1;
header('Content-Type: ' . $type);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');
if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $r)) {
    if ($r[1] !== '') $start = (int)$r[1];
    if ($r[2] !== '') $end = min((int)$r[2], $size - 1);
    if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int)$r[2]); $end = $size - 1; }
    if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fp = fopen($path, 'rb'); fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp)) { $b = fread($fp, min(65536, $left)); echo $b; $left -= strlen($b); }
fclose($fp);
