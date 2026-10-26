<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class SuppressionDirective
{
    private const string PATTERN = '/@(phpstan|psalm|rector|php-cs-fixer)-?(ignore|suppress|disable)|@(noRector|noinspection|codingStandardsIgnore|SuppressWarnings|codeCoverageIgnore|infection-ignore)|(phpcs|php-cs-fixer|rector):\s*(ignore|disable)|gitleaks\x3aallow/i';

    public static function appearsIn(string $comment): bool
    {
        return 1 === preg_match(self::PATTERN, $comment);
    }
}
