<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\Admission\FamilyLab;
use Maatify\RateLimiter\Tests\Support\Admission\LaneAwareReaderInterface;
use Maatify\RateLimiter\Tests\Support\Admission\OptedLoginPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use Maatify\RateLimiter\Tests\Support\Admission\RealReaderAdapter;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate READER-PARITY (5b3a/PLAN.md section 5, decision D12): the operational reader rebuilds the K4/K4P, K5/K5p and
 * micro-cap keys on its own and must select the same material and family as the pipeline that wrote the state, with and
 * without an instance id, on U and P, with one secret and across a rotation (current and previous secret and hasher).
 */
#[Group('reader')]
final class FamilyReaderParityTest extends TestCase
{
    private const OLD = 'secret-one';
    private const NEW = 'secret-two';

    private static function context(bool $known, ?string $id): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.60',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'acct-read',
            null,
            null,
            false,
            [],
            $known,
            null,
            clientInstanceId: $id,
        );
    }

    /** @return iterable<string, array{0: string, 1: bool, 2: bool}> */
    public static function cases(): iterable
    {
        foreach (['U', 'P'] as $lane) {
            foreach ([true, false] as $withId) {
                foreach ([false, true] as $rotated) {
                    yield $lane . ($withId ? ' id' : ' no-id') . ($rotated ? ' rotated' : ' one-secret') => [$lane, $withId, $rotated];
                }
            }
        }
    }

    /** Five known-device failures (K5 score 10, micro-cap 5) and two unknown-device failures (account budget 2). */
    private function seed(AdmissionLaneEnum $lane, bool $withId): FamilyLab
    {
        $lab = new FamilyLab(new OptedLoginPolicy(), $lane, secret: self::OLD);
        $t = 0;
        for ($i = 0; $i < 5; $i++) {
            $lab->at($t++);
            $lab->run(self::context(true, $withId ? 'i:known' : null), 'fail');
        }
        for ($i = 0; $i < 2; $i++) {
            $lab->at($t++);
            $lab->run(self::context(false, $withId ? 'i:other' : null), 'fail');
        }

        return $lab;
    }

    private function reader(FamilyLab $lab, bool $rotated, ?string $class = null): LaneAwareReaderInterface
    {
        $secret = $rotated ? self::NEW : self::OLD;
        $previous = $rotated ? self::OLD : null;
        $resolver = new \Maatify\RateLimiter\Service\DeviceIdentityResolver(
            new \Maatify\RateLimiter\Service\FingerprintHasher($secret),
            $previous !== null ? new \Maatify\RateLimiter\Service\FingerprintHasher($previous) : null,
        );
        $arguments = [$resolver, $lab->store, new InMemoryCircuitBreakerStore(), new DecayCalculator($lab->clock), $lab->clock, $secret, 'prod', $previous];

        return $class === null
            ? new RealReaderAdapter(new RateLimitOperationalReader(...$arguments))
            : new $class(...$arguments);
    }

    /** @return array{k5: ?int, k5Previous: bool, micro: ?int, microPrevious: bool, budget: ?int} */
    private function observed(RateLimitOperationalSnapshotDTO $snapshot): array
    {
        return [
            'k5' => $snapshot->scopes->k5?->score?->value,
            'k5Previous' => $snapshot->scopes->k5?->scoreFromPreviousGeneration ?? false,
            'micro' => $snapshot->budget?->knownDeviceMicroCap?->count,
            'microPrevious' => $snapshot->budget?->knownDeviceMicroCapFromPreviousGeneration ?? false,
            'budget' => $snapshot->budget?->accountBudget?->count,
        ];
    }

    /** @return array{k5: ?int, k5Previous: bool, micro: ?int, microPrevious: bool, budget: ?int} */
    private function read(LaneAwareReaderInterface $reader, BlockPolicyInterface $policy, ?string $id, ?AdmissionLaneEnum $lane): array
    {
        return $this->observed($reader->read(self::context(true, $id), $policy, $lane));
    }

    #[DataProvider('cases')]
    public function testTheReaderWithTheLaneReadsWhatThePipelineWrote(string $laneName, bool $withId, bool $rotated): void
    {
        $lane = AdmissionLaneEnum::from($laneName);
        $lab = $this->seed($lane, $withId);
        $reader = $this->reader($lab, $rotated);
        $id = $withId ? 'i:known' : null;

        $expected = ['k5' => 10, 'k5Previous' => $rotated, 'micro' => 5, 'microPrevious' => $rotated, 'budget' => 2];
        self::assertSame($expected, $this->read($reader, $lab->policy, $id, $lane));
    }

    #[DataProvider('cases')]
    public function testTheReaderWithoutTheLaneDoesNotReadTheLanesState(string $laneName, bool $withId, bool $rotated): void
    {
        $lane = AdmissionLaneEnum::from($laneName);
        $lab = $this->seed($lane, $withId);
        $reader = $this->reader($lab, $rotated);
        $id = $withId ? 'i:known' : null;
        $without = $this->read($reader, $lab->policy, $id, null);
        $with = $this->read($reader, $lab->policy, $id, $lane);

        if ($lane === AdmissionLaneEnum::U && ! $withId) {
            // The U family without an id is today's key set: a laneless read is the same read.
            self::assertSame($with, $without);

            return;
        }
        self::assertNotSame($with, $without);
        self::assertNull($without['k5']);
    }

    public function testTheReaderFollowsTheBudgetCooldownOfTheLane(): void
    {
        $lab = new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::P, secret: self::OLD);
        $key = hash_hmac('sha256', 'login_protection:rate_limiter:budget_cooldown_p:v1:prod:acct-read', self::OLD);
        $t = 0;
        for ($i = 0; $i < 90 && $lab->store->get($key) === null; $i++) {
            $lab->at($t);
            $t += $lab->run(self::context(false, 'i:other'), 'fail')['d'] === 'A' ? 1 : 70;
        }
        self::assertNotNull($lab->store->get($key), 'The proven-lane budget cooldown was issued on its own key.');

        $reader = $this->reader($lab, false);
        self::assertNotNull($reader->read(self::context(true, 'i:known'), $lab->policy, AdmissionLaneEnum::P)->budget?->cooldown);
        self::assertNull($reader->read(self::context(true, 'i:known'), $lab->policy, null)->budget?->cooldown);
        self::assertNull($reader->read(self::context(true, 'i:known'), $lab->policy, AdmissionLaneEnum::U)->budget?->cooldown);
    }

    // ---- detectors: a reader that picks the wrong material or family must fail the parity check ------------------

    /** @param array<string, string> $methodBodies */
    private function mutantDiffers(array $methodBodies, string $laneName, bool $withId, bool $rotated): bool
    {
        $lane = AdmissionLaneEnum::from($laneName);
        $lab = $this->seed($lane, $withId);
        $mutant = PipelineMutant::reader($methodBodies);
        $id = $withId ? 'i:known' : null;

        return $this->read($this->reader($lab, $rotated, $mutant), $lab->policy, $id, $lane)
            !== $this->read($this->reader($lab, $rotated), $lab->policy, $id, $lane);
    }

    public function testDetectorReaderOnTheFingerprintMissesIdKeyedState(): void
    {
        self::assertTrue($this->mutantDiffers(['k5Material' => 'return $device->fingerprintHash;'], 'U', true, false));
    }

    public function testDetectorReaderWithoutAPreviousMaterialMissesStateAcrossARotation(): void
    {
        self::assertTrue($this->mutantDiffers(
            ['previousK5Material' => 'return $device->previousFingerprintHash ?? $device->fingerprintHash;'],
            'P',
            true,
            true,
        ));
    }

    public function testDetectorReaderOnTheUnsuffixedAccountKeyMissesTheProvenFamily(): void
    {
        self::assertTrue($this->mutantDiffers(['k4KeyType' => "return 'k4';"], 'P', true, false));
    }

    public function testDetectorReaderOnTheUnsuffixedDeviceKeyMissesTheProvenFamily(): void
    {
        self::assertTrue($this->mutantDiffers(['k5KeyType' => "return 'k5';"], 'P', true, false));
    }
}
