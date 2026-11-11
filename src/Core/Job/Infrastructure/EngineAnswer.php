<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EngineAnswer
{
    private const int FRAME_HEADER_BYTES = 8;

    public function __construct(
        private string $call,
        private int $status,
        private string $body,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function accepting(int ...$statuses): array
    {
        $this->refuseOtherThan($statuses);
        $answer = '' === $this->body ? [] : json_decode($this->body, true);

        return is_array($answer) ? $answer : throw DockerEngineFailed::unexpected($this->call);
    }

    // A log of a container without a terminal comes in frames (one byte for the stream, three zero bytes and a big-endian length before each chunk), so the headers must go before the text is read.
    public function demultiplexed(int ...$statuses): string
    {
        $this->refuseOtherThan($statuses);
        $text = '';

        for ($offset = 0; $offset < strlen($this->body); $offset += self::FRAME_HEADER_BYTES + $length) {
            $length = $this->frameLengthAt($offset);
            $text .= substr($this->body, $offset + self::FRAME_HEADER_BYTES, $length);
        }

        return $text;
    }

    // A build answers one JSON object per line and reports a failed step as an error line after a 200, so the lines decide.
    public function built(int ...$statuses): ImageBuild
    {
        $this->refuseOtherThan($statuses);
        $output = '';
        $error = null;

        foreach (explode("\n", trim($this->body)) as $line) {
            [$stream, $failure] = $this->buildLine(trim($line));
            $output .= $stream;
            $error = $failure ?? $error;
        }

        return new ImageBuild($output, $error);
    }

    /**
     * @return array{string, ?string}
     */
    private function buildLine(string $line): array
    {
        $message = '' === $line ? [] : json_decode($line, true);
        $stream = is_array($message) ? ($message['stream'] ?? '') : null;
        $error = is_array($message) ? ($message['error'] ?? null) : false;

        if (!is_string($stream) || (null !== $error && !is_string($error))) {
            throw DockerEngineFailed::unexpected($this->call);
        }

        return [$stream, $error];
    }

    /**
     * @param array<int> $statuses
     */
    private function refuseOtherThan(array $statuses): void
    {
        if (in_array($this->status, $statuses, true)) {
            return;
        }

        $answer = json_decode($this->body, true);

        throw DockerEngineFailed::refused($this->call, $this->status, is_array($answer) && is_string($answer['message'] ?? null) ? $answer['message'] : '');
    }

    private function frameLengthAt(int $offset): int
    {
        $header = strlen($this->body) - $offset >= self::FRAME_HEADER_BYTES ? unpack('Cstream/x3/Nlength', $this->body, $offset) : false;

        if (!is_array($header) || !is_int($header['length']) || $offset + self::FRAME_HEADER_BYTES + $header['length'] > strlen($this->body)) {
            throw DockerEngineFailed::unexpected($this->call);
        }

        return $header['length'];
    }
}
