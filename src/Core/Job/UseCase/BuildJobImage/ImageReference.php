<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

// Docker's reference grammar in short, so no space or line break can ride along into a FROM line or a build's tag.
final readonly class ImageReference
{
    private const string NAME = '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?::\d+)?(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*';
    private const string TAG = '(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?';
    private const string DIGEST = '(?:@sha256:[a-f0-9]{64})?';

    public static function isImage(string $reference): bool
    {
        return 1 === preg_match('~^'.self::NAME.self::TAG.self::DIGEST.'$~D', $reference);
    }

    public static function isTag(string $reference): bool
    {
        return 1 === preg_match('~^'.self::NAME.self::TAG.'$~D', $reference);
    }
}
