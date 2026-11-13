<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage;

use Intakt\Plugin\PhpLanguage\Domain\UpstreamTagNotRead;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTags;

// Tags as an upstream repository would list them, because no test may reach GitHub.
final class RecordedUpstreamTags implements UpstreamTags
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $commits keyed by repository URL and tag, joined by a space
     */
    public function __construct(
        private readonly array $commits = [],
        private readonly bool $unreachable = false,
    ) {
    }

    public function commitOf(string $repositoryUrl, string $tag): ?string
    {
        $this->asked[] = $repositoryUrl.' '.$tag;

        if ($this->unreachable) {
            throw UpstreamTagNotRead::because('git failed 3 times: could not resolve host');
        }

        return $this->commits[$repositoryUrl.' '.$tag] ?? null;
    }
}
