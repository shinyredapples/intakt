<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CodexLogins::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexLoginUnusable::class)]
#[UsesClass(CodexTokenRefresh::class)]
final class CodexLoginsTest extends TestCase
{
    private string $root;

    private MockHttpClient $http;

    private InMemoryStore $lockStore;

    public function testACallGetsAReadOnlyCopyOfTheLoginInItsOwnHome(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');

        $home = $logins->handOver('team');

        self::assertSame($this->root.'/homes/team', $home);
        self::assertSame(json_decode(CodexLoginTest::json('2026-11-01T00:00:00Z'), true), json_decode((string) file_get_contents($home.'/auth.json'), true));
        self::assertSame(0o400, fileperms($home.'/auth.json') & 0o777);
    }

    public function testTheCallHomeNeverHoldsTheKeptLogin(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');

        $home = $logins->handOver('team');
        chmod($home.'/auth.json', 0o600);
        file_put_contents($home.'/auth.json', 'written by the call');

        self::assertSame(CodexLoginTest::json('2026-11-01T00:00:00Z'), file_get_contents($logins->loginFile('team')));
    }

    public function testAHandOverReplacesWhatTheLastCallLeftInItsCopy(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');
        $home = $logins->handOver('team');
        chmod($home.'/auth.json', 0o600);
        file_put_contents($home.'/auth.json', 'written by the call');

        $logins->handOver('team');

        self::assertSame(json_decode(CodexLoginTest::json('2026-11-01T00:00:00Z'), true), json_decode((string) file_get_contents($home.'/auth.json'), true));
    }

    public function testALinkTheCallLeftInPlaceOfItsCopyDoesNotRedirectTheHandOver(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');
        new Filesystem()->dumpFile($logins->loginFile('other'), 'other account login');
        $home = $logins->handOver('team');
        unlink($home.'/auth.json');
        symlink($logins->loginFile('other'), $home.'/auth.json');

        $logins->handOver('team');

        self::assertSame('other account login', file_get_contents($logins->loginFile('other')));
        self::assertFalse(is_link($home.'/auth.json'));
    }

    public function testALinkTheCallLeftInPlaceOfItsHomeDoesNotRedirectTheHandOver(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');
        new Filesystem()->dumpFile($logins->loginFile('other'), 'other account login');
        $home = $logins->handOver('team');
        new Filesystem()->remove($home);
        symlink(dirname($logins->loginFile('other')), $home);

        $logins->handOver('team');

        self::assertSame('other account login', file_get_contents($logins->loginFile('other')));
        self::assertFalse(is_link($home));
    }

    public function testAFreshLoginIsHandedOverWithoutARefresh(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-11-01T00:00:00Z');

        $logins->handOver('team');

        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testADueLoginIsRefreshedAndKeptBeforeTheCallGetsIt(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-10-01T00:00:00Z');

        $home = $logins->handOver('team');

        /** @var array{tokens: array<string, string>, last_refresh: string} $kept */
        $kept = json_decode((string) file_get_contents($logins->loginFile('team')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id-1', 'access-2', 'refresh-2', '2026-11-02T12:00:00Z'], [$kept['tokens']['id_token'], $kept['tokens']['access_token'], $kept['tokens']['refresh_token'], $kept['last_refresh']]);
        self::assertSame(0o600, fileperms($logins->loginFile('team')) & 0o777);
        self::assertSame($kept, json_decode((string) file_get_contents($home.'/auth.json'), true));
    }

    public function testARefreshedLoginIsNotRefreshedByTheNextCall(): void
    {
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-10-01T00:00:00Z');

        $logins->handOver('team');
        $logins->handOver('team');

        self::assertSame(1, $this->http->getRequestsCount());
    }

    public function testAFailedRefreshKeepsTheStoredLoginAndHandsNothingOver(): void
    {
        $this->http = new MockHttpClient(new JsonMockResponse(['error' => 'server_error'], ['http_code' => 500]));
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-10-01T00:00:00Z');

        try {
            $logins->handOver('team');
            self::fail('The refresh did not fail.');
        } catch (CodexLoginUnusable) {
            self::assertSame(CodexLoginTest::json('2026-10-01T00:00:00Z'), file_get_contents($logins->loginFile('team')));
            self::assertFileDoesNotExist($this->root.'/homes/team/auth.json');
        }
    }

    public function testTheLockIsReleasedAfterAFailedRefresh(): void
    {
        $this->http = new MockHttpClient(new JsonMockResponse(['error' => 'server_error'], ['http_code' => 500]));
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-10-01T00:00:00Z');

        try {
            $logins->handOver('team');
        } catch (CodexLoginUnusable) {
        }

        self::assertTrue(new LockFactory($this->lockStore)->createLock('intakt.codex.login.team')->acquire());
    }

    public function testTheRefreshRunsWhileTheAccountsLockIsHeld(): void
    {
        $heldDuringRefresh = null;
        $this->http = new MockHttpClient(function () use (&$heldDuringRefresh): JsonMockResponse {
            $heldDuringRefresh = !new LockFactory($this->lockStore)->createLock('intakt.codex.login.team')->acquire();

            return new JsonMockResponse(['access_token' => 'access-2']);
        });
        $logins = $this->logins();
        $this->storeLogin($logins, '2026-10-01T00:00:00Z');

        $logins->handOver('team');

        self::assertTrue($heldDuringRefresh);
    }

    public function testAnAccountWithoutALoginNamesWhereItGoes(): void
    {
        $logins = $this->logins();

        $this->expectException(CodexLoginUnusable::class);
        $this->expectExceptionMessageMatches('#'.preg_quote($this->root.'/logins/team/auth.json', '#').'#');

        $logins->handOver('team');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNoAccount(): iterable
    {
        yield 'a path upwards' => ['../team'];
        yield 'a name with a slash' => ['team/b'];
        yield 'an uppercase name' => ['Team'];
        yield 'an empty name' => [''];
    }

    #[DataProvider('namesThatAreNoAccount')]
    public function testANameNoAccountCanHaveIsRefused(string $name): void
    {
        $this->expectException(CodexLoginUnusable::class);

        $this->logins()->handOver($name);
    }

    public function testEveryNameAnAccountCanHaveIsAccepted(): void
    {
        self::assertSame($this->root.'/logins/team_2/auth.json', $this->logins()->loginFile('team_2'));
        self::assertSame($this->root.'/logins/default/auth.json', $this->logins()->loginFile(Account::DEFAULT));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-codex-'.bin2hex(random_bytes(6));
        $this->http = new MockHttpClient(new JsonMockResponse(['access_token' => 'access-2', 'refresh_token' => 'refresh-2']));
        $this->lockStore = new InMemoryStore();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    private function logins(): CodexLogins
    {
        return new CodexLogins($this->root, new CodexTokenRefresh($this->http, new MockClock('2026-11-02 12:00:00', 'UTC')), new LockFactory($this->lockStore));
    }

    private function storeLogin(CodexLogins $logins, string $lastRefresh): void
    {
        new Filesystem()->dumpFile($logins->loginFile('team'), CodexLoginTest::json($lastRefresh));
    }
}
