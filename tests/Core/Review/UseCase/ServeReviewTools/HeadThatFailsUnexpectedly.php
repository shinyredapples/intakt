<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use InvalidArgumentException;

// A stream wrapper whose stat fails with the SPL root the old catch of the head took, so a round whose head lies under it shows that failure passes through.
final class HeadThatFailsUnexpectedly
{
    public const string SCHEME = 'intakt-unexpected-head';

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path): array|false
    {
        throw new InvalidArgumentException(sprintf('The filesystem cannot answer for "%s".', $path));
    }
}
