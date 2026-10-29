<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RequiredTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequiredTools::class)]
final class RequiredToolsTest extends TestCase
{
    private string|false $path;

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        putenv('INTAKT_GITLEAKS');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->path ? 'PATH' : 'PATH='.$this->path);
        putenv('INTAKT_GITLEAKS');
    }

    public function testEveryMissingToolOfTheSelectedGatesIsListedOnce(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = new RequiredTools()->missingFor(['audit', 'tests', 'secrets', 'secrets-history']);

        self::assertSame(
            array_map(RequiredTools::notFound(...), ['git', 'composer', 'sh', 'unshare', 'setsid', 'true', 'gitleaks', 'env']),
            $missing,
        );
    }

    public function testAToolOfAGateThatIsNotSelectedIsNotRequired(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        self::assertSame([RequiredTools::notFound('git')], new RequiredTools()->missingFor(['cs']));
    }

    public function testTheMessageNamesTheCommandAndTheRequirementsPage(): void
    {
        self::assertSame('gitleaks not found, see docs/architecture/requirements.md', RequiredTools::notFound('gitleaks'));
    }

    public function testAScannerNamedByTheOverrideIsLookedUpInsteadOfTheOneOnThePath(): void
    {
        putenv('INTAKT_GITLEAKS=/nonexistent/scanner');

        self::assertSame([RequiredTools::notFound('/nonexistent/scanner')], new RequiredTools()->missingFor(['secrets']));
    }

    public function testToolsOnThePathAreFound(): void
    {
        self::assertSame([], new RequiredTools()->missingFor(['cs']));
    }
}
