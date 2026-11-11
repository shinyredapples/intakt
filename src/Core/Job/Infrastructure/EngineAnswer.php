<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EngineAnswer
{
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
        $answer = '' === $this->body ? [] : json_decode($this->body, true);

        if (!in_array($this->status, $statuses, true)) {
            throw DockerEngineFailed::refused($this->call, $this->status, is_array($answer) && is_string($answer['message'] ?? null) ? $answer['message'] : '');
        }

        return is_array($answer) ? $answer : throw DockerEngineFailed::unexpected($this->call);
    }
}
