<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ProbeCodexLogin;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'intakt:codex:probe', description: 'Run one call on an account\'s Codex login with the pinned model and effort')]
final readonly class ProbeCodexLoginCommand
{
    private const string PROMPT = 'Answer with the single word OK.';

    /**
     * @param array{model: non-empty-string, effort: string}|null $codex
     */
    public function __construct(
        private CodexLogins $logins,
        #[Autowire(param: AiServices::CODEX)]
        private ?array $codex,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The account whose login the call uses')] string $account = Account::DEFAULT): int
    {
        if (null === $this->codex) {
            $io->error('Codex is not configured: set codex.model and codex.effort in the Intakt configuration.');

            return Command::FAILURE;
        }

        $settings = CodexSettings::fromConfiguration($this->codex);
        $io->definitionList(['Account' => $account], ['Model' => $settings->model], ['Effort' => $settings->effort->value]);

        try {
            $answer = $this->answer($settings, $account);
        } catch (CodexLoginUnusable|RuntimeException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success('Codex answered: '.$answer);

        return Command::SUCCESS;
    }

    private function answer(CodexSettings $settings, string $account): string
    {
        $raw = new IsolatedCodexCli($this->logins, $settings, $account)->request(new Codex($settings->model), self::PROMPT);

        $result = new ResultConverter()->convert($raw);

        if (!$result instanceof TextResult) {
            throw new RuntimeException('Codex answered with more than text.');
        }

        return $result->getContent();
    }
}
