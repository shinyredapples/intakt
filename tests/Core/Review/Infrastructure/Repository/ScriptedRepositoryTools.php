<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\TraceableToolbox;
use Symfony\AI\AiBundle\Profiler\DataCollector;
use Symfony\AI\AiBundle\Test\AiAssertionsTrait;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TraceablePlatform;

// Shared by the tests of each repository tool, because each drives its tool through an agent the same way.
trait ScriptedRepositoryTools
{
    use AiAssertionsTrait;

    private const string COLLECTOR = 'test.repository_tools.ai_data_collector';

    private ReviewCheckout $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    // Because the toolbox is built per checkout and is no container service, the collector is built from this run's traceable platform and toolbox instead of a profiled request.
    protected static function getAiDataCollector(): DataCollector
    {
        self::assertTrue(self::getContainer()->has(self::COLLECTOR), 'No scripted run was made.');
        $collector = self::getContainer()->get(self::COLLECTOR);
        self::assertInstanceOf(DataCollector::class, $collector);

        return $collector;
    }

    /**
     * @return list<string>
     */
    private function runScript(ToolCall ...$calls): array
    {
        $toolbox = new TraceableToolbox(new FaultTolerantToolbox(new ReviewerToolboxFactory()->create($this->fixture->checkout)));
        $step = 0;
        $platform = new TraceablePlatform(MockPlatformFactory::createPlatform(static function () use (&$step, $calls) {
            return $step < count($calls) ? new ToolCallResult([$calls[$step++]]) : new TextResult('done');
        }));

        $result = new Agent($platform, 'reviewer', toolbox: $toolbox)->call(new MessageBag())->getContent();

        self::assertSame('done', $result);
        $collector = new DataCollector([$platform], [$toolbox], [$toolbox], [], [], []);
        $collector->lateCollect();
        self::getContainer()->set(self::COLLECTOR, $collector);

        return array_values(array_map(static function (ToolResult $call): string {
            $answer = $call->getResult();
            self::assertIsString($answer);
            self::assertNotFalse(json_encode($answer));

            return $answer;
        }, $toolbox->getCalls()));
    }
}
