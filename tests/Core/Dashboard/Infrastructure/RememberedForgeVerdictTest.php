<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Intakt\Core\Dashboard\Infrastructure\RememberedForgeVerdict;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnection;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RememberedForgeVerdict::class)]
#[UsesClass(ForgeConnectionVerdict::class)]
final class RememberedForgeVerdictTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $cache;
    private int $runs = 0;
    private ForgeConnectionVerdict $verdict;
    private LockFactory $locks;
    private bool $pauseFirstRun = false;

    public function testAVerdictOfTheLastMinuteIsShownWithoutRunningTheCheckAgain(): void
    {
        $first = $this->remembered()->latest();
        $this->clock->sleep(59);
        $second = $this->remembered()->latest();

        self::assertSame(1, $this->runs);
        self::assertEquals($first, $second);
    }

    public function testAVerdictOlderThanAMinuteIsCheckedAgain(): void
    {
        $this->remembered()->latest();
        $this->clock->sleep(61);
        $this->verdict = ForgeConnectionVerdict::failed('GITHUB_APP_INSTALLATION_ID is empty', 'set it');

        self::assertSame('GITHUB_APP_INSTALLATION_ID is empty', $this->remembered()->latest()?->problem);
        self::assertSame(2, $this->runs);
    }

    public function testACredentialInTheProblemIsRedactedBeforeTheVerdictIsKept(): void
    {
        $this->verdict = ForgeConnectionVerdict::failed('refused for token synthetic-marker', 'set it');

        $shown = $this->remembered()->latest();

        self::assertSame('refused for token [redacted]', $shown?->problem);
        self::assertSame('set it', $shown?->fix);
        self::assertStringNotContainsString('synthetic-marker', serialize($this->cache->getValues()));
    }

    public function testAnInstallWithoutAnAppStaysNotConfiguredWhenItIsRemembered(): void
    {
        $this->verdict = ForgeConnectionVerdict::withoutAnApp();
        $this->remembered()->latest();

        self::assertFalse($this->remembered()->latest()?->configured);
    }

    public function testAStoredValueThatIsNoVerdictIsCheckedAgain(): void
    {
        $this->cache->save($this->cache->getItem('forge-connection')->set('not a verdict'));

        self::assertTrue($this->remembered()->latest()?->passes());
        self::assertSame(1, $this->runs);
    }

    public function testTwoOverlappingLoadsOfAnExpiredVerdictRunTheCheckOnce(): void
    {
        $this->pauseFirstRun = true;
        $first = new Fiber($this->remembered()->latest(...));
        $second = new Fiber($this->remembered()->latest(...));

        $first->start();
        $second->start();
        $first->resume();

        self::assertSame(1, $this->runs);
        self::assertTrue($first->getReturn()?->passes());
        self::assertNull($second->getReturn());
    }

    public function testALoadThatFindsTheCheckRunningElsewhereShowsTheKeptVerdictOnceItIsKept(): void
    {
        $lock = $this->locks->createLock('dashboard.forge-verdict');
        $lock->acquire();

        self::assertNull($this->remembered()->latest());

        $lock->release();
        self::assertTrue($this->remembered()->latest()?->passes());
        self::assertSame(1, $this->runs);
    }

    public function testTheCheckLeavesTheLockFreeForTheNextExpiredVerdict(): void
    {
        $this->remembered()->latest();

        self::assertTrue($this->locks->createLock('dashboard.forge-verdict')->acquire());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->cache = new ArrayAdapter(clock: $this->clock);
        $this->verdict = ForgeConnectionVerdict::passed();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    private function remembered(): RememberedForgeVerdict
    {
        $forge = self::createStub(ForgeConnection::class);
        $forge->method('run')->willReturnCallback(function (): ForgeConnectionVerdict {
            // Paused inside the first run, so a second load arrives while the check is still out at the forge.
            if (1 === ++$this->runs && $this->pauseFirstRun) {
                Fiber::suspend();
            }

            return $this->verdict;
        });
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('synthetic-marker', '[redacted]', $text));

        return new RememberedForgeVerdict($forge, $this->cache, $redactor, $this->locks);
    }
}
