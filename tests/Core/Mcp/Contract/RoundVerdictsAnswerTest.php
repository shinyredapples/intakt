<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

// Covers nothing because the contract is the verdicts tool's answer as an agent meets it through the running server.
#[Group('persistence')]
#[CoversNothing]
final class RoundVerdictsAnswerTest extends KernelTestCase
{
    public function testTheVerdictsToolAnswersWithResolvedAndFollowUpsAsListsOfStrings(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->start(2);
        $verdict = new Verdict(Decision::Approve, [], [new ResolvedFinding('F1')], [new FollowUpNote('Cover an empty cart.')]);
        foreach ($fixture->bus->messages as $message) {
            $fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => $verdict]));
        }

        $client = new McpServerClient($kernel);
        $client->initialize('2025-11-25');
        $answer = $client->call('tools/call', ['name' => 'review_verdicts', 'arguments' => ['roundId' => $round->id()->toRfc4122()]], 3);

        self::assertFalse(McpServerClient::at($answer, 'result', 'isError'));
        $answered = McpServerClient::at($answer, 'result', 'structuredContent', 'verdicts', '0', 'verdict');
        self::assertIsArray($answered);
        self::assertSame([['F1'], ['Cover an empty cart.']], [$answered['resolved'] ?? null, $answered['follow_ups'] ?? null]);
    }
}
