<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeNotARange;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeOption;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The console builds this object per run for #[MapInput], so the container has no reason to hold it.
#[Exclude]
final class GuardMainInput
{
    #[Argument('The project a violation halts, as its owner card names it')]
    public string $project;

    #[Argument('The repository whose main branch is checked: the top folder of a checkout, or a bare repository')]
    public string $repository;

    #[Argument('The full commit name of the head of main the watchdog saw last')]
    public string $lastSeenHead;

    /** @var list<string> */
    #[Option('A merge Intakt recorded, as <base>..<head> with full commit names (repeatable)')]
    public array $recordedMerge = [];

    /** @var list<string> */
    #[Option('An SSH public key the owner registered, "<type> <base64>" (repeatable)')]
    public array $ownerKey = [];

    /**
     * @throws CommitNameNotFull
     * @throws RecordedMergeNotARange
     */
    public function watchedMain(): WatchedMain
    {
        return new WatchedMain($this->lastSeenHead, array_map(RecordedMergeOption::parsed(...), $this->recordedMerge));
    }
}
