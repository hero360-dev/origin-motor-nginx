<?php
/**
 * balancer-api.php — nginx Edge Load Balancer + Token Generator
 *
 * Implementación de referencia para el app server externo.
 * Selecciona el edge con menor carga y genera un token nginx secure_link.
 *
 * USO (GET):
 *   /balancer-api.php?fx=fx0235&ip=203.0.113.45&key=TU_API_KEY
 *
 * RESPUESTA (JSON):
 *   { "ok": true, "fx": "fx0235", "edge": "edge1",
 *     "edge_ip": "186.233.186.55",
 *     "url": "http://186.233.186.55/01hbx0235c6WI3k/myStream/playlist.m3u8?ngtk=HASH&ngte=1756291200",
 *     "expires": 1756291200, "expires_in": 7200 }
 */

// ─── Configuración ────────────────────────────────────────────────────────────

// Secreto compartido con el origin (mismo valor que NGINX_TOKEN_SECRET en /etc/casino-secrets.env)
// NUNCA hardcodear en producción — usar variable de entorno o config file
define('NGINX_TOKEN_SECRET', getenv('NGINX_TOKEN_SECRET') ?: 'REEMPLAZAR_CON_EL_SECRETO_REAL');

// API key para proteger este endpoint (los clientes deben enviarla)
define('API_KEY', getenv('NGINX_BALANCER_API_KEY') ?: 'REEMPLAZAR_CON_TU_API_KEY');

// Tiempo de vida del token en segundos
define('TOKEN_TTL', 7200);

// Pesos para el algoritmo de scoring (ajustar según necesidad)
define('WEIGHT_VIEWERS', 1.0);   // peso de viewers activos
define('WEIGHT_BW',      0.5);   // peso de Mbps de salida

// Timeout para consultar métricas de cada edge
define('EDGE_TIMEOUT', 3);

// Lista de edges disponibles
$EDGES = [
    'edge1' => ['ip' => '186.233.186.55', 'label' => 'Edge 1'],
    'edge2' => ['ip' => '186.233.186.58', 'label' => 'Edge 2'],
    'edge3' => ['ip' => '198.147.24.146', 'label' => 'Edge 3'],
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Genera token nginx secure_link (con IP binding).
 * Fórmula: base64url( MD5("{expires}{uri}{client_ip} {secret}", raw) )
 */
function nginxMakeToken(string $expires, string $uri, string $clientIp, string $secret): string
{
    $input  = "{$expires}{$uri}{$clientIp} {$secret}";
    $binary = md5($input, true);
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * Genera token nginx secure_link (sin IP binding).
 * Usar si los clientes tienen problemas con CGNAT/IPv6.
 * REQUIERE cambiar secure_link_md5 en los edges también.
 */
function nginxMakeTokenNoIp(string $expires, string $uri, string $secret): string
{
    $input  = "{$expires}{$uri} {$secret}";
    $binary = md5($input, true);
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * Convierte "fx0235" → "0235"
 */
function fxToChNum(string $fx): string
{
    return str_pad(ltrim(preg_replace('/^fx/i', '', $fx), '0') ?: '0', 4, '0', STR_PAD_LEFT);
}

/**
 * Consulta métricas de todos los edges en paralelo (curl_multi).
 * Devuelve: ['edge1' => ['viewers'=>12, 'bw_out_mbps'=>45.3, 'nginx_status'=>'active'], ...]
 */
function fetchEdgeMetrics(array $edges, int $timeout): array
{
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($edges as $name => $e) {
        $ch = curl_init("http://{$e['ip']}:8091/metrics");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$name] = $ch;
    }

    // Ejecutar en paralelo
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    $metrics = [];
    foreach ($handles as $name => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $data = $raw ? @json_decode($raw, true) : null;
        $metrics[$name] = [
            'viewers'      => $data['viewers']      ?? null,
            'bw_out_mbps'  => $data['bw_out_mbps']  ?? null,
            'nginx_status' => $data['nginx_status']  ?? 'unknown',
            'ok'           => ($data && ($data['nginx_status'] ?? '') === 'active'),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $metrics;
}

/**
 * Selecciona el mejor edge según scoring de carga.
 * score = viewers × WEIGHT_VIEWERS + bw_out_mbps × WEIGHT_BW
 * Menor score = menos carga = mejor candidato.
 *
 * Devuelve el nombre del edge seleccionado ('edge1', 'edge2', 'edge3').
 * Si ningún edge responde, devuelve el fallback (edge1).
 */
function selectBestEdge(array $edges, array $metrics, string $fallback = 'edge1'): array
{
    $best      = null;
    $bestScore = PHP_FLOAT_MAX;

    foreach ($edges as $name => $e) {
        $m = $metrics[$name] ?? [];

        // Excluir edges que no respondieron o tienen nginx caído
        if (!($m['ok'] ?? false)) {
            continue;
        }

        $score = ($m['viewers']     * WEIGHT_VIEWERS)
               + ($m['bw_out_mbps'] * WEIGHT_BW);

        if ($score < $bestScore) {
            $bestScore = $score;
            $best      = $name;
        }
    }

    // Fallback si todos fallaron
    if ($best === null) {
        $best = $fallback;
    }

    return [
        'name'  => $best,
        'ip'    => $edges[$best]['ip'],
        'label' => $edges[$best]['label'],
        'score' => $bestScore === PHP_FLOAT_MAX ? null : round($bestScore, 2),
    ];
}

// ─── Request handler ──────────────────────────────────────────────────────────

header('Content-Type: application/json');

// Autenticación por API key (query param o header)
$apiKey = $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// Validar parámetro fx
$fx = trim($_GET['fx'] ?? '');
if (!preg_match('/^fx\d{4,5}$/i', $fx)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'fx_invalido', 'detail' => 'Formato esperado: fx0235']);
    exit;
}

// Obtener IP del cliente (la IP que el edge verá)
// Prioridad: parámetro ?ip= explícito > X-Forwarded-For > REMOTE_ADDR
$clientIp = '';
if (!empty($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $clientIp = $_GET['ip'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    // Tomar la primera IP de la cadena (la del cliente original)
    $parts    = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    $clientIp = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $parts[0] : '';
}
if (empty($clientIp)) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
}

if (!filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    echo json_encode([
        'ok'     => false,
        'error'  => 'ip_invalida',
        'detail' => 'Se requiere IPv4 pública del cliente. Enviar como ?ip=X.X.X.X o header X-Forwarded-For',
    ]);
    exit;
}

// ─── Selección de edge ────────────────────────────────────────────────────────

$metrics  = fetchEdgeMetrics($EDGES, EDGE_TIMEOUT);
$selected = selectBestEdge($EDGES, $metrics);

// ─── Generación de token ──────────────────────────────────────────────────────

$chNum   = fxToChNum($fx);
$expires = time() + TOKEN_TTL;
$uri     = "/01hbx{$chNum}c6WI3k/myStream/playlist.m3u8";
$token   = nginxMakeToken((string)$expires, $uri, $clientIp, NGINX_TOKEN_SECRET);
$url     = "http://{$selected['ip']}{$uri}?ngtk={$token}&ngte={$expires}";

// ─── Respuesta ────────────────────────────────────────────────────────────────

echo json_encode([
    'ok'         => true,
    'fx'         => strtolower($fx),
    'ch_num'     => $chNum,
    'client_ip'  => $clientIp,
    'edge'       => $selected['name'],
    'edge_ip'    => $selected['ip'],
    'edge_label' => $selected['label'],
    'edge_score' => $selected['score'],
    'url'        => $url,
    'expires'    => $expires,
    'expires_in' => TOKEN_TTL,
    // Debug: métricas de todos los edges (útil para desarrollo, remover en producción)
    '_edges_metrics' => $metrics,
]);
