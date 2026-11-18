<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\GitHubConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(GitHubConfiguration::class)]
final class GitHubConfigurationTest extends TestCase
{
    public function testTheReserveIsTwentyPercentWithoutAGithubSection(): void
    {
        self::assertSame(20, $this->reserveOf([]));
    }

    public function testTheReserveIsTwentyPercentWhenTheGithubSectionLeavesItOut(): void
    {
        self::assertSame(20, $this->reserveOf(['github' => []]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sharesAtTheBounds(): iterable
    {
        yield 'no reserve' => [0];
        yield 'the whole hour' => [100];
    }

    #[DataProvider('sharesAtTheBounds')]
    public function testAShareAtABoundIsTheReserve(int $share): void
    {
        self::assertSame($share, $this->reserveOf(['github' => ['reserve_percent' => $share]]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidShares(): iterable
    {
        yield 'above 100' => [101];
        yield 'below 0' => [-1];
        yield 'a fraction' => [20.5];
        yield 'a string' => ['20'];
        yield 'null' => [null];
    }

    #[DataProvider('invalidShares')]
    public function testAnInvalidReserveIsRefusedWithTheKeysPath(mixed $share): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/path "intakt\.github\.reserve_percent"/');

        $this->reserveOf(['github' => ['reserve_percent' => $share]]);
    }

    public function testAnUnknownKeyInTheGithubSectionIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "reserve_percnt" under "intakt\.github"/');

        $this->reserveOf(['github' => ['reserve_percnt' => 30]]);
    }

    /**
     * @param array<string, mixed> $contents
     */
    private function reserveOf(array $contents): mixed
    {
        /** @var array{github: array{reserve_percent: int}} $configuration */
        $configuration = new Processor()->processConfiguration(new GitHubConfiguration(), [$contents]);

        return $configuration['github']['reserve_percent'];
    }
}
