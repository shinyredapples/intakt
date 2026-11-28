<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\EnvironmentAccountLogins;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EnvironmentAccountLogins::class)]
#[CoversClass(AccountLogin::class)]
#[UsesClass(Account::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(EveryKeyRejected::class)]
final class EnvironmentAccountLoginsTest extends TestCase
{
    private const array TOKENS = ['default' => 'PLANT-first', 'team' => 'PLANT-second'];

    private AccountWindowsOnAClock $cooldowns;

    public function testTheFirstFreeAccountInTheOwnersOrderIsTaken(): void
    {
        $login = new EnvironmentAccountLogins(self::TOKENS, $this->cooldowns)->claudeCode();

        self::assertSame(['claude_code.default', 'PLANT-first'], [$login->account->key(), $login->token]);
    }

    public function testAnAccountThatCoolsDownIsPassedOverWithItsToken(): void
    {
        $this->cooldowns->coolDown(new Account(Provider::ClaudeCode, 'default'), 60);

        $login = new EnvironmentAccountLogins(self::TOKENS, $this->cooldowns)->claudeCode();

        self::assertSame(['claude_code.team', 'PLANT-second'], [$login->account->key(), $login->token]);
    }

    public function testEveryAccountCoolingDownGivesNoLogin(): void
    {
        $this->cooldowns->coolDown(new Account(Provider::ClaudeCode, 'default'), 60);
        $this->cooldowns->coolDown(new Account(Provider::ClaudeCode, 'team'), 90);

        $this->expectException(EveryAccountCoolingDown::class);

        new EnvironmentAccountLogins(self::TOKENS, $this->cooldowns)->claudeCode();
    }

    public function testEveryRejectedKeyIsNothingToWaitFor(): void
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturn(true);
        $cooldowns->method('isEveryAccountRejected')->willReturn(true);

        $this->expectException(EveryKeyRejected::class);

        new EnvironmentAccountLogins(self::TOKENS, $cooldowns)->claudeCode();
    }

    protected function setUp(): void
    {
        $this->cooldowns = new AccountWindowsOnAClock(new MockClock('2026-11-25 10:00:00'), ['claude_code' => ['default', 'team']]);
    }
}
