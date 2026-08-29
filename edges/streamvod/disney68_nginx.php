<?php
if (posix_getppid() == 1) {
    file_put_contents('/tmp/fx0068_nginx.log', date('[Y-m-d H:i:s] ') . 'Huerfano: ' . getmypid() . PHP_EOL, FILE_APPEND);
    die('Proceso huerfano. Abortando.' . PHP_EOL);
}
include('/home/streamvod/utils.php');
// Nginx mode — disney68.php → fx0068
$idopt  = '9999';
$file   = '/home/streamvod/disney68.txt';
$stream = 'fx0068';
$nginx_dest = 'rtmp://prov:prov001@23.137.84.97:1936/live/fx0068';
SendStream($idopt, $file, $stream, $nginx_dest);
