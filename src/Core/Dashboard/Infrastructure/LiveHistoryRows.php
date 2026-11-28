<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\History;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventAppended;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;
use Twig\Environment;

#[AsEventListener]
final readonly class LiveHistoryRows
{
    public const string TEMPLATE = 'dashboard/stream/history_row.stream.html.twig';

    public function __construct(
        private Ledger $ledger,
        private LiveUpdates $updates,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LedgerEventAppended $appended): void
    {
        try {
            $stream = $this->streamOf($appended->recorded);
            // Boundary: logs a row that could not be read or rendered and skips its push, because the event is stored and a reload of the page shows it.
        } catch (Throwable $failure) {
            $this->logger->warning('A live History row was not published.', ['event' => $appended->recorded->id->toRfc4122(), 'exception' => $failure]);

            return;
        }

        $this->updates->publish(new LiveEvent(DashboardTopics::HISTORY, $stream));
    }

    private function streamOf(RecordedEvent $recorded): string
    {
        return $this->twig->render(self::TEMPLATE, ['row' => History::rowOf($recorded, $this->eventsOfRoundOf($recorded))]);
    }

    // Read only for an event that names a round, because only such a row takes its project from another one.
    private function eventsOfRoundOf(RecordedEvent $recorded): RecordedEvents
    {
        $round = PayloadFields::of($recorded)->text('round');

        return null === $round ? new RecordedEvents([]) : $this->ledger->readMentioning(new Mentions($round));
    }
}
