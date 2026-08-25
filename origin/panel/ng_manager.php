<?php
session_start();

// ─── CONFIG ───────────────────────────────────────────────────────────────
define('NG_USER', 'ngadmin');
define('NG_PASS', 'Str3am@2026!');
define('SESSION_TIMEOUT', 7200); // 2 hours
define('NGINX_DIR', '/etc/supervisor/nginx-streams.d');
define('ACTIVE_DIR', '/etc/supervisor/conf.d');
define('LIB_NGINX', '/etc/supervisor/library/nginx');
define('LIB_WOWZA', '/etc/supervisor/library/wowza');
define('WOWZA_DIR', '/etc/supervisor/conf.d');
define('SCRIPTS_DIR', '/usr/local/bin');
define('HLS_BASE', '/var/lib/nginx-hls');
define('SECRETS_FILE', '/etc/casino-secrets.env');

// ─── SESSION TIMEOUT ──────────────────────────────────────────────────────
if (!empty($_SESSION['ng_auth'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        header('Location: ng_manager.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ─── AUTH ─────────────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    if ($_POST['user'] === NG_USER && $_POST['pass'] === NG_PASS) {
        $_SESSION['ng_auth'] = true;
        $_SESSION['last_activity'] = time();
        header('Location: ng_manager.php'); exit;
    } else { $login_err = 'Usuario o contraseña incorrectos'; }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ng_manager.php'); exit; }
if (!empty($_SESSION['ng_auth'])) $_SESSION['last_activity'] = time();

// ─── HELPERS ──────────────────────────────────────────────────────────────
function load_secrets() {
    $s = [];
    if (!file_exists(SECRETS_FILE)) return $s;
    foreach (file(SECRETS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line,'=') !== false) {
            [$k,$v] = explode('=', $line, 2);
            $s[trim($k)] = trim($v, " \"'\t");
        }
    }
    return $s;
}

function get_mysql_names() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $s = load_secrets();
    if (empty($s['MYSQL_TUNNEL_HOST'])) return $cache;
    try {
        $dsn = "mysql:host={$s['MYSQL_TUNNEL_HOST']};port={$s['MYSQL_TUNNEL_PORT']};dbname={$s['MYSQL_TUNNEL_DB']};charset=utf8";
        $pdo = new PDO($dsn, $s['MYSQL_TUNNEL_USER'], $s['MYSQL_TUNNEL_PASS'],
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Cargar nombres de AMBAS tablas (lat = canales generales, porn = canales adultos)
        $stmt = $pdo->query("
            SELECT st_before, descripcion FROM stvchannels_lat  WHERE st_before IS NOT NULL
            UNION ALL
            SELECT st_before, descripcion FROM stvchannels_porn WHERE st_before IS NOT NULL
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$row['st_before']] = $row['descripcion'];
        }
    } catch (Exception $e) { /* tunnel not active */ }
    return $cache;
}

function get_channels() {
    // Leer TODOS los fx*.conf del directorio activo (conf.d)
    $files = glob(ACTIVE_DIR . '/fx*.conf') ?: [];
    $channels = [];
    foreach ($files as $f) {
        $name = preg_replace('/\.conf$/', '', basename($f));
        if (preg_match('/^fx\d+$/', $name) && !in_array($name, $channels))
            $channels[] = $name;
    }
    sort($channels);
    return $channels;
}

function get_all_supervisord_statuses() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $out = shell_exec("sudo supervisorctl status 2>&1") ?? '';
    foreach (explode("
", $out) as $line) {
        if (preg_match('/^(\S+)\s+(\S+)/', $line, $m)) {
            $name = $m[1]; $status = $m[2];
            $cache[$name] = $status;
        }
    }
    return $cache;
}

function get_supervisord_status($ch) {
    $all = get_all_supervisord_statuses();
    // New single-file approach: program name = channel name
    if (isset($all[$ch])) return $all[$ch];
    // Legacy ng- approach fallback
    return $all["ng-$ch"] ?? 'UNKNOWN';
}

function get_nginx_stats() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $xml = @file_get_contents('http://localhost:8090/stat');
    if (!$xml) return $cache;
    $dom = @simplexml_load_string($xml);
    if (!$dom) return $cache;
    foreach ($dom->server->application ?? [] as $app) {
        foreach ($app->live->stream ?? [] as $stream) {
            $name = (string)$stream->name;
            $bw_v = (int)$stream->bw_video;
            $bw_a = (int)$stream->bw_audio;
            $cache[$name] = [
                'bw_video' => $bw_v,
                'bw_audio' => $bw_a,
                'bw_total' => $bw_v + $bw_a,
                'clients'  => (int)$stream->nclients,
            ];
        }
    }
    return $cache;
}

function get_all_live_viewers() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['viewers' => [], 'bytes' => []];
    $log = '/var/log/nginx/access.log';
    if (!is_readable($log)) return $cache;
    $since_v = time() - 12;
    $since_b = time() - 30;
    $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
               'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
    $loglines = shell_exec("tail -n 5000 " . escapeshellarg($log) . " 2>/dev/null");
    if (!$loglines) return $cache;
    foreach (explode("\n", $loglines) as $line) {
        if (strpos($line, '/hls/') === false) continue;
        if (!preg_match('/^(\S+) \S+ \S+ \[(\d{2})\/(\w+)\/(\d{4}):(\d{2}):(\d{2}):(\d{2}).*?" \d+ (\d+)/', $line, $m)) continue;
        $mon = $months[$m[3]] ?? 1;
        $ts = mktime((int)$m[5], (int)$m[6], (int)$m[7], $mon, (int)$m[2], (int)$m[4]);
        if (!preg_match('#/hls/([^/]+)/#', $line, $ch)) continue;
        $channel = $ch[1];
        if ($ts >= $since_v && strpos($line, 'index.m3u8') !== false) {
            $cache['viewers'][$channel][$m[1]] = true;
        }
        if ($ts >= $since_b && preg_match('#/hls/[^/]+/\d+\.ts#', $line)) {
            $cache['bytes'][$channel] = ($cache['bytes'][$channel] ?? 0) + (int)$m[8];
        }
    }
    return $cache;
}
function get_hls_viewers($ch) {
    $all = get_all_live_viewers();
    return isset($all['viewers'][$ch]) ? count($all['viewers'][$ch]) : 0;
}
function get_hls_output_bw($ch) {
    $all = get_all_live_viewers();
    return (int)(($all['bytes'][$ch] ?? 0) * 8 / 30);
}

function get_hls_info($ch) {
    $dir = HLS_BASE . "/$ch";
    if (!is_dir($dir)) return ['segments'=>0,'age'=>-1];
    $files = glob("$dir/*.ts");
    if (!$files) return ['segments'=>0,'age'=>-1];
    $latest = max(array_map('filemtime', $files));
    return ['segments'=>count($files), 'age'=>time()-$latest];
}

function get_source_url($ch) {
    $conf = ACTIVE_DIR . "/$ch.conf";
    if (!file_exists($conf)) return '';
    $content = file_get_contents($conf);
    if (preg_match('/-i\s+(https?:\/\/\S+)/', $content, $m)) return $m[1];
    return '';
}

function generate_nginx_conf(string $ch, string $active_content): string {
    // Genera conf para nginx reemplazando el destino RTMP
    $conf = $active_content;
    // Normalizar audio: quitar -strict -2 y agregar bitrate/sample si falta
    $conf = preg_replace('/-c:a aac -strict -2/', '-c:a aac -b:a 128k -ar 48000 -ac 2', $conf);
    // Reemplazar destino RTMP (Wowza → nginx local)
    // Patrones: rtmp://USER:PASS@host:1935/APPNAME/myStream
    $conf = preg_replace(
        '/-f flv rtmp:\/\/[^ \n]+/',
        "-f flv rtmp://127.0.0.1:1936/live/$ch",
        $conf
    );
    return $conf;
}

function generate_wowza_conf(string $ch, string $active_content): string {
    // Genera conf para Wowza reemplazando el destino RTMP
    $conf = $active_content;
    // Normalizar audio para Wowza
    $conf = preg_replace('/-c:a aac -b:a 128k -ar 48000 -ac 2/', '-c:a aac -strict -2', $conf);
    // Reemplazar destino nginx → Wowza
    $conf = preg_replace(
        '/-f flv rtmp:\/\/127\.0\.0\.1:1936\/live\/\S+/',
        "-f flv rtmp://prov:prov001@localhost:1935/$ch/myStream",
        $conf
    );
    return $conf;
}

function is_push_channel($ch) {
    static $cache = [];
    if (isset($cache[$ch])) return $cache[$ch];
    $conf = ACTIVE_DIR . "/$ch.conf";
    if (!file_exists($conf)) { $cache[$ch]=false; return false; }
    $content = file_get_contents($conf);
    $cache[$ch] = strpos($content, 'TYPE=push') !== false
               || strpos($content, '/bin/false') !== false;
    return $cache[$ch];
}

function has_wowza_config($ch) {
    return file_exists(WOWZA_DIR . "/$ch.conf") || file_exists(LIB_WOWZA . "/$ch.conf");
}

function get_channel_system($ch) {
    // Read active conf to determine current system
    $active = ACTIVE_DIR . "/$ch.conf";
    if (!file_exists($active)) return 'nginx'; // default
    $content = file_get_contents($active);
    if (strpos($content, ':1935') !== false) return 'wowza';
    if (strpos($content, ':1936') !== false) return 'nginx';
    return 'nginx';
}

function get_wowza_status($ch) {
    if (!has_wowza_config($ch)) return null;
    $all = get_all_supervisord_statuses();
    $st  = $all[$ch] ?? 'OTHER';
    if ($st === 'RUNNING') return 'RUNNING';
    if ($st === 'STOPPED') return 'STOPPED';
    return 'OTHER';
}

function format_bw($bps) {
    $kbps = round($bps / 1024);
    if ($kbps >= 1000) return round($kbps / 1024, 1) . ' Mbps';
    return $kbps . ' kbps';
}

function bw_color($bps) {
    $kbps = $bps / 1024;
    if ($kbps < 50)  return '#ef4444';
    if ($kbps < 300) return '#f97316';
    if ($kbps < 800) return '#eab308';
    return '#22c55e';
}

function bw_pct($bps) {
    $kbps = $bps / 1024;
    return min(100, round($kbps / 30)); // 3000 kbps = 100%
}

// ─── API ENDPOINTS ────────────────────────────────────────────────────────
if (!empty($_SESSION['ng_auth']) && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'];

    if ($api === 'stats') {
        $channels = get_channels();
        $ng_stats = get_nginx_stats();
        $result = [];
        foreach ($channels as $ch) {
            $st = get_supervisord_status($ch);
            $ngs = $ng_stats[$ch] ?? ['bw_video'=>0,'bw_audio'=>0,'bw_total'=>0,'clients'=>0];
            $hls = get_hls_info($ch);
            $result[$ch] = [
                'status'    => $st,
                'bw_video'  => $ngs['bw_video'],
                'bw_audio'  => $ngs['bw_audio'],
                'bw_total'  => $ngs['bw_total'],
                'bw_v_fmt'  => format_bw($ngs['bw_video']),
                'bw_a_fmt'  => format_bw($ngs['bw_audio']),
                'bw_pct'    => bw_pct($ngs['bw_total']),
                'bw_color'  => bw_color($ngs['bw_total']),
                'clients'   => get_hls_viewers($ch),
                'bw_out'    => get_hls_output_bw($ch),
                'hls_segs'  => $hls['segments'],
                'hls_age'   => $hls['age'],
                'wowza_st'  => get_wowza_status($ch),
                'system'    => get_channel_system($ch),
            ];
        }
        echo json_encode($result);
        exit;
    }

    if ($api === 'delete' && isset($_GET['ch'])) {
        $ch = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ch']);
        shell_exec("sudo supervisorctl stop ng-$ch > /dev/null 2>&1");
        @unlink(NGINX_DIR . "/ng-$ch.conf");
        @unlink(SCRIPTS_DIR . "/ng-$ch.sh");
        shell_exec("(sleep 1 && sudo supervisorctl reread && sudo supervisorctl update) > /dev/null 2>&1 &");
        shell_exec("rm -rf " . HLS_BASE . "/$ch");
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($api === 'switch' && isset($_GET['ch'], $_GET['target'])) {
        $ch     = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ch']);
        $target = $_GET['target'] === 'wowza' ? 'wowza' : 'nginx';
        $result = ['ok'=>false,'msg'=>''];
        if ($target === 'wowza') {
            $lib    = LIB_WOWZA . "/$ch.conf";
            $active = ACTIVE_DIR . "/$ch.conf";
            // Auto-generar template Wowza si no existe
            if (!file_exists($lib) && file_exists($active)) {
                $src = file_get_contents($active);
                $wowza_conf = generate_wowza_conf($ch, $src);
                @file_put_contents($lib, $wowza_conf);
                @chmod($lib, 0644);
            }
            if (file_exists($lib)) {
                shell_exec("sudo supervisorctl stop $ch > /dev/null 2>&1");
                shell_exec("sudo cp " . escapeshellarg($lib) . " " . escapeshellarg($active) . " 2>/dev/null");
                shell_exec("(sudo supervisorctl reread && sudo supervisorctl update $ch && sudo supervisorctl start $ch) > /dev/null 2>&1 &");
            }
            $result = ['ok'=>true,'msg'=>"$ch → Wowza"];
        } elseif ($target === 'nginx') {
            $lib        = LIB_NGINX . "/$ch.conf";
            $active     = ACTIVE_DIR . "/$ch.conf";
            $lib_wowza  = LIB_WOWZA . "/$ch.conf";
            // Guardar snapshot Wowza en library si no existe (para poder revertir)
            if (file_exists($active) && !file_exists($lib_wowza)) {
                $wowza_content = file_get_contents($active);
                // Solo guardar si es conf de Wowza
                if (strpos($wowza_content, ':1935') !== false) {
                    @file_put_contents($lib_wowza, $wowza_content);
                    @chmod($lib_wowza, 0644);
                }
            }
            // Auto-generar template nginx si no existe
            if (!file_exists($lib) && file_exists($active)) {
                $src = file_get_contents($active);
                $nginx_conf = generate_nginx_conf($ch, $src);
                @file_put_contents($lib, $nginx_conf);
                @chmod($lib, 0644);
            }
            if (file_exists($lib)) {
                shell_exec("sudo supervisorctl stop $ch > /dev/null 2>&1");
                shell_exec("sudo cp " . escapeshellarg($lib) . " " . escapeshellarg($active) . " 2>/dev/null");
                shell_exec("(sudo supervisorctl reread && sudo supervisorctl update $ch && sudo supervisorctl start $ch) > /dev/null 2>&1 &");
                $result = ['ok'=>true,'msg'=>"$ch → nginx"];
            } else {
                $result = ['ok'=>false,'msg'=>"$ch: no se pudo generar conf nginx"];
            }
        }
        echo json_encode($result);
        exit;
    }

    if ($api === 'switch_bulk' && isset($_GET['target'], $_GET['channels'])) {
        $target   = $_GET['target'] === 'wowza' ? 'wowza' : 'nginx';
        $channels = array_map(fn($c) => preg_replace('/[^a-zA-Z0-9_-]/','',trim($c)),
                              explode(',', $_GET['channels']));
        $done = 0;
        foreach ($channels as $ch) {
            if (!$ch) continue;
            if ($target === 'wowza' && has_wowza_config($ch)) {
                shell_exec("sudo supervisorctl stop ng-$ch > /dev/null 2>&1 &");
                shell_exec("sudo supervisorctl start $ch > /dev/null 2>&1 &");
                $done++;
            } elseif ($target === 'nginx') {
                if (has_wowza_config($ch)) shell_exec("sudo supervisorctl stop $ch > /dev/null 2>&1 &");
                shell_exec("sudo supervisorctl start ng-$ch > /dev/null 2>&1 &");
                $done++;
            }
        }
        echo json_encode(['ok'=>true,'done'=>$done]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'unknown api']);
    exit;
}

// ─── POST ACTIONS (start/stop/restart) ────────────────────────────────────
if (!empty($_SESSION['ng_auth']) && isset($_POST['action'], $_POST['channel'])) {
    $ch = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['channel']);
    $action = $_POST['action'];
    if (in_array($action, ['start','stop','restart']) && $ch) {
        shell_exec("sudo supervisorctl $action ng-$ch > /dev/null 2>&1");
    }
    header('Location: ng_manager.php');
    exit;
}


$channels = empty($_SESSION['ng_auth']) ? [] : get_channels();
$ng_names = empty($_SESSION['ng_auth']) ? [] : get_mysql_names();
$ng_stats = empty($_SESSION['ng_auth']) ? [] : get_nginx_stats();
$timeout_msg = isset($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Nginx Stream Manager</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
a{color:#38bdf8;text-decoration:none}
/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;width:100vw;position:fixed;inset:0;z-index:999;background:#0f172a}
.login-box{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:40px;width:380px;box-shadow:0 25px 50px rgba(0,0,0,.5)}
.login-box h1{font-size:1.6rem;color:#38bdf8;text-align:center;margin-bottom:6px}
.login-box p{text-align:center;color:#64748b;margin-bottom:28px;font-size:.9rem}
.login-box input{width:100%;padding:11px 14px;background:#0f172a;border:1px solid #475569;border-radius:8px;color:#e2e8f0;font-size:.95rem;margin-bottom:12px;outline:none;transition:border .2s}
.login-box input:focus{border-color:#38bdf8}
.login-box button{width:100%;padding:12px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border:none;border-radius:8px;color:#fff;font-weight:700;font-size:1rem;cursor:pointer;transition:opacity .2s}
.login-box button:hover{opacity:.9}
.err{background:#7f1d1d;color:#fca5a5;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:.88rem}
.warn{background:#78350f;color:#fde68a;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:.88rem}
/* Header */
.topbar{background:#1e293b;border-bottom:1px solid #334155;padding:13px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar h1{font-size:1.15rem;color:#38bdf8;display:flex;align-items:center;gap:8px}
.topbar-right{display:flex;align-items:center;gap:14px}
.meta{color:#64748b;font-size:.82rem}
.logout-btn{background:#ef4444;color:#fff;padding:6px 14px;border-radius:6px;font-size:.82rem;border:none;cursor:pointer}
/* Stats bar */
.statsbar{display:flex;gap:14px;padding:16px 24px;flex-wrap:wrap}
.scard{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:14px 20px;min-width:130px;text-align:center;flex:1}
.scard .val{font-size:1.9rem;font-weight:800}
.scard .lbl{font-size:.75rem;color:#64748b;margin-top:3px;text-transform:uppercase;letter-spacing:.04em}
/* Bulk actions */
.bulk-bar{background:#1e293b;border:1px solid #334155;border-radius:10px;margin:0 24px 18px;padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.bulk-bar label{color:#94a3b8;font-size:.85rem;margin-right:4px}
.bulk-btn{padding:7px 16px;border:none;border-radius:7px;cursor:pointer;font-size:.82rem;font-weight:600;transition:all .2s}
.bb-wowza{background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed44}
.bb-nginx{background:#0ea5e922;color:#38bdf8;border:1px solid #0ea5e944}
.bb-wowza:hover{background:#7c3aed44}
.bb-nginx:hover{background:#0ea5e944}
.bb-sel{background:#0f172a;color:#94a3b8;border:1px solid #334155}
.bb-sel:hover{background:#1e293b}
.sel-info{color:#64748b;font-size:.8rem;min-width:80px}
/* Table */
.content{padding:0 24px 30px}
.section-hdr{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.section-hdr h2{color:#94a3b8;font-size:.9rem;text-transform:uppercase;letter-spacing:.06em}
.badge-sm{background:#334155;padding:2px 10px;border-radius:20px;font-size:.75rem;color:#94a3b8}
.tbl-wrap{border-radius:10px;overflow:hidden;border:1px solid #334155}
table{width:100%;border-collapse:collapse;background:#1e293b}
th{background:#0f172a;padding:10px 12px;text-align:left;font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
td{padding:9px 12px;border-bottom:1px solid #0f172a;font-size:.85rem;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#1a2540}
.chk-col{width:32px}
/* Status badge */
.sbadge-push-live{background:#dc262620;color:#f87171;padding:3px 10px;border-radius:6px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:5px}
.sbadge-push-off{background:#33415520;color:#64748b;padding:3px 10px;border-radius:6px;font-size:.8rem}
.dot-push{width:7px;height:7px;border-radius:50%;background:#ef4444;animation:pulse 1s infinite;flex-shrink:0}
.push-url-box{background:#0f172a;border:1px solid #334155;border-radius:6px;padding:5px 8px;font-size:.68rem;color:#38bdf8;font-family:monospace;word-break:break-all;margin-top:4px}
.sbadge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:.75rem;font-weight:600;white-space:nowrap}
/* Bandwidth bar */
.bw-wrap{min-width:160px}
.bw-nums{display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:4px}
.bw-bar-bg{background:#0f172a;border-radius:4px;height:8px;overflow:hidden;position:relative}
.bw-bar{height:100%;border-radius:4px;transition:width .8s ease,background .8s ease;min-width:2px}
.bw-label{font-size:.7rem;color:#64748b;margin-top:3px;text-align:right}
/* Channel name */
.ch-id{font-weight:700;font-size:.88rem}
.ch-name{color:#94a3b8;font-size:.75rem;margin-top:1px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* HLS info */
.hls-live{display:inline-flex;align-items:center;gap:5px;font-size:.78rem}
.dot-live{width:7px;height:7px;background:#22c55e;border-radius:50%;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
/* URL */
.url-cell{max-width:200px}
.url-link{font-size:.72rem;color:#38bdf8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.copy-btn{background:#1e293b;border:1px solid #334155;color:#94a3b8;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:.7rem;margin-top:3px}
.copy-btn:hover{background:#334155}
.url-row{display:flex;align-items:center;gap:5px;margin-bottom:4px}
.url-lbl{font-size:.68rem;color:#475569;min-width:38px}
.url-icon-btn{background:#1e293b;border:1px solid #334155;color:#94a3b8;padding:3px 7px;border-radius:4px;cursor:pointer;font-size:.78rem;transition:all .15s}
.url-icon-btn:hover{background:#334155;color:#e2e8f0}
.url-icon-btn.play{color:#4ade80;border-color:#14532d66}
.url-icon-btn.play:hover{background:#14532d33}
.url-icon-btn.play-src{color:#f59e0b;border-color:#78350f66}
.url-icon-btn.play-src:hover{background:#78350f33}
/* Actions */
.act-btns{display:flex;gap:5px;flex-wrap:nowrap;align-items:center}
/* Edge sub-row */
.edge-sub-row td{padding:0}
.edge-sub-td{padding:0!important;border-top:none!important}
.edge-dist-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;background:#060d18;border-bottom:1px solid #1e293b}
.edc{padding:14px 18px;border-right:1px solid #1e293b}
.edc:last-child{border-right:none}
.edc-header{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.edc-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.edc-dot.active{background:#22c55e;box-shadow:0 0 5px #22c55e88;animation:pulse 2s infinite}
.edc-dot.inactive{background:#ef4444}
.edc-dot.unknown{background:#475569}
.edc-name{font-size:.82rem;font-weight:700;color:#e2e8f0}
.edc-ip{font-size:.68rem;color:#334155;margin-left:auto}
.edc-stats-row{display:flex;gap:18px;margin-bottom:8px}
.edc-stat{display:flex;flex-direction:column;gap:2px}
.edc-stat-label{font-size:.63rem;color:#475569;text-transform:uppercase;letter-spacing:.04em}
.edc-stat-value{font-size:.98rem;font-weight:700;color:#38bdf8}
.edc-stat-value.vw-val{color:#22c55e}
.edc-bw-bg{height:4px;background:#1e293b;border-radius:2px;margin-bottom:10px;overflow:hidden}
.edc-bw-fill{height:100%;background:#38bdf8;border-radius:2px;transition:width .5s}
.edc-play-btn{background:#0ea5e9;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:.78rem;font-weight:600;cursor:pointer;width:100%;transition:background .2s}
.edc-play-btn:hover{background:#0284c7}
.search-wrap{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 14px;background:#0a1628;border:1px solid #1e293b;border-radius:10px}
.search-wrap input{flex:1;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:8px 14px;color:#e2e8f0;font-size:.88rem;outline:none;transition:border-color .2s}
.search-wrap input:focus{border-color:#38bdf8}
.search-wrap input::placeholder{color:#334155}
.search-count{font-size:.78rem;color:#475569;white-space:nowrap;flex-shrink:0}
.no-results{padding:32px;text-align:center;color:#334155;font-size:.88rem}
.edc-token-btn{background:#d97706;color:#fff;border:none;border-radius:6px;padding:6px 10px;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .2s}
.edc-token-btn:hover{background:#b45309}
.edc-token-btn:disabled{background:#334155;color:#475569;cursor:wait}
.abtn.a-edges{background:#0ea5e920;color:#38bdf8;border:1px solid #38bdf840;font-size:.75rem}
.abtn.a-edges:hover{background:#0ea5e940}
.abtn.a-edges.open{background:#38bdf820;border-color:#38bdf8;color:#7dd3fc}
.abtn{padding:5px 10px;border:none;border-radius:6px;cursor:pointer;font-size:.75rem;font-weight:600;white-space:nowrap;transition:all .2s}
.abtn:hover{opacity:.8;transform:translateY(-1px)}
.a-stop{background:#7f1d1d33;color:#f87171;border:1px solid #7f1d1d66}
.a-start{background:#14532d33;color:#4ade80;border:1px solid #14532d66}
.a-restart{background:#1e3a5f33;color:#60a5fa;border:1px solid #1e3a5f66}
.a-del{background:#1c1c1c;color:#f43f5e;border:1px solid #3f1f2a}
/* Switch toggle */
.switch-wrap{display:flex;flex-direction:column;gap:4px;min-width:100px}
.sw-label{font-size:.7rem;color:#64748b;text-align:center}
.sw-toggle{display:flex;background:#0f172a;border-radius:20px;padding:2px;border:1px solid #334155}
.sw-btn{flex:1;padding:4px 8px;border:none;border-radius:18px;cursor:pointer;font-size:.72rem;font-weight:600;background:transparent;color:#64748b;transition:all .2s;white-space:nowrap}
.sw-btn.active-wowza{background:#7c3aed;color:#fff}
.sw-btn.active-nginx{background:#0ea5e9;color:#fff}
/* Inactivity bar */
#inact-bar{position:fixed;bottom:0;left:0;right:0;background:#1e293b;border-top:1px solid #f59e0b;padding:8px 20px;display:none;align-items:center;justify-content:space-between;z-index:200}
#inact-bar span{color:#fbbf24;font-size:.85rem}
#inact-bar button{background:#f59e0b;color:#000;border:none;padding:5px 14px;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:600}
/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:300;display:none;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:28px;width:380px;max-width:90vw}
.modal h3{color:#f87171;margin-bottom:10px}
.modal p{color:#94a3b8;font-size:.9rem;margin-bottom:20px}
.modal-btns{display:flex;gap:10px;justify-content:flex-end}
.modal-btns .cancel{background:#334155;color:#e2e8f0;border:none;padding:8px 18px;border-radius:7px;cursor:pointer}
.modal-btns .confirm{background:#ef4444;color:#fff;border:none;padding:8px 18px;border-radius:7px;cursor:pointer;font-weight:600}
/* Toast */
#toast{position:fixed;top:20px;right:20px;background:#1e293b;border:1px solid #334155;border-radius:10px;padding:12px 20px;font-size:.88rem;z-index:400;opacity:0;transform:translateY(-10px);transition:all .3s;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{border-color:#22c55e;color:#4ade80}
#toast.err{border-color:#ef4444;color:#f87171}
/* Player Modal */
#player-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;display:none;align-items:center;justify-content:center}
#player-modal.show{display:flex}
.player-box{background:#0f172a;border:1px solid #334155;border-radius:14px;width:min(860px,95vw);overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.7)}
.player-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#1e293b;border-bottom:1px solid #334155}
.player-title{font-size:.9rem;color:#e2e8f0;font-weight:600}
.player-close{background:#ef444433;color:#f87171;border:none;border-radius:6px;padding:4px 12px;cursor:pointer;font-size:.85rem}
.player-close:hover{background:#ef4444;color:#fff}
#player-video{width:100%;aspect-ratio:16/9;background:#000;display:block}
.player-footer{padding:10px 18px;background:#1e293b;border-top:1px solid #334155;display:flex;align-items:center;gap:10px}
.player-url{font-size:.7rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.player-copy-url{background:#334155;color:#94a3b8;border:none;padding:4px 10px;border-radius:5px;cursor:pointer;font-size:.75rem;flex-shrink:0}
.player-copy-url:hover{background:#475569}
#player-unsupported{display:none;padding:40px 20px;text-align:center}
#player-unsupported p{color:#94a3b8;margin-bottom:16px;font-size:.9rem}
#player-unsupported .vlc-hint{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;margin-bottom:14px}
#player-unsupported .vlc-hint code{color:#38bdf8;font-size:.8rem;word-break:break-all}
#player-iframe{width:100%;height:480px;border:0;background:#000;display:none}

/* ─── SIDEBAR LAYOUT ────────────────────────────────────────────────────── */
:root { --sb-w: 220px; }
body { display: flex; }
.sidebar {
  width: var(--sb-w); min-height: 100vh; background: #0a1628;
  border-right: 1px solid #1e293b; display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; z-index: 200; transition: width .25s;
  overflow: hidden;
}
.sidebar.collapsed { width: 58px; }
.sidebar.collapsed .sb-label,
.sidebar.collapsed .sb-logo-text,
.sidebar.collapsed .sb-user-info { display: none; }
.sb-logo { display: flex; align-items: center; gap: 10px; padding: 18px 14px 14px;
  border-bottom: 1px solid #1e293b; }
.sb-logo-icon { font-size: 1.3rem; flex-shrink: 0; }
.sb-logo-text { font-size: 1rem; font-weight: 700; color: #38bdf8; white-space: nowrap; }
.sb-toggle { margin-left: auto; background: none; border: none; color: #475569;
  cursor: pointer; font-size: 1rem; padding: 2px; flex-shrink: 0; }
.sb-toggle:hover { color: #94a3b8; }
.sb-user { padding: 12px 14px; border-bottom: 1px solid #1e293b; }
.sb-user-info { font-size: .75rem; color: #475569; }
.sb-clock { font-size: .72rem; color: #334155; margin-top: 2px; }
.sb-nav { flex: 1; padding: 8px 0; }
.sb-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px;
  color: #64748b; text-decoration: none; font-size: .88rem; transition: all .2s;
  white-space: nowrap; border-left: 3px solid transparent; }
.sb-item:hover { background: #1e293b; color: #e2e8f0; }
.sb-item.active { background: #0ea5e915; color: #38bdf8; border-left-color: #38bdf8; }
.sb-item .sb-icon { font-size: 1.1rem; flex-shrink: 0; width: 22px; text-align: center; }
.sb-status { padding: 12px 14px; border-top: 1px solid #1e293b;
  font-size: .72rem; color: #475569; }
.sb-status-dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e;
  display: inline-block; margin-right: 5px; animation: pulse 2s infinite; }
.sb-logout { display: flex; align-items: center; gap: 10px; padding: 12px 16px;
  color: #ef4444; font-size: .85rem; cursor: pointer; border: none; background: none;
  width: 100%; border-top: 1px solid #1e293b; transition: background .2s; }
.sb-logout:hover { background: #7f1d1d22; }
.main-wrap { margin-left: var(--sb-w); flex: 1; transition: margin-left .25s;
  min-width: 0; }
.sidebar.collapsed ~ .main-wrap { margin-left: 58px; }
/* Hide old topbar logout (now in sidebar) */
.logout-btn { display: none; }

</style>
</head>
<body>

<?php if (empty($_SESSION['ng_auth'])): ?>
<!-- LOGIN -->
<div class="login-wrap">
  <div class="login-box">
    <h1>⚡ Nginx Stream</h1>
    <p>Panel de administración</p>
    <?php if ($timeout_msg): ?><div class="warn">⏱ Sesión cerrada por inactividad</div><?php endif ?>
    <?php if (!empty($login_err)): ?><div class="err"><?= htmlspecialchars($login_err) ?></div><?php endif ?>
    <form method="POST">
      <input type="text" name="user" placeholder="Usuario" required autofocus autocomplete="username">
      <input type="password" name="pass" placeholder="Contraseña" required autocomplete="current-password">
      <button type="submit" name="login">Iniciar sesión</button>
    </form>
  </div>
</div>

<?php else:
$running=0; $stopped=0; $total=count($channels);
foreach($channels as $ch){ $s=get_supervisord_status($ch); if($s==='RUNNING') $running++; else $stopped++; }
?>
<!-- DASHBOARD -->
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
    <a class="sb-item active" href="ng_manager.php">
      <span class="sb-icon">📡</span><span class="sb-label">Canales</span>
    </a>
    <a class="sb-item" href="ng_help.php">
      <span class="sb-icon">❓</span><span class="sb-label">Ayuda / Guía</span>
    </a>
    <a class="sb-item" href="ng_edges.php">
      <span class="sb-icon">🌐</span><span class="sb-label">Edges</span>
    </a>
    <a class="sb-item" href="ng_settings.php">
      <span class="sb-icon">⚙️</span><span class="sb-label">Configuración</span>
    </a>
  </nav>
  <div class="sb-status">
    <span class="sb-status-dot"></span>
    <span id="sb-channels">Cargando...</span>
  </div>
  <button class="sb-logout" onclick="location.href='?logout'">
    <span>🚪</span><span class="sb-label">Salir</span>
  </button>
</div>
<div class="main-wrap">
<div class="topbar">
  <h1>📡 Canales nginx-rtmp</h1>
  <div class="topbar-right">
    <span class="meta" id="last-refresh">Actualizando...</span>
  </div>
</div>

<div class="statsbar">
  <div class="scard"><div class="val"><?= $total ?></div><div class="lbl">Canales</div></div>
  <div class="scard"><div class="val" id="s-running" style="color:#22c55e"><?= $running ?></div><div class="lbl">En línea</div></div>
  <div class="scard"><div class="val" id="s-stopped" style="color:#ef4444"><?= $stopped ?></div><div class="lbl">Detenidos</div></div>
  <div class="scard"><div class="val" id="s-avail" style="color:#f59e0b"><?= $total>0?round($running/$total*100):0 ?>%</div><div class="lbl">Disponibilidad</div></div>
</div>

<!-- BULK ACTIONS -->
<div class="bulk-bar">
  <label>Acciones masivas:</label>
  <button class="bulk-btn bb-nginx" onclick="bulkSwitch('nginx','all')">⚡ Todos → nginx</button>
  <button class="bulk-btn bb-wowza" onclick="bulkSwitch('wowza','all')">☁ Todos → Wowza</button>
  <button class="bulk-btn bb-sel" onclick="bulkSwitch('nginx','sel')">⚡ Seleccionados → nginx</button>
  <button class="bulk-btn bb-sel" onclick="bulkSwitch('wowza','sel')">☁ Seleccionados → Wowza</button>
  <span class="sel-info" id="sel-count">0 seleccionados</span>
  <button class="bulk-btn bb-sel" onclick="toggleSelectAll()" style="margin-left:auto">☑ Sel. todos</button>
</div>

<div class="content">
  <div class="section-hdr">
    <h2>Canales nginx-rtmp</h2>
    <span class="badge-sm" id="b-running"><?= $running ?> RUNNING</span>
  </div>
  <div class="search-wrap">
    <span style="color:#38bdf8;font-size:1rem;flex-shrink:0">🔍</span>
    <input type="text" id="ch-search" placeholder="Buscar por canal (fx0093) o nombre (FOX ONE)..." oninput="filterChannels(this.value)" autocomplete="off">
    <span class="search-count" id="search-count"></span>
    <button onclick="document.getElementById('ch-search').value='';filterChannels('');" style="background:none;border:none;color:#475569;cursor:pointer;font-size:1rem;padding:2px 6px" title="Limpiar">✕</button>
  </div>
  <div class="tbl-wrap">
  <table>
    <thead><tr>
      <th class="chk-col"><input type="checkbox" id="chk-all" onchange="selectAll(this)"></th>
      <th>Canal</th>
      <th>Nombre</th>
      <th>Estado</th>
      <th>Ancho de banda</th>
      <th>HLS</th>
      <th>URL</th>
      <th>Sistema</th>
      <th>Acciones</th>
    </tr></thead>
    <tbody>
<?php
$url_base = 'http://'.$_SERVER['HTTP_HOST'].':8090/hls';
foreach($channels as $ch):
  $st    = get_supervisord_status($ch);
  $ngs   = $ng_stats[$ch] ?? ['bw_video'=>0,'bw_audio'=>0,'bw_total'=>0,'clients'=>0];
  $hls   = get_hls_info($ch);
  $name  = $ng_names[$ch] ?? '';
  $url   = "$url_base/$ch/index.m3u8";
  $wst   = get_wowza_status($ch);
  $hwowza = has_wowza_config($ch);

  $st_color = match($st){
    'RUNNING'=>'#22c55e','STOPPED'=>'#ef4444','STARTING'=>'#f59e0b',
    'BACKOFF'=>'#f97316','EXITED'=>'#dc2626',default=>'#64748b'};
  $st_icon = match($st){
    'RUNNING'=>'▶','STOPPED'=>'■','STARTING'=>'◌','BACKOFF'=>'⚠','EXITED'=>'✕',default=>'?'};

  $bw_v_fmt = format_bw($ngs['bw_video']);
  $bw_a_fmt = format_bw($ngs['bw_audio']);
  $bw_total = $ngs['bw_total'];
  $bw_pct   = bw_pct($bw_total);
  $bw_col   = bw_color($bw_total);

  // Current active system
  $active_sys = 'nginx'; // default in this panel
  if ($hwowza && $wst === 'RUNNING' && $st !== 'RUNNING') $active_sys = 'wowza';
  $is_push = is_push_channel($ch);
?>
    <tr id="row-<?= $ch ?>" class="ch-main-row" data-ch="<?= $ch ?>" data-name="<?= strtolower(htmlspecialchars($name)) ?>">
      <td class="chk-col"><input type="checkbox" class="ch-chk" value="<?= $ch ?>"></td>
      <td>
        <div class="ch-id"><?= htmlspecialchars($ch) ?></div>
      </td>
      <td>
        <div class="ch-name" title="<?= htmlspecialchars($name) ?>"><?= $name ? htmlspecialchars($name) : '<span style="color:#334155">—</span>' ?></div>
      </td>
      <td>
        <?php if ($is_push): ?>
          <?php if ($hls['segments'] > 0): ?>
            <span class="sbadge-push-live" id="st-<?= $ch ?>">
              <span class="dot-push"></span> EN VIVO
            </span>
          <?php else: ?>
            <span class="sbadge-push-off" id="st-<?= $ch ?>">📡 SIN SEÑAL</span>
          <?php endif ?>
        <?php elseif ($st !== 'RUNNING' && $hwowza && $wst === 'RUNNING'): ?>
          <span class="sbadge" id="st-<?= $ch ?>" style="background:#7c3aed22;color:#a78bfa">
            ☁ EN WOWZA
          </span>
        <?php else: ?>
          <span class="sbadge" id="st-<?= $ch ?>" style="background:<?= $st_color ?>22;color:<?= $st_color ?>">
            <?= $st_icon ?> <?= $st ?>
          </span>
        <?php endif ?>
      </td>
      <td>
        <?php $bw_out = get_hls_output_bw($ch); ?>
        <div class="bw-wrap" id="bw-<?= $ch ?>">
          <div class="bw-nums">
            <span style="color:#94a3b8;font-size:.7rem">↓ Entrada</span>
            <span style="color:#94a3b8;font-size:.7rem">↑ Salida</span>
          </div>
          <div class="bw-nums" style="margin-bottom:3px">
            <span style="color:#a3e635" id="bwv-<?= $ch ?>"><?= format_bw($bw_total) ?></span>
            <span style="color:#f59e0b" id="bwout-<?= $ch ?>"><?= $bw_out > 0 ? format_bw($bw_out) : '—' ?></span>
          </div>
          <div class="bw-bar-bg">
            <div class="bw-bar" id="bwbar-<?= $ch ?>" style="width:<?= $bw_pct ?>%;background:<?= $bw_col ?>"></div>
          </div>
          <div class="bw-label" id="bwlbl-<?= $ch ?>">
            <?= get_hls_viewers($ch) ?> usuarios en vivo
          </div>
        </div>
      </td>
      <td id="hls-<?= $ch ?>">
        <?php if($hls['segments']>0): ?>
          <div class="hls-live">
            <?php if($hls['age']<10): ?><span class="dot-live"></span><?php endif ?>
            <?= $hls['segments'] ?> segs
            <?php if($hls['age']>=10): ?><span style="color:#64748b">(<?= $hls['age'] ?>s)</span><?php endif ?>
          </div>
        <?php else: ?>
          <span style="color:#334155;font-size:.78rem">Sin datos</span>
        <?php endif ?>
      </td>
      <td>
        <?php if($is_push): ?>
        <div class="push-url-box" title="URL para configurar en el encoder/OBS">
          rtmp://23.137.84.97:1936/live/<?= $ch ?>
        </div>
        <?php else: ?>
        <?php $src_url = get_source_url($ch); ?>
        <?php if($src_url): ?>
        <div class="url-row">
          <span class="url-lbl">Src:</span>
          <button class="url-icon-btn" onclick="copyUrl('<?= htmlspecialchars($src_url) ?>')" title="Copiar URL fuente">📋</button>
          <button class="url-icon-btn play-src" onclick="openPlayer('<?= htmlspecialchars($src_url) ?>','<?= $ch ?> — Fuente')" title="Reproducir fuente">▶</button>
        </div>
        <?php endif ?>
        <?php endif ?>
        <div id="hlsbtn-<?= $ch ?>"><?php if($hls['segments'] > 0): ?>
        <div class="url-row">
          <span class="url-lbl">HLS:</span>
          <button class="url-icon-btn" onclick="copyUrl('<?= $url ?>')" title="Copiar URL HLS">📋</button>
          <button class="url-icon-btn play" onclick="openPlayer('<?= $url ?>','<?= $ch ?> — HLS Output')" title="Reproducir HLS nginx">▶</button>
        </div>
        <?php else: ?>
        <div class="url-row">
          <span class="url-lbl" style="color:#334155">HLS:</span>
          <button class="url-icon-btn" onclick="copyUrl('<?= $url ?>')" title="Copiar URL HLS (sin stream activo)">📋</button>
          <button class="url-icon-btn" style="opacity:.3;cursor:not-allowed" title="Sin HLS activo — canal en Wowza o sin segmentos">▶</button>
        </div>
        <?php endif ?></div>
      </td>
      <td>
        <?php if($is_push): ?>
          <span style="color:#38bdf8;font-size:.72rem">📡 Push<br><span style="color:#334155;font-size:.65rem">nginx directo</span></span>
        <?php elseif($hwowza): ?>
        <div class="switch-wrap">
          <div class="sw-toggle" id="sw-<?= $ch ?>">
            <button class="sw-btn <?= $active_sys==='wowza'?'active-wowza':'' ?>"
              onclick="switchCh('<?= $ch ?>','wowza')">Wowza</button>
            <button class="sw-btn <?= $active_sys==='nginx'?'active-nginx':'' ?>"
              onclick="switchCh('<?= $ch ?>','nginx')">nginx</button>
          </div>
        </div>
        <?php else: ?>
          <span style="color:#38bdf8;font-size:.75rem">nginx only</span>
        <?php endif ?>
      </td>
      <td>
        <div class="act-btns">
          <?php if(!$is_push): ?>
          <?php if($st==='RUNNING'): ?>
            <button class="abtn a-stop" onclick="chanAction('stop','<?= $ch ?>')">■ Stop</button>
            <button class="abtn a-restart" onclick="chanAction('restart','<?= $ch ?>')">↺</button>
          <?php else: ?>
            <button class="abtn a-start" onclick="chanAction('start','<?= $ch ?>')">▶ Start</button>
          <?php endif ?>
          <button class="abtn a-del" onclick="confirmDelete('<?= $ch ?>')" title="Eliminar canal">🗑</button>
          <?php endif ?>
          <button class="abtn a-edges" id="ebtn-<?= $ch ?>" onclick="toggleEdges('<?= $ch ?>')" title="Ver distribución por edge">🌐</button>
        </div>
      </td>
    </tr>
<!-- edge-sub-row se genera por JS en toggleEdges() -->
<?php endforeach ?>
    </tbody>
  </table>
  </div>
</div><!-- /content -->

<!-- FOOTER -->
<div style="text-align:center;padding:16px;color:#334155;font-size:.78rem;border-top:1px solid #1e293b">
  Nginx Stream Manager · Sistema independiente · Auto-refresh 5s
</div>

<?php endif ?>

<!-- PLAYER MODAL -->
<div id="player-modal">
  <div class="player-box">
    <div class="player-header">
      <span class="player-title" id="player-title">Reproduciendo...</span>
      <button class="player-close" onclick="closePlayer()">✕ Cerrar</button>
    </div>
    <div id="player-unsupported">
      <p>⚠ Este formato (<strong>.ts</strong>) no es reproducible directamente en el navegador.</p>
      <div class="vlc-hint">
        <p style="color:#94a3b8;font-size:.78rem;margin-bottom:8px">Copia la URL y ábrela en VLC u otro reproductor:</p>
        <code id="player-ts-url"></code>
      </div>
      <button class="player-copy-url" style="font-size:.85rem;padding:8px 18px" onclick="fallbackCopy(document.getElementById('player-ts-url').textContent);toast('URL copiada ✓')">📋 Copiar URL</button>
    </div>
    <video id="player-video" controls autoplay playsinline></video>
    <div id="player-hls-error" style="display:none;background:#7f1d1d;color:#fca5a5;padding:8px 12px;border-radius:6px;font-size:.8rem;margin-top:8px;word-break:break-all"></div>
    <iframe id="player-iframe" src="about:blank" allowfullscreen></iframe>
    <div class="player-footer">
      <span class="player-url" id="player-url-display"></span>
      <button class="player-copy-url" onclick="fallbackCopy(document.getElementById('player-url-display').textContent);toast('URL copiada ✓')">📋 Copiar</button>
    </div>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="del-modal">
  <div class="modal">
    <h3>🗑 Eliminar canal</h3>
    <p>¿Confirmas eliminar <strong id="del-ch-name"></strong>? Se detendrá el proceso y se borrarán los archivos de configuración.</p>
    <div class="modal-btns">
      <button class="cancel" onclick="closeModal()">Cancelar</button>
      <button class="confirm" id="del-confirm-btn">Eliminar</button>
    </div>
  </div>
</div>

<!-- INACTIVITY WARNING -->
<div id="inact-bar">
  <span>⏱ Sesión expirará por inactividad en <strong id="inact-timer">5:00</strong></span>
  <button onclick="resetInactivity()">Continuar</button>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script src="https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js"></script>
<script>
// ── Edge distribution ───────────────────────────────────────────
let edgeData = {};

function toggleEdges(ch) {
  const row = document.getElementById('edgerow-' + ch);
  const btn = document.getElementById('ebtn-' + ch);
  const open = row.style.display !== 'none';
  row.style.display = open ? 'none' : 'table-row';
  btn.classList.toggle('open', !open);
  if (!open) fetchEdgeData();
}

function fetchEdgeData() {
  fetch('ng_channel_edges.php')
    .then(r => r.json())
    .then(data => { edgeData = data; updateEdgeRows(); })
    .catch(() => {});
}

function updateEdgeRows() {
  const EDGES = ['edge1','edge2','edge3'];
  document.querySelectorAll('.edge-sub-row').forEach(row => {
    if (row.style.display === 'none') return;
    const ch = row.id.replace('edgerow-','');
    const chNum = ch.replace('fx','');
    EDGES.forEach(eid => {
      const edata  = edgeData[eid];
      if (!edata) return;
      const chData = (edata.channels||{})[chNum] || null;
      const dot  = document.getElementById('edcdot-' + ch + '-' + eid);
      const vw   = document.getElementById('edcvw-'  + ch + '-' + eid);
      const bw   = document.getElementById('edcbw-'  + ch + '-' + eid);
      const req  = document.getElementById('edcreq-' + ch + '-' + eid);
      const bar  = document.getElementById('edcbar-' + ch + '-' + eid);
      if (!dot) return;
      dot.className = 'edc-dot ' + (edata.nginx_status === 'active' ? 'active' : (edata.nginx_status === 'inactive' ? 'inactive' : 'unknown'));
      if (chData) {
        vw.textContent  = chData.viewers;
        bw.textContent  = chData.bw_mbps.toFixed(2) + ' Mbps';
        req.textContent = chData.requests;
        const pct = Math.min(100, (chData.bw_mbps / 20) * 100);
        bar.style.width      = pct + '%';
        bar.style.background = chData.bw_mbps > 10 ? '#f59e0b' : '#38bdf8';
      } else {
        vw.textContent  = '0';
        bw.textContent  = '0 Mbps';
        req.textContent = '0';
        bar.style.width = '0%';
      }
    });
  });
}

function playFromEdge(chNum, edgeIp, edgeLabel) {
  // Reproducir desde origin directamente (sin token, para monitoreo)
  const url = 'http://23.137.84.97:8090/hls/fx' + chNum + '/index.m3u8';
  openPlayer(url, 'fx' + chNum + ' — Origin (preview)');
}

// Auto-refresh edge data every 10s if any row is open
setInterval(() => {
  if (document.querySelector('.edge-sub-row[style*="table-row"]')) fetchEdgeData();
}, 10000);
</script>
<script>
// ─── CONFIG ───────────────────────────────────────────────────────────────
const REFRESH_MS   = 5000;
const TIMEOUT_MS   = 7200000; // 2h
const WARN_MS      = 7500000-300000; // warn at 1h55m


// ─── SIDEBAR ──────────────────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('collapsed');
  localStorage.setItem('sb_collapsed', document.getElementById('sidebar').classList.contains('collapsed'));
}
if (localStorage.getItem('sb_collapsed') === 'true') {
  document.getElementById('sidebar')?.classList.add('collapsed');
}
setInterval(() => {
  const el = document.getElementById('sb-clock');
  if (el) el.textContent = new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}, 1000);

// ─── INACTIVITY LOGOUT ────────────────────────────────────────────────────
let lastActive = Date.now();
let warnTimer  = null;
let inactInterval = null;

function resetInactivity() {
  lastActive = Date.now();
  document.getElementById('inact-bar').style.display = 'none';
  clearInterval(inactInterval);
}

['mousemove','keydown','click','scroll','touchstart'].forEach(e =>
  document.addEventListener(e, resetInactivity, {passive:true})
);

setInterval(() => {
  const idle = Date.now() - lastActive;
  if (idle >= TIMEOUT_MS) { location.href = '?logout'; return; }
  const bar  = document.getElementById('inact-bar');
  const left = Math.ceil((TIMEOUT_MS - idle) / 1000);
  if (idle >= TIMEOUT_MS - 300000) { // show warning 5 min before
    bar.style.display = 'flex';
    const m = String(Math.floor(left/60)).padStart(2,'0');
    const s = String(left%60).padStart(2,'0');
    document.getElementById('inact-timer').textContent = m+':'+s;
  }
}, 1000);

// ─── TOAST ────────────────────────────────────────────────────────────────
function toast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show ' + type;
  clearTimeout(t._t);
  t._t = setTimeout(() => t.className = '', 3500);
}

// ─── COPY URL ─────────────────────────────────────────────────────────────
function copyUrl(url) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(() => toast('URL copiada ✓')).catch(() => fallbackCopy(url));
  } else { fallbackCopy(url); }
}
function fallbackCopy(url) {
  var el = document.createElement('textarea');
  el.value = url;
  el.setAttribute('readonly', '');
  el.style.cssText = 'position:absolute;left:-9999px;top:0;';
  document.body.appendChild(el);
  el.select();
  try { document.execCommand('copy'); toast('URL copiada ✓'); }
  catch(e) { toast('Error al copiar','err'); }
  document.body.removeChild(el);
}
function _execCopy(url) {
  // Most reliable cross-browser method on HTTP
  const r = window.prompt('Selecciona todo (Ctrl+A) y copia (Ctrl+C):', url);
  if (r !== null) toast('URL copiada ✓');
}

// ─── CHANNEL ACTIONS (start/stop/restart) ─────────────────────────────────
function chanAction(action, ch) {
  fetch(`?api=stats&_action=${action}&_ch=${ch}`, {method:'GET'})
    .catch(()=>{});
  // Use form POST for actual action
  const f = document.createElement('form');
  f.method = 'POST'; f.action = '';
  f.innerHTML = `<input name="action" value="${action}"><input name="channel" value="${ch}">`;
  document.body.appendChild(f); f.submit();
}

// Actually do it via a simple hidden form approach (since actions use POST)
// Override: use fetch for start/stop/restart via supervisorctl
function chanAction(action, ch) {
  const url = `ng_manager_action.php?a=${action}&ch=${encodeURIComponent(ch)}`;
  // Fallback: just reload with POST - use simple approach
  doAction(action, ch);
}

function doAction(action, ch) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('channel', ch);
  fetch('ng_manager.php', {method:'POST', body: fd})
    .then(() => { toast(`${ch}: ${action} ejecutado`); setTimeout(refreshStats,1500); })
    .catch(() => toast('Error de conexión','err'));
}

// ─── DELETE MODAL ─────────────────────────────────────────────────────────
let pendingDelete = null;
function confirmDelete(ch) {
  pendingDelete = ch;
  document.getElementById('del-ch-name').textContent = ch;
  document.getElementById('del-modal').classList.add('show');
  document.getElementById('del-confirm-btn').onclick = () => doDelete(ch);
}
function closeModal() {
  document.getElementById('del-modal').classList.remove('show');
  pendingDelete = null;
}
function doDelete(ch) {
  closeModal();
  toast(`Eliminando ${ch}...`);
  fetch(`ng_manager.php?api=delete&ch=${encodeURIComponent(ch)}`)
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        const row = document.getElementById('row-' + ch);
        if (row) { row.style.opacity='0'; row.style.transition='opacity .4s'; setTimeout(()=>row.remove(),400); }
        toast(`${ch} eliminado ✓`);
        setTimeout(refreshStats, 500);
      } else { toast('Error al eliminar','err'); }
    })
    .catch(() => toast('Error de conexión','err'));
}

// ─── SWITCH ───────────────────────────────────────────────────────────────
function switchCh(ch, target) {
  toast(`Cambiando ${ch} → ${target}...`);
  fetch(`ng_manager.php?api=switch&ch=${encodeURIComponent(ch)}&target=${target}`)
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        toast(d.msg + ' ✓');
        updateSwitch(ch, target);
        setTimeout(refreshStats, 2000);
      } else { toast('No se pudo cambiar (verifica config Wowza)','err'); }
    })
    .catch(() => toast('Error de conexión','err'));
}

function updateSwitch(ch, target) {
  const sw = document.getElementById('sw-' + ch);
  if (!sw) return;
  const btns = sw.querySelectorAll('.sw-btn');
  btns[0].className = 'sw-btn' + (target==='wowza' ? ' active-wowza' : '');
  btns[1].className = 'sw-btn' + (target==='nginx' ? ' active-nginx' : '');
}

function updateSwitchFromData(ch, system) {
  updateSwitch(ch, system);
}

function bulkSwitch(target, scope) {
  let channels = [];
  if (scope === 'all') {
    document.querySelectorAll('.ch-chk').forEach(c => channels.push(c.value));
  } else {
    document.querySelectorAll('.ch-chk:checked').forEach(c => channels.push(c.value));
  }
  if (!channels.length) { toast('No hay canales seleccionados','err'); return; }
  const list = channels.join(',');
  toast(`Cambiando ${channels.length} canales → ${target}...`);
  fetch(`ng_manager.php?api=switch_bulk&target=${target}&channels=${encodeURIComponent(list)}`)
    .then(r => r.json())
    .then(d => {
      toast(`${d.done} canales → ${target} ✓`);
      channels.forEach(ch => updateSwitch(ch, target));
      setTimeout(refreshStats, 2500);
    })
    .catch(() => toast('Error de conexión','err'));
}

// ─── CHECKBOXES ───────────────────────────────────────────────────────────
function selectAll(master) {
  document.querySelectorAll('.ch-chk').forEach(c => c.checked = master.checked);
  updateSelCount();
}
function toggleSelectAll() {
  const all = document.querySelectorAll('.ch-chk');
  const anyUnchecked = Array.from(all).some(c => !c.checked);
  all.forEach(c => c.checked = anyUnchecked);
  document.getElementById('chk-all').checked = anyUnchecked;
  updateSelCount();
}
function updateSelCount() {
  const n = document.querySelectorAll('.ch-chk:checked').length;
  document.getElementById('sel-count').textContent = n + ' seleccionado' + (n!==1?'s':'');
}
document.addEventListener('change', e => { if(e.target.classList.contains('ch-chk')) updateSelCount(); });

// ─── LIVE STATS REFRESH ───────────────────────────────────────────────────
function stColor(bps) {
  const k = bps/1024;
  if (k<50)  return '#ef4444';
  if (k<300) return '#f97316';
  if (k<800) return '#eab308';
  return '#22c55e';
}
function stPct(bps) { return Math.min(100, Math.round(bps/1024/30)); }
function fmtBw(bps) {
  const k = Math.round(bps/1024);
  return k>=1000 ? (k/1024).toFixed(1)+' Mbps' : k+' kbps';
}
function stBadge(st) {
  const map = {
    RUNNING:['#22c55e','▶'],STOPPED:['#ef4444','■'],
    STARTING:['#f59e0b','◌'],BACKOFF:['#f97316','⚠'],
    EXITED:['#dc2626','✕'],UNKNOWN:['#64748b','?']
  };
  return map[st] || map['UNKNOWN'];
}

let running_count = 0, stopped_count = 0;

function refreshStats() {
  fetch('ng_manager.php?api=stats')
    .then(r => r.json())
    .then(data => {
      running_count = 0; stopped_count = 0;
      for (const [ch, d] of Object.entries(data)) {
        // Status badge
        const stEl = document.getElementById('st-' + ch);
        if (stEl) {
          if (d.system === 'wowza' && d.status === 'RUNNING') {
            stEl.style.background = '#7c3aed22';
            stEl.style.color = '#a78bfa';
            stEl.textContent = '☁ EN WOWZA';
          } else if (d.system === 'wowza' && d.status !== 'RUNNING') {
            stEl.style.background = '#7c3aed11';
            stEl.style.color = '#64748b';
            stEl.textContent = '☁ WOWZA OFF';
          } else {
            const [col, icon] = stBadge(d.status);
            stEl.style.background = col + '22';
            stEl.style.color = col;
            stEl.textContent = icon + ' ' + d.status;
          }
        }
        if (d.status === 'RUNNING') running_count++;
        else stopped_count++;

        // Bandwidth bar
        const bar  = document.getElementById('bwbar-' + ch);
        const bwv  = document.getElementById('bwv-' + ch);
        const bwa  = document.getElementById('bwa-' + ch);
        const bwlbl= document.getElementById('bwlbl-' + ch);
        if (bar) {
          const col = stColor(d.bw_total);
          bar.style.width = stPct(d.bw_total) + '%';
          bar.style.background = col;
        }
        if (bwv) bwv.textContent = d.bw_v_fmt;
        if (bwa) bwa.textContent = d.bw_a_fmt;
        if (bwlbl) bwlbl.textContent = d.clients + ' usuarios en vivo';
        const bwout = document.getElementById('bwout-' + ch);
        if (bwout) bwout.textContent = d.bw_out > 0 ? fmtBw(d.bw_out) : '—';
        // Update entrada label
        const bwvEl = document.getElementById('bwv-' + ch);
        if (bwvEl) bwvEl.textContent = fmtBw(d.bw_total);
        // HLS column live update
        // Actualizar badge de estado para push channels
        const stEl = document.getElementById('st-' + ch);
        if (stEl && stEl.classList.contains('sbadge-push-live') || stEl && stEl.classList.contains('sbadge-push-off')) {
          if (d.hls_segs > 0) {
            stEl.className = 'sbadge-push-live';
            stEl.innerHTML = '<span class="dot-push"></span> EN VIVO';
          } else {
            stEl.className = 'sbadge-push-off';
            stEl.textContent = '📡 SIN SEÑAL';
          }
        }
        // Actualizar botón de play HLS según segmentos activos
        const hlsBtnEl = document.getElementById('hlsbtn-' + ch);
        if (hlsBtnEl && d.hls_segs !== undefined) {
          const hlsUrl = 'http://' + location.hostname + ':8090/hls/' + ch + '/index.m3u8';
          if (d.hls_segs > 0) {
            hlsBtnEl.innerHTML = '<div class="url-row"><span class="url-lbl">HLS:</span>'
              + '<button class="url-icon-btn" onclick="copyUrl(\''+hlsUrl+'\')" title="Copiar URL HLS">📋</button>'
              + '<button class="url-icon-btn play" onclick="openPlayer(\''+hlsUrl+'\',\''+ch+' — HLS Output\')" title="Reproducir HLS nginx">▶</button>'
              + '</div>';
          } else {
            hlsBtnEl.innerHTML = '<div class="url-row"><span class="url-lbl" style="color:#334155">HLS:</span>'
              + '<button class="url-icon-btn" onclick="copyUrl(\''+hlsUrl+'\')" title="Copiar URL HLS (sin stream activo)">📋</button>'
              + '<button class="url-icon-btn" style="opacity:.3;cursor:not-allowed" title="Sin HLS activo — canal en Wowza o sin segmentos">▶</button>'
              + '</div>';
          }
        }
        const hlsEl = document.getElementById('hls-' + ch);
        if (hlsEl && d.hls_segs !== undefined) {
          if (d.hls_segs > 0) {
            const age = d.hls_age;
            const clr = age < 5 ? '#22c55e' : (age < 15 ? '#f59e0b' : '#ef4444');
            const aStr = age >= 0 ? (age < 60 ? age + 's' : Math.floor(age/60) + 'm') : '';
            const anim = age < 15 ? 'animation:pulse 1.5s infinite' : '';
            hlsEl.innerHTML = '<div style="display:flex;align-items:center;gap:5px">'
              + '<span style="width:8px;height:8px;border-radius:50%;background:' + clr + ';display:inline-block;' + anim + '"></span>'
              + '<span>' + d.hls_segs + ' segs</span>'
              + '<span style="color:#64748b;font-size:.72rem">' + aStr + '</span>'
              + '</div>';
          } else {
            hlsEl.innerHTML = '<span style="color:#334155;font-size:.78rem">Sin datos</span>';
          }
        }
      }
      // Update counters
      const tot = running_count + stopped_count;
      const sRun = document.getElementById('s-running');
      const sStp = document.getElementById('s-stopped');
      const sAvl = document.getElementById('s-avail');
      const bRun = document.getElementById('b-running');
      if (sRun) sRun.textContent = running_count;
      if (sStp) sStp.textContent = stopped_count;
      if (sAvl) sAvl.textContent = (tot>0?Math.round(running_count/tot*100):0)+'%';
      if (bRun) bRun.textContent = running_count + ' RUNNING';

      // Update switch toggles from stats
      for (const [ch, d] of Object.entries(data)) {
        if (d.system) updateSwitchFromData(ch, d.system);
      }
      const ts = new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
      const ref = document.getElementById('last-refresh');
      if (ref) ref.textContent = 'Actualizado: ' + ts;
      const sbCh = document.getElementById('sb-channels');
      if (sbCh) sbCh.textContent = running_count + '/' + (running_count+stopped_count) + ' en línea';
    })
    .catch(() => {});
}

// ─── PLAYER ───────────────────────────────────────────────────────────────
let hlsInstance = null;

function openPlayer(url, title) {
  const modal = document.getElementById('player-modal');
  const video = document.getElementById('player-video');
  const unsup = document.getElementById('player-unsupported');
  const titleEl = document.getElementById('player-title');
  const urlDisp = document.getElementById('player-url-display');

  titleEl.textContent = title || 'Reproduciendo...';
  urlDisp.textContent = url;
  unsup.style.display = 'none';
  video.style.display = 'block';
  const errEl = document.getElementById('player-hls-error');
  if(errEl){ errEl.style.display='none'; errEl.textContent=''; }

  // Destroy previous instance
  if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
  video.src = '';

  const isHLS = url.includes('.m3u8');
  const isTS  = url.includes('.ts');

  if (isHLS) {
    if (Hls.isSupported()) {
      hlsInstance = new Hls({
        enableWorker: false,
        maxBufferLength: 30,
        maxMaxBufferLength: 60,
        xhrSetup: function(xhr) { xhr.withCredentials = false; }
      });
      hlsInstance.loadSource(url);
      hlsInstance.attachMedia(video);
      hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
        video.play().catch(function(){});
      });
      hlsInstance.on(Hls.Events.ERROR, function(e, data) {
        console.error('HLS error:', data);
        if (data.fatal) {
          const errEl = document.getElementById('player-hls-error');
          if(errEl) { errEl.textContent = 'Error: ' + data.type + ' — ' + (data.details||''); errEl.style.display='block'; }
          toast('Error HLS: ' + (data.details||data.type), 'err');
        }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = url;
      video.play().catch(()=>{});
    } else {
      showUnsupported(url);
      return;
    }
  } else if (isTS) {
    // Use server-side FFmpeg transcoder via iframe
    const frame = document.getElementById('player-iframe');
    video.style.display = 'none';
    frame.style.display = 'block';
    const previewUrl = 'ng_preview.php?url=' + encodeURIComponent(url) + '&ch=' + encodeURIComponent(titleEl.textContent.split(' — ')[0] || 'preview');
    frame.src = previewUrl;
  } else {
    video.src = url;
    video.play().catch(()=>{});
  }

  modal.classList.add('show');
}

function showUnsupported(url) {
  const video = document.getElementById('player-video');
  const unsup = document.getElementById('player-unsupported');
  const tsUrl = document.getElementById('player-ts-url');
  video.style.display = 'none';
  video.src = '';
  unsup.style.display = 'block';
  tsUrl.textContent = url;
}

function closePlayer() {
  const video = document.getElementById('player-video');
  const frame = document.getElementById('player-iframe');
  if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
  video.pause(); video.src = '';
  // Stop server-side preview if iframe was used
  if (frame.src !== 'about:blank') {
    try { frame.contentWindow.stopPreview(); } catch(e) {}
    frame.src = 'about:blank';
  }
  frame.style.display = 'none';
  video.style.display = 'block';
  document.getElementById('player-modal').classList.remove('show');
  document.getElementById('player-unsupported').style.display = 'none';
}

// Close on backdrop click
document.getElementById('player-modal')?.addEventListener('click', function(e) {
  if (e.target === this) closePlayer();
});

// Start auto-refresh
setInterval(refreshStats, REFRESH_MS);
refreshStats();
</script>
</div><!-- /main-wrap -->
</body>
</html>
<script>
let _clientIP=null;
async function getClientIP(){
  if(_clientIP) return _clientIP;
  try{ const r=await fetch('https://api.ipify.org?format=json'); _clientIP=(await r.json()).ip; return _clientIP; }
  catch(e){ return null; }
}
async function playWithToken(ch,chNum,edgeId,edgeIp,edgeLabel){
  const btn=document.getElementById('tkbtn-'+ch+'-'+edgeId);
  if(btn){ btn.disabled=true; btn.textContent='...'; }
  try{
    const ip=await getClientIP();
    if(!ip){ toast('No se pudo obtener la IP','err'); return; }
    const r=await fetch('ng_token_generate.php?fx='+encodeURIComponent(ch)+'&ip='+encodeURIComponent(ip));
    if(!r.ok){ toast('Error '+r.status+' al generar token','err'); return; }
    const data=await r.json();
    if(!data.success){ toast('Error: '+(data.error||'desconocido'),'err'); return; }
    const ed=data.urls[edgeId];
    if(!ed){ toast('Edge no encontrado','err'); return; }
    openPlayer(ed.url, ch+' — '+edgeLabel+' (token)');
    toast('Token valido 2h \u2713');
  }catch(e){ toast('Error: '+e.message,'err'); }
  finally{ if(btn){ btn.disabled=false; btn.textContent='\uD83D\uDD11 Token'; } }
}
</script>
<script>
// ── Buscador de canales ─────────────────────────────────────────────────
(function initSearch(){
  const rows = document.querySelectorAll('tr.ch-main-row');
  const countEl = document.getElementById('search-count');
  if(countEl) countEl.textContent = rows.length + ' canales';
})();

let _searchTimer = null;
function filterChannels(val){
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(function(){
    const q = val.trim().toLowerCase();
    const rows = document.querySelectorAll('tr.ch-main-row');
    let shown = 0;
    rows.forEach(function(tr){
      const ch   = (tr.dataset.ch   || '').toLowerCase();
      const name = (tr.dataset.name || '').toLowerCase();
      const match = !q || ch.includes(q) || name.includes(q);
      tr.style.display = match ? '' : 'none';
      // Ocultar edge sub-row si el canal se oculta
      const er = document.getElementById('edgerow-' + tr.dataset.ch);
      if(er && !match) er.style.display = 'none';
      if(match) shown++;
    });
    const countEl = document.getElementById('search-count');
    if(countEl) countEl.textContent = shown + ' canal' + (shown !== 1 ? 'es' : '');
    // Mostrar mensaje si no hay resultados
    let noRes = document.getElementById('no-results-row');
    if(shown === 0 && q){
      if(!noRes){
        const tbody = document.querySelector('tbody');
        const tr = document.createElement('tr');
        tr.id = 'no-results-row';
        tr.innerHTML = '<td colspan="9" class="no-results">Sin resultados para "'+val+'"</td>';
        tbody.appendChild(tr);
      } else { noRes.style.display = ''; }
    } else if(noRes){ noRes.style.display = 'none'; }
  }, 150);
}

// ── Edge sub-row generado dinámicamente ────────────────────────────────
const EDGE_LIST = [
  {id:'edge1', label:'Edge 1', ip:'186.233.186.55'},
  {id:'edge2', label:'Edge 2', ip:'186.233.186.58'},
  {id:'edge3', label:'Edge 3', ip:'198.147.24.146'}
];

function buildEdgeSubRow(ch){
  const chNum = ch.replace('fx','');
  let html = '<tr id="edgerow-'+ch+'" class="edge-sub-row" style="display:none">'
           + '<td colspan="9" class="edge-sub-td">'
           + '<div class="edge-dist-grid" id="edgegrid-'+ch+'">';
  EDGE_LIST.forEach(function(ei){
    html +=
      '<div class="edc" id="edc-'+ch+'-'+ei.id+'">'
      +'<div class="edc-header">'
      +'<span class="edc-dot unknown" id="edcdot-'+ch+'-'+ei.id+'"></span>'
      +'<span class="edc-name">'+ei.label+'</span>'
      +'<span class="edc-ip">'+ei.ip+'</span>'
      +'</div>'
      +'<div class="edc-stats-row">'
      +'<div class="edc-stat"><span class="edc-stat-label">&#128065; Viewers</span>'
      +'<span class="edc-stat-value vw-val" id="edcvw-'+ch+'-'+ei.id+'">&#8212;</span></div>'
      +'<div class="edc-stat"><span class="edc-stat-label">&#128228; BW Salida</span>'
      +'<span class="edc-stat-value" id="edcbw-'+ch+'-'+ei.id+'">&#8212;</span></div>'
      +'<div class="edc-stat"><span class="edc-stat-label">&#128246; Req/30s</span>'
      +'<span class="edc-stat-value" id="edcreq-'+ch+'-'+ei.id+'">&#8212;</span></div>'
      +'</div>'
      +'<div class="edc-bw-bg"><div class="edc-bw-fill" id="edcbar-'+ch+'-'+ei.id+'" style="width:0%"></div></div>'
      +'<div style="display:flex;gap:5px;margin-top:2px">'
      +'<button class="edc-token-btn" style="flex:1" id="tkbtn-'+ch+'-'+ei.id+'"'
      +' onclick="playWithToken(\''+ch+'\',\''+chNum+'\',\''+ei.id+'\',\''+ei.ip+'\',\''+ei.label+'\')">'
      +'&#128273; Play con Token</button>'
      +'<a class="edc-play-btn" style="flex:0 0 34px;text-align:center;text-decoration:none;background:#0f172a;border:1px solid #334155;color:#64748b"'
      +' href="http://'+ei.ip+'/01hbx'+chNum+'c6WI3k/myStream/playlist.m3u8"'
      +' target="_blank" title="URL del edge (requiere token)">&#128279;</a>'
      +'</div>'
      +'</div>';
  });
  html += '</div></td></tr>';
  return html;
}

// Sobreescribir toggleEdges para crear sub-row on demand
function toggleEdges(ch){
  const mainRow = document.getElementById('row-'+ch);
  const btn = document.getElementById('ebtn-'+ch);
  let edgeRow = document.getElementById('edgerow-'+ch);
  // Crear si no existe
  if(!edgeRow && mainRow){
    mainRow.insertAdjacentHTML('afterend', buildEdgeSubRow(ch));
    edgeRow = document.getElementById('edgerow-'+ch);
  }
  if(!edgeRow) return;
  const open = edgeRow.style.display !== 'none';
  edgeRow.style.display = open ? 'none' : 'table-row';
  if(btn) btn.classList.toggle('open', !open);
  if(!open) fetchEdgeData();
}
</script>
