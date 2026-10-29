<?php
// 錄影簽名雛形：社工在現場用手機開這頁，簽收人在錄影畫面上直接手寫簽名＋唸聲明。說明見 lib/poc_sign.php。
// 熊哥 2026-10-29 試用回饋：「強制要開啟攝影鏡頭，簽名直接壓在錄影上」→
// 進頁面就要求開鏡頭（不開就不能簽）；簽名框疊在鏡頭畫面上，錄下來的影片同時有人臉、聲音與簽名的過程。
// 同日再補：「簽完送出就結束錄影」→ 不另設「完成／重錄」，按送出即停止錄影並上傳。
// 再補：「開始錄影簽名才需要開鏡頭，簽完送出就關閉錄影」→ 按開始才開鏡頭（拒絕仍不能簽），錄影一停就關鏡頭。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
page_head('錄影簽名（雛形）', $u);
?>
<h1>物資簽收：錄影＋簽名 <small class="tag">雛形</small></h1>
<p class="note">雛形測試用，請勿填真實姓名或個資；簽收人請用代稱（例如「測試甲」）。</p>
<form id="f" onsubmit="return false">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="statement" id="stmtv">
  <p><label>簽收人（代稱）<br><input type="text" name="recipient" maxlength="40" required></label></p>
  <p><label>物資品項與數量<br><textarea name="items" rows="2" maxlength="200" required placeholder="例：白米 2 包、罐頭 6 罐"></textarea></label></p>

  <details open><summary>蒐集錄影的告知事項（請唸給或給簽收人看）</summary>
    <p class="note">中華基督教救助協會為確認物資已交付本人，於本次簽收時錄製您的影像、聲音與簽名過程。用途僅限物資簽收佐證與內部查核；只有經手同工與督導主管可調閱；保存 <?= POC_KEEP_DAYS ?> 天（雛形測試期）後刪除。您可以拒絕錄影，改以紙本簽收，不影響您領取物資；也可以事後要求刪除。</p>
  </details>
  <p><label><input type="checkbox" name="consent" value="1" id="consent"> 簽收人已了解上述告知事項，並同意本次錄影。</label></p>

  <p id="camerr" class="note" style="color:#c62828;display:none"></p>
  <div id="stage" style="position:relative;width:100%;max-width:480px;aspect-ratio:3/4;background:#000;border-radius:14px;overflow:hidden">
    <video id="live" playsinline muted autoplay style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transform:scaleX(-1)"></video>
    <canvas id="sig" width="600" height="300" style="position:absolute;left:0;right:0;bottom:0;width:100%;height:40%;background:rgba(255,255,255,.55);touch-action:none"></canvas>
    <div style="position:absolute;left:8px;bottom:calc(40% + 6px);color:#fff;font-size:13px;text-shadow:0 1px 2px #000">↓ 在這裡簽名</div>
    <div id="recdot" style="position:absolute;top:8px;left:8px;color:#fff;font-weight:700;text-shadow:0 1px 2px #000;display:none">● REC <span id="sec">0</span>s</div>
  </div>
  <p style="margin-top:8px">錄影時請簽收人唸：<b id="stmt">我是＿＿＿，今天收到中華基督教救助協會提供的物資，數量正確。</b>，並在畫面下方簽名。</p>
  <p>
    <button type="button" id="rec">開始錄影並簽名</button>
    <button type="button" id="clr" disabled>清除簽名</button>
    <span id="rs" class="note">勾選同意後，按「開始錄影並簽名」會開啟鏡頭</span>
  </p>
  <p><button type="button" id="go" class="primary" disabled>簽完送出</button> <span id="msg"></span></p>
</form>
<p><a href="/poc/">← 雛形紀錄列表</a></p>
<script>
(() => {
  const $ = id => document.getElementById(id);
  window.addEventListener('error', e => { $('msg').textContent = '程式錯誤：' + e.message; });
  window.addEventListener('unhandledrejection', e => { $('msg').textContent = '程式錯誤：' + (e.reason && e.reason.message || e.reason); });
  const MAX = 45, W = 480, H = 640;
  let stream = null, rec = null, chunks = [], blob = null, timer = null, sec = 0, raf = 0, inked = false, composite = true;
  const live = $('live'), sig = $('sig'), sx = sig.getContext('2d');
  const out = document.createElement('canvas'); out.width = W; out.height = H; const ox = out.getContext('2d');
  sx.lineWidth = 4; sx.lineCap = 'round'; sx.lineJoin = 'round'; sx.strokeStyle = '#111';
  const pick = () => ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';

  // 進頁面就開鏡頭；不開就不能簽（熊哥：強制要開啟攝影鏡頭）
  async function openCam() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: true });
      live.srcObject = stream; $('camerr').style.display = 'none';
      // iPhone Safari：要主動 play()，loadeddata 也可能不觸發；最多等 2 秒就往下走，不可卡住（10-29 熊哥回報無法簽名與送出）
      try { await live.play(); } catch (_) {}
      await Promise.race([new Promise(r => live.readyState >= 2 ? r() : (live.onloadeddata = r)), new Promise(r => setTimeout(r, 2000))]);
      return true;
    } catch (e) {
      $('camerr').textContent = '必須開啟鏡頭與麥克風才能簽收（' + e.message + '）。請在瀏覽器設定允許後再按一次；簽收人不同意錄影請改用紙本簽收。';
      $('camerr').style.display = 'block'; $('rs').textContent = '';
      return false;
    }
  }
  const closeCam = () => { if (stream) stream.getTracks().forEach(t => t.stop()); stream = null; live.srcObject = null; };

  // 合成：鏡頭畫面＋簽名區疊在同一張畫布上錄影，影片裡同時看得到人與簽名過程
  function draw() {
    const vw = live.videoWidth || 640, vh = live.videoHeight || 480, s = Math.max(W / vw, H / vh);
    ox.save(); ox.translate(W, 0); ox.scale(-1, 1);
    ox.drawImage(live, (W - vw * s) / 2, (H - vh * s) / 2, vw * s, vh * s); ox.restore();
    const sh = H * 0.4;
    ox.fillStyle = 'rgba(255,255,255,.55)'; ox.fillRect(0, H - sh, W, sh);
    ox.drawImage(sig, 0, H - sh, W, sh);
    ox.fillStyle = '#fff'; ox.font = '14px sans-serif'; ox.fillText(new Date().toLocaleString('zh-TW'), 10, 22);
    raf = requestAnimationFrame(draw);
  }

  const pos = e => { const r = sig.getBoundingClientRect(); return [(e.clientX - r.left) * sig.width / r.width, (e.clientY - r.top) * sig.height / r.height]; };
  let drawing = false;
  sig.onpointerdown = e => { if (!rec || rec.state !== 'recording') { $('rs').textContent = '請先按「開始錄影並簽名」，錄影中才能簽名'; return; } drawing = true; inked = true; sig.setPointerCapture(e.pointerId); sx.beginPath(); sx.moveTo(...pos(e)); };
  sig.onpointermove = e => { if (drawing) { sx.lineTo(...pos(e)); sx.stroke(); } };
  sig.onpointerup = sig.onpointercancel = () => { drawing = false; };
  $('clr').onclick = () => { sx.clearRect(0, 0, sig.width, sig.height); inked = false; };

  $('rec').onclick = async () => {
    if (!$('consent').checked) { $('rs').textContent = '請先勾選同意錄影'; return; }
    if (!window.MediaRecorder) { $('rs').textContent = '這個瀏覽器不支援錄影'; return; }
    $('rec').disabled = true; $('rs').textContent = '正在開啟鏡頭…';
    if (!(await openCam())) { $('rec').disabled = false; return; }
    chunks = []; blob = null; sec = 0; inked = false; sx.clearRect(0, 0, sig.width, sig.height);
    const type = pick();
    const opts = Object.assign({ videoBitsPerSecond: 800000, audioBitsPerSecond: 64000 }, type ? { mimeType: type } : {});
    // 先試合成錄影（鏡頭＋簽名疊在一起）；任何一步失敗就退回只錄鏡頭，再失敗就顯示原因，不可安靜卡住
    rec = null; composite = false;
    if (out.captureStream) {
      try {
        draw();
        rec = new MediaRecorder(new MediaStream([...out.captureStream(24).getVideoTracks(), ...stream.getAudioTracks()]), opts);
        composite = true;
      } catch (e) { cancelAnimationFrame(raf); rec = null; }
    }
    if (!rec) {
      try { rec = new MediaRecorder(stream, opts); }
      catch (e) { try { rec = new MediaRecorder(stream); } catch (e2) { $('rs').textContent = '無法開始錄影：' + e2.message; closeCam(); $('rec').disabled = false; return; } }
    }
    rec.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    rec.onstop = () => {
      cancelAnimationFrame(raf); clearInterval(timer);
      blob = new Blob(chunks, { type: rec.mimeType || type || 'video/webm' });
      $('recdot').style.display = 'none'; $('clr').disabled = true;
      closeCam();   // 簽完送出就關閉錄影：停止錄影的同時關掉鏡頭與麥克風
      upload();   // 簽完送出就結束錄影：停止與上傳是同一個動作
    };
    try { rec.start(1000); } catch (e) { $('rs').textContent = '無法開始錄影：' + e.message; closeCam(); $('rec').disabled = false; return; }
    $('rec').disabled = true; $('clr').disabled = false; $('go').disabled = false; $('recdot').style.display = 'block';
    $('rs').textContent = '錄影中：請唸聲明，並在畫面下方簽名，簽完按「簽完送出」';
    // 到上限仍未送出：停止錄影但不自動送出，避免沒簽名的影片被上傳
    timer = setInterval(() => { sec++; $('sec').textContent = sec; if (sec >= MAX) { autoStopped = true; rec.stop(); } }, 1000);
  };
  let autoStopped = false;

  $('go').onclick = () => {
    if (rec && rec.state !== 'recording' && blob && inked) { $('go').disabled = true; upload(); return; }   // 上傳失敗後重送同一段錄影
    if (!rec || rec.state !== 'recording') return;
    if (!inked) { $('msg').textContent = '還沒簽名，請在畫面下方簽名後再送出'; return; }
    $('go').disabled = true; rec.stop();
  };

  async function upload() {
    if (autoStopped && !$('go').disabled) { autoStopped = false; $('rs').textContent = '已達 ' + MAX + ' 秒上限，錄影停止；請按「開始錄影並簽名」重新錄'; $('rec').disabled = false; $('go').disabled = true; return; }
    $('stmtv').value = $('stmt').textContent;
    const fd = new FormData($('f'));
    fd.append('signature', await new Promise(r => sig.toBlob(r, 'image/png')), 'signature.png');
    fd.append('video', blob, 'video.' + (blob.type.includes('mp4') ? 'mp4' : 'webm'));
    fd.append('composite', composite ? '1' : '0');
    $('go').disabled = true; $('msg').textContent = '上傳中…';
    try {
      const res = await fetch('/poc/save.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await res.json();
      if (j.id) location.href = '/poc/view.php?id=' + j.id;
      else { $('msg').textContent = j.error || '送出失敗'; $('go').disabled = false; }
    } catch (e) { $('msg').textContent = '送出失敗：' + e.message; $('go').disabled = false; }
  };
})();
</script>
<?php page_foot();
