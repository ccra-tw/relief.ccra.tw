# relief.ccra.tw（CCRA 急難救助系統，自建）

取代舊急難救助系統（廠商開發、ASP.NET）的自建版本。技術：PHP＋PostgreSQL，部署在協會自有主機，前面走 Cloudflare（目前以 Cloudflare Access 限內部測試，未來會對外）。

**本 repo 公開，Issues 全世界看得到**（熊哥 2026-10-14：「公開 issues 可以避開內部資訊，搭配 Planner 處理」）。
Issue 與程式碼只寫功能、需求與技術決定；**不寫**：主機 IP、內部網段、帳號密碼與金鑰、同工／捐贈者／個案（受助者）姓名與個資、內部流程爭議。
這些細節放 CCRA Planner「資訊部共同事務」對應的票，Issue 內只寫「細節見 Planner 票 rkDDMh2C」。

🔴 **個案資料是高敏感個資**（身分證字號、病況、債務、家屬）：
- repo、Issue、測試資料、截圖一律只用假資料，不得出現任何真實個案。
- 開發只參考舊系統的**資料表結構**，不取舊資料（熊哥 10-07 裁示：「資料有高敏問題…未來有需要再來處理資料匯入」）。
- 密鑰與連線設定放主機上的設定檔（不進 repo）。

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (ccra-tw/relief.ccra.tw), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` + `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
