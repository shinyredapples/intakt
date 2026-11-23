<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CodingStandardsGate extends PhpFilesToolGate
{
    // Because PHP CS Fixer's cache signature covers its own version and the rules but not the code of Intakt's fixers.
    private const string FIXERS = 'src/PhpCsFixer';

    public function name(): string
    {
        return 'cs';
    }

    // The cache file in the gates cache, named after the code of Intakt's fixers so a changed fixer starts from an empty cache; null when they cannot be read.
    public function cacheFile(string $fixers, string $cacheDirectory): ?string
    {
        $sources = glob($fixers.'/*.php');

        if (false === $sources || [] === $sources) {
            return null;
        }

        sort($sources);
        $context = hash_init('xxh128');

        foreach ($sources as $source) {
            $code = file_get_contents($source);

            if (false === $code) {
                return null;
            }

            hash_update($context, basename($source)."\0".strlen($code)."\0".$code);
        }

        return $cacheDirectory.'/php-cs-fixer-'.hash_final($context).'.cache';
    }

    protected function tool(): string
    {
        return 'php-cs-fixer';
    }

    protected function arguments(Workspace $workspace, array $files): array
    {
        return [
            'fix', '--dry-run', '-v', '--format=json', '--no-interaction',
            ...$this->cacheArguments($workspace),
            '--config='.$workspace->config('php-cs-fixer.php'),
            ...$files,
        ];
    }

    protected function findings(ProcessResult $result): array
    {
        $report = JsonNode::parse($result->output);

        if (!$report instanceof JsonNode) {
            return [];
        }

        return array_map(
            static fn (JsonNode $file): string => $file->get('name')->text().': '.implode(', ', array_map(static fn (JsonNode $fixer): string => $fixer->text(), $file->get('appliedFixers')->items())),
            $report->get('files')->items(),
        );
    }

    /**
     * @return list<string>
     */
    private function cacheArguments(Workspace $workspace): array
    {
        $directory = GatesCache::directory();
        $file = $this->cacheFile($workspace->config(self::FIXERS), $directory);

        if (null === $file) {
            return ['--using-cache=no'];
        }

        try {
            new Filesystem()->mkdir($directory);
        } catch (IOException) {
            // Because a cache folder that cannot be made only costs time: the gate then checks every file in scope.
            return ['--using-cache=no'];
        }

        if (!is_writable($directory)) {
            return ['--using-cache=no'];
        }

        return ['--using-cache=yes', '--cache-file='.$file];
    }
}
