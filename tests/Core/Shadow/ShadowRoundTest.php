<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ChangeDecision;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\SerializerInterface;

// Covers nothing because it proves the application as configured: a project's mode from its config, its whole round, then its post through the container's forge writes.
#[CoversNothing]
#[Group('persistence')]
final class ShadowRoundTest extends KernelTestCase
{
    private string $file = '';

    private mixed $savedConfig = null;

    private RoundFixture $fixture;

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
        new Filesystem()->remove(self::createKernel()->getCacheDir());
        $this->fixture = new RoundFixture(self::getContainer());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_CONFIG']);
        if (null !== $this->savedConfig) {
            $_SERVER['INTAKT_CONFIG'] = $this->savedConfig;
        }

        parent::tearDown();
        new Filesystem()->remove([$this->file, self::createKernel()->getCacheDir()]);
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

    // Posts the decision the way a forge plugin will once one writes (plan task T18), so the only write a round leads to goes through the container's forge writes.
    private function reviewAndPost(string $project): ChangeReview
    {
        $review = $this->reviewChange()->review($this->request($project));
        $forge = self::getContainer()->get(ForgeWrites::class);
        assert($forge instanceof ForgeWrites);

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
        $serializer = self::getContainer()->get(SerializerInterface::class);
        assert($serializer instanceof SerializerInterface);
        $handler = new RunReviewRoundHandler($this->fixture->transactions(), new RoundLedger($this->fixture->ledger()), $this->fixture->bus);

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]),
            $this->fixture->transactions(),
            new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($this->fixture->ledger()),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
        );
    }

    private function request(string $project): ChangeReviewRequest
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );
        $panel = new Panel($members, QuorumPolicy::All);

        return new ChangeReviewRequest($project, __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    private function forge(): WriteRefusingForge
    {
        $forge = self::getContainer()->get(WriteRefusingForge::class);
        assert($forge instanceof WriteRefusingForge);

        return $forge;
    }
}
