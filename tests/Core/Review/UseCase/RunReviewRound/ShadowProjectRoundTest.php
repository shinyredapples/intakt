<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversClass(RunReviewRoundHandler::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(VerdictsOfRoundHandler::class)]
final class ShadowProjectRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'shadow']));
    }

    public function testAShadowProjectsRoundStartsWithoutBeingHeld(): void
    {
        $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame([LedgerEventKind::ReviewStarted], $this->fixture->ledgerKinds());
    }

    public function testAShadowProjectsVerdictsCountLikeAnyOtherRounds(): void
    {
        $id = $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));
        $vendor = new ScriptedVendor();
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($id, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertCount(2, $verdicts->counting);
    }
}
