<?php
// 從 CCNDA 教會名錄更新已綁定教會的名稱、地址、電話、Email、負責人（#10）。名錄是主檔，本系統只存副本。
require __DIR__ . '/../lib/boot.php';
[$n, $miss] = church_sync();
echo "教會同步：更新 {$n}，名錄查無 {$miss}\n";
