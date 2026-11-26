<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnvironmentVariables;
use Intakt\Core\Ai\Contract\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Account::class)]
#[UsesClass(Provider::class)]
final class AccountTest extends TestCase
{
    public function testTheDefaultAccountReadsTheProvidersOwnVariables(): void
    {
        self::assertSame(['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'], [...new Account(Provider::OpenAiCompatible, Account::DEFAULT)->variables()]);
    }

    public function testAnotherAccountReadsTheProvidersVariablesWithItsNameAppended(): void
    {
        self::assertSame(['OPENAI_COMPATIBLE_BASE_URL_TEAM_B', 'OPENAI_COMPATIBLE_API_KEY_TEAM_B'], [...new Account(Provider::OpenAiCompatible, 'team_b')->variables()]);
    }

    public function testTheKeyNamesTheProviderAndTheAccount(): void
    {
        self::assertSame('anthropic.team', new Account(Provider::Anthropic, 'team')->key());
    }

    public function testAccountsOfTwoProvidersWithOneNameHaveTwoKeys(): void
    {
        self::assertNotSame(new Account(Provider::OpenAi, 'team')->key(), new Account(Provider::OpenAiCompatible, 'team')->key());
    }

    public function testTheDisabledMessageNamesTheAccountAndItsMissingVariables(): void
    {
        self::assertSame(
            'Account "team" of provider "anthropic" is disabled: set ANTHROPIC_API_KEY_TEAM to enable it.',
            new Account(Provider::Anthropic, 'team')->disabledMessage(new EnvironmentVariables('ANTHROPIC_API_KEY_TEAM')),
        );
    }
}
