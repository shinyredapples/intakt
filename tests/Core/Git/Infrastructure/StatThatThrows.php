<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Infrastructure;

use InvalidArgumentException;

// A stream wrapper whose stat throws, because the git calls take a folder from no collaborator a test could stub, and a check of a folder is the first thing they do.
final class StatThatThrows
{
    public const string PROTOCOL = 'intakt-stat-throws';

    /** @var resource|null */
    public $context;

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(): array|false
    {
        throw new InvalidArgumentException('unexpected');
    }
}
