<?php
// 錄影簽名雛形：接收簽名與錄影，回 JSON {id} 或 {error}。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit(json_encode(['error' => '只接受 POST'])); }
csrf_check();
$sig = ($_FILES['signature']['error'] ?? 1) === UPLOAD_ERR_OK ? (string)file_get_contents($_FILES['signature']['tmp_name']) : '';
$vid = ($_FILES['video']['error'] ?? 1) === UPLOAD_ERR_OK ? $_FILES['video']['tmp_name'] : '';
if (($_FILES['video']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) exit(json_encode(['error' => '錄影檔太大（上限 10 MB），請錄短一點。']));
$snap = ($_FILES['snapshot']['error'] ?? 1) === UPLOAD_ERR_OK ? (string)file_get_contents($_FILES['snapshot']['tmp_name']) : '';
[$id, $err] = poc_save($_POST, $sig, $vid, $u, $snap);
echo json_encode($id ? ['id' => $id] : ['error' => $err], JSON_UNESCAPED_UNICODE);
