<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$usageLedger = $container->get(UsageLedger::class);
assert($usageLedger instanceof UsageLedger);

$answer = new TextResult('a stubbed answer');
$answer->getMetadata()->add('token_usage', new TokenUsage(
    promptTokens: 1200,
    completionTokens: 80,
    cacheCreationTokens: 300,
    totalTokens: 1580,
));

$reservation = $usageLedger->reserve('alpha', Tier::Strong);
$usageLedger->settle($reservation, MockPlatformFactory::createPlatform(['stub' => $answer])->invoke('stub', 'hello'));

$kernel->shutdown();
