<?php

declare(strict_types=1);

namespace Intakt\Gates\Architecture;

final readonly class ModuleAddress
{
    private const string PATTERN = '/^Intakt\\\\(Core|Plugin)\\\\([A-Z]\w*)\\\\(.+)$/';

    private function __construct(
        public string $area,
        public string $module,
        public string $inside,
    ) {
    }

    public static function ofClass(string $className): ?self
    {
        if (1 !== preg_match(self::PATTERN, $className, $match)) {
            return null;
        }

        return new self($match[1], $match[2], $match[3]);
    }

    public function isSameModuleAs(self $other): bool
    {
        return $this->area === $other->area && $this->module === $other->module;
    }

    public function isInContract(): bool
    {
        return str_starts_with($this->inside, 'Contract\\');
    }

    public function layer(): string
    {
        return explode('\\', $this->inside)[0];
    }

    public function directory(): string
    {
        return $this->area.'/'.$this->module.'/';
    }
}
