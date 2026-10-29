<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\UseCase;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders\ProbeProvidersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ProbeProvidersCommand::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(TierRequest::class)]
final class ProbeProvidersCommandTest extends TestCase
{
    public function testEachConfiguredProviderIsCalledOnceAndItsUsageLineIsPrinted(): void
    {
        $calls = [];
        $tester = $this->execute($this->resolver(), $this->invoker($calls, static function (): TextResult {
            $result = new TextResult('ok');
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 12, completionTokens: 3, cachedTokens: 4, remainingTokens: 900, totalTokens: 15, model: 'big'));

            return $result;
        }));

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alpha/strong', 'alpha/light'], $calls);
        self::assertStringContainsString('anthropic: model big, prompt 12, completion 3, cached 4, total 15, remaining 900', $tester->getDisplay());
        self::assertStringContainsString('openai: model big, prompt 12', $tester->getDisplay());
    }

    public function testAnAnswerWithoutUsageSaysSo(): void
    {
        $calls = [];

        $tester = $this->execute($this->resolver(), $this->invoker($calls, static fn (): string => 'ok'));

        self::assertStringContainsString('anthropic: the provider reported no token usage', $tester->getDisplay());
    }

    public function testAFailingProviderIsReportedAndTheOthersStillRun(): void
    {
        $calls = [];
        $failFirst = static function () use (&$calls): string {
            if (1 === count($calls)) {
                throw new RateLimitExceededException(30);
            }

            return 'ok';
        };

        $tester = $this->execute($this->resolver(), $this->invoker($calls, $failFirst));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('anthropic: Rate limit exceeded.', $tester->getDisplay());
        self::assertStringContainsString('openai: the provider reported no token usage', $tester->getDisplay());
    }

    public function testWithoutAnEnabledProviderItSaysSo(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(['alpha']);
        $resolver->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): never => throw new TierNotMapped($project, $tier));
        $calls = [];

        $tester = $this->execute($resolver, $this->invoker($calls, static fn (): string => 'ok'));

        self::assertStringContainsString('No enabled provider is configured.', $tester->getDisplay());
        self::assertSame([], $calls);
    }

    private function resolver(): TierResolver
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(['alpha']);
        $resolver->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => match ($tier) {
            Tier::Strong, Tier::Standard => new ResolvedModel(Provider::Anthropic, 'big'),
            Tier::Light => new ResolvedModel(Provider::OpenAi, 'big'),
        });

        return $resolver;
    }

    /**
     * @param list<string> $calls
     */
    private function invoker(array &$calls, callable $answer): TierInvoker
    {
        $platform = MockPlatformFactory::createPlatform($answer(...));
        $invoker = self::createStub(TierInvoker::class);
        $invoker->method('invoke')->willReturnCallback(static function (string $project, Tier $tier, TierRequest $request) use (&$calls, $platform): DeferredResult {
            $calls[] = $project.'/'.$tier->value;

            return $platform->invoke('big', $request->input);
        });

        return $invoker;
    }

    private function execute(TierResolver $resolver, TierInvoker $invoker): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ProbeProvidersCommand($resolver, $invoker));
        $tester = new CommandTester($application->find('intakt:vendor:probe'));
        $tester->execute([]);

        return $tester;
    }
}
