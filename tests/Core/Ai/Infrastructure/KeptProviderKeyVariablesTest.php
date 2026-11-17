<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

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

    private const array COMPATIBLE = ['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'];

    protected function tearDown(): void
    {
        foreach (self::COMPATIBLE as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
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

    public function testAKeptKeyOfAProviderTheCatalogNoLongerListsLoadsNoVariable(): void
    {
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog()));

        self::assertSame([], new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog([new AnthropicDescriptor()]))->loadEnvVars());
    }

    public function testWithoutAKeptKeyNoVariableLoads(): void
    {
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(null);

        self::assertSame([], new KeptProviderKeyVariables($keys, new SymfonyAiProviderCatalog())->loadEnvVars());
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
     * @return array<string, string>
     */
    private function variablesOf(string $key): array
    {
        $providers = new SymfonyAiProviderCatalog();
        $keys = self::createStub(KeptProviderKeys::class);
        $keys->method('read')->willReturn(ProviderKey::recognise($key, $providers));

        return new KeptProviderKeyVariables($keys, $providers)->loadEnvVars();
    }
}
