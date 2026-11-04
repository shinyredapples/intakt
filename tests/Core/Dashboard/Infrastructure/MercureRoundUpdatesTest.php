<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\RoundProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversClass(MercureRoundUpdates::class)]
#[UsesClass(RoundProgress::class)]
final class MercureRoundUpdatesTest extends TestCase
{
    private const string ROUND = '01a19a97-48cf-70f1-bdae-965425786c46';

    private const string REVIEWER = '01a19a97-48cf-70f1-bdae-965425786c47';

    /**
     * @var list<Update>
     */
    private array $published = [];

    private BufferingLogger $log;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    public function testUpdatesOfARoundReachTheHubInTheOrderTheyHappened(): void
    {
        $updates = $this->updates();

        $updates->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');
        $updates->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, 'Looking at the cart.');
        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(
            ['append reviewer-'.self::REVIEWER.'-activity', 'append reviewer-'.self::REVIEWER.'-text', 'replace round-'.self::ROUND.'-state'],
            array_map($this->actionAndTargetOf(...), $this->published),
        );
    }

    public function testEveryUpdateOfARoundIsOnTheRoundsTopic(): void
    {
        $updates = $this->updates();

        $updates->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');
        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame([['review/rounds/'.self::ROUND], ['review/rounds/'.self::ROUND]], array_map(static fn (Update $update): array => $update->getTopics(), $this->published));
    }

    public function testAnUpdateIsPrivateBecauseItCarriesModelOutputAboutPrivateCode(): void
    {
        $this->updates()->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, 'A line of code.');

        self::assertTrue($this->published[0]->isPrivate());
    }

    public function testStreamedModelTextIsEscapedInTheTurboStream(): void
    {
        $this->updates()->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, '</template><script>alert(1)</script>');

        self::assertStringContainsString('&lt;/template&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $this->published[0]->getData());
        self::assertStringNotContainsString('<script>', $this->published[0]->getData());
    }

    public function testAToolNameIsEscapedInTheTurboStream(): void
    {
        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, '<b>x</b>');

        self::assertStringContainsString('<li>&lt;b&gt;x&lt;/b&gt;</li>', $this->published[0]->getData());
    }

    public function testARoundInProgressShowsHowManyReviewersFinished(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertStringContainsString('1 of 2 reviewers finished.', $this->published[0]->getData());
    }

    public function testAJudgedRoundShowsItIsJudged(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertStringContainsString('Judged.', $this->published[0]->getData());
    }

    public function testAReviewerUpdateBringsThePlaceOfAReviewerThePageDoesNotShowYet(): void
    {
        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

        self::assertStringContainsString(sprintf('targets="#round-%s-live:not(:has(#reviewer-%s-text))"', self::ROUND, self::REVIEWER), $this->published[0]->getData());
    }

    public function testAHubThatRefusesTheUpdateDoesNotFailTheCallerAndIsLoggedWithItsRoundAndFailure(): void
    {
        $refusal = new RuntimeException('the hub refused the update');
        $updates = $this->updates(static fn (Update $update): string => throw $refusal);

        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(
            [['warning', 'A live round update was not published.', ['round' => self::ROUND, 'stream' => 'round_changed', 'exception' => $refusal]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAStoppedReviewerReplacesItsStateAndEmptiesItsStopCell(): void
    {
        $this->updates()->reviewerStopped(Uuid::fromString(self::ROUND), self::REVIEWER);

        $data = $this->published[0]->getData();
        $row = 'round-'.self::ROUND.'-reviewer-'.self::REVIEWER;

        self::assertStringContainsString(sprintf('target="%s-state"><template><td id="%s-state">stopped</td>', $row, $row), $data);
        self::assertStringContainsString(sprintf('target="%s-stop"><template><td id="%s-stop"></td>', $row, $row), $data);
    }

    public function testAHubThatRefusesAStoppedReviewerIsLoggedAndDoesNotFailTheCaller(): void
    {
        $refusal = new RuntimeException('the hub refused the update');

        $this->updates(static fn (Update $update): string => throw $refusal)->reviewerStopped(Uuid::fromString(self::ROUND), self::REVIEWER);

        self::assertSame(
            [['warning', 'A live round update was not published.', ['round' => self::ROUND, 'stream' => 'reviewer_stopped', 'exception' => $refusal]]],
            $this->log->cleanLogs(),
        );
    }

    /**
     * @param (callable(Update): string)|null $publisher
     */
    private function updates(?callable $publisher = null): MercureRoundUpdates
    {
        $publisher ??= function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        };
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), $publisher);

        return new MercureRoundUpdates($hub, new Environment(new FilesystemLoader(__DIR__.'/../../../../templates')), $this->log);
    }

    private function actionAndTargetOf(Update $update): string
    {
        preg_match('/action="(\w+)" target="([^"]+)"/', $update->getData(), $found);

        return ($found[1] ?? '').' '.($found[2] ?? '');
    }
}
