<?php
session_start();
if(empty($_SESSION['ng_auth'])){header('Location: ng_manager.php');exit;}

// ── API endpoint ────────────────────────────────────────────────────────────
if(isset($_GET['api'])){
    $edges = [
        'edge1' => '186.233.186.55',
        'edge2' => '186.233.186.58',
        'edge3' => '198.147.24.146',
    ];
    $result = [];
    $mh = curl_multi_init();
    $handles = [];
    foreach($edges as $name => $ip){
        $ch = curl_init("http://{$ip}:8091/metrics");
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$name] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while($running > 0);
    foreach($handles as $name => $ch){
        $raw = curl_multi_getcontent($ch);
        $data = $raw ? @json_decode($raw, true) : null;
        $result[$name] = $data ?: ['hostname'=>$name,'error'=>'timeout','nginx_status'=>'unknown',
            'viewers'=>0,'bw_out_mbps'=>0,'bytes_out_30s'=>0,'requests_30s'=>0,
            'cache_size'=>'N/A','cache_files'=>0,'timestamp'=>time()];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edges - Nginx Stream</title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;display:flex}
:root{--sb-w:220px}
.sidebar{width:var(--sb-w);min-height:100vh;background:#0a1628;border-right:1px solid #1e293b;display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:200;transition:width .25s;overflow:hidden}
.sidebar.collapsed{width:58px}.sidebar.collapsed .sb-label,.sidebar.collapsed .sb-logo-text,.sidebar.collapsed .sb-user-info{display:none}
.sb-logo{display:flex;align-items:center;gap:10px;padding:18px 14px 14px;border-bottom:1px solid #1e293b}
.sb-logo-icon{font-size:1.3rem;flex-shrink:0}.sb-logo-text{font-size:1rem;font-weight:700;color:#38bdf8;white-space:nowrap}
.sb-toggle{margin-left:auto;background:none;border:none;color:#475569;cursor:pointer;font-size:1rem;padding:2px}
.sb-user{padding:12px 14px;border-bottom:1px solid #1e293b}.sb-user-info{font-size:.75rem;color:#475569}.sb-clock{font-size:.72rem;color:#334155;margin-top:2px}
.sb-nav{flex:1;padding:8px 0}.sb-item{display:flex;align-items:center;gap:12px;padding:10px 16px;color:#64748b;text-decoration:none;font-size:.88rem;transition:all .2s;white-space:nowrap;border-left:3px solid transparent}
.sb-item:hover{background:#1e293b;color:#e2e8f0}.sb-item.active{background:#0ea5e915;color:#38bdf8;border-left-color:#38bdf8}
.sb-icon{font-size:1.1rem;flex-shrink:0;width:22px;text-align:center}
.sb-logout{display:flex;align-items:center;gap:10px;padding:12px 16px;color:#ef4444;font-size:.85rem;cursor:pointer;border:none;background:none;width:100%;border-top:1px solid #1e293b}
.sb-status{padding:10px 14px;border-top:1px solid #1e293b;font-size:.72rem;color:#334155;display:flex;align-items:center;gap:6px}
.sb-status-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.sidebar.collapsed~.main-wrap{margin-left:58px}
.main-wrap{margin-left:var(--sb-w);flex:1;padding:28px 32px;transition:margin-left .25s;min-height:100vh}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.topbar h1{font-size:1.4rem;color:#38bdf8}
.refresh-info{font-size:.75rem;color:#475569}
/* Edge cards grid */
.edges-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px}
.edge-card{background:#0a1628;border:1px solid #1e293b;border-radius:12px;padding:20px;position:relative;transition:border-color .3s}
.edge-card.online{border-color:#22c55e44}
.edge-card.offline{border-color:#ef444444}
.edge-card.error{border-color:#f59e0b44}
.card-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #1e293b}
.card-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.dot-active{background:#22c55e;box-shadow:0 0 6px #22c55e88;animation:pulse 2s infinite}
.dot-inactive{background:#ef4444}
.dot-unknown{background:#f59e0b}
.card-name{font-size:1.05rem;font-weight:700;color:#e2e8f0}
.card-ip{font-size:.72rem;color:#475569;margin-left:auto}
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stat-box{background:#0f172a;border-radius:8px;padding:12px}
.stat-label{font-size:.68rem;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.stat-value{font-size:1.2rem;font-weight:700;color:#38bdf8}
.stat-value.green{color:#22c55e}
.stat-value.yellow{color:#f59e0b}
.stat-value.red{color:#ef4444}
.stat-sub{font-size:.68rem;color:#334155;margin-top:2px}
.cache-row{margin-top:12px;background:#0f172a;border-radius:8px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center}
.cache-label{font-size:.72rem;color:#475569}
.cache-val{font-size:.85rem;font-weight:600;color:#64748b}
.ts{font-size:.65rem;color:#1e3a5f;text-align:right;margin-top:10px}
.totals-bar{background:#0a1628;border:1px solid #1e293b;border-radius:12px;padding:18px 24px;margin-bottom:24px;display:flex;gap:32px;flex-wrap:wrap}
.total-item{display:flex;flex-direction:column;gap:2px}
.total-label{font-size:.7rem;color:#475569;text-transform:uppercase;letter-spacing:.05em}
.total-value{font-size:1.6rem;font-weight:800;color:#38bdf8}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid #1e293b;border-top-color:#38bdf8;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style></head><body>

<div class="sidebar" id="sidebar">
  <div class="sb-logo">
    <span class="sb-logo-icon">⚡</span>
    <span class="sb-logo-text">Nginx Stream</span>
    <button class="sb-toggle" onclick="toggleSidebar()" title="Colapsar">☰</button>
  </div>
  <div class="sb-user">
    <div class="sb-user-info">ngadmin</div>
    <div class="sb-clock" id="sb-clock">--:--:--</div>
  </div>
  <nav class="sb-nav">
    <a class="sb-item" href="ng_dashboard.php">
      <span class="sb-icon">📊</span><span class="sb-label">Dashboard</span>
    </a>
    <a class="sb-item" href="ng_manager.php">
      <span class="sb-icon">📡</span><span class="sb-label">Canales</span>
    </a>
    <a class="sb-item active" href="ng_edges.php">
      <span class="sb-icon">🌐</span><span class="sb-label">Edges</span>
    </a>
    <a class="sb-item" href="ng_help.php">
      <span class="sb-icon">❓</span><span class="sb-label">Ayuda / Guía</span>
    </a>
    <a class="sb-item" href="ng_settings.php">
      <span class="sb-icon">⚙️</span><span class="sb-label">Configuración</span>
    </a>
  </nav>
  <div class="sb-status">
    <span class="sb-status-dot"></span>
    <span id="sb-status-txt">Cargando...</span>
  </div>
  <button class="sb-logout" onclick="location.href='ng_manager.php?logout'">
    <span>🚪</span><span class="sb-label">Salir</span>
  </button>
</div>

<div class="main-wrap">
  <div class="topbar">
    <h1>🌐 Edge Servers</h1>
    <span class="refresh-info">Auto-refresh: <span id="countdown">5</span>s &nbsp;<span id="spin-icon" class="spinner" style="display:none"></span></span>
  </div>

  <!-- Totals bar -->
  <div class="totals-bar" id="totals-bar">
    <div class="total-item"><span class="total-label">Edges Online</span><span class="total-value" id="t-online">—</span></div>
    <div class="total-item"><span class="total-label">Viewers Totales</span><span class="total-value" id="t-viewers">—</span></div>
    <div class="total-item"><span class="total-label">BW Salida Total</span><span class="total-value" id="t-bw">—</span></div>
    <div class="total-item"><span class="total-label">Req/30s Total</span><span class="total-value" id="t-req">—</span></div>
  </div>

  <!-- Edge cards -->
  <div class="edges-grid" id="edges-grid">
    <div style="color:#475569;padding:20px">Cargando datos de edges...</div>
  </div>
</div>

<script>
const EDGES = {
  edge1: '186.233.186.55',
  edge2: '186.233.186.58',
  edge3: '198.147.24.146'
};

function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('collapsed');
  localStorage.setItem('sb_collapsed', document.getElementById('sidebar').classList.contains('collapsed'));
}
if(localStorage.getItem('sb_collapsed')==='true')
  document.getElementById('sidebar').classList.add('collapsed');

// Clock
setInterval(()=>{
  const n=new Date();
  document.getElementById('sb-clock').textContent=n.toLocaleTimeString('es-MX');
},1000);

function statusDotClass(s){
  if(s==='active') return 'dot-active';
  if(s==='inactive') return 'dot-inactive';
  return 'dot-unknown';
}
function cardClass(s){
  if(s==='active') return 'edge-card online';
  if(s==='inactive') return 'edge-card offline';
  return 'edge-card error';
}
function bwColor(mbps){
  if(mbps>50) return 'red';
  if(mbps>10) return 'yellow';
  return 'green';
}
function fmtTs(ts){
  if(!ts) return '—';
  return new Date(ts*1000).toLocaleTimeString('es-MX');
}

function renderEdges(data){
  const grid = document.getElementById('edges-grid');
  let html = '';
  let totalOnline=0, totalViewers=0, totalBw=0, totalReq=0;

  const names = ['edge1','edge2','edge3'];
  names.forEach(name => {
    const d = data[name];
    if(!d){ html += `<div class="edge-card error"><div class="card-header"><span class="card-dot dot-unknown"></span><span class="card-name">${name}</span><span class="card-ip">${EDGES[name]}</span></div><p style="color:#f59e0b;font-size:.85rem">Sin respuesta</p></div>`; return; }
    const st = d.nginx_status || 'unknown';
    if(st==='active') totalOnline++;
    totalViewers += d.viewers||0;
    totalBw      += d.bw_out_mbps||0;
    totalReq     += d.requests_30s||0;
    const bwc = bwColor(d.bw_out_mbps||0);
    html += `
    <div class="${cardClass(st)}">
      <div class="card-header">
        <span class="card-dot ${statusDotClass(st)}"></span>
        <span class="card-name">${d.hostname||name}</span>
        <span class="card-ip">${EDGES[name]}</span>
      </div>
      <div class="stats-grid">
        <div class="stat-box">
          <div class="stat-label">👁 Viewers</div>
          <div class="stat-value green">${d.viewers||0}</div>
          <div class="stat-sub">últimos 12s</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">📤 BW Salida</div>
          <div class="stat-value ${bwc}">${(d.bw_out_mbps||0).toFixed(2)} Mbps</div>
          <div class="stat-sub">últimos 30s</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">📶 Req/30s</div>
          <div class="stat-value">${d.requests_30s||0}</div>
          <div class="stat-sub">peticiones totales</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">🔄 nginx</div>
          <div class="stat-value ${st==='active'?'green':'red'}">${st}</div>
          <div class="stat-sub">estado del servicio</div>
        </div>
      </div>
      <div class="cache-row">
        <span class="cache-label">💾 Cache</span>
        <span class="cache-val">${d.cache_size||'—'} &nbsp;|&nbsp; ${d.cache_files||0} archivos</span>
      </div>
      <div class="ts">Actualizado: ${fmtTs(d.timestamp)}</div>
    </div>`;
  });
  grid.innerHTML = html;

  document.getElementById('t-online').textContent  = `${totalOnline}/3`;
  document.getElementById('t-viewers').textContent = totalViewers;
  document.getElementById('t-bw').textContent      = totalBw.toFixed(2)+' Mbps';
  document.getElementById('t-req').textContent     = totalReq;
  document.getElementById('sb-status-txt').textContent = `${totalOnline}/3 edges activos`;
}

let countdown = 5;
function tick(){
  countdown--;
  document.getElementById('countdown').textContent = countdown;
  if(countdown <= 0){ countdown=5; fetchData(); }
}
setInterval(tick, 1000);

function fetchData(){
  document.getElementById('spin-icon').style.display='inline-block';
  fetch('ng_edges.php?api=1')
    .then(r=>r.json())
    .then(data=>{ renderEdges(data); })
    .catch(e=>{ console.error(e); })
    .finally(()=>{ document.getElementById('spin-icon').style.display='none'; });
}

// Load immediately
fetchData();
</script>
</body></html>
