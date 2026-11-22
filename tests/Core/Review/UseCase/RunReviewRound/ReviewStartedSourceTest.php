<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversClass(RoundLedger::class)]
#[CoversClass(RunReviewRoundHandler::class)]
final class ReviewStartedSourceTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testANewRoundsReviewStartedCarriesTheRepositoryBaseAndTask(): void
    {
        $this->fixture->startFromSource(new ChangeSource('/repos/alpha', 'main'));

        $payload = $this->startPayload();
        self::assertSame('/repos/alpha', $payload['repository']);
        self::assertSame('main', $payload['base']);
        self::assertSame(RoundFixture::TASK, $payload['task']);
    }

    public function testARoundWithoutASourceRecordsTheTaskAndNoRepository(): void
    {
        $this->fixture->start(1);

        $payload = $this->startPayload();
        self::assertSame(RoundFixture::TASK, $payload['task']);
        self::assertArrayNotHasKey('repository', $payload);
        self::assertArrayNotHasKey('base', $payload);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    /**
     * @return array<mixed>
     */
    private function startPayload(): array
    {
        $started = array_values(array_filter(
            iterator_to_array($this->fixture->ledger()->readInOrder()),
            static fn (RecordedEvent $event): bool => LedgerEventKind::ReviewStarted === $event->event->kind,
        ));
        self::assertCount(1, $started);

        $payload = json_decode($started[0]->event->payload, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($payload));

        return $payload;
    }
}
