<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The panel's result on the published review bench, measured on one dated run, so Trends can lead with what the reviewers catch on planted defects.
final readonly class BenchHeadline
{
    // $runs is null when the README does not say how often each change ran, so the words never claim a count per run nobody wrote down.
    public function __construct(
        public int $found,
        public int $planted,
        public int $falseAlarms,
        public string $ranOn,
        public ?int $runs = null,
    ) {
    }

    public function reading(): string
    {
        return sprintf('%d of %d', $this->found, $this->planted);
    }

    // The defects planted once, because the bench counts each planted defect again in every run (5 defects, 3 runs: "15 of 15").
    public function plantedPerRun(): ?int
    {
        if (null === $this->runs || $this->runs < 1 || 0 !== $this->planted % $this->runs) {
            return null;
        }

        return intdiv($this->planted, $this->runs);
    }

    // The big figure of the headline: "All 5" when every run found every planted defect, else the finds over every run.
    public function figure(): string
    {
        $perRun = $this->plantedPerRun();

        return null !== $perRun && $this->found === $this->planted ? 'All '.$perRun : $this->reading();
    }

    // The words after the figure, which make the headline one sentence: "All 5 planted defects found in each of 3 runs".
    public function words(): string
    {
        $perRun = $this->plantedPerRun();
        $defects = 1 === $perRun ? 'defect' : 'defects';

        return match (true) {
            null === $perRun, 1 === $this->runs => 'planted '.$defects.' found',
            $this->found === $this->planted => sprintf('planted %s found in each of %d runs', $defects, $this->runs),
            default => sprintf('finds of %d planted %s over %d runs', $perRun, $defects, $this->runs),
        };
    }

    public function sentence(): string
    {
        return $this->figure().' '.$this->words();
    }

    public function falseAlarmsInWords(): string
    {
        return match ($this->falseAlarms) {
            0 => 'No false alarms',
            1 => '1 false alarm in all',
            default => $this->falseAlarms.' false alarms in all',
        };
    }
}
