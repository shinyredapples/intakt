<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\KeepProviderKey;

use ArrayObject;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\ProviderKeyAlreadyKept;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeepProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyNotChecked;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyRefusedByProvider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

#[CoversClass(KeepProviderKey::class)]
#[CoversClass(KeyRefusedByProvider::class)]
#[CoversClass(KeyNotChecked::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(KeptProviderKeyFile::class)]
#[UsesClass(ConfiguredKey::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(SymfonyAiKeyCheck::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(KeyCheckOutcome::class)]
#[UsesClass(ProviderKeyAlreadyKept::class)]
final class KeepProviderKeyTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    private string $directory;

    public function testARecognisedKeyTheProviderAcceptsIsKeptAsConfiguredWithItsLastCharactersOnly(): void
    {
        $configured = $this->useCase(new ArrayObject(), $this->answer(200, [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))->keep('sk-ant-'.self::BODY);

        self::assertSame('anthropic', $configured->provider);
        self::assertSame('5678', $configured->lastCharacters);
    }

    public function testAKeyTheProviderAcceptsIsKeptInTheDataVolumeAndShownAsConfigured(): void
    {
        (void) $this->useCase(new ArrayObject(), $this->answer(200, [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))->keep('sk-ant-'.self::BODY);

        self::assertSame('sk-ant-'.self::BODY, new KeptProviderKeyFile($this->directory, new SymfonyAiProviderCatalog())->read()?->secret());
        self::assertSame('5678', $this->useCase(new ArrayObject(), $this->answer(200, []))->configured()?->lastCharacters);
    }

    public function testAKeyTheProviderRefusesIsNotKept(): void
    {
        try {
            (void) $this->useCase(new ArrayObject(), $this->answer(401, ['error' => ['message' => 'no']]))->keep('hf_'.self::BODY);
            self::fail('The provider should refuse the key.');
        } catch (KeyRefusedByProvider) {
            self::assertFileDoesNotExist($this->directory.'/provider-key.json');
        }
    }

    public function testAKeyTheCheckCouldNotReachIsNotKept(): void
    {
        try {
            (void) $this->useCase(new ArrayObject(), new MockResponse('', ['error' => 'connection refused']))->keep('sk-'.self::BODY);
            self::fail('The provider should not be reached.');
        } catch (KeyNotChecked) {
            self::assertFileDoesNotExist($this->directory.'/provider-key.json');
        }
    }

    public function testAKeyTheProviderRefusesThrowsKeyRefusedByProvider(): void
    {
        $this->expectException(KeyRefusedByProvider::class);

        (void) $this->useCase(new ArrayObject(), $this->answer(401, ['error' => ['message' => 'Invalid credentials']]))->keep('hf_'.self::BODY);
    }

    public function testAKeyTheCheckCouldNotReachThrowsKeyNotChecked(): void
    {
        $this->expectException(KeyNotChecked::class);

        (void) $this->useCase(new ArrayObject(), new MockResponse('', ['error' => 'connection refused']))->keep('sk-'.self::BODY);
    }

    public function testAnUnrecognisedKeyIsRefusedBeforeAnyCallIsMade(): void
    {
        $calls = new ArrayObject();

        try {
            (void) $this->useCase($calls, $this->answer(200, []))->keep('not a key');
            self::fail('The key should not be recognised.');
        } catch (KeyNotRecognised) {
            self::assertCount(0, $calls);
        }
    }

    public function testARefusedKeyAppearsNeitherInTheExceptionNorItsChain(): void
    {
        $key = 'sk-ant-'.self::BODY;

        try {
            (void) $this->useCase(new ArrayObject(), $this->answer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.$key]]))->keep($key);
            self::fail('The provider should refuse the key.');
        } catch (KeyRefusedByProvider $refused) {
            self::assertStringNotContainsString($key, (string) $refused);
            self::assertNotInstanceOf(Throwable::class, $refused->getPrevious());
        }
    }

    public function testAKeyEchoedByAnUnreachableProviderStaysOutOfTheException(): void
    {
        $key = 'hf_'.self::BODY;

        try {
            (void) $this->useCase(new ArrayObject(), new MockResponse('', ['error' => 'Bearer '.$key]))->keep($key);
            self::fail('The provider should not be reached.');
        } catch (KeyNotChecked $unchecked) {
            self::assertStringNotContainsString($key, (string) $unchecked);
            self::assertNotInstanceOf(Throwable::class, $unchecked->getPrevious());
        }
    }

    public function testAKeyKeptByAnotherRequestWhileThisKeyIsCheckedIsNotReplaced(): void
    {
        $providers = new SymfonyAiProviderCatalog();
        $file = new KeptProviderKeyFile($this->directory, $providers);
        $first = 'sk-ant-'.self::BODY;
        // Forces the order of a race, because the other request passed the "none configured" guard too and finishes while this one waits on its provider check.
        $check = self::createStub(KeyCheck::class);
        $check->method('check')->willReturnCallback(static function () use ($file, $first, $providers): KeyCheckOutcome {
            $file->keep(ProviderKey::recognise($first, $providers));

            return KeyCheckOutcome::Accepted;
        });

        try {
            (void) new KeepProviderKey($check, $file, $providers)->keep('hf_'.self::BODY);
            self::fail('The second key should be refused.');
        } catch (ProviderKeyAlreadyKept) {
            self::assertSame($first, $file->read()?->secret());
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/'.uniqid('keep-key-', true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answer(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    /**
     * @param ArrayObject<int, string> $calls
     */
    private function useCase(ArrayObject $calls, MockResponse $answer): KeepProviderKey
    {
        $providers = new SymfonyAiProviderCatalog();
        $http = new MockHttpClient(static function (string $method, string $url) use ($calls, $answer): MockResponse {
            $calls->append($url);

            return $answer;
        });

        return new KeepProviderKey(new SymfonyAiKeyCheck($http, $providers, $this->lightTiers(), new NullLogger()), new KeptProviderKeyFile($this->directory, $providers), $providers);
    }

    private function lightTiers(): TierResolver
    {
        $tiers = self::createStub(TierResolver::class);
        // One project per provider, each with its light tier on that provider, so every key has a check model.
        $tiers->method('projects')->willReturn(new ProjectNames(...array_map(static fn (Provider $provider): string => $provider->value, Provider::cases())));
        $tiers->method('configured')->willReturnCallback(static fn (string $project): ResolvedModel => new ResolvedModel(Provider::from($project), 'light-'.$project));

        return $tiers;
    }
}
