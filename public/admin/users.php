<?php
// 使用者、所屬區辦與會簽設定（#3）。服管組 2026-10-15：會簽者「指定且固定」，資格限服管組主任、區辦主任或區辦社工。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(['admin']);
$db = rdb();
const COSIGNER_ROLES = ['hq_head', 'region_head', 'region_worker'];
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $a = $_POST['act'] ?? '';
    $role = $_POST['role'] ?? '';
    $rid = ($_POST['region_id'] ?? '') === '' ? null : (int)$_POST['region_id'];
    $needs = !empty($_POST['needs_cosign']);
    $cos = ($_POST['cosigner_id'] ?? '') === '' ? null : (int)$_POST['cosigner_id'];
    $name = trim($_POST['display_name'] ?? '');
    if (!isset(ROLE_NAMES[$role]) || $name === '') $msg = '角色與顯示名稱必填。';
    elseif ($needs && !$cos) $msg = '勾了「須會簽」就要指定會簽者。';
    elseif ($cos) {
        $c = $db->prepare('SELECT role FROM app_user WHERE id = ? AND active'); $c->execute([$cos]);
        if (!in_array($c->fetchColumn(), COSIGNER_ROLES, true)) $msg = '會簽者只能是服管組主任、區辦主任或區辦社工。';
    }
    if (!$msg && $a === 'edit') {
        $id = (int)$_POST['id'];
        if ($cos === $id) $msg = '不能指定自己為會簽者。';
        else {
            $db->prepare('UPDATE app_user SET display_name=?, role=?, region_id=?, needs_cosign=?, cosigner_id=?, active=? WHERE id=?')
               ->execute([$name, $role, $rid, $needs ? 't' : 'f', $needs ? $cos : null, empty($_POST['active']) ? 'f' : 't', $id]);
            audit('update', 'user', $id, ['role' => $role, 'region_id' => $rid, 'needs_cosign' => $needs, 'cosigner_id' => $needs ? $cos : null, 'active' => !empty($_POST['active'])]);
            $msg = "已更新：$name";
        }
    } elseif (!$msg && $a === 'add') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $username = strtolower(trim($_POST['username'] ?? ''));
        $pw = null;
        if ($email === '' && $username === '') $msg = '內部同工填 email，教會帳號填帳號，擇一。';
        else {
            if ($username !== '') $pw = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kz'), '=');   // 12 碼初始密碼，只顯示這一次
            try {
                $s = $db->prepare('INSERT INTO app_user (email, username, pass_hash, display_name, role, region_id, needs_cosign, cosigner_id) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
                $s->execute([$email ?: null, $username ?: null, $pw ? password_hash($pw, PASSWORD_DEFAULT) : null, $name, $role, $rid, $needs ? 't' : 'f', $needs ? $cos : null]);
                $id = $s->fetchColumn();
                audit('create', 'user', $id, ['email' => $email ?: null, 'username' => $username ?: null, 'role' => $role]);
                $msg = "已新增：$name" . ($pw ? "。初始密碼（只顯示這一次）：$pw" : '');
            } catch (PDOException $e) { $msg = '這個 email 或帳號已經存在。'; }
        }
    }
}
$users = $db->query('SELECT u.*, r.code AS rcode FROM app_user u LEFT JOIN region r ON r.id = u.region_id ORDER BY u.role, u.display_name')->fetchAll();
$regions = $db->query('SELECT id, code, name FROM region WHERE active ORDER BY code')->fetchAll();
$cosigners = array_filter($users, fn($x) => $x['active'] && in_array($x['role'], COSIGNER_ROLES, true));
$tok = h(csrf_token());
$sel = function ($name, $opts, $cur, $blank = '—') {
    $o = "<select name=$name><option value=\"\">$blank</option>";
    foreach ($opts as $k => $v) $o .= '<option value="' . h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($v) . '</option>';
    return $o . '</select>';
};
$rOpts = []; foreach ($regions as $r) $rOpts[$r['id']] = $r['code'] . ' ' . $r['name'];
$cOpts = []; foreach ($cosigners as $c) $cOpts[$c['id']] = $c['display_name'] . '（' . ROLE_NAMES[$c['role']] . '）';
page_head('使用者與會簽', $u);
echo '<h1>使用者與會簽</h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
echo '<table><tr><th>名稱</th><th>登入</th><th>角色</th><th>區辦</th><th>須會簽</th><th>會簽者</th><th>啟用</th><th></th></tr>';
foreach ($users as $x) {
    echo "<tr><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=edit><input type=hidden name=id value=\"{$x['id']}\">";
    echo '<td><input name=display_name value="' . h($x['display_name']) . '"></td><td>' . h($x['email'] ?: $x['username']) . '</td>';
    echo '<td>' . $sel('role', ROLE_NAMES, $x['role'], '請選擇') . '</td><td>' . $sel('region_id', $rOpts, $x['region_id']) . '</td>';
    echo '<td><input type=checkbox name=needs_cosign value=1' . ($x['needs_cosign'] ? ' checked' : '') . '></td><td>' . $sel('cosigner_id', $cOpts, $x['cosigner_id']) . '</td>';
    echo '<td><input type=checkbox name=active value=1' . ($x['active'] ? ' checked' : '') . '></td><td><button>儲存</button></td></form></tr>';
}
echo '</table><h2>新增使用者</h2>';
echo "<form method=post class=card><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=add>";
echo '<label>顯示名稱<input name=display_name required></label>';
echo '<label>內部同工 email（協會 M365）<input name=email type=email></label><label>或教會帳號<input name=username></label>';
echo '<label>角色' . $sel('role', ROLE_NAMES, '', '請選擇') . '</label><label>所屬區辦' . $sel('region_id', $rOpts, '') . '</label>';
echo '<label><span>須會簽</span><input type=checkbox name=needs_cosign value=1></label><label>會簽者' . $sel('cosigner_id', $cOpts, '') . '</label>';
echo '<button>新增</button></form>';
page_foot();
