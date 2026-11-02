<?php

declare(strict_types=1);

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockResultConverter;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// A platform that decorates another one hands the decorated result's converted answer on, as a failover or error-typing layer does.
final class PassingOn implements ResultConverterInterface
{
    public function __construct(private readonly DeferredResult $inner)
    {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        return $this->inner->getResult();
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}

$stream = new StreamResult((static function (): Generator {
    yield new TextDelta('Hello.');
    yield new TokenUsage(promptTokens: 40, completionTokens: 10);
})());
$inner = new DeferredResult(new MockResultConverter(), new InMemoryRawResult(object: $stream), ['stream' => true]);
$outer = new DeferredResult(new PassingOn($inner), $inner->getRawResult(), ['stream' => true]);

foreach ($outer->asStream() as $delta) {
}

$usage = $outer->getMetadata()->get('token_usage');
$prompt = $usage instanceof TokenUsageInterface ? $usage->getPromptTokens() : null;
echo sprintf('the stream reported 40 prompt tokens once, the nested result reads %s', var_export($prompt, true)), PHP_EOL;

if (40 !== $prompt) {
    echo 'a nested deferred result counts the usage of a streamed answer twice', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
