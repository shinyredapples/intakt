<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use Intakt\Core\Git\Contract\GitServices;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// The shipped setting, so a key path outside the folder the image keeps on its volume is seen by a test and not only on an install.
#[CoversNothing]
final class SigningKeySettingTest extends KernelTestCase
{
    public function testTheSigningKeyIsReadFromTheKeysFolderOfTheDataVolume(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame($container->getParameter('kernel.project_dir').'/var/data/keys/signing_key', $container->getParameter(GitServices::SIGNING_KEY));
    }
}
