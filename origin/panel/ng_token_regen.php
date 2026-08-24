<?php
session_start();
if(empty($_SESSION['ng_auth']) || $_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit;
}

// Generar nuevo secreto de 48 chars
$newSecret = bin2hex(random_bytes(24));

// Actualizar /etc/casino-secrets.env
$envFile = '/etc/casino-secrets.env';
$content = file_get_contents($envFile);
if(strpos($content, 'NGINX_TOKEN_SECRET=') !== false){
    $content = preg_replace('/^NGINX_TOKEN_SECRET=.*$/m', "NGINX_TOKEN_SECRET={$newSecret}", $content);
} else {
    $content .= "\nNGINX_TOKEN_SECRET={$newSecret}\n";
}
file_put_contents($envFile, $content);

// Sincronizar a los 3 edges via SSH y actualizar nginx.conf
$edges = [
    ['alias'=>'edge1','ip'=>'186.233.186.55'],
    ['alias'=>'edge2','ip'=>'186.233.186.58'],
    ['alias'=>'edge3','ip'=>'198.147.24.146'],
];
$errors = [];
foreach($edges as $edge){
    // Actualizar el secreto en nginx.conf del edge usando sed
    $cmd = "ssh -o ConnectTimeout=5 -i /root/.ssh/id_edges root@{$edge['ip']} "
         . "\"sed -i 's|secure_link_md5 \\\"[^\\\"]*\\\"|secure_link_md5 \\\"\\\$secure_link_expires\\\$uri\\\$remote_addr {$newSecret}\\\"|g' "
         . "/etc/nginx/nginx.conf && nginx -t && nginx -s reload\" 2>&1";
    $out = shell_exec($cmd);
    if(strpos($out, 'successful') === false && strpos($out, 'reloaded') === false){
        $errors[] = $edge['alias'] . ': ' . trim($out);
    }
}

header('Content-Type: application/json');
if(empty($errors)){
    echo json_encode(['success'=>true, 'message'=>'Secreto actualizado en origin y 3 edges']);
} else {
    echo json_encode(['success'=>false, 'error'=>implode('; ', $errors)]);
}
