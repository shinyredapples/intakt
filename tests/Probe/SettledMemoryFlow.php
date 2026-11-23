<?php

declare(strict_types=1);

use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Kernel;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;

// Runs through bin/probe: the booted instance's environment, cache directory and SQLite memory file, read and written through the container's own services.
require dirname(__DIR__, 2).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof TestContainer);

// The probe runs offline and the instance has no embedding account, so the scripted platform stands in for the one the install would call.
$container->set(EmbeddingsNotConfigured::class, MockPlatformFactory::createPlatform(
    static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input])),
));
echo 'environment: '.$kernel->getEnvironment().', memory file: <instance cache>/'.basename($kernel->getCacheDir().'/memory.db').PHP_EOL;
echo 'embedding platform: scripted (MockPlatformFactory in place of EmbeddingsNotConfigured)'.PHP_EOL;

$console = new Application($kernel);
$console->setAutoExit(false);
// The same command an install runs to create the memory table; "memory" is the store's name in config/packages/ai.php.
$setup = $console->run(new ArrayInput(['command' => 'ai:store:setup', 'store' => 'memory', '--no-interaction' => true]), new ConsoleOutput());

$index = $container->get(MemoryIndex::class);
$memory = $container->get(ProjectMemory::class);
$file = new PDO('sqlite:'.$kernel->getCacheDir().'/memory.db');
$finding = new SettledFinding('alpha', 'migrate() takes no lock.', 'Intentional: one writer.', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1'));
$counts = static function (string $hash) use ($memory, $file): array {
    $found = $memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', $hash)));
    $rows = $file->query('SELECT COUNT(*) FROM project_memory');

    return ['settled' => count($found->settled), 'stale' => count($found->stale), 'rows' => false === $rows ? -1 : (int) $rows->fetchColumn()];
};

$index->settle($finding);
$steps = ['settled, code unchanged (hash-1)' => [$counts('hash-1'), ['settled' => 1, 'stale' => 0, 'rows' => 1]]];
$steps['code changed (hash-2)'] = [$counts('hash-2'), ['settled' => 0, 'stale' => 1, 'rows' => 1]];
$index->forget($finding);
$steps['forgotten, asked at hash-1'] = [$counts('hash-1'), ['settled' => 0, 'stale' => 0, 'rows' => 0]];
$steps['forgotten, asked at hash-2'] = [$counts('hash-2'), ['settled' => 0, 'stale' => 0, 'rows' => 0]];

$failed = 0 === $setup ? 0 : 1;

foreach ($steps as $step => [$actual, $expected]) {
    $verdict = $actual === $expected ? 'ok' : 'MISMATCH';
    $failed += 'ok' === $verdict ? 0 : 1;
    echo sprintf('%s: settled=%d stale=%d rows in project_memory=%d %s', $step, $actual['settled'], $actual['stale'], $actual['rows'], $verdict).PHP_EOL;
}

exit(0 === $failed ? 0 : 1);
