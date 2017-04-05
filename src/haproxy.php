<?php

$backendName = "nginx_80";

$cmd = 'echo "show servers state ' . $backendName . '" | /usr/bin/nc -U /tmp/haproxy';
echo $cmd;
$resp = shell_exec($cmd);
echo $resp;
