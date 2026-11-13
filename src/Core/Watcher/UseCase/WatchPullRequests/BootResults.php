<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// Main boots once per commit and tool fingerprint, because every head of the project is judged against the same main result.
final readonly class BootResults
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private WatcherLedger $ledger,
    ) {
    }

    public function ofMain(BootKey $key, ChangeSource $source): BootOutcome
    {
        $stored = $this->entities->find(BootRecord::class, $this->idOf($key));

        if ($stored instanceof BootRecord) {
            return $stored->outcome();
        }

        $outcome = $this->boot(new BootAt($source, $key->main));
        $this->remember($key, $outcome);
        $this->ledger->bootResultComputed($key, $outcome);

        return $outcome;
    }

    // A crash is the boot step's, not main's, so it is never kept: the next ask boots again instead of trusting a broken tool for good.
    private function remember(BootKey $key, BootOutcome $outcome): void
    {
        if (BootState::Crashed === $outcome->state) {
            return;
        }

        $this->entities->persist(new BootRecord($key, $outcome, $this->clock->now()));
        $this->entities->flush();
    }

    // Turned into an outcome because a boot that throws would stop the review it only gates.
    private function boot(BootAt $at): BootOutcome
    {
        try {
            $result = $this->queries->dispatch(new BootHarness($at))->last(HandledStamp::class)?->getResult();
        } catch (Throwable $failure) {
            return BootOutcome::crashed($failure::class.': '.$failure->getMessage());
        }

        return $result instanceof BootOutcome ? $result : BootOutcome::crashed('The boot query did not answer an outcome.');
    }

    /**
     * @return array{project: string, main: string, toolFingerprint: string}
     */
    private function idOf(BootKey $key): array
    {
        return ['project' => $key->project, 'main' => $key->main->hash, 'toolFingerprint' => $key->toolFingerprint];
    }
}
