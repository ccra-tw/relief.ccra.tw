<?php
// 急難救助申請（#5）：身分證號分流、建立／儲存草稿、附件、依角色遮罩與可見範圍。
require_once __DIR__ . '/form_spec.php';
require_once __DIR__ . '/pii.php';

const UPLOAD_DIR_DEFAULT = '/var/lib/relief/uploads';
const APPLY_ROLES = ['church_staff', 'church_pastor'];    // 由教會提出

function upload_dir(): string { return getenv('RELIEF_UPLOAD_DIR') && PHP_SAPI === 'cli' ? getenv('RELIEF_UPLOAD_DIR') : UPLOAD_DIR_DEFAULT; }

function person_find(string $idno): ?array {
    $s = rdb()->prepare('SELECT * FROM person WHERE idno_hmac = ?'); $s->execute([pii_hash($idno)]);
    return $s->fetch() ?: null;
}

function person_get_or_create(string $idno): int {
    if ($p = person_find($idno)) return (int)$p['id'];
    $n = idno_norm($idno);
    $s = rdb()->prepare('INSERT INTO person (idno_hmac, idno_enc, idno_head, idno_last4) VALUES (?,?,?,?) ON CONFLICT (idno_hmac) DO UPDATE SET idno_hmac = EXCLUDED.idno_hmac RETURNING id');
    $s->execute([pii_hash($n), pii_encrypt($n), $n[0], substr($n, -4)]);
    return (int)$s->fetchColumn();
}

// 輸入身分證號後的分流：已有案號且開案未滿一年 → 異動（#7）；否則新申請
function intake_route(string $idno): array {
    if (!idno_valid($idno)) return ['error' => '身分證／居留證號碼格式或檢查碼不正確。'];
    $p = person_find($idno);
    if ($p) {
        $s = rdb()->prepare("SELECT id, case_no, opened_at FROM application WHERE person_id = ? AND case_no IS NOT NULL AND opened_at IS NOT NULL
                             AND opened_at > (current_date - interval '1 year') AND kind = 'new' ORDER BY opened_at DESC LIMIT 1");
        $s->execute([$p['id']]);
        if ($a = $s->fetch()) return ['route' => 'change', 'parent_id' => (int)$a['id'], 'case_no' => $a['case_no']];
    }
    return ['route' => 'new'];
}

// 同住家屬：每人身分證號加密，其他欄位照填
function household_clean(array $rows): array {
    $out = []; $err = [];
    foreach ($rows as $i => $r) {
        $r = array_map(fn($v) => is_string($v) ? trim($v) : $v, (array)$r);
        if (($r['name'] ?? '') === '' && ($r['idno'] ?? '') === '') continue;   // 空白列略過
        $row = [];
        foreach (HOUSEHOLD_FIELDS as $k => $label) if ($k !== 'idno') $row[$k] = $k === 'micro_ins' ? !empty($r[$k]) : (string)($r[$k] ?? '');
        if ($row['name'] === '') $err["household_$i"] = '同住家屬第 ' . ($i + 1) . ' 列：姓名必填';
        $id = (string)($r['idno'] ?? '');
        if ($id !== '') {
            if (!idno_valid($id)) $err["household_{$i}_idno"] = '同住家屬第 ' . ($i + 1) . ' 列：身分證號不正確';
            else { $n = idno_norm($id); $row['idno_enc'] = pii_encrypt($n); $row['idno_head'] = $n[0]; $row['idno_last4'] = substr($n, -4); }
        }
        $out[] = $row;
    }
    return [$out, $err];
}

// 建立或更新草稿。回傳 [application id 或 null, 錯誤陣列]
function app_save(array $u, array $in, ?int $id = null): array {
    if (!in_array($u['role'], APPLY_ROLES, true) || !$u['church_oid']) return [null, ['_' => '只有教會帳號可以填寫申請。']];
    if (!church_can_apply($u['church_oid'])) return [null, ['_' => '教會尚未完成名錄綁定，暫時不能提出申請。']];
    [$data, $err] = form_validate($in);
    [$hh, $herr] = household_clean((array)($in['household'] ?? []));
    $err += $herr;
    $phone = preg_replace('/\s+/', '', (string)($in['applicant_phone'] ?? ''));
    if ($phone === '') $err['applicant_phone'] = '申請人連絡電話必填';
    $db = rdb();
    if ($id === null) {
        $route = intake_route((string)($in['idno'] ?? ''));
        if (isset($route['error'])) $err['idno'] = $route['error'];
        elseif ($route['route'] === 'change') $err['idno'] = "此個案已有案號 {$route['case_no']} 且開案未滿一年，請改走異動申請。";
        if ($err) return [null, $err];
        $pid = person_get_or_create((string)$in['idno']);
        $amount = $data['amount_requested']; unset($data['amount_requested']);
        $name = $data['name']; unset($data['name']);
        $s = $db->prepare('INSERT INTO application (person_id, church_oid, region_id, name, data, household, amount_requested, applicant_id, applicant_phone) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
        $s->execute([$pid, $u['church_oid'], church_region($u['church_oid']), $name, json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($hh, JSON_UNESCAPED_UNICODE), $amount, $u['id'], $phone]);
        $id = (int)$s->fetchColumn();
        audit('create', 'application', $id, ['kind' => 'new']);
        return [$id, []];
    }
    $a = app_load($u, $id);
    if (!$a) return [null, ['_' => '找不到這份申請。']];
    if (!in_array($a['status'], ['draft', 'returned'], true)) return [null, ['_' => '已送審的申請不能在這裡修改。']];
    if ($err) return [$id, $err];
    // 修改時身分證欄留空＝沿用原值（畫面上只顯示遮罩，不會把舊號碼送回瀏覽器）；以同一列、同姓名對應
    foreach ($hh as $i => &$row) {
        $old = $a['household'][$i] ?? null;
        if (!isset($row['idno_enc']) && $old && isset($old['idno_enc']) && ($old['name'] ?? '') === $row['name'])
            foreach (['idno_enc', 'idno_head', 'idno_last4'] as $kk) $row[$kk] = $old[$kk];
    }
    unset($row);
    $amount = $data['amount_requested']; unset($data['amount_requested']);
    $name = $data['name']; unset($data['name']);
    $db->prepare('UPDATE application SET name=?, data=?, household=?, amount_requested=?, applicant_phone=?, updated_at=now() WHERE id=?')
       ->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($hh, JSON_UNESCAPED_UNICODE), $amount, $phone, $id]);
    audit('update', 'application', $id);
    return [$id, []];
}

// 依角色與範圍載入一份申請；看不到就回 null（不洩漏存在與否）
function app_load(array $u, int $id): ?array {
    [$w, $p] = scope_sql($u, 'a.region_id', 'a.church_oid');
    $s = rdb()->prepare("SELECT a.*, p.idno_head, p.idno_last4, p.idno_enc FROM application a JOIN person p ON p.id = a.person_id WHERE a.id = ? AND ($w OR a.id IN (SELECT application_id FROM assignment WHERE user_id = ?))");
    $s->execute(array_merge([$id], $p, [$u['id']]));
    $a = $s->fetch();
    if (!$a) return null;
    $a['data'] = json_decode($a['data'], true);
    $a['household'] = json_decode($a['household'], true);
    if (in_array($u['role'], APPLY_ROLES, true)) unset($a['status']);      // 需求 0002：教會端看不到審核狀態
    return $a;
}

// 身分證號顯示：預設遮罩；審核角色要求看完整號碼時解密並寫稽核
function app_idno_display(array $u, array $a, bool $full = false): string {
    if ($full && in_array($u['role'], PII_FULL_ROLES, true)) {
        audit('view', 'idno', $a['id'], ['field' => 'person.idno']);
        return (string)pii_decrypt($a['idno_enc']);
    }
    return pii_mask($a['idno_head'], $a['idno_last4']);
}

// 附件上傳：只收 PDF／JPG／PNG／DOCX、10MB 以內；以實際內容判斷格式，不信副檔名
function attach_add(array $u, int $appId, string $kind, string $tmp, string $origName): ?string {
    $a = app_load($u, $appId);
    if (!$a) return '找不到這份申請。';
    if (!empty($a['closed_at'])) return '已結案，不能再上傳附件。';
    if (!isset(ATTACH_KINDS[$kind])) return '附件類別不正確。';
    if (!is_file($tmp)) return '沒有收到檔案。';
    $size = filesize($tmp);
    if ($size <= 0 || $size > ATTACH_MAX) return '檔案大小需在 10MB 以內。';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(ATTACH_MIME[$mime])) return '只接受 PDF、JPG、PNG 或 Word（docx）檔。';
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return '主機存檔目錄無法建立。';
    $stored = bin2hex(random_bytes(16)) . '.' . ATTACH_MIME[$mime];
    if (!(PHP_SAPI === 'cli' ? copy($tmp, "$dir/$stored") : move_uploaded_file($tmp, "$dir/$stored"))) return '存檔失敗。';
    chmod("$dir/$stored", 0640);
    $s = rdb()->prepare('INSERT INTO attachment (application_id, kind, orig_name, stored_name, mime, size, sha256, uploaded_by) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
    $s->execute([$appId, $kind, mb_substr(basename($origName), 0, 200), $stored, $mime, $size, hash_file('sha256', "$dir/$stored"), $u['id']]);
    audit('create', 'attachment', $s->fetchColumn(), ['application_id' => $appId, 'kind' => $kind, 'mime' => $mime, 'size' => $size]);
    return null;
}
