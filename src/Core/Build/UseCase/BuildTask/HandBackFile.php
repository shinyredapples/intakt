<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Closure;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// Checked against the project's mirror, because the checks need a bare repository whose HEAD names main, and the build clone's HEAD names the task's branch.
final readonly class HandBackFile
{
    // Never asked, because a first build has no rework that asks for a merge of main.
    private const bool MERGE_OF_MAIN_ASKED = false;

    public function __construct(
        private BuildCheckout $checkout,
        private Filesystem $filesystem,
        // A file of its own for each check, beside the prompts, because the checks read a bundle from a path.
        #[Autowire('%kernel.project_dir%/var/hand-backs')]
        private string $bundleFolder,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(HandBack): T $check
     *
     * @return T|string
     */
    public function check(BuildTaskRequest $request, string $bundle, Closure $check): mixed
    {
        try {
            return $this->checkedIn($request, $bundle, $check);
        } catch (IOException|BuildCheckoutFailed $failure) {
            // Also after a passed check, because a file that cannot be removed leaves the untrusted bundle on disk.
            return $failure->getMessage();
        }
    }

    /**
     * @template T
     *
     * @param Closure(HandBack): T $check
     *
     * @return T
     */
    private function checkedIn(BuildTaskRequest $request, string $bundle, Closure $check): mixed
    {
        $this->filesystem->mkdir($this->bundleFolder, 0o700);
        $file = $this->filesystem->tempnam($this->bundleFolder, 'handback-', '.bundle');

        try {
            // Inside the try, because tempnam already made the file, and a write that fails must not leave it behind.
            $this->filesystem->dumpFile($file, $bundle);

            // No credentials, because the build job mounts none yet, so there is nothing to redact.
            return $check(new HandBack($this->checkout->mirrorOf($request->project), $request->branch, $file, self::MERGE_OF_MAIN_ASKED, new JobCredentials()));
        } finally {
            $this->filesystem->remove($file);
        }
    }
}
