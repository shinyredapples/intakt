<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Dashboard\Infrastructure\MessageQueues;
use Symfony\Component\Clock\ClockInterface;

// Read from the database alone and never through the message bus, so a stopped consumer still yields a status; the document follows docs/user/status.schema.json.
final readonly class StatusReadModel
{
    public function __construct(
        private ClockInterface $clock,
        private Holds $holds,
        private MessageQueues $queues,
        private ProjectStatuses $projects,
    ) {
    }

    public function json(): string
    {
        return json_encode([
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'holds' => $this->holdsInEffect(),
            'queues' => $this->queues->now(),
            'projects' => $this->projects->now(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Names only, because a hold's end can be a vendor's reset time, which only the admin area shows (docs/design/05-architecture/13-dashboard.md).
    /**
     * @return list<string>
     */
    private function holdsInEffect(): array
    {
        return array_map(static fn (SharedHold $hold): string => $hold->value, $this->holds->sharedInEffect());
    }
}
