<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Override;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

// Records instead of posting, because an instance under a journey runs no hub, and the journey reads what the dashboard published from another process.
final readonly class RecordingHub implements HubInterface
{
    public const string FILE = 'journey-hub-updates.jsonl';

    public function __construct(
        private HubInterface $hub,
        private string $directory,
    ) {
    }

    /**
     * @return list<Update>
     */
    public static function updatesIn(string $directory): array
    {
        $file = $directory.'/'.self::FILE;

        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(self::updateOf(...), false === $lines ? [] : $lines);
    }

    #[Override]
    public function publish(Update $update): string
    {
        $line = json_encode(['topics' => $update->getTopics(), 'data' => $update->getData(), 'private' => $update->isPrivate()], JSON_THROW_ON_ERROR);
        // Locked, because the web server and a consumer may publish at the same moment.
        new Filesystem()->appendToFile($this->directory.'/'.self::FILE, $line.PHP_EOL, true);

        return $update->getId() ?? 'urn:uuid:'.Uuid::v4()->toRfc4122();
    }

    #[Override]
    public function getPublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    #[Override]
    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->hub->getFactory();
    }

    #[Override]
    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->hub->getProtocolVersion();
    }

    #[Override]
    public function getCookieName(): string
    {
        return $this->hub->getCookieName();
    }

    private static function updateOf(string $line): Update
    {
        $recorded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $topics = is_array($recorded) ? $recorded['topics'] ?? null : null;

        if (!is_array($topics) || $topics !== array_values(array_filter($topics, is_string(...))) || !is_string($recorded['data'] ?? null) || !is_bool($recorded['private'] ?? null)) {
            throw new UnexpectedValueException('not a recorded update: '.$line);
        }

        return new Update($topics, $recorded['data'], $recorded['private']);
    }
}
