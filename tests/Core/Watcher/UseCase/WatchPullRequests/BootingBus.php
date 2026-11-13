<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootOutcome;
use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class BootingBus implements MessageBusInterface
{
    /** @var list<BootHarness> */
    public array $booted = [];
    public bool $throws = false;

    public function __construct(public BootOutcome $outcome)
    {
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof BootHarness);
        $this->booted[] = $message;

        if ($this->throws) {
            throw new RuntimeException('The query bus is down.');
        }

        return new Envelope($message, [new HandledStamp($this->outcome, 'handler')]);
    }
}
