<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\PinnableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;

// The container's own pool and clock, pinned so the window ends without waiting.
#[CoversClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnableClock::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(PinnedProvider::class)]
final class PinnedProviderCooldownWindowsWiringTest extends KernelTestCase
{
    public function testTheInstalledStoreCoolsAPinnedProviderDownAndReleasesItAfterTheWindow(): void
    {
        $container = self::getContainer();
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(PinnableClock::class, $clock);
        $clock->pinAt('2026-11-19T12:00:00+00:00');
        $cooldowns = $container->get('test.intakt.pinned_provider_cooldowns');
        self::assertInstanceOf(PinnedProviderCooldownWindows::class, $cooldowns);
        $deepinfra = new PinnedProvider(new Account(Provider::OpenAiCompatible, Account::DEFAULT), 'deepinfra');

        $cooldowns->coolDown($deepinfra, 60);
        $during = $cooldowns->isCoolingDown($deepinfra);
        $clock->sleep(60);

        self::assertSame([true, false], [$during, $cooldowns->isCoolingDown($deepinfra)]);
    }
}
