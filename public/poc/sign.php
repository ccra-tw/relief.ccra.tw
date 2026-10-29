<?php
// 錄影簽名雛形：社工在現場用手機開這頁，簽收人一邊錄影一邊手寫簽名＋唸聲明。說明見 lib/poc_sign.php。
// 熊哥 2026-10-29 試用回饋（依序）：
//   「強制要開啟攝影鏡頭，簽名直接壓在錄影上」→ 拒絕鏡頭就不能簽；簽名疊在鏡頭畫面上合成錄影
//   「簽完送出就結束錄影」→ 不另設完成／重錄，送出即停止錄影並上傳
//   「開始錄影簽名才需要開鏡頭，簽完送出就關閉錄影」→ 按開始才開鏡頭，錄影一停就關
//   「按下錄影簽名在下方開啟錄影和簽名，可以全畫面簽名比較方便」→ 按下後展開全螢幕：上方鏡頭、下方大簽名區
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
page_head('錄影簽名（雛形）', $u);
?>
<style>
label.chk{display:flex;flex-direction:row;align-items:flex-start;gap:10px;font-weight:700;cursor:pointer}
label.chk input{width:24px!important;height:24px;min-width:24px;margin:2px 0 0!important;padding:0;flex:none;accent-color:var(--brand)}
#f .fe{color:#c62828;font-weight:700;font-size:15px;margin-top:6px}
#f .bad{outline:3px solid #c62828;outline-offset:2px;border-radius:6px}
#stage{display:none;position:fixed;inset:0;z-index:9999;background:#000;flex-direction:column}
#stage.on{display:flex}
#stage .cam{position:relative;flex:1 1 auto;overflow:hidden}
#stage .cam video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1)}
#stage .cam .hint{position:absolute;left:10px;right:10px;top:44px;color:#fff;font-size:14px;line-height:1.4;text-shadow:0 1px 3px #000}
#stage .cam .clock{position:absolute;top:8px;right:10px;color:#fff;font:600 15px/1.2 ui-monospace,monospace;background:rgba(0,0,0,.45);padding:4px 8px;border-radius:8px}
#stage .cam .rec{position:absolute;top:10px;left:10px;color:#fff;font-weight:700;text-shadow:0 1px 3px #000}
#stage .cam canvas{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#stage .cam .ph{position:absolute;left:0;right:0;bottom:44px;text-align:center;color:#fff;font-size:15px;text-shadow:0 1px 3px #000;pointer-events:none}
#stage .bar{display:flex;gap:10px;padding:10px;background:#f4f4f4;align-items:center}
#stage .bar button{flex:1;font-size:17px;padding:14px 8px;border-radius:12px}
#stage .bar .msg{flex:2;font-size:13px;color:#c62828}
</style>
<h1>物資簽收：錄影＋簽名 <small class="tag">雛形</small></h1>
<p class="note">雛形測試用，請勿填真實姓名或個資；簽收人請用代稱（例如「測試甲」）。</p>
<details class="help"><summary><b>使用說明（第一次用請先看）</b></summary>
  <p><b>操作步驟</b></p>
  <ol>
    <li>填簽收人代稱與物資品項。</li>
    <li>把下方「告知事項」唸給簽收人聽，對方同意後打勾。</li>
    <li>按「開始錄影並簽名」：會開啟鏡頭並進入全螢幕，上方是鏡頭、下方白色區域簽名。</li>
    <li>請簽收人看著鏡頭唸出畫面上的聲明，並在白色區域簽名（簽錯按「清除」重簽）。</li>
    <li>按「簽完送出」：錄影結束、鏡頭關閉並上傳，自動跳到收執頁，可播放確認。</li>
  </ol>
  <p><b>要用哪個瀏覽器</b>：安卓請用 <b>Chrome</b>，iPhone 請用 <b>Safari</b>。從 LINE、Telegram、Facebook 點進來的連結是 App 內建瀏覽器，常常不能開鏡頭——請點右上角選單選「在 Chrome（或 Safari）中開啟」。</p>
  <p><b>沒有跳出「允許使用相機／麥克風」怎麼辦</b></p>
  <ul>
    <li>安卓 Chrome：點網址列左邊的圖示 →「權限」→ 相機、麥克風改成「允許」，重新整理再按一次。</li>
    <li>iPhone Safari：點網址列左邊的「大小」→「網站設定」→ 相機、麥克風改成「允許」；或到「設定 → Safari → 相機／麥克風」改成「詢問」。</li>
    <li>還是不行：把畫面上的紅字訊息截圖給資訊部。</li>
  </ul>
  <p><b>其他</b>：錄影最長 45 秒，超過會自動停下請重錄；簽收人不同意錄影，請改用紙本簽收，不影響領取物資。</p>
</details>
<form id="f" onsubmit="return false">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="statement" id="stmtv">
  <p><label>簽收人（代稱）<br><input type="text" name="recipient" maxlength="40" required></label></p>
  <p><label>物資品項與數量<br><textarea name="items" rows="2" maxlength="200" required placeholder="例：白米 2 包、罐頭 6 罐"></textarea></label></p>

  <details open><summary>蒐集錄影的告知事項（請唸給或給簽收人看）</summary>
    <p class="note">中華基督教救助協會為確認物資已交付本人，於本次簽收時錄製您的影像、聲音與簽名過程。用途僅限物資簽收佐證與內部查核；只有經手同工與督導主管可調閱；保存 <?= POC_KEEP_DAYS ?> 天（雛形測試期）後刪除。您可以拒絕錄影，改以紙本簽收，不影響您領取物資；也可以事後要求刪除。</p>
  </details>
  <p><label class="chk"><input type="checkbox" name="consent" value="1" id="consent"><span>簽收人已了解上述告知事項，並同意本次錄影。</span></label></p>
  <p>錄影時請簽收人唸：<b id="stmt">我是＿＿＿，今天收到中華基督教救助協會提供的物資，數量正確。</b></p>
  <p><button type="button" id="rec" class="primary">開始錄影並簽名</button> <span id="rs" class="note">勾選同意後按這裡，會開啟鏡頭並進入全螢幕簽名</span></p>
  <p id="camerr" class="note" style="color:#c62828;display:none"></p>
  <p id="msg" class="note"></p>
</form>
<p><a href="/poc/">← 雛形紀錄列表</a></p>

<div id="stage">
  <div class="cam">
    <video id="live" playsinline muted autoplay></video>
    <div class="rec">● REC <span id="sec">0</span>s</div>
    <div class="clock" id="clock"></div>
    <div class="hint">請唸：<span id="stmt2"></span></div>
    <canvas id="sig"></canvas>
    <div class="ph">直接在畫面上簽名</div>
  </div>
  <div class="bar">
    <button type="button" id="clr">清除</button>
    <span class="msg" id="smsg"></span>
    <button type="button" id="go" class="primary">簽完送出</button>
  </div>
</div>

<script>
(() => {
  const $ = id => document.getElementById(id);
  const say = t => { $('smsg').textContent = t; $('msg').textContent = t; };
  window.addEventListener('error', e => say('程式錯誤：' + e.message));
  window.addEventListener('unhandledrejection', e => say('程式錯誤：' + (e.reason && e.reason.message || e.reason)));
  const MAX = 45, W = 480; let H = 800;
  let stamped = '', stampDone = false;
  let stream = null, rec = null, chunks = [], blob = null, timer = null, sec = 0, raf = 0, inked = false, composite = false, autoStopped = false;
  const live = $('live'), sig = $('sig'), sx = sig.getContext('2d');
  const out = document.createElement('canvas'); out.width = W; out.height = H; const ox = out.getContext('2d');
  // 時間戳（熊哥 10-29：「錄影簽名部分要加上時間戳」）：螢幕即時顯示、合成進影片、送出時蓋進簽名圖
  const ts = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); };
  setInterval(() => { $('clock').textContent = ts(); }, 500);
  const pick = () => ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
  $('stmt2').textContent = $('stmt').textContent;
  // App 內建瀏覽器（Telegram、LINE、Facebook、Android WebView）常不跳相機權限詢問（熊哥 10-29 安卓試用回報）→ 先提示改用 Chrome／Safari
  // 熊哥同日再問「有辦法去開預設瀏覽器而不是 app 內建的瀏覽器嗎」：
  //   LINE：網址加 openExternalBrowser=1，LINE 會自己改用外部瀏覽器開（直接跳轉）
  //   安卓其他 App（Telegram、Facebook、WebView）：intent:// 連結交給系統，用預設瀏覽器開
  //   iPhone：沒有通用做法；試 x-safari-https://（新版 iOS 可用），不行就複製網址自己貼到 Safari
  const ua = navigator.userAgent, here = location.href;
  if (/Line\//i.test(ua) && !/[?&]openExternalBrowser=1/.test(location.search)) {
    location.replace(here + (location.search ? '&' : '?') + 'openExternalBrowser=1');
    return;
  }
  if (/Telegram|Line\/|FBAN|FBAV|Instagram|; wv\)/i.test(ua) || !(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
    const android = /Android/i.test(ua), u = new URL(here);
    const ext = android ? 'intent://' + u.host + u.pathname + u.search + '#Intent;scheme=https;end'
                        : 'x-safari-https://' + u.host + u.pathname + u.search;
    $('camerr').innerHTML = '這是 App 內建瀏覽器，可能無法開啟鏡頭。'
      + '<br><a class="btn primary" href="' + ext + '">用' + (android ? '手機預設瀏覽器' : ' Safari ') + '開啟這一頁</a> '
      + '<button type="button" id="cpy">複製網址</button>'
      + '<br><small>按了沒反應的話：點右上角選單選「在瀏覽器中開啟」，或按「複製網址」貼到 ' + (android ? 'Chrome' : 'Safari') + '。</small>';
    $('camerr').style.display = 'block';
    $('cpy').onclick = async () => {
      try { await navigator.clipboard.writeText(here); $('cpy').textContent = '已複製'; }
      catch (_) { prompt('請長按複製這個網址：', here); }
    };
  }

  // 全螢幕簽名區：畫布像素跟著實際尺寸（含高解析度螢幕），筆畫才不會糊
  function sizePad() {
    const r = sig.getBoundingClientRect(), d = window.devicePixelRatio || 1;
    sig.width = Math.round(r.width * d); sig.height = Math.round(r.height * d);
    sx.setTransform(1, 0, 0, 1, 0, 0);
    sx.lineWidth = 5 * d; sx.lineCap = 'round'; sx.lineJoin = 'round'; sx.strokeStyle = '#fff'; sx.shadowColor = 'rgba(0,0,0,.9)'; sx.shadowBlur = 3 * d;   // 白字黑邊：疊在任何畫面上都看得清楚
  }
  const open = () => { $('stage').classList.add('on'); document.body.style.overflow = 'hidden'; requestAnimationFrame(sizePad); };
  const shut = () => { $('stage').classList.remove('on'); document.body.style.overflow = ''; };

  async function openCam() {
    try {
      if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) throw new Error('此瀏覽器不支援開啟鏡頭，請改用 Chrome 或 Safari');
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: true });
      live.srcObject = stream; $('camerr').style.display = 'none';
      // iPhone Safari：要主動 play()，loadeddata 也可能不觸發；最多等 2 秒就往下走，不可卡住
      try { await live.play(); } catch (_) {}
      await Promise.race([new Promise(r => live.readyState >= 2 ? r() : (live.onloadeddata = r)), new Promise(r => setTimeout(r, 2000))]);
      return true;
    } catch (e) {
      shut();
      $('camerr').textContent = '必須開啟鏡頭與麥克風才能簽收（' + e.message + '）。請在瀏覽器設定允許後再按一次；簽收人不同意錄影請改用紙本簽收。';
      $('camerr').style.display = 'block'; $('rs').textContent = '';
      return false;
    }
  }
  const closeCam = () => { if (stream) stream.getTracks().forEach(t => t.stop()); stream = null; live.srcObject = null; };

  // 合成：上方鏡頭、下方簽名，跟手機上看到的版面一樣，錄進同一段影片
  function draw() {
    // 熊哥 10-29：「簽名要蓋在影片上」→ 鏡頭畫面鋪滿，簽名直接疊在畫面上（與手機上看到的一致）
    const vw = live.videoWidth || 640, vh = live.videoHeight || 480, s = Math.max(W / vw, H / vh);
    ox.fillStyle = '#000'; ox.fillRect(0, 0, W, H);
    ox.save(); ox.translate(W, 0); ox.scale(-1, 1);
    ox.drawImage(live, (W - vw * s) / 2, (H - vh * s) / 2, vw * s, vh * s); ox.restore();
    ox.drawImage(sig, 0, 0, W, H);
    ox.font = '600 18px monospace'; const t = ts(), tw = ox.measureText(t).width;
    ox.fillStyle = 'rgba(0,0,0,.55)'; ox.fillRect(W - tw - 20, 8, tw + 14, 28); ox.fillStyle = '#fff'; ox.fillText(t, W - tw - 13, 28);
    raf = requestAnimationFrame(draw);
  }

  const pos = e => { const r = sig.getBoundingClientRect(); return [(e.clientX - r.left) * sig.width / r.width, (e.clientY - r.top) * sig.height / r.height]; };
  let drawing = false;
  sig.onpointerdown = e => { if (!rec || rec.state !== 'recording') return; drawing = true; inked = true; sig.setPointerCapture(e.pointerId); sx.beginPath(); sx.moveTo(...pos(e)); };
  sig.onpointermove = e => { if (drawing) { sx.lineTo(...pos(e)); sx.stroke(); } };
  sig.onpointerup = sig.onpointercancel = () => { drawing = false; };
  $('clr').onclick = () => { sx.clearRect(0, 0, sig.width, sig.height); inked = false; };

  $('rec').onclick = async () => {
    // 熊哥 10-29：資料沒填就進簽名，送出才報錯又回不去 → 進入錄影前先檢查全部欄位
    // 熊哥同日：「跳上去沒有在旁邊出現提醒字樣」→ 提示寫在那一欄正下方，欄位框變紅
    document.querySelectorAll('#f .fe').forEach(x => x.remove());
    document.querySelectorAll('#f .bad').forEach(x => x.classList.remove('bad'));
    const miss = [['recipient', '↑ 請填寫簽收人代稱'], ['items', '↑ 請填寫物資品項與數量'], ['consent', '↑ 請唸告知事項給簽收人聽，對方同意後勾選這一項']]
      .find(([n]) => { const el = $('f').elements[n]; return el.type === 'checkbox' ? !el.checked : !el.value.trim(); });
    if (miss) {
      const el = $('f').elements[miss[0]], tip = document.createElement('div');
      tip.className = 'fe'; tip.textContent = miss[1];
      (el.type === 'checkbox' ? el.closest('label') : el).classList.add('bad');
      el.closest('p').appendChild(tip);
      $('rs').textContent = miss[1].replace('↑ ', '');
      el.scrollIntoView({ block: 'center' }); el.focus({ preventScroll: true });
      el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', () => { tip.remove(); (el.type === 'checkbox' ? el.closest('label') : el).classList.remove('bad'); }, { once: true });
      return;
    }
    if (!window.MediaRecorder) { $('rs').textContent = '這個瀏覽器不支援錄影'; return; }
    $('rec').disabled = true; $('rs').textContent = '正在開啟鏡頭…'; say('');
    open();
    if (!(await openCam())) { $('rec').disabled = false; return; }
    // 合成畫布的長寬比跟手機上的鏡頭區一致，簽名在影片裡的位置才不會被拉長
    { const r = document.querySelector('#stage .cam').getBoundingClientRect(); H = Math.round(W * (r.height / r.width || 800 / 480) / 2) * 2; out.width = W; out.height = H; }
    chunks = []; blob = null; sec = 0; inked = false; autoStopped = false; stamped = ''; stampDone = false; $('sec').textContent = '0';
    sx.clearRect(0, 0, sig.width, sig.height);
    const type = pick();
    const opts = Object.assign({ videoBitsPerSecond: 900000, audioBitsPerSecond: 64000 }, type ? { mimeType: type } : {});
    // 先試合成錄影；任何一步失敗就退回只錄鏡頭，再失敗就顯示原因，不可安靜卡住
    rec = null; composite = false;
    if (out.captureStream) {
      try { draw(); rec = new MediaRecorder(new MediaStream([...out.captureStream(24).getVideoTracks(), ...stream.getAudioTracks()]), opts); composite = true; }
      catch (e) { cancelAnimationFrame(raf); rec = null; }
    }
    if (!rec) {
      try { rec = new MediaRecorder(stream, opts); }
      catch (e) { try { rec = new MediaRecorder(stream); } catch (e2) { say('無法開始錄影：' + e2.message); closeCam(); shut(); $('rec').disabled = false; return; } }
    }
    rec.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    rec.onstop = () => {
      cancelAnimationFrame(raf); clearInterval(timer);
      blob = new Blob(chunks, { type: rec.mimeType || type || 'video/webm' });
      closeCam();   // 簽完送出就關閉錄影：停止錄影的同時關掉鏡頭與麥克風
      upload();
    };
    try { rec.start(1000); } catch (e) { say('無法開始錄影：' + e.message); closeCam(); shut(); $('rec').disabled = false; return; }
    $('go').disabled = false; $('rs').textContent = '';
    // 到上限仍未送出：停止錄影但不自動送出，避免沒簽完的影片被上傳
    timer = setInterval(() => { sec++; $('sec').textContent = sec; if (sec >= MAX) { autoStopped = true; rec.stop(); } }, 1000);
  };

  $('go').onclick = () => {
    if (rec && rec.state !== 'recording' && blob && inked) { $('go').disabled = true; upload(); return; }   // 上傳失敗後重送同一段錄影
    if (!rec || rec.state !== 'recording') return;
    if (!inked) { say('還沒簽名，請在白色區域簽名後再送出'); return; }
    $('go').disabled = true; rec.stop();
  };

  async function upload() {
    if (autoStopped) { autoStopped = false; shut(); $('rec').disabled = false; $('rs').textContent = '已達 ' + MAX + ' 秒上限，錄影停止；請再按一次重新錄'; return; }
    $('stmtv').value = $('stmt').textContent;
    const fd = new FormData($('f'));
    // 簽署時間蓋在簽名圖右下角（送出那一刻）；伺服器另記收到時間，兩者都在收執頁
    const d = window.devicePixelRatio || 1;
    if (!stamped) stamped = '簽署時間 ' + ts();   // 重送時沿用第一次的時間，不重複蓋
    const st = stamped;
    if (!stampDone) { stampDone = true; sx.save(); sx.font = (14 * d) + 'px monospace'; sx.fillStyle = '#fff'; sx.textAlign = 'right'; sx.fillText(st, sig.width - 10 * d, sig.height - 10 * d); sx.restore(); }
    fd.append('signed_at_client', st.replace('簽署時間 ', ''));
    fd.append('signature', await new Promise(r => sig.toBlob(r, 'image/png')), 'signature.png');
    fd.append('video', blob, 'video.' + (blob.type.includes('mp4') ? 'mp4' : 'webm'));
    fd.append('composite', composite ? '1' : '0');
    say('上傳中…');
    try {
      const res = await fetch('/poc/save.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await res.json();
      if (j.id) location.href = '/poc/view.php?id=' + j.id;
      else { shut(); $('rec').disabled = false; say(''); $('rs').textContent = (j.error || '送出失敗') + '　請修正後再按一次重新錄影簽名。'; }   // 伺服器退件：回到表單，不可困在全螢幕
    } catch (e) { say('送出失敗：' + e.message + '（可再按一次送出重試）'); $('go').disabled = false; }
  }
})();
</script>
<?php page_foot();
