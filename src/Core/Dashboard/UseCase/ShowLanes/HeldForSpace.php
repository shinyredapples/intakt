<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowLanes;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Git\Contract\GitServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// Measured on each request and never kept, so the line goes away by itself on the first page load after space is back.
final readonly class HeldForSpace
{
    private const array UNITS = ['bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

    private const string RESUMES = 'Work already running goes on. This line goes away by itself once space is back; a step that was refused meanwhile must be run again.';

    public function __construct(
        private DiskSpace $disk,
        // The mirrors folder, because every mirror update starts there; a build clone's folder is the caller's choice, so the page has none to measure.
        #[Autowire(param: GitServices::MIRRORS_DIRECTORY)]
        private string $mirrorsDirectory,
    ) {
    }

    /**
     * @return array{short: string, title: string, text: string, broken: bool}|null
     */
    public function now(): ?array
    {
        foreach ($this->folders() as $folder) {
            try {
                $hold = $this->disk->holdAt($folder);
            } catch (FreeSpaceUnreadable $unreadable) {
                return $this->unreadable($unreadable);
            } catch (ProcessTimedOutException $timedOut) {
                return $this->unreadable(new FreeSpaceUnreadable($folder, sprintf('df timed out after %s seconds', $timedOut->getExceededTimeout())));
            }

            if ($hold instanceof DiskHold) {
                return $this->held($hold);
            }
        }

        return null;
    }

    /**
     * The mirrors folder and each mirror in it, because a mirror can sit on a disk of its own and UpdateMirror measures that one.
     *
     * @return list<string>
     */
    private function folders(): array
    {
        $folders = [NearestFolder::of($this->mirrorsDirectory)];
        foreach (glob($this->mirrorsDirectory.'/*', GLOB_ONLYDIR) ?: [] as $mirror) {
            $folders[] = $mirror;
        }

        return $folders;
    }

    /**
     * @return array{short: string, title: string, text: string, broken: bool}
     */
    private function unreadable(FreeSpaceUnreadable $unreadable): array
    {
        return [
            'short' => 'Free disk space unknown',
            'title' => 'The free disk space cannot be read.',
            'text' => $unreadable->getMessage().' A mirror update or build clone on that disk fails until it can be read.',
            'broken' => true,
        ];
    }

    /**
     * @return array{short: string, title: string, text: string, broken: bool}
     */
    private function held(DiskHold $hold): array
    {
        return match ($hold->floor) {
            DiskFloor::Bytes => $this->heldBytes($hold),
            DiskFloor::Inodes => [
                'short' => 'New work paused: the disk is low on file slots',
                'title' => 'New work is paused because the disk is low on free file slots (inodes).',
                'text' => sprintf('The disk of %s has %s free file slots, below the floor of %s (disk.min_free_inodes). %s', $hold->folder, number_format($hold->free), number_format($hold->minimum), self::RESUMES),
                'broken' => false,
            ],
        };
    }

    /**
     * @return array{short: string, title: string, text: string, broken: bool}
     */
    private function heldBytes(DiskHold $hold): array
    {
        [$free, $minimum] = $this->bytePair($hold->free, $hold->minimum);

        return [
            'short' => 'New work paused: the disk is low on space',
            'title' => 'New work is paused because the disk is low on free space.',
            'text' => sprintf('The disk of %s has %s free, below the floor of %s (disk.min_free_bytes). %s', $hold->folder, $free, $minimum, self::RESUMES),
            'broken' => false,
        ];
    }

    /**
     * @return array{string, string}
     */
    private function bytePair(int $free, int $minimum): array
    {
        $pair = [$this->bytes($free), $this->bytes($minimum)];
        if ($pair[0] === $pair[1]) {
            return [$this->exactBytes($free), $this->exactBytes($minimum)];
        }

        return $pair;
    }

    private function exactBytes(int $bytes): string
    {
        return number_format($bytes).(1 === $bytes ? ' byte' : ' bytes');
    }

    private function bytes(int $bytes): string
    {
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
            $size /= 1024;
            ++$unit;
        }

        // Rounded down, so a reading one byte below its floor never reads as equal to it.
        $number = number_format(floor($size * 10) / 10, 0 === $unit ? 0 : 1);

        if (0 === $unit) {
            return $this->exactBytes($bytes);
        }

        return (str_ends_with($number, '.0') ? substr($number, 0, -2) : $number).' '.self::UNITS[$unit];
    }
}
