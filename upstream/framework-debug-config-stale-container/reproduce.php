<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// debug:config compiles the container in a clone of the kernel. A config file dated in the future keeps the cached
// container stale, so the clone rebuilds it while booting and the command loads the same extensions a second time.
const LOCALE = 'zu_ZA';

$directory = sys_get_temp_dir().'/debug-config-stale-'.bin2hex(random_bytes(6));
// Owner-only: the child executes the config written here, so another local user must not be able to swap it.
mkdir($directory.'/config', 0o700, true);
$config = $directory.'/config/framework.php';
file_put_contents($config, <<<'PHP'
    <?php

    use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

    return static function (ContainerConfigurator $container): void {
        $container->extension('framework', ['secret' => 'probe', 'default_locale' => 'zu_ZA']);
    };
    PHP);

// APP_DEBUG is set for the child explicitly, so a caller's APP_DEBUG=0 cannot turn the freshness check off.
$autoload = realpath((string) ini_get('auto_prepend_file'));
$console = static function (array $arguments) use ($directory, $autoload): Process {
    $environment = ['APP_DEBUG' => '1', 'PROBE_DIR' => $directory, 'PROBE_AUTOLOAD' => false === $autoload ? false : $autoload];
    $process = new Process([PHP_BINARY, __DIR__.'/console.php', ...$arguments], null, $environment);
    $process->run();

    return $process;
};

try {
    $warmup = $console(['cache:warmup']);
    touch($config, time() + 60);
    $run = 0 === $warmup->getExitCode() ? $console(['debug:config', 'framework', 'default_locale']) : $warmup;
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

$status = (int) $run->getExitCode();

if (0 === $status && str_contains($run->getOutput(), LOCALE)) {
    echo 'debug:config framework default_locale printed '.LOCALE.PHP_EOL;
    echo 'REPRODUCTION PASSES'.PHP_EOL;
    exit(0);
}

$lines = array_map(trim(...), explode("\n", $run->getErrorOutput().$run->getOutput()));
$message = array_find($lines, static fn (string $line): bool => '' !== $line && !str_starts_with($line, 'In ') && !str_starts_with($line, 'debug:config') && !str_starts_with($line, 'cache:warmup'));
echo 'exception: '.($message ?? '(none)').PHP_EOL;
echo "still fails (exit {$status})".PHP_EOL;
exit(1);
