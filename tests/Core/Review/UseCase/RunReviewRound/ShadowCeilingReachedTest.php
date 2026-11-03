<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\UseCase\RunReviewRound\ShadowCeilingReached;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShadowCeilingReached::class)]
final class ShadowCeilingReachedTest extends TestCase
{
    public function testARoundHeldAtTheCeilingSaysTheAccountIsNotBelowTheShadowCeiling(): void
    {
        $held = ShadowCeilingReached::by('alpha', 'anthropic', StartDecision::AtCeiling);

        self::assertSame('The shadow round of project "alpha" was held: an account of provider "anthropic" is not below the shadow ceiling (at-ceiling).', $held->getMessage());
    }

    public function testARoundHeldByAPauseSaysTheAccountIsNotAvailableAndWhyInPlainWords(): void
    {
        $held = ShadowCeilingReached::by('alpha', 'anthropic', StartDecision::Paused);

        self::assertSame('The shadow round of project "alpha" was held: an account of provider "anthropic" is not available for a start, because Intakt is paused (paused).', $held->getMessage());
    }

    public function testNoHoldOtherThanTheCeilingMentionsTheCeiling(): void
    {
        $messages = array_map(
            static fn (StartDecision $decision): string => ShadowCeilingReached::by('alpha', 'anthropic', $decision)->getMessage(),
            array_filter(StartDecision::cases(), static fn (StartDecision $decision): bool => !$decision->admits() && StartDecision::AtCeiling !== $decision),
        );

        self::assertCount(8, $messages);
        foreach ($messages as $message) {
            self::assertStringNotContainsString('shadow ceiling', $message);
            self::assertStringContainsString('is not available for a start, because', $message);
        }
    }

    public function testAHeldRoundIsReportedToTheCaller(): void
    {
        self::assertInstanceOf(ReportableFailure::class, ShadowCeilingReached::by('alpha', 'anthropic', StartDecision::Paused));
    }
}
