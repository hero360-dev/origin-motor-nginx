<?php
session_start();
if (empty($_SESSION['ng_auth'])) { http_response_code(403); exit("No autorizado"); }

$FFMPEG = "/usr/bin/ffmpeg";

function isAlive(int $pid): bool {
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return trim((string)shell_exec("ps -p ".(int)$pid." -o pid= 2>/dev/null")) !== '';
}

$url = $_GET['url'] ?? '';
$ch  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ch'] ?? 'preview');

if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    http_response_code(400); exit("URL inválida");
}

$sessionHash = substr(sha1(session_id()), 0, 10);
$token    = $ch . "_" . $sessionHash;
$baseDir  = __DIR__ . "/ng_preview_tmp";
$workDir  = $baseDir . "/" . $token;
$m3u8     = $workDir . "/index.m3u8";
$pidFile  = $workDir . "/ffmpeg.pid";
$logFile  = $workDir . "/ffmpeg.log";
$segTpl   = $workDir . "/seg_%05d.ts";
$hlsUrl   = "ng_preview_tmp/" . rawurlencode($token) . "/index.m3u8";

@mkdir($workDir, 0775, true);

$pid = 0;
if (is_file($pidFile)) {
    $pid = (int)trim((string)@file_get_contents($pidFile));
    if (!isAlive($pid)) {
        $pid = 0;
        @unlink($pidFile);
        foreach (glob($workDir . "/*") as $f) if (is_file($f)) @unlink($f);
    }
}

if ($pid === 0) {
    @file_put_contents($logFile, "");
    $cmd = "nohup " . escapeshellcmd($FFMPEG) . " "
        . "-hide_banner -loglevel warning "
        . "-user_agent " . escapeshellarg("Mozilla/5.0") . " "
        . "-reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 2 "
        . "-fflags +genpts "
        . "-i " . escapeshellarg($url) . " "
        . "-map 0:v:0 -map 0:a? "
        . "-c:v libx264 -preset veryfast -tune zerolatency -crf 24 -pix_fmt yuv420p "
        . "-g 48 -keyint_min 48 -sc_threshold 0 "
        . "-c:a aac -b:a 96k -ac 2 -ar 44100 "
        . "-f hls "
        . "-hls_time 2 -hls_list_size 6 "
        . "-hls_flags delete_segments+append_list "
        . "-hls_segment_filename " . escapeshellarg($segTpl) . " "
        . escapeshellarg($m3u8) . " "
        . ">" . escapeshellarg($logFile) . " 2>&1 & echo $!";
    $pid = (int)trim((string)shell_exec($cmd));
    if ($pid > 0) @file_put_contents($pidFile, (string)$pid);
}

header("X-Frame-Options: SAMEORIGIN");
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Preview <?= htmlspecialchars($ch) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;color:#ccc;font-family:'Segoe UI',sans-serif;padding:14px}
.meta{font-size:.78rem;color:#64748b;margin-bottom:10px;line-height:1.6}
.meta .val{color:#38bdf8;font-family:monospace;word-break:break-all}
video{width:100%;max-height:72vh;background:#000;border-radius:6px;display:block}
.btns{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.btn{padding:5px 12px;border:none;border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600}
.btn-stop{background:#7f1d1d;color:#fca5a5}
.btn-reload{background:#1e3a5f;color:#93c5fd}
#msg{font-size:.8rem;margin-top:8px;color:#f59e0b;min-height:18px}
</style>
</head>
<body>
<div class="meta">
  <div><b>Canal:</b> <span class="val"><?= htmlspecialchars($ch) ?></span></div>
  <div><b>Input:</b> <span class="val"><?= htmlspecialchars($url) ?></span></div>
  <div><b>PID:</b> <span class="val"><?= $pid ?></span></div>
</div>
<div class="btns">
  <button class="btn btn-stop" onclick="stopPreview()">⏹ Detener</button>
  <button class="btn btn-reload" onclick="reloadPlayer()">↺ Recargar</button>
</div>
<video id="v" controls autoplay muted playsinline></video>
<div id="msg">⏳ Iniciando preview...</div>
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script>
const hlsSrc = <?= json_encode($hlsUrl) ?> + "?t=" + Date.now();
const ch = <?= json_encode($ch) ?>;
const urlParam = <?= json_encode($url) ?>;
let hls;

function msg(t){ document.getElementById('msg').textContent = t || ''; }

async function waitForM3U8() {
  for (let i = 0; i < 30; i++) {
    try {
      const r = await fetch(hlsSrc, {cache:'no-store'});
      if (r.ok) { const t = await r.text(); if (t.includes('#EXTM3U')) return true; }
    } catch(e) {}
    await new Promise(r => setTimeout(r, 600));
  }
  return false;
}

async function startPlayer() {
  msg("⏳ Iniciando preview...");
  const ok = await waitForM3U8();
  if (!ok) { msg("❌ No se generó el HLS. Revisa la fuente."); return; }
  const v = document.getElementById('v');
  if (v.canPlayType('application/vnd.apple.mpegurl')) {
    v.src = hlsSrc; msg(""); return;
  }
  if (!window.Hls || !Hls.isSupported()) { msg("❌ Navegador no soporta HLS.js"); return; }
  if (hls) { try { hls.destroy(); } catch(e) {} }
  hls = new Hls({ lowLatencyMode: true, backBufferLength: 20 });
  hls.loadSource(hlsSrc);
  hls.attachMedia(v);
  hls.on(Hls.Events.ERROR, (e, d) => { if(d.fatal) msg("Error: " + d.details); });
  msg("");
}

function reloadPlayer() {
  if (hls) { try { hls.destroy(); } catch(e){} }
  const v = document.getElementById('v'); v.pause(); v.src = '';
  startPlayer();
}

async function stopPreview() {
  msg("⏹ Deteniendo...");
  try { await fetch("ng_preview_stop.php?ch="+encodeURIComponent(ch)+"&session=<?= urlencode($sessionHash) ?>", {cache:"no-store",credentials:"same-origin"}); } catch(e){}
  if (hls) { try { hls.destroy(); } catch(e){} }
  const v = document.getElementById('v'); v.pause(); v.src = '';
  msg("✅ Preview detenido");
}

window.addEventListener('beforeunload', () => {
  navigator.sendBeacon("ng_preview_stop.php?ch="+encodeURIComponent(ch)+"&session=<?= urlencode($sessionHash) ?>");
});

startPlayer();
</script>
</body>
</html>
