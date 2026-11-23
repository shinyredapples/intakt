<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// A layer a pull request could write to the cache would end up in the image a later job on main runs, and the builder runs privileged.
#[CoversNothing]
final class CiImageLayerCacheTest extends TestCase
{
    private const string ACTION = __DIR__.'/../../.github/actions/ci-image/action.yml';

    private const string BUILD_IMAGE = __DIR__.'/../../.github/ci/build-image.sh';

    private const string MISSING_IMAGE = "steps.name.outputs.present != 'true' && steps.restore.outputs.cache-hit != 'true'";

    public function testTheBuilderImageIsPinnedByTagAndDigest(): void
    {
        $driverOptions = $this->step('buildx')['with']['driver-opts'] ?? null;

        self::assertIsString($driverOptions);
        self::assertMatchesRegularExpression('#^image=moby/buildkit:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$#', $driverOptions);
    }

    public function testTheBuildxActionsArePinnedByCommit(): void
    {
        self::assertMatchesRegularExpression('#^docker/setup-buildx-action@[0-9a-f]{40}$#', $this->step('buildx')['uses'] ?? '');
        self::assertMatchesRegularExpression('#^docker/build-push-action@[0-9a-f]{40}$#', $this->step('build')['uses'] ?? '');
    }

    public function testBuildxRunsOnlyWhenTheImageIsMissingAndTheDockerHubLoginDidNotFail(): void
    {
        self::assertSame(self::MISSING_IMAGE." && env.DOCKERHUB_LOGIN_FAILED != '1'", $this->step('buildx')['if'] ?? null);
        self::assertSame("steps.buildx.outcome == 'success'", $this->step('build')['if'] ?? null);
    }

    public function testOnlyARunOnMainWritesTheLayerCacheUnderAFixedScopeBestEffort(): void
    {
        $with = $this->step('build')['with'] ?? [];

        self::assertSame('type=gha,scope=intakt-ci-image', $with['cache-from'] ?? null);
        self::assertSame("\${{ github.event_name != 'pull_request' && github.ref == 'refs/heads/main' && 'type=gha,scope=intakt-ci-image,mode=max,ignore-error=true' || '' }}", $with['cache-to'] ?? null);
    }

    public function testTheBuildGetsNoSecretTokenOrBuildArgument(): void
    {
        $build = $this->step('build');

        self::assertSame(['context', 'file', 'tags', 'load', 'push', 'cache-from', 'cache-to'], array_keys($build['with'] ?? []));
        self::assertSame(['DOCKER_BUILD_RECORD_UPLOAD' => 'false', 'DOCKER_BUILD_SUMMARY' => 'false'], $build['env'] ?? null);
        foreach (['buildx', 'build'] as $id) {
            $step = json_encode($this->step($id), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secrets.', $step);
            self::assertStringNotContainsString('github.token', $step);
        }
    }

    public function testTheBuiltImageCarriesTheTagBuildImageShLooksFor(): void
    {
        $with = $this->step('build')['with'] ?? [];
        $script = (string) file_get_contents(self::BUILD_IMAGE);

        self::assertSame(['.', '.github/ci/Dockerfile', 'intakt-ci:${{ steps.name.outputs.hash }}', true, false], [$with['context'] ?? null, $with['file'] ?? null, $with['tags'] ?? null, $with['load'] ?? null, $with['push'] ?? null]);
        self::assertStringContainsString('echo "hash=$hash" >> "$GITHUB_OUTPUT"', $this->step('name')['run'] ?? '');
        self::assertStringContainsString('image="intakt-ci:$hash"', $script);
        self::assertStringContainsString('--file .github/ci/Dockerfile .', $script);
    }

    public function testABuildOrLoadFailureStillFailsTheJob(): void
    {
        self::assertStringNotContainsString('continue-on-error', (string) file_get_contents(self::ACTION));
    }

    /**
     * @return array{id?: string, uses?: string, if?: string, run?: string, with?: array<string, bool|string>, env?: array<string, string>}
     */
    private function step(string $id): array
    {
        $step = array_find($this->steps(), static fn (array $step): bool => $id === ($step['id'] ?? null));
        self::assertIsArray($step, 'The CI image action has no step with the id '.$id.'.');

        return $step;
    }

    /**
     * @return list<array{id?: string, uses?: string, if?: string, run?: string, with?: array<string, bool|string>, env?: array<string, string>}>
     */
    private function steps(): array
    {
        /** @var array{runs: array{steps: list<array{id?: string, uses?: string, if?: string, run?: string, with?: array<string, bool|string>, env?: array<string, string>}>}} $action */
        $action = Yaml::parseFile(self::ACTION);

        return $action['runs']['steps'];
    }
}
