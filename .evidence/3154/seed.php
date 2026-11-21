<?php

declare(strict_types=1);

// Writes straight to the ledger, because the board only reads it and a real round needs a provider (#3154): one change in review, one judged.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$events = [
    [LedgerEventKind::ReviewStarted, ['round' => 'round-1', 'project' => 'acme-shop', 'reviewers' => 2, 'change' => '41', 'number' => 1]],
    [LedgerEventKind::JudgeDone, ['round' => 'round-1', 'act' => [], 'follow_ups' => []]],
    [LedgerEventKind::ReviewStarted, ['round' => 'round-2', 'project' => 'acme-shop', 'reviewers' => 2, 'change' => '42', 'number' => 1]],
    [LedgerEventKind::ReviewerStarted, ['round' => 'round-2', 'reviewer' => 'round-2-code', 'role' => 'Code reviewer', 'tier' => 'standard']],
];

foreach ($events as [$kind, $payload]) {
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    if (!$result->succeeded()) {
        throw new RuntimeException('the ledger refused a '.$kind->value.' event: '.$result->failure);
    }
}

echo 'seeded '.count($events)." events\n";
