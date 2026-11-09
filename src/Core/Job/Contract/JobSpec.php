<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobSpec
{
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,40}$/';
    private const string HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

    /**
     * @param list<string>   $command
     * @param list<JobInput> $inputs
     * @param list<string>   $registryHosts
     */
    public function __construct(
        public string $id,
        public string $image,
        public array $command,
        public array $inputs,
        public string $outputDir,
        public int $memoryBytes,
        public float $cpus,
        public int $timeoutSeconds,
        public EgressPolicy $egress,
        public string $vendorHost = '',
        public array $registryHosts = [],
    ) {
        $this->mustBeNamedSafely();
        $this->mustHavePositiveLimits();
        $this->mustKeepInputsApartFromItsOutput();
        $this->mustHaveHostsThatMatchItsEgress();
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        if (EgressPolicy::None === $this->egress) {
            return [];
        }

        return [$this->vendorHost, ...$this->registryHosts];
    }

    private function mustBeNamedSafely(): void
    {
        // The id ends up in container, network and label names, so it must not carry anything a name cannot.
        if (1 !== preg_match(self::ID_PATTERN, $this->id)) {
            throw new InvalidJobSpec('A job id is lower-case letters, digits and dashes, at most 41 characters.');
        }

        if ('' === $this->image || [] === $this->command) {
            throw new InvalidJobSpec('A job names its image and its command.');
        }

        if (!str_starts_with($this->outputDir, '/')) {
            throw new InvalidJobSpec('A job output directory is an absolute path.');
        }
    }

    private function mustHavePositiveLimits(): void
    {
        if ($this->memoryBytes < 1 || $this->cpus <= 0.0 || $this->timeoutSeconds < 1) {
            throw new InvalidJobSpec('A job has a memory limit, a CPU limit and a time limit above zero.');
        }
    }

    private function mustKeepInputsApartFromItsOutput(): void
    {
        $output = rtrim($this->outputDir, '/').'/';

        foreach ($this->inputs as $input) {
            $source = rtrim($input->hostPath, '/').'/';

            if (str_starts_with($source, $output) || str_starts_with($output, $source)) {
                throw new InvalidJobSpec('An input cannot hold or sit inside the job output directory.');
            }
        }
    }

    private function mustHaveHostsThatMatchItsEgress(): void
    {
        if (EgressPolicy::None === $this->egress && ('' !== $this->vendorHost || [] !== $this->registryHosts)) {
            throw new InvalidJobSpec('A job without egress has no hosts to reach.');
        }

        if (EgressPolicy::None === $this->egress) {
            return;
        }

        foreach ($this->allowedHosts() as $host) {
            if (1 !== preg_match(self::HOST_PATTERN, $host)) {
                throw new InvalidJobSpec('An allowed host is a plain host name, the vendor first.');
            }
        }
    }
}
