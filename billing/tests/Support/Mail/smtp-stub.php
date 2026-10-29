<?php

// Minimal SMTP server for tests, one connection. Usage: php smtp-stub.php <echo|slow|tarpit>
// echo: refuses every AUTH with a 535 that repeats the client's payload. slow: answers each command after 0.6 s.
// tarpit: accepts and never greets. Prints its port on the first line of stdout.
$mode = $argv[1] ?? 'echo';
$server = stream_socket_server('tcp://127.0.0.1:0');
fwrite(STDOUT, substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1)."\n");
$client = stream_socket_accept($server, 30);

if ($client === false || $mode === 'tarpit') {
    sleep(30);
    exit;
}

fwrite($client, "220 stub ESMTP\r\n");
$step = null;

while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    $mode === 'slow' && usleep(600000);

    if ($step === 'login-user') {
        fwrite($client, "334 UGFzc3dvcmQ6\r\n");
        $step = 'login-pass';
    } elseif ($step === 'login-pass') {
        fwrite($client, "535 5.7.8 rejected: {$line}\r\n");
        $step = null;
    } elseif ($step === 'data') {
        if ($line === '.') {
            fwrite($client, "250 2.0.0 queued\r\n");
            $step = null;
        }
    } elseif (str_starts_with($line, 'EHLO')) {
        fwrite($client, "250-stub\r\n250 AUTH PLAIN LOGIN\r\n");
    } elseif (str_starts_with($line, 'AUTH LOGIN')) {
        fwrite($client, "334 VXNlcm5hbWU6\r\n");
        $step = 'login-user';
    } elseif (str_starts_with($line, 'AUTH')) {
        fwrite($client, "535 5.7.8 rejected: {$line}\r\n");
    } elseif ($line === 'DATA') {
        fwrite($client, "354 go\r\n");
        $step = 'data';
    } elseif ($line === 'QUIT') {
        fwrite($client, "221 bye\r\n");
        break;
    } else {
        fwrite($client, "250 ok\r\n");
    }
}
