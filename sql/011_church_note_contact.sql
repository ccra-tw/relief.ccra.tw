-- 011 教會紀錄加「訪視／電訪」與其內容（使用單位 2026-11-12 試用回饋：日期與評估之間加訪視／電訪內容、多行、並標明是訪視還是電訪）
-- 舊紀錄這兩欄留空；新紀錄必填（程式檢查）。
ALTER TABLE church_note ADD COLUMN IF NOT EXISTS contact_kind text CHECK (contact_kind IN ('訪視', '電訪'));
ALTER TABLE church_note ADD COLUMN IF NOT EXISTS contact_content text;
