<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\SourcePrefix;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** LT-12 (the budgets value object), LT-13 (the fence key) and LT-18 (one source derivation, two users). */
#[Group('admission-engine')]
final class AdmissionBudgetsAndSourceTest extends TestCase
{
    public function testTheBudgetsCarryTheStudyValuesTheTestsPass(): void
    {
        $b = EngineLab::budgets();

        self::assertSame(600, $b->windowSeconds);
        self::assertSame(24, $b->sourceCapacity);
        self::assertSame(30, $b->permitDeadlineSeconds);
        self::assertSame(600, $b->permitGraceSeconds);
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function invalid(): iterable
    {
        yield 'zero window' => [['windowSeconds' => 0]];
        yield 'zero source capacity' => [['sourceCapacity' => 0]];
        yield 'negative failure cap' => [['sourceFailureCap' => -1]];
        yield 'deadline beyond the window (D <= W)' => [['permitDeadlineSeconds' => 601]];
        yield 'zero deadline' => [['permitDeadlineSeconds' => 0]];
        yield 'negative grace' => [['permitGraceSeconds' => -1]];
    }

    /** @param array<string, int> $override */
    #[DataProvider('invalid')]
    public function testAnInvalidBudgetIsRejectedAtConstruction(array $override): void
    {
        $values = [
            'windowSeconds' => 600, 'sourceCapacity' => 24, 'sourceFailureCap' => 4, 'accountBucketTakes' => 4, 'accountBucketSeconds' => 1800,
            'proofAccountWindow' => 10, 'proofAccountDay' => 60, 'proofProofWindow' => 6, 'proofProofDay' => 30, 'newDevicesPerDay' => 10,
            'crossAccountFailureCap' => 4, 'permitDeadlineSeconds' => 30, 'permitGraceSeconds' => 600,
        ];
        $this->expectException(RateLimiterException::class);
        new AdmissionBudgetsDTO(...array_replace($values, $override));
    }

    public function testTheFenceKeyIsPerBudgetGroupAndEnvironmentAndSecret(): void
    {
        $base = AdmissionRuntime::fenceKey('prod', 'login', 'key-secret');

        self::assertNotSame($base, AdmissionRuntime::fenceKey('prod', 'otp', 'key-secret'));
        self::assertNotSame($base, AdmissionRuntime::fenceKey('staging', 'login', 'key-secret'));
        self::assertNotSame($base, AdmissionRuntime::fenceKey('prod', 'login', 'other-secret'));
        self::assertSame($base, AdmissionRuntime::fenceKey('prod', 'login', 'key-secret'));
        self::assertStringNotContainsString('login', $base, 'the derived key is opaque');
    }

    /** @return iterable<string, array{string}> */
    public static function addresses(): iterable
    {
        yield 'ipv4' => ['198.51.100.7'];
        yield 'ipv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348'];
        yield 'ipv6 compressed' => ['2001:db8::1'];
        yield 'ipv4-mapped' => ['::ffff:198.51.100.7'];
        yield 'loopback' => ['::1'];
    }

    #[DataProvider('addresses')]
    public function testThePipelineAndTheRuntimeDeriveTheSameSource(string $ip): void
    {
        $pipeline = (new \ReflectionClass(EvaluationPipeline::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(EvaluationPipeline::class, 'getIpPrefix');

        self::assertSame($method->invoke($pipeline, $ip), SourcePrefix::of($ip));
        self::assertSame($method->invoke($pipeline, $ip, 48), SourcePrefix::of($ip, 48));
    }
}
