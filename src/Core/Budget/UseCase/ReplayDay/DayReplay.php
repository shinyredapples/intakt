<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartGuard;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built for one replay, because its stores and guard run on the replay's own clock instead of the install's.
#[Exclude]
final readonly class DayReplay
{
    public function __construct(
        private MeasuredUse $measured,
        private Holds $holds,
        private StartGuard $guard,
    ) {
    }

    /**
     * @throws ScriptLineNotUnderstood
     */
    public function play(ScriptedStep $step): string
    {
        return match ($step->words[0] ?? '') {
            'read' => $this->read($step),
            'limit' => $this->limit($step),
            'hold' => $this->hold($step),
            'start' => $this->start($step),
            'merge' => $this->merge($step),
            default => throw ScriptLineNotUnderstood::because($step->line, 'a step is read, limit, hold, start or merge'),
        };
    }

    private function read(ScriptedStep $step): string
    {
        [$account, $window, $percent, $resetsAt] = $this->arguments($step, 4);
        $usageWindow = UsageWindow::tryFrom($window) ?? throw ScriptLineNotUnderstood::because($step->line, 'a window is one of '.implode(', ', array_column(UsageWindow::cases(), 'value')));
        if (!is_numeric($percent) || (float) $percent < 0) {
            throw ScriptLineNotUnderstood::because($step->line, 'a used percent is a number of zero or more');
        }

        $usage = new Usage((float) $percent, ScriptedStep::momentOf($resetsAt, $step->line));

        return $this->measured->record(new WindowReading($this->accountOf($step, $account), $usageWindow, $usage))->value;
    }

    /**
     * @return list<string>
     */
    private function arguments(ScriptedStep $step, int $count): array
    {
        $arguments = array_slice($step->words, 1);
        if (count($arguments) !== $count) {
            throw ScriptLineNotUnderstood::because($step->line, sprintf('%s takes %d arguments', $step->words[0], $count));
        }

        return $arguments;
    }

    private function limit(ScriptedStep $step): string
    {
        [$account, $resetsAt] = $this->arguments($step, 2);

        return $this->holds->coolDown($this->accountOf($step, $account), ScriptedStep::momentOf($resetsAt, $step->line))->value;
    }

    private function hold(ScriptedStep $step): string
    {
        [$hold, $until] = $this->arguments($step, 2);
        $sharedHold = SharedHold::tryFrom($hold) ?? throw ScriptLineNotUnderstood::because($step->line, 'a hold is one of '.implode(', ', array_column(SharedHold::cases(), 'value')));
        $this->holds->hold($sharedHold, ScriptedStep::momentOf($until, $step->line));

        return 'held';
    }

    private function start(ScriptedStep $step): string
    {
        [$account, $running] = $this->arguments($step, 2);
        if (!ctype_digit($running)) {
            throw ScriptLineNotUnderstood::because($step->line, 'the calls running are a whole number of zero or more');
        }

        return $this->guard->decide($this->accountOf($step, $account), (int) $running)->value;
    }

    private function merge(ScriptedStep $step): string
    {
        $this->arguments($step, 0);

        return $this->guard->decideMerge()->value;
    }

    private function accountOf(ScriptedStep $step, string $key): Account
    {
        [$provider, $name] = explode('.', $key, 2) + ['', ''];
        $knownProvider = Provider::tryFrom($provider);
        if (!$knownProvider instanceof Provider || 1 !== preg_match(Account::NAME_PATTERN, $name)) {
            throw ScriptLineNotUnderstood::because($step->line, 'an account is written as provider.name, such as claude_code.main');
        }

        return new Account($knownProvider, $name);
    }
}
