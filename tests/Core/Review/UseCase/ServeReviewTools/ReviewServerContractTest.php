<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\OwnerServerClient;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers nothing because the contract is the review server as a client meets it over HTTP.
#[Group('persistence')]
#[CoversNothing]
final class ReviewServerContractTest extends KernelTestCase
{
    private OwnerServerClient $client;

    private OwnerServerClient $owner;

    private RoundFixture $fixture;

    private string $roundId;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->fixture = new RoundFixture(self::getContainer());
        $this->roundId = $this->fixture->start(1)->id()->toRfc4122();
        $this->client = new OwnerServerClient($kernel, '/mcp/review');
        $this->client->initialize('2025-11-25');
        $this->owner = new OwnerServerClient($kernel);
        $this->owner->initialize('2025-11-25');
    }

    public function testTheReviewServerListsTheThreeRepositoryToolsOnly(): void
    {
        self::assertSame(['list_directory', 'read_file', 'search_files'], $this->toolNames($this->client));
    }

    public function testTheOwnerServerDoesNotListTheRepositoryTools(): void
    {
        self::assertSame([], array_intersect(['list_directory', 'read_file', 'search_files'], $this->toolNames($this->owner)));
    }

    public function testEveryToolOfTheReviewServerIsReadOnlyAndNotDestructive(): void
    {
        $tools = OwnerServerClient::at($this->client->call('tools/list', [], 2), 'result', 'tools');
        self::assertIsArray($tools);
        self::assertCount(3, $tools);

        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertTrue(OwnerServerClient::at($tool, 'annotations', 'readOnlyHint'));
            self::assertFalse(OwnerServerClient::at($tool, 'annotations', 'destructiveHint'));
        }
    }

    public function testAToolReadsAFileAtTheHeadOfARoundUnderReview(): void
    {
        $result = $this->call('read_file', ['roundId' => $this->roundId, 'path' => 'RoundFixture.php']);

        self::assertFalse(OwnerServerClient::at($result, 'isError'));
        self::assertStringContainsString('final readonly class RoundFixture', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAToolListsADirectoryAtTheHead(): void
    {
        $result = $this->call('list_directory', ['roundId' => $this->roundId]);

        self::assertFalse(OwnerServerClient::at($result, 'isError'));
        self::assertStringContainsString('RoundFixture.php', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAPathThatLeavesTheHeadIsRefusedAsAToolError(): void
    {
        foreach (['../ScriptedJudgement.php', '/etc/passwd', '.git/config'] as $path) {
            $result = $this->call('read_file', ['roundId' => $this->roundId, 'path' => $path]);

            self::assertTrue(OwnerServerClient::at($result, 'isError'), $path);
            self::assertStringContainsString('outside the repository under review', json_encode($result, JSON_THROW_ON_ERROR), $path);
        }
    }

    public function testAnUnknownRoundIsRefusedAsAToolError(): void
    {
        $result = $this->call('read_file', ['roundId' => 'not-a-round', 'path' => 'RoundFixture.php']);

        self::assertTrue(OwnerServerClient::at($result, 'isError'));
        self::assertStringNotContainsString('#0', $this->client->lastBody());
    }

    public function testAJudgedRoundIsRefusedBecauseItsHeadIsNoLongerUnderReview(): void
    {
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Approve, [], [], [])]));
        }

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($this->roundId));

        $result = $this->call('read_file', ['roundId' => $this->roundId, 'path' => 'RoundFixture.php']);

        self::assertTrue(OwnerServerClient::at($result, 'isError'));
        self::assertStringContainsString('no longer under review', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testSearchingManyFilesSendsIncreasingProgressNotifications(): void
    {
        $this->client->call('tools/call', ['name' => 'search_files', 'arguments' => ['roundId' => $this->roundId, 'text' => 'RoundFixture'], '_meta' => ['progressToken' => 'p1']], 5);

        preg_match_all('/^data: (.*)$/m', $this->client->lastBody(), $events);
        /** @var list<int> $progress */
        $progress = [];

        foreach ($events[1] as $event) {
            $message = json_decode($event, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($message) && 'notifications/progress' === ($message['method'] ?? null)) {
                $step = OwnerServerClient::at($message, 'params', 'progress');
                self::assertIsInt($step);
                $progress[] = $step;
            }
        }

        self::assertGreaterThanOrEqual(2, count($progress));
        $sorted = $progress;
        sort($sorted);
        self::assertSame($sorted, $progress);
        self::assertSame($progress, array_values(array_unique($progress, SORT_NUMERIC)));
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array<mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        $result = OwnerServerClient::at($this->client->call('tools/call', ['name' => $tool, 'arguments' => $arguments], 3), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function toolNames(OwnerServerClient $client): array
    {
        $tools = OwnerServerClient::at($client->call('tools/list', [], 2), 'result', 'tools');
        self::assertIsArray($tools);
        $names = array_map(static fn (mixed $tool): string => is_array($tool) && is_string($tool['name'] ?? null) ? $tool['name'] : '', $tools);
        sort($names);

        return $names;
    }
}
