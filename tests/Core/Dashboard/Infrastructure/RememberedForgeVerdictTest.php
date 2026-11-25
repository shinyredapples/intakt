<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\RememberedForgeVerdict;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnection;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RememberedForgeVerdict::class)]
#[UsesClass(ForgeConnectionVerdict::class)]
final class RememberedForgeVerdictTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $cache;
    private int $runs = 0;
    private ForgeConnectionVerdict $verdict;

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

        self::assertSame('GITHUB_APP_INSTALLATION_ID is empty', $this->remembered()->latest()->problem);
        self::assertSame(2, $this->runs);
    }

    public function testACredentialInTheProblemIsRedactedBeforeTheVerdictIsKept(): void
    {
        $this->verdict = ForgeConnectionVerdict::failed('refused for token synthetic-marker', 'set it');

        $shown = $this->remembered()->latest();

        self::assertSame('refused for token [redacted]', $shown->problem);
        self::assertSame('set it', $shown->fix);
        self::assertStringNotContainsString('synthetic-marker', serialize($this->cache->getValues()));
    }

    public function testAnInstallWithoutAnAppStaysNotConfiguredWhenItIsRemembered(): void
    {
        $this->verdict = ForgeConnectionVerdict::withoutAnApp();
        $this->remembered()->latest();

        self::assertFalse($this->remembered()->latest()->configured);
    }

    public function testAStoredValueThatIsNoVerdictIsCheckedAgain(): void
    {
        $this->cache->save($this->cache->getItem('forge-connection')->set('not a verdict'));

        self::assertTrue($this->remembered()->latest()->passes());
        self::assertSame(1, $this->runs);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->cache = new ArrayAdapter(clock: $this->clock);
        $this->verdict = ForgeConnectionVerdict::passed();
    }

    private function remembered(): RememberedForgeVerdict
    {
        $forge = self::createStub(ForgeConnection::class);
        $forge->method('run')->willReturnCallback(function (): ForgeConnectionVerdict {
            ++$this->runs;

            return $this->verdict;
        });
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('synthetic-marker', '[redacted]', $text));

        return new RememberedForgeVerdict($forge, $this->cache, $redactor);
    }
}
