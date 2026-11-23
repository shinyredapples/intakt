<?php

declare(strict_types=1);

// Seeds an install for the mock comparison of #1796, because a fresh install has no review with a judged round and a running one.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$first = '0195f3a0-0000-7000-8000-000000000598';
$second = '0195f3a0-0000-7000-8000-000000000599';
$change = 'feat/hold-pushes-while-main-is-red';
$started = ['project' => 'intakt', 'reviewers' => 2, 'quorum' => 'all', 'change' => $change, 'repository' => '/repos/intakt', 'base' => 'main', 'task' => 'Hold pushes while main is red: before each push to a pull request, read the latest status of main and hold the push while it is red.'];
$reviewer = static fn (string $round, string $role): array => ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard'];

$events = [
    [LedgerEventKind::ReviewStarted, ['round' => $first, 'number' => 1] + $started, null],
    [LedgerEventKind::ReviewerStarted, $reviewer($first, 'correctness') + ['model' => 'claude-sonnet-5-5'], $first],
    [LedgerEventKind::ReviewerStarted, $reviewer($first, 'security') + ['model' => 'gpt-5.5'], $first],
    [LedgerEventKind::VerdictReceived, $reviewer($first, 'correctness') + ['decision' => 'changes', 'findings' => 2], null],
    [LedgerEventKind::VerdictReceived, $reviewer($first, 'security') + ['decision' => 'changes', 'findings' => 1], null],
    [LedgerEventKind::JudgeDone, ['round' => $first, 'act' => ['F1', 'F2', 'F3'], 'follow_ups' => [], 'fallback' => false, 'findings' => [
        ['id' => 'F1', 'severity' => 'P1', 'text' => 'The gate reads the status of main once at startup, so main turning red later is never seen.', 'merged' => [], 'file' => 'src/Forge/PushGate.php', 'line' => 88],
        ['id' => 'F2', 'severity' => 'P2', 'text' => 'Pushes on hold are released in no fixed order. Release the oldest first.', 'merged' => [], 'file' => 'src/Forge/PushGate.php', 'line' => 131],
        ['id' => 'F3', 'severity' => 'P3', 'text' => 'The guide says "blocked" where the dashboard says "held".', 'merged' => [], 'file' => 'docs/guide/holds.md', 'line' => 12],
    ]], null],
    [LedgerEventKind::ReviewStarted, ['round' => $second, 'number' => 2] + $started, null],
    [LedgerEventKind::ReviewerStarted, $reviewer($second, 'correctness') + ['model' => 'claude-sonnet-5-5'], $second],
    [LedgerEventKind::ReviewerStarted, $reviewer($second, 'security') + ['model' => 'gpt-5.5'], $second],
    [LedgerEventKind::VerdictReceived, $reviewer($second, 'security') + ['decision' => 'approve', 'findings' => 0], null],
];

$appended = [];
foreach ($events as [$kind, $payload, $subject]) {
    $appended[] = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR), $subject));
}

echo 'seeded '.count($appended)." ledger events, open /reviews/{$second}\n";
