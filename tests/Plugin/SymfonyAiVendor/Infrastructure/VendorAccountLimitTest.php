<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

#[CoversClass(VendorAccountLimit::class)]
#[UsesClass(VendorAccountBusy::class)]
final class VendorAccountLimitTest extends TestCase
{
    public function testACallBeyondAnAccountsParallelLimitIsRefusedWhileTheOtherCallsHoldTheSemaphore(): void
    {
        $limit = self::limit(2);
        $ran = [];

        $limit->run(Provider::Anthropic, function () use ($limit, &$ran): void {
            $limit->run(Provider::Anthropic, function () use ($limit, &$ran): void {
                try {
                    $limit->run(Provider::Anthropic, static function () use (&$ran): void {
                        $ran[] = 'third';
                    });
                } catch (VendorAccountBusy) {
                    $ran[] = 'refused';
                }
            });
        });

        self::assertSame(['refused'], $ran);
    }

    public function testAnotherAccountIsNotCappedByAFullOne(): void
    {
        $limit = self::limit(1);

        $result = $limit->run(Provider::Anthropic, static fn (): string => $limit->run(Provider::OpenAi, static fn (): string => 'ran'));

        self::assertSame('ran', $result);
    }

    public function testThePlaceIsFreeAgainWhenTheCallEnds(): void
    {
        $limit = self::limit(1);
        $limit->run(Provider::Anthropic, static fn (): null => null);

        self::assertSame('again', $limit->run(Provider::Anthropic, static fn (): string => 'again'));
    }

    public function testThePlaceIsFreeAgainWhenTheCallFails(): void
    {
        $limit = self::limit(1);

        try {
            $limit->run(Provider::Anthropic, static fn () => throw new RuntimeException('provider down'));
            self::fail('The failure was swallowed.');
        } catch (RuntimeException) {
        }

        self::assertSame('again', $limit->run(Provider::Anthropic, static fn (): string => 'again'));
    }

    private static function limit(int $parallelCalls): VendorAccountLimit
    {
        return new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), $parallelCalls);
    }
}
