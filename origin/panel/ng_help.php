<?php
session_start();
if(empty($_SESSION["ng_auth"])){header("Location: ng_manager.php");exit;}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>&#10067; Ayuda / Guia</title><style>
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
.content{padding:24px;max-width:920px}.section{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:22px;margin-bottom:18px}.section h2{font-size:1rem;color:#38bdf8;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #334155}.tip{display:flex;gap:14px;padding:10px 0;border-bottom:1px solid #0f172a;font-size:.88rem;color:#94a3b8;line-height:1.5}.tip:last-child{border-bottom:none}.dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;margin-top:4px}table{width:100%;border-collapse:collapse;font-size:.84rem}th{background:#0f172a;padding:8px 12px;text-align:left;color:#64748b;font-size:.75rem;text-transform:uppercase}td{padding:8px 12px;border-bottom:1px solid #0f172a;color:#94a3b8}tr:last-child td{border-bottom:none}</style></head><body><div class="sidebar" id="sidebar">
<div class="sb-logo"><span>&#9889;</span><span class="sb-logo-text">Nginx Stream</span>
<button class="sb-toggle" onclick="toggleSidebar()">&#9776;</button></div>
<div class="sb-user"><div class="sb-clock" id="sb-clock">--:--:--</div></div>
<nav class="sb-nav">
<a class="sb-item" href="ng_dashboard.php"><span class="sb-icon">&#128202;</span><span class="sb-label">Dashboard</span></a>
<a class="sb-item" href="ng_manager.php"><span class="sb-icon">&#128225;</span><span class="sb-label">Canales</span></a>
<a class="sb-item active" href="ng_help.php"><span class="sb-icon">&#10067;</span><span class="sb-label">Ayuda</span></a>
<a class="sb-item" href="ng_edges.php">
      <span class="sb-icon">🌐</span><span class="sb-label">Edges</span>
    </a>
    <a class="sb-item" href="ng_settings.php"><span class="sb-icon">&#9881;</span><span class="sb-label">Configuracion</span></a>
</nav>
<button class="sb-logout" onclick="location.href='ng_manager.php?logout'">&#128682; <span class="sb-label">Salir</span></button>
</div><div class="main-wrap"><div class="topbar"><h1>&#10067; Ayuda / Guia</h1></div><div class="content">
<div class="section"><h2>&#128308; Indicador HLS - Colores</h2>
<div class="tip"><div class="dot" style="background:#22c55e"></div><div><strong style="color:#e2e8f0">Verde (0-4s)</strong> - Stream perfecto. Segmentos en tiempo real.</div></div>
<div class="tip"><div class="dot" style="background:#f59e0b"></div><div><strong style="color:#e2e8f0">Amarillo (5-14s)</strong> - Retraso leve. Puede ser momentaneo. Monitorear.</div></div>
<div class="tip"><div class="dot" style="background:#ef4444"></div><div><strong style="color:#e2e8f0">Rojo (15s+)</strong> - Problema. Stream cortado o fuente fallo. Requiere atencion.</div></div>
<div class="tip"><div class="dot" style="background:#334155"></div><div><strong style="color:#e2e8f0">Sin datos</strong> - Canal no esta enviando a nginx (puede estar en Wowza).</div></div>
</div>
<div class="section"><h2>&#128268; Estados del canal</h2>
<table><tr><th>Estado</th><th>Significado</th><th>Accion</th></tr>
<tr><td style="color:#22c55e">RUNNING nginx</td><td>Activo en nginx, generando HLS</td><td>Ninguna</td></tr>
<tr><td style="color:#a78bfa">EN WOWZA</td><td>Activo en Wowza, nginx pausado</td><td>Toggle para cambiar</td></tr>
<tr><td style="color:#ef4444">STOPPED</td><td>Proceso detenido</td><td>Click Start</td></tr>
<tr><td style="color:#f97316">BACKOFF</td><td>Fallando repetidamente</td><td>Revisar URL fuente</td></tr>
</table></div>
<div class="section"><h2>&#127760; Puertos del sistema</h2>
<table><tr><th>Puerto</th><th>Servicio</th><th>Descripcion</th></tr>
<tr><td style="color:#38bdf8">1935</td><td>Wowza RTMP</td><td>Entrada streams Wowza</td></tr>
<tr><td style="color:#38bdf8">1936</td><td>nginx RTMP</td><td>Entrada streams nginx</td></tr>
<tr><td style="color:#38bdf8">8090</td><td>nginx HLS</td><td>Salida HLS del sistema nginx</td></tr>
<tr><td style="color:#38bdf8">8087</td><td>Wowza REST API</td><td>API administracion Wowza</td></tr>
<tr><td style="color:#38bdf8">80</td><td>Apache</td><td>Este panel de administracion</td></tr>
</table></div>
<div class="section"><h2>&#128257; Como funciona el switch Wowza / nginx</h2>
<div class="tip"><div class="dot" style="background:#38bdf8"></div><div>Al cambiar, FFmpeg se detiene, se copia la plantilla correcta a supervisord (conf.d), y se reinicia. Solo UNA conexion a la fuente en todo momento.</div></div>
<div class="tip"><div class="dot" style="background:#38bdf8"></div><div>Plantillas: <code style="color:#38bdf8">/etc/supervisor/library/nginx/fxNNNN.conf</code> y <code style="color:#38bdf8">/etc/supervisor/library/wowza/fxNNNN.conf</code></div></div>
</div></div></div><script>
function toggleSidebar(){var s=document.getElementById('sidebar');s.classList.toggle('collapsed');localStorage.setItem('sb_c',s.classList.contains('collapsed'));}
if(localStorage.getItem('sb_c')==='true')document.getElementById('sidebar').classList.add('collapsed');
setInterval(function(){var e=document.getElementById('sb-clock');if(e)e.textContent=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});},1000);
</script></body></html>