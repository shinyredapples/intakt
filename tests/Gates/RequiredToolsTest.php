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

    private string $root;

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        putenv('INTAKT_GITLEAKS');
        $this->root = sys_get_temp_dir().'/intakt-required-tools-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->path ? 'PATH' : 'PATH='.$this->path);
        putenv('INTAKT_GITLEAKS');
        if (is_file($this->root.'/scanner')) {
            unlink($this->root.'/scanner');
        }

        rmdir($this->root);
    }

    public function testEveryMissingToolOfTheSelectedGatesIsListedOnce(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = $this->tools()->missingFor(['audit', 'tests', 'secrets', 'secrets-history']);

        self::assertSame(
            array_map(RequiredTools::notFound(...), ['git', 'composer', 'sh', 'unshare', 'setsid', 'true', 'bash', 'jq', 'gitleaks', 'env']),
            $missing,
        );
    }

    public function testTheTestsGateNeedsBashAndJqForTheShowcaseAndReleaseLabelsScripts(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = $this->tools()->missingFor(['tests']);

        self::assertContains(RequiredTools::notFound('bash'), $missing);
        self::assertContains(RequiredTools::notFound('jq'), $missing);
    }

    public function testAToolOfAGateThatIsNotSelectedIsNotRequired(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        self::assertSame([RequiredTools::notFound('git')], $this->tools()->missingFor(['cs']));
    }

    public function testTheMessageNamesTheCommandAndTheRequirementsPage(): void
    {
        self::assertSame('gitleaks not found, see docs/architecture/requirements.md', RequiredTools::notFound('gitleaks'));
    }

    public function testAScannerNamedByTheOverrideIsLookedUpInsteadOfTheOneOnThePath(): void
    {
        putenv('INTAKT_GITLEAKS=/nonexistent/scanner');

        self::assertSame([RequiredTools::notFound('/nonexistent/scanner')], $this->tools()->missingFor(['secrets']));
    }

    public function testAScannerOverrideNamingAnotherRequiredToolIsListedOnce(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');
        putenv('INTAKT_GITLEAKS=env');

        $missing = $this->tools()->missingFor(['secrets', 'secrets-history']);

        self::assertSame(array_map(RequiredTools::notFound(...), ['git', 'env']), $missing);
    }

    public function testARelativeScannerPathIsFoundInTheWorkspaceRoot(): void
    {
        file_put_contents($this->root.'/scanner', "#!/bin/sh\n");
        chmod($this->root.'/scanner', 0o700);
        putenv('INTAKT_GITLEAKS=./scanner');

        self::assertSame([], $this->tools()->missingFor(['secrets']));
    }

    public function testARelativeScannerPathMissingFromTheWorkspaceRootIsListed(): void
    {
        putenv('INTAKT_GITLEAKS=./scanner');

        self::assertSame([RequiredTools::notFound('./scanner')], $this->tools()->missingFor(['secrets']));
    }

    public function testToolsOnThePathAreFound(): void
    {
        self::assertSame([], $this->tools()->missingFor(['cs']));
    }

    private function tools(): RequiredTools
    {
        return new RequiredTools($this->root);
    }
}
