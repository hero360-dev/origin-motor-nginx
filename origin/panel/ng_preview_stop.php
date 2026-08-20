<?php
session_start();
if (empty($_SESSION['ng_auth'])) { http_response_code(403); exit("No autorizado"); }

$ch = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ch'] ?? '');
$sessionHash = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['session'] ?? '');

if (!$ch || !$sessionHash) { http_response_code(400); exit("Params inválidos"); }

function isAlive(int $pid): bool {
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return trim((string)shell_exec("ps -p ".(int)$pid." -o pid= 2>/dev/null")) !== '';
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p)) rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

$token   = $ch . "_" . $sessionHash;
$workDir = __DIR__ . "/ng_preview_tmp/" . $token;
$pidFile = $workDir . "/ffmpeg.pid";

$pid = 0;
if (is_file($pidFile)) $pid = (int)trim((string)@file_get_contents($pidFile));

if ($pid > 0 && isAlive($pid)) {
    shell_exec("kill -TERM " . (int)$pid . " 2>/dev/null");
    usleep(300000);
    if (isAlive($pid)) shell_exec("kill -KILL " . (int)$pid . " 2>/dev/null");
}

rrmdir($workDir);

header("Content-Type: application/json");
echo json_encode(["ok" => true, "pid" => $pid]);
