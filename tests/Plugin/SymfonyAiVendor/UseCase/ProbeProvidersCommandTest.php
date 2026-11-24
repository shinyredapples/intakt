<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\UseCase;

use ArrayObject;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders\ProbeProvidersCommand;
use Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders\ProbeUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[CoversClass(ProbeProvidersCommand::class)]
#[UsesClass(ProbeUsage::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(PromptAssembly::class)]
final class ProbeProvidersCommandTest extends TestCase
{
    public function testEachConfiguredProviderIsCalledOnceAndItsUsageLineIsPrinted(): void
    {
        $calls = new ArrayObject();
        $tester = $this->execute($this->resolver(), $this->invoker($calls, static function (): TextResult {
            $result = new TextResult('ok');
            $result->getMetadata()->add('token_usage', new TokenUsage(
                promptTokens: 12,
                completionTokens: 3,
                cachedTokens: 4,
                remainingTokens: 900,
                totalTokens: 15,
                model: 'big',
            ));

            return $result;
        }));

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['anthropic/big', 'openai/big'], $calls->getArrayCopy());
        self::assertStringContainsString('anthropic: model big, prompt 12, completion 3, cached 4, total 15, remaining 900', $tester->getDisplay());
        self::assertStringContainsString('openai: model big, prompt 12', $tester->getDisplay());
    }

    public function testTheProbeSendsExactlyOneUserMessageHoldingTheFixedProbePrompt(): void
    {
        /** @var ArrayObject<int, mixed> $inputs */
        $inputs = new ArrayObject();
        $platform = self::createStub(PlatformInterface::class);
        $platform->method('invoke')->willReturnCallback(static function (Model|string $model, array|string|object $input) use ($inputs): DeferredResult {
            $inputs->append($input);

            return MockPlatformFactory::createPlatform('ok')->invoke('big', $input);
        });
        $invoker = self::createStub(TierInvoker::class);
        $invoker->method('invoke')->willReturnCallback(static fn (ResolvedModel $model, TierRequest $request): DeferredResult => $request->invokeOn($platform, 'big'));

        $this->execute($this->resolver(), $invoker);

        self::assertCount(2, $inputs);
        foreach ($inputs as $input) {
            self::assertInstanceOf(MessageBag::class, $input);
            $messages = $input->getMessages();
            self::assertCount(1, $messages);
            self::assertInstanceOf(UserMessage::class, $messages[0]);
            self::assertSame('Answer with the single word: ok', $messages[0]->asText());
        }
    }

    public function testAnAnswerWithoutUsageSaysSo(): void
    {
        $calls = new ArrayObject();

        $tester = $this->execute($this->resolver(), $this->invoker($calls, static fn (): string => 'ok'));

        self::assertStringContainsString('anthropic: the provider reported no token usage', $tester->getDisplay());
    }

    public function testAFailingProviderIsReportedAndTheOthersStillRun(): void
    {
        $calls = new ArrayObject();
        $failFirst = static function () use ($calls): string {
            if (1 === $calls->count()) {
                throw new RateLimitExceededException(30);
            }

            return 'ok';
        };

        $tester = $this->execute($this->resolver(), $this->invoker($calls, $failFirst));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('anthropic: the call failed with '.RateLimitExceededException::class, $tester->getDisplay());
        self::assertStringContainsString('openai: the provider reported no token usage', $tester->getDisplay());
    }

    public function testAFailureMessageIsNotPrintedBecauseItCanCarryAKey(): void
    {
        $calls = new ArrayObject();
        $leaking = static fn (): never => throw new RuntimeException('GET https://provider.example/v1?key=probe-secret-value failed');

        $tester = $this->execute($this->resolver(), $this->invoker($calls, $leaking));

        self::assertStringNotContainsString('probe-secret-value', $tester->getDisplay());
        self::assertStringContainsString('anthropic: the call failed with '.RuntimeException::class, $tester->getDisplay());
    }

    public function testAUsageThatCannotBeReadFailsOnlyItsProvider(): void
    {
        $calls = new ArrayObject();
        $unreadable = self::createStub(TokenUsageInterface::class);
        $unreadable->method('getModel')->willThrowException(new RuntimeException('unreadable usage'));
        $unreadableFirst = static function () use ($calls, $unreadable): TextResult {
            $result = new TextResult('ok');
            if (1 === $calls->count()) {
                $result->getMetadata()->add('token_usage', $unreadable);
            }

            return $result;
        };

        $tester = $this->execute($this->resolver(), $this->invoker($calls, $unreadableFirst));

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['anthropic/big', 'openai/big'], $calls->getArrayCopy());
        self::assertStringContainsString('anthropic: the call failed with '.RuntimeException::class, $tester->getDisplay());
        self::assertStringContainsString('openai: the provider reported no token usage', $tester->getDisplay());
    }

    public function testWithoutAnEnabledProviderItSaysSo(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames('alpha'));
        $resolver->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): never => throw new TierNotMapped($project, $tier));
        $calls = new ArrayObject();

        $tester = $this->execute($resolver, $this->invoker($calls, static fn (): string => 'ok'));

        self::assertStringContainsString('No enabled provider is configured.', $tester->getDisplay());
        self::assertCount(0, $calls);
    }

    private function resolver(): TierResolver
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames('alpha'));
        $resolver->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => match ($tier) {
            Tier::Strong, Tier::Standard => new ResolvedModel(Provider::Anthropic, 'big'),
            Tier::Light => new ResolvedModel(Provider::OpenAi, 'big'),
        });

        return $resolver;
    }

    /**
     * @param ArrayObject<int, string> $calls
     */
    private function invoker(ArrayObject $calls, callable $answer): TierInvoker
    {
        $platform = MockPlatformFactory::createPlatform($answer(...));
        $invoker = self::createStub(TierInvoker::class);
        $invoker->method('invoke')->willReturnCallback(static function (ResolvedModel $model, TierRequest $request) use ($calls, $platform): DeferredResult {
            $calls->append($model->provider->value.'/'.$model->model);

            return $request->invokeOn($platform, 'big');
        });

        return $invoker;
    }

    private function execute(TierResolver $resolver, TierInvoker $invoker): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ProbeProvidersCommand($resolver, $invoker, new Serializer([new ObjectNormalizer()])));
        $tester = new CommandTester($application->find('intakt:vendor:probe'));
        $tester->execute([]);

        return $tester;
    }
}
