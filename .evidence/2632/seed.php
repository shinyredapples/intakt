<?php

declare(strict_types=1);

// Seeds a bin/instance install for the Waiting on you evidence of #2632, because a fresh install has no outage and no running review to show.

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel((string) getenv('APP_ENV'), false);
$kernel->boot();
$services = $kernel->getContainer()->get('test.service_container');
$now = $services->get(ClockInterface::class)->now();

$appended = [];
$mode = $argv[1] ?? '';

if ('page' === $mode) {
    $entities = $services->get(EntityManagerInterface::class);
    $entities->persist(new OutageNoticeRecord(new Account(Provider::Anthropic, 'main'), $now->modify('-3 hours'), $now->modify('-2 hours -40 minutes')));
    $entities->persist(new OutageNoticeRecord(new Account(Provider::OpenAi, 'work'), $now->modify('-50 minutes'), $now->modify('-35 minutes')));
    $entities->persist(new OutageNoticeRecord(new Account(Provider::Codex, 'spare'), $now->modify('-2 hours'), $now->modify('-100 minutes')));
    $entities->flush();

    $ledger = $services->get(Ledger::class);
    foreach ([
        [LedgerEventKind::ReviewStarted, ['round' => '0195f3a0-0000-7000-8000-000000000001', 'project' => 'alpha', 'reviewers' => 2, 'change' => '42', 'number' => 2]],
        [LedgerEventKind::ReviewStarted, ['round' => '0195f3a0-0000-7000-8000-000000000002', 'project' => 'beta', 'reviewers' => 2, 'change' => '7', 'number' => 1]],
    ] as [$kind, $payload]) {
        $appended[] = $ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));
    }
    $mode = 'end';
}

// `end-main` ends the other waiting outage on its own, so the page lists it among the notices handled today.
$ending = ['end' => new Account(Provider::Codex, 'spare'), 'end-main' => new Account(Provider::Anthropic, 'main')][$mode] ?? null;
if ($ending instanceof Account) {
    // Through the real reading path, so the reading that ends the outage keeps its moment on the notice.
    $services->get(MeasuredUse::class)->record(new WindowReading($ending, UsageWindow::FiveHour, new Usage(12.0, $now->modify('+3 hours'))));
}

echo 'seeded '.$mode.' ('.count($appended)." ledger events)\n";
