<?php

declare(strict_types=1);

// Sends scripted tool calls of one reviewer to the open review page of #1796's seeded round (php live.php <round> <reviewer>, in the running install's environment) through RoundToolCalls and RoundUpdates, because those two record and publish a real tool call.

use Intakt\Core\Review\Contract\ReviewTool;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Kernel;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

// A kernel beside the application's final one, with its passes, because the three services are private and only a container that makes them public hands them out in dev.
final class EvidenceKernel extends BaseKernel
{
    use MicroKernelTrait;

    public const array SERVICES = [RoundUpdates::class, RoundToolCalls::class, ReviewRounds::class];

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    // A cache of its own, because the application's container in var/cache/dev keeps the services private.
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/evidence-1796';
    }

    protected function build(ContainerBuilder $container): void
    {
        Kernel::addApplicationPasses($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (EvidenceKernel::SERVICES as $id) {
                    $container->hasAlias($id) ? $container->getAlias($id)->setPublic(true) : $container->getDefinition($id)->setPublic(true);
                }
            }
        });
    }
}

[$roundId, $reviewer] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!Uuid::isValid($roundId) || !Uuid::isValid($reviewer)) {
    fwrite(STDERR, "usage: php live.php <round> <reviewer>\n");
    exit(1);
}

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new EvidenceKernel(is_string($environment) ? $environment : 'dev', false);
$kernel->boot();
[$updates, $recorded, $rounds] = array_map($kernel->getContainer()->get(...), EvidenceKernel::SERVICES);
if (!$updates instanceof RoundUpdates || !$recorded instanceof RoundToolCalls || !$rounds instanceof ReviewRounds) {
    fwrite(STDERR, "the container did not hand out the round's services\n");
    exit(1);
}
$round = $rounds->stored($roundId);

$script = [
    [ReviewTool::ListDirectory, ['path' => '.']],
    [ReviewTool::ReadFile, ['path' => 'src/Forge/PushGate.php']],
    [ReviewTool::SearchFiles, ['text' => 'mainStatus(', 'path' => 'src']],
    [ReviewTool::ReadFile, ['path' => 'src/Forge/MainWatcher.php']],
    [ReviewTool::ReadFile, ['path' => 'tests/Forge/PushGateTest.php']],
    [ReviewTool::SearchFiles, ['text' => 'held_at', 'path' => 'src/Forge']],
    [ReviewTool::ListDirectory, ['path' => 'docs/guide']],
    [ReviewTool::ReadFile, ['path' => 'docs/guide/holds.md']],
];
// Recorded only on the first run, because every capture publishes the script again and the summary must count each call once.
$record = [] === $recorded->countedIn($round);

foreach ($script as $number => [$tool, $arguments]) {
    // Read again for each call, as the review server reads it per request, because publishing the summary clears the entity manager and detaches the round.
    $round = $rounds->stored($roundId);

    if ($record) {
        ReviewTool::ReadFile === $tool ? $recorded->read($round, $tool->value, $arguments['path']) : $recorded->called($round, $tool->value);
    }

    $updates->toolCalled($round->id(), $reviewer, $tool->callInWords(new ToolCall('call-'.$number, $tool->value, $arguments)));
    $updates->summaryChanged($round->id());
}

echo 'published '.count($script).' tool calls'.($record ? ', recorded' : '')."\n";
