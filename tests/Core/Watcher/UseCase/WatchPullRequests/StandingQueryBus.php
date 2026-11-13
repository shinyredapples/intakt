<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Judges with the real identity rules and clears the entity manager, because the real handlers of both queries do, for a round recorded on one identity.
final class StandingQueryBus implements MessageBusInterface
{
    /** @var list<VerdictsOfRound> */
    public array $asked = [];
    public HeadMove $move;
    public bool $fetched = true;
    /** @var list<object> */
    public array $boots = [];
    public BootOutcome $main;
    public BootOutcome $head;

    public function __construct(
        private readonly VerdictIdentity $recorded,
        private readonly EntityManagerInterface $entities,
    ) {
        $this->move = new HeadMove(HeadMoveKind::Unmoved, []);
        $this->main = BootOutcome::booted();
        $this->head = BootOutcome::booted();
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $answer = match (true) {
            $message instanceof IdentityAtHead => $this->identityAt($message),
            $message instanceof MainCommit => $this->bootAsked($message, new CommitId(str_repeat('f', 40))),
            $message instanceof BootHarness => $this->bootAsked($message, $this->main),
            $message instanceof BootHarnessAtMain => $this->bootAsked($message, $this->head),
            default => $this->verdictsOf($message),
        };

        return new Envelope($message, [new HandledStamp($answer, 'handler')]);
    }

    private function bootAsked(object $query, CommitId|BootOutcome $answer): CommitId|BootOutcome
    {
        $this->boots[] = $query;

        return $answer;
    }

    // Fails when not fetched, because the local repository throws for a commit it lacks.
    private function identityAt(IdentityAtHead $query): HeadIdentity
    {
        if (!$this->fetched) {
            throw new ForgeReadFailed('The commit is not in the repository.');
        }

        $commits = new CommitRange($this->recorded->commits->base, $query->round->head);

        return new HeadIdentity(new VerdictIdentity($commits, $this->recorded->terms), $this->move);
    }

    private function verdictsOf(object $query): RoundVerdicts
    {
        assert($query instanceof VerdictsOfRound);
        $this->asked[] = $query;
        $this->entities->clear();

        return new RoundVerdicts($this->recorded->standingAt($query->current, $query->move), []);
    }
}
