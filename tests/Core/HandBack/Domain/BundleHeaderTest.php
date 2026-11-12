<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\BundleHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BundleHeader::class)]
final class BundleHeaderTest extends TestCase
{
    private const string ONE = '1111111111111111111111111111111111111111';

    private const string TWO = '2222222222222222222222222222222222222222';

    private const string LONG = '3333333333333333333333333333333333333333333333333333333333333333';

    public function testAVersionTwoHeaderGivesItsPrerequisitesAndReferences(): void
    {
        $header = BundleHeader::parse("# v2 git bundle\n-".self::ONE." Start\n-".self::TWO."\n".self::TWO.' refs/heads/task');

        self::assertNotNull($header);
        self::assertSame([self::ONE, self::TWO], $header->prerequisites);
        self::assertSame(['refs/heads/task' => self::TWO], $header->references);
    }

    public function testAVersionThreeHeaderWithSha256IdsIsRead(): void
    {
        $header = BundleHeader::parse("# v3 git bundle\n@object-format=sha256\n".self::LONG.' refs/heads/task');

        self::assertNotNull($header);
        self::assertSame(['refs/heads/task' => self::LONG], $header->references);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'no signature' => [self::ONE.' refs/heads/task'];
        yield 'an unknown version' => ["# v4 git bundle\n".self::ONE.' refs/heads/task'];
        yield 'a capability in version two' => ["# v2 git bundle\n@object-format=sha1\n".self::ONE.' refs/heads/task'];
        yield 'a filter capability' => ["# v3 git bundle\n@filter=blob:none\n".self::ONE.' refs/heads/task'];
        yield 'a SHA-256 id in a SHA-1 bundle' => ["# v2 git bundle\n".self::LONG.' refs/heads/task'];
        yield 'a SHA-1 id in a SHA-256 bundle' => ["# v3 git bundle\n@object-format=sha256\n".self::ONE.' refs/heads/task'];
        yield 'an uppercase id' => ["# v2 git bundle\n".strtoupper(str_repeat('a', 40)).' refs/heads/task'];
        yield 'a short prerequisite' => ["# v2 git bundle\n-1111111 Start\n".self::ONE.' refs/heads/task'];
        yield 'a reference without a name' => ["# v2 git bundle\n".self::ONE.' '];
        yield 'a name with a space' => ["# v2 git bundle\n".self::ONE.' refs/heads/a b'];
        yield 'the same reference twice' => ["# v2 git bundle\n".self::ONE." refs/heads/task\n".self::TWO.' refs/heads/task'];
        yield 'an option as a prerequisite' => ["# v2 git bundle\n---upload-pack=x\n".self::ONE.' refs/heads/task'];
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedHeaderIsNoBundle(string $header): void
    {
        self::assertNull(BundleHeader::parse($header));
    }
}
