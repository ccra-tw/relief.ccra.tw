-- 008 通知信與提醒排程（#9）
-- dedupe_key：同一件事只記一筆（提醒排程重跑、同一次動作重送都不會重寄）。NULL 不受限（一般事件通知）
ALTER TABLE notification ADD COLUMN IF NOT EXISTS dedupe_key text;
CREATE UNIQUE INDEX IF NOT EXISTS notification_dedupe ON notification (dedupe_key) WHERE dedupe_key IS NOT NULL;
-- 寄送端先記後寄：claimed_at 先寫再寄，寄成功補 sent_at；claimed 了卻沒 sent 的不自動重寄（寧可漏寄不重寄）
ALTER TABLE notification ADD COLUMN IF NOT EXISTS claimed_at timestamptz;
CREATE INDEX IF NOT EXISTS notification_pending ON notification (id) WHERE claimed_at IS NULL;
