<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

// `messenger:consume async --concurrency=2 --limit=1` hands the worker these options: --fetch-size defaults to
// --concurrency (8.2), and the message limit stops the worker after the first message it hands out.
const FETCH_SIZE = 2;
const MESSAGE_LIMIT = 1;
const REDELIVER_TIMEOUT = 3600;

final class Ping
{
    public function __construct(public readonly int $number)
    {
    }
}

$dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$transport = new DoctrineTransport(new Connection(['redeliver_timeout' => REDELIVER_TIMEOUT], $dbal), new PhpSerializer());
$transport->send(new Envelope(new Ping(1)));
$transport->send(new Envelope(new Ping(2)));

$handled = [];
$bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
    Ping::class => [static function (Ping $ping) use (&$handled): void { $handled[] = $ping->number; }],
]))]);
$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(MESSAGE_LIMIT));

(new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0, 'fetch_size' => FETCH_SIZE]);

echo 'The stopped worker handled: '.implode(', ', $handled).PHP_EOL;

// The next worker starts at once, as a supervisor restarts the stopped one.
$next = iterator_to_array($transport->get(), false);
$nextNumber = [] === $next ? null : $next[0]->getMessage()->number;

if (2 === $nextNumber) {
    echo 'The next worker got message 2 at once.'.PHP_EOL;
    echo 'REPRODUCTION PASSES'.PHP_EOL;
    exit(0);
}

echo 'The next worker got nothing: message 2 stays delivered and hidden until redeliver_timeout ('.REDELIVER_TIMEOUT.' s).'.PHP_EOL;
echo 'REPRODUCTION FAILS'.PHP_EOL;
exit(1);
