<?php
/**
 * ng_token_generate.php
 * Genera token seguro para streams HLS en edges nginx.
 * Mismo patrón que wowza_generate.php pero usando secure_link (MD5).
 */
session_start();
if(empty($_SESSION['ng_auth'])){ http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit; }

// ── Leer secreto desde /etc/casino-secrets.env ────────────────────────────
$envFile = '/etc/casino-secrets.env';
$secret  = '';
if(file_exists($envFile)){
    // Usar parser manual: parse_ini_file falla con paréntesis en comentarios
    foreach(file($envFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
        $line = trim($line);
        if($line==='' || $line[0]==='#' || $line[0]===';') continue;
        if(strpos($line,'NGINX_TOKEN_SECRET=')===0){
            $secret = substr($line, strlen('NGINX_TOKEN_SECRET='));
            break;
        }
    }
}
if(empty($secret)){
    http_response_code(500);
    echo json_encode(['error'=>'NGINX_TOKEN_SECRET no configurado']);
    exit;
}

// ── Validar parámetros ────────────────────────────────────────────────────
$fx = trim($_GET['fx'] ?? '');
$ip = trim($_GET['ip'] ?? '');

if(!preg_match('/^fx\d{4,5}$/', $fx)){
    http_response_code(400);
    echo json_encode(['error'=>'FX inválido']);
    exit;
}
if(!filter_var($ip, FILTER_VALIDATE_IP)){
    http_response_code(400);
    echo json_encode(['error'=>'IP inválida']);
    exit;
}

// ── Configuración de edges ────────────────────────────────────────────────
$edges = [
    'edge1' => ['ip'=>'186.233.186.55', 'label'=>'Edge 1'],
    'edge2' => ['ip'=>'186.233.186.58', 'label'=>'Edge 2'],
    'edge3' => ['ip'=>'198.147.24.146', 'label'=>'Edge 3'],
];

// ── Extraer número de canal ───────────────────────────────────────────────
$ch_num = preg_replace('/^fx/', '', $fx); // fx0095 → 0095

// ── Tiempo de validez: 2 horas ───────────────────────────────────────────
$expires = time() + 7200;

// ── Generar token para cada edge ──────────────────────────────────────────
// Fórmula nginx secure_link_md5: "{expires}{uri}{remote_addr} {secret}"
// remote_addr = IP del cliente que nginx recibirá
function makeToken(string $expires, string $uri, string $ip, string $secret): string {
    $str    = "{$expires}{$uri} {$secret}"; // Sin IP: mas robusto con IPv6/CGNAT
    $binary = md5($str, true);
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

$urls = [];
foreach($edges as $edgeKey => $edge){
    $uri  = "/01hbx{$ch_num}c6WI3k/myStream/playlist.m3u8";
    $hash = makeToken((string)$expires, $uri, $ip, $secret);
    $urls[$edgeKey] = [
        'label' => $edge['label'],
        'url'   => "http://{$edge['ip']}{$uri}?ngtk={$hash}&ngte={$expires}",
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'success'    => true,
    'fx'         => $fx,
    'ip'         => $ip,
    'expires'    => $expires,
    'expires_in' => 7200,
    'urls'       => $urls,
]);
