<?php

declare(strict_types=1);

// Writes straight to the ledger, because the board only reads it and no console command records a build step without a real build run (#2732).

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$events = match ($argv[1] ?? '') {
    // Every task at rest (a failed step, a passed push), because the asleep octopus shows only while nothing runs (#2732, D1).
    'idle' => [
        [LedgerEventKind::ReviewStarted, ['round' => 'round-1', 'project' => 'alpha', 'reviewers' => 2, 'change' => '42', 'number' => 1]],
        [LedgerEventKind::JudgeDone, ['round' => 'round-1', 'act' => [], 'follow_ups' => []]],
        [LedgerEventKind::BuildStepEnded, ['project' => 'alpha', 'task' => 'task-7', 'step' => 'task', 'outcome' => 'failed', 'failure' => 'The task could not be read.']],
        [LedgerEventKind::BuildStepEnded, ['project' => 'alpha', 'task' => 'task-8', 'step' => 'push', 'outcome' => 'passed', 'failure' => null]],
    ],
    // A review and a build in flight beside alpha, because the project filter shows the asleep octopus only for a project at rest while others work (#2732, D3).
    'filter' => [
        [LedgerEventKind::ReviewStarted, ['round' => 'round-2', 'project' => 'beta', 'reviewers' => 2, 'change' => '43', 'number' => 1]],
        [LedgerEventKind::BuildStepEnded, ['project' => 'gamma', 'task' => 'task-9', 'step' => 'clone', 'outcome' => 'passed', 'failure' => null]],
    ],
    // A project whose only work is a pushed build, because the filter must list it to show its asleep octopus while others work (#2732, F1).
    'buildonly' => [
        [LedgerEventKind::BuildStepEnded, ['project' => 'delta', 'task' => 'task-10', 'step' => 'push', 'outcome' => 'passed', 'failure' => null]],
    ],
    default => throw new InvalidArgumentException('Usage: seed.php idle|filter|buildonly'),
};

foreach ($events as [$kind, $payload]) {
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    if (!$result->succeeded()) {
        throw new RuntimeException('the ledger refused a '.$kind->value.' event: '.$result->failure);
    }
}

echo 'seeded '.$argv[1].': '.count($events)." events\n";
