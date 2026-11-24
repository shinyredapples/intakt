<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccountCredentials;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ConfiguredAccountCredentials::class)]
#[UsesClass(Account::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexLoginUnusable::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexTokenRefresh::class)]
#[UsesClass(ConfiguredAccounts::class)]
final class ConfiguredAccountCredentialsTest extends TestCase
{
    private string $root;

    public function testAnEnabledAccountWithKeyVariablesHasItsCredentials(): void
    {
        self::assertTrue($this->credentials(['anthropic' => ['team']])->areSet(new Account(Provider::Anthropic, 'team')));
    }

    public function testAnAccountThatIsNotEnabledLacksItsCredentials(): void
    {
        self::assertFalse($this->credentials(['anthropic' => ['default']])->areSet(new Account(Provider::Anthropic, 'team')));
    }

    public function testAnEnabledCodexAccountWithoutItsLoginFileLacksItsCredentials(): void
    {
        self::assertFalse($this->credentials(['codex' => ['team']])->areSet(new Account(Provider::Codex, 'team')));
    }

    public function testAnEnabledCodexAccountWithItsLoginFileHasItsCredentials(): void
    {
        new Filesystem()->dumpFile($this->root.'/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));

        self::assertTrue($this->credentials(['codex' => ['team']])->areSet(new Account(Provider::Codex, 'team')));
    }

    public function testAnEnabledCodexAccountWhoseLoginFileHoldsNoTokensLacksItsCredentials(): void
    {
        new Filesystem()->dumpFile($this->root.'/logins/team/auth.json', '{}');

        self::assertFalse($this->credentials(['codex' => ['team']])->areSet(new Account(Provider::Codex, 'team')));
    }

    public function testACodexLoginFileDoesNotMakeAnAccountThatIsNotEnabledReady(): void
    {
        new Filesystem()->dumpFile($this->root.'/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));

        self::assertFalse($this->credentials([])->areSet(new Account(Provider::Codex, 'team')));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-credentials-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    /**
     * @param array<string, list<string>> $enabled
     */
    private function credentials(array $enabled): ConfiguredAccountCredentials
    {
        $logins = new CodexLogins($this->root, new CodexTokenRefresh(new MockHttpClient(), new MockClock('2026-11-02 12:00:00', 'UTC')), new LockFactory(new InMemoryStore()));

        return new ConfiguredAccountCredentials(new ConfiguredAccounts($enabled), $logins);
    }
}
