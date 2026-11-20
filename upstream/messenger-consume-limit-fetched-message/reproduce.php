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

final class Ping
{
    public function __construct(public int $number)
    {
    }
}

$database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$transport = new DoctrineTransport(new Connection(['table_name' => 'messenger_messages', 'queue_name' => 'default', 'redeliver_timeout' => 3600, 'auto_setup' => true], $database), new PhpSerializer());
$transport->send(new Envelope(new Ping(1)));
$transport->send(new Envelope(new Ping(2)));

$handled = [];
$bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([Ping::class => [static function (Ping $ping) use (&$handled): void {
    $handled[] = $ping->number;
}]]))]);
$events = new EventDispatcher();
// `messenger:consume doctrine --limit=1 --fetch-size=2`, which `--concurrency=2` sets by default (its fetch size defaults to the concurrency).
$events->addSubscriber(new StopWorkerOnMessageLimitListener(1));

new Worker(['doctrine' => $transport], $bus, $events)->run(['sleep' => 0, 'fetch_size' => 2]);

$waiting = (int) $database->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL');
// What the next consumer, started in its place, gets right away.
$next = [...$transport->get()];
printf('Handled: %s; rows left: %d, of them waiting (not delivered): %d; the next consumer gets %d message(s)%s', implode(', ', $handled), (int) $database->fetchOne('SELECT COUNT(*) FROM messenger_messages'), $waiting, count($next), PHP_EOL);

if (1 !== $waiting || 1 !== count($next)) {
    echo 'The message fetched but not handled at the limit stays delivered, so the next consumer gets it only after redeliver_timeout', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
