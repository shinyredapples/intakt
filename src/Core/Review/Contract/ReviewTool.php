<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\Result\ToolCall;

// The tools a reviewer reads the head with, named once here, because the live log shows each call in plain words and never by its tool name.
enum ReviewTool: string
{
    case ReadFile = 'read_file';
    case ListDirectory = 'list_directory';
    case SearchFiles = 'search_files';

    private const string ROOT = '.';

    // The model writes the arguments, so a value that is not a usable string reads as the call without it instead of failing the update.
    public function callInWords(ToolCall $call): string
    {
        $arguments = $call->getArguments();
        $path = self::textOf($arguments['path'] ?? null);

        return match ($this) {
            self::ReadFile => null === $path ? 'read a file' : 'read '.$path,
            self::ListDirectory => self::isRoot($path) ? 'listed the repository root' : 'listed '.$path,
            self::SearchFiles => self::searchInWords(self::textOf($arguments['text'] ?? null), $path),
        };
    }

    // A trailing slash is trimmed because a model writes the root as `./` as often as `.`.
    private static function isRoot(?string $path): bool
    {
        return null === $path || self::ROOT === rtrim($path, '/');
    }

    public function kindInWords(): string
    {
        return match ($this) {
            self::ReadFile => 'File reads',
            self::ListDirectory => 'Directory listings',
            self::SearchFiles => 'Searches',
        };
    }

    private static function searchInWords(?string $text, ?string $path): string
    {
        $searched = null === $text ? 'searched the files' : sprintf('searched for "%s"', $text);

        return self::isRoot($path) ? $searched : $searched.' in '.$path;
    }

    private static function textOf(mixed $argument): ?string
    {
        return is_string($argument) && '' !== trim($argument) ? trim($argument) : null;
    }
}
