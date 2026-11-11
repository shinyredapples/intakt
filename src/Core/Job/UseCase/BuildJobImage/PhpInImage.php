<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;

// Asks the built image itself, because a devcontainer or a Dockerfile brings whatever PHP it names and a floating minor tag moves.
final readonly class PhpInImage
{
    private const string ANSWER = 'php.json';
    // Composer's spelling of extension names (lowercase, a dash for a space), so "Zend OPcache" compares with ext-zend-opcache.
    private const string PROBE = 'file_put_contents("%s/%s", json_encode(["version" => PHP_VERSION, "extensions" => array_map(static fn (string $name): string => strtolower(str_replace(" ", "-", $name)), get_loaded_extensions())], JSON_THROW_ON_ERROR));';
    private const string VERSION = '/^\d+\.\d+\.\d+/';
    private const int MEMORY_BYTES = 256 * 1024 * 1024;
    private const float CPUS = 1.0;
    private const int SECONDS = 60;

    public function __construct(private JobRunner $jobs)
    {
    }

    /**
     * @throws JobImageNotBuilt
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobOutputRefused
     * @throws JobEventNotRecorded
     */
    public function verify(string $tag, PhpProfile $php): string
    {
        [$version, $extensions] = $this->probe($tag);

        if (!$php->allows($version)) {
            throw JobImageNotBuilt::anotherPhp($tag, $version, $php->rule);
        }

        $missing = array_values(array_diff($php->extensions, $extensions));

        return [] === $missing ? $version : throw JobImageNotBuilt::missingExtensions($tag, $missing);
    }

    /**
     * @return array{string, list<string>}
     */
    private function probe(string $tag): array
    {
        $result = $this->jobs->run(new JobSpec(
            new JobId('image-probe-'.substr(hash('xxh128', $tag), 0, 16)),
            $tag,
            ['php', '-r', sprintf(self::PROBE, JobSpec::OUTPUT_DIRECTORY, self::ANSWER)],
            [],
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            JobEgress::none(),
        ));

        if (JobCause::Exited !== $result->end->cause || 0 !== $result->end->exitCode) {
            throw JobImageNotBuilt::probeFailed($tag, $result->end);
        }

        return $this->answerOf($tag, $result->output->contents(self::ANSWER));
    }

    /**
     * @return array{string, list<string>}
     */
    private function answerOf(string $tag, ?string $json): array
    {
        $answer = json_decode($json ?? '', true);
        $version = is_array($answer) ? ($answer['version'] ?? null) : null;
        $extensions = is_array($answer) ? ($answer['extensions'] ?? null) : null;

        if (!is_string($version) || 1 !== preg_match(self::VERSION, $version) || !is_array($extensions) || !array_is_list($extensions)) {
            throw JobImageNotBuilt::probeUnreadable($tag);
        }

        return [$version, array_map(static fn (mixed $name): string => is_string($name) ? $name : throw JobImageNotBuilt::probeUnreadable($tag), $extensions)];
    }
}
