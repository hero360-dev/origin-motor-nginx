<?php
session_start();
if(empty($_SESSION['ng_auth'])){ http_response_code(403); exit; }
$secret = '';
foreach(file('/etc/casino-secrets.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line);
    if($line===''||$line[0]==='#'||$line[0]===';') continue;
    if(strpos($line,'NGINX_TOKEN_SECRET=')===0){ $secret=substr($line,strlen('NGINX_TOKEN_SECRET=')); break; }
}
$masked = $secret ? (substr($secret,0,6) . str_repeat('*', max(0,strlen($secret)-10)) . substr($secret,-4)) : 'NO CONFIGURADO';
header('Content-Type: application/json');
echo json_encode(['masked'=>$masked, 'length'=>strlen($secret)]);
