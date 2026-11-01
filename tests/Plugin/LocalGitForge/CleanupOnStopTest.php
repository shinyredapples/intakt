<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanupOnStop::class)]
final class CleanupOnStopTest extends TestCase
{
    public function testAStopDuringTheWorkRunsTheCleanupOnce(): void
    {
        $onStop = new CleanupOnStop();
        $runs = 0;

        $onStop->during(static function () use (&$runs): void {
            ++$runs;
        }, static function () use ($onStop): void {
            $onStop->stop();
            $onStop->stop();
        });

        self::assertSame(1, $runs);
    }

    public function testAStopAfterTheWorkRunsNoCleanup(): void
    {
        $onStop = new CleanupOnStop();
        $runs = 0;
        $onStop->during(static function () use (&$runs): void {
            ++$runs;
        }, static fn (): null => null);

        $onStop->stop();

        self::assertSame(0, $runs);
    }

    public function testTheWorkGivesItsResult(): void
    {
        $result = new CleanupOnStop()->during(static function (): void {
        }, static fn (): string => 'reviewed');

        self::assertSame('reviewed', $result);
    }

    public function testAResetForgetsTheCleanup(): void
    {
        $onStop = new CleanupOnStop();
        $runs = 0;
        $onStop->during(static function () use (&$runs): void {
            ++$runs;
        }, static function () use ($onStop): void {
            $onStop->reset();
            $onStop->stop();
        });

        self::assertSame(0, $runs);
    }
}
