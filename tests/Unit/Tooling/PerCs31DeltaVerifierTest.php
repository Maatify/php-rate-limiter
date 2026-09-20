<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

final class PerCs31DeltaVerifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function deltaFixtures(): iterable
    {
        $root = dirname(__DIR__, 3) . '/tests/Fixtures/PerCs31Delta';
        foreach ([
            'clone' => 0,
            'switch' => 1,
            'pipe' => 1,
            'empty-closure' => 1,
            'anonymous-class-attributes' => 1,
            'enum-constant' => 1,
            'multiline-array' => 1,
        ] as $rule => $invalidExitCode) {
            yield $rule => [
                $rule,
                $root . '/valid/' . $rule . '.php.txt',
                $root . '/invalid/' . $rule . '.php.txt',
                $invalidExitCode,
            ];
        }
    }

    /**
     * @dataProvider deltaFixtures
     */
    public function testValidFixturePassesAndInvalidFixtureIsNotSilentlyAccepted(
        string $rule,
        string $validFixture,
        string $invalidFixture,
        int $invalidExitCode,
    ): void {
        [$validCode, $validOutput] = $this->runVerifier($validFixture);
        self::assertSame(0, $validCode, $rule . ' valid fixture must pass: ' . $validOutput);

        [$invalidCode, $invalidOutput] = $this->runVerifier($invalidFixture);
        self::assertSame($invalidExitCode, $invalidCode, $rule . ' invalid fixture result: ' . $invalidOutput);
        if ($invalidExitCode === 1) {
            self::assertStringContainsString($rule, $invalidOutput);
        } else {
            self::assertStringContainsString('ADVISORY', $invalidOutput);
            self::assertStringContainsString($rule, $invalidOutput);
        }
    }

    /**
     * @return array{int, string}
     */
    private function runVerifier(string $fixture): array
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $root . '/scripts/ci/check-per-cs-31-delta.php', $fixture],
            $descriptors,
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            self::fail('Unable to start the PER-CS 3.1 delta verifier process.');
        }

        if (!is_array($pipes)) {
            proc_terminate($process);
            proc_close($process);
            self::fail('The PER-CS 3.1 delta verifier did not expose pipes.');
        }

        if (!isset($pipes[0], $pipes[1], $pipes[2])
            || !is_resource($pipes[0])
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])) {
            proc_terminate($process);
            proc_close($process);
            self::fail('The PER-CS 3.1 delta verifier did not expose the expected pipes.');
        }

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $output];
    }
}
