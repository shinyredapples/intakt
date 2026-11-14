<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Override;

// A forge that, like the real one, finds the open pull request of a branch before it opens one, so a repeated call opens nothing new.
final class RecordingChangeOpening implements ChangeOpening
{
    public int $opened = 0;
    public int $calls = 0;
    public ?Closure $during = null;
    public ?ChangeText $text = null;

    /** @var array<string, ChangeId> */
    private array $pulls = [];

    #[Override]
    public function openOrUpdate(ProposedChange $change): ChangeId
    {
        ++$this->calls;
        $this->text = $change->text;

        if ($this->during instanceof Closure) {
            ($this->during)();
        }

        $key = json_encode([$change->project, $change->branch], JSON_THROW_ON_ERROR);

        if (!isset($this->pulls[$key])) {
            ++$this->opened;
            $this->pulls[$key] = new ChangeId(sprintf('https://github.com/acme/%s/pull/%d', $change->project, $this->opened));
        }

        return $this->pulls[$key];
    }
}
