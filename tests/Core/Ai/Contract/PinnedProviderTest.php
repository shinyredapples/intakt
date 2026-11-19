<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PinnedProvider::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class PinnedProviderTest extends TestCase
{
    public function testTheKeyNamesTheAccountAndTheRouterProvider(): void
    {
        $pinned = new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'deepinfra');

        self::assertSame('openai_compatible.default:deepinfra', $pinned->key());
    }

    public function testTheSameRouterProviderOnAnotherAccountHasAnotherKey(): void
    {
        $default = new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'deepinfra');
        $team = new PinnedProvider(new Account(Provider::OpenAiCompatible, 'team'), 'deepinfra');

        self::assertNotSame($default->key(), $team->key());
    }

    public function testARouterProviderNameHoldingAColonCannotTakeAnotherAccountsKey(): void
    {
        $default = new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'team:deepinfra');
        $team = new PinnedProvider(new Account(Provider::OpenAiCompatible, 'team'), 'deepinfra');

        self::assertNotSame($default->key(), $team->key());
    }
}
