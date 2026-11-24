<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Provider::class)]
#[UsesClass(Account::class)]
#[UsesClass(AiServices::class)]
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

    public function testOnlyTheSubscriptionClisSpendAPlan(): void
    {
        self::assertSame(
            [false, false, false, true, true],
            [Provider::Anthropic->spendsAPlan(), Provider::OpenAi->spendsAPlan(), Provider::OpenAiCompatible->spendsAPlan(), Provider::ClaudeCode->spendsAPlan(), Provider::Codex->spendsAPlan()],
        );
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
        self::assertTrue(Provider::Codex->variables()->isEmpty());
    }

    public function testTheDefaultAccountsPlatformIdCarriesNoAccountSuffix(): void
    {
        self::assertSame(AiServices::PLATFORM_PREFIX.'codex', Provider::Codex->platformId(new Account(Provider::Codex, Account::DEFAULT)));
    }

    public function testANamedAccountsPlatformIdEndsWithTheAccountName(): void
    {
        self::assertSame(AiServices::PLATFORM_PREFIX.'codex.team', Provider::Codex->platformId(new Account(Provider::Codex, 'team')));
    }

    public function testTheCliIdIsDerivedFromTheProviderAndTheAccount(): void
    {
        self::assertSame(
            [AiServices::PREFIX.'claude_code.cli', AiServices::PREFIX.'claude_code.cli.team'],
            [Provider::ClaudeCode->cliId(new Account(Provider::ClaudeCode, Account::DEFAULT)), Provider::ClaudeCode->cliId(new Account(Provider::ClaudeCode, 'team'))],
        );
    }

    public function testTheAccountsIdIsDerivedFromTheProvider(): void
    {
        self::assertSame(AiServices::ACCOUNTS_PREFIX.'openai', Provider::OpenAi->accountsId());
    }

    public function testEachProvidersConfigKeyIsItsCaseValue(): void
    {
        self::assertSame(array_column(Provider::cases(), 'value'), array_map(static fn (Provider $provider): string => $provider->configKey(), Provider::cases()));
    }

    public function testTheCodexParameterIsTheModulePrefixWithTheProvider(): void
    {
        self::assertSame('intakt.ai.codex', Provider::Codex->parameter());
    }
}
