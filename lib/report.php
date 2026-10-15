<?php
// 報表與匯出（#10）。欄位依服管組表單 8-1～8-5 的「匯出報表格式」；申請主表加身分證號與就業細項（需求 0004）、
// 同住家屬表（需求 0003）。範圍一律走 scope_sql 在查詢層限縮；每次匯出寫 audit_log。
// 訪視教會紀錄表（8-3）：本系統尚未做訪視教會功能，先不提供。

const REPORTS = [
    'apply'     => '急難救助申請主表',
    'household' => '同住家屬表',
    'church'    => '教會基本資料表',
    'service'   => '服務紀錄表',
    'closure'   => '結案問卷',
];

// 表單答案轉成一格文字：單選／複選接「其他」說明與細項，金額型細項接金額
function fmt_value(array $f, $v): string {
    if ($v === null || $v === '' || $v === []) return '';
    switch ($f['type']) {
        case 'check': return $v ? '是' : '否';
        case 'group':
            $sum = array_sum(array_map('intval', (array)$v));
            return $sum . '（' . implode('、', array_map(fn($k, $x) => "$k " . (int)$x, array_keys((array)$v), (array)$v)) . '）';
        case 'single': case 'multi':
            $out = [];
            foreach ($v['sel'] ?? [] as $o) {
                $t = $o === ($f['other'] ?? null) && ($v['other'] ?? '') !== '' ? "{$o}：{$v['other']}" : $o;
                if (isset($v['sub'][$o])) {
                    $sub = $v['sub'][$o];
                    $parts = array_is_list($sub) ? $sub : array_map(fn($k, $x) => "$k " . (int)$x, array_keys($sub), $sub);
                    if (($v['sub_other'][$o] ?? '') !== '') $parts[] = $v['sub_other'][$o];
                    $t .= '（' . implode('、', $parts) . '）';
                }
                if (isset($v['amount'][$o])) $t .= ' ' . (int)$v['amount'][$o] . ' 元';
                $out[] = $t;
            }
            return implode('、', $out);
        default: return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}

function spec_field(array $spec, string $key): array {
    foreach (form_fields($spec) as $k => $f) if ($k === $key) return $f;
    return ['type' => 'text'];
}

function age_on(?string $birthday, string $on): string {
    if (!$birthday) return '';
    return (string)(new DateTime($birthday))->diff(new DateTime($on))->y;
}

// 篩選條件：region、area、church（名稱關鍵字）、name（個案姓名）、d1／d2（主要日期區間）、o1／o2（開案日期區間）
function report_filters(array $in): array {
    $f = [];
    foreach (['region', 'area'] as $k) if (ctype_digit((string)($in[$k] ?? ''))) $f[$k] = (int)$in[$k];
    foreach (['church', 'name'] as $k) if (trim((string)($in[$k] ?? '')) !== '') $f[$k] = mb_substr(trim((string)$in[$k]), 0, 50);
    foreach (['d1', 'd2', 'o1', 'o2'] as $k) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in[$k] ?? ''))) $f[$k] = $in[$k];
    return $f;
}

// 共同條件：範圍＋大區小區＋教會＋開案日。回傳 [where 片段陣列, 參數陣列]
function report_where(array $u, array $f, string $dateCol): array {
    [$sw, $sp] = scope_sql($u, 'a.region_id', 'a.church_oid');
    $w = ["($sw)"]; $p = $sp;
    if (isset($f['region'])) { $w[] = 'a.region_id = ?'; $p[] = $f['region']; }
    if (isset($f['area']))   { $w[] = 'c.area_id = ?';   $p[] = $f['area']; }
    if (isset($f['church'])) { $w[] = 'c.name ILIKE ?';  $p[] = '%' . $f['church'] . '%'; }
    if (isset($f['name']))   { $w[] = 'a.name ILIKE ?';  $p[] = '%' . $f['name'] . '%'; }
    if (isset($f['d1']))     { $w[] = "$dateCol >= ?";   $p[] = $f['d1']; }
    if (isset($f['d2']))     { $w[] = "$dateCol < (?::date + 1)"; $p[] = $f['d2']; }
    if (isset($f['o1']))     { $w[] = 'a.opened_at >= ?'; $p[] = $f['o1']; }
    if (isset($f['o2']))     { $w[] = 'a.opened_at <= ?'; $p[] = $f['o2']; }
    return [$w, $p];
}

const APPLY_COLS = ['category', 'marital', 'housing', 'source', 'religion', 'support', 'debt', 'employment', 'subsidy', 'emergency',
                    'before', 'event', 'plan', 'income_before', 'income_now', 'spend_before', 'spend_after', 'church_service', 'usage'];

// 回傳 [表頭, 資料列]
function report_rows(array $u, string $kind, array $f): array {
    $db = rdb();
    $base = 'FROM application a JOIN church c ON c.oid = a.church_oid JOIN area ar ON ar.id = c.area_id JOIN region rg ON rg.id = a.region_id';
    $full = in_array($u['role'], PII_FULL_ROLES, true);
    switch ($kind) {
        case 'apply': case 'household':
            [$w, $p] = report_where($u, $f, 'a.submitted_at');
            $s = $db->prepare("SELECT a.*, rg.name AS rname, ar.name AS aname, c.name AS cname, p.idno_enc, p.idno_head, p.idno_last4,
                                      (SELECT sum(amount) FROM installment i WHERE i.application_id = a.id) AS granted,
                                      (SELECT max(r.at) FROM review_step r WHERE r.application_id = a.id AND r.stage = 'hq' AND r.action = 'approve') AS done_at
                               $base JOIN person p ON p.id = a.person_id
                               WHERE a.kind = 'new' AND a.status <> 'draft' AND " . implode(' AND ', $w) . ' ORDER BY a.submitted_at, a.id');
            $s->execute($p);
            $rows = [];
            if ($kind === 'apply') {
                $head = ['編號', '大區', '小區', '個案編號', '個案姓名', '身分證字號', '教會名稱', '申請日期', '審核完成日期', '性別', '生日', '年齡', '通訊地址-縣市'];
                foreach (APPLY_COLS as $k) $head[] = spec_field(FORM_SPEC, $k)['label'];
                $head[] = '審核核發金額';
                foreach ($s as $i => $a) {
                    $d = json_decode($a['data'], true);
                    $r = [$i + 1, $a['rname'], $a['aname'], $a['case_no'], $a['name'], $full ? (string)pii_decrypt($a['idno_enc']) : pii_mask($a['idno_head'], $a['idno_last4']),
                          $a['cname'], substr((string)$a['submitted_at'], 0, 10), substr((string)$a['done_at'], 0, 10),
                          fmt_value(spec_field(FORM_SPEC, 'gender'), $d['gender'] ?? null), $d['birthday'] ?? '', age_on($d['birthday'] ?? null, substr((string)$a['submitted_at'], 0, 10)),
                          mb_substr((string)($d['address'] ?? ''), 0, 3)];
                    foreach (APPLY_COLS as $k) $r[] = fmt_value(spec_field(FORM_SPEC, $k), $d[$k] ?? null);
                    $r[] = $a['granted'];
                    $rows[] = $r;
                }
            } else {
                $head = ['編號', '大區', '小區', '個案編號', '個案姓名', '教會名稱'];
                foreach (HOUSEHOLD_FIELDS as $label) $head[] = $label;
                $n = 0;
                foreach ($s as $a) foreach (json_decode($a['household'], true) ?: [] as $m) {
                    $r = [++$n, $a['rname'], $a['aname'], $a['case_no'], $a['name'], $a['cname']];
                    foreach (HOUSEHOLD_FIELDS as $k => $label) {
                        if ($k === 'idno') {
                            $r[] = empty($m['idno_enc']) ? '' : ($full ? (string)pii_decrypt($m['idno_enc']) : pii_mask($m['idno_head'], $m['idno_last4']));
                        } else $r[] = is_bool($m[$k] ?? null) ? ($m[$k] ? '是' : '否') : (string)($m[$k] ?? '');
                    }
                    $rows[] = $r;
                }
            }
            return [$head, $rows];
        case 'church':
            [$sw, $sp] = scope_sql($u, 'ar.region_id');
            $w = ["($sw)"]; $p = $sp;
            if (isset($f['region'])) { $w[] = 'ar.region_id = ?'; $p[] = $f['region']; }
            if (isset($f['area']))   { $w[] = 'c.area_id = ?';    $p[] = $f['area']; }
            if (isset($f['church'])) { $w[] = 'c.name ILIKE ?';   $p[] = '%' . $f['church'] . '%'; }
            $s = $db->prepare('SELECT c.*, rg.name AS rname, ar.name AS aname FROM church c JOIN area ar ON ar.id = c.area_id JOIN region rg ON rg.id = ar.region_id WHERE '
                              . implode(' AND ', $w) . ' ORDER BY rg.id, ar.id, c.name');
            $s->execute($p);
            $head = ['編號', '大區', '小區', '教會名稱', '名錄代碼', '是否使用', '縣市', '鄉鎮區', '通訊地址', '急難備註'];
            $rows = [];
            foreach ($s as $i => $c) $rows[] = [$i + 1, $c['rname'], $c['aname'], $c['name'], $c['oid'], $c['active'] ? '是' : '否', $c['city'], $c['town'], $c['address'], $c['relief_note']];
            return [$head, $rows];
        case 'service': case 'closure':
            $isC = $kind === 'closure';
            [$w, $p] = report_where($u, $f, $isC ? 'a.closed_at' : 's.served_at');
            $sql = $isC
                ? "SELECT a.case_no, a.opened_at, a.closed_at, rg.name AS rname, ar.name AS aname, c.name AS cname, u.display_name, s.data, s.submitted_at AS at
                   $base JOIN closure s ON s.application_id = a.id AND s.status = 'closed' JOIN app_user u ON u.id = s.submitted_by"
                : "SELECT a.case_no, a.opened_at, rg.name AS rname, ar.name AS aname, c.name AS cname, u.display_name, s.data, s.served_at AS at
                   $base JOIN service_record s ON s.application_id = a.id JOIN app_user u ON u.id = s.user_id";
            $s = $db->prepare($sql . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY at, a.id');
            $s->execute($p);
            $spec = $isC ? CLOSURE_SPEC : SERVICE_SPEC;
            $keys = ['volunteers', 'mode', 'served_at', 'issues', 'content', 'imp_emergency', 'imp_resource', 'imp_mood', 'faith'];
            if ($isC) $keys[] = 'followup';
            $head = $isC ? ['編號', '大區', '小區', '個案編號', '教會名稱', '開案日期', '結案日期', '志工姓名（填寫者）']
                         : ['編號', '大區', '小區', '個案編號', '紀錄日期', '開案日期', '教會名稱', '志工姓名（填寫者）'];
            foreach ($keys as $k) $head[] = spec_field($spec, $k)['label'];
            $rows = [];
            foreach ($s as $i => $x) {
                $d = json_decode($x['data'], true);
                $r = $isC ? [$i + 1, $x['rname'], $x['aname'], $x['case_no'], $x['cname'], $x['opened_at'], $x['closed_at'], $x['display_name']]
                          : [$i + 1, $x['rname'], $x['aname'], $x['case_no'], substr((string)$x['at'], 0, 10), $x['opened_at'], $x['cname'], $x['display_name']];
                foreach ($keys as $k) $r[] = fmt_value(spec_field($spec, $k), $d[$k] ?? null);
                $rows[] = $r;
            }
            return [$head, $rows];
    }
    return [[], []];
}

function report_csv(array $u, string $kind, array $f): array {
    if (!isset(REPORTS[$kind])) return [null, '未知的報表。'];
    [$head, $rows] = report_rows($u, $kind, $f);
    $out = "\xEF\xBB\xBF";
    foreach (array_merge([$head], $rows) as $r) $out .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $r)) . "\r\n";
    audit('export', 'report', $kind, ['filters' => $f, 'rows' => count($rows), 'full_idno' => in_array($u['role'], PII_FULL_ROLES, true) && in_array($kind, ['apply', 'household'], true)]);
    return [$out, null];
}
