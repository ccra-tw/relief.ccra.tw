<?php
// 角色與選單（#2）。選單只是「看得到哪些入口」；每一頁仍要自己呼叫 require_role() 檢查，不能只靠藏選單。
const ROLE_NAMES = [
    'church_staff'  => '教會同工',
    'church_pastor' => '教會牧者',
    'region_worker' => '區辦社工',
    'region_head'   => '區辦主任',
    'hq_worker'     => '服管組社工',
    'hq_head'       => '服管組主任',
    'accountant'    => '會計',
    'cashier'       => '出納',
    'assistant'     => '社工助理',
    'admin'         => '管理者',
];

// 選單：key => [標題, 路徑, 可見角色]
const MENU = [
    'cases'    => ['個案與申請', '/cases/',    ['church_staff','church_pastor','region_worker','region_head','hq_worker','hq_head','assistant','admin']],
    'review'   => ['待我審核',   '/review/',   ['church_pastor','region_worker','region_head','hq_worker','hq_head']],
    'churches' => ['教會與帳號', '/churches/', ['region_worker','region_head','hq_worker','hq_head','admin']],
    'batches'  => ['撥款批次',   '/batches/',  ['hq_worker','hq_head','region_worker','region_head','accountant','cashier','admin']],
    'reports'  => ['報表與匯出', '/reports/',  ['hq_worker','hq_head','admin']],
    'admin'    => ['系統管理',   '/admin/',    ['admin']],
];

function menu_for(string $role): array {
    return array_filter(MENU, fn($m) => in_array($role, $m[2], true));
}

function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        audit('deny', 'page', $_SERVER['SCRIPT_NAME'] ?? '', ['role' => $u['role']]);
        http_response_code(403);
        exit('您的角色沒有這個功能的權限。');
    }
    return $u;
}
