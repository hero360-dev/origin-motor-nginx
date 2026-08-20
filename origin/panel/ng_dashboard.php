<?php
session_start();
if(empty($_SESSION['ng_auth'])){header('Location: ng_manager.php');exit;}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard - Nginx Stream</title><style>
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
</style></head><body>
<div class="sidebar" id="sidebar">
  <div class="sb-logo"><span class="sb-logo-icon">&#9889;</span><span class="sb-logo-text">Nginx Stream</span>
    <button class="sb-toggle" onclick="toggleSidebar()">&#9776;</button></div>
  <div class="sb-user"><div class="sb-user-info">ngadmin</div><div class="sb-clock" id="sb-clock">--:--:--</div></div>
  <nav class="sb-nav">
    <a class="sb-item active" href="ng_dashboard.php"><span class="sb-icon">&#128202;</span><span class="sb-label">Dashboard</span></a>
    <a class="sb-item" href="ng_manager.php"><span class="sb-icon">&#128225;</span><span class="sb-label">Canales</span></a>
    <a class="sb-item" href="ng_help.php"><span class="sb-icon">&#10067;</span><span class="sb-label">Ayuda / Guia</span></a>
    <a class="sb-item" href="ng_edges.php">
      <span class="sb-icon">🌐</span><span class="sb-label">Edges</span>
    </a>
    <a class="sb-item" href="ng_settings.php"><span class="sb-icon">&#9881;</span><span class="sb-label">Configuracion</span></a>
  </nav>
  <button class="sb-logout" onclick="location.href='ng_manager.php?logout'">&#128682; <span class="sb-label">Salir</span></button>
</div>
<div class="main-wrap">
<div class="topbar"><h1>&#128202; Dashboard</h1><span style="color:#64748b;font-size:.82rem" id="upd"></span></div>
<div class="content">
<div class="grid">
<div class="card"><div class="val" id="d-total" style="color:#38bdf8">-</div><div class="lbl">Canales</div></div>
<div class="card"><div class="val" id="d-run" style="color:#22c55e">-</div><div class="lbl">nginx activo</div></div>
<div class="card"><div class="val" id="d-wow" style="color:#a78bfa">-</div><div class="lbl">En Wowza</div></div>
<div class="card"><div class="val" id="d-stop" style="color:#ef4444">-</div><div class="lbl">Detenidos</div></div>
<div class="card"><div class="val" id="d-bw" style="color:#f59e0b">-</div><div class="lbl">Bitrate total</div></div>
<div class="card"><div class="val" id="d-views" style="color:#34d399">-</div><div class="lbl">Viewers</div></div>
</div>
<div class="charts-grid">
<div class="chart-box"><h3>&#128200; Bitrate total (ultimos 60s)</h3><canvas id="bw-chart" height="180"></canvas></div>
<div class="chart-box"><h3>&#127775; Estado canales</h3><canvas id="donut-chart" height="180"></canvas></div>
</div>
<div class="ch-list"><h3>&#128225; Detalle por canal</h3>
<div class="ch-row" style="color:#475569;font-size:.72rem;border-bottom:1px solid #334155;padding-bottom:6px;margin-bottom:4px"><span>CANAL</span><span>SISTEMA</span><span>BITRATE</span><span>HLS</span></div>
<div id="ch-table"></div>
</div>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar(){var s=document.getElementById('sidebar');s.classList.toggle('collapsed');localStorage.setItem('sb_c',s.classList.contains('collapsed'));}
if(localStorage.getItem('sb_c')==='true')document.getElementById('sidebar').classList.add('collapsed');
setInterval(function(){var e=document.getElementById('sb-clock');if(e)e.textContent=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});},1000);
var bwHist=[],bwChart,donut;
function fmtBw(b){var k=Math.round(b/1024);return k>=1000?(k/1024).toFixed(1)+' Mbps':k+' kbps';}
function init(){
  var bx=document.getElementById('bw-chart').getContext('2d');
  bwChart=new Chart(bx,{type:'line',data:{labels:[],datasets:[{label:'kbps',data:[],borderColor:'#38bdf8',backgroundColor:'rgba(56,189,248,.12)',fill:true,tension:.4,pointRadius:0}]},options:{animation:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{color:'#475569'},grid:{color:'#1e293b22'}},x:{ticks:{color:'#475569',maxTicksLimit:8},grid:{display:false}}}}});
  var dx=document.getElementById('donut-chart').getContext('2d');
  donut=new Chart(dx,{type:'doughnut',data:{labels:['nginx','Wowza','Detenido'],datasets:[{data:[0,0,0],backgroundColor:['#22c55e','#7c3aed','#ef4444'],borderWidth:0}]},options:{plugins:{legend:{labels:{color:'#94a3b8',font:{size:12}}}}}});
}
async function refresh(){
  try{
    var r=await fetch('ng_manager.php?api=stats',{credentials:'same-origin'});
    var data=await r.json();
    var total=0,run=0,wow=0,stop=0,bwT=0,views=0,rows='';
    for(var ch in data){
      var d=data[ch];total++;
      if(d.system==='wowza')wow++;
      else if(d.status==='RUNNING'){run++;bwT+=d.bw_total;views+=d.clients;}
      else stop++;
      var cl=d.system==='wowza'?'#a78bfa':d.status==='RUNNING'?'#22c55e':'#ef4444';
      var st=d.system==='wowza'?'Wowza':d.status==='RUNNING'?'nginx':d.status;
      var age=d.hls_age>=0&&d.hls_age<5?'<span style="color:#22c55e">LIVE</span>':d.hls_age>=0?'<span style="color:#64748b">'+d.hls_age+'s</span>':'<span style="color:#334155">-</span>';
      rows+='<div class="ch-row"><span style="font-weight:700">'+ch+'</span><span style="color:'+cl+'">'+st+'</span><span style="color:#94a3b8">'+fmtBw(d.bw_total)+'</span>'+age+'</div>';
    }
    document.getElementById('d-total').textContent=total;
    document.getElementById('d-run').textContent=run;
    document.getElementById('d-wow').textContent=wow;
    document.getElementById('d-stop').textContent=stop;
    document.getElementById('d-bw').textContent=fmtBw(bwT);
    document.getElementById('d-views').textContent=views;
    document.getElementById('ch-table').innerHTML=rows;
    var t=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    bwHist.push({t:t,v:Math.round(bwT/1024)});if(bwHist.length>60)bwHist.shift();
    bwChart.data.labels=bwHist.map(function(x){return x.t;});
    bwChart.data.datasets[0].data=bwHist.map(function(x){return x.v;});
    bwChart.update('none');
    donut.data.datasets[0].data=[run,wow,stop];donut.update('none');
    document.getElementById('upd').textContent='Actualizado: '+t;
  }catch(e){}
}
init();refresh();setInterval(refresh,5000);
</script></body></html>