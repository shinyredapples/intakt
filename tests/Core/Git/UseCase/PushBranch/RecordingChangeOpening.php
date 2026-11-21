<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use Override;

// A forge that, like the real one, finds the open pull request of a branch before it opens one, so a repeated call opens nothing new.
final class RecordingChangeOpening implements ChangeOpening
{
    public int $opened = 0;
    public int $calls = 0;
    public int $ownerChecks = 0;
    public bool $openedByAnother = false;
    public ?Closure $during = null;
    public ?ChangeText $text = null;

    /** @var array<string, ChangeId> */
    private array $pulls = [];

    #[Override]
    public function refuseForeignChange(string $project, string $branch): void
    {
        ++$this->ownerChecks;

        if ($this->openedByAnother) {
            throw new ForgeWriteFailed(sprintf('An open pull request of branch %s was not opened by this App, so Intakt leaves it alone.', $branch));
        }
    }

    #[Override]
    public function openOrUpdate(ProposedChange $change): ChangeId
    {
        ++$this->calls;

        if ($this->during instanceof Closure) {
            ($this->during)();
        }

        // After the hook, so a forge the hook takes down holds the text it held before.
        $this->text = $change->text;
        $key = json_encode([$change->project, $change->branch], JSON_THROW_ON_ERROR);

        if (!isset($this->pulls[$key])) {
            ++$this->opened;
            $this->pulls[$key] = new ChangeId(sprintf('https://github.com/acme/%s/pull/%d', $change->project, $this->opened));
        }

        return $this->pulls[$key];
    }
}
