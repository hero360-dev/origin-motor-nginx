<?php
if (posix_getppid() == 1) {
    file_put_contents('/tmp/fx0698_nginx.log', date('[Y-m-d H:i:s] ') . 'Huerfano: ' . getmypid() . PHP_EOL, FILE_APPEND);
    die('Proceso huerfano. Abortando.' . PHP_EOL);
}
include('/home/streamvod/utils.php');
// Nginx mode — latromance79.php → fx0698
$idopt  = '9999';
$file   = '/home/streamvod/latromance79.txt';
$stream = 'fx0698';
$nginx_dest = 'rtmp://prov:prov001@23.137.84.97:1936/live/fx0698';
SendStream($idopt, $file, $stream, $nginx_dest);
