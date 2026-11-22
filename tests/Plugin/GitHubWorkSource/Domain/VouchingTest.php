<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Vouching::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(LabelApplication::class)]
final class VouchingTest extends TestCase
{
    public function testAVouchingRestoredFromAStoredRowIsTrustedAndKeepsItsLabelsInOrder(): void
    {
        $vouching = Vouching::restored(['ready', 'priority']);

        self::assertTrue($vouching->trusted);
        self::assertSame(['ready', 'priority'], $vouching->labels);
    }

    public function testAnUntrustedVouchingCarriesNoLabels(): void
    {
        $vouching = Vouching::untrusted();

        self::assertFalse($vouching->trusted);
        self::assertSame([], $vouching->labels);
    }

    public function testAnAuthorWithoutWriteAccessYieldsAnUntrustedVouchingWithNoLabels(): void
    {
        $history = new IssueHistory('ana', [new LabelApplication('ready', 'ana')], '', ['ready']);

        $vouching = Vouching::of('mallory', $history, static fn (string $account): bool => 'ana' === $account);

        self::assertFalse($vouching->trusted);
        self::assertSame([], $vouching->labels);
    }
}
