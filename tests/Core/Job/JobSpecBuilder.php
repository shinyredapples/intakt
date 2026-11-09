<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\EgressPolicy;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobSpec;

final readonly class JobSpecBuilder
{
    /**
     * @param list<JobInput> $inputs
     */
    private function __construct(
        private string $outputDir,
        private array $inputs,
        private EgressPolicy $egress,
        private string $id,
    ) {
    }

    public static function in(string $outputDir): self
    {
        return new self($outputDir, [], EgressPolicy::Allowlist, 'build-1');
    }

    public function withInput(string $hostPath, string $containerPath): self
    {
        return new self($this->outputDir, [...$this->inputs, new JobInput($hostPath, $containerPath)], $this->egress, $this->id);
    }

    public function withoutEgress(): self
    {
        return new self($this->outputDir, $this->inputs, EgressPolicy::None, $this->id);
    }

    public function named(string $id): self
    {
        return new self($this->outputDir, $this->inputs, $this->egress, $id);
    }

    public function build(): JobSpec
    {
        $allowlist = EgressPolicy::Allowlist === $this->egress;

        return new JobSpec(
            $this->id,
            'job-image:1',
            ['make', 'build'],
            $this->inputs,
            $this->outputDir,
            512 * 1024 * 1024,
            1.5,
            600,
            $this->egress,
            $allowlist ? 'api.vendor.example' : '',
            $allowlist ? ['registry.example', 'packages.example'] : [],
        );
    }
}
