<?php

declare(strict_types=1);

// Run on a running install (bin/instance -- php .evidence/3171-dropped-quotes-probe.php), because the evidence of #3171 is the page the install serves and not a test's kernel.

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Kernel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require __DIR__.'/../vendor/autoload.php';

passthru('php bin/console doctrine:migrations:migrate --no-interaction --quiet', $status);
if (0 !== $status) {
    exit('migrations failed'.PHP_EOL);
}

new Dotenv()->bootEnv(__DIR__.'/../.env');
$environment = $_SERVER['APP_ENV'] ?? null;
$port = $_SERVER['DASHBOARD_PORT'] ?? null;
if (!is_string($environment) || !is_string($port)) {
    exit('APP_ENV and DASHBOARD_PORT must be set'.PHP_EOL);
}

$kernel = new Kernel($environment, false);
$kernel->boot();
$ledger = $kernel->getContainer()->get('test.service_container')->get(Ledger::class);
if (!$ledger instanceof Ledger) {
    exit('no ledger'.PHP_EOL);
}

$f3 = droppedOfTheRecording((string) file_get_contents(__DIR__.'/../fixtures/demo/sample-change-review.json'));

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
    $context = stream_context_create(['http' => ['header' => 'Host: localhost:'.$port]]);
    $card = new Crawler((string) file_get_contents($url, false, $context))->filter('[data-dropped]');
    $disclosure = $card->filter('details.fc-quotes');

    printf("%s: GET /reviews/<round> %s\n", $name, $http_response_header[0] ?? '');
    printf("  text:     %s\n", is_string($dropped['text']) ? $dropped['text'] : '');
    printf("  call:     %s\n", $card->filter('.fc-call')->text());
    printf("  summary:  %s (open: %s)\n", $disclosure->filter('summary')->text(), null === $disclosure->attr('open') ? 'no' : 'yes');
    printf("  quotes:   %s\n", json_encode($disclosure->filter('.fc-quote-list code')->each(static fn (Crawler $code): string => $code->text())));
    printf("  note:     %s\n", $disclosure->filter('.fc-unchecked')->count() > 0 ? $disclosure->filter('.fc-unchecked')->text() : '(none)');
    printf("  reason:   %s\n", $disclosure->filter('.fc-reason')->text());
}

/**
 * @return array<mixed>
 */
function droppedOfTheRecording(string $recording): array
{
    $events = json_decode($recording, true, flags: JSON_THROW_ON_ERROR);
    $dropped = null;
    foreach (is_array($events) && is_array($events['events'] ?? null) ? $events['events'] : [] as $event) {
        if (is_array($event) && 'judge_done' === ($event['kind'] ?? null) && is_string($event['payload'] ?? null)) {
            $payload = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
            $dropped = is_array($payload) && is_array($payload['dropped'] ?? null) ? ($payload['dropped'][0] ?? null) : null;
        }
    }

    return is_array($dropped) ? $dropped : exit('the recording holds no dropped finding'.PHP_EOL);
}
