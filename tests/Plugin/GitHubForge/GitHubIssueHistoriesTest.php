<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueHistories::class)]
#[UsesClass(GitHubIssueReads::class)]
#[UsesClass(InstallationReads::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(BudgetReserve::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class GitHubIssueHistoriesTest extends TestCase
{
    private RecordedIssueApi $api;

    protected function setUp(): void
    {
        $this->api = new RecordedIssueApi();
    }

    public function testTheLastEditorIsTheAuthorWhenNobodyEditedTheIssue(): void
    {
        self::assertSame('ana', $this->api->reads()->historyOf('alpha', 12)->editor);
    }

    public function testTheLastEditorIsTheAccountThatEditedTheIssue(): void
    {
        $this->api->historyAnswer = $this->history(['__typename' => 'User', 'login' => 'mallory'], []);

        self::assertSame('mallory', $this->api->reads()->historyOf('alpha', 12)->editor);
    }

    public function testAnEditedIssueWhoseEditorGitHubCannotNameFailsTheRead(): void
    {
        $this->api->historyAnswer = $this->history(null, [], '2026-11-10T12:00:00Z');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered an edited issue without its editor.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnEditorWithoutALoginFailsTheRead(): void
    {
        $this->api->historyAnswer = $this->history(['__typename' => 'User'], [], '2026-11-10T12:00:00Z');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered an editor without a login.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnEditorFieldErrorFailsTheReadInsteadOfNamingTheAuthor(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null,"timelineItems":{"nodes":[]}}}},"errors":[{"message":"Editor unavailable","path":["repository","issueOrPullRequest","editor"]}]}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered the history of an item with errors.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnAnswerWithAnEmptyErrorListIsRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null,"lastEditedAt":null,"timelineItems":{"pageInfo":{"hasPreviousPage":false,"startCursor":null},"nodes":[]}}}},"errors":[]}';

        self::assertSame('ana', $this->api->reads()->historyOf('alpha', 12)->editor);
    }

    public function testAnIssueTheForgeDoesNotKnowFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":null}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without the issue.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnAnswerWithoutTheLabelHistoryFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null}}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without the label history.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnItemWithoutAnyAccountFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":null,"editor":null,"timelineItems":{"nodes":[]}}}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered an issue without an editor.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testEachLabelIsNamedWithTheAccountThatAppliedItLastInTheOrderOfThoseApplications(): void
    {
        $this->api->historyAnswer = $this->history(null, [['ana', 'User', 'intakt'], ['mallory', 'User', 'priority:high'], ['bo', 'User', 'intakt']]);

        self::assertSame(['priority:high' => 'mallory', 'intakt' => 'bo'], $this->api->reads()->historyOf('alpha', 12)->labelAppliers);
    }

    public function testOlderLabelApplicationsAreReadFromTheEarlierPagesOfTheHistory(): void
    {
        $this->api->historyAnswer = $this->labelPage([['bo', 'User', 'intakt']], 'c2');
        $this->api->earlierHistory['c2'] = $this->labelPage([['mallory', 'User', 'priority:high']], 'c1');
        $this->api->earlierHistory['c1'] = $this->labelPage([['ana', 'User', 'steer:pause'], ['ana', 'User', 'intakt']], null);

        self::assertSame(['steer:pause' => 'ana', 'priority:high' => 'mallory', 'intakt' => 'bo'], $this->api->reads()->historyOf('alpha', 12)->labelAppliers);
        self::assertSame(['owner' => 'acme', 'name' => 'widgets', 'number' => 12, 'before' => 'c1'], $this->api->graphQlVariables);
    }

    public function testALabelHistoryLongerThanThePageCapFailsTheRead(): void
    {
        $this->api->historyAnswer = $this->labelPage([['ana', 'User', 'intakt']], 'c1');
        foreach (range(1, 10) as $page) {
            $this->api->earlierHistory['c'.$page] = $this->labelPage([['ana', 'User', 'intakt']], 'c'.($page + 1));
        }

        try {
            $this->api->reads()->historyOf('alpha', 12);
            self::fail('A label history past the page cap was answered.');
        } catch (ForgeReadFailed $failure) {
            self::assertSame('GitHub answered a label history longer than this forge reads.', $failure->getMessage());
            self::assertCount(10, $this->api->requests);
        }
    }

    public function testALabelHistoryOfExactlyThePageCapIsRead(): void
    {
        $this->api->historyAnswer = $this->labelPage([['ana', 'User', 'intakt']], 'c1');
        foreach (range(1, 8) as $page) {
            $this->api->earlierHistory['c'.$page] = $this->labelPage([['ana', 'User', 'intakt']], 'c'.($page + 1));
        }
        $this->api->earlierHistory['c9'] = $this->labelPage([['bo', 'User', 'priority:high']], null);

        self::assertSame(['priority:high' => 'bo', 'intakt' => 'ana'], $this->api->reads()->historyOf('alpha', 12)->labelAppliers);
        self::assertCount(10, $this->api->requests);
    }

    public function testALabelHistoryWithoutItsPageInfoFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null,"lastEditedAt":null,"timelineItems":{"nodes":[]}}}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a label history without its page info.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testALabelHistoryWithEarlierEventsButNoCursorFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null,"lastEditedAt":null,"timelineItems":{"pageInfo":{"hasPreviousPage":true,"startCursor":null},"nodes":[]}}}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a label history without its page info.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testAnAppIsNamedAsThePermissionCheckKnowsIt(): void
    {
        $this->api->historyAnswer = $this->history(['__typename' => 'Bot', 'login' => 'intakt-app'], [['intakt-app', 'Bot', 'intakt']], '2026-11-10T12:00:00Z');

        $history = $this->api->reads()->historyOf('alpha', 12);

        self::assertSame(['intakt-app[bot]', ['intakt' => 'intakt-app[bot]']], [$history->editor, $history->labelAppliers]);
    }

    public function testAnAppLoginThatAlreadyEndsInTheBotSuffixKeepsIt(): void
    {
        $this->api->historyAnswer = $this->history(['__typename' => 'Bot', 'login' => 'intakt-app[bot]'], [['intakt-app[bot]', 'Bot', 'intakt']], '2026-11-10T12:00:00Z');

        $history = $this->api->reads()->historyOf('alpha', 12);

        self::assertSame(['intakt-app[bot]', ['intakt' => 'intakt-app[bot]']], [$history->editor, $history->labelAppliers]);
    }

    public function testALabelAppliedByADeletedAccountNamesNoApplier(): void
    {
        $this->api->historyAnswer = $this->history(null, [[null, 'User', 'intakt']]);

        self::assertSame(['intakt' => null], $this->api->reads()->historyOf('alpha', 12)->labelAppliers);
    }

    public function testALabelEventWithoutALabelNameFailsTheRead(): void
    {
        $this->api->historyAnswer = '{"data":{"repository":{"issueOrPullRequest":{"author":{"login":"ana"},"editor":null,"lastEditedAt":null,"timelineItems":{"pageInfo":{"hasPreviousPage":false,"startCursor":null},"nodes":[{"actor":{"login":"ana"},"label":null}]}}}}}';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a label event this forge cannot read.'));

        $this->api->reads()->historyOf('alpha', 12);
    }

    public function testTheHistoryIsAskedOfTheProjectsRepository(): void
    {
        $this->api->reads()->historyOf('alpha', 12);

        self::assertSame(['POST https://api.github.com/graphql'], $this->api->requests);
        self::assertSame(['owner' => 'acme', 'name' => 'widgets', 'number' => 12, 'before' => null], $this->api->graphQlVariables);
    }

    // Each event as its login, account type and label, oldest first, because that is the order GitHub answers them in.
    /**
     * @param array<string, string>|null           $editor
     * @param list<array{?string, string, string}> $events
     */
    private function history(?array $editor, array $events, ?string $editedAt = null): string
    {
        return $this->answer(['editor' => $editor, 'lastEditedAt' => $editedAt], $events, null);
    }

    /**
     * @param list<array{?string, string, string}> $events
     */
    private function labelPage(array $events, ?string $earlier): string
    {
        return $this->answer(['editor' => null, 'lastEditedAt' => null], $events, $earlier);
    }

    /**
     * @param array{editor: array<string, string>|null, lastEditedAt: ?string} $edit
     * @param list<array{?string, string, string}>                             $events
     */
    private function answer(array $edit, array $events, ?string $earlier): string
    {
        $nodes = array_map(static fn (array $event): array => ['actor' => null === $event[0] ? null : ['__typename' => $event[1], 'login' => $event[0]], 'label' => ['name' => $event[2]]], $events);
        $pageInfo = ['hasPreviousPage' => null !== $earlier, 'startCursor' => $earlier];
        $item = ['author' => ['__typename' => 'User', 'login' => 'ana'], ...$edit, 'timelineItems' => ['pageInfo' => $pageInfo, 'nodes' => $nodes]];

        return json_encode(['data' => ['repository' => ['issueOrPullRequest' => $item]]], JSON_THROW_ON_ERROR);
    }
}
