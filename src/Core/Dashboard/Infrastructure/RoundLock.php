<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Service\ResetInterface;

// One key for every press that acts on a round, so a start and a stop of the same round, or two of either, never run at once (#2703, D2).
#[AsEventListener]
final class RoundLock implements ResetInterface
{
    public const string KEY_PREFIX = 'dashboard-round-';

    /** @var list<LockInterface> */
    private array $held = [];

    public function __construct(private readonly LockFactory $locks)
    {
    }

    // False when another press holds the round. Not waiting, because a worker should not wait for another press. Called only with a round the ledger knows, because the file store keeps one file per key and a made-up round must leave none.
    public function tryHolding(string $round): bool
    {
        $lock = $this->locks->createLock(self::KEY_PREFIX.$round);

        if (!$lock->acquire()) {
            return false;
        }

        $this->held[] = $lock;

        return true;
    }

    // Released only once the response listeners ran, so a press that arrives while the answer is still built finds the round busy.
    public function __invoke(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    public function reset(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }

        $this->held = [];
    }
}
