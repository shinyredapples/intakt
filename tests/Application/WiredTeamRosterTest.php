<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccountCredentials;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use Intakt\Core\Ai\Infrastructure\ConfiguredListedAccounts;
use Intakt\Core\Dashboard\UseCase\ShowTeam\AccountState;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;

// The roster as the container wires it from the config file, because which accounts are listed and which keys they lack is decided in config/packages/ai.php.
#[CoversClass(TeamRoster::class)]
#[UsesClass(Account::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexLoginUnusable::class)]
#[UsesClass(ConfiguredAccountCredentials::class)]
#[UsesClass(ConfiguredAccounts::class)]
#[UsesClass(ConfiguredListedAccounts::class)]
#[UsesClass(Team::class)]
#[UsesClass(TeamAccount::class)]
final class WiredTeamRosterTest extends KernelTestCase
{
    // The compiled containers are keyed by these variables alone, so the container must not read at compile time any other variable that a test changes.
    private const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG'];

    private string $file = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private CompiledContainers $containers;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testAnAccountWithItsKeyIsReady(): void
    {
        $this->listAccounts('anthropic: [default]');

        self::assertSame(AccountState::Ready, $this->roster()['anthropic.default'] ?? null);
    }

    public function testAnAccountListedUnderTheHyphenatedProviderKeyHasNoKey(): void
    {
        $this->listAccounts('openai-compatible: [team]');

        self::assertSame(AccountState::NoKey, $this->roster()['openai_compatible.team'] ?? null);
    }

    public function testACodexAccountListedWithoutTheCodexSectionHasNoKey(): void
    {
        $this->listAccounts('codex: [team]');

        self::assertSame(AccountState::NoKey, $this->roster()['codex.team'] ?? null);
    }

    public function testCodexIsLeftOutWhenTheFileNeitherListsItsAccountsNorHasItsSection(): void
    {
        self::assertArrayNotHasKey('codex.default', $this->roster());
    }

    public function testACodexAccountWithoutItsLoginFileHasNoKey(): void
    {
        file_put_contents($this->file, "codex: { model: gpt-6.2-sol, effort: high }\n");
        $this->listAccounts('codex: [nologin]');

        self::assertSame(AccountState::NoKey, $this->roster()['codex.nologin'] ?? null);
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach (self::KEYS as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-roster-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, "projects:\n    alpha:\n        tiers:\n            strong: { provider: anthropic, model: big }\n");
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            $this->unsetVariable($name);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (false !== $before['process']) {
                putenv($name.'='.$before['process']);
            }
        }

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    private function listAccounts(string $line): void
    {
        file_put_contents($this->file, "\naccounts:\n    {$line}\n", FILE_APPEND);
    }

    /**
     * @return array<string, AccountState>
     */
    private function roster(): array
    {
        self::ensureKernelShutdown();
        // Keyed by the config and keys, because the container is built from those alone; INTAKT_CONFIG is left out because its path changes per test.
        $keys = array_map(static fn (string $name): mixed => $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name), array_diff(self::KEYS, ['INTAKT_CONFIG']));
        $this->containers->useFor([(string) file_get_contents($this->file), $keys]);
        self::bootKernel();

        $roster = self::getContainer()->get('test.intakt.team_roster');
        self::assertInstanceOf(TeamRoster::class, $roster);
        $accounts = $roster->now()->accounts;

        return array_combine(array_map(static fn (TeamAccount $account): string => $account->account->key(), $accounts), array_map(static fn (TeamAccount $account): AccountState => $account->state, $accounts));
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'team-roster');
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
