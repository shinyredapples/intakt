<?php

declare(strict_types=1);

// Evidence for #2732: appends the ledger events of one board state to the running instance's database (DATABASE_URL from bin/instance).
// Usage: php .evidence/2732/seed.php idle|filter

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$events = match ($argv[1] ?? '') {
    // A review ran and was judged, a task failed and another pushed: nothing is running.
    'idle' => [
        [LedgerEventKind::ReviewStarted, ['round' => 'round-1', 'project' => 'alpha', 'reviewers' => 2, 'change' => '42', 'number' => 1]],
        [LedgerEventKind::JudgeDone, ['round' => 'round-1', 'act' => [], 'follow_ups' => []]],
        [LedgerEventKind::BuildStepEnded, ['project' => 'alpha', 'task' => 'task-7', 'step' => 'task', 'outcome' => 'failed', 'failure' => 'The task could not be read.']],
        [LedgerEventKind::BuildStepEnded, ['project' => 'alpha', 'task' => 'task-8', 'step' => 'push', 'outcome' => 'passed', 'failure' => null]],
    ],
    // alpha is at rest, beta has a review running and gamma a build in flight.
    'filter' => [
        [LedgerEventKind::ReviewStarted, ['round' => 'round-2', 'project' => 'beta', 'reviewers' => 2, 'change' => '43', 'number' => 1]],
        [LedgerEventKind::BuildStepEnded, ['project' => 'gamma', 'task' => 'task-9', 'step' => 'clone', 'outcome' => 'passed', 'failure' => null]],
    ],
    default => throw new InvalidArgumentException('Usage: seed.php idle|filter'),
};

foreach ($events as [$kind, $payload]) {
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    if (!$result->succeeded()) {
        throw new RuntimeException('the ledger refused a '.$kind->value.' event: '.$result->failure);
    }
}

echo 'seeded '.$argv[1].': '.count($events)." events\n";
