<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunOrigin;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCiRuns;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubWatchReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubWatchReads::class)]
#[CoversClass(GitHubCiRuns::class)]
#[CoversClass(RepositoryAddress::class)]
#[CoversClass(WatchedRepositories::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(CiRun::class)]
#[UsesClass(CiRunOrigin::class)]
#[UsesClass(CiState::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(OpenChangeListing::class)]
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
final class GitHubWatchReadsTest extends TestCase
{
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string VALIDATOR = 'W/"listing-1"';
    private const string PULLS = 'GET '.self::API.'/pulls';

    /** @var list<string> */
    private array $requested = [];
    /** @var list<array<mixed>> */
    private array $options = [];
    private string $listing = 'pulls_open.json';
    private int $listingStatus = 200;
    private string $listingEtag = 'W/"listing-2"';
    private string $runs = 'runs_green.json';
    private string $suites = 'suites_none.json';
    private int $failWith = 0;

    public function testAListingAnsweredNotModifiedIsReportedUnchanged(): void
    {
        $this->listingStatus = 304;

        $listing = $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertTrue($listing->isUnchanged());
    }

    public function testAnUnchangedListingCostsNoFurtherRead(): void
    {
        $this->listingStatus = 304;

        $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertCount(1, $this->listingRequests());
    }

    public function testAListingSendsTheEarlierValidatorAndReturnsTheNewOne(): void
    {
        $listing = $this->reads()->listOpen('alpha', self::VALIDATOR);

        self::assertSame(self::VALIDATOR, $this->header($this->options[0], 'if-none-match'));
        self::assertSame('W/"listing-2"', $listing->validator);
    }

    public function testAFirstListingSendsNoValidator(): void
    {
        $this->reads()->listOpen('alpha', null);

        self::assertSame('', $this->header($this->options[0], 'if-none-match'));
    }

    public function testOpenPullRequestsAreListedWithTheirAddressHeadAndUpdateTimeInUtc(): void
    {
        $listing = $this->reads()->listOpen('alpha', null);

        self::assertSame(
            ['https://github.com/acme/widgets/pull/7|'.self::HEAD.'|2026-11-02T09:00:00+00:00', 'https://github.com/acme/widgets/pull/8|'.str_repeat('2', 40).'|2026-11-02T07:30:00+00:00'],
            array_map(static fn (OpenChange $change): string => $change->id->value.'|'.$change->head->hash.'|'.$change->updatedAt->format('c'), $listing->changes()),
        );
    }

    public function testAListingOverTwoPagesReturnsEveryPullRequestAndNoValidator(): void
    {
        $this->listing = 'pulls_page_full.json';

        $listing = $this->reads()->listOpen('alpha', null);

        self::assertCount(101, $listing->changes());
        self::assertNull($listing->validator);
    }

    public function testAFullPageFollowedByAnEmptyOneKeepsTheValidator(): void
    {
        $this->listing = 'pulls_page_full_only.json';

        $listing = $this->reads()->listOpen('alpha', null);

        self::assertCount(100, $listing->changes());
        self::assertSame('W/"listing-2"', $listing->validator);
    }

    public function testAListingOfMoreThanTenPagesFailsTheRead(): void
    {
        $this->listing = 'pulls_endless.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAHeadWithAWorkflowRunInProgressIsUnfinished(): void
    {
        $this->runs = 'runs_in_progress.json';

        self::assertSame([CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAHeadWithAQueuedCheckSuiteIsUnfinished(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_queued.json';

        self::assertSame([CiRunState::Unfinished], $this->statesOfHead());
    }

    public function testAFailedWorkflowRunIsFailed(): void
    {
        $this->runs = 'runs_failed.json';

        self::assertSame([CiRunState::Failed], $this->statesOfHead());
    }

    public function testAFailedCheckSuiteIsFailed(): void
    {
        $this->suites = 'suites_failed.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Failed], $this->statesOfHead());
    }

    public function testACancelledRunIsCancelledAndNotFailed(): void
    {
        $this->runs = 'runs_cancelled.json';

        self::assertSame([CiRunState::Cancelled], $this->statesOfHead());
    }

    public function testFinishedGreenRunsAndSuitesAreSucceeded(): void
    {
        $this->suites = 'suites_green.json';

        self::assertSame([CiRunState::Succeeded, CiRunState::Succeeded, CiRunState::Succeeded], $this->statesOfHead());
    }

    public function testRunsOfAnotherHeadAreLeftOut(): void
    {
        $this->runs = 'runs_other_head.json';

        self::assertSame([CiRunState::Succeeded], $this->statesOfHead());
    }

    public function testACheckSuiteWithNoCheckRunIsLeftOut(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_empty_queue.json';

        self::assertTrue($this->state()->isEmpty());
    }

    public function testARunKeepsItsOriginAndStartTime(): void
    {
        $this->runs = 'runs_in_progress.json';

        $run = $this->state()->runs[0];

        self::assertSame('ci|pull_request|https://github.com/acme/widgets/pull/7|2026-11-02T09:00:00+00:00', $run->origin->name.'|'.$run->origin->event.'|'.$run->origin->change?->value.'|'.$run->startedAt->format('c'));
    }

    public function testACheckSuiteIsNamedAfterItsAppAndItsEventIsCheckSuite(): void
    {
        $this->runs = 'runs_none.json';
        $this->suites = 'suites_green.json';

        $origin = $this->state()->runs[0]->origin;

        self::assertSame('Lint App|check_suite', $origin->name.'|'.$origin->event);
    }

    public function testTheRunsAreAskedForTheExactHead(): void
    {
        $this->state();

        self::assertContains('GET '.self::API.'/actions/runs?head_sha='.self::HEAD.'&per_page=100', $this->requested);
        self::assertContains('GET '.self::API.'/commits/'.self::HEAD.'/check-suites?per_page=100', $this->requested);
    }

    public function testAHeadWithNoRunsHasAnEmptyState(): void
    {
        $this->runs = 'runs_none.json';

        self::assertTrue($this->state()->isEmpty());
    }

    public function testARefusedListingRaisesAReadFailureCausedByRefusedCredentials(): void
    {
        $this->failWith = 401;

        try {
            $this->reads()->listOpen('alpha', null);
            self::fail('The refused read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testARefusedCiReadRaisesAReadFailureCausedByRefusedCredentials(): void
    {
        $this->failWith = 401;

        try {
            $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
            self::fail('The refused read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testAFailedListingRaisesAReadFailure(): void
    {
        $this->failWith = 500;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAMissingRepositoryRaisesAReadFailureOnTheListing(): void
    {
        $this->failWith = 404;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAFailedCiReadRaisesAReadFailure(): void
    {
        $this->failWith = 500;

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
    }

    public function testAnUnreachableGitHubRaisesAReadFailureThatDoesNotChainTheClientFailure(): void
    {
        $this->failWith = -1;

        try {
            $this->reads()->listOpen('alpha', null);
            self::fail('The failed read was not reported.');
        } catch (ForgeReadFailed $failed) {
            self::assertNull($failed->getPrevious());
        }
    }

    public function testAnAnswerThatIsNotJsonRaisesAReadFailure(): void
    {
        $this->listing = 'contents_widget.txt';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testACompletedRunWithoutAConclusionRaisesAReadFailure(): void
    {
        $this->runs = 'runs_no_conclusion.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testAnAnswerWithoutTheListOfRunsRaisesAReadFailure(): void
    {
        $this->runs = 'access_tokens_404.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testMoreRunsThanOnePageHoldsRaiseAReadFailure(): void
    {
        $this->runs = 'runs_more_than_a_page.json';

        $this->expectException(ForgeReadFailed::class);

        $this->state();
    }

    public function testAListedPullRequestAtAnotherHostRaisesAReadFailure(): void
    {
        $this->listing = 'pulls_foreign_address.json';

        $this->expectException(ForgeReadFailed::class);

        $this->reads()->listOpen('alpha', null);
    }

    public function testAProjectWithoutAGitHubRepositoryRaisesAReadFailureBeforeAnyRequest(): void
    {
        try {
            $this->reads()->listOpen('beta', null);
            self::fail('The project has no GitHub repository.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->requested);
        }
    }

    /**
     * @return list<CiRunState>
     */
    private function statesOfHead(): array
    {
        return array_map(static fn (CiRun $run): CiRunState => $run->state, $this->state()->runs);
    }

    private function state(): CiState
    {
        return $this->reads()->ciStateOf('alpha', new CommitId(self::HEAD));
    }

    /**
     * @return list<string>
     */
    private function listingRequests(): array
    {
        return array_values(array_filter($this->requested, static fn (string $request): bool => str_starts_with($request, self::PULLS)));
    }

    private function reads(): GitHubWatchReads
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requested[] = $method.' '.$url;
            $this->options[] = $options;

            return $this->answer($url);
        }, 'https://api.github.com');
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new GitHubWatchReads(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]), new GitHubCiRuns());
    }

    private function answer(string $url): ResponseInterface
    {
        if ($this->failWith > 0) {
            return new MockResponse('{}', ['http_code' => $this->failWith]);
        }

        if (-1 === $this->failWith) {
            throw new TransportException('connection refused');
        }

        return match (true) {
            str_contains($url, '/pulls') => $this->listingPage($url),
            str_contains($url, '/actions/runs') => new MockResponse(self::fixture($this->runs)),
            default => new MockResponse(self::fixture($this->suites)),
        };
    }

    private function listingPage(string $url): MockResponse
    {
        if (304 === $this->listingStatus) {
            return new MockResponse('', ['http_code' => 304]);
        }

        $page = 1 === preg_match('/[?&]page=(\d+)/', $url, $found) ? (int) $found[1] : 1;
        $headers = ['response_headers' => ['ETag' => $this->listingEtag]];

        return match ($this->listing) {
            'pulls_page_full.json' => new MockResponse(self::fixture(1 === $page ? 'pulls_page_full.json' : 'pulls_page_last.json'), $headers),
            'pulls_page_full_only.json' => new MockResponse(1 === $page ? self::fixture('pulls_page_full.json') : '[]', $headers),
            'pulls_endless.json' => new MockResponse(self::fixture('pulls_page_full.json'), $headers),
            default => new MockResponse(self::fixture($this->listing), $headers),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private function header(array $options, string $name): string
    {
        $headers = $options['normalized_headers'] ?? [];
        $values = is_array($headers) ? ($headers[$name] ?? []) : [];

        return is_array($values) && is_string($values[0] ?? null) ? preg_replace('/^[^:]+: /', '', $values[0]) ?? '' : '';
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }
}
