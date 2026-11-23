<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Domain\RoundTransition;
use Intakt\Core\Review\Infrastructure\ReviewRoundStateMachine;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

// The expected values are the literal strings of the machine as the removed config/packages/workflow.php built it, so the attributes are compared with that machine and not with themselves.
#[CoversClass(ReviewRoundStateMachine::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(RoundTransition::class)]
#[UsesClass(StateMarkingStore::class)]
final class ReviewRoundStateMachineTest extends KernelTestCase
{
    public function testTheRoundMachineKeepsItsPlacesInTheirOrder(): void
    {
        self::assertSame(
            ['collecting' => 'collecting', 'complete' => 'complete', 'judged' => 'judged'],
            $this->roundMachine()->getDefinition()->getPlaces(),
        );
    }

    public function testTheRoundMachineKeepsItsTransitions(): void
    {
        $transitions = array_map(
            static fn (Transition $transition): array => [$transition->getName(), $transition->getFroms(), $transition->getTos()],
            $this->roundMachine()->getDefinition()->getTransitions(),
        );

        self::assertSame([['complete', ['collecting'], ['complete']], ['judge', ['complete'], ['judged']]], $transitions);
    }

    public function testARoundStartsCollecting(): void
    {
        self::assertSame(['collecting'], $this->roundMachine()->getDefinition()->getInitialPlaces());
    }

    // The registrar names the service after the type, so only a state machine answers to this id.
    public function testTheRoundMachineIsAStateMachine(): void
    {
        self::assertTrue(self::getContainer()->has('state_machine.review_round'));
    }

    public function testTheRoundMachineKeepsItsPlaceOnTheRound(): void
    {
        self::assertInstanceOf(StateMarkingStore::class, $this->roundMachine()->getMarkingStore());
    }

    public function testTheRegistryGivesTheRoundMachineForARound(): void
    {
        $round = new ReflectionClass(ReviewRound::class)->newInstanceWithoutConstructor();

        self::assertSame('review_round', self::getContainer()->get(Registry::class)->get($round)->getName());
    }

    private function roundMachine(): WorkflowInterface
    {
        return self::getContainer()->get('state_machine.review_round');
    }
}
