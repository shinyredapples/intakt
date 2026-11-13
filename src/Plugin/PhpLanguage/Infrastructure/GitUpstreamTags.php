<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTagNotRead;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTags;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class GitUpstreamTags implements UpstreamTags
{
    // No repository of its own, because ls-remote needs none, and one found above the working directory would bring its own config.
    private const string NO_REPOSITORY = '--git-dir=/nonexistent';

    // Asked for too, because git lists the commit an annotated tag points to only under this suffix.
    private const string PEELED = '^{}';

    // An empty folder of Intakt's own, because git's repository discovery could read a stray repository in or above the shared temp folder.
    public function __construct(
        private HardenedGit $hardened,
        private NetworkGit $network,
        #[Autowire('%kernel.cache_dir%/upstream-tags')]
        private string $directory,
    ) {
    }

    public function commitOf(string $repositoryUrl, string $tag): ?string
    {
        $ref = 'refs/tags/'.$tag;
        new Filesystem()->mkdir($this->directory);

        try {
            $output = $this->network->run($this->hardened->call([self::NO_REPOSITORY, 'ls-remote', $repositoryUrl, $ref, $ref.self::PEELED], $this->directory));
        } catch (NetworkGitFailed|RepositoryAttributesRefused $failed) {
            throw UpstreamTagNotRead::because($failed->getMessage());
        }

        $commits = [];
        foreach (explode("\n", trim($output)) as $line) {
            [$commit, $name] = explode("\t", $line, 2) + ['', ''];
            $commits[$name] = $commit;
        }

        // Exact names only, because ls-remote also lists a ref that merely ends in the pattern, such as refs/tags/fork/refs/tags/v1.0.
        return $commits[$ref.self::PEELED] ?? $commits[$ref] ?? null;
    }
}
