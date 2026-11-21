<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$scratch = sys_get_temp_dir().'/rector-parallel-argument-list-'.getmypid();
$folder = $scratch.'/src/'.str_repeat('a_folder_with_a_long_name/', 5);
$filesystem = new Filesystem();
$paths = [];

for ($index = 0; $index < 1_000; ++$index) {
    $paths[] = $folder.'Clean'.$index.'.php';
    $filesystem->dumpFile(array_last($paths), "<?php\n\nfinal class Clean{$index}\n{\n}\n");
}

$filesystem->dumpFile($scratch.'/rector.php', "<?php\n\nreturn Rector\\Config\\RectorConfig::configure()->withParallel()->withPreparedSets(deadCode: true);\n");

$rector = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', '--clear-cache', '--config='.$scratch.'/rector.php', ...$paths], $scratch);
$rector->run();
$filesystem->remove($scratch);

echo 'Command-line paths: ', strlen(implode(' ', $paths)), ' bytes', PHP_EOL;
echo trim($rector->getOutput().$rector->getErrorOutput()), PHP_EOL;

if (!$rector->isSuccessful()) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
