<?php

declare(strict_types=1);

// Writes straight to the ledger, because the board only reads it and a real round needs a provider (#2543): three running reviewers, so a row of three lanes is full.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$lanes = [
    ['round-1', 'acme-shop', '41', 'Code reviewer', 'standard'],
    ['round-2', 'acme-shop-storefront', '42', 'Security and privacy reviewer', 'strong'],
    ['round-3', 'acme-billing', '43', 'Code reviewer', 'standard'],
];

$events = [];
foreach ($lanes as [$round, $project, $change, $role, $tier]) {
    $events[] = [LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 1, 'change' => $change, 'number' => 1]];
    $events[] = [LedgerEventKind::ReviewerStarted, ['round' => $round, 'reviewer' => $round.'-code', 'role' => $role, 'tier' => $tier]];
}

foreach ($events as [$kind, $payload]) {
    $result = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    if (!$result->succeeded()) {
        throw new RuntimeException('the ledger refused a '.$kind->value.' event: '.$result->failure);
    }
}

echo 'seeded '.count($events)." events\n";
