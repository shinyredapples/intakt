<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Symfony\Component\Messenger\Attribute\AsMessageMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;

// A judging queued before its failure stamp existed gets the stamp when a worker receives it, ahead of its handler, so the envelope Messenger reports failed carries it and a judging whose retries run out after an upgrade is still published.
#[AsMessageMiddleware(bus: 'command.bus')]
final readonly class StampFailureOfEarlierJudging implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $stack->next()->handle($this->stamped($envelope), $stack);
    }

    private function stamped(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof JudgeRound || $envelope->last(DispatchOnFailureStamp::class) instanceof DispatchOnFailureStamp) {
            return $envelope;
        }

        return $envelope->with(RecordFailedJudging::onFailureOf($message->roundId));
    }
}
