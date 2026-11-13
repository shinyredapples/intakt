<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
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

    // Runs while the boot runs, so a test can force what another worker or the database does between the lookup and the store.
    public ?Closure $meanwhile = null;

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

        if (null !== $this->meanwhile) {
            ($this->meanwhile)();
        }

        return new Envelope($message, [new HandledStamp($this->outcome, 'handler')]);
    }
}
