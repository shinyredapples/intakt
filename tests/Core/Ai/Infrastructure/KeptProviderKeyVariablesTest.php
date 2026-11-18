<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use ArrayIterator;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyVariables;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\String\LazyString;

#[CoversClass(KeptProviderKeyVariables::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(Provider::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
final class KeptProviderKeyVariablesTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';
    private const array COMPATIBLE = ['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'];

    protected function tearDown(): void
    {
        foreach (self::COMPATIBLE as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    public function testAKeptAnthropicKeyLoadsAsItsProviderVariable(): void
    {
        $key = 'sk-ant-'.self::BODY;

        self::assertSame(['ANTHROPIC_API_KEY' => $key], $this->variablesOf($key));
    }

    public function testAKeptOpenAiKeyLoadsAsItsProviderVariable(): void
    {
        $key = 'sk-'.self::BODY;

        self::assertSame(['OPENAI_API_KEY' => $key], $this->variablesOf($key));
    }

    public function testAKeptHuggingFaceTokenLoadsAsHfTokenAndAsTheRouterOnTheOpenAiCompatibleEndpoint(): void
    {
        $key = 'hf_'.self::BODY;

        self::assertSame(
            ['HF_TOKEN' => $key, 'OPENAI_COMPATIBLE_BASE_URL' => 'https://router.huggingface.co', 'OPENAI_COMPATIBLE_API_KEY' => $key],
            $this->variablesOf($key),
        );
    }

    public function testAKeptHuggingFaceTokenLeavesTheOpenAiCompatibleEndpointAloneWhenTheEnvironmentSetsItsBaseUrl(): void
    {
        $key = 'hf_'.self::BODY;
        $_SERVER['OPENAI_COMPATIBLE_BASE_URL'] = 'https://models.example.test';

        self::assertSame(['HF_TOKEN' => $key], $this->variablesOf($key));
    }

    public function testAKeptHuggingFaceTokenLeavesTheOpenAiCompatibleEndpointAloneWhenTheEnvironmentSetsItsKey(): void
    {
        $key = 'hf_'.self::BODY;
        $_ENV['OPENAI_COMPATIBLE_API_KEY'] = 'owner-key';

        self::assertSame(['HF_TOKEN' => $key], $this->variablesOf($key));
    }

    public function testAKeptHuggingFaceTokenStandsInWhenTheEnvironmentLeavesTheOpenAiCompatibleVariablesEmpty(): void
    {
        $key = 'hf_'.self::BODY;
        $_SERVER['OPENAI_COMPATIBLE_BASE_URL'] = '';

        self::assertArrayHasKey('OPENAI_COMPATIBLE_API_KEY', $this->variablesOf($key));
    }

    public function testAKeptHuggingFaceTokenIsNeverPairedWithAnEndpointTheOwnerSetOnlyInTheVault(): void
    {
        $processor = $this->processorWithVault(['OPENAI_COMPATIBLE_BASE_URL' => 'https://models.example.test']);

        self::assertSame('https://models.example.test', $processor->getEnv('string', 'OPENAI_COMPATIBLE_BASE_URL', $this->unused(...)));
        $this->expectException(EnvNotFoundException::class);
        $processor->getEnv('string', 'OPENAI_COMPATIBLE_API_KEY', $this->unused(...));
    }

    public function testAKeyTheOwnerSetOnlyInTheVaultIsNeverPairedWithTheHuggingFaceRouter(): void
    {
        $processor = $this->processorWithVault(['OPENAI_COMPATIBLE_API_KEY' => 'owner-key']);

        self::assertSame('owner-key', $processor->getEnv('string', 'OPENAI_COMPATIBLE_API_KEY', $this->unused(...)));
        $this->expectException(EnvNotFoundException::class);
        $processor->getEnv('string', 'OPENAI_COMPATIBLE_BASE_URL', $this->unused(...));
    }

    public function testAKeptHuggingFaceTokenStandsInWhenTheVaultLeavesTheOpenAiCompatibleVariablesEmpty(): void
    {
        $processor = $this->processorWithVault(['OPENAI_COMPATIBLE_BASE_URL' => '', 'OTHER_SECRET' => 'other']);

        self::assertSame('https://router.huggingface.co', $processor->getEnv('string', 'OPENAI_COMPATIBLE_BASE_URL', $this->unused(...)));
        self::assertSame('hf_'.self::BODY, $processor->getEnv('string', 'OPENAI_COMPATIBLE_API_KEY', $this->unused(...)));
    }

    public function testAKeptHuggingFaceTokenLeavesTheOpenAiCompatibleEndpointAloneWhenAVaultValueCannotBeRead(): void
    {
        $unreadable = LazyString::fromCallable(static fn (): never => throw new RuntimeException('The vault cannot decrypt this secret.'));

        self::assertSame(['HF_TOKEN' => 'hf_'.self::BODY], $this->variablesOf('hf_'.self::BODY, [new FixedEnvVars(['OPENAI_COMPATIBLE_BASE_URL' => $unreadable])]));
    }

    public function testAKeptHuggingFaceTokenLeavesTheOpenAiCompatibleEndpointAloneWhenAnotherLoaderFails(): void
    {
        $failing = self::createStub(EnvVarLoaderInterface::class);
        $failing->method('loadEnvVars')->willThrowException(new RuntimeException('The vault cannot be opened.'));

        self::assertSame(['HF_TOKEN' => 'hf_'.self::BODY], $this->variablesOf('hf_'.self::BODY, [$failing]));
    }

    public function testALoaderThatFailsIsLoggedByClassOnlyAndItsVariableCountsAsSet(): void
    {
        $failing = self::createStub(EnvVarLoaderInterface::class);
        $failing->method('loadEnvVars')->willThrowException(new RuntimeException('secret-text-from-the-vault'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret-text-from-the-vault') && RuntimeException::class === $context['exception_class']),
        );
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog()));

        $variables = new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog(), $logger, [$failing])->loadEnvVars();

        self::assertSame(['HF_TOKEN' => 'hf_'.self::BODY], $variables);
    }

    public function testTheWarningForALoaderThatFailsNamesBothClassesOnALoggerWithoutAFormatter(): void
    {
        $failing = self::createStub(EnvVarLoaderInterface::class);
        $failing->method('loadEnvVars')->willThrowException(new RuntimeException('secret-text-from-the-vault'));
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog()));
        $written = fopen('php://memory', 'w+');
        self::assertIsResource($written);

        // Symfony's logger without a formatter, because config/packages/ai.php builds that one before the logger service exists.
        new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog(), new Logger(LogLevel::WARNING, $written), [$failing])->loadEnvVars();

        $line = (string) stream_get_contents($written, offset: 0);
        self::assertStringContainsString('[warning] The env var loader '.$failing::class.' failed with '.RuntimeException::class.', so its variable counts as set.', $line);
        self::assertStringNotContainsString('secret-text-from-the-vault', $line);
    }

    public function testAKeptKeyOfAProviderTheCatalogNoLongerListsLoadsNoVariable(): void
    {
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog()));

        self::assertSame([], new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog([new AnthropicDescriptor()]), self::createStub(LoggerInterface::class))->loadEnvVars());
    }

    public function testWithoutAKeptKeyNoVariableLoads(): void
    {
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(null);

        self::assertSame([], new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog(), self::createStub(LoggerInterface::class))->loadEnvVars());
    }

    public function testAnUnreadableKeptFileLoadsNoVariableAndLogsOnlyAFixedMessage(): void
    {
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willThrowException(new KeptProviderKeyUnreadable());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('The kept provider key could not be read, so it is not used.');

        self::assertSame([], new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog(), $logger)->loadEnvVars());
    }

    /**
     * @param list<EnvVarLoaderInterface> $otherLoaders
     *
     * @return array<string, string>
     */
    private function variablesOf(string $key, array $otherLoaders = []): array
    {
        return $this->loaderOf($key, $otherLoaders)->loadEnvVars();
    }

    /**
     * @param list<EnvVarLoaderInterface> $otherLoaders
     */
    private function loaderOf(string $key, array $otherLoaders): KeptProviderKeyVariables
    {
        $providers = new SymfonyAiProviderCatalog();
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise($key, $providers));

        return new KeptProviderKeyVariables($keys, $providers, self::createStub(LoggerInterface::class), $otherLoaders);
    }

    // Returns nothing because the processor reads a plain string variable itself and calls this only for a prefix it chains.
    private function unused(): null
    {
        return null;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function processorWithVault(array $secrets): EnvVarProcessor
    {
        $vault = new FixedEnvVars($secrets);

        // The vault's loader comes first because the container ranks it above the kept key's, and the kept key gets it too, as the container hands it every other loader.
        return new EnvVarProcessor(new Container(), new ArrayIterator([$vault, $this->loaderOf('hf_'.self::BODY, [$vault])]));
    }
}
