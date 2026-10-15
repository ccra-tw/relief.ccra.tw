<?php
// 區辦與區域主檔（#3）。新增、修改都寫 audit_log。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(['admin']);
$db = rdb();
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $a = $_POST['act'] ?? '';
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $active = !empty($_POST['active']);
    if (!preg_match('/^[A-Z0-9]{1,4}$/', $code) || $name === '') {
        $msg = '代碼限 1～4 個英數字，名稱必填。';
    } elseif ($a === 'region_add') {
        $s = $db->prepare('INSERT INTO region (code, name) VALUES (?,?) ON CONFLICT (code) DO NOTHING RETURNING id');
        $s->execute([$code, $name]);
        $id = $s->fetchColumn();
        if ($id) { audit('create', 'region', $id, ['code' => $code, 'name' => $name]); $msg = "已新增區辦 $code"; }
        else $msg = "區辦碼 $code 已存在。";
    } elseif ($a === 'region_edit') {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE region SET code=?, name=?, active=? WHERE id=?')->execute([$code, $name, $active ? 't' : 'f', $id]);
        audit('update', 'region', $id, ['code' => $code, 'name' => $name, 'active' => $active]);
        $msg = "已更新區辦 $code";
    } elseif ($a === 'area_add') {
        $rid = (int)$_POST['region_id'];
        $s = $db->prepare('INSERT INTO area (region_id, code, name) VALUES (?,?,?) ON CONFLICT (region_id, code) DO NOTHING RETURNING id');
        $s->execute([$rid, $code, $name]);
        $id = $s->fetchColumn();
        if ($id) { audit('create', 'area', $id, ['region_id' => $rid, 'code' => $code, 'name' => $name]); $msg = "已新增區域 $code"; }
        else $msg = "這個區辦已有區域碼 $code。";
    } elseif ($a === 'area_edit') {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE area SET code=?, name=?, active=? WHERE id=?')->execute([$code, $name, $active ? 't' : 'f', $id]);
        audit('update', 'area', $id, ['code' => $code, 'name' => $name, 'active' => $active]);
        $msg = "已更新區域 $code";
    }
}
$regions = $db->query('SELECT * FROM region ORDER BY code')->fetchAll();
$areas = $db->query('SELECT * FROM area ORDER BY region_id, code')->fetchAll();
$tok = h(csrf_token());
page_head('區辦與區域', $u);
echo '<h1>區辦與區域</h1><p class="note">區辦碼與區域碼會用在案號 ZZ-X-YYYY-NNN（ZZ＝區辦碼、X＝區域碼）。</p>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
foreach ($regions as $r) {
    echo '<section class="card" style="max-width:none;margin:12px 0">';
    echo "<form method=post class=row><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=region_edit><input type=hidden name=id value=\"{$r['id']}\">";
    echo '<label>區辦碼<input name=code value="' . h($r['code']) . '" size=4></label><label>名稱<input name=name value="' . h($r['name']) . '"></label>';
    echo '<label><span>啟用</span><input type=checkbox name=active value=1' . ($r['active'] ? ' checked' : '') . '></label><button>儲存</button></form>';
    echo '<table><tr><th>區域碼</th><th>名稱</th><th>啟用</th><th></th></tr>';
    foreach ($areas as $x) if ($x['region_id'] == $r['id']) {
        echo "<tr><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=area_edit><input type=hidden name=id value=\"{$x['id']}\">";
        echo '<td><input name=code value="' . h($x['code']) . '" size=4></td><td><input name=name value="' . h($x['name']) . '"></td>';
        echo '<td><input type=checkbox name=active value=1' . ($x['active'] ? ' checked' : '') . '></td><td><button>儲存</button></td></form></tr>';
    }
    echo "<tr><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=area_add><input type=hidden name=region_id value=\"{$r['id']}\">";
    echo '<td><input name=code size=4 placeholder="碼"></td><td><input name=name placeholder="新區域名稱"></td><td></td><td><button>新增區域</button></td></form></tr></table></section>';
}
echo "<h2>新增區辦</h2><form method=post class=card><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=region_add>";
echo '<label>區辦碼<input name=code size=4 required></label><label>名稱<input name=name required></label><button>新增</button></form>';
page_foot();
