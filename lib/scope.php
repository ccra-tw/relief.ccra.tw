<?php
// 資料可見範圍（#3）：限縮在查詢層強制，不是只藏畫面。
// 回傳 [SQL 片段, 參數]，接在 WHERE 後面用：... WHERE <其他條件> AND <scope>
//   管理者、服管組、會計、出納：全部
//   區辦社工／主任、社工助理：只看自己區辦（$col 指向資料列的 region_id 欄位）
//   教會同工／牧者：只看自己教會（$churchCol 指向資料列的 church_oid 欄位；沒給就什麼都看不到）
// 他區審核時，區辦社工看得到「派給自己」的案件——那是 #6 用 assignment 另外放行，不在這裡放寬。
const SCOPE_ALL = ['admin', 'hq_worker', 'hq_head', 'accountant', 'cashier'];
const SCOPE_REGION = ['region_worker', 'region_head', 'assistant'];
const SCOPE_CHURCH = ['church_staff', 'church_pastor'];

function scope_sql(array $u, string $col = 'region_id', ?string $churchCol = null): array {
    if (in_array($u['role'], SCOPE_ALL, true)) return ['TRUE', []];
    if (in_array($u['role'], SCOPE_REGION, true)) {
        if (!$u['region_id']) return ['FALSE', []];          // 沒設所屬區辦：一筆都不給，不要退回全部
        return ["$col = ?", [(int)$u['region_id']]];
    }
    if (in_array($u['role'], SCOPE_CHURCH, true) && $churchCol && $u['church_oid'])
        return ["$churchCol = ?", [$u['church_oid']]];
    return ['FALSE', []];                                    // 未知角色一律看不到
}
