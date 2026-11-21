<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InstallGhTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/install-gh.sh';

    private const string FINGERPRINT = '7F38BBB59D064DBCB3D84D725612B36462313325';

    // Fakes, because the real install needs root and the network; the log lets a test compare the exact calls in order.
    private const array FAKES = [
        'curl' => '#!/bin/sh'."\n".'echo "curl $*" >> "$CALLS"',
        'gpg' => '#!/bin/sh'."\n".'echo "gpg $*" >> "$CALLS"'."\n".'if [ "$1" = --export ] && [ -n "$HAS_KEY" ]; then echo key; fi',
        'dpkg' => '#!/bin/sh'."\n".'echo amd64',
        'sudo' => '#!/bin/sh'."\n".'echo "sudo $*" >> "$CALLS"'."\n".'if [ "$1" = tee ]; then cat > /dev/null; fi'."\n"
            .'if [ "$1 $2" = "apt-get install" ]; then printf "#!/bin/sh\necho \"gh version 2.83.0 (2025-11-04)\"\n" > "$BIN/gh"; chmod +x "$BIN/gh"; fi',
    ];

    private const array INSTALL = [
        'curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o',
        'gpg --quiet --import',
        'gpg --export '.self::FINGERPRINT,
        'sudo install -D -m 644',
        'sudo tee /etc/apt/sources.list.d/github-cli.list',
        'sudo apt-get update -qq',
        'sudo apt-get install -y -qq gh',
    ];

    public function testARecentGhIsKept(): void
    {
        $sandbox = $this->sandbox('2.48.0');

        $result = $this->runScript($sandbox, true);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame([], $this->calls($sandbox));
    }

    public function testAnOlderGhIsReplacedFromThePinnedRepository(): void
    {
        $sandbox = $this->sandbox('2.45.0');

        $result = $this->runScript($sandbox, true);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame(self::INSTALL, $this->callPrefixes($sandbox));
        self::assertStringContainsString('gh version 2.83.0', $result->output);
    }

    public function testAMissingGhIsInstalledAndTheScratchFolderRemoved(): void
    {
        $sandbox = $this->sandbox(null);

        $result = $this->runScript($sandbox, true);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame(self::INSTALL, $this->callPrefixes($sandbox));
        self::assertSame([], array_values(array_diff((array) scandir($sandbox->root.'/tmp'), ['.', '..'])));
    }

    public function testADownloadWithoutThePinnedKeyStopsBeforeAnyPrivilegedCommand(): void
    {
        $sandbox = $this->sandbox(null);

        $result = $this->runScript($sandbox, false);

        self::assertFalse($result->succeeded());
        self::assertStringContainsString('lacks the key '.self::FINGERPRINT, $result->errors);
        self::assertSame([], array_values(array_filter($this->calls($sandbox), static fn (string $call): bool => str_starts_with($call, 'sudo '))));
        self::assertSame([], array_values(array_diff((array) scandir($sandbox->root.'/tmp'), ['.', '..'])));
    }

    private function sandbox(?string $ghVersion): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('calls', '');

        foreach (self::FAKES as $name => $fake) {
            $sandbox->write('bin/'.$name, $fake."\n");
        }

        if (null !== $ghVersion) {
            $sandbox->write('bin/gh', '#!/bin/sh'."\n".'echo "gh version '.$ghVersion.' (2024-01-01)"'."\n");
        }

        return $sandbox;
    }

    // Only the fakes and the few real tools the script needs are on PATH, so a gh installed on this machine stays out.
    private function runScript(Sandbox $sandbox, bool $hasKey): ProcessResult
    {
        $environment = ['CALLS' => $sandbox->root.'/calls', 'BIN' => $sandbox->root.'/bin', 'TMPDIR' => $sandbox->root.'/tmp', 'HAS_KEY' => $hasKey ? '1' : ''];

        return $sandbox->runScript(
            'chmod +x bin/*; mkdir tmp tools; for tool in sed mktemp rm cat chmod; do ln -s "$(command -v "$tool")" "tools/$tool"; done; '
            .'PATH="$PWD/bin:$PWD/tools" /bin/sh '.escapeshellarg(self::SCRIPT),
            $environment,
        );
    }

    /**
     * @return list<string>
     */
    private function calls(Sandbox $sandbox): array
    {
        $calls = file_get_contents($sandbox->root.'/calls');

        return false === $calls || '' === $calls ? [] : explode("\n", rtrim($calls, "\n"));
    }

    /**
     * @return list<string>
     */
    private function callPrefixes(Sandbox $sandbox): array
    {
        return array_map(
            static fn (string $call): string => array_find(self::INSTALL, static fn (string $prefix): bool => str_starts_with($call, $prefix)) ?? $call,
            $this->calls($sandbox),
        );
    }
}
