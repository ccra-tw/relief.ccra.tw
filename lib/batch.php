<?php
// 撥款批次、匯款、領據（#8）。
const HQ_ROLES = ['hq_worker', 'hq_head'];
const FIN_ROLES = ['accountant', 'cashier'];

// 未產出批次：已通過、未結案的申請，每案只取「最小期數、還沒進批次也還沒撥」的那一期
function unbatched(): array {
    return rdb()->query("SELECT DISTINCT ON (i.application_id) i.*, a.case_no, a.name, a.kind, a.parent_id, a.region_id, a.church_oid
        FROM installment i JOIN application a ON a.id = i.application_id
        WHERE a.status = 'approved' AND a.closed_at IS NULL AND i.paid_on IS NULL AND i.batch_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM installment j WHERE j.application_id = i.application_id AND j.seq < i.seq AND j.paid_on IS NULL)
        ORDER BY i.application_id, i.seq")->fetchAll();
}

// 服管組產出暫存批次（把目前所有未產出批次的項目放進去）。回傳 [batch id 或 null, 錯誤]
function batch_create(array $u): array {
    if (!in_array($u['role'], HQ_ROLES, true)) return [null, '批次由服管組產出。'];
    $items = unbatched();
    if (!$items) return [null, '目前沒有待撥款的項目。'];
    $db = rdb(); $db->beginTransaction();
    try {
        $y = (int)date('Y');
        $no = (int)$db->query("SELECT COALESCE(max(no),0)+1 FROM batch WHERE year = $y")->fetchColumn();
        $s = $db->prepare('INSERT INTO batch (year, no, created_by) VALUES (?,?,?) RETURNING id'); $s->execute([$y, $no, $u['id']]);
        $bid = (int)$s->fetchColumn();
        $up = $db->prepare('UPDATE installment SET batch_id = ?, region_confirmed = false WHERE id = ? AND batch_id IS NULL');
        foreach ($items as $it) $up->execute([$bid, $it['id']]);
        $regions = array_unique(array_column($items, 'region_id'));
        foreach ($regions as $rid) {
            $w = $db->prepare("SELECT id FROM app_user WHERE active AND role IN ('region_worker','region_head') AND region_id = ?"); $w->execute([$rid]);
            foreach ($w->fetchAll(PDO::FETCH_COLUMN) as $uid) notify('batch_confirm', (int)$uid, null, "撥款批次 {$y}-{$no} 請確認", "服管組已產出暫存批次 {$y}-{$no}，請確認貴區的項目或退出不撥的案件。");
        }
        audit('create', 'batch', $bid, ['year' => $y, 'no' => $no, 'items' => count($items)]);
        $db->commit();
        return [$bid, null];
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

function batch_row(int $id): ?array { $s = rdb()->prepare('SELECT * FROM batch WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

// 批次明細（可只看某區）；含教會帳戶
function batch_items(int $bid, ?int $regionId = null): array {
    $sql = "SELECT i.*, a.case_no, a.name, a.region_id, a.kind, r.code AS rcode, r.name AS rname, c.name AS church, c.bank_name, c.bank_code, c.branch_code, c.account_no, c.account_name,
              (SELECT count(*) FROM installment k WHERE k.application_id = i.application_id) AS total_seq
            FROM installment i JOIN application a ON a.id = i.application_id JOIN region r ON r.id = a.region_id JOIN church c ON c.oid = a.church_oid
            WHERE i.batch_id = ?" . ($regionId ? ' AND a.region_id = ?' : '') . ' ORDER BY r.code, a.case_no, i.seq';
    $s = rdb()->prepare($sql); $s->execute($regionId ? [$bid, $regionId] : [$bid]);
    return $s->fetchAll();
}

// 各區小計與合計
function batch_totals(int $bid): array {
    $t = ['regions' => [], 'total' => 0, 'count' => 0];
    foreach (batch_items($bid) as $it) {
        $t['regions'][$it['rcode']] = ($t['regions'][$it['rcode']] ?? 0) + (int)$it['amount'];
        $t['total'] += (int)$it['amount']; $t['count']++;
    }
    return $t;
}

// 區辦確認或退出（退出＝清除批號，回到未產出批次）
function batch_region_act(array $u, int $installmentId, string $act): ?string {
    if (!in_array($u['role'], ['region_worker', 'region_head'], true)) return '批次確認由區辦處理。';
    $s = rdb()->prepare('SELECT i.*, a.region_id, b.status AS bstatus FROM installment i JOIN application a ON a.id = i.application_id JOIN batch b ON b.id = i.batch_id WHERE i.id = ?');
    $s->execute([$installmentId]); $it = $s->fetch();
    if (!$it || (int)$it['region_id'] !== (int)$u['region_id']) return '找不到貴區的這個項目。';
    if ($it['bstatus'] !== 'confirming') return '批次已送出，不能再確認或退出。';
    if ($act === 'confirm') rdb()->prepare('UPDATE installment SET region_confirmed = true WHERE id = ?')->execute([$installmentId]);
    elseif ($act === 'withdraw') rdb()->prepare('UPDATE installment SET batch_id = NULL, region_confirmed = false WHERE id = ?')->execute([$installmentId]);
    else return '未知的動作。';
    audit('update', 'installment', $installmentId, ['batch' => $it['batch_id'], 'act' => $act]);
    return null;
}

// 服管組送會計：每一項都要區辦確認過
function batch_send(array $u, int $bid): ?string {
    if (!in_array($u['role'], HQ_ROLES, true)) return '由服管組送出。';
    $b = batch_row($bid);
    if (!$b || $b['status'] !== 'confirming') return '這個批次不在確認階段。';
    $items = batch_items($bid);
    if (!$items) return '批次裡沒有項目。';
    $left = array_filter($items, fn($i) => !$i['region_confirmed']);
    if ($left) return '還有 ' . count($left) . ' 筆區辦尚未確認。';
    rdb()->prepare("UPDATE batch SET status='sent', sent_by=?, sent_at=now() WHERE id=?")->execute([$u['id'], $bid]);
    $f = rdb()->query("SELECT id FROM app_user WHERE active AND role IN ('accountant','cashier')");
    foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $uid) notify('batch_sent', (int)$uid, null, "撥款批次 {$b['year']}-{$b['no']} 已送會計", '請依匯款清單撥款，完成後標記匯款成功。');
    audit('update', 'batch', $bid, ['status' => 'sent', 'total' => array_sum(array_column($items, 'amount'))]);
    return null;
}

// 出納標記匯款成功：寫撥款日、開放領據、通知教會與區辦
function batch_paid(array $u, int $bid, string $date): ?string {
    if ($u['role'] !== 'cashier') return '由出納標記匯款成功。';
    $b = batch_row($bid);
    if (!$b || $b['status'] !== 'sent') return '這個批次還沒送到會計，或已經標記過。';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '匯款日期格式不正確。';
    $db = rdb(); $db->beginTransaction();
    try {
        $db->prepare("UPDATE batch SET status='paid', paid_by=?, paid_on=? WHERE id=?")->execute([$u['id'], $date, $bid]);
        foreach (batch_items($bid) as $it) {
            $db->prepare('UPDATE installment SET paid_on = ? WHERE id = ?')->execute([$date, $it['id']]);
            $db->prepare('UPDATE application SET last_paid_at = ? WHERE id = ?')->execute([$date, $it['application_id']]);
            $a = app_row((int)$it['application_id']);
            $label = $a['case_no'] ?: (app_row((int)$a['parent_id'])['case_no'] ?? '');
            notify_church($a, '急難救助金已匯款', "案號 {$label} 第 {$it['seq']} 期已於 {$date} 匯款，領據已開放下載，請於 10 天內簽回。");
            notify_region_workers($a, '急難救助金已匯款', "案號 {$label} 第 {$it['seq']} 期已於 {$date} 匯款。");
        }
        audit('update', 'batch', $bid, ['status' => 'paid', 'paid_on' => $date]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return null;
}

// 匯款清單（服管組表單 6-5 格式）CSV；匯出寫稽核
function batch_export_csv(array $u, int $bid): array {
    if (!in_array($u['role'], array_merge(HQ_ROLES, FIN_ROLES, ['admin']), true)) return [null, '沒有匯出權限。'];
    $b = batch_row($bid);
    if (!$b) return [null, '找不到批次。'];
    $rows = [["批號：{$b['year']}-{$b['no']}"], ['社團法人中華基督教救助協會'], [date('Y年n月')], ['匯款清單'],
             ['編號', '銀行名稱', '銀行總行代號', '分行代號', '帳號', '帳戶名稱', '項目', '金額', '批次日期', '教會名稱', '區辦']];
    $n = 0;
    foreach (batch_items($bid) as $it) {
        $rows[] = [++$n, $it['bank_name'], $it['bank_code'], $it['branch_code'], $it['account_no'], $it['account_name'],
                   "{$it['name']}（{$it['total_seq']}-{$it['seq']}）", (int)$it['amount'], date('Y-m-d', strtotime($b['created_at'])), $it['church'], $it['rname']];
    }
    $out = "\xEF\xBB\xBF";
    foreach ($rows as $r) $out .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $r)) . "\r\n";
    audit('export', 'batch', $bid, ['format' => 'remit_list', 'rows' => $n]);
    return [$out, null];
}

// 領據簽回登記（區辦或服管組登記；教會從畫面下載列印）
function receipt_back(array $u, int $installmentId, string $date): ?string {
    if (!in_array($u['role'], ['region_worker', 'region_head', 'hq_worker', 'hq_head', 'admin'], true)) return '由區辦或服管組登記領據簽回。';
    $s = rdb()->prepare('SELECT i.*, a.region_id FROM installment i JOIN application a ON a.id = i.application_id WHERE i.id = ?'); $s->execute([$installmentId]);
    $it = $s->fetch();
    if (!$it || !$it['paid_on']) return '這一期還沒匯款，不能登記領據。';
    if (in_array($u['role'], ['region_worker', 'region_head'], true) && (int)$it['region_id'] !== (int)$u['region_id']) return '只能登記貴區的領據。';
    rdb()->prepare('UPDATE installment SET receipt_back_on = ?, receipt_by = ? WHERE id = ?')->execute([$date, $u['id'], $installmentId]);
    audit('update', 'installment', $installmentId, ['receipt_back_on' => $date]);
    return null;
}

// 未回領據（提醒用；#9 每 10 天寄一次；不凍結）
function receipts_outstanding(?string $today = null): array {
    $t = $today ?? date('Y-m-d');
    $s = rdb()->prepare("SELECT i.id, i.application_id, i.seq, i.paid_on, (?::date - i.paid_on) AS days FROM installment i WHERE i.paid_on IS NOT NULL AND i.receipt_back_on IS NULL ORDER BY i.paid_on");
    $s->execute([$t]);
    return $s->fetchAll();
}

// 教會收款帳戶設定（區辦／服管組／管理者）；帳號去空白、只收數字（需求 0004）
function church_account_set(array $u, string $oid, array $f): ?string {
    if (!can_edit_church($u, $oid)) return '只能設定貴區的教會。';
    $acct = norm_account((string)($f['account_no'] ?? ''));
    if ($acct === null || $acct === '') return '帳號必填，且只能是數字。';
    foreach (['bank_code', 'branch_code'] as $k) if (!ctype_digit(preg_replace('/\s+/', '', (string)($f[$k] ?? '')))) return '銀行代號與分行代號只能是數字。';
    if (trim($f['bank_name'] ?? '') === '' || trim($f['account_name'] ?? '') === '') return '銀行名稱與戶名必填。';
    rdb()->prepare('UPDATE church SET bank_name=?, bank_code=?, branch_code=?, account_no=?, account_name=? WHERE oid=?')
         ->execute([trim($f['bank_name']), preg_replace('/\s+/', '', $f['bank_code']), preg_replace('/\s+/', '', $f['branch_code']), $acct, trim($f['account_name']), $oid]);
    audit('update', 'church', $oid, ['field' => 'bank_account']);
    return null;
}

function can_edit_church(array $u, string $oid): bool {
    if (in_array($u['role'], ['hq_worker', 'hq_head', 'admin'], true)) return true;
    return in_array($u['role'], ['region_worker', 'region_head'], true) && church_region($oid) === (int)$u['region_id'];
}
