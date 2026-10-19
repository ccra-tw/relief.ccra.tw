<?php
// 測試用：在命令列模擬一次網頁請求。RELIEF_REQ＝JSON {page, method, post}；
// 替身身分 RELIEF_TEST_USER、測試資料表 RELIEF_TEST_DB=1（見 lib/auth.php、lib/db.php 的測試接縫）。
$r = json_decode((string)getenv('RELIEF_REQ'), true);
$_SERVER['REQUEST_METHOD'] = $r['method'] ?? 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . basename($r['page']);
$_POST = $r['post'] ?? [];
$_GET = $r['get'] ?? [];
chdir(dirname($r['page']));
include $r['page'];
