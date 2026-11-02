<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Domain\EveryAccountShadowGuard;
use Intakt\Tests\Core\Budget\ScriptedAccountGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EveryAccountShadowGuard::class)]
#[UsesClass(Account::class)]
#[UsesClass(ConfiguredAccounts::class)]
final class EveryAccountShadowGuardTest extends TestCase
{
    public function testAProviderStartsWhileEveryEnabledAccountIsBelowTheCeiling(): void
    {
        $guard = new ScriptedAccountGuard();

        $decision = $this->shadowGuard($guard)->decide(Provider::Anthropic);

        self::assertSame(StartDecision::Start, $decision);
        self::assertSame(['anthropic.default', 'anthropic.team'], $guard->asked);
    }

    public function testAProviderIsRefusedWhileOneEnabledAccountIsAboveTheCeiling(): void
    {
        $guard = new ScriptedAccountGuard(['anthropic.team' => StartDecision::AtCeiling]);

        self::assertSame(StartDecision::AtCeiling, $this->shadowGuard($guard)->decide(Provider::Anthropic));
    }

    public function testAProviderWithoutAnEnabledAccountStarts(): void
    {
        $guard = new ScriptedAccountGuard(['openai.default' => StartDecision::AtCeiling]);

        self::assertSame(StartDecision::Start, $this->shadowGuard($guard)->decide(Provider::OpenAi));
    }

    private function shadowGuard(ScriptedAccountGuard $guard): EveryAccountShadowGuard
    {
        return new EveryAccountShadowGuard($guard, new ConfiguredAccounts([Provider::Anthropic->value => ['default', 'team']]));
    }
}
