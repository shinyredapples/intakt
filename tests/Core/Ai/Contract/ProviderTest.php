<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Provider::class)]
final class ProviderTest extends TestCase
{
    public function testNeitherSubscriptionCliReadsTheCheckout(): void
    {
        self::assertSame([false, false], [Provider::ClaudeCode->readsTheCheckout(), Provider::Codex->readsTheCheckout()]);
    }

    public function testAnApiProviderReadsTheCheckout(): void
    {
        self::assertSame([true, true, true], [Provider::Anthropic->readsTheCheckout(), Provider::OpenAi->readsTheCheckout(), Provider::OpenAiCompatible->readsTheCheckout()]);
    }

    public function testOnlyTheApiProvidersStream(): void
    {
        self::assertSame(
            [true, true, true, false, false],
            [Provider::Anthropic->streams(), Provider::OpenAi->streams(), Provider::OpenAiCompatible->streams(), Provider::ClaudeCode->streams(), Provider::Codex->streams()],
        );
    }

    public function testCodexNeedsNoVariableBecauseItsLoginIsAFile(): void
    {
        self::assertSame([], Provider::Codex->variables());
    }
}
