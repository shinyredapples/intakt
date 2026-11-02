<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ForwardedProgress;
use Mcp\Server\ClientGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForwardedProgress::class)]
#[UsesClass(RoundProgress::class)]
final class ForwardedProgressTest extends TestCase
{
    public function testAStepIsSentOnlyOnceBecauseProgressMustIncrease(): void
    {
        $sent = [];
        $client = $this->createMock(ClientGateway::class);
        $client->expects(self::exactly(2))->method('progress')->willReturnCallback(static function (float $progress) use (&$sent): void {
            $sent[] = $progress;
        });
        $progress = new ForwardedProgress($client);

        $progress->reached(new RoundProgress(0, 2, false));
        $progress->reached(new RoundProgress(0, 2, false));
        $progress->reached(new RoundProgress(1, 2, false));
        $progress->reached(new RoundProgress(1, 2, false));

        self::assertSame([0.0, 1.0], $sent);
    }

    public function testTheJudgeCountsAsTheStepAfterTheReviewers(): void
    {
        $sent = [];
        $client = $this->createMock(ClientGateway::class);
        $client->expects(self::once())->method('progress')->willReturnCallback(static function (float $progress, ?float $total, ?string $message) use (&$sent): void {
            $sent = [$progress, $total, $message];
        });

        new ForwardedProgress($client)->reached(new RoundProgress(2, 2, true));

        self::assertSame([3.0, 3.0, 'The round is judged.'], $sent);
    }
}
