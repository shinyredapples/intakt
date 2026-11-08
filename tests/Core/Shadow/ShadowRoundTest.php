<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\ReviewChange\ChangeDecision;
use Intakt\Core\Review\UseCase\ReviewChange\FailureInWords;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// Covers nothing because it proves the application as configured: a project's mode from its config, its whole round, then its post through the container's forge writes.
#[CoversNothing]
#[Group('persistence')]
final class ShadowRoundTest extends KernelTestCase
{
    private string $file = '';

    private mixed $savedConfig = null;

    private RoundFixture $fixture;

    private CompiledContainers $containers;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $this->savedConfig = $_SERVER['INTAKT_CONFIG'] ?? null;
        $this->file = sys_get_temp_dir().'/intakt-shadow-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, <<<'YAML'
            projects:
                alpha:
                    mode: shadow
                    tiers:
                        standard: { provider: anthropic, model: big }
                beta:
                    tiers:
                        standard: { provider: anthropic, model: big }
            YAML);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $this->containers = self::compiledContainers();
        // Keyed by the config's content, because its path changes per test while the content is the only build input this class sets.
        $this->containers->useFor([(string) file_get_contents($this->file)]);
        $this->fixture = new RoundFixture(self::getContainer(), $this->modes());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_CONFIG']);
        if (null !== $this->savedConfig) {
            $_SERVER['INTAKT_CONFIG'] = $this->savedConfig;
        }

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    public function testAShadowProjectsRoundRunsItsWholePanelAndPostsNothingToTheForge(): void
    {
        $review = $this->reviewAndPost('alpha');

        self::assertSame(Decision::Approve, $review->decision);
        self::assertCount(2, $review->verdicts);
        self::assertSame([], $this->forge()->writeLog);
    }

    public function testALiveProjectsRoundPostsItsReviewToTheForge(): void
    {
        try {
            $this->reviewAndPost('beta');
            self::fail('The live project\'s review did not reach the forge.');
        } catch (LogicException $refused) {
            self::assertSame('The test forge refuses every write.', $refused->getMessage());
        }

        self::assertSame(['post review on beta '.RoundFixture::CHANGE], $this->forge()->writeLog);
    }

    public function testAShadowProjectsVerdictsAreMarkedShadowInTheLedger(): void
    {
        $this->reviewChange()->review($this->request('alpha'));

        self::assertSame(['shadow', 'shadow'], $this->recordedVerdictModes());
    }

    public function testALiveProjectsVerdictsAreMarkedLiveInTheLedger(): void
    {
        $this->reviewChange()->review($this->request('beta'));

        self::assertSame(['live', 'live'], $this->recordedVerdictModes());
    }

    public function testAShadowProjectsApprovingVerdictsAreNotCountedForAMerge(): void
    {
        $review = $this->reviewChange()->review($this->request('alpha'));

        $verdicts = $this->countedFor($review);

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertSame([], $verdicts->counting);
    }

    public function testALiveProjectsApprovingVerdictsAreCountedForAMerge(): void
    {
        $review = $this->reviewChange()->review($this->request('beta'));

        $verdicts = $this->countedFor($review);

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertCount(2, $verdicts->counting);
    }

    // Read through the container's handler, so the project's mode comes from its config as the merge will read it.
    private function countedFor(ChangeReview $review): RoundVerdicts
    {
        $handler = self::getContainer()->get(VerdictsOfRoundHandler::class);

        return $handler(new VerdictsOfRound($review->roundId, SampleIdentity::of(RoundFixture::TASK, $this->panel()), new HeadMove(HeadMoveKind::Unmoved, [])));
    }

    /**
     * @return list<mixed>
     */
    private function recordedVerdictModes(): array
    {
        $verdicts = array_filter(iterator_to_array($this->fixture->ledger()->readInOrder(), false), static fn (RecordedEvent $recorded): bool => LedgerEventKind::VerdictReceived === $recorded->event->kind);

        return array_values(array_map(static fn (RecordedEvent $recorded): mixed => self::payloadOf($recorded)['mode'] ?? null, $verdicts));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function payloadOf(RecordedEvent $recorded): array
    {
        $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($payload));

        return $payload;
    }

    private function modes(): ProjectModes
    {
        return self::getContainer()->get(ProjectModes::class);
    }

    // Posts the decision the way a round will once it posts its outcome, so the only write a round leads to goes through the container's forge writes.
    private function reviewAndPost(string $project): ChangeReview
    {
        $review = $this->reviewChange()->review($this->request($project));
        $forge = self::getContainer()->get(ForgeWrites::class);

        $forge->postReview($project, ReviewToPost::change(RoundFixture::CHANGE), ReviewToPost::comment($review->decision->value));

        return $review;
    }

    private function reviewChange(): ReviewChange
    {
        $vendor = new VerdictPerRole([]);
        $work = function () use ($vendor): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, $vendor);
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };
        $serializer = self::getContainer()->get('serializer.stored');
        $handler = $this->fixture->startHandlerOn($this->fixture->bus);

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]),
            $this->fixture->transactions(),
            new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
            new FailureInWords(),
        );
    }

    private function request(string $project): ChangeReviewRequest
    {
        $panel = $this->panel();

        return new ChangeReviewRequest($project, __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'shadow-round');
    }

    private function forge(): WriteRefusingForge
    {
        return self::getContainer()->get(WriteRefusingForge::class);
    }
}
