<?php
session_start();
if(empty($_SESSION['ng_auth'])){ http_response_code(403); exit; }
$vars = parse_ini_file('/etc/casino-secrets.env');
$secret = $vars['NGINX_TOKEN_SECRET'] ?? '';
$masked = $secret ? (substr($secret,0,6) . str_repeat('*', max(0,strlen($secret)-10)) . substr($secret,-4)) : 'NO CONFIGURADO';
header('Content-Type: application/json');
echo json_encode(['masked'=>$masked, 'length'=>strlen($secret)]);
