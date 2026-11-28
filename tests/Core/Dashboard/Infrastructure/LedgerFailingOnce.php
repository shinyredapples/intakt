<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use RuntimeException;

// Fails its first read after running a step, so a test can let another worker trigger while the reader still holds its lock; every later read goes to the ledger it wraps.
final class LedgerFailingOnce implements Ledger
{
    /**
     * @param (Closure(): void)|null $meanwhile
     */
    public function __construct(
        private readonly Ledger $ledger,
        private ?Closure $meanwhile,
    ) {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        return $this->ledger->append($event);
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readOfKind($kind);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readOfKindAbout($kind, $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readOfKindWithoutSubject($kind);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readMentioning($texts);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        $this->failOnce();

        return $this->ledger->readOfKindsMentioning($texts, ...$kinds);
    }

    private function failOnce(): void
    {
        $meanwhile = $this->meanwhile;

        if (!$meanwhile instanceof Closure) {
            return;
        }

        $this->meanwhile = null;
        $meanwhile();

        throw new RuntimeException('The ledger is gone for one read.');
    }
}
