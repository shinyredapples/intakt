<?php

declare(strict_types=1);

use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

$database = $argv[1] ?? throw new InvalidArgumentException('Name a fresh SQLite file.');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$database;
new Dotenv()->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

$fixture = new RoundFixture($container);
$roundId = $fixture->startFromSource(new ChangeSource('/repos/alpha', 'main'));
$findings = [
    new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null),
    new Finding('B', Severity::P2, null, null, null, 'Text of B.', FixableBy::Builder, false, null, null),
];

foreach ($fixture->bus->messages as $message) {
    $fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
}

$fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer)])))(new JudgeRound($roundId));
$fixture->startFor('feat/next', $fixture->bus, 1);

foreach ($fixture->ledger()->readInOrder() as $recorded) {
    echo $recorded->event->kind->value, ' ', $recorded->event->payload, "\n";
}

$kernel->shutdown();
