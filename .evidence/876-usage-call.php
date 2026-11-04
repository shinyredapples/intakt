<?php

declare(strict_types=1);

// Evidence for #876: one usage call on a stubbed vendor, written to the usage ledger of the database in DATABASE_URL.
// Run from the repository root: APP_ENV=test php .evidence/876-usage-call.php

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();
$usageLedger = $kernel->getContainer()->get('test.service_container')->get(UsageLedger::class);

$answer = new TextResult('a stubbed answer');
$answer->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 1200, completionTokens: 80, cacheCreationTokens: 300, totalTokens: 1580));

$reservation = $usageLedger->reserve('alpha', Tier::Strong);
$usageLedger->settle($reservation, MockPlatformFactory::createPlatform(['stub' => $answer])->invoke('stub', 'hello'));

$kernel->shutdown();
