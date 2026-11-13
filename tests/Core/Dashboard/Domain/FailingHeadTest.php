<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
final class FailingHeadTest extends TestCase
{
    public function testAnotherCauseKeepsTheFailuresTheNextTryAndTheHandOver(): void
    {
        $retryAt = new DateTimeImmutable('2026-10-28T12:08:00+00:00');
        $head = new FailingHead('alpha', '7', new HeadFault('token abc', 3, $retryAt))->handedOver();

        $redacted = $head->withCause('token [redacted]');

        self::assertSame('token [redacted]', $redacted->fault->cause);
        self::assertSame(3, $redacted->fault->failures);
        self::assertSame($retryAt, $redacted->fault->retryAt);
        self::assertTrue($redacted->handedOver);
        self::assertSame('token abc', $head->fault->cause);
    }
}
