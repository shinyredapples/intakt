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

// One key for every press that acts on a review, so a start and a stop of the same review, or two of either, never run at once (#2703, D2; #3021, D4); the held locks live on the main request and not in this shared service, so a worker serving many requests keeps no per-request state here.
#[AsEventListener]
final readonly class RoundLock
{
    public const string KEY_PREFIX = 'dashboard-review-';

    private const string HELD = '_intakt_review_locks';

    public function __construct(
        private LockFactory $locks,
        private RequestStack $requests,
    ) {
    }

    // False when another press holds the review. Keyed by the id of the review's first round, because every round page of one review posts to its own round and a new round never changes the first. Not waiting, because a worker should not wait for another press. Called only with a round the ledger knows, because the file store keeps one file per key and a made-up round must leave none.
    public function tryHolding(string $firstRound): bool
    {
        $request = $this->requests->getMainRequest() ?? throw new LogicException('A review is held only while a request is handled, because the end of that request releases it.');
        $lock = $this->locks->createLock(self::KEY_PREFIX.$firstRound);

        if (!$lock->acquire()) {
            return false;
        }

        $request->attributes->set(self::HELD, [...$this->heldBy($request), $lock]);

        return true;
    }

    // Released only once the response listeners ran, so a press that arrives while the answer is still built finds the review busy. Only the end of the main request releases, because a sub-request copied from it carries the same list of locks and its end would free the review while the press still runs. When a response listener throws while the error answer is built, the kernel skips kernel.finish_request, so the lock is then freed only when its object is destroyed, because the factory creates it with auto-release.
    public function __invoke(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

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
