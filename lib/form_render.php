<?php
// 依 FORM_SPEC 產生表單 HTML（#5）。條件欄位（其他、次選項）用 data-show 標記，由 /assets/form.js 顯示／隱藏；
// 不開 JS 時全部顯示，驗證仍以伺服器端為準。
function fr_val(array $d, string $k) { return $d[$k] ?? null; }

function fr_field(array $f, $v, array $err): string {
    $k = $f['key']; $req = $f['req'] ? ' <span class=req>*</span>' : '';
    $h = '<div class="field" id="f-' . h($k) . '"><div class="lbl">' . h($f['label']) . $req . '</div>';
    if (!empty($f['hint'])) $h .= '<div class="note">' . h($f['hint']) . '</div>';
    switch ($f['type']) {
        case 'text': case 'tel': case 'date':
            $h .= '<input name="' . h($k) . '" type="' . ($f['type'] === 'tel' ? 'tel' : $f['type']) . '" value="' . h($v) . '">'; break;
        case 'textarea': $h .= '<textarea name="' . h($k) . '" rows=4>' . h($v) . '</textarea>'; break;
        case 'money': $h .= '<input name="' . h($k) . '" inputmode=numeric value="' . h($v) . '">'; break;
        case 'check': $h .= '<label class=inline><input type=checkbox name="' . h($k) . '" value=1' . ($v ? ' checked' : '') . '> 是</label>'; break;
        case 'group':
            $h .= '<div class=grid>';
            foreach ($f['opts'] as $i => $o) $h .= '<label>' . h($o) . '<input name="' . h($k) . "[$i]\" inputmode=numeric value=\"" . h($v[$o] ?? '') . '"></label>';
            $h .= '</div>'; break;
        case 'single': case 'multi':
            $sel = $v['sel'] ?? [];
            $type = $f['type'] === 'single' ? 'radio' : 'checkbox';
            $name = $f['type'] === 'single' ? $k : $k . '[]';
            $h .= '<div class=opts>';
            foreach ($f['opts'] as $i => $o) {
                $h .= '<label class=inline><input type=' . $type . ' name="' . h($name) . '" value="' . h($o) . '"' . (in_array($o, $sel, true) ? ' checked' : '') . '> ' . h($o) . '</label>';
                if (!empty($f['amount_each']) && $o !== ($f['none'] ?? null)) $h .= '<input class=amt name="' . h($k) . "_amt[$i]\" inputmode=numeric placeholder=金額 value=\"" . h($v['amount'][$o] ?? '') . '" data-show="' . h($k) . '=' . h($o) . '">';
            }
            $h .= '</div>';
            foreach ($f['sub'] ?? [] as $opt => $s) {
                $sk = $k . '__' . array_search($opt, $f['opts'], true);
                $cur = $v['sub'][$opt] ?? [];
                $h .= '<div class=sub data-show="' . h($k) . '=' . h($opt) . '"><span class=note>' . h($opt) . '：</span>';
                if ($s['type'] === 'amounts') foreach ($s['opts'] as $i => $o) $h .= '<label>' . h($o) . '<input name="' . h($sk) . "[$i]\" inputmode=numeric placeholder=金額 value=\"" . h($cur[$o] ?? '') . '"></label>';
                else foreach ($s['opts'] as $o) $h .= '<label class=inline><input type=' . ($s['type'] === 'single' ? 'radio' : 'checkbox') . ' name="' . h($s['type'] === 'single' ? $sk : $sk . '[]') . '" value="' . h($o) . '"' . (in_array($o, (array)$cur, true) ? ' checked' : '') . '> ' . h($o) . '</label>';
                if (isset($s['other'])) $h .= '<input name="' . h($sk) . '_other" placeholder="請說明" value="' . h($v['sub_other'][$opt] ?? '') . '">';
                $h .= '</div>';
            }
            if (isset($f['other'])) $h .= '<input class=other name="' . h($k) . '_other" placeholder="請說明「其他」" value="' . h($v['other'] ?? '') . '" data-show="' . h($k) . '=' . h($f['other']) . '">';
            break;
    }
    foreach ($err as $ek => $em) if ($ek === $k || str_starts_with($ek, $k . '_')) $h .= '<div class=err>' . h($em) . '</div>';
    return $h . '</div>';
}

function fr_form(array $data, array $err): string {
    $h = '';
    foreach (FORM_SPEC as [$title, $fields]) {
        $h .= '<fieldset><legend>' . h($title) . '</legend>';
        foreach ($fields as $f) $h .= fr_field($f, $data[$f['key']] ?? null, $err);
        $h .= '</fieldset>';
    }
    return $h;
}

function fr_household(array $rows, array $err): string {
    $rows = $rows ?: [[]];
    $rows[] = [];  // 永遠多一列空白可填
    $h = '<fieldset><legend>同住家屬（可多筆，空白列不存）</legend><table class=hh><tr>';
    foreach (HOUSEHOLD_FIELDS as $k => $l) $h .= '<th>' . h($l) . '</th>';
    $h .= '</tr>';
    foreach ($rows as $i => $r) {
        $h .= '<tr>';
        foreach (HOUSEHOLD_FIELDS as $k => $l) {
            if ($k === 'micro_ins') $h .= "<td><input type=checkbox name=\"household[$i][$k]\" value=1" . (!empty($r[$k]) ? ' checked' : '') . '></td>';
            elseif ($k === 'idno') $h .= "<td><input name=\"household[$i][idno]\" placeholder=\"" . (isset($r['idno_last4']) ? h(pii_mask($r['idno_head'], $r['idno_last4'])) . '（不改請留空）' : '') . '"></td>';
            else $h .= "<td><input name=\"household[$i][$k]\"" . ($k === 'birthday' ? ' type=date' : '') . ' value="' . h($r[$k] ?? '') . '"></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</table>';
    foreach ($err as $ek => $em) if (str_starts_with($ek, 'household')) $h .= '<div class=err>' . h($em) . '</div>';
    return $h . '</fieldset>';
}
