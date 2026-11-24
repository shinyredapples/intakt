<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Doctor;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Intakt\Core\Doctor\UseCase\RunDoctor\DoctorCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DoctorCommand::class)]
#[UsesClass(CheckResult::class)]
final class DoctorCommandTest extends TestCase
{
    public function testTheDoctorExitsZeroAndPrintsAPassLineForEachCheckWhenEveryCheckPasses(): void
    {
        $tester = $this->execute($this->check('Hub', CheckResult::passed()), $this->check('Forge', CheckResult::passed()));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("pass: Hub\npass: Forge\n", $tester->getDisplay());
    }

    public function testTheDoctorExitsNonZeroAndPrintsTheFixTextWhenOneCheckFails(): void
    {
        $tester = $this->execute($this->check('Hub', CheckResult::passed()), $this->check('Forge', CheckResult::failed('Fix the key.')));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("pass: Hub\nFAIL: Forge: Fix the key.\n", $tester->getDisplay());
    }

    public function testACheckThatThrowsIsReportedAsCouldNotRunAndCountsAsAFailure(): void
    {
        $tester = $this->execute($this->check('Hub', new RuntimeException('secret-token-value')));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('FAIL: Hub: The check could not run (RuntimeException)', $tester->getDisplay());
        self::assertStringNotContainsString('secret-token-value', $tester->getDisplay());
    }

    public function testAFixTextWithALineBreakIsPrintedAsOneLine(): void
    {
        $tester = $this->execute($this->check('Forge', CheckResult::failed("does not match: example/repo\nextra\r\n.")));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("FAIL: Forge: does not match: example/repo extra  .\n", $tester->getDisplay());
    }

    public function testACheckNameWithAControlCharacterIsPrintedWithASpaceInItsPlace(): void
    {
        $tester = $this->execute($this->check("Hub\tname", CheckResult::passed()));

        self::assertSame("pass: Hub name\n", $tester->getDisplay());
    }

    public function testAFixTextThatIsNotValidUtf8StillHasItsLineBreaksReplaced(): void
    {
        $tester = $this->execute($this->check('Forge', CheckResult::failed("bad \xFF\nbyte")));

        self::assertSame("FAIL: Forge: bad \xFF byte\n", $tester->getDisplay());
    }

    private function check(string $name, CheckResult|RuntimeException $outcome): HealthCheck
    {
        return new readonly class($name, $outcome) implements HealthCheck {
            public function __construct(
                private string $name,
                private CheckResult|RuntimeException $outcome,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(): CheckResult
            {
                return $this->outcome instanceof RuntimeException ? throw $this->outcome : $this->outcome;
            }
        };
    }

    private function execute(HealthCheck ...$checks): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DoctorCommand($checks));
        $tester = new CommandTester($application->find('intakt:doctor'));
        $tester->execute([]);

        return $tester;
    }
}
