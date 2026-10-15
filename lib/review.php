<?php
// 審核四關＋會簽＋派案（#6）。依服管組 2026-10-15 回覆（#1 留言）：
//   起點兩種：教會同工送出 → 教會牧者審；教會牧者自己送出 → 直接到區辦（牧者那關視為已完成）
//   區辦社工 → 他區區辦社工（隨機，排除本區）→ 服管組社工或主任（隨機）→ 通過（開案日＝通過日）
//   審核者若「須會簽」，審完先交給他固定的會簽者；會簽完成才進下一關
//   退件回到教會修改；再送審時，他區與服管組回到原本派到的那一位（需求 0001），沒派過的才隨機派
const STAGES = ['pastor_review' => 'pastor', 'region_review' => 'region', 'other_review' => 'other_region', 'hq_review' => 'hq'];
const STATUS_NAMES = [
    'draft' => '教會同工尚未送審', 'pastor_review' => '教會牧者審核中', 'region_review' => '區辦社工審核中', 'other_review' => '他區社工審核中',
    'hq_review' => '服管組審核中', 'approved' => '申請通過', 'returned' => '退件（教會修改中）', 'rejected' => '不通過', 'closed' => '已結案',
];

// 審核修改表（服管組）：狀態 × 角色 → all（全部欄位＋上傳）／basic（姓名、電話、手機、地址＋上傳）／不能改
// 主任、會計、出納、社工助理整欄空白＝不能改。他區社工只有被派到的那一案才算（見 edit_level）。
const EDIT_MATRIX = [
    'draft'         => ['church_staff' => 'all', 'church_pastor' => 'all', 'region_worker' => 'all', 'hq_worker' => 'all'],
    'returned'      => ['church_staff' => 'all', 'church_pastor' => 'all', 'region_worker' => 'all', 'hq_worker' => 'all'],
    'pastor_review' => ['church_staff' => 'all', 'church_pastor' => 'all', 'region_worker' => 'all', 'hq_worker' => 'all'],
    'region_review' => ['region_worker' => 'all', 'hq_worker' => 'all'],
    'other_review'  => ['region_worker' => 'all', 'other_worker' => 'all', 'hq_worker' => 'all'],
    'hq_review'     => ['region_worker' => 'all', 'other_worker' => 'all', 'hq_worker' => 'all'],
    'approved'      => ['church_staff' => 'basic', 'church_pastor' => 'basic', 'region_worker' => 'basic'],
    'rejected'      => [],
];
const BASIC_FIELDS = ['name', 'tel', 'mobile', 'address'];

function app_row(int $id): ?array {
    $s = rdb()->prepare('SELECT * FROM application WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

// 這位使用者對這一案目前能改到什麼程度：'all'／'basic'／null
function edit_level(array $u, array $a): ?string {
    if ($a['status'] === 'closed' || !empty($a['closed_at'])) return null;   // 結案後唯讀，管理者也不例外（#7）
    if ($u['role'] === 'admin') return 'all';                     // 最高權限：任何狀態都可改
    // 異動申請在「教會牧者異動審核中」時，教會同工那格是空白（審核修改表）
    if (($a['kind'] ?? 'new') === 'change' && $a['status'] === 'pastor_review' && $u['role'] === 'church_staff') return null;
    $m = EDIT_MATRIX[$a['status']] ?? [];
    $r = $u['role'];
    if ($r === 'region_worker') {
        $own = (int)$u['region_id'] === (int)$a['region_id'];
        if ($own) return $m['region_worker'] ?? null;
        $assigned = rdb()->prepare("SELECT 1 FROM assignment WHERE application_id = ? AND stage = 'other_region' AND user_id = ?");
        $assigned->execute([$a['id'], $u['id']]);
        return $assigned->fetchColumn() ? ($m['other_worker'] ?? null) : null;
    }
    if (in_array($r, ['church_staff', 'church_pastor'], true)) return $u['church_oid'] === $a['church_oid'] ? ($m[$r] ?? null) : null;
    return $m[$r] ?? null;
}

function next_case_no(array $a): string {
    $db = rdb();
    $c = $db->prepare('SELECT r.code AS rcode, ar.code AS acode FROM church c JOIN area ar ON ar.id = c.area_id JOIN region r ON r.id = ar.region_id WHERE c.oid = ?');
    $c->execute([$a['church_oid']]); $x = $c->fetch();
    $y = (int)date('Y');
    $s = $db->prepare('INSERT INTO case_seq (region_id, year, last) VALUES (?, ?, 1) ON CONFLICT (region_id, year) DO UPDATE SET last = case_seq.last + 1 RETURNING last');
    $s->execute([$a['region_id'], $y]);
    return sprintf('%s-%s-%d-%04d', $x['rcode'], $x['acode'], $y, $s->fetchColumn());
}

function step(int $appId, string $stage, string $part, int $uid, string $action, ?string $opinion): void {
    rdb()->prepare('INSERT INTO review_step (application_id, stage, part, user_id, action, opinion) VALUES (?,?,?,?,?,?)')
         ->execute([$appId, $stage, $part, $uid, $action, $opinion]);
}

// 教會送審（新送或退件後再送）。回傳錯誤或 null
function app_submit(array $u, int $id): ?string {
    $a = app_row($id);
    if (!$a || !in_array($u['role'], APPLY_ROLES, true) || $u['church_oid'] !== $a['church_oid']) return '找不到這份申請。';
    if (!in_array($a['status'], ['draft', 'returned'], true)) return '這份申請已在審核中。';
    $db = rdb(); $db->beginTransaction();
    try {
        // 送審即編案號；退件再送沿用。異動申請不另編號，沿用原案（畫面顯示原案案號）
        $caseNo = $a['kind'] === 'change' ? null : ($a['case_no'] ?: next_case_no($a));
        $to = $u['role'] === 'church_pastor' ? 'region_review' : 'pastor_review';
        $db->prepare('UPDATE application SET status=?, case_no=?, submitted_at=COALESCE(submitted_at, now()), cosign_user_id=NULL, cosign_for_id=NULL, updated_at=now() WHERE id=?')
           ->execute([$to, $caseNo, $id]);
        step($id, 'submit', 'submit', $u['id'], 'submit', null);
        audit('review', 'application', $id, ['action' => 'submit', 'to' => $to, 'case_no' => $caseNo]);
        notify_stage($id, $to);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return null;
}

// 這位使用者現在能不能在這一案上做審核動作；回傳 'review'／'cosign'／null
function review_part(array $u, array $a): ?string {
    if (!isset(STAGES[$a['status']])) return null;
    if ($a['cosign_user_id']) return (int)$a['cosign_user_id'] === (int)$u['id'] ? 'cosign' : null;
    switch ($a['status']) {
        case 'pastor_review': return $u['role'] === 'church_pastor' && $u['church_oid'] === $a['church_oid'] ? 'review' : null;
        case 'region_review': return $u['role'] === 'region_worker' && (int)$u['region_id'] === (int)$a['region_id'] ? 'review' : null;
        case 'other_review': case 'hq_review':
            $s = rdb()->prepare('SELECT user_id FROM assignment WHERE application_id = ? AND stage = ?');
            $s->execute([$a['id'], STAGES[$a['status']]]);
            return (int)$s->fetchColumn() === (int)$u['id'] ? 'review' : null;
    }
    return null;
}

// 派案：已派過（被退回後再送）就回到原本那位；沒派過才隨機（需求 0001）
function assign(array $a, string $stage): ?int {
    $db = rdb();
    $s = $db->prepare('SELECT user_id FROM assignment WHERE application_id = ? AND stage = ?'); $s->execute([$a['id'], $stage]);
    if ($uid = $s->fetchColumn()) return (int)$uid;
    if ($stage === 'other_region') {
        $c = $db->prepare("SELECT id FROM app_user WHERE active AND role = 'region_worker' AND region_id IS NOT NULL AND region_id <> ?");
        $c->execute([$a['region_id']]);
    } else {
        $c = $db->query("SELECT id FROM app_user WHERE active AND role IN ('hq_worker','hq_head')");
    }
    $ids = $c->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return null;
    $pick = (int)$ids[random_int(0, count($ids) - 1)];
    $db->prepare('INSERT INTO assignment (application_id, stage, user_id) VALUES (?,?,?)')->execute([$a['id'], $stage, $pick]);
    audit('create', 'assignment', $a['id'], ['stage' => $stage, 'user_id' => $pick]);
    return $pick;
}

// 期數與金額：[1 => 6000, 2 => 6000, ...]；整組取代
function set_installments(array $u, int $appId, string $stage, array $amounts): ?string {
    $clean = [];
    foreach (array_values($amounts) as $i => $v) {
        $v = preg_replace('/[\s,]/', '', (string)$v);
        if ($v === '') continue;
        if (!ctype_digit($v) || (int)$v <= 0) return '期數金額只能填正整數。';
        $clean[$i + 1] = (int)$v;
    }
    if (!isset($clean[1])) return '第一期核發金額必填（需求 0003）。';
    if (array_keys($clean) !== range(1, count($clean))) return '期數要從第一期連續填寫，中間不能空。';
    $db = rdb();
    $db->prepare('DELETE FROM installment WHERE application_id = ?')->execute([$appId]);
    $ins = $db->prepare('INSERT INTO installment (application_id, seq, amount, set_stage, set_by) VALUES (?,?,?,?,?)');
    foreach ($clean as $seq => $amt) $ins->execute([$appId, $seq, $amt, $stage, $u['id']]);
    return null;
}

// 審核動作：approve（完成審核）／return（退件）／reject（不通過）。意見必填。
function review_act(array $u, int $id, string $action, string $opinion, ?array $installments = null): ?string {
    $a = app_row($id);
    if (!$a) return '找不到這份申請。';
    $part = review_part($u, $a);
    if (!$part) return '這一案目前不在您的審核關卡。';
    if (!in_array($action, ['approve', 'return', 'reject'], true)) return '未知的動作。';
    $opinion = trim($opinion);
    if ($opinion === '') return $action === 'approve' ? '請填寫審核意見。' : '請填寫退件或不通過的原因。';
    $stage = STAGES[$a['status']];
    $db = rdb(); $db->beginTransaction();
    try {
        if ($installments !== null && $stage !== 'pastor') {
            if ($e = set_installments($u, $id, $stage, $installments)) { $db->rollBack(); return $e; }
        }
        if ($action === 'approve' && $stage === 'region' && $part === 'review') {
            $n = $db->prepare('SELECT count(*) FROM installment WHERE application_id = ? AND seq = 1'); $n->execute([$id]);
            if (!$n->fetchColumn()) { $db->rollBack(); return '區辦審核要配置期數與金額，第一期必填（需求 0003）。'; }
        }
        step($id, $stage, $part, $u['id'], $action, $opinion);
        if ($action === 'return') {
            $db->prepare("UPDATE application SET status='returned', cosign_user_id=NULL, cosign_for_id=NULL, updated_at=now() WHERE id=?")->execute([$id]);
            notify_church($a, '急難救助申請被退件', "案號 {$a['case_no']} 被退件，原因：{$opinion}。請修改後再送審。");
        } elseif ($action === 'reject') {
            $db->prepare("UPDATE application SET status='rejected', cosign_user_id=NULL, cosign_for_id=NULL, updated_at=now() WHERE id=?")->execute([$id]);
            notify_church($a, '急難救助申請未通過', "案號 {$a['case_no']} 審核未通過。");
        } elseif ($part === 'review' && $u['needs_cosign'] && $u['cosigner_id']) {
            $db->prepare('UPDATE application SET cosign_user_id=?, cosign_for_id=?, updated_at=now() WHERE id=?')->execute([$u['cosigner_id'], $u['id'], $id]);
            notify((string)'cosign_needed', (int)$u['cosigner_id'], null, '有一案等您會簽', "案號 {$a['case_no']} 已由審核者完成審核，等您會簽。");
        } else {
            advance($a);
        }
        audit('review', 'application', $id, ['stage' => $stage, 'part' => $part, 'action' => $action]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return null;
}

function advance(array $a): void {
    $next = ['pastor_review' => 'region_review', 'region_review' => 'other_review', 'other_review' => 'hq_review', 'hq_review' => 'approved'][$a['status']];
    $db = rdb();
    if ($next === 'approved') {
        // 開案日＝原案通過日；異動通過不改開案日（12 個月區間從原案算）
        $db->prepare("UPDATE application SET status='approved', opened_at=CASE WHEN kind = 'new' THEN current_date ELSE opened_at END, cosign_user_id=NULL, cosign_for_id=NULL, updated_at=now() WHERE id=?")->execute([$a['id']]);
        notify_church($a, '急難救助申請通過', "案號 {$a['case_no']} 審核通過，後續撥款進度會再通知。");
        return;
    }
    if (in_array($next, ['other_review', 'hq_review'], true) && assign($a, STAGES[$next]) === null)
        throw new RuntimeException('找不到可派案的審核者（' . STATUS_NAMES[$next] . '）');
    $db->prepare('UPDATE application SET status=?, cosign_user_id=NULL, cosign_for_id=NULL, updated_at=now() WHERE id=?')->execute([$next, $a['id']]);
    notify_stage((int)$a['id'], $next);
}

function notify_stage(int $id, string $status): void {
    $a = app_row($id);
    if (in_array($status, ['other_review', 'hq_review'], true)) {
        $s = rdb()->prepare('SELECT user_id FROM assignment WHERE application_id = ? AND stage = ?'); $s->execute([$id, STAGES[$status]]);
        if ($uid = $s->fetchColumn()) notify('review_assigned', (int)$uid, null, '有一案派給您審核', "案號 {$a['case_no']} 已派給您（" . STATUS_NAMES[$status] . '）。');
    }
}

function notify_church(array $a, string $subject, string $body): void {
    $s = rdb()->prepare("SELECT id FROM app_user WHERE active AND church_oid = ? AND role IN ('church_staff','church_pastor')");
    $s->execute([$a['church_oid']]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) notify('church_result', (int)$uid, null, $subject, $body);
}

// 依審核修改表修改申請內容。'all' 整份表單重新驗證；'basic' 只改姓名、電話、手機、地址。回傳 [錯誤陣列]
function app_update(array $u, int $id, array $in): array {
    $a = app_row($id);
    if (!$a) return ['_' => '找不到這份申請。'];
    $lv = edit_level($u, $a);
    if (!$lv) return ['_' => '這個狀態下您不能修改這份申請（依審核修改表）。'];
    $db = rdb();
    $data = json_decode($a['data'], true);
    if ($lv === 'basic') {
        $name = trim((string)($in['name'] ?? $a['name']));
        if ($name === '') return ['name' => '個案姓名必填'];
        foreach (['tel', 'mobile', 'address'] as $k) if (array_key_exists($k, $in)) $data[$k] = $k === 'address' ? trim((string)$in[$k]) : preg_replace('/\s+/', '', (string)$in[$k]);
        if (($data['mobile'] ?? '') === '' || ($data['address'] ?? '') === '') return ['_' => '手機與地址必填'];
        $db->prepare('UPDATE application SET name=?, data=?, updated_at=now() WHERE id=?')->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE), $id]);
        audit('update', 'application', $id, ['level' => 'basic']);
        return [];
    }
    [$nd, $err] = form_validate($in);
    if ($err) return $err;
    $amount = $nd['amount_requested']; unset($nd['amount_requested']);
    $name = $nd['name']; unset($nd['name']);
    $db->prepare('UPDATE application SET name=?, data=?, amount_requested=?, updated_at=now() WHERE id=?')->execute([$name, json_encode($nd, JSON_UNESCAPED_UNICODE), $amount, $id]);
    audit('update', 'application', $id, ['level' => 'all', 'status' => $a['status']]);
    return [];
}

// 待我審核的清單
function my_queue(array $u): array {
    $s = rdb()->query("SELECT * FROM application WHERE status IN ('pastor_review','region_review','other_review','hq_review') ORDER BY submitted_at, id");
    return array_values(array_filter($s->fetchAll(), fn($a) => review_part($u, $a) !== null));
}
