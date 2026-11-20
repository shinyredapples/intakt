<?php

declare(strict_types=1);

// debug:config compiles the container in a clone of the kernel; a config file dated in the future keeps the cached container stale, so the clone rebuilds it while booting and the command loads the same extensions a second time.
$root = dirname(__DIR__, 2);
$file = $root.'/config/services.yaml';
$mtime = filemtime($file);
touch($file, time() + 60);

try {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/console').' debug:config framework secret --env=dev 2>&1', $output, $status);
} finally {
    touch($file, $mtime);
}

echo implode("\n", array_slice($output, -5)), "\n";
echo 0 === $status ? "REPRODUCTION PASSES\n" : "still fails (exit {$status})\n";
exit(0 === $status ? 0 : 1);
