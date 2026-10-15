<?php
require __DIR__ . '/../lib/boot.php';
if (current_user_or_null()) { header('Location: /'); exit; }
$err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $err = password_login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if (!$err) { header('Location: /'); exit; }
}
page_head('登入');
echo '<h1>教會帳號登入</h1>';
echo '<p class="note">協會同工請直接以協會 M365 帳號開啟本站，不必在這裡登入。</p>';
if ($err) echo '<p class="err">' . h($err) . '</p>';
echo '<form method="post" class="card"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
echo '<label>帳號<input name="username" autocomplete="username" required></label>';
echo '<label>密碼<input type="password" name="password" autocomplete="current-password" required></label>';
echo '<button>登入</button></form>';
page_foot();
