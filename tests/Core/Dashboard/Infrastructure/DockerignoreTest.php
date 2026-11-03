<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The Dockerfile copies the whole build context, so a runtime env file left in the checkout would land in an image layer.
#[CoversNothing]
final class DockerignoreTest extends TestCase
{
    #[DataProvider('runtimeEnvFiles')]
    public function testTheBuildContextLeavesOutAFileThatHoldsRuntimeSecrets(string $file): void
    {
        $excluded = file(__DIR__.'/../../../../.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertContains($file, $excluded);
    }

    public function testTheBuildContextKeepsTheCommittedEnvDefaults(): void
    {
        $excluded = file(__DIR__.'/../../../../.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertNotContains('.env', $excluded);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runtimeEnvFiles(): iterable
    {
        yield 'the env file the user guide names' => ['intakt.env'];
        yield 'the local overrides' => ['.env.local'];
        yield 'the compiled env of composer dump-env' => ['.env.local.php'];
    }
}
