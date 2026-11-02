<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

// Each account's login is kept here and only here; a call gets a read-only copy in a home of its own, so nothing a call writes reaches the kept login.
final readonly class CodexLogins
{
    private const string LOGIN_FILE = 'auth.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/codex')]
        private string $directory,
        private CodexTokenRefresh $refresh,
        private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    // Under the account's lock, because a refresh replaces the refresh token and a second refresh with the old one would lose the login.
    public function handOver(string $account): string
    {
        $this->assertAccountName($account);
        $lock = $this->locks->createLock('intakt.codex.login.'.$account);
        $lock->acquire(true);

        try {
            $this->copyReadOnly($account, $this->refreshedIfDue($account));
        } finally {
            $lock->release();
        }

        return $this->callHome($account);
    }

    public function loginFile(string $account): string
    {
        $this->assertAccountName($account);

        return $this->directory.'/logins/'.$account.'/'.self::LOGIN_FILE;
    }

    // The name becomes a path and a lock key, so only a name an account can have is accepted.
    private function assertAccountName(string $account): void
    {
        if (1 !== preg_match(Account::NAME_PATTERN, $account)) {
            throw new CodexLoginUnusable('"'.$account.'" is no account name.');
        }
    }

    private function refreshedIfDue(string $account): CodexLogin
    {
        $login = $this->stored($account);
        $now = $this->clock->now();

        if (!$login->isDueAt($now)) {
            return $login;
        }

        $refreshed = $login->refreshed($this->refresh->tokensFor($login->refreshToken()), $now);
        $this->write($this->loginFile($account), $refreshed->json(), 0o600);

        return $refreshed;
    }

    private function stored(string $account): CodexLogin
    {
        $file = $this->loginFile($account);
        $json = is_file($file) ? file_get_contents($file) : false;

        if (false === $json) {
            throw new CodexLoginUnusable('Account "'.$account.'" has no Codex login: copy its auth.json to '.$file.'.');
        }

        return CodexLogin::fromJson($json);
    }

    private function copyReadOnly(string $account, CodexLogin $login): void
    {
        $this->write($this->callHome($account).'/'.self::LOGIN_FILE, $login->json(), 0o400);
    }

    private function callHome(string $account): string
    {
        return $this->directory.'/homes/'.$account;
    }

    // The directory keeps the file from other users while it is written, before its own mode is set.
    private function write(string $file, string $contents, int $mode): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(dirname($file), 0o700);
        $filesystem->dumpFile($file, $contents);
        $filesystem->chmod($file, $mode);
    }
}
