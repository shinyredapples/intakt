<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The console builds this object per run for #[MapInput], so the container has no reason to hold it.
#[Exclude]
final class CheckMainInput
{
    #[Argument('The repository whose HEAD names main: a bare mirror or a .git folder')]
    public string $repository;

    #[Option('The head of main the last check passed; omitted, all of main is checked')]
    public ?string $since = null;

    /**
     * @var list<string>
     */
    #[Option('A public key file of the owner (OpenSSH format, one key per line); repeatable')]
    public array $ownerKey = [];

    /**
     * @var list<string>
     */
    #[Option('A merge commit Intakt recorded; repeatable')]
    public array $recordedMerge = [];
}
