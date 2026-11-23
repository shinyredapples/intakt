<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\StreamChunks;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use ReflectionClass;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// Shared by the listeners of one stream, so whichever of them finds the stream cut can say what came before; excluded from the container because one is built per stream.
#[Exclude]
final class StreamTally
{
    private const int KEPT = 5;

    private const int TEXT_SHOWN = 80;

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private int $chunks = 0;

    /**
     * @var list<string>
     */
    private array $last = [];

    public function counted(DeltaInterface $delta): void
    {
        ++$this->chunks;
        $this->last = array_slice([...$this->last, $this->described($delta)], -self::KEPT);
    }

    public function cut(StreamCutBy $by, string $message, ?Throwable $previous = null): StreamEndedEarly
    {
        return new StreamEndedEarly($message, $by, $this->chunks, new StreamChunks(...$this->last), $previous);
    }

    // Only the kind and the start of its text, because the end of the stream is what a diagnosis needs, and the model's whole answer stays out of the finding.
    private function described(DeltaInterface $delta): string
    {
        $kind = new ReflectionClass($delta)->getShortName();

        return match (true) {
            $delta instanceof TextDelta => $kind.' '.$this->quoted($delta->getText()),
            $delta instanceof ThinkingDelta => $kind.' '.$this->quoted($delta->getThinking()),
            $delta instanceof MetadataDelta => $kind.' '.$delta->getKey().'='.$this->valueOf($delta),
            default => $kind,
        };
    }

    private function quoted(string $text): string
    {
        return json_encode(mb_substr($text, 0, self::TEXT_SHOWN), self::JSON);
    }

    // Only a scalar is shown, because a provider's metadata object may not encode.
    private function valueOf(MetadataDelta $delta): string
    {
        $value = $delta->getValue();

        return null === $value || is_scalar($value) ? (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES) : get_debug_type($value);
    }
}
