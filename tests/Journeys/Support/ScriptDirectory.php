<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;

// The folder a journey arms its scripted model answers in and every consumer worker records its calls in, so the journey reads the calls of other processes without a shared service.
final readonly class ScriptDirectory
{
    private const string SCRIPT = 'script.json';

    /**
     * @param Closure(string, string): bool $link
     */
    public function __construct(
        private string $path,
        private ClockInterface $clock,
        private Closure $link = link(...),
    ) {
    }

    /**
     * @param non-empty-list<array{content_type: string, body: string}> $answers
     */
    public function arm(float $weight, array $answers): void
    {
        new Filesystem()->dumpFile($this->path.'/'.self::SCRIPT, json_encode(['weight' => $weight, 'answers' => $answers], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{weight: float, answers: non-empty-list<array{content_type: string, body: string}>}
     *
     * @throws ScriptNotArmed
     */
    public function script(): array
    {
        $file = $this->path.'/'.self::SCRIPT;
        $script = $this->decoded($file);
        $weight = $script['weight'] ?? null;
        $answers = $this->answersIn($script['answers'] ?? null);

        if ((!is_float($weight) && !is_int($weight)) || $weight < 0 || null === $answers) {
            throw new ScriptNotArmed($file);
        }

        return ['weight' => (float) $weight, 'answers' => $answers];
    }

    /**
     * @throws RecordNotPublished
     */
    public function callStarted(): int
    {
        $call = count($this->filesOf('start')) + 1;

        while (!$this->published($call.'.start', $this->record($call))) {
            ++$call;
        }

        return $call;
    }

    /**
     * @throws CallEndedTwice
     * @throws RecordNotPublished
     */
    public function callEnded(int $call): void
    {
        if (!$this->published($call.'.end', $this->record($call))) {
            throw new CallEndedTwice($call);
        }
    }

    /**
     * @return list<ScriptedCall>
     */
    public function calls(): array
    {
        $calls = [];

        foreach ($this->filesOf('start') as $file) {
            $number = basename($file, '.start');
            $started = ctype_digit($number) && (string) (int) $number === $number ? $this->recordIn($file, (int) $number) : null;

            // Skipped, because only a writer other than this class leaves an empty, partial, linked or misnamed record, and one such file must not hide the others; a zero-padded or overflowing name is misnamed, so no call is listed twice.
            if (null === $started) {
                continue;
            }

            $ended = $this->recordIn($this->path.'/'.$number.'.end', (int) $number);
            $calls[] = new ScriptedCall($started['call'], $started['pid'], $started['start'], $started['at'], $ended['at'] ?? null);
        }

        usort($calls, static fn (ScriptedCall $one, ScriptedCall $other): int => $one->number <=> $other->number);

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function filesOf(string $kind): array
    {
        $files = glob($this->path.'/*.'.$kind);

        return false === $files ? [] : $files;
    }

    private function record(int $call): string
    {
        $pid = (int) getmypid();
        $start = new ProcParents()->startOf($pid) ?? throw new RecordNotPublished($this->path.'/'.$call);

        return json_encode(['call' => $call, 'pid' => $pid, 'start' => $start, 'at' => (float) $this->clock->now()->format('U.u')], JSON_THROW_ON_ERROR);
    }

    // False only when the name was taken; any other failure throws, because retrying it would never end.
    private function published(string $name, string $record): bool
    {
        $filesystem = new Filesystem();
        $temporary = $filesystem->tempnam($this->path, '.record-');
        $file = $this->path.'/'.$name;

        try {
            $filesystem->dumpFile($temporary, $record);

            // link() refuses a name that exists, so two workers never take one call number, and the record appears whole or not at all.
            $linked = @($this->link)($temporary, $file);
            $landed = $linked && $this->isTheSameFile($file, $temporary);

            // A link() through a symbolic link at the name reports success and creates the link's target, which must not stay behind as a record under another name.
            if ($linked && !$landed) {
                $this->removeOtherNamesOf($temporary);
            }
        } finally {
            $filesystem->remove($temporary);
        }

        // Checked even after a reported success, because a link() through a symbolic link put at the name after the scan lands elsewhere and must not claim the number.
        if ($linked !== $landed || (!$linked && !is_link($file) && !file_exists($file))) {
            throw new RecordNotPublished($file);
        }

        return $linked;
    }

    private function isTheSameFile(string $name, string $temporary): bool
    {
        clearstatcache(true, $name);
        $atName = @lstat($name);
        $record = @stat($temporary);

        return false !== $atName && false !== $record && $atName['dev'] === $record['dev'] && $atName['ino'] === $record['ino'];
    }

    private function removeOtherNamesOf(string $temporary): void
    {
        foreach (array_diff((array) scandir($this->path), ['.', '..', basename($temporary)]) as $name) {
            if ($this->isTheSameFile($this->path.'/'.$name, $temporary)) {
                new Filesystem()->remove($this->path.'/'.$name);
            }
        }
    }

    /**
     * @return array{call: int, pid: int, start: int, at: float}|null
     */
    private function recordIn(string $file, int $number): ?array
    {
        $record = is_link($file) ? [] : $this->decoded($file);
        $call = $record['call'] ?? null;
        $pid = $record['pid'] ?? null;
        $start = $record['start'] ?? null;
        $at = $record['at'] ?? null;

        if ($number !== $call || !is_int($pid) || !is_int($start) || (!is_float($at) && !is_int($at))) {
            return null;
        }

        return ['call' => $call, 'pid' => $pid, 'start' => $start, 'at' => (float) $at];
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $file): array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        $decoded = false === $contents ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return non-empty-list<array{content_type: string, body: string}>|null
     */
    private function answersIn(mixed $answers): ?array
    {
        if (!is_array($answers) || [] === $answers || !array_is_list($answers)) {
            return null;
        }

        $read = [];

        foreach ($answers as $answer) {
            if (!is_array($answer) || !is_string($answer['content_type'] ?? null) || !is_string($answer['body'] ?? null)) {
                return null;
            }

            $read[] = ['content_type' => $answer['content_type'], 'body' => $answer['body']];
        }

        return $read;
    }
}
