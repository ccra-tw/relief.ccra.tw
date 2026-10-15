<?php
// 異動申請、服務紀錄、結案（#7）。
//   異動：沿用審核引擎（application kind='change'、parent_id 指原案），不另編案號、通過時不改開案日
//   12 個月 2 次（服管組 2026-10-15）：從開案月份往後推 12 個月為一個區間，區間內原案＋異動最多 2 次，且不可重疊（前一筆還在審就不能再送）
//   服務紀錄：教會填；撥款後每 10 天提醒（#9），不凍結（「28 天未回凍結」已刪除）
//   結案：提早結案「2-3 選項」要區辦審核，其餘送出即結案；結案日＝教會送出結案報告日；結案後唯讀
require_once __DIR__ . '/followup_spec.php';

function change_window(array $parent): array {
    $start = date('Y-m-01', strtotime($parent['opened_at']));
    $end = date('Y-m-t', strtotime($start . ' +11 months'));
    return [$start, $end];
}

function is_closed(array $a): bool { return $a['status'] === 'closed' || !empty($a['closed_at']); }

function church_owns(array $u, array $a): bool {
    return in_array($u['role'], APPLY_ROLES, true) && $u['church_oid'] === $a['church_oid'];
}

// 建立異動申請（草稿）。回傳 [id 或 null, 錯誤陣列]
function change_create(array $u, int $parentId, array $in): array {
    $p = app_row($parentId);
    if (!$p || !church_owns($u, $p)) return [null, ['_' => '找不到原案。']];
    if ($p['kind'] !== 'new' || $p['status'] !== 'approved' || !$p['opened_at']) return [null, ['_' => '只有已通過的原案可以提出異動。']];
    if (is_closed($p)) return [null, ['_' => '已結案的個案不能再異動。']];
    [, $end] = change_window($p);
    if (date('Y-m-d') > $end) return [null, ['_' => "已超過開案後 12 個月的區間（到 {$end}），請提出新申請。"]];
    $q = rdb()->prepare("SELECT status FROM application WHERE parent_id = ? AND kind = 'change' AND status <> 'rejected'");
    $q->execute([$parentId]);
    $prev = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($prev && array_diff($prev, ['approved'])) return [null, ['_' => '這個個案已有一筆異動還在處理中，不能重疊申請。']];
    if (count($prev) >= 1) return [null, ['_' => '開案後 12 個月內最多申請 2 次（原案＋1 次異動），這個個案已用完。']];
    [$data, $err] = form_validate($in, CHANGE_SPEC);
    if ($err) return [null, $err];
    $amount = $data['amount_requested']; unset($data['amount_requested']);
    $s = rdb()->prepare("INSERT INTO application (person_id, church_oid, region_id, kind, parent_id, name, data, amount_requested, applicant_id, applicant_phone)
                         VALUES (?,?,?,'change',?,?,?,?,?,?) RETURNING id");
    $s->execute([$p['person_id'], $p['church_oid'], $p['region_id'], $parentId, $p['name'], json_encode($data, JSON_UNESCAPED_UNICODE), $amount, $u['id'], $p['applicant_phone']]);
    $id = (int)$s->fetchColumn();
    audit('create', 'application', $id, ['kind' => 'change', 'parent_id' => $parentId]);
    return [$id, []];
}

// 服務紀錄（教會填；系統帶入登入者為志工）
function service_add(array $u, int $appId, array $in): array {
    $a = app_row($appId);
    if (!$a || !church_owns($u, $a)) return ['_' => '找不到這個個案。'];
    if ($a['kind'] !== 'new' || $a['status'] !== 'approved') return ['_' => '申請通過後才能填服務紀錄。'];
    if (is_closed($a)) return ['_' => '已結案，不能再新增服務紀錄。'];
    [$data, $err] = form_validate($in, SERVICE_SPEC);
    if ($err) return $err;
    $s = rdb()->prepare('INSERT INTO service_record (application_id, user_id, data, served_at) VALUES (?,?,?,?) RETURNING id');
    $s->execute([$appId, $u['id'], json_encode($data, JSON_UNESCAPED_UNICODE), str_replace('T', ' ', $data['served_at'])]);
    audit('create', 'service_record', $s->fetchColumn(), ['application_id' => $appId]);
    return [];
}

// 距離上次「撥款或服務紀錄」幾天（提醒判斷用；撥款日在 #8 寫入 disbursed_at 後一併納入）
function service_days_since(array $a, ?string $today = null): ?int {
    if ($a['status'] !== 'approved' || is_closed($a)) return null;
    $s = rdb()->prepare('SELECT max(served_at)::date FROM service_record WHERE application_id = ?'); $s->execute([$a['id']]);
    $last = max(array_filter([$s->fetchColumn(), $a['opened_at'], $a['last_paid_at'] ?? null]));
    return (int)((strtotime($today ?? date('Y-m-d')) - strtotime($last)) / 86400);
}

// 送出結案報告＋結案問卷。回傳錯誤陣列
function closure_submit(array $u, int $appId, array $in): array {
    $a = app_row($appId);
    if (!$a || !church_owns($u, $a)) return ['_' => '找不到這個個案。'];
    if ($a['kind'] !== 'new' || $a['status'] !== 'approved') return ['_' => '申請通過的個案才能結案。'];
    if (is_closed($a)) return ['_' => '這個個案已經結案。'];
    $p = rdb()->prepare("SELECT 1 FROM closure WHERE application_id = ? AND status = 'pending_review'"); $p->execute([$appId]);
    if ($p->fetchColumn()) return ['_' => '已有一份結案申請在等區辦審核。'];
    [$data, $err] = form_validate($in, CLOSURE_SPEC);
    if ($err) return $err;
    $type = $data['close_type']['sel'][0];
    $needReview = in_array($type, CLOSE_REVIEW_TYPES, true);
    $db = rdb(); $db->beginTransaction();
    try {
        $s = $db->prepare('INSERT INTO closure (application_id, close_type, data, status, submitted_by) VALUES (?,?,?,?,?) RETURNING id');
        $s->execute([$appId, $type, json_encode($data, JSON_UNESCAPED_UNICODE), $needReview ? 'pending_review' : 'closed', $u['id']]);
        $cid = $s->fetchColumn();
        if (!$needReview) close_case($appId);
        else notify_region_workers($a, '有一份提早結案等您審核', "案號 {$a['case_no']} 申請提早結案（{$type}）。");
        audit('create', 'closure', $cid, ['application_id' => $appId, 'type' => $type, 'review' => $needReview]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return [];
}

function close_case(int $appId, ?string $date = null): void {
    rdb()->prepare("UPDATE application SET status='closed', closed_at=COALESCE(?::date, current_date), updated_at=now() WHERE id=?")->execute([$date, $appId]);
}

// 區辦審核提早結案：approve／return／reject；通過時結案日＝教會送出結案報告那天
function closure_review(array $u, int $closureId, string $action, string $opinion): ?string {
    $c = rdb()->prepare("SELECT * FROM closure WHERE id = ? AND status = 'pending_review'"); $c->execute([$closureId]);
    $cl = $c->fetch();
    if (!$cl) return '找不到待審的結案申請。';
    $a = app_row((int)$cl['application_id']);
    if ($u['role'] !== 'region_worker' || (int)$u['region_id'] !== (int)$a['region_id']) return '提早結案由該區辦社工審核。';
    if (trim($opinion) === '') return '請填寫審核意見。';
    $to = ['approve' => 'closed', 'return' => 'returned', 'reject' => 'rejected'][$action] ?? null;
    if (!$to) return '未知的動作。';
    $db = rdb(); $db->beginTransaction();
    try {
        $db->prepare('UPDATE closure SET status=?, reviewed_by=?, reviewed_at=now(), review_opinion=? WHERE id=?')->execute([$to, $u['id'], $opinion, $closureId]);
        if ($to === 'closed') close_case((int)$a['id'], date('Y-m-d', strtotime($cl['submitted_at'])));
        notify_church($a, '結案申請審核結果', "案號 {$a['case_no']} 的結案申請：" . ['closed' => '通過', 'returned' => '退件，請修改後再送', 'rejected' => '不通過'][$to] . "。意見：{$opinion}");
        audit('review', 'closure', $closureId, ['action' => $action]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return null;
}

function notify_region_workers(array $a, string $subject, string $body): void {
    notify_users('closure_review', rcpt_region($a), $subject, $body);
}
