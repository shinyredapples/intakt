<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// A pull request's analyser caches must never reach main's scope: PHPStan's result cache is PHP that a later run includes.
#[CoversNothing]
final class GatesCacheWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    public function testTheCheckJobRestoresTheGatesCacheWithMainsLatestAsTheFallback(): void
    {
        $restore = $this->step('Restore the gates cache');

        self::assertStringStartsWith('actions/cache/restore@', $restore['uses'] ?? '');
        self::assertSame('${{ runner.temp }}/gates-cache', $restore['with']['path'] ?? null);
        self::assertSame('${{ steps.gates-cache-name.outputs.name }}', $restore['with']['key'] ?? null);
        self::assertStringContainsString('${{ github.ref_name }}', $restore['with']['restore-keys'] ?? '');
        self::assertStringContainsString('gates-${{ runner.os }}-main-', $restore['with']['restore-keys'] ?? '');
    }

    public function testTheGatesCacheKeyHashesTheLockAndTheGatesConfiguration(): void
    {
        $name = $this->step('Name the gates cache');

        foreach (['composer.lock', 'config/gates/*'] as $file) {
            self::assertStringContainsString("'".$file."'", $name['env']['CONFIG_HASH'] ?? '');
        }
        self::assertStringContainsString('$GITHUB_REF_NAME-$CONFIG_HASH-$GITHUB_SHA', $name['run'] ?? '');
    }

    public function testOnlyAPushToMainSavesTheGatesCache(): void
    {
        $save = $this->step('Save the gates cache');

        self::assertSame("github.event_name == 'push' && github.ref == 'refs/heads/main'", $save['if'] ?? null);
        self::assertStringStartsWith('actions/cache/save@', $save['uses'] ?? '');
        self::assertSame('${{ steps.gates-cache-name.outputs.name }}', $save['with']['key'] ?? null);
    }

    public function testTheGatesContainerGetsTheRestoredDirectoryAsItsCache(): void
    {
        $run = $this->step('Run the gates')['run'] ?? '';

        self::assertStringContainsString('--volume "$gates_cache:/gates-cache" --env INTAKT_GATES_CACHE=/gates-cache', $run);
        self::assertStringContainsString('gates_cache="$RUNNER_TEMP/gates-cache"', $run);
    }

    public function testNoSecretIsReadInTheGatesCacheSteps(): void
    {
        foreach (['Name the gates cache', 'Restore the gates cache', 'Save the gates cache'] as $name) {
            self::assertStringNotContainsString('secrets.', json_encode($this->step($name), JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return array{uses?: string, if?: string, run?: string, with?: array<string, string>, env?: array<string, string>}
     */
    private function step(string $name): array
    {
        /** @var array{jobs: array{check: array{steps: list<array{name?: string, uses?: string, if?: string, run?: string, with?: array<string, string>, env?: array<string, string>}>}}} $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);
        $step = array_find($workflow['jobs']['check']['steps'], static fn (array $step): bool => $name === ($step['name'] ?? null));
        self::assertIsArray($step, 'The check job of ci.yml has no step named '.$name.'.');

        return $step;
    }
}
