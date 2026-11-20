<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EngineAnswer
{
    private const int FRAME_HEADER_BYTES = 8;
    private const string IMAGE_ID = '/^sha256:[a-f0-9]{64}$/D';

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

    // For a log cut after a number of bytes: the frame the cut splits keeps what arrived of it, and a header the cut splits is dropped.
    public function demultiplexedHead(int ...$statuses): string
    {
        $this->refuseOtherThan($statuses);
        $text = '';

        for ($offset = 0; strlen($this->body) - $offset >= self::FRAME_HEADER_BYTES; $offset += self::FRAME_HEADER_BYTES + $length) {
            $header = unpack('Cstream/x3/Nlength', $this->body, $offset);
            $length = is_array($header) && is_int($header['length']) ? $header['length'] : throw DockerEngineFailed::unexpected($this->call);
            $text .= substr($this->body, $offset + self::FRAME_HEADER_BYTES, $length);
        }

        return $text;
    }

    // A build answers one JSON object per line and reports a failed step as an error line after a 200, so the lines decide; only the image id the engine names on completion makes a build without an error a success.
    public function built(int ...$statuses): ImageBuild
    {
        $this->refuseOtherThan($statuses);
        $output = '';
        $error = null;
        $image = null;

        foreach (explode("\n", $this->body) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            [$stream, $failure, $built] = $this->buildLine($line);
            $output .= $stream;
            $error = $failure ?? $error;
            $image = $built ?? $image;
        }

        if (null === $error && null === $image) {
            throw DockerEngineFailed::unexpected($this->call);
        }

        return new ImageBuild($output, $error, $image);
    }

    /**
     * @return array{string, ?string, ?string}
     */
    private function buildLine(string $line): array
    {
        $message = json_decode($line);

        if (!$message instanceof stdClass) {
            throw DockerEngineFailed::unexpected($this->call);
        }

        $stream = $message->stream ?? '';
        $error = $message->error ?? null;
        $aux = $message->aux ?? null;
        $image = $aux instanceof stdClass ? ($aux->ID ?? null) : null;

        if (!is_string($stream) || (null !== $error && !is_string($error)) || (null !== $image && (!is_string($image) || 1 !== preg_match(self::IMAGE_ID, $image)))) {
            throw DockerEngineFailed::unexpected($this->call);
        }

        return [$stream, $error, $image];
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
