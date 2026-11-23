<?php

declare(strict_types=1);

// Seeds a dev install (APP_ENV=dev, after the migrations) for the mock comparison of #1796, because a fresh install has no review with a judged round and a running one.

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Kernel;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

require dirname(__DIR__, 2).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new Kernel(is_string($environment) ? $environment : 'dev', false);
$kernel->boot();
// Built from the public Doctrine registry, because the Ledger service is private and only the test environment has a container that hands it out.
$ledger = new DoctrineLedger($kernel->getContainer()->get('doctrine'), new NativeClock());

$first = '0195f3a0-0000-7000-8000-000000000598';
$second = '0195f3a0-0000-7000-8000-000000000599';
$change = 'feat/hold-pushes-while-main-is-red';
$task = 'Hold pushes while main is red: before each push to a pull request, read the latest status of main and hold the push while it is red.';
$started = ['project' => 'intakt', 'reviewers' => 2, 'quorum' => 'all', 'change' => $change, 'repository' => '/repos/intakt', 'base' => 'main', 'task' => $task];
// Round 2's reviewers carry the ids of its stored reviewers (live.php publishes tool calls under the first), as a round the application opened writes them.
$reviewerIds = [$second => ['correctness' => '0195f3a0-0000-7000-8000-000000000601', 'security' => '0195f3a0-0000-7000-8000-000000000602']];
$reviewer = static fn (string $round, string $role): array => ['round' => $round, 'reviewer' => $reviewerIds[$round][$role] ?? $round.'-'.$role, 'role' => $role, 'tier' => 'standard'];
$finding = static fn (string $id, string $severity, string $text, string $file, int $line): array => ['id' => $id, 'severity' => $severity, 'file' => $file, 'line' => $line, 'symbol' => null, 'text' => $text, 'fixable_by' => 'builder', 'repeat' => false, 'requirement' => null, 'evidence' => null, 'regression' => false, 'gate_gap' => false];
$open = [
    $finding('F1', 'P1', 'The gate reads the status of main once at startup, so main turning red later is never seen.', 'src/Forge/PushGate.php', 88),
    $finding('F2', 'P2', 'Pushes on hold are released in no fixed order. Release the oldest first.', 'src/Forge/PushGate.php', 131),
    $finding('F3', 'P3', 'The guide says "blocked" where the dashboard says "held".', 'docs/guide/holds.md', 12),
];
$securityCall = '0195f3a0-0000-7000-8000-000000000611';

// Round 2 as a stored round with the findings of round 1 open, because the session summary is read from the stored round (StoredSessionSummaries) and the ledger alone leaves it out.
$entities = $kernel->getContainer()->get('doctrine')->getManager();
$identity = VerdictIdentity::fromRecorded(implode('.', [str_repeat('a', 40), str_repeat('b', 40), ...array_fill(0, 3, str_repeat('0', 64))]));
$stored = new ReviewRound(Uuid::fromString($second), new ReviewTarget(new ReviewSubject('intakt', '/repos/intakt', $task, ''), $identity), new ChangeRound($change, 2, json_encode($open, JSON_THROW_ON_ERROR)));
$stored->addReviewer(new UuidV7($reviewerIds[$second]['correctness']), new PanelReviewer(new ReviewerRole('correctness', 'Review the change for correctness.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 200_000));
$stored->addReviewer(new UuidV7($reviewerIds[$second]['security']), new PanelReviewer(new ReviewerRole('security', 'Review the change for security.'), new ReviewerModel(Tier::Standard, Provider::OpenAi), 200_000));
foreach ($stored->reviewers() as $storedReviewer) {
    $storedReviewer->recordStart(new NativeClock()->now());
}
$entities->persist($stored);
$entities->flush();

$events = [
    [LedgerEventKind::ReviewStarted, ['round' => $first, 'number' => 1] + $started, null],
    [LedgerEventKind::ReviewerStarted, $reviewer($first, 'correctness') + ['model' => 'claude-sonnet-5-5'], $first],
    [LedgerEventKind::ReviewerStarted, $reviewer($first, 'security') + ['model' => 'gpt-6.1-terra'], $first],
    [LedgerEventKind::VerdictReceived, $reviewer($first, 'correctness') + ['decision' => 'changes', 'findings' => 2], null],
    [LedgerEventKind::VerdictReceived, $reviewer($first, 'security') + ['decision' => 'changes', 'findings' => 1], null],
    [LedgerEventKind::JudgeDone, ['round' => $first, 'act' => ['F1', 'F2', 'F3'], 'follow_ups' => [], 'fallback' => false, 'findings' => [
        ['id' => 'F1', 'severity' => 'P1', 'text' => 'The gate reads the status of main once at startup, so main turning red later is never seen.', 'merged' => [], 'file' => 'src/Forge/PushGate.php', 'line' => 88],
        ['id' => 'F2', 'severity' => 'P2', 'text' => 'Pushes on hold are released in no fixed order. Release the oldest first.', 'merged' => [], 'file' => 'src/Forge/PushGate.php', 'line' => 131],
        ['id' => 'F3', 'severity' => 'P3', 'text' => 'The guide says "blocked" where the dashboard says "held".', 'merged' => [], 'file' => 'docs/guide/holds.md', 'line' => 12],
    ]], null],
    [LedgerEventKind::ReviewStarted, ['round' => $second, 'number' => 2] + $started, null],
    [LedgerEventKind::ReviewerStarted, $reviewer($second, 'correctness') + ['model' => 'claude-sonnet-5-5'], $second],
    [LedgerEventKind::ReviewerStarted, $reviewer($second, 'security') + ['model' => 'gpt-6.1-terra'], $second],
    [LedgerEventKind::UsageRecorded, ['call' => $securityCall, 'phase' => 'started', 'project' => 'intakt', 'tier' => 'standard', 'round' => $second, 'model' => 'gpt-6.1-terra'], null],
    [LedgerEventKind::UsageRecorded, ['call' => $securityCall, 'phase' => 'settled', 'usages' => [['model' => 'gpt-6.1-terra', 'prompt' => 18_400, 'completion' => 1_250, 'total' => 19_650]]], null],
    [LedgerEventKind::VerdictReceived, $reviewer($second, 'security') + ['decision' => 'approve', 'findings' => 0], null],
];

foreach ($events as [$kind, $payload, $subject]) {
    if (!$ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR), $subject))->succeeded()) {
        fwrite(STDERR, "could not append {$kind->value}\n");
        exit(1);
    }
}

echo 'seeded 1 stored round and '.count($events).' ledger events in '.$kernel->getEnvironment().", open /reviews/{$second}\n";
