<?php
// 錄影簽名雛形：社工在現場用手機開這頁，簽收人手寫簽名＋錄一段聲明。說明見 lib/poc_sign.php。
require __DIR__ . '/../../lib/boot.php';
require __DIR__ . '/../../lib/poc_sign.php';
$u = require_role(POC_ROLES);
page_head('錄影簽名（雛形）', $u);
?>
<h1>物資簽收：手寫簽名＋錄影聲明 <small class="tag">雛形</small></h1>
<p class="note">雛形測試用，請勿填真實姓名或個資；簽收人請用代稱（例如「測試甲」）。</p>
<form id="f" onsubmit="return false">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <p><label>簽收人（代稱）<br><input type="text" name="recipient" maxlength="40" required></label></p>
  <p><label>物資品項與數量<br><textarea name="items" rows="2" maxlength="200" required placeholder="例：白米 2 包、罐頭 6 罐"></textarea></label></p>

  <h2>1. 錄影聲明</h2>
  <p>請簽收人對鏡頭唸：<b id="stmt">我是＿＿＿，今天收到中華基督教救助協會提供的物資，數量正確。</b></p>
  <input type="hidden" name="statement" id="stmtv">
  <video id="live" playsinline muted autoplay style="width:100%;max-width:420px;background:#000;border-radius:12px;display:none"></video>
  <video id="play" playsinline controls style="width:100%;max-width:420px;border-radius:12px;display:none"></video>
  <p>
    <button type="button" id="cam">開啟鏡頭</button>
    <button type="button" id="rec" disabled>開始錄影</button>
    <button type="button" id="stop" disabled>停止</button>
    <span id="rs" class="note"></span>
  </p>

  <h2>2. 手寫簽名</h2>
  <canvas id="sig" width="600" height="220" style="width:100%;max-width:600px;height:auto;border:1px dashed #999;border-radius:12px;touch-action:none;background:#fff"></canvas>
  <p><button type="button" id="clr">清除簽名</button></p>

  <details open><summary>蒐集錄影的告知事項（請唸給或給簽收人看）</summary>
    <p class="note">中華基督教救助協會為確認物資已交付本人，於本次簽收時錄製您的影像與聲音。用途僅限物資簽收佐證與內部查核；只有經手同工與督導主管可調閱；保存 <?= POC_KEEP_DAYS ?> 天（雛形測試期）後刪除。您可以拒絕錄影，改以紙本簽收，不影響您領取物資；也可以事後要求刪除。</p>
  </details>
  <p><label><input type="checkbox" name="consent" value="1"> 簽收人已了解上述告知事項，並同意本次錄影。</label></p>
  <p><button type="button" id="go" class="primary">送出簽收</button> <span id="msg"></span></p>
</form>
<p><a href="/poc/">← 雛形紀錄列表</a></p>
<script>
(() => {
  const $ = id => document.getElementById(id);
  let stream = null, rec = null, chunks = [], blob = null, timer = null, sec = 0;
  const MAX = 30;
  const pick = () => ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';

  $('cam').onclick = async () => {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: true });
      $('live').srcObject = stream; $('live').style.display = 'block'; $('play').style.display = 'none';
      $('rec').disabled = false; $('rs').textContent = '鏡頭已開啟';
    } catch (e) { $('rs').textContent = '無法開啟鏡頭：' + e.message + '（請允許相機與麥克風權限）'; }
  };
  $('rec').onclick = () => {
    if (!window.MediaRecorder) { $('rs').textContent = '這個瀏覽器不支援錄影'; return; }
    chunks = []; blob = null; sec = 0;
    const type = pick();
    rec = new MediaRecorder(stream, Object.assign({ videoBitsPerSecond: 800000, audioBitsPerSecond: 64000 }, type ? { mimeType: type } : {}));
    rec.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    rec.onstop = () => {
      blob = new Blob(chunks, { type: rec.mimeType || type || 'video/webm' });
      $('play').src = URL.createObjectURL(blob); $('play').style.display = 'block'; $('live').style.display = 'none';
      $('rs').textContent = '已錄 ' + sec + ' 秒（' + (blob.size / 1048576).toFixed(1) + ' MB），可播放確認；要重錄再按「開啟鏡頭」';
      stream.getTracks().forEach(t => t.stop());
      $('rec').disabled = true; $('stop').disabled = true; clearInterval(timer);
    };
    rec.start(1000);
    $('rec').disabled = true; $('stop').disabled = false;
    timer = setInterval(() => { sec++; $('rs').textContent = '錄影中 ' + sec + ' / ' + MAX + ' 秒'; if (sec >= MAX) rec.stop(); }, 1000);
  };
  $('stop').onclick = () => { if (rec && rec.state === 'recording') rec.stop(); };

  // 手寫簽名
  const c = $('sig'), x = c.getContext('2d'); let drawing = false, inked = false;
  x.lineWidth = 3; x.lineCap = 'round'; x.strokeStyle = '#111';
  const pos = e => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
  c.onpointerdown = e => { drawing = true; inked = true; c.setPointerCapture(e.pointerId); x.beginPath(); x.moveTo(...pos(e)); };
  c.onpointermove = e => { if (drawing) { x.lineTo(...pos(e)); x.stroke(); } };
  c.onpointerup = c.onpointercancel = () => { drawing = false; };
  $('clr').onclick = () => { x.clearRect(0, 0, c.width, c.height); inked = false; };

  $('go').onclick = async () => {
    const f = $('f');
    if (!blob) { $('msg').textContent = '請先錄影'; return; }
    if (!inked) { $('msg').textContent = '請先簽名'; return; }
    $('stmtv').value = $('stmt').textContent;
    const fd = new FormData(f);
    fd.append('signature', await new Promise(r => c.toBlob(r, 'image/png')), 'signature.png');
    fd.append('video', blob, 'video.' + (blob.type.includes('mp4') ? 'mp4' : 'webm'));
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
