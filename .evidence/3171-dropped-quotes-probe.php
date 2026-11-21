<?php

declare(strict_types=1);

// Run as: bin/instance -- php .evidence/3171-dropped-quotes-probe.php
// Writes two judged rounds into the running install's ledger (the demo's F3 as recorded in fixtures/demo/sample-change-review.json, and the stray-backtick finding), then asks the running server for each review page and prints what the dropped card's Quoted code disclosure lists.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__).'/vendor/autoload.php';

passthru('php bin/console doctrine:migrations:migrate --no-interaction --quiet', $status);
if (0 !== $status) {
    exit('migrations failed'.PHP_EOL);
}

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');
$kernel = new Kernel((string) $_SERVER['APP_ENV'], false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);

$recording = json_decode((string) file_get_contents('fixtures/demo/sample-change-review.json'), true, flags: JSON_THROW_ON_ERROR);
$f3 = null;
foreach ($recording['events'] as $event) {
    if ('judge_done' === $event['kind']) {
        $f3 = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR)['dropped'][0];
    }
}

$rounds = [
    'demo F3' => $f3,
    'stray backtick' => ['id' => 'F1', 'severity' => 'P2', 'text' => 'A stray ` appears before `ghostName`.', 'file' => 'src/A.php', 'line' => 3, 'reason' => 'none of its 1 quoted code fragments is in the change'],
];

foreach ($rounds as $name => $dropped) {
    $round = (string) Uuid::v7();
    (void) $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, json_encode(['round' => $round, 'project' => 'probe', 'reviewers' => 1, 'change' => 'probe-'.$round, 'number' => 1], JSON_THROW_ON_ERROR)));
    (void) $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, json_encode(['round' => $round, 'act' => [], 'follow_ups' => [], 'findings' => [], 'dropped' => [$dropped]], JSON_THROW_ON_ERROR)));

    // The dashboard answers only a loopback name with its configured port (RefuseForeignHost), and the instance listens on a port of its own.
    $url = getenv('INTAKT_INSTANCE_URL').'/reviews/'.$round;
    $context = stream_context_create(['http' => ['header' => 'Host: localhost:'.$_SERVER['DASHBOARD_PORT']]]);
    $card = new Crawler((string) file_get_contents($url, false, $context))->filter('[data-dropped]');
    $disclosure = $card->filter('details.fc-quotes');

    printf("%s: GET /reviews/<round> %s\n", $name, $http_response_header[0] ?? '');
    printf("  text:     %s\n", $dropped['text']);
    printf("  call:     %s\n", $card->filter('.fc-call')->text());
    printf("  summary:  %s (open: %s)\n", $disclosure->filter('summary')->text(), null === $disclosure->attr('open') ? 'no' : 'yes');
    printf("  quotes:   %s\n", json_encode($disclosure->filter('.fc-quote-list code')->each(static fn (Crawler $code): string => $code->text())));
    printf("  note:     %s\n", $disclosure->filter('.fc-unchecked')->count() > 0 ? $disclosure->filter('.fc-unchecked')->text() : '(none)');
    printf("  reason:   %s\n", $disclosure->filter('.fc-reason')->text());
}
