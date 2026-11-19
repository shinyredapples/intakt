<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use LogicException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// One key for every press that acts on a round, so a start and a stop of the same round, or two of either, never run at once (#2703, D2).
// The held locks live on the main request and not in this shared service, so a worker serving many requests keeps no per-request state here.
#[AsEventListener]
final readonly class RoundLock
{
    public const string KEY_PREFIX = 'dashboard-round-';

    private const string HELD = '_intakt_round_locks';

    public function __construct(
        private LockFactory $locks,
        private RequestStack $requests,
    ) {
    }

    // False when another press holds the round. Not waiting, because a worker should not wait for another press. Called only with a round the ledger knows, because the file store keeps one file per key and a made-up round must leave none.
    public function tryHolding(string $round): bool
    {
        $request = $this->requests->getMainRequest() ?? throw new LogicException('A round is held only while a request is handled, because the end of that request releases it.');
        $lock = $this->locks->createLock(self::KEY_PREFIX.$round);

        if (!$lock->acquire()) {
            return false;
        }

        $request->attributes->set(self::HELD, [...$this->heldBy($request), $lock]);

        return true;
    }

    // Released only once the response listeners ran, so a press that arrives while the answer is still built finds the round busy. A sub-request holds nothing of its own, so its end frees nothing.
    public function __invoke(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();

        foreach ($this->heldBy($request) as $lock) {
            $lock->release();
        }

        $request->attributes->remove(self::HELD);
    }

    /**
     * @return list<LockInterface>
     */
    private function heldBy(Request $request): array
    {
        $held = $request->attributes->get(self::HELD, []);

        return is_array($held) ? array_values(array_filter($held, static fn (mixed $lock): bool => $lock instanceof LockInterface)) : [];
    }
}
