<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Review\Contract\ReplayMark;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Answers every replay mark query with one mark, as the Review module's handler would for the demo project.
final readonly class MarkAnsweringQueries implements MessageBusInterface
{
    public function __construct(private ReplayMark $mark)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message, [new HandledStamp($this->mark, 'replay-mark')]);
    }
}
