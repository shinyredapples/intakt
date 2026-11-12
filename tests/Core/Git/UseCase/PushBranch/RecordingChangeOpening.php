<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
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
    public function openOrUpdate(ProposedChange $change): ChangeId
    {
        ++$this->calls;

        if ($this->during instanceof Closure) {
            ($this->during)();
        }

        if (!isset($this->pulls[$change->branch])) {
            ++$this->opened;
            $this->pulls[$change->branch] = new ChangeId('https://github.com/acme/widgets/pull/'.$this->opened);
        }

        return $this->pulls[$change->branch];
    }
}
