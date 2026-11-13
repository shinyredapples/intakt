<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage;

// Made-up commit names, one per release the tests lock.
final readonly class LockedCommits
{
    public const string CONSOLE_8_0_0 = '1111111111111111111111111111111111111111';

    public const string CONSOLE_8_1_0 = '2222222222222222222222222222222222222222';

    public const string UID_8_0_0 = '3333333333333333333333333333333333333333';

    public const string FORK = '4444444444444444444444444444444444444444';

    public const string OTHER = '5555555555555555555555555555555555555555';

    // The upstream tags of the releases above.
    public static function upstream(): RecordedUpstreamTags
    {
        return new RecordedUpstreamTags([
            'https://github.com/symfony/console.git v8.0.0' => self::CONSOLE_8_0_0,
            'https://github.com/symfony/console.git v8.1.0' => self::CONSOLE_8_1_0,
            'https://github.com/symfony/uid.git v8.0.0' => self::UID_8_0_0,
            'https://github.com/doctrine/DoctrineBundle.git 3.0.0' => self::OTHER,
            'https://github.com/symfony/ai-anthropic-platform.git v1.0.0-BETA1' => self::OTHER,
            'https://github.com/acme/tool.git 1.0.0' => self::OTHER,
        ]);
    }
}
