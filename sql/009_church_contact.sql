-- 009 教會聯絡資料（#10 教會基本資料表用）：從名錄 /oid/ 快取。名錄是主檔，這裡只是副本，由 bin/church_sync.php 更新
ALTER TABLE church ADD COLUMN IF NOT EXISTS tel        text;
ALTER TABLE church ADD COLUMN IF NOT EXISTS email      text;
ALTER TABLE church ADD COLUMN IF NOT EXISTS president  text;   -- 名錄 president＋master_title（負責人／牧者）
