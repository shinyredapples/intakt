<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueHistories::class)]
#[CoversClass(IssueHistory::class)]
#[CoversClass(LabelApplication::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(GitHubCalls::class)]
final class GitHubIssueHistoriesTest extends TestCase
{
    public function testTheHistoryNamesTheAccountThatLastEditedTheBody(): void
    {
        $api = $this->answering('history_edited.json');

        $history = $api->histories()->of('alpha', 12);

        self::assertSame('bob', $history->lastEditor);
        self::assertStringContainsString('"number":12', $api->graphqlRequests[0]);
        self::assertStringContainsString('"owner":"acme"', $api->graphqlRequests[0]);
    }

    public function testABodyNeverEditedWasLastWrittenByItsAuthor(): void
    {
        self::assertSame('ana', $this->answering('history_unedited.json')->histories()->of('alpha', 12)->lastEditor);
    }

    public function testAFieldErrorBesideANullEditorFailsTheReadInsteadOfNamingTheAuthor(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_editor_field_error.json')->histories()->of('alpha', 12);
    }

    public function testAnAnswerWithErrorsAndNoDataFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_errors.json')->histories()->of('alpha', 12);
    }

    public function testTheLabelHistoryNamesTheAccountThatAppliedEachLabel(): void
    {
        $history = $this->answering('history_edited.json')->histories()->of('alpha', 12);

        self::assertSame(['intakt' => 'ana', 'priority:high' => 'bob'], $this->appliers($history));
    }

    public function testLabelsAreListedInTheOrderTheyWereApplied(): void
    {
        $history = $this->answering('history_edited.json')->histories()->of('alpha', 12);

        self::assertSame(['intakt', 'priority:high'], array_map(static fn (LabelApplication $label): string => $label->label, $history->labels));
    }

    public function testALabelRemovedAndAppliedAgainNamesTheLaterAccountAndTakesTheLaterPlace(): void
    {
        $history = $this->answering('history_labels_reapplied.json')->histories()->of('alpha', 12);

        self::assertSame(['priority:high' => 'ana', 'intakt' => 'mallory'], $this->appliers($history));
        self::assertSame(['priority:high', 'intakt'], array_keys($this->appliers($history)));
    }

    public function testARemovalWithoutAnActorFailsTheRead(): void
    {
        $answer = json_decode(RecordedIssueApi::fixture('history_labels_reapplied.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $answer['data']['repository']['issue']['timelineItems']['nodes'][3]['actor'] = null;
        $api = new RecordedIssueApi();
        $api->graphql = json_encode($answer, JSON_THROW_ON_ERROR);

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12);
    }

    public function testAHistoryWithMoreLabelEventsThanOnePageFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_more_events.json')->histories()->of('alpha', 12);
    }

    public function testAnAnswerThatIsNotJsonFailsTheRead(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = 'not json';

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12);
    }

    public function testANon2xxAnswerFailsTheRead(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = '502';

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12);
    }

    private function answering(string $fixture): RecordedIssueApi
    {
        $api = new RecordedIssueApi();
        $api->graphql = RecordedIssueApi::fixture($fixture);

        return $api;
    }

    /**
     * @return array<string, string>
     */
    private function appliers(IssueHistory $history): array
    {
        $appliers = [];
        foreach ($history->labels as $label) {
            $appliers[$label->label] = $label->appliedBy;
        }

        return $appliers;
    }
}
