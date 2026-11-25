<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because the launcher's PID names its group only once the launcher has run `setsid`, a signal to the group before then reaches no one.
final readonly class ProcessGroup
{
    public const int STOP_GRACE_SECONDS = 2;

    // Because a stop that finds one of these disabled would throw before it signals anything, bin/check refuses to start a gate without them.
    public const array FUNCTIONS = ['pcntl' => ['pcntl_waitid'], 'posix' => ['posix_kill', 'posix_getpgid']];

    private const int POLL_MICROSECONDS = 10000;

    private const string ZOMBIE = 'Z';

    private const int START_TIME_FIELD = 19;

    public function __construct(
        private int $id,
        private string $launcherStart,
    ) {
    }

    // Because the launcher is still bin/check's unreaped child right after its start, its start time read then names it apart from any later process given its number; without one no later stop could prove it ours, so it is killed while that still holds.
    public static function ofLauncher(int $id): self
    {
        $group = new self($id, self::startTimeOf($id));

        if ('' === $group->launcherStart && null !== $group->hasTheUnreapedChildEnded()) {
            posix_kill($id, 9);
            $group->awaitTheKilledLauncher();
        }

        return $group;
    }

    // Because a stop without these functions or without /proc could not reach a launcher before its session, the process that would start one refuses to.
    public static function whatTheStopLacks(): ?string
    {
        foreach (self::FUNCTIONS as $functions) {
            foreach ($functions as $function) {
                if (!function_exists($function)) {
                    return 'the PHP function '.$function.' is missing';
                }
            }
        }

        $own = '/proc/'.posix_getpid().'/stat';

        return '' === self::startTimeOf(posix_getpid()) ? 'the stop cannot read '.$own : null;
    }

    // Because only an unreaped child's PID is surely still the launcher's, the bare PID is signaled only then, and its zombie then holds the PID, so a session it created in the meantime is still its own.
    public function kill(): void
    {
        if ($this->isOursWithoutItsGroup()) {
            posix_kill($this->id, 9);

            if (posix_getpgid($this->id) !== $this->id) {
                return;
            }
        }

        posix_kill(-$this->id, 9);
    }

    public function stop(): void
    {
        self::stopTogether([$this]);
    }

    // Because only the gate child can reach the session of its contained run, each is asked to stop and killed when it does not; all share one grace, and each is asked the moment its group exists, so none waits for another's grace.
    /**
     * @param list<self> $groups
     */
    public static function stopTogether(array $groups): void
    {
        $deadline = (hrtime(true) / 1_000_000_000) + self::STOP_GRACE_SECONDS;
        $unasked = $groups;

        while (true) {
            $unasked = self::askedOnceTheyLeadTheirGroup($unasked);

            if ([] === $unasked || (hrtime(true) / 1_000_000_000) >= $deadline) {
                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        foreach ($groups as $group) {
            $group->outlives($deadline);
        }

        foreach ($groups as $group) {
            $group->kill();
        }
    }

    // Because a launcher that has no group yet runs nothing it could stop, it is not asked; `stop()` waits for its group or kills it.
    private function askToStop(): void
    {
        if (!$this->isOursWithoutItsGroup()) {
            posix_kill(-$this->id, 15);
        }
    }

    public function outlives(float $deadline): bool
    {
        while ($this->isAlive() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->isAlive();
    }

    // Because asking the group before the launcher leads it would miss what the launcher starts right after, a launcher still held before its session is left for the next round; one that leads its group, has ended or is no longer ours is asked now.
    /**
     * @param list<self> $groups
     *
     * @return list<self>
     */
    private static function askedOnceTheyLeadTheirGroup(array $groups): array
    {
        $held = [];

        foreach ($groups as $group) {
            if ($group->isHeldBeforeItsSession()) {
                $held[] = $group;

                continue;
            }

            $group->askToStop();
        }

        return $held;
    }

    // Because a kill is delivered after `posix_kill()` returns, the launcher is waited for, within the stop grace, so it has ended once bin/check goes on.
    private function awaitTheKilledLauncher(): void
    {
        $deadline = (hrtime(true) / 1_000_000_000) + self::STOP_GRACE_SECONDS;

        while (false === $this->hasTheUnreapedChildEnded() && (hrtime(true) / 1_000_000_000) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function isHeldBeforeItsSession(): bool
    {
        return false === $this->hasTheUnreapedChildEnded() && $this->isTheLauncherWithoutItsGroup();
    }

    // Because the stop runs inside a signal handler that may interrupt Symfony Process mid-call, ownership is asked of the kernel without touching the launcher's pipes: `WNOWAIT` reaps nothing, so the number stays the launcher's while this is true, and a failed call means it was reaped.
    private function isOursWithoutItsGroup(): bool
    {
        return null !== $this->hasTheUnreapedChildEnded() && $this->isTheLauncherWithoutItsGroup();
    }

    // Because `WNOHANG` leaves the reported PID at 0 while the child still runs, a nonzero one means it has ended and waits as a zombie; null means the number is no longer an unreaped child of bin/check.
    private function hasTheUnreapedChildEnded(): ?bool
    {
        $info = [];

        if (!pcntl_waitid(P_PID, $this->id, $info, WEXITED | WNOHANG | WNOWAIT)) {
            return null;
        }

        return is_array($info) && 0 !== ($info['pid'] ?? 0);
    }

    private function isTheLauncherWithoutItsGroup(): bool
    {
        return '' !== $this->launcherStart && self::startTimeOf($this->id) === $this->launcherStart && posix_getpgid($this->id) !== $this->id;
    }

    private static function startTimeOf(int $id): string
    {
        return self::fieldsOf((string) @file_get_contents('/proc/'.$id.'/stat'))[self::START_TIME_FIELD] ?? '';
    }

    /**
     * @return list<string>
     */
    private static function fieldsOf(string $status): array
    {
        $end = strrpos($status, ')');

        return false === $end ? [] : explode(' ', substr($status, $end + 2));
    }

    private function isAlive(): bool
    {
        if (!posix_kill(-$this->id, 0)) {
            return false;
        }

        $statuses = glob('/proc/[0-9]*/stat');

        if (false === $statuses || [] === $statuses) {
            return true;
        }

        return [] !== array_filter($statuses, fn (string $status): bool => $this->isLiveMember((string) @file_get_contents($status)));
    }

    private function isLiveMember(string $status): bool
    {
        $fields = self::fieldsOf($status);

        return [] !== $fields && self::ZOMBIE !== $fields[0] && (string) $this->id === ($fields[2] ?? '');
    }
}
