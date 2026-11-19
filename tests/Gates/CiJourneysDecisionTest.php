<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the journeys job's decision step of ci.yml and the load step of the CI image action with a fake git and docker.
 */
#[CoversNothing]
final class CiJourneysDecisionTest extends SandboxTestCase
{
    // The fake git answers the merge base and exits from git diff with the status in diff-status, recording its arguments.
    private const string FAKE_GIT = <<<'SH'
        #!/bin/sh
        case "$1" in
          merge-base) echo base ;;
          diff) echo "$@" > diff-arguments; exit "$(cat diff-status)" ;;
          *) exit 2 ;;
        esac
        SH;

    // The fake docker has the image when image-present exists; loading the tar adds it only when the tar holds the tag.
    private const string FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        case "$1" in
          load) if [ -e tar-has-tag ]; then touch image-present; fi ;;
          image) [ "$3" = intakt-ci:abc ] && [ -e image-present ] ;;
          *) exit 2 ;;
        esac
        SH;

    /** @return iterable<string, array{string, string, int, string}> */
    public static function decisions(): iterable
    {
        yield 'a push to main' => ['push', 'no', 0, 'yes'];
        yield 'a pull request that needs the app image' => ['pull_request', 'yes', 0, 'yes'];
        yield 'a docs-only pull request' => ['pull_request', 'no', 0, 'no'];
        yield 'a pull request that touches the journeys\' test code' => ['pull_request', 'no', 1, 'yes'];
    }

    #[DataProvider('decisions')]
    public function testTheJourneysRunOnAPushOrWhenTheirInputsChanged(string $event, string $imageNeeded, int $diffStatus, string $expected): void
    {
        $sandbox = $this->decisionSandbox($imageNeeded, $diffStatus);

        $result = $this->decide($sandbox, $event);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame("needed={$expected}\n", file_get_contents($sandbox->root.'/output'));
    }

    public function testTheDiffCoversTheJourneysAndTheTestCodeTheyLoad(): void
    {
        $sandbox = $this->decisionSandbox('no', 0);

        $this->decide($sandbox, 'pull_request');

        self::assertSame(
            "diff --quiet base head -- tests/Journeys tests/Support tests/Core/Watcher/RecordedPullRequests.php\n",
            file_get_contents($sandbox->root.'/diff-arguments'),
        );
    }

    public function testAGitErrorFailsTheStep(): void
    {
        $sandbox = $this->decisionSandbox('no', 128);

        $result = $this->decide($sandbox, 'pull_request');

        self::assertFalse($result->succeeded());
        self::assertFileDoesNotExist($sandbox->root.'/output');
    }

    /** @return iterable<string, array{string, bool, bool, bool}> */
    public static function images(): iterable
    {
        yield 'the image on the runner' => ['present', true, true, true];
        yield 'a cache tar with the image\'s tag' => ['tar with tag', true, true, true];
        yield 'a cache tar without the image\'s tag' => ['tar without tag', true, false, false];
        yield 'no image and no cache tar' => ['nothing', true, false, false];
        yield 'no image after a good login' => ['nothing', false, true, true];
    }

    #[DataProvider('images')]
    public function testAfterAFailedLoginOnlyAnImageAlreadyThereIsBuiltOn(string $image, bool $loginFailed, bool $succeeds, bool $built): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        $sandbox->write('.github/ci/build-image.sh', "#!/bin/sh\ntouch built\n");
        $sandbox->write('tmp/.keep', '');
        if ('present' === $image) {
            $sandbox->write('image-present', '');
        }
        if (str_starts_with($image, 'tar')) {
            $sandbox->write('tmp/intakt-ci-image.tar', '');
        }
        if ('tar with tag' === $image) {
            $sandbox->write('tar-has-tag', '');
        }

        $result = $sandbox->runScript(
            'chmod +x bin/docker .github/ci/build-image.sh; PATH="$PWD/bin:$PATH"; '.$this->loadStep(),
            ['KEY' => 'intakt-ci-image-abc', 'RUNNER_TEMP' => $sandbox->root.'/tmp', 'DOCKERHUB_LOGIN_FAILED' => $loginFailed ? '1' : ''],
        );

        self::assertSame($succeeds, $result->succeeded(), $result->errors.$result->output);
        self::assertSame($built, is_file($sandbox->root.'/built'));
        self::assertFileDoesNotExist($sandbox->root.'/tmp/intakt-ci-image.tar');
    }

    private function decisionSandbox(string $imageNeeded, int $diffStatus): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/git', self::FAKE_GIT);
        $sandbox->write('.github/ci/image-needed.sh', "#!/bin/sh\necho {$imageNeeded}\n");
        $sandbox->write('diff-status', (string) $diffStatus);

        return $sandbox;
    }

    private function decide(Sandbox $sandbox, string $event): ProcessResult
    {
        /** @var array{jobs: array{journeys: array{steps: list<array{id?: string, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/ci.yml');
        $steps = array_values(array_filter($workflow['jobs']['journeys']['steps'], static fn (array $step): bool => 'journeys' === ($step['id'] ?? null)));
        self::assertCount(1, $steps);

        return $sandbox->runScript(
            'chmod +x bin/git .github/ci/image-needed.sh; PATH="$PWD/bin:$PATH"; '.($steps[0]['run'] ?? ''),
            ['EVENT' => $event, 'BASE_REF' => 'main', 'HEAD_SHA' => 'head', 'GITHUB_OUTPUT' => $sandbox->root.'/output'],
        );
    }

    private function loadStep(): string
    {
        /** @var array{runs: array{steps: list<array{run?: string}>}} $action */
        $action = Yaml::parseFile(__DIR__.'/../../.github/actions/ci-image/action.yml');
        $steps = array_values(array_filter($action['runs']['steps'], static fn (array $step): bool => str_contains($step['run'] ?? '', 'docker load')));
        self::assertCount(1, $steps);

        return $steps[0]['run'] ?? '';
    }
}
