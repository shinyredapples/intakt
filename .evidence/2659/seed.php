<?php

declare(strict_types=1);

// Writes straight to the ledger, because the board only reads it and a real round needs a provider (#2659); six mock stress names.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$projects = ['intakt', 'acme-shop', 'field-notes', 'billing-api', 'docs-site', 'customer-portal-legacy-migration'];
$events = [];
foreach ($projects as $p => $project) {
    foreach (range(1, 3) as $n) {
        $round = sprintf('round-%d-%d', $p, $n);
        $change = (string) (600 + $p * 10 + $n);
        $events[] = [LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 2, 'change' => $change, 'number' => 1]];
        // The first change of every project stays in review, so In review holds one card per project and each lane names its project.
        if (1 === $n) {
            $events[] = [LedgerEventKind::ReviewerStarted, ['round' => $round, 'reviewer' => $round.'-code', 'role' => 'Code reviewer', 'tier' => 'standard']];
            continue;
        }
        $events[] = [LedgerEventKind::JudgeDone, ['round' => $round, 'act' => [], 'follow_ups' => []]];
    }
}
$events[] = [LedgerEventKind::HeadFailed, ['project' => 'customer-portal-legacy-migration', 'change' => '655', 'head' => str_repeat('a', 40), 'cause' => 'The checkout of the head failed.', 'failures' => 2, 'retry_at' => '2026-11-19T12:00:00+00:00']];
$events[] = [LedgerEventKind::HeadFailed, ['project' => 'billing-api', 'change' => '635', 'head' => str_repeat('b', 40), 'cause' => 'The checkout of the head failed.', 'failures' => 1, 'retry_at' => '2026-11-19T12:00:00+00:00']];

foreach ($events as [$kind, $payload]) {
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    if (!$result->succeeded()) {
        throw new RuntimeException('the ledger refused a '.$kind->value.' event: '.$result->failure);
    }
}

echo 'seeded '.count($projects).' projects: '.count($events)." events\n";
