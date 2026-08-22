<?php
session_start();
if(empty($_SESSION['ng_auth'])){ http_response_code(403); exit; }

$edges = [
    'edge1' => ['ip'=>'186.233.186.55', 'label'=>'Edge 1'],
    'edge2' => ['ip'=>'186.233.186.58', 'label'=>'Edge 2'],
    'edge3' => ['ip'=>'198.147.24.146', 'label'=>'Edge 3'],
];

$mh = curl_multi_init();
$handles = [];
foreach($edges as $name => $e){
    $ch = curl_init("http://{$e['ip']}:8091/metrics");
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

$result = [];
foreach($handles as $name => $ch){
    $raw  = curl_multi_getcontent($ch);
    $data = $raw ? @json_decode($raw, true) : null;
    $result[$name] = [
        'label'        => $edges[$name]['label'],
        'ip'           => $edges[$name]['ip'],
        'nginx_status' => $data['nginx_status'] ?? 'unknown',
        'bw_out_mbps'  => $data['bw_out_mbps']  ?? 0,
        'viewers'      => $data['viewers']       ?? 0,
        'channels'     => $data['channels']      ?? [],
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

header('Content-Type: application/json');
echo json_encode($result);
