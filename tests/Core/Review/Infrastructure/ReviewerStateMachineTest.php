<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ReviewerStateMachine;
use Intakt\Core\Review\Infrastructure\StateMarkingStore;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

// The expected values are the literal strings of the machine as the removed config/packages/workflow.php built it, so the attributes are compared with that machine and not with themselves.
#[CoversClass(ReviewerStateMachine::class)]
#[UsesClass(RoundReviewer::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
#[UsesClass(StateMarkingStore::class)]
final class ReviewerStateMachineTest extends KernelTestCase
{
    public function testTheReviewerMachineKeepsItsPlacesInTheirOrder(): void
    {
        self::assertSame(
            ['queued' => 'queued', 'running' => 'running', 'interrupted' => 'interrupted', 'verdict' => 'verdict', 'failed' => 'failed', 'blocked' => 'blocked'],
            $this->reviewerMachine()->getDefinition()->getPlaces(),
        );
    }

    // Three block transitions are expected, because the configuration split a state machine transition with three input places into one per place.
    public function testTheReviewerMachineKeepsItsTransitionsWithBlockFromQueuedRunningAndInterrupted(): void
    {
        $transitions = array_map(
            static fn (Transition $transition): array => [$transition->getName(), $transition->getFroms(), $transition->getTos()],
            $this->reviewerMachine()->getDefinition()->getTransitions(),
        );

        self::assertSame([
            ['start', ['queued'], ['running']],
            ['receive', ['running'], ['verdict']],
            ['fail', ['running'], ['failed']],
            ['requeue', ['running'], ['queued']],
            ['block', ['queued'], ['blocked']],
            ['block', ['running'], ['blocked']],
            ['block', ['interrupted'], ['blocked']],
            ['interrupt', ['running'], ['interrupted']],
            ['resume', ['interrupted'], ['running']],
        ], $transitions);
    }

    public function testAnInterruptedReviewerHasStartedButIsNotFinished(): void
    {
        self::assertTrue(ReviewerState::Interrupted->hasStarted());
        self::assertFalse(ReviewerState::Interrupted->isFinished());
    }

    public function testAReviewerStartsQueued(): void
    {
        self::assertSame(['queued'], $this->reviewerMachine()->getDefinition()->getInitialPlaces());
    }

    // The registrar names the service after the type, so only a state machine answers to this id.
    public function testTheReviewerMachineIsAStateMachine(): void
    {
        self::assertTrue(self::getContainer()->has('state_machine.reviewer'));
    }

    public function testTheReviewerMachineKeepsItsPlaceOnTheReviewer(): void
    {
        self::assertInstanceOf(StateMarkingStore::class, $this->reviewerMachine()->getMarkingStore());
    }

    public function testTheRegistryGivesTheReviewerMachineForAReviewer(): void
    {
        $reviewer = new ReflectionClass(RoundReviewer::class)->newInstanceWithoutConstructor();

        self::assertSame('reviewer', self::getContainer()->get(Registry::class)->get($reviewer)->getName());
    }

    private function reviewerMachine(): WorkflowInterface
    {
        return self::getContainer()->get('state_machine.reviewer');
    }
}
