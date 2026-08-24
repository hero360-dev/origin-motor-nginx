<?php
session_start();
if(empty($_SESSION["ng_auth"])){header("Location: ng_manager.php");exit;}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Configuracion - Nginx Stream</title>
<style>
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
.sb-logout:hover{background:#7f1d1d22}
.main-wrap{margin-left:var(--sb-w);flex:1;transition:margin-left .25s;min-width:0}
.sidebar.collapsed~.main-wrap{margin-left:58px}
.topbar{background:#1e293b;border-bottom:1px solid #334155;padding:13px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar h1{font-size:1.15rem;color:#38bdf8}
.content{padding:24px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:18px;text-align:center}
.card .val{font-size:2rem;font-weight:800;margin-bottom:4px}
.card .lbl{font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
.chart-box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:18px}
.chart-box h3{font-size:.85rem;color:#94a3b8;margin-bottom:14px}
.ch-list{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:18px}
.ch-list h3{font-size:.85rem;color:#94a3b8;margin-bottom:12px}
.ch-row{display:grid;grid-template-columns:100px 1fr 100px 80px;gap:8px;padding:7px 0;border-bottom:1px solid #0f172a;font-size:.82rem;align-items:center}
.ch-row:last-child{border-bottom:none}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.content{padding:24px;max-width:920px}.section{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:22px;margin-bottom:18px}.section h2{font-size:1rem;color:#38bdf8;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #334155}.info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #0f172a;font-size:.85rem}.info-row:last-child{border-bottom:none}.info-lbl{color:#64748b}.info-val{color:#e2e8f0;font-family:monospace;font-size:.82rem;background:#0f172a;padding:3px 10px;border-radius:5px;max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.badge-ok{color:#22c55e;background:#22c55e15;padding:3px 10px;border-radius:10px;font-size:.78rem}.badge-off{color:#ef4444;background:#ef444415;padding:3px 10px;border-radius:10px;font-size:.78rem}</style></head><body><div class="sidebar" id="sidebar">
<div class="sb-logo"><span>&#9889;</span><span class="sb-logo-text">Nginx Stream</span>
<button class="sb-toggle" onclick="toggleSidebar()">&#9776;</button></div>
<div class="sb-user"><div class="sb-clock" id="sb-clock">--:--:--</div>
<div class="section"><h2></div></div>#128273; Token de Seguridad nginx</h2>
<div class="info-row"><span class="info-lbl">Secreto actual</span><span class="info-val" id="token-secret-display">Cargando...</span></div>
<div class="info-row"><span class="info-lbl">Algoritmo</span><span class="info-val">MD5 + IP + tiempo (nginx secure_link)</span></div>
<div class="info-row"><span class="info-lbl">Validez</span><span class="info-val">2 horas por sesion</span></div>
<div class="info-row"><span class="info-lbl">Edges protegidos</span><span class="info-val">Edge1, Edge2, Edge3 (.m3u8)</span></div>
<div style="margin-top:12px;display:flex;gap:10px;align-items:center">
<button id="btn-regen" onclick="regenToken()" style="background:#d97706;color:#fff;border:none;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer">Regenerar secreto</button>
<span id="regen-status" style="color:#64748b;font-size:.8rem"></span>
</div>
<p style="color:#334155;font-size:.72rem;margin-top:6px">* Doble clic requerido. Actualiza origin y sincroniza a los 3 edges via SSH.</p>
</div>
</div></div>
<nav class="sb-nav">
<a class="sb-item" href="ng_dashboard.php"><span class="sb-icon">&#128202;</span><span class="sb-label">Dashboard</span></a>
<a class="sb-item" href="ng_manager.php"><span class="sb-icon">&#128225;</span><span class="sb-label">Canales</span></a>
<a class="sb-item" href="ng_help.php"><span class="sb-icon">&#10067;</span><span class="sb-label">Ayuda</span></a>
<a class="sb-item active" href="ng_settings.php"><span class="sb-icon">&#9881;</span><span class="sb-label">Configuracion</span></a>
</nav>
<button class="sb-logout" onclick="location.href='ng_manager.php?logout'">&#128682; <span class="sb-label">Salir</span></button>
</div><div class="main-wrap">
<div class="topbar"><h1>&#9881; Configuracion del sistema</h1><span style="color:#64748b;font-size:.82rem" id="cfg-upd"></span></div>
<div class="content">
<div class="section"><h2>&#127968; Directorios del sistema</h2>
<div class="info-row"><span class="info-lbl">Configs activos</span><span class="info-val">/etc/supervisor/conf.d/</span></div>
<div class="info-row"><span class="info-lbl">Plantillas nginx</span><span class="info-val">/etc/supervisor/library/nginx/</span></div>
<div class="info-row"><span class="info-lbl">Plantillas Wowza</span><span class="info-val">/etc/supervisor/library/wowza/</span></div>
<div class="info-row"><span class="info-lbl">HLS segments</span><span class="info-val">/var/lib/nginx-hls/</span></div>
<div class="info-row"><span class="info-lbl">Logs supervisor</span><span class="info-val">/var/log/supervisor/</span></div>
</div>
<div class="section" id="srv-status"><h2>&#128268; Estado de servicios</h2>
<div id="srv-rows"><span style="color:#475569">Cargando...</span></div>
</div>
<div class="section"><h2>&#128279; Conexion MySQL (Tunel SSH)</h2>
<div id="mysql-status"><span style="color:#475569">Verificando...</span></div>
</div>
<div class="section"><h2>&#127894; Sesion</h2>
<div class="info-row"><span class="info-lbl">Usuario activo</span><span class="info-val">ngadmin</span></div>
<div class="info-row"><span class="info-lbl">Timeout inactividad</span><span class="info-val">2 horas</span></div>
<div class="info-row"><span class="info-lbl">Auto-refresh panel</span><span class="info-val">Cada 5 segundos</span></div>
</div>
</div>
<div class="section"><h2></div></div>#128273; Token de Seguridad nginx</h2>
<div class="info-row"><span class="info-lbl">Secreto actual</span><span class="info-val" id="token-secret-display">Cargando...</span></div>
<div class="info-row"><span class="info-lbl">Algoritmo</span><span class="info-val">MD5 + IP + tiempo (nginx secure_link)</span></div>
<div class="info-row"><span class="info-lbl">Validez</span><span class="info-val">2 horas por sesion</span></div>
<div class="info-row"><span class="info-lbl">Edges protegidos</span><span class="info-val">Edge1, Edge2, Edge3 (.m3u8)</span></div>
<div style="margin-top:12px;display:flex;gap:10px;align-items:center">
<button id="btn-regen" onclick="regenToken()" style="background:#d97706;color:#fff;border:none;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer">Regenerar secreto</button>
<span id="regen-status" style="color:#64748b;font-size:.8rem"></span>
</div>
<p style="color:#334155;font-size:.72rem;margin-top:6px">* Doble clic requerido. Actualiza origin y sincroniza a los 3 edges via SSH.</p>
</div>
</div></div>
<script>
function toggleSidebar(){var s=document.getElementById('sidebar');s.classList.toggle('collapsed');localStorage.setItem('sb_c',s.classList.contains('collapsed'));}
if(localStorage.getItem('sb_c')==='true')document.getElementById('sidebar').classList.add('collapsed');
setInterval(function(){var e=document.getElementById('sb-clock');if(e)e.textContent=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});},1000);
</script>
<script>
async function loadStatus(){
  try{
    var r = await fetch('ng_manager.php?api=stats',{credentials:'same-origin'});
    var d = await r.json();
    var total = Object.keys(d).length;
    var run = Object.values(d).filter(function(x){return x.status==='RUNNING'&&x.system==='nginx';}).length;
    var wow = Object.values(d).filter(function(x){return x.system==='wowza';}).length;
    document.getElementById('srv-rows').innerHTML =
      '<div class="info-row"><span class="info-lbl">WowzaStreamingEngine</span><span class="badge-ok">RUNNING</span></div>' +
      '<div class="info-row"><span class="info-lbl">nginx-rtmp</span><span class="badge-ok">RUNNING</span></div>' +
      '<div class="info-row"><span class="info-lbl">Supervisord</span><span class="badge-ok">RUNNING</span></div>' +
      '<div class="info-row"><span class="info-lbl">Canales nginx activos</span><span class="info-val">'+run+' / '+total+'</span></div>' +
      '<div class="info-row"><span class="info-lbl">Canales en Wowza</span><span class="info-val">'+wow+'</span></div>';
  }catch(e){document.getElementById('srv-rows').innerHTML='<span style="color:#ef4444">Error al conectar</span>';}
  // MySQL check
  try{
    var m = await fetch('ng_manager.php?api=stats',{credentials:'same-origin'});
    document.getElementById('mysql-status').innerHTML =
      '<div class="info-row"><span class="info-lbl">Host</span><span class="info-val">127.0.0.1:3307 (tunel SSH)</span></div>' +
      '<div class="info-row"><span class="info-lbl">Base de datos</span><span class="info-val">supertv</span></div>' +
      '<div class="info-row"><span class="info-lbl">Servidor remoto</span><span class="info-val">192.200.100.226</span></div>' +
      '<div class="info-row"><span class="info-lbl">Servicio tunel</span><span class="badge-ok">mysql-tunnel.service</span></div>';
  }catch(e){}
  document.getElementById('cfg-upd').textContent = new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
loadStatus();

// Cargar secreto enmascarado
async function loadTokenSecret() {
  try {
    const r = await fetch('ng_token_info.php');
    const d = await r.json();
    if(d.masked) document.getElementById('token-secret-display').textContent = d.masked;
  } catch(e) {}
}

let regenClick = 0;
async function regenToken() {
  regenClick++;
  const btn = document.getElementById('btn-regen');
  const st  = document.getElementById('regen-status');
  if(regenClick === 1){
    st.textContent = 'Haz clic una vez mas para confirmar.';
    setTimeout(()=>{ regenClick=0; st.textContent=''; }, 5000);
    return;
  }
  regenClick = 0;
  btn.disabled = true; st.textContent = 'Regenerando...';
  try {
    const r = await fetch('ng_token_regen.php', {method:'POST', credentials:'same-origin'});
    const d = await r.json();
    if(d.success){
      st.textContent = 'Secreto regenerado y sincronizado a edges ✓';
      st.style.color = '#22c55e';
      loadTokenSecret();
    } else {
      st.textContent = 'Error: ' + (d.error||'desconocido');
      st.style.color = '#ef4444';
    }
  } catch(e){ st.textContent = 'Error de red'; st.style.color='#ef4444'; }
  finally { btn.disabled=false; }
}
loadStatus();
loadTokenSecret();
</script></body></html>
