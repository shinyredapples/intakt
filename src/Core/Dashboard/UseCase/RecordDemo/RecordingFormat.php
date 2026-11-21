<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

// The one home of the recording's and the capture's format, so the two never disagree on how a moment is written.
final readonly class RecordingFormat
{
    // With microseconds, because ledger rows and updates of one second must keep their order.
    private const array MOMENTS = [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\TH:i:s.uP', DateTimeNormalizer::TIMEZONE_KEY => 'UTC'];

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        #[Target('stored')]
        private SerializerInterface $serializer,
    ) {
    }

    public function write(Recording $recording): string
    {
        return $this->serializer->serialize($recording, JsonEncoder::FORMAT, [...self::MOMENTS, JsonEncode::OPTIONS => self::JSON | JSON_PRETTY_PRINT])."\n";
    }

    /**
     * @throws RecordingIsMalformed
     */
    public function read(string $json): Recording
    {
        try {
            return $this->serializer->deserialize($json, Recording::class, JsonEncoder::FORMAT, self::MOMENTS);
        } catch (ExceptionInterface $failure) {
            throw RecordingIsMalformed::because($failure);
        }
    }

    public function lineOf(CapturedUpdate $update): string
    {
        return $this->serializer->serialize($update, JsonEncoder::FORMAT, [...self::MOMENTS, JsonEncode::OPTIONS => self::JSON])."\n";
    }

    // Null for a line that is no update, so the reader names the line.
    public function updateOf(string $line): ?CapturedUpdate
    {
        try {
            return $this->serializer->deserialize($line, CapturedUpdate::class, JsonEncoder::FORMAT, self::MOMENTS);
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
