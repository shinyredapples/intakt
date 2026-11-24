<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use JsonException;

// The report is the hand-off between bin/install-check and bin/journey-alarm, so it is read as untrusted input.
final readonly class InstallReport
{
    /**
     * @param list<InstallStepResult> $steps
     */
    public function __construct(public array $steps)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InstallCheckCouldNotRun('the install-check report is not JSON');
        }

        $steps = is_array($data) && is_array($data['steps'] ?? null) && array_is_list($data['steps']) ? $data['steps'] : null;

        if (null === $steps) {
            throw new InstallCheckCouldNotRun('the install-check report holds no list of steps');
        }

        return new self(array_map(self::stepFrom(...), $steps));
    }

    public function toJson(): string
    {
        return json_encode(
            ['steps' => array_map(static fn (InstallStepResult $step): array => ['name' => $step->name, 'status' => $step->status->value, 'tail' => $step->tail], $this->steps)],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        )."\n";
    }

    public function hasFailed(): bool
    {
        return array_any($this->steps, static fn (InstallStepResult $step): bool => InstallStatus::Failed === $step->status);
    }

    private static function stepFrom(mixed $step): InstallStepResult
    {
        $name = is_array($step) ? $step['name'] ?? null : null;
        $status = is_array($step) && is_string($step['status'] ?? null) ? InstallStatus::tryFrom($step['status']) : null;
        $tail = is_array($step) ? $step['tail'] ?? null : null;

        if (!is_string($name) || '' === $name || null === $status || !is_string($tail)) {
            throw new InstallCheckCouldNotRun('a step of the install-check report has no name, status or tail');
        }

        return new InstallStepResult($name, $status, $tail);
    }
}
