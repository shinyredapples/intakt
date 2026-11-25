<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;

// A recorded forge with one pull request: every read comes before the first write, as the contract asks, and each pass records the labels before and after it.
final class RecordingStatusLabels implements StatusLabels
{
    public const string OWN = 'intakt[bot]';
    public const string LABELS = 'labels';
    public const string EVENTS = 'events';

    /** @var list<string> */
    public array $passes = [];
    public int $comments = 0;
    private ?string $failing = null;
    private ?Closure $whileReading = null;

    /**
     * @param list<string>     $labels
     * @param list<LabelEvent> $events
     */
    public function __construct(
        public array $labels,
        private array $events = [],
    ) {
    }

    public function failOnceReading(string $read): void
    {
        $this->failing = $read;
    }

    // Runs once in the middle of the next pass, between its reads, to force the order of two overlapping writers.
    public function whileReading(Closure $other): void
    {
        $this->whileReading = $other;
    }

    public function match(string $project, ChangeId $change, ?RoundStatusLabel $wanted): void
    {
        $before = $this->labels;
        $present = $this->read(self::LABELS, $this->labels);
        $this->interleave();
        $events = $this->read(self::EVENTS, $this->events);
        $reconciliation = StatusReconciliation::toward($wanted, $present);
        $comment = $reconciliation->owesComment($events, static fn (string $login): bool => self::OWN === $login);
        foreach ($reconciliation->remove as $label) {
            $this->labels = array_values(array_diff($this->labels, [$label]));
            $this->events[] = new LabelEvent($label, self::OWN, false);
        }

        if ($reconciliation->add instanceof RoundStatusLabel) {
            $this->labels[] = $reconciliation->add->value;
            $this->events[] = new LabelEvent($reconciliation->add->value, self::OWN, true);
        }

        $this->comments += $comment ? 1 : 0;
        $this->passes[] = sprintf('[%s] -> [%s]', implode(', ', $before), implode(', ', $this->labels));
    }

    /**
     * @template T
     *
     * @param list<T> $value
     *
     * @return list<T>
     */
    private function read(string $read, array $value): array
    {
        if ($this->failing === $read) {
            $this->failing = null;

            throw new ForgeReadFailed(sprintf('GitHub answered 503 to the %s read.', $read));
        }

        return $value;
    }

    private function interleave(): void
    {
        $other = $this->whileReading;
        $this->whileReading = null;
        if ($other instanceof Closure) {
            $other();
        }
    }
}
