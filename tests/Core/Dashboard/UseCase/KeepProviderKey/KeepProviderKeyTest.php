<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\KeepProviderKey;

use ArrayObject;
use Closure;
use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeepProviderKey;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyNotChecked;
use Intakt\Core\Dashboard\UseCase\KeepProviderKey\KeyRefusedByProvider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiKeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure\ScriptedKeyedPlatforms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Exception\AuthenticationException;
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
        $configured = $this->useCase(new ArrayObject(), static fn (): string => 'ok')->keep('sk-ant-'.self::BODY);

        self::assertSame(KeyKind::Anthropic, $configured->kind);
        self::assertSame('5678', $configured->lastCharacters);
    }

    public function testAKeyTheProviderRefusesThrowsKeyRefusedByProvider(): void
    {
        $this->expectException(KeyRefusedByProvider::class);

        (void) $this->useCase(new ArrayObject(), static fn (): never => throw new AuthenticationException('bad'))->keep('hf_'.self::BODY);
    }

    public function testAKeyTheCheckCouldNotReachThrowsKeyNotChecked(): void
    {
        $this->expectException(KeyNotChecked::class);

        (void) $this->useCase(new ArrayObject(), static fn (): never => throw new RuntimeException('down'))->keep('sk-'.self::BODY);
    }

    public function testAnUnrecognisedKeyIsRefusedBeforeAnyCallIsMade(): void
    {
        $calls = new ArrayObject();

        try {
            (void) $this->useCase($calls, static fn (): string => 'ok')->keep('not a key');
            self::fail('The key should not be recognised.');
        } catch (KeyNotRecognised) {
            self::assertCount(0, $calls);
        }
    }

    public function testARefusedKeyAppearsNeitherInTheExceptionNorItsChain(): void
    {
        $key = 'sk-ant-'.self::BODY;

        try {
            (void) $this->useCase(new ArrayObject(), static fn (): never => throw new AuthenticationException('Invalid x-api-key '.$key))->keep($key);
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
            (void) $this->useCase(new ArrayObject(), static fn (): never => throw new RuntimeException('Bearer '.$key))->keep($key);
            self::fail('The provider should not be reached.');
        } catch (KeyNotChecked $unchecked) {
            self::assertStringNotContainsString($key, (string) $unchecked);
            self::assertNotInstanceOf(Throwable::class, $unchecked->getPrevious());
        }
    }

    /**
     * @param ArrayObject<int, array{string, array<mixed>}> $calls
     */
    private function useCase(ArrayObject $calls, Closure $answer): KeepProviderKey
    {
        return new KeepProviderKey(new SymfonyAiKeyCheck(new ScriptedKeyedPlatforms($calls, $answer)));
    }
}
