<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\Contract;

use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobEgress::class)]
#[CoversClass(JobRefused::class)]
final class JobEgressTest extends TestCase
{
    public function testNoneHasNoHost(): void
    {
        self::assertTrue(JobEgress::none()->isNone());
        self::assertSame([], [...JobEgress::none()->hosts]);
    }

    public function testAnAllowlistKeepsEachHostOnce(): void
    {
        $egress = JobEgress::to(new EgressHosts('api.vendor.example', 'repo.packagist.org', 'api.vendor.example'));

        self::assertFalse($egress->isNone());
        self::assertSame(['api.vendor.example', 'repo.packagist.org'], [...$egress->hosts]);
    }

    public function testAHostOf253CharactersIsAccepted(): void
    {
        $host = $this->hostOfLength(253);

        self::assertSame([$host], [...JobEgress::to(new EgressHosts($host))->hosts]);
    }

    public function testAnEmptyAllowlistIsRefused(): void
    {
        $this->expectExceptionObject(JobRefused::forEmptyAllowlist());

        JobEgress::to(new EgressHosts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedHosts(): iterable
    {
        yield 'an address' => ['192.0.2.1'];
        yield 'an IPv6 address' => ['[2001:db8::1]'];
        yield 'a wildcard' => ['*.vendor.example'];
        yield 'a leading dot' => ['.vendor.example'];
        yield 'a trailing dot' => ['vendor.example.'];
        yield 'a single label' => ['localhost'];
        yield 'uppercase' => ['API.vendor.example'];
        yield 'a port' => ['api.vendor.example:443'];
        yield 'a second word' => ['api.vendor.example all'];
        yield 'a config line' => ["api.vendor.example\nhttp_access allow all"];
        yield 'a trailing newline' => ["api.vendor.example\n"];
        yield 'a label of 64 characters' => [str_repeat('a', 64).'.example'];
        yield 'a label starting with a dash' => ['-api.vendor.example'];
        yield 'empty' => [''];
    }

    #[DataProvider('refusedHosts')]
    public function testAHostThatIsNotAPlainHostNameIsRefused(string $host): void
    {
        $this->expectExceptionObject(JobRefused::forEgressHost($host));

        JobEgress::to(new EgressHosts('api.vendor.example', $host));
    }

    public function testAHostOf254CharactersIsRefused(): void
    {
        $host = $this->hostOfLength(254);

        $this->expectExceptionObject(JobRefused::forEgressHost($host));

        JobEgress::to(new EgressHosts($host));
    }

    // Three labels of 63 characters and a top-level domain of three letters, so the fourth label alone sets the length.
    private function hostOfLength(int $length): string
    {
        $label = str_repeat('a', 63);

        return implode('.', [$label, $label, $label, str_repeat('b', $length - 196), 'com']);
    }
}
