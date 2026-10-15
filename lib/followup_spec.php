<?php
// 異動、服務紀錄、結案表單欄位（#7）。依服管組表單 3-1、4-1、5-1、5-2；與申請表共用同一套驗證與畫面（form_validate／fr_form）。
const SERVICE_ITEMS = ['傾聽關懷陪伴', '心理協談', '悲傷輔導', '禱告扶持', '使用傳福音工具', '教會愛心奉獻', '協助就醫交通', '陪同就醫', '兒童課後照顧（含六、日）', '兒童托育',
                       '家事服務', '協助就業', '協助緊急安置', '協助搬家', '發放物資', '申請本會食物銀行', '申請其他社福單位金援', '申請其他社福單位食物銀行', '其他'];
const FAMILY_ISSUES = ['債務', '醫療費用欠費', '失業', '經濟不穩定', '積欠房租', '就醫交通', '兒童照顧', '老人照顧', '身心障礙照顧', '疾病照顧', '家屬照顧', '自我照顧',
                       '親屬死亡、悲傷議題', '家暴', '夫妻關係', '親子關係', '環境整潔', '其他'];

const CHANGE_SPEC = [
    ['異動申請', [
        ['key' => 'category', 'label' => '救助金類別', 'type' => 'single', 'req' => true, 'opts' => ['一般急難', '重大災難']],
        ['key' => 'change_items', 'label' => '異動項目', 'type' => 'multi', 'req' => true, 'opts' => ['期數', '金額']],
        ['key' => 'change_reason', 'label' => '異動原因', 'type' => 'single', 'req' => true, 'opts' => ['案家情況變動', '案家仍需要金援', '其他'], 'other' => '其他'],
        ['key' => 'change_desc', 'label' => '異動事件描述（個案現況概述暨急難救助金已核發情形）', 'type' => 'textarea', 'req' => true],
        ['key' => 'amount_requested', 'label' => '教會申請金額（元）', 'type' => 'money', 'req' => true],
    ]],
];

// 服務紀錄與結案問卷共用的訪視欄位
const VISIT_FIELDS = [
    ['key' => 'volunteers', 'label' => '訪視志工人數', 'type' => 'money', 'req' => true],
    ['key' => 'mode', 'label' => '服務方式', 'type' => 'single', 'req' => true, 'opts' => ['家訪', '面談', '電話關懷']],
    ['key' => 'served_at', 'label' => '服務時間', 'type' => 'datetime', 'req' => true],
    ['key' => 'issues', 'label' => '案家議題（複選）', 'type' => 'multi', 'req' => true, 'opts' => FAMILY_ISSUES, 'other' => '其他'],
    ['key' => 'content', 'label' => '服務內容（複選）', 'type' => 'multi', 'req' => true, 'opts' => SERVICE_ITEMS, 'other' => '其他',
     'sub' => ['使用傳福音工具' => ['type' => 'single', 'opts' => ['福氣臨門', '其他'], 'other' => '其他']]],
    ['key' => 'imp_emergency', 'label' => '案家急難情形（與上次訪視比較）', 'type' => 'single', 'req' => true, 'opts' => ['未改善', '稍有改善', '明顯改善']],
    ['key' => 'imp_resource', 'label' => '資源連結情況', 'type' => 'single', 'req' => true, 'opts' => ['尚未連結其他資源', '有其他資源介入'],
     'sub' => ['有其他資源介入' => ['type' => 'multi', 'opts' => ['金援', '物資']]]],
    ['key' => 'imp_mood', 'label' => '案主情緒情況', 'type' => 'single', 'req' => true, 'opts' => ['明顯改善', '稍有改善', '維持原狀', '稍微變差', '明顯變差']],
    ['key' => 'faith', 'label' => '信仰狀況（是否為基督徒）', 'type' => 'single', 'req' => true, 'opts' => ['是', '否'],
     'sub' => ['是' => ['type' => 'single', 'opts' => ['沒參加聚會', '重回教會（穩定聚會）', '一個月1-2次', '一週1-2次']],
               '否' => ['type' => 'single', 'opts' => ['對信仰排斥', '願意慕道不願聚會', '開始參加聚會', '決志或受洗']]]],
];

const SERVICE_SPEC = [['服務紀錄', [...VISIT_FIELDS,
    ['key' => 'note', 'label' => '備註', 'type' => 'textarea', 'req' => false],
]]];

// 結案類型：「2-3 選項」（提早結案：案家情況改善／案主死亡）需區辦審核（服管組表單 5-1 備註）
const CLOSE_REVIEW_TYPES = ['案家情況改善，提早結案', '案主死亡，提早結案'];
const CLOSURE_SPEC = [
    ['結案', [
        ['key' => 'close_type', 'label' => '結案類型', 'type' => 'single', 'req' => true, 'other' => '其他',
         'opts' => ['正常結案', '案家情況改善，提早結案', '案主死亡，提早結案', '案主失聯／不在國內，提早結案', '其他']],
        ['key' => 'close_note', 'label' => '備註', 'type' => 'textarea', 'req' => false],
    ]],
    ['結案問卷', [...VISIT_FIELDS,
        ['key' => 'followup', 'label' => '後續服務（後續還會關懷聯繫嗎）', 'type' => 'single', 'req' => true, 'opts' => ['是', '否'],
         'sub' => ['是' => ['type' => 'single', 'opts' => ['案主仍有其他需要，教會可以扶持陪伴', '還未進入教會，但心是柔軟的', '開始進入教會牧養', '其他'], 'other' => '其他'],
                   '否' => ['type' => 'single', 'opts' => ['案主純粹拿資源', '案主態度不佳', '案主情況教會可能無法承接', '其他'], 'other' => '其他']]],
        ['key' => 'note', 'label' => '備註', 'type' => 'textarea', 'req' => false],
    ]],
];
