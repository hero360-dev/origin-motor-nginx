<?php
if (posix_getppid() == 1) {
    file_put_contents('/tmp/fx0697_nginx.log', date('[Y-m-d H:i:s] ') . 'Huerfano: ' . getmypid() . PHP_EOL, FILE_APPEND);
    die('Proceso huerfano. Abortando.' . PHP_EOL);
}
include('/home/streamvod/utils.php');
// Nginx mode — latscifi78.php → fx0697
$idopt  = '9999';
$file   = '/home/streamvod/latscifi78.txt';
$stream = 'fx0697';
$nginx_dest = 'rtmp://prov:prov001@23.137.84.97:1936/live/fx0697';
SendStream($idopt, $file, $stream, $nginx_dest);
