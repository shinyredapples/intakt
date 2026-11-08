<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BuildImageTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/build-image.sh';

    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        if [ "$1" = image ]; then [ -f present ]; exit; fi
        echo "$*" >> built
        BASH;

    public function testTheTagChangesWhenAProfileFileChangesAndOnlyThen(): void
    {
        $sandbox = $this->checkout();

        $first = $this->tag($sandbox);
        $again = $this->tag($sandbox);
        $sandbox->write('composer.json', '{"require": {"ext-intl": "*"}}');
        $afterComposer = $this->tag($sandbox);
        $sandbox->write('config/gates/php/check.ini', 'zend.assertions = -1');

        self::assertSame($first, $again);
        self::assertNotSame($first, $afterComposer);
        self::assertNotSame($afterComposer, $this->tag($sandbox));
    }

    public function testAnExistingTagIsReusedWithoutABuild(): void
    {
        $sandbox = $this->checkout();
        $sandbox->write('present', '');

        $this->tag($sandbox);

        self::assertFileDoesNotExist($sandbox->root.'/built');
    }

    public function testAMissingTagIsBuiltFromTheCiDockerfileWithTheCheckoutAsContext(): void
    {
        $sandbox = $this->checkout();

        $tag = $this->tag($sandbox);

        self::assertStringEqualsFile($sandbox->root.'/built', "build --tag {$tag} --file .github/ci/Dockerfile .\n");
    }

    private function checkout(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        chmod($sandbox->root.'/bin/docker', 0o755);
        $sandbox->write('.github/ci/Dockerfile', 'FROM scratch');
        $sandbox->write('composer.json', '{}');
        $sandbox->write('config/gates/php/check.ini', 'zend.assertions = 1');

        return $sandbox;
    }

    private function tag(Sandbox $sandbox): string
    {
        $sandbox->write('env', '');
        $result = $sandbox->runScript('PATH="$PWD/bin:$PATH" '.escapeshellarg(self::SCRIPT), ['GITHUB_ENV' => $sandbox->root.'/env']);
        self::assertTrue($result->succeeded(), $result->output.$result->errors);
        self::assertMatchesRegularExpression('/^CI_IMAGE=(intakt-ci:[0-9a-f]{32})\n$/', (string) file_get_contents($sandbox->root.'/env'));

        return substr(trim((string) file_get_contents($sandbox->root.'/env')), 9);
    }
}
