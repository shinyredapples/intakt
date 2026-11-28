<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use DateTimeImmutable;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\SplitLines;
use Intakt\Core\Build\Domain\SplitPart;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use stdClass;

// Everything a split needs to finish without a second model call, because a crash between two parts resumes from this record alone.
final readonly class SplitPlan
{
    private const string MARKER_OPENS = '<!-- intakt:part ';

    /**
     * @param list<SplitPart> $parts
     * @param list<string>    $labels
     */
    private function __construct(
        public string $project,
        public int $task,
        public string $run,
        private array $parts,
        private array $labels,
        private DateTimeImmutable $plannedAt,
    ) {
    }

    // The part lines are kept as the model wrote them, because the record is what files the parts and plain code reads them again with the same check; the title and the body are not, because they are untrusted text no later step needs.
    public static function payloadOf(SizingRequest $request, SizingVerdict $verdict, string $run): string
    {
        return json_encode([
            'project' => $request->project,
            'task' => (string) $request->task,
            'size' => $verdict->size->value,
            'estimated_lines' => $verdict->estimated_lines,
            'text' => SizingRecords::textOf($request),
            'run' => $run,
            'parts' => $verdict->parts,
            'labels' => $request->labels,
        ], JSON_THROW_ON_ERROR);
    }

    // Null for a payload whose parts plain code does not read whole, or that names another task than the one asked for, because a split resumed from a guess would file parts that wait for the wrong work or belong to another issue.
    public static function of(SizingRequest $request, RecordedEvent $recorded): ?self
    {
        // Decoded to objects, so a JSON object such as `{"0":"ready"}` stays an object and is never read as a list.
        $fields = json_decode($recorded->event->payload);
        $fields = $fields instanceof stdClass ? get_object_vars($fields) : [];
        $task = is_string($fields['task'] ?? null) ? $fields['task'] : null;
        $run = $fields['run'] ?? null;
        $parts = SplitLines::partsIn(self::textsIn($fields['parts'] ?? null) ?? []);
        $labels = self::textsIn($fields['labels'] ?? null);

        if ($request->project !== ($fields['project'] ?? null) || (string) $request->task !== $task || !is_string($run) || null === $parts || null === $labels) {
            return null;
        }

        return new self($request->project, $request->task, $run, $parts, $labels, $recorded->recordedAt);
    }

    // By the marker's opening, because only a part carries it, and a trusted author who copies one into a task only keeps that task whole.
    public static function carriesAMarker(SizingRequest $request): bool
    {
        return str_contains($request->body, self::MARKER_OPENS);
    }

    /**
     * @return list<SplitPart>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    // The `Waits for` lines name the parts this one depends on by the issue numbers they were filed under (keyed by part number), so the build order follows the split's own order.
    /**
     * @param array<int, int> $filed
     */
    public function issueOf(SplitPart $part, array $filed): PartIssue
    {
        $waits = array_map(static fn (int $earlier): string => sprintf('Waits for #%d', $filed[$earlier]), $part->dependsOn);
        $body = sprintf("Part %d of %d of #%d, split by sizing.\n\nDone when: %s\nVerify: %s\nFiles: %s", $part->number, count($this->parts), $this->task, $part->doneWhen, $part->verify, $part->files);

        return new PartIssue($part->title, [] === $waits ? $body : $body."\n\n".implode("\n", $waits), $this->markerOf($part), new IssueLabels(...$this->labels), $this->plannedAt);
    }

    // Null for anything but a list of texts, so the plan is unreadable: dropping one entry would leave a split of other parts or parts without one of the original's labels.
    /**
     * @return list<string>|null
     */
    private static function textsIn(mixed $values): ?array
    {
        if (!is_array($values)) {
            return null;
        }

        $texts = array_values(array_filter($values, is_string(...)));

        return count($texts) === count($values) ? $texts : null;
    }

    // The task's sizing key and the part's number, so a part of one split is never taken for a part of another.
    private function markerOf(SplitPart $part): string
    {
        return sprintf('%s%s %d -->', self::MARKER_OPENS, SizingRecords::keyOf($this->project, $this->task), $part->number);
    }
}
