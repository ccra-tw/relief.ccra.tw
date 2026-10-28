<?php
// 教會主檔與帳號申請的業務規則（#4）。頁面只負責表單，規則都在這裡，測試直接呼叫。
require_once __DIR__ . '/directory.php';

const CHURCH_ROLES = ['region_worker', 'region_head', 'hq_worker', 'hq_head', 'admin'];

function area_region(int $areaId): ?int {
    $s = rdb()->prepare('SELECT region_id FROM area WHERE id = ? AND active'); $s->execute([$areaId]);
    $r = $s->fetchColumn(); return $r === false ? null : (int)$r;
}

// 區辦角色只能綁到自己區辦底下的區域；服管組與管理者不限
function can_use_area(array $u, int $areaId): bool {
    $rid = area_region($areaId);
    if ($rid === null) return false;
    if (in_array($u['role'], ['hq_worker', 'hq_head', 'admin'], true)) return true;
    return in_array($u['role'], ['region_worker', 'region_head'], true) && (int)$u['region_id'] === $rid;
}

// 綁定名錄教會；回傳錯誤訊息或 null
function church_bind(array $u, string $oid, int $areaId): ?string {
    if (!can_use_area($u, $areaId)) return '只能綁定到您所屬區辦底下的區域。';
    $c = dir_get($oid);
    if (!$c) return '名錄查無這個 oid。';
    $s = rdb()->prepare('INSERT INTO church (oid, name, city, town, address, tel, email, president, area_id, bound_by) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT (oid) DO NOTHING RETURNING oid');
    $s->execute([$c['oid'], $c['name'], $c['city'], $c['town'], $c['address'], $c['tel'], $c['email'], $c['president'], $areaId, $u['id']]);
    if (!$s->fetchColumn()) return '這間教會已經綁定過了。';
    audit('create', 'church', $c['oid'], ['area_id' => $areaId, 'name' => $c['name']]);
    return null;
}

function church_add_request(array $u, array $f): ?string {
    $areaId = (int)($f['area_id'] ?? 0);
    if (!can_use_area($u, $areaId)) return '只能為您所屬區辦底下的區域提出。';
    foreach (['name' => '教會名稱', 'tel' => '電話', 'city' => '縣市', 'address' => '地址'] as $k => $label)
        if (trim($f[$k] ?? '') === '') return "{$label}必填。";
    // 名錄的 add_data 會建一個待審核的名錄資料；「管理密碼」由名錄要求，這裡隨機產生，不給教會也不存明文
    $resp = dir_add(['ocname' => $f['name'], 'passwd' => bin2hex(random_bytes(8)), 'code' => 'TW', 'tel' => $f['tel'],
                     'email1' => $f['email'] ?? '', 'zipcode' => $f['zipcode'] ?? '', 'city' => $f['city'], 'area' => $f['town'] ?? '',
                     // 小秘 10/07：申請內容開頭帶「【急難救助系統申請】」，她在名錄後台審核時依此辨識來源（名錄記不到金鑰）
                     'address' => '【急難救助系統申請】' . $f['address']]);
    $s = rdb()->prepare('INSERT INTO church_add_request (name, tel, email, zipcode, city, town, address, area_id, api_response, requested_by) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
    $s->execute([$f['name'], $f['tel'], $f['email'] ?? null, $f['zipcode'] ?? null, $f['city'], $f['town'] ?? null, $f['address'], $areaId, json_encode($resp, JSON_UNESCAPED_UNICODE), $u['id']]);
    audit('create', 'church_add_request', $s->fetchColumn(), ['name' => $f['name']]);
    return null;
}

// 名錄核可後：填回 oid，同時綁定為教會
function church_add_approved(array $u, int $reqId, string $oid): ?string {
    $r = rdb()->prepare("SELECT * FROM church_add_request WHERE id = ? AND status = 'submitted'"); $r->execute([$reqId]);
    $row = $r->fetch();
    if (!$row) return '找不到待處理的新增申請。';
    if ($err = church_bind($u, $oid, (int)$row['area_id'])) return $err;
    rdb()->prepare("UPDATE church_add_request SET status='approved', oid=?, decided_at=now() WHERE id=?")->execute([$oid, $reqId]);
    audit('update', 'church_add_request', $reqId, ['status' => 'approved', 'oid' => $oid]);
    return null;
}

// 可以提出急難申請的教會：已綁定且啟用（不在名錄、或名錄還沒核可的，church 表裡根本沒有它）
// 從名錄更新已綁定教會的名稱、地址、聯絡資料（名錄是主檔）。回傳 [更新筆數, 查無筆數]
function church_sync(): array {
    $up = rdb()->prepare('UPDATE church SET name=?, city=?, town=?, address=?, tel=?, email=?, president=?, synced_at=now() WHERE oid=?');
    $n = $miss = 0;
    foreach (rdb()->query('SELECT oid FROM church ORDER BY oid')->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        $c = dir_get((string)$oid);
        if (!$c) { $miss++; continue; }
        $up->execute([$c['name'], $c['city'], $c['town'], $c['address'], $c['tel'], $c['email'], $c['president'], $oid]);
        $n++;
    }
    return [$n, $miss];
}

function church_can_apply(string $oid): bool {
    $s = rdb()->prepare('SELECT 1 FROM church WHERE oid = ? AND active'); $s->execute([$oid]);
    return (bool)$s->fetchColumn();
}

function church_region(string $oid): ?int {
    $s = rdb()->prepare('SELECT a.region_id FROM church c JOIN area a ON a.id = c.area_id WHERE c.oid = ?'); $s->execute([$oid]);
    $r = $s->fetchColumn(); return $r === false ? null : (int)$r;
}

// ---- 教會帳號申請 ----
function account_request_create(array $u, array $f): ?string {
    if ($u['role'] !== 'region_worker') return '教會帳號申請由區辦社工提出。';
    $oid = (string)($f['church_oid'] ?? '');
    $rid = church_region($oid);
    if ($rid === null) return '教會尚未綁定名錄。';
    if ($rid !== (int)$u['region_id']) return '只能為自己區辦的教會申請帳號。';
    $username = strtolower(trim($f['username'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{3,30}$/', $username)) return '帳號限 4～31 個英數字（可含 . _ -）。';
    $q = rdb()->prepare('SELECT 1 FROM app_user WHERE username = ? UNION SELECT 1 FROM account_request WHERE username = ? AND status = \'pending\'');
    $q->execute([$username, $username]);
    if ($q->fetchColumn()) return '這個帳號已有人使用或正在申請。';
    $role = $f['role'] ?? 'church_staff';
    if (!in_array($role, ['church_staff', 'church_pastor'], true)) return '角色只能是教會同工或教會牧者。';
    if (trim($f['display_name'] ?? '') === '') return '名稱必填。';
    $s = rdb()->prepare('INSERT INTO account_request (church_oid, username, display_name, role, email, region_id, requested_by) VALUES (?,?,?,?,?,?,?) RETURNING id');
    $s->execute([$oid, $username, trim($f['display_name']), $role, trim($f['email'] ?? '') ?: null, $rid, $u['id']]);
    audit('create', 'account_request', $s->fetchColumn(), ['church_oid' => $oid, 'username' => $username, 'role' => $role]);
    return null;
}

// 區辦主任核可／退回；核可時建帳號、產生初始密碼、寫通知。回傳 [錯誤或 null, 初始密碼或 null]
function account_request_decide(array $u, int $id, bool $approve, string $reason = ''): array {
    $s = rdb()->prepare("SELECT * FROM account_request WHERE id = ? AND status = 'pending'"); $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return ['找不到待審的帳號申請。', null];
    if ($u['role'] !== 'region_head' || (int)$u['region_id'] !== (int)$r['region_id']) return ['只有該區辦主任可以核可。', null];
    $db = rdb();
    $db->beginTransaction();
    try {
        if (!$approve) {
            if (trim($reason) === '') { $db->rollBack(); return ['退回要寫原因。', null]; }
            $db->prepare("UPDATE account_request SET status='rejected', decided_by=?, decided_at=now(), reject_reason=? WHERE id=?")->execute([$u['id'], $reason, $id]);
            notify('account_rejected', (int)$r['requested_by'], null, '教會帳號申請未核可', "帳號 {$r['username']} 的申請未核可。原因：$reason");
            audit('review', 'account_request', $id, ['result' => 'rejected', 'reason' => $reason]);
            $db->commit();
            return [null, null];
        }
        $pw = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kz'), '=');
        $ins = $db->prepare('INSERT INTO app_user (username, pass_hash, display_name, role, church_oid) VALUES (?,?,?,?,?) RETURNING id');
        $ins->execute([$r['username'], password_hash($pw, PASSWORD_DEFAULT), $r['display_name'], $r['role'], $r['church_oid']]);
        $newId = $ins->fetchColumn();
        $db->prepare("UPDATE account_request SET status='approved', decided_by=?, decided_at=now(), user_id=? WHERE id=?")->execute([$u['id'], $newId, $id]);
        // 初始密碼不進通知信（信可能被轉寄）；由區辦社工當面或電話交給教會，信裡只說帳號已開通
        notify('account_approved', (int)$r['requested_by'], null, '教會帳號已開通', "帳號 {$r['username']} 已開通，請將初始密碼交給教會，首次登入後請教會更改。");
        if ($r['email']) notify('account_approved', null, $r['email'], '急難救助系統帳號已開通', "您的帳號 {$r['username']} 已開通，區辦社工會另外提供初始密碼。");
        audit('review', 'account_request', $id, ['result' => 'approved', 'user_id' => $newId]);
        audit('create', 'user', $newId, ['username' => $r['username'], 'role' => $r['role'], 'via' => 'account_request']);
        $db->commit();
        return [null, $pw];
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

// 先記後寄：只寫進 notification，由寄送端另外寄。$key 有給時同一個 key 只記一筆（回傳 0＝已記過）
function notify(string $kind, ?int $toUser, ?string $toEmail, string $subject, string $body, ?string $key = null): int {
    $s = rdb()->prepare('INSERT INTO notification (kind, to_user_id, to_email, subject, body, dedupe_key) VALUES (?,?,?,?,?,?) ON CONFLICT DO NOTHING');
    $s->execute([$kind, $toUser, $toEmail, $subject, $body, $key]);
    return $s->rowCount();
}
