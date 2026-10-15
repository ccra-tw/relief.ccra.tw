<?php
// 急難救助申請表欄位（#5）。依服管組「急難救助金系統表單 2-2 主表單」整理；欄位定義集中在這裡，畫面、驗證、匯出都讀它。
// type：text／textarea／date／tel／single（單選）／multi（複選）／money（金額）／check（勾選）／group（一組金額小欄）
// other：選到這個值才出現、且必填的補充欄（「其他（需要額外填寫的開放欄位）」）
// sub：某個選項的次選項（例：基督教 → 本會／他會；銀行債 → 卡債／信貸… 每項可填金額）

const FORM_SPEC = [
    ['個案基本資料', [
        ['key' => 'category', 'label' => '申請類別', 'type' => 'single', 'req' => true, 'opts' => ['一般急難', '重大災難'],
         'hint' => '重大災難：突然發生不可抗力之災難，造成大量傷亡、基本民生需求無法於短期（一週至一個月）內回復。'],
        ['key' => 'name', 'label' => '個案姓名', 'type' => 'text', 'req' => true],
        ['key' => 'nationality', 'label' => '國籍', 'type' => 'single', 'req' => true, 'opts' => ['中華民國', '外籍人士']],
        ['key' => 'gender', 'label' => '性別', 'type' => 'single', 'req' => true, 'opts' => ['男', '女']],
        ['key' => 'birthday', 'label' => '生日', 'type' => 'date', 'req' => true],
        ['key' => 'tel', 'label' => '連絡電話', 'type' => 'tel', 'req' => false],
        ['key' => 'mobile', 'label' => '聯絡手機', 'type' => 'tel', 'req' => true],
        ['key' => 'marital', 'label' => '婚姻狀況', 'type' => 'single', 'req' => true, 'opts' => ['未婚', '同居', '已婚', '分居', '離婚', '喪偶']],
        ['key' => 'micro_ins', 'label' => '投保微型保險', 'type' => 'check', 'req' => false],
        ['key' => 'address', 'label' => '通訊地址', 'type' => 'text', 'req' => true],
        ['key' => 'housing', 'label' => '居住性質', 'type' => 'single', 'req' => true, 'opts' => ['自宅', '租屋', '其他'], 'other' => '其他'],
        ['key' => 'source', 'label' => '個案來源', 'type' => 'single', 'req' => true, 'opts' => ['教會發掘', '主動求助', '救協轉介', '學校轉介', '村里長轉介', '其他'], 'other' => '其他'],
        ['key' => 'religion', 'label' => '宗教信仰', 'type' => 'single', 'req' => true, 'opts' => ['無宗教信仰', '民間宗教', '佛教', '道教', '一貫道', '基督教', '其他'], 'other' => '其他',
         'sub' => ['基督教' => ['type' => 'single', 'opts' => ['本會會友', '他會會友']]]],
        ['key' => 'support', 'label' => '支持系統（親朋好友是否支持支援）', 'type' => 'single', 'req' => true, 'opts' => ['是', '否'],
         'sub' => ['是' => ['type' => 'multi', 'opts' => ['金援', '陪伴', '提供住宿', '其他'], 'other' => '其他']]],
    ]],
    ['案家經濟狀況', [
        ['key' => 'debt', 'label' => '債務情況', 'type' => 'multi', 'req' => true, 'none' => '無',
         'opts' => ['無', '積欠健保國民年金', '銀行債', '親友債（向親朋好友借款）', '醫療債（積欠醫療費用）', '第三方借貸', '其他'], 'other' => '其他',
         'sub' => ['銀行債' => ['type' => 'amounts', 'opts' => ['卡債', '信貸', '車貸', '房貸']],
                   '第三方借貸' => ['type' => 'amounts', 'opts' => ['地下錢莊', '私人會社', '互助會']]]],
        ['key' => 'employment', 'label' => '就業情況', 'type' => 'single', 'req' => true, 'opts' => ['無', '全職', '兼職', '臨時工'],
         'sub' => ['無' => ['type' => 'single', 'opts' => ['殘傷疾病', '無工作意願', '缺乏工作機會', '無工作技能', '因家庭需要無法外出工作', '其他'], 'other' => '其他']]],
        ['key' => 'subsidy', 'label' => '補助情況', 'type' => 'multi', 'req' => true, 'none' => '無', 'amount_each' => true,
         'opts' => ['無', '低收入戶補助（含低收＋身障）', '中低收入戶（含中低收＋身障）', '特殊境遇家庭', '65歲以上長者（原住民55歲以上）', '身心障礙者（未具低收、中低收資格）', '保險給付', '其他'], 'other' => '其他',
         'sub' => ['65歲以上長者（原住民55歲以上）' => ['type' => 'multi', 'opts' => ['勞保退休金', '軍公教退休金', '農漁保退休金', '國民年金老人給付']]]],
    ]],
    ['急難事件', [
        ['key' => 'emergency', 'label' => '急難類別', 'type' => 'multi', 'req' => true, 'opts' => ['疾病', '意外', '死亡', '失業', '就業不穩定', '入獄', '家暴', '其他'], 'other' => '其他',
         'sub' => ['失業' => ['type' => 'single', 'opts' => ['自願離職', '非自願離職']]]],
        ['key' => 'before', 'label' => '原本情況', 'type' => 'textarea', 'req' => true],
        ['key' => 'event', 'label' => '急難事件', 'type' => 'textarea', 'req' => true],
        ['key' => 'plan', 'label' => '案家現況與未來計畫', 'type' => 'textarea', 'req' => true],
    ]],
    ['案家經濟情況（元／月）', [
        ['key' => 'income_before', 'label' => '原本收入', 'type' => 'group', 'req' => true, 'opts' => ['工作收入', '其他收入']],
        ['key' => 'income_now', 'label' => '現在收入', 'type' => 'group', 'req' => true, 'opts' => ['工作收入', '其他收入']],
        ['key' => 'spend_before', 'label' => '急難前支出', 'type' => 'group', 'req' => true, 'opts' => ['房租', '貸款', '水電瓦斯', '交通', '伙食', '勞健保', '醫療費', '學雜費', '保險', '其他']],
        ['key' => 'spend_after', 'label' => '急難後支出', 'type' => 'group', 'req' => true, 'opts' => ['房租', '貸款', '水電瓦斯', '交通', '伙食', '勞健保', '醫療費', '學雜費', '保險', '其他']],
        ['key' => 'econ_note', 'label' => '經濟情況備註', 'type' => 'textarea', 'req' => false],
    ]],
    ['教會與補助', [
        ['key' => 'church_service', 'label' => '教會可提供的服務', 'type' => 'multi', 'req' => true, 'other' => '其他',
         'opts' => ['傾聽關懷陪伴', '心理協談', '悲傷輔導', '禱告扶持', '使用傳福音工具', '教會愛心奉獻', '協助就醫交通', '陪同就醫', '兒童課後照顧（含六、日）', '兒童托育',
                    '家事服務', '協助就業', '協助緊急安置', '協助搬家', '發放物資', '申請本會食物銀行', '申請其他社福單位金援', '申請其他社福單位食物銀行', '其他'],
         'sub' => ['使用傳福音工具' => ['type' => 'single', 'opts' => ['福氣臨門', '其他'], 'other' => '其他']]],
        ['key' => 'usage', 'label' => '補助使用方式', 'type' => 'multi', 'req' => true, 'other' => '其他',
         'opts' => ['就學－學雜費', '就業－失業補助', '就醫－醫療費', '安養照顧－養護費', '喪葬補助', '基本生活費', '重大災難補助', '其他']],
        ['key' => 'amount_requested', 'label' => '教會申請金額（元）', 'type' => 'money', 'req' => true],
    ]],
];

// 同住家屬（可多筆）。身分證字號另外加密，不放進一般欄位。
const HOUSEHOLD_FIELDS = [
    'name' => '姓名', 'idno' => '身分證字號', 'birthday' => '生日', 'gender' => '性別', 'relation' => '關係',
    'school_job' => '職業／學校', 'grade' => '年級', 'income_before' => '急難前月收入', 'income_after' => '急難後月收入', 'micro_ins' => '投保微型保險',
];

const ATTACH_KINDS = ['consent' => '同意書', 'proof' => '證明文件'];
const ATTACH_MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                     'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
const ATTACH_MAX = 10 * 1024 * 1024;

function form_fields(array $spec = FORM_SPEC): array {
    $out = [];
    foreach ($spec as [, $fields]) foreach ($fields as $f) $out[$f['key']] = $f;
    return $out;
}

// 驗證並正規化表單輸入。回傳 [資料, 錯誤陣列]
function form_validate(array $in, array $spec = FORM_SPEC): array {
    $data = []; $err = [];
    foreach (form_fields($spec) as $k => $f) {
        $v = $in[$k] ?? null;
        switch ($f['type']) {
            case 'text': case 'textarea': case 'tel':
                $v = trim((string)$v);
                if ($f['type'] === 'tel') $v = preg_replace('/\s+/', '', $v);
                if ($f['req'] && $v === '') $err[$k] = "{$f['label']}必填";
                $data[$k] = $v; break;
            case 'datetime':
                $v = trim((string)$v);
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) $err[$k] = "{$f['label']}格式不正確";
                elseif ($f['req'] && $v === '') $err[$k] = "{$f['label']}必填";
                $data[$k] = $v; break;
            case 'date':
                $v = trim((string)$v);
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $err[$k] = "{$f['label']}格式不正確";
                elseif ($f['req'] && $v === '') $err[$k] = "{$f['label']}必填";
                $data[$k] = $v; break;
            case 'money':
                $v = preg_replace('/[\s,]/', '', (string)$v);
                if ($v === '' && $f['req']) $err[$k] = "{$f['label']}必填";
                elseif ($v !== '' && !ctype_digit($v)) $err[$k] = "{$f['label']}只能填數字";
                $data[$k] = $v === '' ? null : (int)$v; break;
            case 'check':
                $data[$k] = !empty($v); break;
            case 'group':
                $g = [];
                foreach ($f['opts'] as $i => $o) {
                    $x = preg_replace('/[\s,]/', '', (string)($v[$i] ?? ''));
                    if ($x !== '' && !ctype_digit($x)) $err[$k] = "{$f['label']}：{$o}只能填數字";
                    $g[$o] = $x === '' ? 0 : (int)$x;
                }
                $data[$k] = $g; break;
            case 'single': case 'multi':
                $sel = $f['type'] === 'single' ? (($v === null || $v === '') ? [] : [(string)$v]) : array_values(array_map('strval', (array)($v ?? [])));
                $sel = array_values(array_intersect($sel, $f['opts']));            // 不在選項裡的值丟掉
                if ($f['req'] && !$sel) { $err[$k] = "{$f['label']}必選"; $data[$k] = []; break; }
                if (isset($f['none']) && in_array($f['none'], $sel, true) && count($sel) > 1) $err[$k] = "{$f['label']}：選了「{$f['none']}」就不能再選其他項";
                $item = ['sel' => $sel];
                if (isset($f['other']) && in_array($f['other'], $sel, true)) {
                    $item['other'] = trim((string)($in[$k . '_other'] ?? ''));
                    if ($item['other'] === '') $err[$k . '_other'] = "{$f['label']}選了「其他」，請填寫說明";
                }
                foreach ($f['sub'] ?? [] as $opt => $s) {
                    if (!in_array($opt, $sel, true)) continue;
                    $sk = $k . '__' . array_search($opt, $f['opts'], true);
                    if ($s['type'] === 'amounts') {
                        $am = [];
                        foreach ($s['opts'] as $i => $o) { $x = preg_replace('/[\s,]/', '', (string)(($in[$sk] ?? [])[$i] ?? '')); if ($x !== '' && !ctype_digit($x)) $err[$sk] = "{$opt}：{$o}金額只能填數字"; if ($x !== '') $am[$o] = (int)$x; }
                        $item['sub'][$opt] = $am;
                    } else {
                        $ss = $s['type'] === 'single' ? array_filter([(string)($in[$sk] ?? '')]) : array_map('strval', (array)($in[$sk] ?? []));
                        $ss = array_values(array_intersect($ss, $s['opts']));
                        if (!$ss) $err[$sk] = "{$f['label']}：請選擇「{$opt}」的細項";
                        $item['sub'][$opt] = $ss;
                        if (isset($s['other']) && in_array($s['other'], $ss, true)) {
                            $o2 = trim((string)($in[$sk . '_other'] ?? ''));
                            if ($o2 === '') $err[$sk . '_other'] = "{$opt}選了「其他」，請填寫說明";
                            $item['sub_other'][$opt] = $o2;
                        }
                    }
                }
                if (!empty($f['amount_each'])) foreach ($sel as $i => $o) { $x = preg_replace('/[\s,]/', '', (string)(($in[$k . '_amt'] ?? [])[array_search($o, $f['opts'], true)] ?? '')); if ($x !== '' && !ctype_digit($x)) $err[$k . '_amt'] = "{$f['label']}金額只能填數字"; $item['amount'][$o] = $x === '' ? null : (int)$x; }
                $data[$k] = $item; break;
        }
    }
    return [$data, $err];
}

// 帳號欄位：自動去除空白、只允許數字（需求 0004）
function norm_account(string $s): ?string {
    $s = preg_replace('/\s+/', '', $s);
    return ($s === '' || ctype_digit($s)) ? $s : null;
}
