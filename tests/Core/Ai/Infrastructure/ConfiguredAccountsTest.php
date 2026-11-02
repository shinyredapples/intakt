<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredAccounts::class)]
#[UsesClass(Account::class)]
final class ConfiguredAccountsTest extends TestCase
{
    public function testTheEnabledAccountsOfAProviderComeInTheOwnersOrder(): void
    {
        $accounts = new ConfiguredAccounts(['anthropic' => ['team', 'default']]);

        self::assertSame(['anthropic.team', 'anthropic.default'], array_map(static fn (Account $account): string => $account->key(), $accounts->of(Provider::Anthropic)));
    }

    public function testAProviderWithoutAnEnabledAccountHasNone(): void
    {
        self::assertSame([], new ConfiguredAccounts(['anthropic' => ['default']])->of(Provider::OpenAi));
    }
}
