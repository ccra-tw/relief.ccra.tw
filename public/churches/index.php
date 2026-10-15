<?php
// 教會與帳號（#4）：搜尋名錄綁定教會、不在名錄的新增申請、教會帳號申請與核可。
require __DIR__ . '/../../lib/boot.php';
$u = require_role(CHURCH_ROLES);
$db = rdb();
$msg = ''; $pw = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    switch ($_POST['act'] ?? '') {
        case 'bind':     $msg = church_bind($u, (string)$_POST['oid'], (int)$_POST['area_id']) ?? '已綁定。'; break;
        case 'add':      $msg = church_add_request($u, $_POST) ?? '已送出名錄新增申請；名錄核可前這間教會還不能提出急難申請。'; break;
        case 'add_ok':   $msg = church_add_approved($u, (int)$_POST['id'], trim((string)$_POST['oid'])) ?? '已登記名錄核可並完成綁定。'; break;
        case 'acct':     $msg = account_request_create($u, $_POST) ?? '已送出帳號申請，等區辦主任核可。'; break;
        case 'acct_ok':  [$e, $pw] = account_request_decide($u, (int)$_POST['id'], true); $msg = $e ?? '已核可並開通帳號。'; break;
        case 'acct_no':  [$e] = account_request_decide($u, (int)$_POST['id'], false, (string)($_POST['reason'] ?? '')); $msg = $e ?? '已退回。'; break;
    }
}
[$w, $p] = scope_sql($u, 'a.region_id');
$areas = $db->prepare("SELECT a.id, a.code, a.name, r.code AS rcode FROM area a JOIN region r ON r.id = a.region_id WHERE a.active AND $w ORDER BY r.code, a.code"); $areas->execute($p); $areas = $areas->fetchAll();
$churches = $db->prepare("SELECT c.*, a.code AS acode, r.code AS rcode FROM church c JOIN area a ON a.id = c.area_id JOIN region r ON r.id = a.region_id WHERE $w ORDER BY c.name"); $churches->execute($p); $churches = $churches->fetchAll();
$adds = $db->prepare("SELECT x.* FROM church_add_request x JOIN area a ON a.id = x.area_id WHERE x.status = 'submitted' AND $w ORDER BY x.id"); $adds->execute($p); $adds = $adds->fetchAll();
[$w2, $p2] = scope_sql($u, 'q.region_id');
$reqs = $db->prepare("SELECT q.*, c.name AS cname FROM account_request q JOIN church c ON c.oid = q.church_oid WHERE q.status = 'pending' AND $w2 ORDER BY q.id"); $reqs->execute($p2); $reqs = $reqs->fetchAll();
$found = isset($_GET['q']) ? dir_search((string)$_GET['q']) : null;
$tok = h(csrf_token());
$areaSel = '<select name=area_id required>' . implode('', array_map(fn($a) => '<option value="' . $a['id'] . '">' . h("{$a['rcode']}-{$a['code']} {$a['name']}") . '</option>', $areas)) . '</select>';

page_head('教會與帳號', $u);
echo '<h1>教會與帳號</h1>';
if ($msg) echo '<p><b>' . h($msg) . '</b></p>';
if ($pw) echo '<p class="card">初始密碼（只顯示這一次，請交給教會）：<b>' . h($pw) . '</b></p>';

echo '<h2>從名錄綁定教會</h2><form><input name=q value="' . h($_GET['q'] ?? '') . '" placeholder="教會名稱（至少 2 字）"><button>搜尋名錄</button></form>';
if ($found !== null) {
    if (!$found) echo '<p class="note">名錄查無符合的教會。若確定不在名錄，請用下方「名錄新增申請」。</p>';
    else { echo '<table><tr><th>oid</th><th>名稱</th><th>地址</th><th>綁定到區域</th></tr>';
        foreach ($found as $c) echo "<tr><td>" . h($c['oid']) . "</td><td>" . h($c['name']) . "</td><td>" . h($c['city'] . $c['town'] . $c['address']) . "</td><td><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=bind><input type=hidden name=oid value=\"" . h($c['oid']) . "\">$areaSel <button>綁定</button></form></td></tr>";
        echo '</table>'; }
}

echo '<h2>已綁定的教會</h2><table><tr><th>名稱</th><th>oid</th><th>區域</th></tr>';
foreach ($churches as $c) echo '<tr><td>' . h($c['name']) . '</td><td>' . h($c['oid']) . '</td><td>' . h("{$c['rcode']}-{$c['acode']}") . '</td></tr>';
echo '</table>';

echo "<h2>名錄新增申請（不在名錄的教會）</h2><form method=post class=card><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=add>";
echo '<label>教會名稱<input name=name required></label><label>電話<input name=tel required></label><label>Email<input name=email type=email></label>';
echo '<label>郵遞區號<input name=zipcode></label><label>縣市<input name=city required></label><label>鄉鎮區<input name=town></label><label>地址<input name=address required></label>';
echo "<label>所屬區域$areaSel</label><button>送出名錄新增申請</button></form>";
if ($adds) { echo '<h3>等待名錄核可</h3><table><tr><th>名稱</th><th>送出</th><th>名錄核可後填 oid</th></tr>';
    foreach ($adds as $x) echo '<tr><td>' . h($x['name']) . '</td><td>' . h(date('m-d', strtotime($x['created_at']))) . "</td><td><form method=post><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=add_ok><input type=hidden name=id value=\"{$x['id']}\"><input name=oid size=8 placeholder=oid> <button>登記核可</button></form></td></tr>";
    echo '</table>'; }

if ($u['role'] === 'region_worker') {
    echo "<h2>申請教會帳號</h2><form method=post class=card><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=acct>";
    echo '<label>教會<select name=church_oid required>' . implode('', array_map(fn($c) => '<option value="' . h($c['oid']) . '">' . h($c['name']) . '</option>', $churches)) . '</select></label>';
    echo '<label>帳號<input name=username required></label><label>名稱（使用者）<input name=display_name required></label>';
    echo '<label>角色<select name=role><option value=church_staff>教會同工</option><option value=church_pastor>教會牧者</option></select></label>';
    echo '<label>教會聯絡 Email（通知用，可空白）<input name=email type=email></label><button>送出帳號申請</button></form>';
}
if ($reqs) { echo '<h2>待核可的帳號申請</h2><table><tr><th>教會</th><th>帳號</th><th>名稱</th><th>角色</th><th></th></tr>';
    foreach ($reqs as $q) {
        echo '<tr><td>' . h($q['cname']) . '</td><td>' . h($q['username']) . '</td><td>' . h($q['display_name']) . '</td><td>' . h(ROLE_NAMES[$q['role']]) . '</td><td>';
        if ($u['role'] === 'region_head') echo "<form method=post style=display:inline><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=acct_ok><input type=hidden name=id value=\"{$q['id']}\"><button>核可</button></form> <form method=post style=display:inline><input type=hidden name=csrf value=\"$tok\"><input type=hidden name=act value=acct_no><input type=hidden name=id value=\"{$q['id']}\"><input name=reason placeholder=退回原因> <button>退回</button></form>";
        else echo '等區辦主任核可';
        echo '</td></tr>';
    }
    echo '</table>'; }
page_foot();
