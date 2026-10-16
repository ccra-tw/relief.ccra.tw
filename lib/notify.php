<?php
// 通知信收件對象（#9）。依服管組「急難救助金系統通知 Email 內容」每封信寫的「系統寄給…」：
//   進入某一關 → 寄給該關審核者（牧者／區辦一社工／他區社工／服管組社工）；會簽 → 固定會簽者
//   退件 → 退回上一關，寄給那一關的審核者（見 return_recipients）；退回教會時寄教會
//   第四關通過 → 區辦一社工、區辦一主任、教會（含牧長）
//   匯款成功 → 區辦社工、區辦主任、服管組社工、服管組主任；需求 0003 再加教會同工與牧者
//   服務紀錄未回、領據未回、結案提醒 → 教會與牧長
// 信件內容只放案號與教會，不放受助人姓名與其他個資（信可能被轉寄）。

function users_by(string $where, array $params): array {
    $s = rdb()->prepare("SELECT id FROM app_user WHERE active AND $where ORDER BY id");
    $s->execute($params);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

function rcpt_church(array $a): array { return users_by("church_oid = ? AND role IN ('church_staff','church_pastor')", [$a['church_oid']]); }
function rcpt_pastor(array $a): array { return users_by("church_oid = ? AND role = 'church_pastor'", [$a['church_oid']]); }
function rcpt_region(array $a): array { return users_by("role = 'region_worker' AND region_id = ?", [$a['region_id']]); }
function rcpt_region_head(array $a): array { return users_by("role = 'region_head' AND region_id = ?", [$a['region_id']]); }
function rcpt_hq(): array { return users_by("role IN ('hq_worker','hq_head')", []); }

function rcpt_assigned(array $a, string $stage): array {
    $s = rdb()->prepare('SELECT user_id FROM assignment WHERE application_id = ? AND stage = ?');
    $s->execute([$a['id'], $stage]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

// 退件退回到的那一關要通知的人（服管組 2026-10-16 高榕回覆：一律退回上一關；遇到會簽不退給會簽者、退給該關社工）。
// 退回教會（returned）時回傳空陣列，教會另外由 notify_church 通知。
function return_recipients(array $a, string $target): array {
    return match ($target) {
        'pastor_review' => rcpt_pastor($a),
        'region_review' => rcpt_region($a),
        'other_review'  => rcpt_assigned($a, 'other_region'),
        'hq_review'     => rcpt_assigned($a, 'hq'),
        default         => [],
    };
}

// 一次寄給多位；$key 有給時每位收件人各一個防重寄鍵（key#uid）。回傳實際新增筆數
function notify_users(string $kind, array $uids, string $subject, string $body, ?string $key = null): int {
    $n = 0;
    foreach (array_unique($uids) as $uid) $n += notify($kind, (int)$uid, null, $subject, $body, $key === null ? null : "$key#$uid");
    return $n;
}

function case_label(array $a): string {
    return $a['case_no'] ?: (($a['parent_id'] ?? null) ? (app_row((int)$a['parent_id'])['case_no'] ?? '') : '');
}

// 提醒排程（每天跑一次；重跑不會重寄）。不凍結（服管組 10/15 回覆刪除 28 天凍結）
//   服務紀錄：距上次撥款或服務紀錄滿 10 天起，每 10 天提醒一次
//   領據：匯款後滿 10 天未簽回，每 10 天提醒一次
//   結案提醒：每一期都已匯款、尚未結案也沒有送結案報告 → 提醒一次
function run_reminders(?string $today = null): array {
    $t = $today ?? date('Y-m-d');
    $cnt = ['service' => 0, 'receipt' => 0, 'closure' => 0];
    $apps = rdb()->query("SELECT * FROM application WHERE status = 'approved' AND closed_at IS NULL AND kind = 'new'")->fetchAll();
    foreach ($apps as $a) {
        $d = service_days_since($a, $t);
        if ($d !== null && $d >= 10) {
            $cnt['service'] += notify_users('remind_service', rcpt_church($a), '服務紀錄填寫提醒',
                "案號 {$a['case_no']} 距上次撥款或服務紀錄已 {$d} 天，請志工填寫服務紀錄。", 'svc:' . $a['id'] . ':' . intdiv($d, 10));
        }
        $s = rdb()->prepare("SELECT count(*) AS n, count(paid_on) AS paid FROM installment i JOIN application x ON x.id = i.application_id WHERE x.id = ? OR x.parent_id = ?");
        $s->execute([$a['id'], $a['id']]);
        $p = $s->fetch();
        $pending = rdb()->prepare("SELECT count(*) FROM closure WHERE application_id = ? AND status IN ('pending_review','closed')");
        $pending->execute([$a['id']]);
        if ((int)$p['n'] > 0 && (int)$p['n'] === (int)$p['paid'] && !(int)$pending->fetchColumn()) {
            $cnt['closure'] += notify_users('remind_closure', rcpt_church($a), '結案提醒',
                "案號 {$a['case_no']} 各期急難救助金都已匯款，請填寫結案報告與結案問卷。", 'close:' . $a['id']);
        }
    }
    foreach (receipts_outstanding($t) as $r) {
        if ((int)$r['days'] < 10) continue;
        $a = app_row((int)$r['application_id']);
        $cnt['receipt'] += notify_users('remind_receipt', rcpt_church($a), '領據回收提醒',
            '案號 ' . case_label($a) . " 第 {$r['seq']} 期（{$r['paid_on']} 匯款）的領據尚未簽回，請簽名後交給區辦。", 'rcpt:' . $r['id'] . ':' . intdiv((int)$r['days'], 10));
    }
    return $cnt;
}
