<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class BuildImageTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/build-image.sh';

    private const string FAKE_DOCKER = <<<'BASH'
        #!/bin/sh
        if [ "$1" = image ]; then [ -f present ]; exit; fi
        echo "$*" >> built
        BASH;

    public function testTheTagChangesWhenAProfileFileChangesAndOnlyThen(): void
    {
        $sandbox = $this->checkout();

        $first = $this->tag($sandbox);
        $again = $this->tag($sandbox);
        $sandbox->write('composer.json', '{"require": {"ext-intl": "*"}}');
        $second = $this->tag($sandbox);
        $sandbox->write('config/gates/php/check.ini', '');

        self::assertSame($first, $again);
        self::assertNotSame($first, $second);
        self::assertNotSame($second, $this->tag($sandbox));
    }

    public function testAnExistingTagIsReusedWithoutABuild(): void
    {
        $sandbox = $this->checkout();
        $sandbox->write('present', '');

        $this->tag($sandbox);

        self::assertFileDoesNotExist($sandbox->root.'/built');
    }

    public function testAMissingTagIsBuiltWithTheCheckoutAsContext(): void
    {
        $sandbox = $this->checkout();

        $tag = $this->tag($sandbox);

        self::assertStringEqualsFile($sandbox->root.'/built', "build --tag {$tag} --file .github/ci/Dockerfile .\n");
    }

    public function testAfterAFailedLoginAMissingTagFailsWithoutABuild(): void
    {
        $sandbox = $this->checkout();
        $sandbox->write('env', '');

        $result = $sandbox->runScript(
            'PATH="$PWD/bin:$PATH" '.escapeshellarg(self::SCRIPT),
            ['GITHUB_ENV' => $sandbox->root.'/env', 'DOCKERHUB_LOGIN_FAILED' => '1'],
        );

        self::assertFalse($result->succeeded());
        self::assertStringContainsString('The Docker Hub login failed', $result->errors);
        self::assertFileDoesNotExist($sandbox->root.'/built');
        self::assertStringEqualsFile($sandbox->root.'/env', '');
    }

    public function testAfterAFailedLoginAnExistingTagIsStillReused(): void
    {
        $sandbox = $this->checkout();
        $sandbox->write('present', '');
        $sandbox->write('env', '');

        $result = $sandbox->runScript(
            'PATH="$PWD/bin:$PATH" '.escapeshellarg(self::SCRIPT),
            ['GITHUB_ENV' => $sandbox->root.'/env', 'DOCKERHUB_LOGIN_FAILED' => '1'],
        );

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertFileDoesNotExist($sandbox->root.'/built');
        self::assertStringStartsWith('CI_IMAGE=intakt-ci:', (string) file_get_contents($sandbox->root.'/env'));
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
        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame(1, preg_match('/^CI_IMAGE=(intakt-ci:[0-9a-f]{32})\n$/', (string) file_get_contents($sandbox->root.'/env'), $tag));

        return $tag[1] ?? self::fail();
    }
}
