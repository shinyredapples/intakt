<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A project whose vendor folder is a symlink to a shared install cache named "deps".
$vendor = dirname(__DIR__, 2).'/vendor';
$filesystem = new Filesystem();
$scratch = $filesystem->tempnam(sys_get_temp_dir(), 'mate-root-');
$filesystem->remove($scratch);
$filesystem->mkdir([$scratch.'/project/mate', $scratch.'/deps/composer']);
$filesystem->dumpFile($scratch.'/deps/autoload.php', '<?php require '.var_export($vendor.'/autoload.php', true).';');
$filesystem->copy($vendor.'/composer/installed.json', $scratch.'/deps/composer/installed.json');
$filesystem->symlink($vendor.'/symfony', $scratch.'/deps/symfony');
$filesystem->symlink($scratch.'/deps', $scratch.'/project/vendor');
$filesystem->dumpFile($scratch.'/project/mate/extensions.php', "<?php return ['symfony/ai-mate' => ['enabled' => true]];");

$process = new Process([PHP_BINARY, $vendor.'/symfony/ai-mate/bin/mate.php', 'tools:list', '--format=json'], $scratch.'/project');
$process->run();
$filesystem->remove($scratch);
$tools = json_decode($process->getOutput(), true);

if (!is_array($tools) || !isset($tools['tools']['server-info'])) {
    echo 'No server-info tool: the project root was taken from the real path of vendor', PHP_EOL, $process->getOutput(), $process->getErrorOutput(), PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
