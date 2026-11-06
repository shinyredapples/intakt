<?php

declare(strict_types=1);

use Symfony\AI\Platform\Event\ModelRoutingEvent;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

// One platform service that a long-running worker keeps for every request, as a FrankenPHP worker or messenger:consume does.
$sentOptions = [];
$provider = MockPlatformFactory::createProvider(static function (Model $model, mixed $input, array $options) use (&$sentOptions): string {
    $sentOptions[] = $options;

    return 'answer';
});
$dispatcher = new EventDispatcher();
$platform = new Platform([$provider], eventDispatcher: $dispatcher);

// Request 1 asks for a model with an option in its name; the default router calls supports() and then invoke().
$platform->invoke('gpt?temperature=0.9', new MessageBag(Message::ofUser('first request')))->asText();

// Request 2 pins the provider with a routing listener, so supports() is skipped, and asks for the plain model.
$dispatcher->addListener(ModelRoutingEvent::class, static fn (ModelRoutingEvent $event) => $event->setProvider($provider));
$platform->invoke('gpt', new MessageBag(Message::ofUser('second request')))->asText();

echo 'options sent for request 1 ("gpt?temperature=0.9"): ', json_encode($sentOptions[0]), PHP_EOL;
echo 'options sent for request 2 ("gpt"): ', json_encode($sentOptions[1]), PHP_EOL;

if (array_key_exists('temperature', $sentOptions[1])) {
    echo 'Request 2 was sent with the temperature of request 1', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
