<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$fixture = __DIR__.'/MarkedRound.php';

require $fixture;

$replay = new MarkedRound()->markedAs(Mark::Replay);
echo 'At run time a round marked through clone with is a replay: ', var_export($replay->isReplay(), true), PHP_EOL;

$phpstan = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpstan', 'analyse', '--level=max', '--no-progress', '--error-format=raw', $fixture], __DIR__);
$phpstan->run();
echo trim($phpstan->getOutput().$phpstan->getErrorOutput()), PHP_EOL;

if (!$replay->isReplay() || !$phpstan->isSuccessful()) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
