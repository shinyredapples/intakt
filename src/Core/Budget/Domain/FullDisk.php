<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Doctrine\DBAL\Exception;
use Symfony\Component\Filesystem\Exception\IOException;
use Throwable;

// Only the filesystem and the database speak with the system's own text, because any other message can carry pull request text that an author could use to start the hold on purpose.
final readonly class FullDisk
{
    private const array SYSTEM_TEXTS = ['no space left on device', 'database or disk is full'];

    public static function causes(Throwable $failure): bool
    {
        for ($link = $failure; $link instanceof Throwable; $link = $link->getPrevious()) {
            if (self::speaksForTheSystem($link) && self::saysFull($link->getMessage())) {
                return true;
            }
        }

        return false;
    }

    private static function speaksForTheSystem(Throwable $link): bool
    {
        return $link instanceof IOException || $link instanceof Exception;
    }

    private static function saysFull(string $message): bool
    {
        $lowered = strtolower($message);

        return array_any(self::SYSTEM_TEXTS, static fn (string $text): bool => str_contains($lowered, $text));
    }
}
