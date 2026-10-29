<?php
// 錄影簽名雛形：收執頁。顯示簽收內容、簽名、錄影，並當場重算雜湊驗證沒被改過。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
$m = poc_load((string)($_GET['id'] ?? ''));
if (!$m) { http_response_code(404); page_head('找不到', $u); echo '<p>找不到這筆簽收紀錄。</p>'; page_foot(); exit; }
audit('poc_sign_view', 'poc_sign', null, ['id' => $m['id']]);   // 存取紀錄：誰在何時調閱
$v = poc_verify($m);
$ok = fn($b) => $b ? '<span style="color:#0a7d3b">✔ 吻合</span>' : '<span style="color:#c62828">✘ 不吻合（檔案或內容被改過）</span>';
page_head('簽收收執（雛形）', $u);
echo '<h1>簽收收執 <small class="tag">雛形</small></h1>';
echo '<table class="kv">';
echo '<tr><th>時間</th><td>' . h(date('Y-m-d H:i:s', strtotime($m['created_at']))) . '</td></tr>';
echo '<tr><th>簽收人（代稱）</th><td>' . h($m['recipient']) . '</td></tr>';
echo '<tr><th>物資</th><td>' . nl2br(h($m['items'])) . '</td></tr>';
echo '<tr><th>聲明內容</th><td>' . h($m['statement']) . '</td></tr>';
echo '<tr><th>經手同工</th><td>' . h($m['worker']) . '</td></tr>';
echo '<tr><th>簽名合成進錄影</th><td>' . (!empty($m['composite']) ? '是（影片裡看得到簽名過程）' : '否（此裝置不支援合成，簽名另存）') . '</td></tr>';
echo '<tr><th>錄影同意</th><td>' . ($m['consent'] ? '已勾選同意' : '—') . '</td></tr>';
echo '</table>';
echo '<h2>錄影（聲明＋簽名過程）</h2><video src="/poc/file.php?id=' . h($m['id']) . '&amp;k=video" controls playsinline style="width:100%;max-width:420px;border-radius:12px"></video>';
echo '<h2>手寫簽名</h2><img src="/poc/file.php?id=' . h($m['id']) . '&amp;k=sig" alt="簽名" style="width:100%;max-width:600px;border:1px solid #ddd;border-radius:12px;background:#fff">';
echo '<h2>存證</h2><table class="kv">';
echo '<tr><th>存證碼</th><td><code>' . h($m['seal']) . '</code><br>' . $ok($v['seal']) . '</td></tr>';
echo '<tr><th>簽名 SHA-256</th><td><code>' . h($m['signature_sha256']) . '</code><br>' . $ok($v['signature']) . '</td></tr>';
echo '<tr><th>錄影 SHA-256</th><td><code>' . h($m['video_sha256']) . '</code>（' . h($m['video_mime']) . '，' . number_format($m['video_bytes'] / 1048576, 1) . ' MB）<br>' . $ok($v['video']) . '</td></tr>';
echo '<tr><th>來源 IP</th><td>' . h($m['ip'] ?? '（未記錄）') . '</td></tr>';
echo '<tr><th>儲存方式</th><td>' . (!empty($m['encrypted']) ? '加密存放（個資金鑰）' : '明碼（v0.3.5 前的試用資料）') . '</td></tr>';
echo '<tr><th>裝置</th><td><small>' . h($m['user_agent']) . '</small></td></tr>';
echo '</table>';
echo '<p class="note">每次打開這頁都會解密並重新計算雜湊；簽名圖、錄影或任何一個欄位被改過，對應那行就會顯示「不吻合」。每天的存證碼另會寄到主機以外的信箱留存，供日後比對。</p>';
echo '<p><a href="/poc/sign.php">再簽一筆</a>　<a href="/poc/">雛形紀錄列表</a></p>';
page_foot();
