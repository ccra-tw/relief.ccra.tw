<?php
// 通知信佇列（#9）：主機不寄信，由資訊部的寄送端來取件、寄出、回報。
//   php bin/notify_queue.php peek [N]         只看佇列，不取件
//   php bin/notify_queue.php claim [N]        取最多 N 筆未取件的（預設 20），先記 claimed_at 再輸出 JSON
//   php bin/notify_queue.php done <id>        寄成功
//   php bin/notify_queue.php fail <id> <原因> 寄失敗（不自動重寄；寧可漏寄不重寄，由人看 error 決定）
// 輸出的收件信箱：內部同工用 app_user.email；教會帳號沒有信箱時留空並標 no_email，寄送端略過。
require __DIR__ . '/../lib/boot.php';
$cmd = $argv[1] ?? '';
$db = rdb();
if ($cmd === 'peek') {   // 只看不取件（寄送端乾跑用），不寫 claimed_at
    $n = max(1, min(200, (int)($argv[2] ?? 20)));
    echo json_encode($db->query("SELECT n.id, n.kind, n.subject, COALESCE(n.to_email, u.email) AS email FROM notification n LEFT JOIN app_user u ON u.id = n.to_user_id WHERE n.claimed_at IS NULL ORDER BY n.id LIMIT $n")->fetchAll(), JSON_UNESCAPED_UNICODE), "\n";
} elseif ($cmd === 'claim') {
    $n = max(1, min(200, (int)($argv[2] ?? 20)));
    $db->beginTransaction();
    $rows = $db->query("SELECT n.id, n.kind, n.subject, n.body, COALESCE(n.to_email, u.email) AS email, u.display_name
                        FROM notification n LEFT JOIN app_user u ON u.id = n.to_user_id
                        WHERE n.claimed_at IS NULL ORDER BY n.id LIMIT $n FOR UPDATE OF n SKIP LOCKED")->fetchAll();
    $up = $db->prepare('UPDATE notification SET claimed_at = now() WHERE id = ?');
    foreach ($rows as &$r) {
        $up->execute([$r['id']]);
        if (!$r['email']) { $r['no_email'] = true; $db->prepare("UPDATE notification SET error = 'no_email' WHERE id = ?")->execute([$r['id']]); }
    }
    $db->commit();
    echo json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
} elseif ($cmd === 'done' && ctype_digit($argv[2] ?? '')) {
    $db->prepare('UPDATE notification SET sent_at = now(), error = NULL WHERE id = ? AND claimed_at IS NOT NULL')->execute([(int)$argv[2]]);
} elseif ($cmd === 'fail' && ctype_digit($argv[2] ?? '')) {
    $db->prepare('UPDATE notification SET error = ? WHERE id = ?')->execute([mb_substr((string)($argv[3] ?? 'failed'), 0, 300), (int)$argv[2]]);
} else {
    fwrite(STDERR, "用法：claim [N]｜done <id>｜fail <id> <原因>\n"); exit(2);
}
