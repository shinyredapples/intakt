<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Override;

// A forge that, like the real one, finds the open pull request of a branch before it opens one, so a repeated call opens nothing new.
final class RecordingChangeOpening implements ChangeOpening
{
    public int $opened = 0;
    public int $calls = 0;
    public ?Closure $during = null;

    /** @var array<string, ChangeId> */
    private array $pulls = [];

    #[Override]
    public function openOrUpdate(string $project, string $branch, string $base, ChangeText $text): ChangeId
    {
        ++$this->calls;

        if (null !== $this->during) {
            ($this->during)();
        }

        if (!isset($this->pulls[$branch])) {
            ++$this->opened;
            $this->pulls[$branch] = new ChangeId('https://github.com/acme/widgets/pull/'.$this->opened);
        }

        return $this->pulls[$branch];
    }
}
