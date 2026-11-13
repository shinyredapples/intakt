<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

// `gh` takes its token from GH_TOKEN or GITHUB_TOKEN in the environment, so no credential passes through this class.
final readonly class GhCliApi implements GitHubApi
{
    private const string REPOSITORY = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';
    private const array TOKENS = ['GH_TOKEN', 'GITHUB_TOKEN'];
    private const int SECONDS = 120;

    public function __construct(private string $repository)
    {
    }

    public function pages(JourneyResource $resource): string
    {
        if (1 !== preg_match(self::REPOSITORY, $this->repository)) {
            throw new JourneyInputFailed('GITHUB_REPOSITORY must name the repository as <owner>/<name>');
        }

        if (array_all(self::TOKENS, static fn (string $name): bool => '' === (string) getenv($name))) {
            throw new JourneyInputFailed('GH_TOKEN or GITHUB_TOKEN must hold the token, so gh never falls back to a stored login');
        }

        $process = new Process(['gh', 'api', $resource->path($this->repository), '--paginate', '--slurp'], null, null, null, self::SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new JourneyInputFailed('gh api '.$resource->value.' timed out after '.self::SECONDS.' seconds');
        }

        return $process->isSuccessful() ? $process->getOutput() : throw new JourneyInputFailed('gh api '.$resource->value.' failed: '.trim($process->getErrorOutput()));
    }
}
