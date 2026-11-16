<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\KeepProviderKey;

use ArrayObject;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeepProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyNotChecked;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyRefusedByProvider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

#[CoversClass(KeepProviderKey::class)]
#[CoversClass(KeyRefusedByProvider::class)]
#[CoversClass(KeyNotChecked::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(ConfiguredKey::class)]
#[UsesClass(KeyKind::class)]
#[UsesClass(KeyNotRecognised::class)]
#[UsesClass(SymfonyAiKeyCheck::class)]
#[UsesClass(KeyCheckOutcome::class)]
final class KeepProviderKeyTest extends TestCase
{
    private const string BODY = 'abcdefgh12345678';

    public function testARecognisedKeyTheProviderAcceptsIsKeptAsConfiguredWithItsLastCharactersOnly(): void
    {
        $configured = $this->useCase(new ArrayObject(), self::answer(200, [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]))->keep('sk-ant-'.self::BODY);

        self::assertSame(KeyKind::Anthropic, $configured->kind);
        self::assertSame('5678', $configured->lastCharacters);
    }

    public function testAKeyTheProviderRefusesThrowsKeyRefusedByProvider(): void
    {
        $this->expectException(KeyRefusedByProvider::class);

        (void) $this->useCase(new ArrayObject(), self::answer(401, ['error' => ['message' => 'Invalid credentials']]))->keep('hf_'.self::BODY);
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
            (void) $this->useCase($calls, self::answer(200, []))->keep('not a key');
            self::fail('The key should not be recognised.');
        } catch (KeyNotRecognised) {
            self::assertCount(0, $calls);
        }
    }

    public function testARefusedKeyAppearsNeitherInTheExceptionNorItsChain(): void
    {
        $key = 'sk-ant-'.self::BODY;

        try {
            (void) $this->useCase(new ArrayObject(), self::answer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.$key]]))->keep($key);
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

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    /**
     * @param ArrayObject<int, string> $calls
     */
    private function useCase(ArrayObject $calls, MockResponse $answer): KeepProviderKey
    {
        return new KeepProviderKey(new SymfonyAiKeyCheck(new MockHttpClient(static function (string $method, string $url) use ($calls, $answer): MockResponse {
            $calls->append($url);

            return $answer;
        })));
    }
}
