<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\ProviderOutage;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Review\Contract\AwaitedReviewer;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Says what the demo waits on, so a first run never shows a blank terminal (issue #2803), and stops it with a plain message when the model it waits on is rate-limited and no fallback is free, instead of retrying silently for minutes (issue #2802); real projects keep intakt:review, which waits. Asks the tier the same question the worker's next call asks, so the line names the model that answers now, a fallback included. Excluded because the command builds it per run.
#[Exclude]
final class DemoProgress implements ReviewProgress
{
    private const int REPEAT_SECONDS = 30;

    private string $waitingOn = '';

    private ?DateTimeImmutable $since = null;

    private ?DateTimeImmutable $lastSaid = null;

    /**
     * @var list<string>
     */
    private array $blockedSeen = [];

    public function __construct(
        private readonly OutputInterface $output,
        private readonly TierInvoker $tiers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function reached(RoundProgress $progress): void
    {
        if ($progress->judged) {
            return;
        }

        $this->stopWhenBlockedByAWait($progress);

        if ([] === $progress->awaited && !$progress->findingsToJudge) {
            return;
        }

        $waitingOn = $this->waitingOn($progress);
        $now = $this->clock->now();

        if ($waitingOn !== $this->waitingOn || !$this->since instanceof DateTimeImmutable) {
            $this->say('Waiting on '.$waitingOn.'.', $now);
            $this->waitingOn = $waitingOn;
            $this->since = $now;

            return;
        }

        if ($this->lastSaid instanceof DateTimeImmutable && $now >= $this->lastSaid->modify('+'.self::REPEAT_SECONDS.' seconds')) {
            $this->say(sprintf('Still waiting after %d s on %s.', $now->getTimestamp() - $this->since->getTimestamp(), $waitingOn), $now);
        }
    }

    // Asked once for each reviewer, at the first poll that sees it blocked, the very first poll included, because the worker may record a reviewer blocked by its cooling pins before the demo polls, and the demo must still stop with the plain message then. A reviewer that delivered its verdict is never asked, because a tier cooling after its answer keeps nothing waiting; a blocked round without a wait ends as usual.
    private function stopWhenBlockedByAWait(RoundProgress $progress): void
    {
        foreach ($progress->blocked as $reviewer) {
            if (in_array($reviewer->id, $this->blockedSeen, true)) {
                continue;
            }

            $this->blockedSeen[] = $reviewer->id;
            $this->modelOf($reviewer->role.' reviewer', $reviewer->tier);
        }
    }

    // The judge only once every reviewer is in, because it reads their findings.
    private function waitingOn(RoundProgress $progress): string
    {
        $parts = array_map(fn (AwaitedReviewer $reviewer): string => $this->named($reviewer->role.' reviewer', $reviewer->tier), $progress->awaited);
        if ([] === $parts) {
            $parts = [$this->named('judge', Vendor::JUDGE_TIER)];
        }

        $last = array_pop($parts);

        return [] === $parts ? $last : implode(', ', $parts).' and '.$last;
    }

    private function named(string $waitedOn, Tier $tier): string
    {
        $model = $this->modelOf($waitedOn, $tier);

        return 'the '.$waitedOn.(null === $model ? '' : ' ('.$model.')');
    }

    // A wait is the only outage this stops for; rejected keys or a disabled provider end the reviewer with its own reason, so the line then names no model.
    private function modelOf(string $waitedOn, Tier $tier): ?string
    {
        try {
            return $this->tiers->answering(SampleChange::PROJECT, $tier)->model;
        } catch (EveryAccountCoolingDown $cooling) {
            throw DemoProviderBusy::for($waitedOn, $cooling->provider, $cooling->secondsUntilAnAccountIsFree);
        } catch (EveryProviderOut $out) {
            $limited = $this->shortestWaitOf($out);

            return $limited instanceof ProviderOutage ? throw DemoProviderBusy::for($waitedOn, $limited->provider, (int) $limited->secondsUntilFree) : null;
        } catch (EveryKeyRejected|TierResolutionFailure) {
            return null;
        }
    }

    private function shortestWaitOf(EveryProviderOut $out): ?ProviderOutage
    {
        $cooling = array_filter($out->outages, static fn (ProviderOutage $outage): bool => null !== $outage->secondsUntilFree);
        usort($cooling, static fn (ProviderOutage $one, ProviderOutage $other): int => $one->secondsUntilFree <=> $other->secondsUntilFree);

        return array_first($cooling);
    }

    private function say(string $line, DateTimeImmutable $now): void
    {
        $this->output->writeln($line);
        $this->lastSaid = $now;
    }
}
