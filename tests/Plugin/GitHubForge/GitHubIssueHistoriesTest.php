<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueHistories::class)]
#[CoversClass(IssueHistory::class)]
#[CoversClass(LabelApplication::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(GitHubMoments::class)]
final class GitHubIssueHistoriesTest extends TestCase
{
    public function testTheHistoryNamesTheAccountThatLastEditedTheBody(): void
    {
        $api = $this->answering('history_edited.json');

        $history = $api->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame('bob', $history->lastEditor);
        self::assertStringContainsString('"number":12', $api->graphqlRequests[0]);
        self::assertStringContainsString('"owner":"acme"', $api->graphqlRequests[0]);
    }

    public function testABodyNeverEditedWasLastWrittenByItsAuthor(): void
    {
        self::assertSame('ana', $this->answering('history_unedited.json')->histories()->of('alpha', 12, $this->listedAt())->lastEditor);
    }

    public function testAFieldErrorBesideANullEditorFailsTheReadInsteadOfNamingTheAuthor(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_editor_field_error.json')->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithErrorsAndNoDataFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_errors.json')->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testTheLabelHistoryNamesTheAccountThatAppliedEachLabel(): void
    {
        $history = $this->answering('history_edited.json')->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame(['intakt' => 'ana', 'priority:high' => 'bob'], $this->appliers($history));
    }

    public function testLabelsAreListedInTheOrderTheyWereApplied(): void
    {
        $history = $this->answering('history_edited.json')->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame(['intakt', 'priority:high'], array_map(static fn (LabelApplication $label): string => $label->label, $history->labels));
    }

    public function testALabelRemovedAndAppliedAgainNamesTheLaterAccountAndTakesTheLaterPlace(): void
    {
        $history = $this->answering('history_labels_reapplied.json')->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame(['priority:high' => 'ana', 'intakt' => 'mallory'], $this->appliers($history));
        self::assertSame(['priority:high', 'intakt'], array_keys($this->appliers($history)));
    }

    public function testARemovalWithoutAnActorFailsTheRead(): void
    {
        $removal = '{"__typename":"UnlabeledEvent","actor":{"__typename":"User","login":"ana"},"label":{"name":"bug"}}';
        $api = $this->answeringWith('history_labels_reapplied.json', $removal, '{"__typename":"UnlabeledEvent","actor":null,"label":{"name":"bug"}}');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnEditByAnAppIsNamedWithTheBotSuffixAsTheRestApiNamesIt(): void
    {
        self::assertSame('renovate[bot]', $this->answering('history_app_actors.json')->histories()->of('alpha', 12, $this->listedAt())->lastEditor);
    }

    public function testALabelAppliedByAnAppNamesTheAppWithTheBotSuffixAndAPersonBare(): void
    {
        $history = $this->answering('history_app_actors.json')->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame(['intakt' => 'intakt-runner[bot]', 'priority:high' => 'bob'], $this->appliers($history));
    }

    public function testAnAccountWithoutItsTypeFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"editor":{"__typename":"User","login":"bob"}', '"editor":{"login":"bob"}');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testTheQueryAsksForTheTypeOfTheAuthorTheEditorAndEveryActor(): void
    {
        $api = $this->answering('history_edited.json');
        $api->histories()->of('alpha', 12, $this->listedAt());

        $body = json_decode($api->graphqlRequests[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['query']);
        self::assertSame(4, preg_match_all('/(author|editor|actor) \{[^}]*__typename/', $body['query']));
    }

    public function testANullEditorWithoutAnErrorFailsTheReadInsteadOfNamingTheAuthor(): void
    {
        $api = $this->answeringWith('history_editor_field_error.json', ',"errors":[{"type":"FORBIDDEN","path":["repository","issueOrPullRequest","userContentEdits","nodes",0,"editor"],"message":"Resource not accessible by integration"}]', '');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithoutThePagingFlagFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"timelineItems":{"pageInfo":{"hasNextPage":false},', '"timelineItems":{');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a label history this forge cannot read whole.'));

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithoutTheLabelsPagingFlagFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"labels":{"pageInfo":{"hasNextPage":false},', '"labels":{');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered labels this forge cannot read whole.'));

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnEventOfAnUnknownKindFailsTheReadInsteadOfRemovingTheLabel(): void
    {
        $api = $this->answeringWith('history_labels_reapplied.json', '{"__typename":"UnlabeledEvent","actor":{"__typename":"User","login":"carol"}', '{"__typename":"UnknownEvent","actor":{"__typename":"User","login":"carol"}');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAHistoryWithMoreLabelEventsThanOnePageFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->answering('history_more_events.json')->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testThePullRequestOfANumberHasItsHistoryReadLikeAnIssue(): void
    {
        $api = $this->answeringWith('history_edited.json', '"__typename":"Issue"', '"__typename":"PullRequest"');

        $history = $api->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame('bob', $history->lastEditor);
        self::assertStringContainsString('issueOrPullRequest(number: $number)', $api->graphqlRequests[0]);
    }

    public function testAnItemUpdatedAfterItWasListedFailsTheReadBecauseItsHistoryMayBeOfOtherTextAndLabels(): void
    {
        $api = $this->answeringWith('history_edited.json', '"updatedAt":"2026-11-10T10:00:00Z"', '"updatedAt":"2026-11-10T10:00:01Z"');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub changed #12 after it was listed, so its history may be of other text and labels.'));

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithoutTheUpdateTimeFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"updatedAt":"2026-11-10T10:00:00Z",', '');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testTheHistoryHoldsTheBodyAndTheLabelsTheItemHasBesideIt(): void
    {
        $history = $this->answering('history_edited.json')->histories()->of('alpha', 12, $this->listedAt());

        self::assertSame('Waits for #3', $history->body);
        self::assertSame(['intakt', 'priority:high'], [...$history->currentLabels]);
    }

    public function testAnEmptyBodyIsReadAsTheEmptyText(): void
    {
        self::assertSame('', $this->answering('history_unedited.json')->histories()->of('alpha', 12, $this->listedAt())->body);
    }

    public function testAnAnswerWithoutTheBodyFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"body":"Waits for #3",', '');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithMoreLabelsThanOnePageFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '"labels":{"pageInfo":{"hasNextPage":false}', '"labels":{"pageInfo":{"hasNextPage":true}');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered labels this forge cannot read whole.'));

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testALabelWithoutItsNameFailsTheRead(): void
    {
        $api = $this->answeringWith('history_edited.json', '{"name":"priority:high"}]', '{}]');

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnUpdateTimeWithANulByteFailsTheReadInsteadOfRaisingAValueError(): void
    {
        $api = $this->answeringWith('history_edited.json', '"updatedAt":"2026-11-10T10:00:00Z"', '"updatedAt":"2026-11-10T10:00:00Z\u0000"');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a moment this forge cannot read.'));

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerWithoutTheItemFailsTheRead(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = '{"data":{"repository":{"issueOrPullRequest":null}}}';

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnAnswerThatIsNotJsonFailsTheRead(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = 'not json';

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testANon2xxAnswerFailsTheRead(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = '502';

        $this->expectException(ForgeReadFailed::class);

        $api->histories()->of('alpha', 12, $this->listedAt());
    }

    public function testAnExhaustedGraphqlPoolAnsweredWith200HoldsTheNextReadUntilTheReset(): void
    {
        $api = $this->answering('history_rate_limited.json');
        $api->graphqlHeaders = ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Limit' => '5000', 'X-RateLimit-Reset' => '1795604400', 'X-RateLimit-Resource' => 'graphql'];
        $histories = $api->histories();

        $first = $this->limitOf($histories);
        $second = $this->limitOf($histories);

        self::assertSame(1795604400, $first->resetsAt->getTimestamp());
        self::assertSame(1795604400, $second->resetsAt->getTimestamp());
        self::assertCount(1, $api->graphqlRequests);
    }

    private function limitOf(GitHubIssueHistories $histories): ForgeRateLimited
    {
        try {
            $histories->of('alpha', 12, $this->listedAt());
        } catch (ForgeRateLimited $limited) {
            return $limited;
        }

        throw new LogicException('The read was expected to be rate limited.');
    }

    private function answering(string $fixture): RecordedIssueApi
    {
        $api = new RecordedIssueApi();
        $api->graphql = RecordedIssueApi::fixture($fixture);

        return $api;
    }

    private function listedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T10:00:00Z');
    }

    // The fixture is changed in exactly one place, so a test cannot pass on a replacement that matched nothing.
    private function answeringWith(string $fixture, string $recorded, string $instead): RecordedIssueApi
    {
        $api = new RecordedIssueApi();
        $api->graphql = str_replace($recorded, $instead, RecordedIssueApi::fixture($fixture), $replaced);
        self::assertSame(1, $replaced);

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
