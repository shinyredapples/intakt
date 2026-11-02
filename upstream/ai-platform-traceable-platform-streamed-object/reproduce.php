<?php

declare(strict_types=1);

use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class Answer
{
    public function __construct(public string $verdict)
    {
    }
}

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new PlatformSubscriber());
$streamed = static fn (): StreamResult => new StreamResult((static function (): Generator {
    yield new TextDelta('{"verdict":');
    yield new TextDelta('"approve"}');
})());
$options = [PlatformSubscriber::RESPONSE_FORMAT => Answer::class, 'stream' => true];

$plain = MockPlatformFactory::createPlatform($streamed, eventDispatcher: $dispatcher)->invoke('model', 'Answer.', $options)->asObject(Answer::class);
echo sprintf('the platform itself assembles the streamed object: %s', $plain->verdict), PHP_EOL;

try {
    $traced = new TraceablePlatform(MockPlatformFactory::createPlatform($streamed, eventDispatcher: $dispatcher))->invoke('model', 'Answer.', $options)->asObject(Answer::class);
} catch (ExceptionInterface $failure) {
    echo sprintf('the traced platform loses it: %s', $failure->getMessage()), PHP_EOL;

    exit(1);
}

echo sprintf('the traced platform assembles the streamed object: %s', $traced->verdict), PHP_EOL;
echo 'REPRODUCTION PASSES', PHP_EOL;
