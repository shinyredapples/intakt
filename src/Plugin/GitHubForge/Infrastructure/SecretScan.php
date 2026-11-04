<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// gitleaks with its built-in rules, because redaction knows only the values a job held and a credential of a known shape can still slip through.
final readonly class SecretScan
{
    private const int FOUND = 3;
    private const int TIMEOUT_SECONDS = 60;
    // Emptied, so a variable set for another tool cannot swap the built-in rules for weaker ones.
    private const array ENVIRONMENT = ['GITLEAKS_CONFIG' => false, 'GITLEAKS_CONFIG_TOML' => false];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $directory,
        #[Autowire(env: 'default:'.GitHubForgeServices::GITLEAKS.':INTAKT_GITLEAKS')]
        private string $scanner,
    ) {
    }

    #[NoDiscard]
    public function findsSecretIn(string $text): bool
    {
        // Inline allow comments in the text are ignored, because the job that wrote the text could add one.
        $process = new Process([$this->scanner, 'stdin', '--no-banner', '--ignore-gitleaks-allow', '--log-level', 'error', '--exit-code', (string) self::FOUND], $this->directory, self::ENVIRONMENT, $text, self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ExceptionInterface $failure) {
            throw new ForgeWriteFailed('The secret scan could not run, so nothing is posted.', 0, $failure);
        }

        return match ($process->getExitCode()) {
            0 => false,
            self::FOUND => true,
            default => throw new ForgeWriteFailed(sprintf('The secret scan failed with exit code %d, so nothing is posted.', $process->getExitCode() ?? -1)),
        };
    }
}
